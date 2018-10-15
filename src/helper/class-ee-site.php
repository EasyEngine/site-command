<?php

namespace EE\Site\Type;

use EE;
use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\reload_global_nginx_proxy;

/**
 * Base class for Site command
 *
 * @package ee
 */
abstract class EE_Site_Command {
	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

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

	public function __construct() {

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
			if ( \EE::docker()::docker_compose_down( $site_fs_path ) ) {
				\EE::log( "[$site_url] Docker Containers removed." );
			} else {
				\EE::exec( "docker rm -f $(docker ps -q -f=label=created_by=EasyEngine -f=label=site_name=$site_url)" );
				if ( $level > 3 ) {
					\EE::warning( 'Error in removing docker containers.' );
				}
			}
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


		if ( $level > 4 ) {
			if ( $this->site_data['site_ssl'] ) {
				\EE::log( 'Removing ssl certs.' );
				$crt_file   = EE_ROOT_DIR . "/services/nginx-proxy/certs/$site_url.crt";
				$key_file   = EE_ROOT_DIR . "/services/nginx-proxy/$site_url.key";
				$conf_certs = EE_ROOT_DIR . "/services/nginx-proxy/acme-conf/certs/$site_url";
				$conf_var   = EE_ROOT_DIR . "/services/nginx-proxy/acme-conf/var/$site_url";

				$cert_files = [ $conf_certs, $conf_var, $crt_file, $key_file ];
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
	 * Enables a website. It will start the docker containers of the website if they are stopped.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be enabled.
	 *
	 * [--force]
	 * : Force execution of site up.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable site
	 *     $ ee site enable example.com
	 *
	 */
	public function enable( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site enable start' );
		$force           = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );

		if ( $this->site_data->site_enabled && ! $force ) {
			\EE::error( sprintf( '%s is already enabled!', $this->site_data->site_url ) );
		}

		\EE::log( sprintf( 'Enabling site %s.', $this->site_data->site_url ) );

		if ( \EE::docker()::docker_compose_up( $this->site_data->site_fs_path ) ) {
			$this->site_data->site_enabled = 1;
			$this->site_data->save();
			\EE::success( sprintf( 'Site %s enabled.', $this->site_data->site_url ) );
		} else {
			\EE::error( sprintf( 'There was error in enabling %s. Please check logs.', $this->site_data->site_url ) );
		}
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

		if ( \EE::docker()::docker_compose_down( $this->site_data->site_fs_path ) ) {
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
	 * Function to add site redirects and initialise ssl process.
	 *
	 * @param array $containers_to_start Containers to start for that site. Default, empty will start all.
	 *
	 * @throws EE\ExitException
	 * @throws \Exception
	 */
	protected function www_ssl_wrapper( $containers_to_start = [] ) {
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

		$is_www_or_non_www_pointed = $this->check_www_or_non_www_domain( $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		if ( ! $is_www_or_non_www_pointed ) {
			$fs          = new Filesystem();
			$confd_path  = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/';
			$config_file = $confd_path . $this->site_data['site_url'] . '-redirect.conf';
			$fs->remove( $config_file );
			\EE\Site\Utils\reload_global_nginx_proxy();
		}

		if ( $this->site_data['site_ssl'] ) {
			$this->init_ssl( $this->site_data['site_url'], $this->site_data['site_fs_path'], $this->site_data['site_ssl'], $this->site_data['site_ssl_wildcard'] );

			if ( $is_www_or_non_www_pointed ) {
				\EE\Site\Utils\add_site_redirects( $this->site_data['site_url'], true, 'inherit' === $this->site_data['site_ssl'], $is_www_or_non_www_pointed );
			}

			$this->dump_docker_compose_yml( [ 'nohttps' => false ] );
			\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], $containers_to_start );

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
	protected function inherit_certs( $site_url ) {

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

		// We don't have to do anything now as nginx-proxy handles everything for us.
		\EE::success( 'Inherited certs from parent' );
	}

	/**
	 * Runs SSL procedure.
	 *
	 * @param string $site_url     Name of the site for ssl.
	 * @param string $site_fs_path Webroot of the site.
	 * @param string $ssl_type     Type of ssl cert to issue.
	 * @param bool $wildcard       SSL with wildcard or not.
	 * @param bool $add_le_on_www  Allow LetsEncrypt on www subdomain.
	 *
	 * @throws \EE\ExitException If --ssl flag has unrecognized value.
	 * @throws \Exception
	 */
	protected function init_ssl( $site_url, $site_fs_path, $ssl_type, $wildcard = false, $add_le_on_www = false ) {

		\EE::debug( 'Starting SSL procedure' );
		if ( 'le' === $ssl_type ) {
			\EE::debug( 'Initializing LE' );
			$this->init_le( $site_url, $site_fs_path, $wildcard, $add_le_on_www );
		} elseif ( 'inherit' === $ssl_type ) {
			if ( $wildcard ) {
				throw new \Exception( 'Cannot use --wildcard with --ssl=inherit', false );
			}
			\EE::debug( 'Inheriting certs' );
			$this->inherit_certs( $site_url );
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
	 * @param bool $add_le_on_www  Allow LetsEncrypt on www subdomain.
	 */
	protected function init_le( $site_url, $site_fs_path, $wildcard = false, $add_le_on_www = false ) {

		\EE::debug( 'Wildcard in init_le: ' . ( bool ) $wildcard );

		$this->site_data['site_url']          = $site_url;
		$this->site_data['site_fs_path']      = $site_fs_path;
		$this->site_data['site_ssl_wildcard'] = $wildcard;
		$client                               = new Site_Letsencrypt();
		$this->le_mail                        = \EE::get_runner()->config['le-mail'] ?? \EE::input( 'Enter your mail id: ' );
		\EE::get_runner()->ensure_present_in_config( 'le-mail', $this->le_mail );
		if ( ! $client->register( $this->le_mail ) ) {
			$this->site_data['site_ssl'] = null;

			return;
		}

		$domains = $this->get_cert_domains( $site_url, $wildcard, $add_le_on_www );

		if ( ! $client->authorize( $domains, $wildcard ) ) {
			return;
		}
		if ( $wildcard ) {
			echo \cli\Colors::colorize( '%YIMPORTANT:%n Run `ee site ssl ' . $this->site_data['site_url'] . '` once the DNS changes have propagated to complete the certification generation and installation.', null );
		} else {
			$this->ssl( [], [] );
		}
	}

	/**
	 * Returns all domains required by cert
	 *
	 * @param string $site_url    Name of site
	 * @param $wildcard           Wildcard cert required?
	 * @param bool $add_le_on_www Allow LetsEncrypt on www subdomain.
	 *
	 * @return array
	 */
	private function get_cert_domains( string $site_url, $wildcard, $add_le_on_www = false ): array {

		$domains = [ $site_url ];
		if ( $wildcard ) {
			$domains[] = "*.{$site_url}";
		} else {
			if ( true === $add_le_on_www ) {
				$domains[] = $this->get_www_domain( $site_url );
			}
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
		$file_path     = $site_path . '/app/src/check.html';
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
	 * If the domain has www in it, returns a domain without www in it.
	 * Else returns a domain with www in it.
	 *
	 * @param string $site_url Name of site
	 *
	 * @return string Domain name with or without www
	 */
	private function get_www_domain( string $site_url ): string {

		$has_www = ( strpos( $site_url, 'www.' ) === 0 );

		if ( $has_www ) {
			return ltrim( $site_url, 'www.' );
		} else {
			return 'www.' . $site_url;
		}
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
	public function ssl( $args = [], $assoc_args = [] ) {

		EE::log( 'Starting SSL verification.' );

		// This checks if this method was called internally by ee or by user
		$called_by_ee = isset( $this->site_data['site_url'] );

		if ( ! $called_by_ee ) {
			$this->site_data = get_site_info( $args );
		}

		if ( ! isset( $this->le_mail ) ) {
			$this->le_mail = \EE::get_config( 'le-mail' ) ?? \EE::input( 'Enter your mail id: ' );
		}

		$force   = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$domains = $this->get_cert_domains( $this->site_data['site_url'], $this->site_data['site_ssl_wildcard'] );
		$client  = new Site_Letsencrypt();

		try {
			$client->check( $domains, $this->site_data['site_ssl_wildcard'] );
		} catch ( \Exception $e ) {
			if ( $called_by_ee ) {
				throw $e;
			}
			EE::error( 'Failed to verify SSL: ' . $e->getMessage() );
			return;
		}

		$san = array_values( array_diff( $domains, [ $this->site_data['site_url'] ] ) );
		$client->request( $this->site_data['site_url'], $san, $this->le_mail, $force );

		if ( ! $this->site_data['site_ssl_wildcard'] ) {
			$client->cleanup();
		}

		reload_global_nginx_proxy();

		EE::log( 'SSL verification completed.' );
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

	/*
	 *  Check site count for maximum 27 sites.
	 */
	protected function check_site_count() {
		$sites = Site::all();

		if( 27 > count( $sites) ) {
			return;
		}

		\EE::error( 'You can not create more than 30 sites' );
	}

	abstract public function create( $args, $assoc_args );

	abstract protected function rollback();

	abstract protected function dump_docker_compose_yml( $additional_filters = [] );

}
