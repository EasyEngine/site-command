<?php

declare( ticks=1 );

/**
 * Creates a simple WordPress Website.
 *
 * ## EXAMPLES
 *
 *     # Create simple WordPress site
 *     $ ee site create example.com --wp
 *
 * @package ee-cli
 */

use \Symfony\Component\Filesystem\Filesystem;

class Site_Command extends EE_Site_Command {
	private $command;
	private $site_name;
	private $site_root;
	private $site_type;
	private $db;
	private $docker;
	private $level;
	private $logger;
	private $le;
	private $skip_chk;
	private $le_mail;
	private $fs;

	public function __construct() {
		$this->level = 0;
		$this->command = 'site';
		pcntl_signal( SIGTERM, [ $this, "rollback" ] );
		pcntl_signal( SIGHUP, [ $this, "rollback" ] );
		pcntl_signal( SIGUSR1, [ $this, "rollback" ] );
		pcntl_signal( SIGINT, [ $this, "rollback" ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, "cleanup" ], [ &$this ] );
		$this->db     = EE::db();
		$this->docker = EE::docker();
		$this->logger = EE::get_file_logger()->withName( 'site_command' );
		$this->fs     = new Filesystem();
	}

	/**
	 * Runs the standard WordPress Site installation.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--letsencrypt]
	 * : Enables ssl via letsencrypt certificate.
	 *
	 * [--type=<type>]
	 * : Type of the site to be created. Values: html,php,wp.
	 *
	 * [--skip-status-check]
	 * : Skips site status check.
	 */
	public function create( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site create start' );
		EE::warning( 'This is a beta version. Please don\'t use it in production.' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site_name = strtolower( \EE\Utils\remove_trailing_slash( $args[0] ) );
		$this->site_type = \EE\Utils\get_flag_value( $assoc_args, 'type', 'html' );
		if ( 'html' !== $this->site_type ) {
			EE::error( "Invalid site-type: $this->site_type" );
		}

		if ( $this->db::site_in_db( $this->site_name ) ) {
			EE::error( "Site $this->site_name already exists. If you want to re-create it please delete the older one using:\n`ee site delete $this->site_name`" );
		}

		$this->le       = \EE\Utils\get_flag_value( $assoc_args, 'letsencrypt' );
		$this->skip_chk = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );

		\EE\SiteUtils\init_checks();

		EE::log( 'Configuring project.' );

		$this->create_site();
		\EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * Lists the created websites.
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
	 * @subcommand list
	 */
	public function _list( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site list start' );

		$format   = \EE\Utils\get_flag_value( $assoc_args, 'format' );
		$enabled  = \EE\Utils\get_flag_value( $assoc_args, 'enabled' );
		$disabled = \EE\Utils\get_flag_value( $assoc_args, 'disabled' );

		$where = array();

		if ( $enabled && ! $disabled ) {
			$where['is_enabled'] = 1;
		} elseif ( $disabled && ! $enabled ) {
			$where['is_enabled'] = 0;
		}

		$sites = $this->db::select( array( 'sitename', 'is_enabled' ), $where );

		if ( ! $sites ) {
			EE::error( 'No sites found!' );
		}

		if ( 'text' === $format ) {
			foreach ( $sites as $site ) {
				EE::log( $site['sitename'] );
			}
		} else {
			$result = array_map(
				function ( $site ) {
					$site['site']   = $site['sitename'];
					$site['status'] = $site['is_enabled'] ? 'enabled' : 'disabled';

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
	 */
	public function delete( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site delete start' );
		$this->populate_site_info( $args );
		EE::confirm( "Are you sure you want to delete $this->site_name?", $assoc_args );
		$this->level = 5;
		$this->delete_site();
		\EE\Utils\delem_log( 'site delete end' );
	}


	/**
	 * Runs the acme le registration and authorization.
	 */
	private function init_le() {
		$client        = new Site_Letsencrypt();
		$this->le_mail = EE::get_runner()->config[ 'le-mail' ] ?? EE::input( 'Enter your mail id: ' );
		EE::get_runner()->ensure_present_in_config( 'le-mail', $this->le_mail );
		if ( ! $client->register( $this->le_mail ) ) {
			$this->le = false;

			return;
		}
		$wildcard = 'wpsubdom' === $this->site_type ? true : false;
		$domains  = $wildcard ? [ "*.$this->site_name", $this->site_name ] : [ $this->site_name ];
		if ( ! $client->authorize( $domains, $this->site_root, $wildcard ) ) {
			$this->le = false;

			return;
		}
		if ( $wildcard ) {
			echo \cli\Colors::colorize( "%YIMPORTANT:%n Run `ee site le $this->site_name` once the dns changes have propogated to complete the certification generation and installation.", null );
		} else {
			$this->le();
		}
	}


	/**
	 * Runs the acme le.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--force]
	 * : Force renewal.
	 */
	public function le( $args = [], $assoc_args = [] ) {
		if ( ! isset( $this->site_name ) ) {
			$this->populate_site_info( $args );
		}
		if ( ! isset( $this->le_mail ) ) {
			$this->le_mail = EE::get_config( 'le-mail' ) ?? EE::input( 'Enter your mail id: ' );
		}
		$force    = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$wildcard = 'wpsubdom' === $this->site_type ? true : false;
		$domains  = $wildcard ? [ "*.$this->site_name", $this->site_name ] : [ $this->site_name ];
		$client   = new Site_Letsencrypt();
		if ( ! $client->check( $domains, $wildcard ) ) {
			$this->le = false;

			return;
		}
		if ( $wildcard ) {
			$client->request( "*.$this->site_name", [ $this->site_name ], $this->le_mail, $force );
		} else {
			$client->request( $this->site_name, [], $this->le_mail, $force );
			$client->cleanup( $this->site_root );
		}
		EE::launch( 'docker exec ee-nginx-proxy sh -c "/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf; /usr/sbin/nginx -s reload"' );
	}

	/**
	 * Enables a website. It will start the docker containers of the website if they are stopped.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be enabled.
	 */
	public function up( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site enable start' );
		$args = \EE\SiteUtils\auto_site_name( $args, $this->command, __FUNCTION__ );
		$this->populate_site_info( $args );
		EE::log( "Enabling site $this->site_name." );
		if ( $this->docker::docker_compose_up( $this->site_root ) ) {
			$this->db::update( [ 'is_enabled' => '1' ], [ 'sitename' => $this->site_name ] );
			EE::success( "Site $this->site_name enabled." );
		} else {
			EE::error( "There was error in enabling $this->site_name. Please check logs." );
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
	 */
	public function down( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site disable start' );
		$args = \EE\SiteUtils\auto_site_name( $args, $this->command, __FUNCTION__ );
		$this->populate_site_info( $args );
		EE::log( "Disabling site $this->site_name." );
		if ( $this->docker::docker_compose_down( $this->site_root ) ) {
			$this->db::update( [ 'is_enabled' => '0' ], [ 'sitename' => $this->site_name ] );
			EE::success( "Site $this->site_name disabled." );
		} else {
			EE::error( "There was error in disabling $this->site_name. Please check logs." );
		}
		\EE\Utils\delem_log( 'site disable end' );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 */
	public function info( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_name ) ) {
			$args = \EE\SiteUtils\auto_site_name( $args, $this->command, __FUNCTION__ );
			$this->populate_site_info( $args );
		}
		$ssl = $this->le ? 'Enabled' : 'Not Enabled';
		EE::log( "Details for site $this->site_name:" );
		$prefix = ( $this->le ) ? 'https://' : 'http://';
		$info   = array(
			array( 'Site', $prefix . $this->site_name ),
			array( 'Access mailhog', $prefix . $this->site_name . '/ee-admin/mailhog/' ),
			array( 'Site Root', $this->site_root ),
			array( 'SSL', $ssl ),
		);

		\EE\Utils\format_table( $info );

		\EE\Utils\delem_log( 'site info end' );
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
	 */
	public function restart( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site restart start' );
		$args                 = \EE\SiteUtils\auto_site_name( $args, $this->command, __FUNCTION__ );
		$all                  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		$no_service_specified = count( $assoc_args ) === 0;

		$this->populate_site_info( $args );

		chdir( $this->site_root );

		if ( $all || $no_service_specified ) {
			$containers = [ 'nginx' ];
		} else {
			$containers = array_keys( $assoc_args );
		}

		foreach ( $containers as $container ) {
			EE\Siteutils\run_compose_command( 'restart', $container, null, 'all services' );
		}
		\EE\Utils\delem_log( 'site restart stop' );
	}

	/**
	 * Reload services in containers without restarting container(s) associated with site.
	 * When no service(--nginx etc.) is specified, all services will be reloaded.
	 *
	 * <site-name>
	 * : Name of the site.
	 *
	 * [--all]
	 * : Reload all services of site(which are supported).
	 *
	 * [--nginx]
	 * : Reload nginx service in container.
	 *
	 */
	public function reload( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site reload start' );
		$args                 = \EE\SiteUtils\auto_site_name( $args, $this->command, __FUNCTION__ );
		$all                  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		$no_service_specified = count( $assoc_args ) === 0;

		$this->populate_site_info( $args );

		chdir( $this->site_root );

		if ( $all || $no_service_specified ) {
			$this->reload_services( [ 'nginx' ] );
		} else {
			$this->reload_services( array_keys( $assoc_args ) );
		}
		\EE\Utils\delem_log( 'site reload stop' );
	}

	/**
	 * Executes reload commands. It needs seperate handling as commands to reload each service is different.
	 */
	private function reload_services( $services ) {
		$reload_command = [
			'nginx' => 'nginx sh -c \'nginx -t && service openresty reload\'',
		];

		foreach ( $services as $service ) {
			$this->run_compose_command( 'exec', $reload_command[$service], 'reload', $service );
		}
	}

	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files() {

		$site_conf_dir           = $this->site_root . '/config';
		$site_docker_yml         = $this->site_root . '/docker-compose.yml';
		$site_conf_env           = $this->site_root . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/default.conf';
		$server_name             = $this->site_name;
		$site_src_dir            = $this->site_root . '/app/src';
		$process_user            = posix_getpwuid( posix_geteuid() );

		EE::log( "Creating site $this->site_name." );
		EE::log( 'Copying configuration files.' );

		$filter                 = array();
		$filter[]               = $this->site_type;
		$filter[]               = $this->le;
		$site_docker            = new Site_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $default_conf_content   = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/default.conf.mustache', [ $this->site_name ] );

		$env_data    = [
			'virtual_host' => $this->site_name,
			'user_id'      => $process_user['uid'],
			'group_id'     => $process_user['gid'],
		];
		$env_content = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );

		try {
			$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			$this->fs->mkdir( $site_conf_dir );
			$this->fs->mkdir( $site_conf_dir . '/nginx' );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );

			$index_data = ['v'.EE_VERSION,$this->site_root];
			$index_html = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/index.html.mustache', $index_data );
			$this->fs->mkdir( $site_src_dir );
			$this->fs->dumpFile( $site_src_dir . '/index.html', $index_html );

			EE\Siteutils\add_site_redirects( $this->site_name, $this->le );

			EE::success( 'Configuration files copied.' );
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Function to create the site.
	 */
	private function create_site() {
		$this->site_root = WEBROOT . $this->site_name;
		$this->level     = 1;
		try {
			EE\Siteutils\create_site_root( $this->site_root, $this->site_name );
			$this->level = 2;
			EE\Siteutils\setup_site_network( $this->site_name );
			$this->level = 3;
			$this->configure_site_files();

			EE\Siteutils\start_site_containers( $this->site_root );

			EE\Siteutils\create_etc_hosts_entry( $this->site_name );
			if ( ! $this->skip_chk ) {
				$this->level = 4;
				EE\Siteutils\site_status_check( $this->site_name );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

		if ( $this->le ) {
			$this->init_le();
		}
		$this->info( [ $this->site_name ], [] );
		$this->create_site_db_entry();
	}

	/**
	 * Function to delete the given site.
	 *
	 * $this->level:
	 *  Level of deletion.
	 *  Level - 0: No need of clean-up.
	 *  Level - 1: Clean-up only the site-root.
	 *  Level - 2: Try to remove network. The network may or may not have been created.
	 *  Level - 3: Disconnect & remove network and try to remove containers. The containers may not have been created.
	 *  Level - 4: Remove containers.
	 *  Level - 5: Remove db entry.
	 */
	private function delete_site() {
		$proxy_type = EE_PROXY_TYPE;
		if ( $this->level >= 3 ) {
			if ( $this->docker::docker_compose_down( $this->site_root ) ) {
				EE::log( "[$this->site_name] Docker Containers removed." );
			} else {
				\EE\Utils\default_launch( "docker rm -f $(docker ps -q -f=label=created_by=EasyEngine -f=label=site_name=$this->site_name)" );
				if ( $this->level > 3 ) {
					EE::warning( 'Error in removing docker containers.' );
				}
			}

			$this->docker::disconnect_site_network_from( $this->site_name, $proxy_type );
		}

		if ( $this->level >= 2 ) {
			if ( $this->docker::rm_network( $this->site_name ) ) {
				EE::log( "[$this->site_name] Docker container removed from network $proxy_type." );
			} else {
				if ( $this->level > 2 ) {
					EE::warning( "Error in removing Docker container from network $proxy_type" );
				}
			}
		}

		if ( $this->fs->exists( $this->site_root ) ) {
			try {
				$this->fs->remove( $this->site_root );
			}
			catch ( Exception $e ) {
				EE::debug( $e );
				EE::error( 'Could not remove site root. Please check if you have sufficient rights.' );
			}
			EE::log( "[$this->site_name] site root removed." );
		}

		if ( $this->level > 4 ) {
			if ( $this->le ) {
				EE::log( 'Removing ssl certs.' );
				$crt_file   = EE_CONF_ROOT . "/nginx/certs/$this->site_name.crt";
				$key_file   = EE_CONF_ROOT . "/nginx/certs/$this->site_name.key";
				$conf_certs = EE_CONF_ROOT . "/acme-conf/certs/$this->site_name";
				$conf_var   = EE_CONF_ROOT . "/acme-conf/var/$this->site_name";

				$cert_files = [ $conf_certs, $conf_var, $crt_file, $key_file ];
				try {
					$this->fs->remove( $cert_files );
				}
				catch ( Exception $e ) {
					EE::warning( $e );
				}

			}
			if ( $this->db::delete( array( 'sitename' => $this->site_name ) ) ) {
				EE::log( 'Removing database entry.' );
			} else {
				EE::error( 'Could not remove the database entry' );
			}
		}
		EE::log( "Site $this->site_name deleted." );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {
		$ssl  = $this->le ? 1 : 0;
		$data = array(
			'sitename'   => $this->site_name,
			'site_type'  => $this->site_type,
			'site_path'  => $this->site_root,
			'is_ssl'     => $ssl,
			'created_on' => date( 'Y-m-d H:i:s', time() ),
		);

		try {
			if ( $this->db::insert( $data ) ) {
				EE::log( 'Site entry created.' );
			} else {
				throw new Exception( 'Error creating site entry in database.' );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Populate basic site info from db.
	 */
	private function populate_site_info( $args ) {

		$this->site_name = \EE\Utils\remove_trailing_slash( $args[0] );

		if ( $this->db::site_in_db( $this->site_name ) ) {

			$db_select = $this->db::select( [], array( 'sitename' => $this->site_name ) );

			$this->site_type = $db_select[0]['site_type'];
			$this->site_root = $db_select[0]['site_path'];
			$this->le        = $db_select[0]['is_ssl'];

		} else {
			EE::error( "Site $this->site_name does not exist." );
		}
	}

	/**
	 * Catch and clean exceptions.
	 *
	 * @param Exception $e
	 */
	private function catch_clean( $e ) {
		\EE\Utils\delem_log( 'site cleanup start' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up.' );
		$this->delete_site();
		\EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	private function rollback() {
		EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site();
		}
		EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

	/**
	 * Shutdown function to catch and rollback from fatal errors.
	 */
	private function shutDownFunction() {
		$error = error_get_last();
		if ( isset( $error ) ) {
			if ( $error['type'] === E_ERROR ) {
				EE::warning( 'An Error occurred. Initiating clean-up.' );
				$this->logger->error( 'Type: ' . $error['type'] );
				$this->logger->error( 'Message: ' . $error['message'] );
				$this->logger->error( 'File: ' . $error['file'] );
				$this->logger->error( 'Line: ' . $error['line'] );
				$this->rollback();
			}
		}
	}
}
