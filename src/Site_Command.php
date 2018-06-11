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
	private $locale;
	private $skip_install;

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
	 * ---
	 *  default: wordpress
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

		$this->proxy_type = 'ee_traefik';
		$this->cache_type = ! empty( $assoc_args['wpredis'] ) ? 'wpredis' : 'none';
		$this->le         = ! empty( $assoc_args['letsencrypt'] ) ? 'le' : false;
		$this->site_title = \EE\Utils\get_flag_value( $assoc_args, 'title', $this->site_name );
		$this->site_user  = \EE\Utils\get_flag_value( $assoc_args, 'admin_user', 'admin' );
		$this->site_pass  = \EE\Utils\get_flag_value( $assoc_args, 'admin_pass', \EE\Utils\random_password() );
		$this->db_name    = str_replace( '-', '_', str_replace( '.', '_', $this->site_name ) );
		$this->db_user    = \EE\Utils\get_flag_value( $assoc_args, 'dbuser', 'wordpress' );
		$this->db_pass    = \EE\Utils\get_flag_value( $assoc_args, 'dbpass', \EE\Utils\random_password() );
		$this->locale     = \EE\Utils\get_flag_value( $assoc_args, 'locale', EE::get_config( 'locale' ) );

		$this->db_root_pass = \EE\Utils\random_password();
		$this->site_email   = \EE\Utils\get_flag_value( $assoc_args, 'admin_email', strtolower( 'mail@' . $this->site_name ) );
		$this->skip_install = \EE\Utils\get_flag_value( $assoc_args, 'skip-install' );

		$this->init_checks();

		EE::log( 'Configuring project...' );

		$this->configure_site();
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
	 * @subcommand list
	 */
	public function _list( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site list start' );

		$enabled  = \EE\Utils\get_flag_value( $assoc_args, 'enabled' );
		$disabled = \EE\Utils\get_flag_value( $assoc_args, 'disabled' );

		$sites = $this->db::select( array( 'sitename', 'is_enabled' ) );

		if ( $sites ) {
			if ( $enabled || $disabled ) {
				if ( $enabled ) {
					$this->list_print( $sites, 'enabled', 1 );
				}
				if ( $disabled ) {
					$this->list_print( $sites, 'disabled', 0 );
				}
			} else {
				$this->list_print( $sites, 'all', 2 );
			}
		} else {
			EE::log( 'No sites found. Go create some!' );
		}
		\EE\Utils\delem_log( 'site list end' );
	}

	/**
	 * Print the list of sites according to parameters.
	 *
	 * @param array  $sites List of sites.
	 * @param String $type  Type of site to be listed - enabled/disabled/all.
	 * @param int    $check Enabled - 1, Disabled - 0, Both - 2.
	 */
	private function list_print( $sites, $type, $check ) {
		$count = 0;
		EE::log( "List of $type Sites:\n" );
		foreach ( $sites as $site ) {
			if ( 2 === $check || $check === $site['is_enabled'] ) {
				EE::log( $site['sitename'] );
				$count ++;
			}
		}
		if ( 0 === $count ) {
			EE::log( "No $type sites found!" );
		}
		EE::log( '' );
	}

	/**
	 * Deletes a website.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website to be deleted.
	 */
	public function delete( $args ) {
		\EE\Utils\delem_log( 'site delete start' );
		$this->populate_site_info( $args );
		$this->level = 5;
		$this->delete_site();
		\EE\Utils\delem_log( 'site delete end' );
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
		EE::log( "Enabling site $this->site_name..." );
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
	 * <site-name>
	 * : Name of website to be disabled.
	 */
	public function disable( $args ) {
		\EE\Utils\delem_log( 'site disable start' );
		$args = \EE\Utils\set_site_arg( $args, 'site disable' );
		$this->populate_site_info( $args );
		EE::log( "Disabling site $this->site_name..." );
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
	 * <site-name>
	 * : Name of the website whose info is required.
	 */
	public function info( $args ) {
		\EE\Utils\delem_log( 'site info start' );
		$args = \EE\Utils\set_site_arg( $args, 'site info' );
		if ( ! isset( $this->site_name ) ) {
			$this->populate_site_info( $args );
		}
		EE::log( "Details for site $this->site_name:" );
		$prefix = ( $this->le ) ? 'https://' : 'http://';
		$info   = array(
			array( 'Access phpMyAdmin', $prefix . $this->site_name . '/ee-admin/pma/' ),
			array( 'Access mail', $prefix . $this->site_name . '/ee-admin/mailhog/' ),
			array( 'Site Title', $this->site_title ),
			array( 'DB Root Password', $this->db_root_pass ),
			array( 'DB Name', $this->db_name ),
			array( 'DB User', $this->db_user ),
			array( 'DB Password', $this->db_pass ),
			array( 'E-Mail', $this->site_email ),
			array( 'Cache Type', $this->cache_type ),
		);

		if ( ! empty( $this->site_user ) && ! $this->skip_install ) {
			$info[] = array( 'WordPress Username', $this->site_user );
			$info[] = array( 'WordPress Password', $this->site_pass );
		}

		\EE\Utils\format_table( $info );

		\EE\Utils\delem_log( 'site info end' );
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

				if ( ! is_dir( EE_CONF_ROOT . '/traefik' ) ) {
					EE::debug( 'Creating traefik folder and config files.' );
					mkdir( EE_CONF_ROOT . '/traefik' );
				}

				touch( EE_CONF_ROOT . '/traefik/acme.json' );
				chmod( EE_CONF_ROOT . '/traefik/acme.json', 600 );
				$traefik_toml = new Site_Docker();
				file_put_contents( EE_CONF_ROOT . '/traefik/traefik.toml', $traefik_toml->generate_traefik_toml() );

				$ee_traefik_command = 'docker run -d -p 8080:8080 -p 80:80 -p 443:443 -l "traefik.enable=false" -v /var/run/docker.sock:/var/run/docker.sock -v ' . EE_CONF_ROOT . '/traefik/traefik.toml:/etc/traefik/traefik.toml -v ' . EE_CONF_ROOT . '/traefik/acme.json:/etc/traefik/acme.json -v ' . EE_CONF_ROOT . '/traefik/endpoints:/etc/traefik/endpoints -v ' . EE_CONF_ROOT . '/traefik/certs:/etc/traefik/certs -v ' . EE_CONF_ROOT . '/traefik/log:/var/log --name ee_traefik easyengine/traefik';

				if ( $this->docker::boot_container( $this->proxy_type, $ee_traefik_command ) ) {
					EE::success( "$this->proxy_type container is up." );
				} else {
					EE::error( "There was some error in starting $this->proxy_type container. Please check logs." );
				}
			}
		}
	}

	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site() {

		$this->site_root         = WEBROOT . $this->site_name;
		$site_conf_dir           = $this->site_root . '/config';
		$site_docker_yml         = $this->site_root . '/docker-compose.yml';
		$site_conf_env           = $this->site_root . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/default.conf';
		$site_php_ini            = $site_conf_dir . '/php-fpm/php.ini';
		$server_name             = ( 'wpsubdom' === $this->site_type ) ? "$this->site_name *.$this->site_name" : $this->site_name;
		$process_user            = posix_getpwuid( posix_geteuid() );

		if ( ! $this->create_site_root() ) {
			EE::error( "Webroot directory for site $this->site_name already exists." );
		}

		EE::log( "Creating WordPress site $this->site_name..." );
		EE::log( 'Copying configuration files...' );

		$filter                 = array();
		$filter[]               = $this->site_type;
		$filter[]               = $this->cache_type;
		$filter[]               = $this->le;
		$site_docker            = new Site_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $this->generate_default_conf( $this->site_type, $this->cache_type, $server_name );
		$env_data               = [
			'virtual_host'  => $this->site_name,
			'root_password' => $this->db_root_pass,
			'database_name' => $this->db_name,
			'database_user' => 'wordpress',
			'user_password' => $this->db_pass,
			'wp_db_host'    => 'db',
			'user_id'       => $process_user['uid'],
			'group_id'      => $process_user['gid'],
		];
		$env_content            = \EE\Utils\mustache_render( EE_CONFIG_TEMPLATE_ROOT . '/.env.mustache', $env_data );
		$php_ini_content        = \EE\Utils\mustache_render( EE_CONFIG_TEMPLATE_ROOT . '/php-fpm/php.ini.mustache', [] );

		try {
			if ( ! ( file_put_contents( $site_docker_yml, $docker_compose_content )
				&& file_put_contents( $site_conf_env, $env_content )
				&& mkdir( $site_conf_dir )
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

		return \EE\Utils\mustache_render( EE_CONFIG_TEMPLATE_ROOT . '/nginx/default.conf.mustache', $default_conf_data );
	}

	/**
	 * Function to create site root directory.
	 */
	private function create_site_root() {

		if ( is_dir( $this->site_root ) ) {
			return false;
		}

		try {
			if ( ! \EE\Utils\default_launch( "mkdir $this->site_root" ) ) {
				return false;
			}
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


	/**
	 * Function to create the site.
	 */
	private function create_site( $assoc_args ) {

		$this->setup_site_network();
		$this->level = 3;
		try {
			if ( ! $this->docker::docker_compose_up( $this->site_root ) ) {
				throw new Exception( 'There was some error in docker-compose up.' );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

		$this->wp_download_and_config( $assoc_args );

		if ( ! $this->skip_install ) {
			$this->create_etc_hosts_entry();
			$this->site_status_check();
			$this->install_wp();
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

		if ( $this->level >= 3 ) {
			if ( $this->docker::docker_compose_down( $this->site_root ) ) {
				EE::log( "[$this->site_name] Docker Containers removed." );
			} else {
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
			if ( ! \EE\Utils\default_launch( "sudo rm -rf $this->site_root" ) ) {
				EE::error( 'Could not remove site root. Please check if you have sufficient rights.' );
			}
			EE::log( "[$this->site_name] site root removed." );
		}
		if ( $this->level > 4 ) {
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

		$core_download_command = "docker-compose exec --user='www-data' php wp core download --locale='" . $this->locale . "' " . $core_download_arguments;
		EE::debug( 'COMMAND: ' . $core_download_command );
		EE::debug( 'STDOUT: ' . shell_exec( $core_download_command ) );

		$install_command = "docker-compose exec --user='www-data' php wp config create --dbuser='" . $this->db_user . "' --dbname='" . $this->db_name . "' --dbpass='" . $this->db_pass . "' --dbhost='db' " . $config_arguments;
		EE::debug( 'COMMAND: ' . $install_command );
		EE::debug( 'STDOUT: ' . shell_exec( $install_command ) );

	}

	/**
	 * Function to create entry in /etc/hosts.
	 */
	private function create_etc_hosts_entry() {

		$host_line = LOCALHOST_IP . "\t$this->site_name";
		$etc_hosts = file_get_contents( '/etc/hosts' );
		if ( ! preg_match( "/\s+$this->site_name\$/m", $etc_hosts ) ) {
			if ( \EE\Utils\default_launch( "sudo /bin/bash -c 'echo \"$host_line\" >> /etc/hosts'" ) ) {
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
		EE::log( "\nInstalling WordPress site..." );
		chdir( $this->site_root );
		$install_command = "docker-compose exec --user='www-data' php wp core install --url='" . $this->site_name . "' --title='" . $this->site_title . "' --admin_user='" . $this->site_user . "'" . ( ! $this->site_pass ? "" : " --admin_password='" . $this->site_pass . "'" ) . " --admin_email='" . $this->site_email . "'";

		EE::debug( 'COMMAND: ' . $install_command );
		EE::debug( 'STDOUT: ' . shell_exec( $install_command ) );

		if ( 'wpsubdom' === $this->site_type || 'wpsubdir' === $this->site_type ) {
			$type               = $this->site_type === 'wpsubdom' ? ' --subdomains' : '';
			$multi_type_command = "docker-compose exec --user='www-data' php wp core multisite-convert" . $type;
			EE::debug( 'COMMAND: ' . $multi_type_command );
			EE::debug( 'STDOUT: ' . shell_exec( $multi_type_command ) );
		}

		$prefix = ( $this->le ) ? 'https://' : 'http://';
		EE::success( $prefix . $this->site_name . " has been created successfully!" );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {
		$data = array(
			'sitename'         => $this->site_name,
			'site_type'        => $this->site_type,
			'site_title'       => $this->site_title,
			'proxy_type'       => $this->proxy_type,
			'cache_type'       => $this->cache_type,
			'site_path'        => $this->site_root,
			'db_name'          => $this->db_name,
			'db_user'          => $this->db_user,
			'db_password'      => $this->db_pass,
			'db_root_password' => $this->db_root_pass,
			'email'            => $this->site_email,
			'created_on'       => date( 'Y-m-d H:i:s', time() ),
		);

		if ( ! $this->skip_install ) {
			$data['wp_user'] = $this->site_user;
			$data['wp_pass'] = $this->site_pass;
		}

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

			$data = array( 'site_type', 'site_title', 'proxy_type', 'cache_type', 'site_path', 'db_name', 'db_user', 'db_password', 'db_root_password', 'wp_user', 'wp_pass', 'email' );

			$db_select = $this->db::select( $data, array( 'sitename' => $this->site_name ) );

			$this->site_type    = $db_select[0]['site_type'];
			$this->site_title   = $db_select[0]['site_title'];
			$this->proxy_type   = $db_select[0]['proxy_type'];
			$this->cache_type   = $db_select[0]['cache_type'];
			$this->site_root    = $db_select[0]['site_path'];
			$this->db_user      = $db_select[0]['db_user'];
			$this->db_name      = $db_select[0]['db_name'];
			$this->db_pass      = $db_select[0]['db_password'];
			$this->db_root_pass = $db_select[0]['db_root_password'];
			$this->site_user    = $db_select[0]['wp_user'];
			$this->site_pass    = $db_select[0]['wp_pass'];
			$this->site_email   = $db_select[0]['email'];

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
		EE::warning( 'Initiating clean-up...' );
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
				EE::warning( 'An Error occurred. Initiating clean-up...' );
				$this->logger->error( 'Type: ' . $error['type'] );
				$this->logger->error( 'Message: ' . $error['message'] );
				$this->logger->error( 'File: ' . $error['file'] );
				$this->logger->error( 'Line: ' . $error['line'] );
				$this->rollback();
			}
		}
	}
}
