<?php

namespace EE\Site\Type;

use EE;
use EE\Model\Site;
use EE\Model\Option;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Utils\download;
use function EE\Utils\extract_zip;
use function EE\Utils\get_flag_value;
use function EE\Utils\get_config_value;
use function EE\Utils\delem_log;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\reload_global_nginx_proxy;
use function EE\Utils\remove_trailing_slash;

/**
 * Base class for Site command
 *
 * @package ee
 */
abstract class EE_Site_Command {
	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	protected $fs;

	/**
	 * @var bool $wildcard Whether the site is letsencrypt type is wildcard or not.
	 */
	private $wildcard;

	/**
	 * @var bool $ssl Whether the site has SSL or not.
	 */
	private $ssl;

	/**
	 * @var string $le_mail Mail id to be used for letsencrypt registration and certificate generation.
	 */
	private $le_mail;

	/**
	 * @var array $site_data Associative array containing essential site related information.
	 */
	protected $site_data;

	/**
	 * @var array $site_meta Associative array containing essential site meta related information.
	 */
	protected $site_meta;

	public function __construct() {

		$this->fs = new Filesystem();
		pcntl_signal( SIGTERM, [ $this, 'rollback' ] );
		pcntl_signal( SIGHUP, [ $this, 'rollback' ] );
		pcntl_signal( SIGUSR1, [ $this, 'rollback' ] );
		pcntl_signal( SIGINT, [ $this, 'rollback' ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, 'cleanup' ], [ &$this ] );
	}

	/**
	 * Lists the created websites.
	 * abstract list
	 *
	 * [--enabled]
	 * : List only enabled sites.
	 *
	 * [--disabled]
	 * : List only disabled sites.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - yaml
	 *   - json
	 *   - count
	 *   - text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all sites
	 *     $ ee site list
	 *
	 *     # List enabled sites
	 *     $ ee site list --enabled
	 *
	 *     # List disabled sites
	 *     $ ee site list --disabled
	 *
	 *     # List all sites in JSON
	 *     $ ee site list --format=json
	 *
	 *     # Count all sites
	 *     $ ee site list --format=count
	 *
	 * @subcommand list
	 */
	public function _list( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site list start' );
		$format   = \EE\Utils\get_flag_value( $assoc_args, 'format' );
		$enabled  = \EE\Utils\get_flag_value( $assoc_args, 'enabled' );
		$disabled = \EE\Utils\get_flag_value( $assoc_args, 'disabled' );

		$sites = Site::all();

		if ( $enabled && ! $disabled ) {
			$sites = Site::where( 'site_enabled', true );
		} elseif ( $disabled && ! $enabled ) {
			$sites = Site::where( 'site_enabled', false );
		}

		if ( empty( $sites ) ) {
			\EE::error( 'No sites found!' );
		}

		if ( 'text' === $format ) {
			foreach ( $sites as $site ) {
				\EE::log( $site->site_url );
			}
		} else {
			$result = array_map(
				function ( $site ) {
					$site->site   = $site->site_url;
					$site->status = $site->site_enabled ? 'enabled' : 'disabled';

					return $site;
				}, $sites
			);

			$formatter = new \EE\Formatter( $assoc_args, [ 'site', 'status' ] );

			$formatter->display_items( $result );
		}

		\EE\Utils\delem_log( 'site list end' );
	}


	/**
	 * Deletes a website.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website to be deleted.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete site
	 *     $ ee site delete example.com
	 *
	 */
	public function delete( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site delete start' );
		$this->site_data = get_site_info( $args, false );

		$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
			'db_host' => $this->site_data['db_host'],
			'db_user' => $this->site_data['db_user'],
			'db_name' => $this->site_data['db_name'],
		];

