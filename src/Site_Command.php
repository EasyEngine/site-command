<?php

declare( ticks=1 );

use function \EE\Utils\get_flag_value;
use function \EE\Utils\copy_recursive;
use function \EE\Utils\remove_trailing_slash;
use function \EE\Utils\random_password;
use function \EE\Utils\delete_dir;


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
	private $site_title;
	private $site_user;
	private $site_pass;
	private $site_email;
	private $env;
	private $site_conf_env;
	private $proxy_type;
	private $db;
	private $level;
	private $logger;

	public function __construct() {
		$this->level = 0;
		pcntl_signal( SIGTERM, [ $this, "rollback" ] );
		pcntl_signal( SIGHUP, [ $this, "rollback" ] );
		pcntl_signal( SIGUSR1, [ $this, "rollback" ] );
		pcntl_signal( SIGINT, [ $this, "rollback" ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, "cleanup" ], [ &$this ] );
		$this->db     = EE::db();
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
	 * [--php]
	 * : PHP website.
	 *
	 * [--html]
	 * : HTML website.
	 *
	 * [--mysql]
	 * : PHP + MySql website.
	 *
	 * [--traefik-proxy]
	 * : Use traefik proxy.
	 *
	 * [--nginx-proxy]
	 * : Use nginx proxy.
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
		$this->logger->info( '========================site create start========================' );

		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );

		$this->site_name  = strtolower( remove_trailing_slash( $args[0] ) );
		$this->site_type  = ! empty( $this->get_site_type( $assoc_args ) ) ? $this->get_site_type( $assoc_args ) : 'wp';
		$this->proxy_type = ! empty( $assoc_args['traefik-proxy'] ) ? 'traefik-proxy' : 'nginx-proxy';
		$this->site_title = ! empty( $assoc_args['title'] ) ? $assoc_args['title'] : $this->site_name;
		$this->site_user  = ! empty( $assoc_args['user'] ) ? $assoc_args['user'] : 'admin';
		$this->site_pass  = ! empty( $assoc_args['pass'] ) ? $assoc_args['pass'] : random_password();
		$this->site_email = ! empty( $assoc_args['email'] ) ? $assoc_args['email'] : strtolower( 'mail@' . $this->site_name );

		$this->init_checks();

		EE::log( 'Configuring project...' );

		$this->configure_site();
		$this->create_site();
		$this->logger->info( '========================site create end========================' );
	}

	/**
	 * Lists the created websites.
	 */
	public function list() {
		$this->logger->info( '========================site list start========================' );
		$sites = $this->db::select( array( 'sitename' ) );
		if ( $sites ) {
			EE::log( "List of Sites:\n" );
			foreach ( $sites as $site ) {
				EE::log( " - " . $site['sitename'] );
			}
			EE::log( '' );
		} else {
			EE::warning( 'No sites found. Go create some!' );
		}
		$this->logger->info( '========================site list end========================' );
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
		$this->logger->info( '========================site delete start========================' );

		$this->site_name = remove_trailing_slash( $args[0] );
		if ( $this->db::site_in_db( $this->site_name ) ) {

			$this->proxy_type = $this->db::select( array( 'proxy_type' ), array( 'sitename' => $this->site_name ) )[0]['proxy_type'];
			$this->level      = 5;
			$this->delete_site();
		} else {
			EE::error( "Site $this->site_name does not exist." );
		}
		$this->logger->info( '========================site delete end========================' );
	}

	/**
	 * Function to check all the required configurations needed to create the site.
	 *
	 * Invokes function start_proxy_server() to start the given proxy if it exists but is not running.
	 * Invokes function create_proxy_server() to create and start the given proxy if it does not exist.
	 */
	private function init_checks() {

		$is_proxy_running = EE::launch( "docker inspect -f '{{.State.Running}}' $this->proxy_type", false, true );

		if ( ! $is_proxy_running->return_code ) {
			if ( preg_match( '/false/', $is_proxy_running->stdout ) ) {
				$this->start_proxy_server();
			}
		} else {
			/**
			 * Checking ports.
			 */
			@fsockopen( 'localhost', 80, $port_80_exit_status );
			@fsockopen( 'localhost', 443, $port_443_exit_status );

			// if any/both the port/s is/are occupied.
			if ( ! ( $port_80_exit_status && $port_443_exit_status ) ) {
				EE::error( 'Cannot create proxy container. Please make sure port 80 and 443 are free.' );
			}
			$this->create_proxy_server();
		}
	}


	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site() {

		$this->site_root     = WEBROOT . $this->site_name;
		$site_conf_dir       = $this->site_root . '/config';
		$site_docker_yml     = $this->site_root . '/docker-compose.yml';
		$this->site_conf_env = $this->site_root . '/.env';

		$ee_conf            = EE_SITE_CONF_ROOT . $this->site_type . '/config';
		$ee_conf_docker_yml = EE_SITE_CONF_ROOT . $this->site_type . ( 'traefik' === $this->proxy_type ? '/docker-compose-traefik.yml' : '/docker-compose.yml' );

		if ( ! $this->create_site_root() ) {
			EE::error( "Webroot directory for site $this->site_name already exists." );
		}
		EE::log( "Creating WordPress site $this->site_name..." );
		EE::log( 'Copying configuration files...' );

		try {
			if ( ! ( copy_recursive( $ee_conf, $site_conf_dir )
				&& copy( $ee_conf_docker_yml, $site_docker_yml )
				&& rename( "$site_conf_dir/.env.example", $this->site_conf_env ) ) ) {
				throw new Exception( 'Could not copy configuration files.' );
			}

			$this->env = file_get_contents( $this->site_conf_env );

			EE::success( 'Configuration files copied.' );

			// Updating config file.
			EE::log( 'Updating configuration files...' );
			$this->env = str_replace( '{V_HOST}', $this->site_name, $this->env );
			EE::success( 'Configuration files updated.' );
			if ( ! file_put_contents( $this->site_conf_env, $this->env ) ) {
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
			if ( ! @mkdir( $this->site_root, 0777, true ) ) {
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
		$this->docker_compose_up();
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
		$this->site_root   = WEBROOT . $this->site_name;
		$chdir_return_code = chdir( $this->site_root );
		if ( $chdir_return_code && ( $this->level > 1 ) ) {
			if ( $this->level >= 3 ) {
				$docker_remove = EE::launch( 'docker-compose down', false, true );
				EE::debug( print_r( $docker_remove, true ) );
				if ( ! $docker_remove->return_code ) {
					EE::log( "[$this->site_name] Docker Containers removed." );
				} else {
					if ( $this->level > 3 ) {
						EE::warning( 'Error in removing docker containers.' );
					}
				}

				$network_disconnect = EE::launch( "docker network disconnect $this->site_name $this->proxy_type", false, true );
				EE::debug( print_r( $network_disconnect, true ) );
				if ( ! $network_disconnect->return_code ) {
					EE::log( "[$this->site_name] Disconnected from Docker network $this->proxy_type" );
				} else {
					EE::warning( "Error in disconnecting from Docker network $this->proxy_type" );
				}

			}

			if ( $this->level >= 2 ) {
				$network_remove = EE::launch( "docker network rm $this->site_name", false, true );
				EE::debug( print_r( $network_remove, true ) );
				if ( ! $network_remove->return_code ) {
					EE::log( "[$this->site_name] Docker network $this->proxy_type removed." );
				} else {
					if ( $this->level > 2 ) {
						EE::warning( "Error in removing Docker network $this->proxy_type" );
					}
				}
			}
		}

		if ( is_dir( $this->site_root ) ) {
			if ( ! delete_dir( $this->site_root ) ) {
				$rmdir = EE::launch( "sudo rm -rf $this->site_root" );
				if ( $rmdir ) {
					EE::log( $rmdir );
					EE::error( 'Could not remove site root. Please check if you have sufficient rights.' );
				}
			}
			EE::log( "[$this->site_name] site root removed." );
		}
		if ( $this->level > 4 ) {
			if ( $this->db::delete( array( 'sitename' => $this->site_name ) ) ) {
				EE::log( 'Removing database entry' );
			} else {
				EE::error( 'Could not remove the database entry' );
			}
		}
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
				if ( $i ++ > 50 ) {
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

		$this->level    = 2;
		$create_network = EE::launch( "docker network create $this->site_name", false, true );

		EE::debug( print_r( $create_network, true ) );

		try {
			if ( ! $create_network->return_code ) {
				EE::success( 'Network started.' );
			} else {
				throw new Exception( 'There was some error in starting the network.' );
			}
			$this->level     = 3;
			$connect_network = EE::launch( "docker network connect $this->site_name $this->proxy_type", false, true );
			EE::debug( print_r( $connect_network, true ) );
			if ( ! $connect_network->return_code ) {
				EE::success( "Site connected to $this->proxy_type." );
			} else {
				throw new Exception( "There was some error connecting to $this->proxy_type." );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Function to start the containers.
	 */
	private function docker_compose_up() {

		$chdir_return_code = chdir( $this->site_root );
		$this->level       = 3;
		try {
			if ( $chdir_return_code ) {
				$docker_compose_up = EE::launch( "docker-compose up -d", false, true );
				EE::debug( print_r( $docker_compose_up, true ) );

				if ( $docker_compose_up->return_code ) {
					throw new Exception( 'There was some error in docker-compose up.' );
				}
			} else {
				throw new Exception( 'Error in changing directory.' );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Function to start the container if it exists but is not running.
	 */
	private function start_proxy_server() {

		$start_docker_return_code = EE::launch( "docker start $this->proxy_type", false, true );
		EE::debug( print_r( $start_docker_return_code, true ) );
		if ( ! $start_docker_return_code->return_code ) {
			EE::success( 'Container started.' );
		} else {
			EE::error( 'There was some error in starting the container.' );
		}
	}


	/**
	 * Function to create and start the container if it does not exist.
	 */
	private function create_proxy_server() {


		$HOME = HOME;
		if ( 'traefik' === $this->proxy_type ) {
			$proxy_return_code = EE::launch(
				"docker run -d -p 8080:8080 -p 80:80 -p 443:443 -v /var/run/docker.sock:/var/run/docker.sock -v /dev/null:/etc/traefik/traefik.toml --name traefik traefik --api --docker --docker.domain=docker.localhost --logLevel=DEBUG"
				, false, true
			);
		} else {
			$proxy_return_code = EE::launch(
				"docker run --name nginx-proxy --restart always -d -p 80:80 -p 443:443 -v $HOME/.ee4/etc/nginx/certs:/etc/nginx/certs -v $HOME/.ee4/etc/nginx/conf.d:/etc/nginx/conf.d -v $HOME/.ee4/etc/nginx/htpasswd:/etc/nginx/htpasswd -v $HOME/.ee4/etc/nginx/vhost.d:/etc/nginx/vhost.d -v $HOME/.ee4/usr/share/nginx/html:/usr/share/nginx/html -v /var/run/docker.sock:/tmp/docker.sock:ro jwilder/nginx-proxy"
				, false, true
			);
		}

		EE::debug( print_r( $proxy_return_code, true ) );

		/*
		$letsencrypt_return_code = EE::launch( "docker run -d --name letsencrypt -v /var/run/docker.sock:/var/run/docker.sock:ro --volumes-from nginx-proxy jrcs/letsencrypt-nginx-proxy-companion", false, true );

		EE::debug( (string) $letsencrypt_return_code );
		*/

		if ( ! ( $proxy_return_code->return_code /*|| $letsencrypt_return_code->return_code */ ) ) {
			EE::success( "$this->proxy_type container launched successfully." );
		} else {
			EE::error( "$this->proxy_type container could not be launched." );
		}
	}

	/**
	 * Function to create entry in /etc/hosts.
	 */
	private function create_etc_hosts_entry() {

		$host_line = LOCALHOST_IP . "\t$this->site_name";
		$etc_hosts = file_get_contents( '/etc/hosts' );
		if ( ! preg_match( "/\s+$this->site_name\$/m", $etc_hosts ) ) {
			$host_line       .= "\n" . LOCALHOST_IP . "\tmail.$this->site_name";
			$etc_hosts_entry = EE::launch(
				"sudo /bin/bash -c 'echo \"$host_line\" >> /etc/hosts'", false
			);
			if ( ! $etc_hosts_entry ) {
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
		exec( "docker-compose exec --user='www-data' php wp core install --url='" . $this->site_name . "' --title='" . $this->site_title . "' --admin_user='" . $this->site_user . "'" . ( ! $this->site_pass ? "" : " --admin_password='" . $this->site_pass . "'" ) . " --admin_email='" . $this->site_email . "'", $op );
		EE::success( "http://" . $this->site_name . " has been created successfully!" );
		EE::log( "Site Title :\t" . $this->site_title . "\nUsername :\t" . $this->site_user . "\nPassword :\t" . $this->site_pass );
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
	 * Function to return the type of site.
	 *
	 * @param array $assoc_args User input arguments.
	 *
	 * @return string Type of site parsed from argument given from user.
	 */
	private function get_site_type( $assoc_args ) {

		$type_of_sites = array(
			'wp',
			'php',
			'html',
			'mysql',
		);

		foreach ( $type_of_sites as $site ) {
			if ( get_flag_value( $assoc_args, $site ) ) {
				return $site;
			}
		}
	}

	/**
	 * Catch and clean exceptions.
	 *
	 * @param Exception $e
	 */
	private function catch_clean( $e ) {
		$this->logger->info( '========================site cleanup start========================' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up...' );
		$this->delete_site();
		$this->logger->info( '========================site cleanup end========================' );
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
