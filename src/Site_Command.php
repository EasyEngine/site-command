<?php

declare( ticks=1 );

use function \EE\Utils\get_type;
use function \EE\Utils\copy_recursive;
use function \EE\Utils\remove_trailing_slash;
use function \EE\Utils\random_password;
use function \EE\Utils\delete_dir;
use function \EE\Utils\delem_log;
use function \EE\Utils\default_debug;
use function \EE\Utils\default_launch;


/**
 * Creates a simple WordPress Website.
 *
 * ## EXAMPLES
 *
 *     # Create simple WordPress site
 *     $ ee4 site create example.com --wp
 *
 * @package ee-cli
 */
class Site_Command extends EE_Command {
	private $site_name;
	private $site_root;
	private $site_type;
	private $multi_type;
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
	private $db_pass;

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
	 * [--letsencrypt]
	 * : Preconfigured letsencrypt supported website.
	 *
	 * [--title=<title>]
	 * : Title of your site.
	 *
	 * [--user=<username>]
	 * : Username of the administrator.
	 *
	 *  [--pass=<password>]
	 * : Password for the the administrator.
	 *
	 * [--email=<email>]
	 * : E-Mail of the administrator.
	 */
	public function create( $args, $assoc_args ) {
		delem_log( 'site create start' );
		EE::warning( 'This is a beta version. Please don\'t use it in production.' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site_name  = strtolower( remove_trailing_slash( $args[0] ) );
		$this->site_type  = get_type( $assoc_args, [ 'wp', 'wpredis' ], 'wp' );
		$this->multi_type = get_type( $assoc_args, [ 'wpsubdom', 'wpsubdir' ] );
		if ( false === $this->site_type ) {
			EE::error( 'Invalid arguments' );
		}

		$this->proxy_type = 'ee4_nginx-proxy';
		$this->cache_type = ! empty( $assoc_args['wpredis'] ) ? 'ee4_redis' : 'none';
		$this->site_title = ! empty( $assoc_args['title'] ) ? $assoc_args['title'] : $this->site_name;
		$this->site_user  = ! empty( $assoc_args['user'] ) ? $assoc_args['user'] : 'admin';
		$this->site_pass  = ! empty( $assoc_args['pass'] ) ? $assoc_args['pass'] : random_password();
		$this->db_pass    = random_password();
		$this->site_email = ! empty( $assoc_args['email'] ) ? $assoc_args['email'] : strtolower( 'mail@' . $this->site_name );
		$this->le         = ! empty( $assoc_args['letsencrypt'] ) ? true : false;

		$this->init_checks();
		if ( 'none' !== $this->cache_type ) {
			$this->cache_checks();
		}

		EE::log( 'Configuring project...' );

		$this->configure_site();
		$this->create_site();
		delem_log( 'site create end' );
	}

	/**
	 * Lists the created websites.
	 *
	 * @subcommand list
	 */
	public function _list() {
		delem_log( 'site list start' );
		$sites = $this->db::select( array( 'sitename' ) );
		if ( $sites ) {
			EE::log( "List of Sites:\n" );
			foreach ( $sites as $site ) {
				EE::log( " - " . $site['sitename'] );
			}
			EE::log( '' );
		} else {
			EE::log( 'No sites found. Go create some!' );
		}
		delem_log( 'site list end' );
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
		delem_log( 'site delete start' );

		$this->site_name = remove_trailing_slash( $args[0] );
		if ( $this->db::site_in_db( $this->site_name ) ) {

			$db_select = $this->db::select( array( 'cache_type', 'proxy_type', 'site_path' ), array( 'sitename' => $this->site_name ) );

			$this->proxy_type = $db_select[0]['proxy_type'];
			$this->cache_type = $db_select[0]['cache_type'];
			$this->site_root  = $db_select[0]['site_path'];
			$this->level      = 5;
			$this->delete_site();
		} else {
			EE::error( "Site $this->site_name does not exist." );
		}
		delem_log( 'site delete end' );
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
				if ( $this->docker::boot_container( $this->proxy_type ) ) {
					EE::success( "$this->proxy_type container is up." );
				} else {
					EE::error( "There was some error in starting $this->proxy_type container. Please check logs." );
				}
			}
		}
	}

	/**
	 * Function to check if the cache server is running.
	 *
	 * Boots up the container if it is stopped or not running.
	 */
	private function cache_checks() {
		if ( ! file_exists( EE_CONF_ROOT . '/redis' ) ) {
			copy_recursive( EE_SITE_CONF_ROOT . '/redis', EE_CONF_ROOT . '/redis' );
		}
		$this->docker::boot_container( $this->cache_type );
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

		$ee_conf = EE_SITE_CONF_ROOT . "/$this->site_type/config";

		if ( ! $this->create_site_root() ) {
			EE::error( "Webroot directory for site $this->site_name already exists." );
		}
		EE::log( "Creating WordPress site $this->site_name..." );
		EE::log( 'Copying configuration files...' );
		$filter = array();
		( ! $this->le ) ?: $filter[] = 'le';
		( ! $this->multi_type ) ?: $filter[] = $this->multi_type;
		$docker_compose_content = $this->docker::generate_docker_composer_yml( $filter );

		try {
			if ( ! ( copy_recursive( $ee_conf, $site_conf_dir )
				&& file_put_contents( $site_docker_yml, $docker_compose_content )
				&& rename( "$site_conf_dir/.env.example", $site_conf_env ) ) ) {
				throw new Exception( 'Could not copy configuration files.' );
			}

			if ( 'wpsubdir' === $this->multi_type ) {
				copy_recursive( EE_SITE_CONF_ROOT . "/$this->multi_type/config", $site_conf_dir );
			}


			EE::success( 'Configuration files copied.' );

			// Updating config file.
			$server_name = ( 'wpsubdom' === $this->multi_type ) ? "$this->site_name *.$this->site_name" : $this->site_name;
			EE::log( 'Updating configuration files...' );
			EE::success( 'Configuration files updated.' );
			if ( ! ( file_put_contents( $site_conf_env, str_replace( [ '{V_HOST}', 'password' ], [ $this->site_name, $this->db_pass ], file_get_contents( $site_conf_env ) ) )
				&& ( file_put_contents( $site_nginx_default_conf, str_replace( '{V_HOST}', $server_name, file_get_contents( $site_nginx_default_conf ) ) ) ) ) ) {
				throw new Exception( 'Could not modify configuration files.' );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}


	/**
	 * Function to create site root directory.
	 */
	private function create_site_root() {

		if ( is_dir( $this->site_root ) ) {
			return false;
		}

		try {
			if ( ! default_launch( "mkdir $this->site_root" ) ) {
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
	private function create_site() {

		$this->setup_site_network();
		$this->level = 3;
		try {
			$this->docker::docker_compose_up( $this->site_root );
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
		$this->create_etc_hosts_entry();
		$this->site_status_check();
		$this->install_wp();
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

			if ( 'none' !== $this->cache_type ) {
				if ( $this->docker::disconnect_network( $this->site_name, $this->cache_type ) ) {
					EE::log( "[$this->site_name] Disconnected from Docker network $this->cache_type" );
				} else {
					EE::warning( "Error in disconnecting from Docker network $this->cache_type" );
				}
			}

			if ( $this->docker::disconnect_network( $this->site_name, $this->proxy_type ) ) {
				EE::log( "[$this->site_name] Disconnected from Docker network $this->proxy_type" );
			} else {
				EE::warning( "Error in disconnecting from Docker network $this->proxy_type" );
			}

		}

		if ( $this->level >= 2 ) {
			if ( $this->docker::rm_network( $this->site_name ) ) {
				EE::log( "[$this->site_name] Docker network $this->proxy_type removed." );
			} else {
				if ( $this->level > 2 ) {
					EE::warning( "Error in removing Docker network $this->proxy_type" );
				}
			}
		}

		if ( is_dir( $this->site_root ) ) {
			if ( ! default_launch( "sudo rm -rf $this->site_root" ) ) {
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
	 * Function to setup site networking and connect given proxy.
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

			if ( $this->docker::connect_network( $this->site_name, $this->proxy_type ) ) {
				EE::success( "Site connected to $this->proxy_type." );
			} else {
				throw new Exception( "There was some error connecting to $this->proxy_type." );
			}
			if ( 'none' !== $this->cache_type ) {
				if ( $this->docker::connect_network( $this->site_name, $this->cache_type ) ) {
					EE::success( "Site connected to $this->cache_type." );
				} else {
					throw new Exception( "There was some error connecting to $this->cache_type." );
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
			$host_line .= "\n" . LOCALHOST_IP . "\tmail.$this->site_name";
			$host_line .= "\n" . LOCALHOST_IP . "\tpma.$this->site_name";

			if ( default_launch( "sudo /bin/bash -c 'echo \"$host_line\" >> /etc/hosts'" ) ) {
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

		if ( $this->multi_type ) {
			$type               = $this->multi_type === 'wpsubdom' ? ' --subdomains' : '';
			$multi_type_command = "docker-compose exec --user='www-data' php wp core multisite-convert" . $type;
			EE::debug( 'COMMAND: ' . $multi_type_command );
			EE::debug( 'STDOUT: ' . shell_exec( $multi_type_command ) );
		}

		EE::success( "http://" . $this->site_name . " has been created successfully!" );
		EE::log( "Access phpMyAdmin:\tpma.$this->site_name" );
		EE::log( "Access mail:\tmail.$this->site_name" );
		EE::log( "Site Title :\t" . $this->site_title . "\nUsername :\t" . $this->site_user . "\nPassword :\t" . $this->site_pass . "\nDB Password :\t" . $this->db_pass );
		EE::log( "E-Mail :\t" . $this->site_email );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {

		$data = array(
			'sitename'   => $this->site_name,
			'site_type'  => $this->site_type,
			'proxy_type' => $this->proxy_type,
			'cache_type' => $this->cache_type,
			'site_path'  => $this->site_root,
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
	 * Catch and clean exceptions.
	 *
	 * @param Exception $e
	 */
	private function catch_clean( $e ) {
		delem_log( 'site cleanup start' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up...' );
		$this->delete_site();
		delem_log( 'site cleanup end' );
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