		\EE::confirm( sprintf( 'Are you sure you want to delete %s?', $this->site_data['site_url'] ), $assoc_args );
		$this->delete_site( 5, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );
		\EE\Utils\delem_log( 'site delete end' );
	}

	/**
	 * Function to delete the given site.
	 *
	 * @param int $level           Level of deletion.
	 *                             Level - 0: No need of clean-up.
	 *                             Level - 1: Clean-up only the site-root.
	 *                             Level - 2: Try to remove network. The network may or may not have been created.
	 *                             Level - 3: Disconnect & remove network and try to remove containers. The containers
	 *                             may not have been created. Level - 4: Remove containers. Level - 5: Remove db entry.
	 * @param string $site_url     Name of the site to be deleted.
	 * @param string $site_fs_path Webroot of the site.
	 * @param array $db_data       Database host, user and password to cleanup db.
	 *
	 * @throws \EE\ExitException
	 */
	protected function delete_site( $level, $site_url, $site_fs_path, $db_data = [] ) {

		$this->fs = new Filesystem();

		if ( $level >= 3 ) {
			if ( \EE_DOCKER::docker_compose_down( $site_fs_path ) ) {
				\EE::log( "[$site_url] Docker Containers removed." );
			} else {
				\EE::exec( "docker rm -f $(docker ps -q -f=label=created_by=EasyEngine -f=label=site_name=$site_url)" );
				if ( $level > 3 ) {
					\EE::warning( 'Error in removing docker containers.' );
				}
			}
		}

		$volumes = \EE_DOCKER::get_volumes_by_label( $site_url );
		foreach ( $volumes as $volume ) {
			\EE::exec( 'docker volume rm ' . $volume );
		}

		if ( ! empty( $db_data['db_host'] ) ) {
			\EE\Site\Utils\cleanup_db( $db_data['db_host'], $db_data['db_name'] );
			\EE\Site\Utils\cleanup_db_user( $db_data['db_host'], $db_data['db_user'] );
		}

		if ( $this->fs->exists( $site_fs_path ) ) {
			try {
				$this->fs->remove( $site_fs_path );
			} catch ( \Exception $e ) {
				\EE::debug( $e );
				\EE::error( 'Could not remove site root. Please check if you have sufficient rights.' );
			}
			\EE::log( "[$site_url] site root removed." );
		}

		$config_file_path = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/' . $site_url . '-redirect.conf';

		if ( $this->fs->exists( $config_file_path ) ) {
			try {
				$this->fs->remove( $config_file_path );
			} catch ( \Exception $e ) {
				\EE::debug( $e );
				\EE::error( 'Could not remove site redirection file. Please check if you have sufficient rights.' );
			}
		}

		/**
		 * Execute before site db data cleanup and after site services cleanup.
		 * Note: This can be use to cleanup site data added by any package command.
		 *
		 * @param string $site_url Url of site which data is cleanup.
		 */
		\EE::do_hook( 'site_cleanup', $site_url );

		if ( $level > 4 ) {
			if ( $this->site_data['site_ssl'] ) {
				\EE::log( 'Removing ssl certs.' );
				$crt_file   = EE_ROOT_DIR . "/services/nginx-proxy/certs/$site_url.crt";
				$key_file   = EE_ROOT_DIR . "/services/nginx-proxy/certs/$site_url.key";
				$pem_file   = EE_ROOT_DIR . "/services/nginx-proxy/certs/$site_url.chain.pem";
				$conf_certs = EE_ROOT_DIR . "/services/nginx-proxy/acme-conf/certs/$site_url";
				$conf_var   = EE_ROOT_DIR . "/services/nginx-proxy/acme-conf/var/$site_url";

				$cert_files = [ $conf_certs, $conf_var, $crt_file, $key_file, $pem_file ];
				try {
					$this->fs->remove( $cert_files );
				} catch ( \Exception $e ) {
					\EE::warning( $e );
				}
			}

			if ( Site::find( $site_url )->delete() ) {
				\EE::log( 'Removed database entry.' );
			} else {
				\EE::error( 'Could not remove the database entry' );
			}
		}
		\EE::log( "Site $site_url deleted." );
	}

	/**
	 * Supports updating and upgrading site.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--ssl=<ssl>]
	 * : Enable ssl on site
	 *
	 * [--wildcard]
	 * : Enable wildcard SSL on site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Add SSL to non-ssl site
	 *     $ ee site update example.com --ssl=le
	 *
	 *     # Add SSL to non-ssl site
	 *     $ ee site update example.com --ssl=le --wildcard
	 *
	 *     # Add self-signed SSL to non-ssl site
	 *     $ ee site update example.com --ssl=self
	 *
	 */
	public function update( $args, $assoc_args ) {

		delem_log( 'site update start' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, true, true, false );
		$ssl             = get_flag_value( $assoc_args, 'ssl', false );
		if ( $ssl ) {
			$this->update_ssl( $assoc_args );
		}
	}

	/**
	 * Funciton to update ssl of a site.
	 */
	protected function update_ssl( $assoc_args ) {

		$ssl            = get_flag_value( $assoc_args, 'ssl', false );
		$wildcard       = get_flag_value( $assoc_args, 'wildcard', false );
		$show_error     = $this->site_data->site_ssl ? true : false;
		$wildcard_error = ( ! $this->site_data->site_ssl_wildcard && $wildcard ) ? true : false;

		$error = $wildcard_error ? 'Update from normal ssl to wildcard is not supported yet.' : 'Site ' . $this->site_data->site_url . ' already contains SSL.';

		if ( $show_error ) {
			EE::error( $error );
		}

		EE::log( 'Starting ssl update for: ' . $this->site_data->site_url );
		try {
			$this->site_data->site_ssl          = $ssl;
			$this->site_data->site_ssl_wildcard = $wildcard ? 1 : 0;

			$site                        = $this->site_data;
			$array_data                  = ( array ) $this->site_data;
			$this->site_data             = reset( $array_data );
			$this->site_data['site_ssl'] = $ssl;
			$this->www_ssl_wrapper( [ 'nginx' ] );
			$site->site_ssl = $ssl;
		} catch ( \Exception $e ) {
			EE::error( $e->getMessage() );
		}
		$site->save();
		EE::success( 'Enabled ssl for ' . $this->site_data['site_url'] );
		delem_log( 'site ssl update end' );
	}

	/**
	 * Enables a website. It will start the docker containers of the website if they are stopped.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be enabled.
	 *
	 * [--force]
	 * : Force execution of site enable.
	 *
	 * [--verify]
	 * : Verify if required global services are working.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable site
	 *     $ ee site enable example.com
	 *
	 *     # Enable site with verification of dependent global services. (Note: This takes longer time to enable the
	 *     site.)
	 *     $ ee site enable example.com --verify
	 *
	 *     # Force enable a site.
	 *     $ ee site enable example.com --force
	 */
	public function enable( $args, $assoc_args, $exit_on_error = true ) {

		\EE\Utils\delem_log( 'site enable start' );
		$force           = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$verify          = \EE\Utils\get_flag_value( $assoc_args, 'verify' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );

		if ( $this->site_data->site_enabled && ! $force ) {
			\EE::error( sprintf( '%s is already enabled!', $this->site_data->site_url ) );
		}

		if ( $verify ) {
			$this->verify_services();
		}

		\EE::log( sprintf( 'Enabling site %s.', $this->site_data->site_url ) );

		$success             = false;
		$containers_to_start = [ 'nginx' ];

		if ( \EE_DOCKER::docker_compose_up( $this->site_data->site_fs_path, $containers_to_start ) ) {
			$this->site_data->site_enabled = 1;
			$this->site_data->save();
			$success = true;
		}

		if ( $success ) {
			\EE::success( sprintf( 'Site %s enabled.', $this->site_data->site_url ) );
		} else {
			$err_msg = sprintf( 'There was error in enabling %s. Please check logs.', $this->site_data->site_url );
			if ( $exit_on_error ) {
				\EE::error( $err_msg );
			}
			throw new \Exception( $err_msg );
		}

		\EE::log( 'Running post enable configurations.' );

		$postfix_exists      = \EE_DOCKER::service_exists( 'postfix', $this->site_data->site_fs_path );
		$containers_to_start = $postfix_exists ? [ 'nginx', 'postfix' ] : [ 'nginx' ];

		\EE\Site\Utils\start_site_containers( $this->site_data->site_fs_path, $containers_to_start );

		$site_data_array = (array) $this->site_data;
		$this->site_data = reset( $site_data_array );
		$this->www_ssl_wrapper( $containers_to_start, true );

		if ( $postfix_exists ) {
			\EE\Site\Utils\configure_postfix( $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		}

		if ( true === (bool) $this->site_data['admin_tools'] ) {
			$admin_tools = new \Admin_Tools_Command();
			$admin_tools->enable( [ $this->site_data['site_url'] ], [ 'force' => true ] );
		}

		if ( true === (bool) $this->site_data['mailhog_enabled'] ) {
			$mailhog = new \Mailhog_Command();
			$mailhog->enable( [ $this->site_data['site_url'] ], [ 'force' => true ] );
		}

		\EE::success( 'Post enable configurations complete.' );

		\EE\Utils\delem_log( 'site enable end' );
	}

	/**
	 * Disables a website. It will stop and remove the docker containers of the website if they are running.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be disabled.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable site
	 *     $ ee site disable example.com
	 *
	 */
	public function disable( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site disable start' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );

		\EE::log( sprintf( 'Disabling site %s.', $this->site_data->site_url ) );

		$fs                        = new Filesystem();
		$redirect_config_file_path = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/' . $args[0] . '-redirect.conf';
		if ( $fs->exists( $redirect_config_file_path ) ) {
			$fs->remove( $redirect_config_file_path );
			\EE\Site\Utils\reload_global_nginx_proxy();
		}

		if ( \EE_DOCKER::docker_compose_down( $this->site_data->site_fs_path ) ) {
			$this->site_data->site_enabled = 0;
			$this->site_data->save();

			\EE::success( sprintf( 'Site %s disabled.', $this->site_data->site_url ) );
		} else {
			\EE::error( sprintf( 'There was error in disabling %s. Please check logs.', $this->site_data->site_url ) );
		}
		\EE\Utils\delem_log( 'site disable end' );
	}

	/**
	 * Restarts containers associated with site.
	 * When no service(--nginx etc.) is specified, all site containers will be restarted.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Restart all containers of site.
	 *
	 * [--nginx]
	 * : Restart nginx container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart all containers of site
	 *     $ ee site restart example.com
	 *
	 */
	public function restart( $args, $assoc_args, $whitelisted_containers = [] ) {

		\EE\Utils\delem_log( 'site restart start' );
		$args                 = auto_site_name( $args, 'site', __FUNCTION__ );
		$all                  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		$no_service_specified = count( $assoc_args ) === 0;

		$this->site_data = get_site_info( $args );

		chdir( $this->site_data['site_fs_path'] );

		if ( $all || $no_service_specified ) {
			$containers = $whitelisted_containers;
		} else {
			$containers = array_keys( $assoc_args );
		}

		foreach ( $containers as $container ) {
			\EE\Site\Utils\run_compose_command( 'restart', $container );
		}
		\EE\Utils\delem_log( 'site restart stop' );
	}

	/**
	 * Clears Object and Page cache for site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be enabled.
	 *
	 * [--page]
	 * : Clear page cache.
	 *
	 * [--object]
	 * : Clear object cache.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear Both cache type for site.
	 *     $ ee site clean example.com
	 *
	 *     # Clear Object cache for site.
	 *     $ ee site clean example.com --object
	 *
	 *     # Clear Page cache for site.
	 *     $ ee site clean example.com --page
	 */
	public function clean( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site clean start' );
		$object          = \EE\Utils\get_flag_value( $assoc_args, 'object' );
		$page            = \EE\Utils\get_flag_value( $assoc_args, 'page' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );
		$purge_key       = '';
		$error           = [];

		// No param passed.
		if ( empty( $object ) && empty( $page ) ) {
			$object = true;
			$page   = true;
		}

		// Object cache clean.
		if ( ! empty( $object ) ) {
			if ( 1 === intval( $this->site_data->cache_mysql_query ) ) {
				$purge_key = $this->site_data->site_url . '_obj';
			} else {
				$error[] = 'Site object cache is not enabled.';
			}
		}

		// Page cache clean.
		if ( ! empty( $page ) ) {
			if ( 1 === intval( $this->site_data->cache_nginx_fullpage ) ) {
				$purge_key = $this->site_data->site_url . '_page';
			} else {
				$error[] = 'Site page cache is not enabled.';
			}
		}

		// If Page and Object both passed.
		if ( ! empty( $object ) && ! empty( $page ) ) {
			$purge_key = $this->site_data->site_url;
		}

		if ( ! empty( $error ) ) {
			\EE::error( implode( ' ', $error ) );
		}

		EE\Site\Utils\clean_site_cache( $purge_key );

		if ( $page ) {
			\EE::success( 'Page cache cleared for ' . $this->site_data->site_url );
		}
		if ( $object ) {
			\EE::success( 'Object cache cleared for ' . $this->site_data->site_url );
		}

		\EE\Utils\delem_log( 'site clean end' );

	}

	/**
	 * Reload services in containers without restarting container(s) associated with site.
	 * When no service(--nginx etc.) is specified, all services will be reloaded.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Reload all services of site(which are supported).
	 *
	 * [--nginx]
	 * : Reload nginx service in container.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reload all containers of site
	 *     $ ee site reload example.com
	 *
	 */
	public function reload( $args, $assoc_args, $whitelisted_containers = [], $reload_commands = [] ) {

		\EE\Utils\delem_log( 'site reload start' );
		$args = auto_site_name( $args, 'site', __FUNCTION__ );
		$all  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		if ( ! array_key_exists( 'nginx', $reload_commands ) ) {
			$reload_commands['nginx'] = 'nginx sh -c \'nginx -t && service openresty reload\'';
		}
		$no_service_specified = count( $assoc_args ) === 0;

		$this->site_data = get_site_info( $args );

		chdir( $this->site_data['site_fs_path'] );

		if ( $all || $no_service_specified ) {
			$this->reload_services( $whitelisted_containers, $reload_commands );
		} else {
			$this->reload_services( array_keys( $assoc_args ), $reload_commands );
		}
		\EE\Utils\delem_log( 'site reload stop' );
	}

	/**
	 * Executes reload commands. It needs separate handling as commands to reload each service is different.
	 *
	 * @param array $services        Services to reload.
	 * @param array $reload_commands Commands to reload the services.
	 */
	private function reload_services( $services, $reload_commands ) {

		foreach ( $services as $service ) {
			\EE\Site\Utils\run_compose_command( 'exec', $reload_commands[ $service ], 'reload', $service );
		}
	}

	/**
	 * Function to verify and check the global services dependent for given site.
	 * Enables the dependent service if it is down.
	 */
	private function verify_services() {

		if ( 'running' !== \EE_DOCKER::container_status( EE_PROXY_TYPE ) ) {
			EE\Service\Utils\nginx_proxy_check();
		}

		if ( 'global-db' === $this->site_data->db_host ) {
			EE\Service\Utils\init_global_container( GLOBAL_DB );
		}

		if ( 'global-redis' === $this->site_data->cache_host ) {
			EE\Service\Utils\init_global_container( GLOBAL_REDIS );
		}
	}

	/**
	 * Function to add site redirects and initialise ssl process.
	 *
	 * @param array $containers_to_start Containers to start for that site. Default, empty will start all.
	 * @param bool $force                Force ssl renewal.
	 * @param bool $renew                True if function is being used for cert renewal.
	 *
	 * @throws EE\ExitException
	 * @throws \Exception
	 */
	protected function www_ssl_wrapper( $containers_to_start = [], $site_enable = false, $force = false, $renew = false ) {
		/**
		 * This adds http www redirection which is needed for issuing cert for a site.
		 * i.e. when you create example.com site, certs are issued for example.com and www.example.com
		 *
		 * We're issuing certs for both domains as it is needed in order to perform redirection of
		 * https://www.example.com -> https://example.com
		 *
		 * We add redirection config two times in case of ssl as we need http redirection
		 * when certs are being requested and http+https redirection after we have certs.
		 */
		\EE\Site\Utils\add_site_redirects( $this->site_data['site_url'], false, 'inherit' === $this->site_data['site_ssl'] );
		\EE\Site\Utils\reload_global_nginx_proxy();
		// Need second reload sometimes for changes to reflect.
		\EE\Site\Utils\reload_global_nginx_proxy();

		$is_www_or_non_www_pointed = $this->check_www_or_non_www_domain( $this->site_data['site_url'], $this->site_data['site_fs_path'] ) || $this->site_data['site_ssl_wildcard'];
		if ( ! $is_www_or_non_www_pointed ) {
			$fs          = new Filesystem();
			$confd_path  = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/';
			$config_file = $confd_path . $this->site_data['site_url'] . '-redirect.conf';
			$fs->remove( $config_file );
			\EE\Site\Utils\reload_global_nginx_proxy();
		}

		if ( $this->site_data['site_ssl'] ) {
			if ( ! $site_enable ) {
				if ( 'custom' !== $this->site_data['site_ssl'] ) {
					$this->init_ssl( $this->site_data['site_url'], $this->site_data['site_fs_path'], $this->site_data['site_ssl'], $this->site_data['site_ssl_wildcard'], $is_www_or_non_www_pointed, $force );
				}

				$this->dump_docker_compose_yml( [ 'nohttps' => false ] );

				\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], $containers_to_start );
			}

			if ( $is_www_or_non_www_pointed ) {
				\EE\Site\Utils\add_site_redirects( $this->site_data['site_url'], true, 'inherit' === $this->site_data['site_ssl'] );
			}

			\EE\Site\Utils\reload_global_nginx_proxy();
		}
	}

	/**
	 * Runs the acme le registration and authorization.
	 *
	 * @param string $site_url Name of the site for ssl.
	 *
	 * @throws \Exception
	 */
	protected function check_parent_site_certs( $site_url ) {

		$parent_site_name = implode( '.', array_slice( explode( '.', $site_url ), 1 ) );
		$parent_site      = Site::find( $parent_site_name, [ 'site_ssl', 'site_ssl_wildcard' ] );

		if ( ! $parent_site ) {
			throw new \Exception( 'Unable to find existing site: ' . $parent_site_name );
		}

		if ( ! $parent_site->site_ssl ) {
			throw new \Exception( "Cannot inherit from $parent_site_name as site does not have SSL cert" . var_dump( $parent_site ) );
		}

		if ( ! $parent_site->site_ssl_wildcard ) {
			throw new \Exception( "Cannot inherit from $parent_site_name as site does not have wildcard SSL cert" );
		}
	}

	/**
	 * Runs SSL procedure.
	 *
	 * @param string $site_url     Name of the site for ssl.
	 * @param string $site_fs_path Webroot of the site.
	 * @param string $ssl_type     Type of ssl cert to issue.
	 * @param bool $wildcard       SSL with wildcard or not.
	 * @param bool $www_or_non_www Allow LetsEncrypt on www or non-www subdomain.
	 * @param bool $force          Force ssl renewal.
	 *
	 * @throws \EE\ExitException If --ssl flag has unrecognized value.
	 * @throws \Exception
	 */
	protected function init_ssl( $site_url, $site_fs_path, $ssl_type, $wildcard = false, $www_or_non_www = false, $force = false ) {

		\EE::debug( 'Starting SSL procedure' );
		if ( 'le' === $ssl_type ) {
			\EE::debug( 'Initializing LE' );
			$this->init_le( $site_url, $site_fs_path, $wildcard, $www_or_non_www, $force );
		} elseif ( 'inherit' === $ssl_type ) {
			if ( $wildcard ) {
				throw new \Exception( 'Cannot use --wildcard with --ssl=inherit', false );
			}
			// We don't have to do anything now as nginx-proxy handles everything for us.
			EE::success( 'Inherited certs from parent' );
		} elseif ( 'self' === $ssl_type ) {
			$client = new Site_Self_signed();
			$client->create_certificate( $site_url );
			// Update wildcard to 1 as self-signed certs are wildcard by default.
			$this->site_data['site_ssl_wildcard'] = 1;
		} else {
			throw new \Exception( "Unrecognized value in --ssl flag: $ssl_type" );
		}
	}

	/**
	 * Runs the acme le registration and authorization.
	 *
	 * @param string $site_url     Name of the site for ssl.
	 * @param string $site_fs_path Webroot of the site.
	 * @param bool $wildcard       SSL with wildcard or not.
	 * @param bool $www_or_non_www Allow LetsEncrypt on www or non-www subdomain.
	 * @param bool $force          Force ssl renewal.
	 */
	protected function init_le( $site_url, $site_fs_path, $wildcard = false, $www_or_non_www, $force = false ) {
		$preferred_challenge = get_config_value( 'preferred_ssl_challenge', '' );
		$is_solver_dns       = ( $wildcard || 'dns' === $preferred_challenge ) ? true : false;
		\EE::debug( 'Wildcard in init_le: ' . ( bool ) $wildcard );

		$this->site_data['site_fs_path']      = $site_fs_path;
		$this->site_data['site_ssl_wildcard'] = $wildcard;
		$client                               = new Site_Letsencrypt();
		$this->le_mail                        = \EE::get_runner()->config['le-mail'] ?? \EE::input( 'Enter your mail id: ' );
		\EE::get_runner()->ensure_present_in_config( 'le-mail', $this->le_mail );
		if ( ! $client->register( $this->le_mail ) ) {
			$this->site_data['site_ssl'] = null;

			return;
		}

		$domains = $this->get_cert_domains( $site_url, $wildcard, $www_or_non_www );

		if ( ! $client->authorize( $domains, $wildcard, $preferred_challenge ) ) {
			return;
		}
		$api_key_absent = empty( get_config_value( 'cloudflare-api-key' ) );
		if ( $is_solver_dns && $api_key_absent ) {
			echo \cli\Colors::colorize( '%YIMPORTANT:%n Run `ee site ssl ' . $site_url . '` once the DNS changes have propagated to complete the certification generation and installation.', null );
		} else {
			if ( ! $api_key_absent && $is_solver_dns ) {
				EE::log( 'Waiting for DNS entry propagation.' );
				sleep( 10 );
			}
			$this->ssl( [], [ 'force' => $force ], $www_or_non_www );
		}
	}

	/**
	 * Returns all domains required by cert
	 *
	 * @param string $site_url     Name of site
	 * @param bool $wildcard       Wildcard cert required?
	 * @param bool $www_or_non_www Allow LetsEncrypt on www subdomain.
	 *
	 * @return array
	 */
	private function get_cert_domains( string $site_url, $wildcard, $www_or_non_www = false ): array {
		$preferred_challenge = get_config_value( 'preferred_ssl_challenge', '' );
		$is_solver_dns       = ( $wildcard || 'dns' === $preferred_challenge ) ? true : false;

		$domains = [ $site_url ];

		if ( $wildcard ) {
			$domains[] = "*.{$site_url}";
		} elseif ( $www_or_non_www || $is_solver_dns ) {
			if ( 0 === strpos( $site_url, 'www.' ) ) {
				$www_or_non_www_site_url = ltrim( $site_url, 'www.' );
			} else {
				$www_or_non_www_site_url = 'www.' . $site_url;
			}
			$domains[] = $www_or_non_www_site_url;
		}

		return $domains;
	}

	/**
	 * Check www or non-www is working with site domain.
	 *
	 * @param string Site url.
	 * @param string Absolute path of site.
	 *
	 * @return bool
	 */
	protected function check_www_or_non_www_domain( $site_url, $site_path ): bool {

		$random_string = EE\Utils\random_password();
		$successful    = false;
		$file_path     = $site_path . '/app/htdocs/check.html';
		file_put_contents( $file_path, $random_string );

		if ( 0 === strpos( $site_url, 'www.' ) ) {
			$site_url = ltrim( $site_url, 'www.' );
		} else {
			$site_url = 'www.' . $site_url;
		}

		$site_url .= '/check.html';

		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, $site_url );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $curl, CURLOPT_HEADER, false );
		$data = curl_exec( $curl );
		curl_close( $curl );

		if ( ! empty( $data ) && $random_string === $data ) {
			$successful = true;
			EE::debug( "pointed for $site_url" );
		}

		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}

		return $successful;
	}

	/**
	 * Verifies ssl challenge and also renews certificates(if expired).
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--force]
	 * : Force renewal.
	 */
	public function ssl( $args = [], $assoc_args = [], $www_or_non_www = false ) {

		EE::log( 'Starting SSL verification.' );

		// This checks if this method was called internally by ee or by user
		$called_by_ee   = ! empty( $this->site_data['site_url'] );
		$api_key_absent = empty( get_config_value( 'cloudflare-api-key' ) );

		if ( ! $called_by_ee ) {
			$this->site_data = get_site_info( $args );
		}

		if ( ! isset( $this->le_mail ) ) {
			$this->le_mail = \EE::get_config( 'le-mail' ) ?? \EE::input( 'Enter your mail id: ' );
		}

		$force   = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$domains = $this->get_cert_domains( $this->site_data['site_url'], $this->site_data['site_ssl_wildcard'], $www_or_non_www );
		$client  = new Site_Letsencrypt();

		$preferred_challenge = get_config_value( 'preferred_ssl_challenge', '' );

		try {
			$client->check( $domains, $this->site_data['site_ssl_wildcard'], $preferred_challenge );
		} catch ( \Exception $e ) {
			if ( $called_by_ee && $api_key_absent ) {
				throw $e;
			}
			$is_solver_dns   = ( $this->site_data['site_ssl_wildcard'] || 'dns' === $preferred_challenge ) ? true : false;
			$api_key_present = ! empty( get_config_value( 'cloudflare-api-key' ) );

			$warning = ( $is_solver_dns && $api_key_present ) ? "The dns entries have not yet propogated. Manually check: \nhost -t TXT _acme-challenge." . $this->site_data['site_url'] . "\nBefore retrying `ee site ssl " . $this->site_data['site_url'] . "`" : 'Failed to verify SSL: ' . $e->getMessage();
			EE::warning( $warning );
			EE::warning( sprintf( 'Check logs and retry `ee site ssl %s` once the issue is resolved.', $this->site_data['site_url'] ) );

			return;
		}

		$san = array_values( array_diff( $domains, [ $this->site_data['site_url'] ] ) );
		$client->request( $this->site_data['site_url'], $san, $this->le_mail, $force );

		if ( ! $this->site_data['site_ssl_wildcard'] ) {
			$client->cleanup();
		}

		reload_global_nginx_proxy();

		EE::success( 'SSL verification completed.' );
	}

	/**
	 * Renews letsencrypt ssl certificates.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--force]
	 * : Force renewal.
	 *
	 * [--all]
	 * : Run renewal for all ssl sites. (Skips renewal for dns/wildcards sites if cloudflare api is not set).
	 *
	 * ## EXAMPLES
	 *
	 *     # Renew ssl cert of a site
	 *     $ ee site ssl-renew example.com
	 *
	 *     # Renew all ssl certs
	 *     $ ee site ssl-renew --all
	 *
	 *     # Force renew ssl cert
	 *     $ ee site ssl-renew example.com --force
	 *
	 * @subcommand ssl-renew
	 */
	public function ssl_renew( $args, $assoc_args ) {

		EE::log( 'Starting SSL cert renewal' );

		if ( ! isset( $this->le_mail ) ) {
			$this->le_mail = EE::get_config( 'le-mail' ) ?? EE::input( 'Enter your mail id: ' );
		}

		$force = get_flag_value( $assoc_args, 'force', false );
		$all   = get_flag_value( $assoc_args, 'all', false );

		if ( $all ) {
			$sites                 = Site::all();
			$api_key_absent        = empty( get_config_value( 'cloudflare-api-key' ) );
			$skip_wildcard_warning = false;
			foreach ( $sites as $site ) {
				if ( 'le' !== $site->site_ssl || ! $site->site_enabled ) {
					continue;
				}
				if ( $site->site_ssl_wildcard && $api_key_absent ) {
					EE::warning( "Wildcard site found: $site->site_url, skipping it." );
					if ( ! $skip_wildcard_warning ) {
						EE::warning( "As this is a wildcard certificate, it cannot be automatically renewed.\nPlease run `ee site ssl-renew $site->site_url` to renew the certificate, or add cloudflare api key in EasyEngine config. Ref: https://rt.cx/eecf" );
						$skip_wildcard_warning = true;
					}
					continue;
				}
				$this->renew_ssl_cert( [ $site->site_url ], $force );
			}
		} else {
			$args = auto_site_name( $args, 'site', __FUNCTION__ );
			$this->renew_ssl_cert( $args, $force );
		}
		EE::success( 'SSL renewal completed.' );
	}

	/**
	 * Function to setup and execute ssl renewal.
	 *
	 * @param array $args User input args.
	 * @param bool $force Whether to force renewal of cert or not.
	 *
	 * @throws EE\ExitException
	 * @throws \Exception
	 */
	private function renew_ssl_cert( $args, $force ) {

		$this->site_data = get_site_info( $args );

		if ( 'inherit' === $this->site_data['site_ssl'] ) {
			EE::error( 'No need to renew certs for site who have inherited ssl. Please renew certificate of the parent site.' );
		}

		if ( 'le' !== $this->site_data['site_ssl'] ) {
			EE::error( 'Only Letsencrypt certificate renewal is supported.' );
		}

		$client = new Site_Letsencrypt();
		if ( $client->isAlreadyExpired( $this->site_data['site_url'] ) ) {
			$this->dump_docker_compose_yml( [ 'nohttps' => true ] );
			$this->enable( $args, [ 'force' => true ] );
		}

		if ( ! $client->isRenewalNecessary( $this->site_data['site_url'] ) ) {
			return 0;
		}

		$postfix_exists      = \EE_DOCKER::service_exists( 'postfix', $this->site_data['site_fs_path'] );
		$containers_to_start = $postfix_exists ? [ 'nginx', 'postfix' ] : [ 'nginx' ];
		$this->www_ssl_wrapper( $containers_to_start, false, $force, true );

		reload_global_nginx_proxy();
	}

	/**
	 * Share a site online using ngrok.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--disable]
	 * : Take online link down.
	 *
	 * [--refresh]
	 * : Refresh site share if link has expired.
	 *
	 * [--token=<token>]
	 * : ngrok token.
	 *
	 * ## EXAMPLES
	 *
	 *     # Share a site online
	 *     $ ee site share example.com
	 *
	 *     # Refresh shareed link if expired
	 *     $ ee site share example.com --refresh
	 *
	 *     # Disable online link
	 *     $ ee site share example.com --disable
	 *
	 */
	public function share( $args, $assoc_args ) {

		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, true, true, false );
		$disable         = get_flag_value( $assoc_args, 'disable', false );
		$refresh         = get_flag_value( $assoc_args, 'refresh', false );
		$token           = get_flag_value( $assoc_args, 'token', false );
		$active_publish  = Option::get( 'publish_site' );
		$publish_url     = Option::get( 'publish_url' );

		$this->fs = new Filesystem();
		$ngrok    = EE_SERVICE_DIR . '/ngrok/ngrok';

		if ( $disable || $refresh ) {
			if ( $this->site_data->site_url === $active_publish ) {
				$this->ngrok_curl( false, $refresh );
			} else {
				EE::error( $this->site_data->site_url . ' does not have active share running.' );
			}

			if ( ! $refresh ) {
				return;
			}
		}

		if ( $this->site_data->site_ssl ) {
			EE::error( 'site share is not yet supported for ssl sites.' );
		}

		$this->maybe_setup_ngrok( $ngrok );
		if ( ! empty( $active_publish ) ) {
			if ( ( $this->site_data->site_url === $active_publish ) ) {
				$error = $refresh ? '' : "{$this->site_data->site_url} has already been shared. Visit link: $publish_url to view it online.\nNote: This link is only valid for few hours. In case it has expired run: `ee site share {$this->site_data->site_url} --refresh`";
			} else {
				$error = "$active_publish site is shared currently. Sharing of only one site at a time is supported.\nTo share {$this->site_data->site_url} , first run: `ee site share $active_publish --disable`";
			}
			if ( ! empty( $error ) ) {
				EE::error( $error );
			}
		}

		if ( ! empty( $token ) ) {
			EE::exec( "$ngrok authtoken $token" );
		}
		$config_80_port = get_config_value( 'proxy_80_port', 80 );
		if ( ! $refresh ) {
			EE::log( "Sharing site: {$this->site_data->site_url} online." );
		}
		EE::debug( "$ngrok http -host-header={$this->site_data->site_url} $config_80_port > /dev/null &" );
		EE::debug( shell_exec( "$ngrok http -host-header={$this->site_data->site_url} $config_80_port > /dev/null &" ) );
		$published_url = $this->ngrok_curl();
		if ( empty( $published_url ) ) {
			EE::error( 'Could not share site.' );
		}
		EE::success( "Successfully shared {$this->site_data->site_url} to url: $published_url" );
		Option::set( 'publish_site', $this->site_data->site_url );
		Option::set( 'publish_url', $published_url );
	}

	/**
	 * Check if ngrok binary is setup. If not, set it up.
	 *
	 * @param $ngrok Path to ngrok binary.
	 */
	private function maybe_setup_ngrok( $ngrok ) {

		if ( $this->fs->exists( $ngrok ) ) {
			return;
		}
		EE::log( 'Setting up ngrok. This may take some time.' );
		$ngrok_zip = $ngrok . '.zip';
		$this->fs->mkdir( dirname( $ngrok ) );
		$uname_m      = 'x86_64' === php_uname( 'm' ) ? 'amd64.zip' : '386.zip';
		$download_url = IS_DARWIN ? 'https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-darwin-' . $uname_m : 'https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-linux-' . $uname_m;
		download( $ngrok_zip, $download_url );
		extract_zip( $ngrok_zip, dirname( $ngrok ) );
		chmod( $ngrok, 0755 );
		$this->fs->remove( $ngrok_zip );
	}

	/**
	 * Function to curl and get data from ngrok api.
	 *
	 * @param bool $get_url To get url of tunnel or not.
	 * @param bool $refresh Whether to disable share refresh or not.
	 */
	private function ngrok_curl( $get_url = true, $refresh = false ) {

		EE::log( 'Checking ngrok api for tunnel details.' );
		$tries = 0;
		$loop  = true;
		while ( $loop ) {
			$ch = curl_init( 'http://127.0.0.1:4040/api/tunnels/' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_AUTOREFERER, true );
			$ngrok_curl = curl_exec( $ch );

			if ( ! empty( $ngrok_curl ) ) {
				$ngrok_data = json_decode( $ngrok_curl, true );
				if ( ! empty( $ngrok_data['tunnels'] ) ) {
					$loop = false;
				}
			}
			$tries ++;
			EE::debug( $ngrok_curl );
			sleep( 1 );
			if ( $tries > 20 ) {
				$loop = false;
			}
		}

		$json_error = json_last_error();
		if ( $json_error !== JSON_ERROR_NONE ) {
			EE::debug( 'Json last error: ' . $json_error );
			EE::error( 'Error fetching ngrok url. Check logs.' );
		}

		$ngrok_tunnel = '';
		if ( ! empty( $ngrok_data['tunnels'] ) ) {
			if ( $get_url ) {
				return str_replace( 'https', 'http', $ngrok_data['tunnels'][0]['public_url'] );
			} else {
				$ngrok_tunnel = str_replace( '+', '%20', urlencode( $ngrok_data['tunnels'][0]['name'] ) );
			}
		} elseif ( $get_url ) {
			EE::error( 'Could not share site. Please check logs.' );
		}

		if ( $refresh ) {
			EE::log( 'Refreshing site share.' );
		} else {
			EE::log( 'Disabling share.' );
		}
		if ( ! empty( $ngrok_tunnel ) ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'http://127.0.0.1:4040/api/tunnels/' . $ngrok_tunnel );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "DELETE" );
			$disable = curl_exec( $ch );
			EE::debug( $disable );
			curl_close( $ch );
			EE::exec( 'killall ngrok' );
		}
		Option::set( 'publish_site', '' );
		Option::set( 'publish_url', '' );
		if ( ! $refresh ) {
			EE::success( 'Site share disabled.' );
		}
	}

	/**
	 * Shutdown function to catch and rollback from fatal errors.
	 */
	protected function shut_down_function() {

		$logger = \EE::get_file_logger()->withName( 'site-command' );
		$error  = error_get_last();
		if ( isset( $error ) && $error['type'] === E_ERROR ) {
			\EE::warning( 'An Error occurred. Initiating clean-up.' );
			$logger->error( 'Type: ' . $error['type'] );
			$logger->error( 'Message: ' . $error['message'] );
			$logger->error( 'File: ' . $error['file'] );
			$logger->error( 'Line: ' . $error['line'] );
			$this->rollback();
		}
	}

	/**
	 * Check site count for maximum 27 sites.
	 *
	 * @throws EE\ExitException
	 */
	protected function check_site_count() {
		$sites = Site::all();

		if ( 27 > count( $sites ) ) {
			return;
		}

		\EE::error( 'You can not create more than 27 sites' );
	}

	/**
	 * Function to populate site-info from database.
	 *
	 * @param string $site_name Name of the site whose info needs to be populated.
	 * @param string $in_array  Populate info in array if true, else in obj form.
	 *
	 * @ignorecommand
	 */
	public function populate_site_info( $site_name, $in_array = true ) {
		$this->site_data = EE\Site\Utils\get_site_info( [ $site_name ], false, false, $in_array );
	}

	/**
	 * Validate ssl-key and ssl-crt paths.
	 *
	 * @param $ssl_key ssl-key file path.
	 * @param $ssl_crt ssl-crt file path.
	 *
	 * @throws \Exception
	 */
	protected function validate_site_custom_ssl( $ssl_key, $ssl_crt ) {
		if ( empty( $ssl_key ) || empty( $ssl_crt ) ) {
			throw new \Exception( 'Pass --ssl-key and --ssl-crt for custom SSL' );
		}

		if ( $this->fs->exists( $ssl_key ) && $this->fs->exists( $ssl_crt ) ) {
			$this->site_data['ssl_key'] = realpath( $ssl_key );
			$this->site_data['ssl_crt'] = realpath( $ssl_crt );
		} else {
			throw new \Exception( 'ssl-key OR ssl-crt path does not exist' );
		}
	}

	/**
	 * Allow custom SSL for site.
	 */
	protected function custom_site_ssl() {

		$ssl_key_dest = sprintf( '%1$s/nginx-proxy/certs/%2$s.key', remove_trailing_slash( EE_SERVICE_DIR ), $this->site_data['site_url'] );
		$ssl_crt_dest = sprintf( '%1$s/nginx-proxy/certs/%2$s.crt', remove_trailing_slash( EE_SERVICE_DIR ), $this->site_data['site_url'] );

		$this->fs->copy( $this->site_data['ssl_key'], $ssl_key_dest, true );
		$this->fs->copy( $this->site_data['ssl_crt'], $ssl_crt_dest, true );
	}

	abstract public function create( $args, $assoc_args );

	abstract protected function rollback();

	abstract public function dump_docker_compose_yml( $additional_filters = [] );

}

