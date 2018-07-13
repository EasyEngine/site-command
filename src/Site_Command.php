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
class Site_Command extends EE_Command {
	private $site_name;
	private $site_root;
	private $site_type;
	private $site_title;
	private $site_user;
	private $site_pass;
	private $site_email;
	private $proxy_type;
	private $cache_type;
	private $db;
	private $docker;
	private $level;
	private $logger;
	private $le;
	private $db_name;
	private $db_user;
	private $db_root_pass;
	private $db_pass;
	private $db_host;
	private $db_port;
	private $locale;
	private $skip_install;
	private $skip_chk;
	private $force;
	private $le_mail;

	public function __construct() {
		$this->level = 0;
		pcntl_signal( SIGTERM, [ $this, "rollback" ] );
		pcntl_signal( SIGHUP, [ $this, "rollback" ] );
		pcntl_signal( SIGUSR1, [ $this, "rollback" ] );
		pcntl_signal( SIGINT, [ $this, "rollback" ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, "cleanup" ], [ &$this ] );
		$this->db     = EE::db();
		$this->docker = EE::docker();
		$this->logger = EE::get_file_logger()->withName( 'site_command' );
	}

	/**
	 * Runs the standard WordPress Site installation.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--wp]
	 * : WordPress website.
	 *
	 * [--wpredis]
	 * : Use redis for WordPress.
	 *
	 * [--wpsubdir]
	 * : WordPress sub-dir Multi-site.
	 *
	 * [--wpsubdom]
	 * : WordPress sub-domain Multi-site.
	 *
	 * [--title=<title>]
	 * : Title of your site.
	 *
	 * [--admin_user=<admin_user>]
	 * : Username of the administrator.
	 *
	 *  [--admin_pass=<admin_pass>]
	 * : Password for the the administrator.
	 *
	 * [--admin_email=<admin_email>]
	 * : E-Mail of the administrator.
	 *
	 * [--dbname=<dbname>]
	 * : Set the database name.
	 * ---
	 * default: wordpress
	 * ---
	 *
	 * [--dbuser=<dbuser>]
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host. Pass value only when remote dbhost is required.
	 * ---
	 * default: db
	 * ---
	 *
	 * [--dbprefix=<dbprefix>]
	 * : Set the database table prefix.
	 *
	 * [--dbcharset=<dbcharset>]
	 * : Set the database charset.
	 * ---
	 * default: utf8
	 * ---
	 *
	 * [--dbcollate=<dbcollate>]
	 * : Set the database collation.
	 *
	 * [--skip-check]
	 * : If set, the database connection is not checked.
	 *
	 * [--version=<version>]
	 * : Select which wordpress version you want to download. Accepts a version number, ‘latest’ or ‘nightly’.
	 *
	 * [--skip-content]
	 * : Download WP without the default themes and plugins.
	 *
	 * [--skip-install]
	 * : Skips wp-core install.
	 *
	 * [--skip-status-check]
	 * : Skips site status check.
	 *
	 * [--letsencrypt]
	 * : Enables ssl via letsencrypt certificate.
	 *
	 * [--force]
	 * : Resets the remote database if it is not empty.
	 */
	public function create( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site create start' );
		EE::warning( 'This is a beta version. Please don\'t use it in production.' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site_name = strtolower( \EE\Utils\remove_trailing_slash( $args[0] ) );
		$this->site_type = \EE\Utils\get_type( $assoc_args, [ 'wp', 'wpsubdom', 'wpsubdir' ], 'wp' );
		if ( false === $this->site_type ) {
			EE::error( 'Invalid arguments' );
		}

		if ( $this->db::site_in_db( $this->site_name ) ) {
			EE::error( "Site $this->site_name already exists. If you want to re-create it please delete the older one using:\n`ee site delete $this->site_name`" );
		}

		$this->proxy_type   = 'ee-nginx-proxy';
		$this->cache_type   = ! empty( $assoc_args['wpredis'] ) ? 'wpredis' : 'none';
		$this->le           = \EE\Utils\get_flag_value( $assoc_args, 'letsencrypt' );
		$this->site_title   = \EE\Utils\get_flag_value( $assoc_args, 'title', $this->site_name );
		$this->site_user    = \EE\Utils\get_flag_value( $assoc_args, 'admin_user', 'admin' );
		$this->site_pass    = \EE\Utils\get_flag_value( $assoc_args, 'admin_pass', \EE\Utils\random_password() );
		$this->db_name      = str_replace( [ '.', '-' ], '_', $this->site_name );
		$this->db_host      = \EE\Utils\get_flag_value( $assoc_args, 'dbhost' );
		$this->db_user      = \EE\Utils\get_flag_value( $assoc_args, 'dbuser', 'wordpress' );
		$this->db_pass      = \EE\Utils\get_flag_value( $assoc_args, 'dbpass', \EE\Utils\random_password() );
		$this->locale       = \EE\Utils\get_flag_value( $assoc_args, 'locale', EE::get_config( 'locale' ) );
		$this->db_root_pass = \EE\Utils\random_password();

		// If user wants to connect to remote database
		if ( 'db' !== $this->db_host ) {
			if ( ! isset( $assoc_args['dbuser'] ) || ! isset( $assoc_args['dbpass'] ) ) {
				EE::error( '`--dbuser` and `--dbpass` are required for remote db host.' );
			}
			$arg_host_port = explode( ':', $this->db_host );
			$this->db_host = $arg_host_port[0];
			$this->db_port = empty( $arg_host_port[1] ) ? '3306' : $arg_host_port[1];
		}

		$this->site_email   = \EE\Utils\get_flag_value( $assoc_args, 'admin_email', strtolower( 'mail@' . $this->site_name ) );
		$this->skip_install = \EE\Utils\get_flag_value( $assoc_args, 'skip-install' );
		$this->skip_chk     = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$this->force        = \EE\Utils\get_flag_value( $assoc_args, 'force' );

		$this->init_checks();

		EE::log( 'Configuring project.' );

		$this->create_site( $assoc_args );
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
	public function enable( $args ) {
		\EE\Utils\delem_log( 'site enable start' );
		$args = \EE\Utils\set_site_arg( $args, 'site enable' );
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
	public function disable( $args ) {
		\EE\Utils\delem_log( 'site disable start' );
		$args = \EE\Utils\set_site_arg( $args, 'site disable' );
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
	public function info( $args ) {
		\EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_name ) ) {
			$args = \EE\Utils\set_site_arg( $args, 'site info' );
			$this->populate_site_info( $args );
		}
		$ssl = $this->le ? 'Enabled' : 'Not Enabled';

		$prefix = ( $this->le ) ? 'https://' : 'http://';
		$info   = array(
			array( 'Site', $prefix . $this->site_name . '/' ),
			array( 'Admin Tools', $prefix . $this->site_name . '/ee-admin/' ),
		);
		
		if ( ! empty( $this->site_user ) && ! $this->skip_install ) {
			$info[] = array( 'WordPress Username', $this->site_user );
			$info[] = array( 'WordPress Password', $this->site_pass );
		}

		$info[] = array( 'DB Root Password', $this->db_root_pass );
		$info[] = array( 'DB Name', $this->db_name );
		$info[] = array( 'DB User', $this->db_user );
		$info[] = array( 'DB Password', $this->db_pass );
		$info[] = array( 'E-Mail', $this->site_email );
		$info[] = array( 'Cache Type', $this->cache_type );
		$info[] = array( 'SSL', $ssl );

		\EE\Utils\format_table( $info );

		\EE\Utils\delem_log( 'site info end' );
	}

	/**
	 * Starts containers associated with site.
	 * When no service(--mailhog etc.) is specified, all containers will be restarted.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Start all admin containers of site.
	 *
	 * [--mailhog]
	 * : Start mailhog container of site.
	 *
	 * [--phpmyadmin]
	 * : Start phpmyadmin container of site.
	 *
	 * [--phpredisadmin]
	 * : Start phpredisadmin container of site.
	 *
	 * [--adminer]
	 * : Start adminer container of site.
	 *
	 * [--anemometer]
	 * : Start anemometer container of site.
	 */
	public function start( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site start start' );
		$args = \EE\Utils\set_site_arg( $args, 'site start' );
		$this->site_docker_compose_execute( $args[0], 'up -d', $args, $assoc_args );
		\EE\Utils\delem_log( 'site start end' );
	}

	/**
	 * Stops containers associated with site.
	 * When no service(--mailhog etc.) is specified, all containers will be stopped.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Stop all admin containers of site.
	 *
	 * [--mailhog]
	 * : Stop mailhog container of site.
	 *
	 * [--phpmyadmin]
	 * : Stop phpmyadmin container of site.
	 *
	 * [--phpredisadmin]
	 * : Stop phpredisadmin container of site.
	 *
	 * [--adminer]
	 * : Stop adminer container of site.
	 *
	 * [--anemometer]
	 * : Stop anemometer container of site.
	 */
	public function stop( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site stop start' );
		$args = \EE\Utils\set_site_arg( $args, 'site stop' );
		$this->site_docker_compose_execute( $args[0], 'stop', $args, $assoc_args );
		\EE\Utils\delem_log( 'site stop end' );
	}

	/**
	 * Restarts containers associated with site.
	 * When no service(--mailhog etc.) is specified, all containers will be restarted.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Restart all admin containers of site.
	 *
	 * [--mailhog]
	 * : Restart mailhog container of site.
	 *
	 * [--phpmyadmin]
	 * : Restart phpmyadmin container of site.
	 *
	 * [--phpredisadmin]
	 * : Restart phpredisadmin container of site.
	 *
	 * [--adminer]
	 * : Restart adminer container of site.
	 *
	 * [--anemometer]
	 * : Restart anemometer container of site.
	 */
	public function restart( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site restart start' );
		$args = \EE\Utils\set_site_arg( $args, 'site restart' );
		$this->site_docker_compose_execute( $args[0], 'restart', $args, $assoc_args );
		\EE\Utils\delem_log( 'site restart end' );
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
	 * [--php]
	 * : Start php service in container.
	 */
	public function reload( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site reload start' );
		$args = \EE\Utils\set_site_arg( $args, 'site reload' );
		$this->site_docker_compose_execute( $args[0], 'reload', $args, $assoc_args );
		\EE\Utils\delem_log( 'site reload end' );
	}

	private function site_docker_compose_execute( $site, $action, $args, $assoc_args ) {
		$all                  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		$no_service_specified = count( $assoc_args ) === 0;

		if ( ! isset( $this->site_name ) ) {
			$this->populate_site_info( $args );
		}

		chdir( $this->site_root );

		if ( $all || $no_service_specified ) {
			if ( $action === 'reload' ) {
				$this->reload_services( [ 'nginx', 'php' ] );

				return;
			}
			$this->run_compose_command( $action, '', null, 'all services' );
		} else {
			$services = array_map( [ $this, 'map_args_to_service' ], array_keys( $assoc_args ) );

			if ( $action === 'reload' ) {
				$this->reload_services( $services );

				return;
			}

			foreach ( $services as $service ) {
				$action_to_display = 'up -d' === $action ? 'start' : null;
				$this->run_compose_command( $action, $service, $action_to_display );
			}
		}
	}


	/**
	 * Generic function to run a docker compose command. Must be ran inside correct directory.
	 */
	private function run_compose_command( $action, $container, $action_to_display = null, $service_to_display = null ) {
		$services        = [ 'mailhog' => 0, 'phpmyadmin' => 0 ];
		$db_actions      = [ 'up -d', 'stop' ];
		$display_action  = $action_to_display ? $action_to_display : $action;
		$display_service = $service_to_display ? $service_to_display : $container;

		\EE::log( ucfirst( $display_action ) . 'ing ' . $display_service );
		$run_compose_command = \EE\Utils\default_launch( "docker-compose $action $container", true, true );

		if ( $run_compose_command && in_array( $action, $db_actions ) ) {

			$db_val = 'stop' === $action ? 0 : 1;
			if ( empty( $container ) ) {
				foreach ( $services as $service => $val ) {
					$services[$service] = $db_val;
				}
			} else {
				$services = [ $container => $db_val ];
			}
			$this->db::update( $services, [ 'sitename' => $this->site_name ], 'services' );

		}
	}

	/**
	 * Executes reload commands. It needs seperate handling as commands to reload each service is different.
	 */
	private function reload_services( $services ) {
		$reload_command = [
			'nginx' => 'nginx sh -c \'nginx -t && service openresty reload\'',
			'php'   => 'php kill -USR2 1'
		];

		foreach ( $services as $service ) {
			$this->run_compose_command( 'exec', $reload_command[$service], 'reload', $service );
		}
	}

	/**
	 * Maps argument passed from cli to docker-compose service name
	 */
	private function map_args_to_service( $arg ) {
		$services_map = [
			'mysql' => 'db',
		];

		return in_array( $arg, array_keys( $services_map ) ) ? $services_map[$arg] : $arg;
	}

	/**
	 * Function to check all the required configurations needed to create the site.
	 *
	 * Boots up the container if it is stopped or not running.
	 */
	private function init_checks() {
		if ( 'running' !== $this->docker::container_status( $this->proxy_type ) ) {
			/**
			 * Checking ports.
			 */
			@fsockopen( 'localhost', 80, $port_80_exit_status );
			@fsockopen( 'localhost', 443, $port_443_exit_status );

			// if any/both the port/s is/are occupied.
			if ( ! ( $port_80_exit_status && $port_443_exit_status ) ) {
				EE::error( 'Cannot create/start proxy container. Please make sure port 80 and 443 are free.' );
			} else {
				$EE_CONF_ROOT     = EE_CONF_ROOT;
				$ee_proxy_command = "docker run --name $this->proxy_type -e LOCAL_USER_ID=`id -u` -e LOCAL_GROUP_ID=`id -g` --restart=always -d -p 80:80 -p 443:443 -v $EE_CONF_ROOT/nginx/certs:/etc/nginx/certs -v $EE_CONF_ROOT/nginx/dhparam:/etc/nginx/dhparam -v $EE_CONF_ROOT/nginx/conf.d:/etc/nginx/conf.d -v $EE_CONF_ROOT/nginx/htpasswd:/etc/nginx/htpasswd -v $EE_CONF_ROOT/nginx/vhost.d:/etc/nginx/vhost.d -v /var/run/docker.sock:/tmp/docker.sock:ro -v $EE_CONF_ROOT:/app/ee4 -v /usr/share/nginx/html easyengine/nginx-proxy:v" . EE_VERSION;


				if ( $this->docker::boot_container( $this->proxy_type, $ee_proxy_command ) ) {
					EE::success( "$this->proxy_type container is up." );
				} else {
					EE::error( "There was some error in starting $this->proxy_type container. Please check logs." );
				}
			}
		}

		$this->site_root = WEBROOT . $this->site_name;
		$this->create_site_root();
	}

	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site() {

		$site_conf_dir           = $this->site_root . '/config';
		$site_admin_dir          = $this->site_root . '/app/src/ee-admin';
		$site_docker_yml         = $this->site_root . '/docker-compose.yml';
		$site_conf_env           = $this->site_root . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/default.conf';
		$site_php_ini            = $site_conf_dir . '/php-fpm/php.ini';
		$server_name             = ( 'wpsubdom' === $this->site_type ) ? "$this->site_name *.$this->site_name" : $this->site_name;
		$process_user            = posix_getpwuid( posix_geteuid() );

		EE::log( "Creating WordPress site $this->site_name." );
		EE::log( 'Copying configuration files.' );

		$filter                 = array();
		$filter[]               = $this->site_type;
		$filter[]               = $this->cache_type;
		$filter[]               = $this->le;
		$filter[]               = $this->db_host;
		$site_docker            = new Site_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $this->generate_default_conf( $this->site_type, $this->cache_type, $server_name );
		$local                  = ( 'db' === $this->db_host ) ? true : false;
		$env_data               = [
			'local'         => $local,
			'virtual_host'  => $this->site_name,
			'root_password' => $this->db_root_pass,
			'database_name' => $this->db_name,
			'database_user' => $this->db_user,
			'user_password' => $this->db_pass,
			'wp_db_host'    => "$this->db_host:$this->db_port",
			'wp_db_user'    => $this->db_user,
			'wp_db_name'    => $this->db_name,
			'wp_db_pass'    => $this->db_pass,
			'user_id'       => $process_user['uid'],
			'group_id'      => $process_user['gid'],
		];
		$env_content            = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );
		$php_ini_content        = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/php-fpm/php.ini.mustache', [] );

		$this->add_site_redirects();

		try {
			if ( ! ( file_put_contents( $site_docker_yml, $docker_compose_content )
				&& file_put_contents( $site_conf_env, $env_content )
				&& mkdir( $site_conf_dir )
				&& mkdir( $site_admin_dir, 0755, true )
				&& copy( SITE_TEMPLATE_ROOT . '/admin-index.php.mustache', $site_admin_dir . '/index.php' )
				&& mkdir( $site_conf_dir . '/nginx' )
				&& file_put_contents( $site_nginx_default_conf, $default_conf_content )
				&& mkdir( $site_conf_dir . '/php-fpm' )
				&& file_put_contents( $site_php_ini, $php_ini_content ) ) ) {
				throw new Exception( 'Could not copy configuration files.' );
			}
			EE::success( 'Configuration files copied.' );
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Adds www to non-www redirection to site
	 */
	private function add_site_redirects() {
		$confd_path = EE_CONF_ROOT . '/nginx/conf.d/';
		$config_file_path = $confd_path . $this->site_name . '-redirect.conf';
		$has_www = strpos( $this->site_name, 'www.' ) === 0;
		$content = '';

		if( $has_www ) {
			$site_name_without_www = ltrim( $this->site_name, '.www' );
			// ee site create www.example.com --le
			if( $this->le ) {
				$content = "
server {
	listen  80;
	listen  443;
	server_name  $site_name_without_www;
	return  301 https://$this->site_name\$request_uri;
}";
			}
			// ee site create www.example.com
			else {
				$content = "
server {
	listen  80;
	server_name  $site_name_without_www;
	return  301 http://$this->site_name\$request_uri;
}";
			}
		}
		else {
			$site_name_with_www = 'www.' . $this->site_name;
			// ee site create example.com --le
			if( $this->le ) {

				$content = "
server {
	listen  80;
	listen  443;
	server_name  $site_name_with_www;
	return  301 https://$this->site_name\$request_uri;
}";
			}
			// ee site create example.com
			else {
				$content = "
server {
	listen  80;
	server_name  $site_name_with_www;
	return  301 http://$this->site_name\$request_uri;
}";
			}
		}
		file_put_contents( $config_file_path, ltrim( $content, PHP_EOL ) );
	}

	/**
	 * Function to generate default.conf from mustache templates.
	 *
	 * @param string $site_type   Type of site (wpsubdom, wpredis etc..)
	 * @param string $cache_type  Type of cache(wpredis or none)
	 * @param string $server_name Name of server to use in virtual_host
	 */
	private function generate_default_conf( $site_type, $cache_type, $server_name ) {
		$default_conf_data['site_type']             = $site_type;
		$default_conf_data['server_name']           = $server_name;
		$default_conf_data['include_php_conf']      = $cache_type !== 'wpredis';
		$default_conf_data['include_wpsubdir_conf'] = $site_type === 'wpsubdir';
		$default_conf_data['include_redis_conf']    = $cache_type === 'wpredis';

		return \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/default.conf.mustache', $default_conf_data );
	}

	/**
	 * Creates site root directory if does not exist.
	 * Throws error if it does exist.
	 */
	private function create_site_root() {

		if ( is_dir( $this->site_root ) ) {
			EE::error( "Webroot directory for site $this->site_name already exists." );
		}

		if ( ! \EE\Utils\default_launch( "mkdir $this->site_root" ) ) {
			EE::error( "Cannot create directory $this->site_root. Please check that folder permission allows easyengine to create directory there." );
		}

		try {
			$this->level = 1;
			$whoami      = EE::launch( "whoami", false, true );

			$terminal_username = rtrim( $whoami->stdout );

			if ( ! chown( $this->site_root, $terminal_username ) ) {
				throw new Exception( 'Could not change ownership of the site root. Please make sure you have appropriate rights.' );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

		return true;
	}

	private function maybe_verify_remote_db_connection() {
		if ( 'db' === $this->db_host ) {
			return;
		}

		// Docker needs special handling if we want to connect to host machine.
		// The since we're inside the container and we want to access host machine,
		// we would need to replace localhost with default gateway
		if ( $this->db_host === '127.0.0.1' || $this->db_host === 'localhost' ) {
			$launch = EE::launch( "docker network inspect $this->site_name --format='{{ (index .IPAM.Config 0).Gateway }}'", false, true );
			\EE\Utils\default_debug( $launch );

			if ( ! $launch->return_code ) {
				$this->db_host = trim( $launch->stdout, "\n" );
			} else {
				throw new Exception( 'There was a problem inspecting network. Please check the logs' );
			}
		}
		\EE::log( 'Verifying connection to remote database' );

		if ( ! \EE\Utils\default_launch( "docker run -it --rm --network='$this->site_name' mysql sh -c \"mysql --host='$this->db_host' --port='$this->db_port' --user='$this->db_user' --password='$this->db_pass' --execute='EXIT'\"" ) ) {
			throw new Exception( 'Unable to connect to remote db' );
		}

		\EE::success( 'Connection to remote db verified' );
	}

	/**
	 * Function to create the site.
	 */
	private function create_site( $assoc_args ) {
		$this->setup_site_network();
		try {
			$this->maybe_verify_remote_db_connection();
			$this->configure_site();
			$this->level = 3;
			EE::log( 'Pulling latest images. This may take some time.' );
			chdir( $this->site_root );
			\EE\Utils\default_launch( 'docker-compose pull' );
			EE::log( 'Starting site\'s services.' );
			if ( ! $this->docker::docker_compose_up( $this->site_root, [ 'nginx' ] ) ) {
				throw new Exception( 'There was some error in docker-compose up.' );
			}
			if ( 'wpredis' === $this->cache_type ) {
				$this->docker::docker_compose_up( $this->site_root, [ 'redis' ] );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

		$this->wp_download_and_config( $assoc_args );

		if ( ! $this->skip_install ) {
			$this->create_etc_hosts_entry();
			if ( ! $this->skip_chk ) {
				$this->site_status_check();
			}
			$this->install_wp();
		}
		if ( $this->le ) {
			$this->init_le();
		}
		$this->info( array( $this->site_name ) );
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

		// Commented below block intentionally as they need change in DB
		// which should be discussed with the team
		if ( 'db' !== $this->db_host && $this->level >= 4 ) {

			chdir( $this->site_root );
			$delete_db_command = "docker-compose exec php bash -c \"mysql --host=$this->db_host --port=$this->db_port --user=$this->db_user --password=$this->db_pass --execute='DROP DATABASE $this->db_name'\"";

			if ( \EE\Utils\default_launch( $delete_db_command ) ) {
				EE::log( 'Database deleted.' );
			} else {
				EE::warning( 'Could not remove the database.' );
			}
		}

		if ( $this->level >= 3 ) {
			if ( $this->docker::docker_compose_down( $this->site_root ) ) {
				EE::log( "[$this->site_name] Docker Containers removed." );
			} else {
				\EE\Utils\default_launch( "docker rm -f $(docker ps -q -f=label=created_by=EasyEngine -f=label=site_name=$this->site_name)" );
				if ( $this->level > 3 ) {
					EE::warning( 'Error in removing docker containers.' );
				}
			}

			$this->docker::disconnect_site_network_from( $this->site_name, $this->proxy_type );
		}

		if ( $this->level >= 2 ) {
			if ( $this->docker::rm_network( $this->site_name ) ) {
				EE::log( "[$this->site_name] Docker container removed from network $this->proxy_type." );
			} else {
				if ( $this->level > 2 ) {
					EE::warning( "Error in removing Docker container from network $this->proxy_type" );
				}
			}
		}

		if ( is_dir( $this->site_root ) ) {
			if ( ! \EE\Utils\default_launch( "rm -rf $this->site_root" ) ) {
				EE::error( 'Could not remove site root. Please check if you have sufficient rights.' );
			}
			EE::log( "[$this->site_name] site root removed." );
		}

		if ( $this->level > 4 ) {
			if ( $this->le ) {
				EE::log( 'Removing ssl certs.' );
				$crt_file = EE_CONF_ROOT . "/nginx/certs/$this->site_name.crt";
				$key_file = EE_CONF_ROOT . "/nginx/certs/$this->site_name.key";
				if ( file_exists( $crt_file ) ) {
					unlink( $crt_file );
				}
				if ( file_exists( $key_file ) ) {
					unlink( $key_file );
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
	 * Checking site is running or not [TESTING]
	 */
	private function site_status_check() {
		$this->level = 4;
		EE::log( 'Checking and verifying site-up status. This may take some time.' );
		$httpcode = '000';
		$ch       = curl_init( $this->site_name );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_NOBODY, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );

		$i = 0;
		try {
			while ( 200 !== $httpcode && 302 !== $httpcode ) {
				curl_exec( $ch );
				$httpcode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				echo '.';
				sleep( 2 );
				if ( $i ++ > 60 ) {
					break;
				}
			}
			if ( 200 !== $httpcode && 302 !== $httpcode ) {
				throw new Exception( 'Problem connecting to site!' );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

	}

	/**
	 * Function to setup site network.
	 */
	private function setup_site_network() {

		$this->level = 2;

		try {
			if ( $this->docker::create_network( $this->site_name ) ) {
				EE::success( 'Network started.' );
			} else {
				throw new Exception( 'There was some error in starting the network.' );
			}
			$this->level = 3;

			$this->docker::connect_site_network_to( $this->site_name, $this->proxy_type );
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	private function wp_download_and_config( $assoc_args ) {
		$core_download_args = array(
			'version',
			'skip-content',
		);

		$config_args = array(
			'dbprefix',
			'dbcharset',
			'dbcollate',
			'skip-check',
		);

		$core_download_arguments = '';
		if ( ! empty( $assoc_args ) ) {
			foreach ( $assoc_args as $key => $value ) {
				if ( in_array( $key, $core_download_args, true ) ) {
					$core_download_arguments .= ' --' . $key . '=' . $value;
				}
			}
		}

		$config_arguments = '';
		if ( ! empty( $assoc_args ) ) {
			foreach ( $assoc_args as $key => $value ) {
				if ( in_array( $key, $config_args, true ) ) {
					$config_arguments .= ' --' . $key . '=' . $value;
				}
			}
		}

		EE::log( 'Downloading and configuring WordPress.' );

		$chown_command = "docker-compose exec php chown -R www-data: /var/www/";
		\EE\Utils\default_launch( $chown_command );

		$core_download_command = "docker-compose exec --user='www-data' php wp core download --locale='" . $this->locale . "' " . $core_download_arguments;
		\EE\Utils\default_launch( $core_download_command );

		// TODO: Look for better way to handle mysql healthcheck
		if ( 'db' === $this->db_host ) {
			$mysql_unhealthy = true;
			$health_chk      = "docker-compose exec --user='www-data' php mysql --user='root' --password='$this->db_root_pass' --host='db' -e exit";
			$count           = 0;
			while ( $mysql_unhealthy ) {
				$mysql_unhealthy = ! \EE\Utils\default_launch( $health_chk );
				if ( $count ++ > 30 ) {
					break;
				}
				sleep( 1 );
			}
		}

		$db_host                  = is_null( $this->db_port ) ? $this->db_host : "$this->db_host:$this->db_port";
		$wp_config_create_command = "docker-compose exec --user='www-data' php wp config create --dbuser='$this->db_user' --dbname='$this->db_name' --dbpass='$this->db_pass' --dbhost='$db_host' $config_arguments " . '--extra-php="if ( isset( \$_SERVER[\'HTTP_X_FORWARDED_PROTO\'] ) && \$_SERVER[\'HTTP_X_FORWARDED_PROTO\'] == \'https\'){\$_SERVER[\'HTTPS\']=\'on\';}"';

		try {
			if ( ! \EE\Utils\default_launch( $wp_config_create_command ) ) {
				throw new Exception( "Couldn't connect to $this->db_host:$this->db_port or there was issue in `wp config create`. Please check logs." );
			}
			if ( 'db' !== $this->db_host ) {
				$name            = str_replace( '_', '\_', $this->db_name );
				$check_db_exists = "docker-compose exec php bash -c \"mysqlshow --user='$this->db_user' --password='$this->db_pass' --host='$this->db_host' --port='$this->db_port' '$name'";

				if ( ! \EE\Utils\default_launch( $check_db_exists ) ) {
					EE::log( "Database `$this->db_name` does not exist. Attempting to create it." );
					$create_db_command = "docker-compose exec php bash -c \"mysql --host=$this->db_host --port=$this->db_port --user=$this->db_user --password=$this->db_pass --execute='CREATE DATABASE $this->db_name;'\"";

					if ( ! \EE\Utils\default_launch( $create_db_command ) ) {
						throw new Exception( "Could not create database `$this->db_name` on `$this->db_host:$this->db_port`. Please check if $this->db_user has rights to create database or manually create a database and pass with `--dbname` parameter." );
					}
					$this->level = 4;
				} else {
					if ( $this->force ) {
						\EE\Utils\default_launch( "docker-compose exec --user='www-data' php wp db reset --yes" );
					}
					$check_tables = "docker-compose exec --user='www-data' php wp db tables";
					if ( \EE\Utils\default_launch( $check_tables ) ) {
						throw new Exception( "WordPress tables already seem to exist. Please backup and reset the database or use `--force` in the site create command to reset it." );
					}
				}
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

	}

	/**
	 * Function to create entry in /etc/hosts.
	 */
	private function create_etc_hosts_entry() {

		$host_line = LOCALHOST_IP . "\t$this->site_name";
		$etc_hosts = file_get_contents( '/etc/hosts' );
		if ( ! preg_match( "/\s+$this->site_name\$/m", $etc_hosts ) ) {
			if ( \EE\Utils\default_launch( "/bin/bash -c 'echo \"$host_line\" >> /etc/hosts'" ) ) {
				EE::success( 'Host entry successfully added.' );
			} else {
				EE::warning( "Failed to add $this->site_name in host entry, Please do it manually!" );
			}
		} else {
			EE::log( 'Host entry already exists.' );
		}
	}


	/**
	 * Install wordpress with given credentials.
	 */
	private function install_wp() {
		EE::log( "\nInstalling WordPress site." );
		chdir( $this->site_root );

		$wp_install_command   = 'install';
		$maybe_multisite_type = '';

		if ( 'wpsubdom' === $this->site_type || 'wpsubdir' === $this->site_type ) {
			$wp_install_command   = 'multisite-install';
			$maybe_multisite_type = $this->site_type === 'wpsubdom' ? '--subdomains' : '';
		}

		$install_command = "docker-compose exec --user='www-data' php wp core $wp_install_command --url='$this->site_name' --title='$this->site_title' --admin_user='$this->site_user'" . ( $this->site_pass ? " --admin_password='$this->site_pass'" : '' ) . " --admin_email='$this->site_email' $maybe_multisite_type";

		$core_install = \EE\Utils\default_launch( $install_command );

		if ( ! $core_install ) {
			EE::warning( 'WordPress install failed. Please check logs.' );
		}

		$prefix = ( $this->le ) ? 'https://' : 'http://';
		EE::success( $prefix . $this->site_name . " has been created successfully!" );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {
		$ssl  = $this->le ? 1 : 0;
		$data = array(
			'sitename'         => $this->site_name,
			'site_type'        => $this->site_type,
			'site_title'       => $this->site_title,
			'proxy_type'       => $this->proxy_type,
			'cache_type'       => $this->cache_type,
			'site_path'        => $this->site_root,
			'db_name'          => $this->db_name,
			'db_user'          => $this->db_user,
			'db_host'          => $this->db_host,
			'db_port'          => $this->db_port,
			'db_password'      => $this->db_pass,
			'db_root_password' => $this->db_root_pass,
			'email'            => $this->site_email,
			'is_ssl'           => $ssl,
			'created_on'       => date( 'Y-m-d H:i:s', time() ),
		);

		if ( ! $this->skip_install ) {
			$data['wp_user'] = $this->site_user;
			$data['wp_pass'] = $this->site_pass;
		}

		try {
			if ( $this->db::insert( $data ) && $this->db::insert( [ 'sitename' => $this->site_name ], 'services' ) ) {
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

			$db_select = $this->db::select( [], array( 'sitename' => $this->site_name ), 'sites', 1 );

			$this->site_type    = $db_select[0]['site_type'];
			$this->site_title   = $db_select[0]['site_title'];
			$this->proxy_type   = $db_select[0]['proxy_type'];
			$this->cache_type   = $db_select[0]['cache_type'];
			$this->site_root    = $db_select[0]['site_path'];
			$this->db_user      = $db_select[0]['db_user'];
			$this->db_name      = $db_select[0]['db_name'];
			$this->db_host      = $db_select[0]['db_host'];
			$this->db_port      = $db_select[0]['db_port'];
			$this->db_pass      = $db_select[0]['db_password'];
			$this->db_root_pass = $db_select[0]['db_root_password'];
			$this->site_user    = $db_select[0]['wp_user'];
			$this->site_pass    = $db_select[0]['wp_pass'];
			$this->site_email   = $db_select[0]['email'];
			$this->le           = $db_select[0]['is_ssl'];

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
