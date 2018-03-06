<?php

use function \EE\Utils\get_flag_value;
use function \EE\Utils\trailingslashit;
use function \EE\Utils\get_home_dir;

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

define( 'HOME', trailingslashit( get_home_dir() ) );
define( 'EE_CONF_ROOT', trailingslashit( HOME . '.ee4' ) );
define( 'EE_SITE_CONF_ROOT', trailingslashit( EE_ROOT ) . 'ee4-config/' );
define( 'LOCALHOST_IP', '127.0.0.1' );
define( 'TABLE', 'sites' );

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

		$this->site_name  = strtolower( $this->remove_trailing_slash( $args[0] ) );
		$this->site_type  = ! empty( $this->get_site_type( $assoc_args ) ) ? $this->get_site_type( $assoc_args ) : 'wp';
		$this->proxy_type = ! empty( $assoc_args['traefik-proxy'] ) ? 'traefik-proxy' : 'nginx-proxy';
		$this->site_title = ! empty( $assoc_args['title'] ) ? $assoc_args['title'] : $this->site_name;
		$this->site_user  = ! empty( $assoc_args['user'] ) ? $assoc_args['user'] : 'admin';
		$this->site_pass  = ! empty( $assoc_args['pass'] ) ? $assoc_args['pass'] : $this->random_password();
		$this->site_email = ! empty( $assoc_args['email'] ) ? $assoc_args['email'] : strtolower( 'mail@' . $this->site_name );

		$this->init_ee4();
		$this->init_checks();
		$this->init_db();

		EE::log( "Installing WordPress site $this->site_name" );
		EE::log( 'Configuring project...' );

		$this->configure_site();
		$this->create_site();
	}

	/**
	 * Site list sub-command.
	 */
	public function list() {
		EE::debug( __FUNCTION__ );

		$this->init_ee4();

		if ( empty( $this->db ) ) {
			$this->init_db();
		}

		$this->list_sites();
	}

	/**
	 * Function to check and create the root directory for ee4.
	 */
	private function init_ee4() {

		$runner = EE::get_runner();

		if ( ! is_dir( EE_CONF_ROOT ) ) {
			mkdir( EE_CONF_ROOT );
		}

		if ( ! is_dir( $runner->config['sites_path'] ) ) {
			mkdir( $runner->config['sites_path'] );
		}
		define( 'WEBROOT', trailingslashit( $runner->config['sites_path'] ) );
		define( 'DB', EE_CONF_ROOT . 'ee4.db' );
	}

	/**
	 * Function to check all the required configurations needed to create the site.
	 *
	 * Invokes function start_proxy_server() to start the given proxy if it exists but is not running.
	 * Invokes function create_proxy_server() to create and start the given proxy if it does not exist.
	 */
	private function init_checks() {
		EE::debug( __FUNCTION__ );
		$is_proxy_running = EE::launch( "docker inspect -f '{{.State.Running}}' $this->proxy_type", false, true );

		EE::debug( (string) $is_proxy_running );

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

		EE::debug( __FUNCTION__ );

		$this->site_root     = WEBROOT . $this->site_name;
		$site_conf_dir       = $this->site_root . '/config';
		$site_docker_yml     = $this->site_root . '/docker-compose.yml';
		$this->site_conf_env = $this->site_root . '/.env';

		$ee_conf            = EE_SITE_CONF_ROOT . $this->site_type . '/config';
		$ee_conf_docker_yml = EE_SITE_CONF_ROOT . $this->site_type . ( 'traefik' === $this->proxy_type ? '/docker-compose-traefik.yml' : '/docker-compose.yml' );

		if ( ! $this->create_site_root() ) {
			EE::error( "Site $this->site_name already exists" );
		}

		EE::log( "Creating WordPress site $this->site_name..." );
		EE::log( 'Copying configuration files...' );
		$this->copy_recursive( $ee_conf, $site_conf_dir );
		copy( $ee_conf_docker_yml, $site_docker_yml );
		rename( "$site_conf_dir/.env.example", $this->site_conf_env );
		$this->env = file_get_contents( $this->site_conf_env );
		EE::success( 'Configuration files copied.' );

		// Updating config file.
		EE::log( 'Updating configuration files...' );
		$this->env = str_replace( '{V_HOST}', $this->site_name, $this->env );
		EE::success( 'Configuration files updated.' );
		file_put_contents( $this->site_conf_env, $this->env );
	}


	/**
	 * Function to create site root directory.
	 */
	private function create_site_root() {
		EE::debug( __FUNCTION__ );

		if ( is_dir( $this->site_root ) ) {
			return false;
		}

		mkdir( $this->site_root );

		$whoami            = EE::launch( "whoami", false, true );
		$terminal_username = rtrim( $whoami->stdout );
		$chown             = chown( $this->site_root, $terminal_username );

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
	 * Function to list all the sites.
	 */
	private function list_sites() {
		$sites = $this->select_db( array( 'sitename' ) );
		if ( $sites ) {
			EE::log( "List of Sites:\n" );
			foreach ( $sites as $site ) {
				EE::log( "  - " . $site['sitename'] );
			}
		} else {
			EE::warning( 'No sites found. Go create some!' );
		}
	}

	/*
	 * Checking site is running or not [TESTING]
	 */
	private function site_status_check() {
		$httpcode = "000";
		$ch       = curl_init( $this->site_name );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_NOBODY, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );

		$i = 0;
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
			EE::error( 'Problem connecting to site!' );
		}

	}

	/**
	 * Function to setup site networking and connect given proxy.
	 */
	private function setup_site_network() {
		EE::debug( __FUNCTION__ );
		$create_network = EE::launch( "docker network create $this->site_name", false, true );

		EE::debug( print_r( $create_network, true ) );

		if ( ! $create_network->return_code ) {
			EE::success( 'Network started.' );
		} else {
			EE::error( 'There was some error in starting the network.' );
		}

		$connect_network = EE::launch( "docker network connect $this->site_name $this->proxy_type", false, true );
		EE::debug( print_r( $connect_network, true ) );
		if ( ! $connect_network->return_code ) {
			EE::success( "Site connected to $this->proxy_type." );
		} else {
			EE::error( "There was some error connecting to $this->proxy_type." );
		}
	}

	/**
	 * Function to start the containers.
	 */
	private function docker_compose_up() {
		EE::debug( __FUNCTION__ );
		$chdir_return_code = chdir( $this->site_root );

		if ( $chdir_return_code ) {
			$docker_compose_up = EE::launch( "docker-compose up -d", false, true );
			EE::debug( print_r( $docker_compose_up, true ) );

			if ( $docker_compose_up->return_code ) {
				EE::error( 'There was some error in docker-compose up.' );
			}
		} else {
			EE::error( 'Error in changing directory.' );
		}
	}

	/**
	 * Function to start the container if it exists but is not running.
	 */
	private function start_proxy_server() {
		EE::debug( __FUNCTION__ );
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
		EE::debug( __FUNCTION__ );

		$HOME = HOME;
		if ( "traefik" === $this->proxy_type ) {
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
			EE::success( 'Container launched successfully.' );
		} else {
			EE::error( 'Container could not be launched.' );
		}

	}

	/**
	 * Function to create entry in /etc/hosts.
	 */
	private function create_etc_hosts_entry() {
		EE::debug( __FUNCTION__ );
		$host_line = LOCALHOST_IP . "\t$this->site_name";
		$etc_hosts = file_get_contents( '/etc/hosts' );
		if ( ! preg_match( "/\s+$this->site_name\$/m", $etc_hosts ) ) {
			$host_line       .= "\n" . LOCALHOST_IP . "\tmail.$this->site_name";
			$etc_hosts_entry = EE::launch(
				"sudo bash -c 'echo \"$host_line\" >> /etc/hosts'", false
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
		EE::success( "http://" . $this->site_name . " is created!" );
		EE::log( "Site Title :\t" . $this->site_title . "\nUsername :\t" . $this->site_user . "\nPassword :\t" . $this->site_pass );
		EE::log( "E-Mail :\t" . $this->site_email );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {
		EE::debug( __FUNCTION__ );

		$data = array(
			'sitename'   => $this->site_name,
			'site_type'  => $this->site_type,
			'proxy_type' => $this->proxy_type,
		);

		if ( $this->insert_db( $data ) ) {
			EE::log( 'Site entry created.' );
		} else {
			EE::error( 'Error creating site entry in database.' );
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
		EE::debug( __FUNCTION__ );
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


	##############################################UTILS##############################################

	/**
	 * Remove trailing slash from a string.
	 *
	 * @param string $str Input string.
	 *
	 * @return string String without trailing slash.
	 */
	private function remove_trailing_slash( $str ) {
		EE::debug( __FUNCTION__ );

		return rtrim( $str, '/' );
	}

	/**
	 * Function to recursively copy directory.
	 *
	 * @param string $source Source directory.
	 * @param string $dest   Destination directory.
	 */
	private function copy_recursive( $source, $dest ) {
		EE::debug( __FUNCTION__ );
		if ( ! is_dir( $dest ) ) {
			mkdir( $dest, 0755 );
		}

		foreach (
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			) as $item
		) {
			if ( $item->isDir() ) {
				mkdir( $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName() );
			} else {
				copy( $item, $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName() );
			}
		}
	}

	/**
	 * Function to generate random password.
	 */
	private function random_password() {
		$alphabet    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
		$pass        = array();
		$alphaLength = strlen( $alphabet ) - 1;
		for ( $i = 0; $i < 12; $i ++ ) {
			$n      = rand( 0, $alphaLength );
			$pass[] = $alphabet[$n];
		}

		return implode( $pass );
	}

	/**
	 * Function to initialize db and db connection.
	 */

	private function init_db() {
		EE::debug( __FUNCTION__ );
		if ( ! ( file_exists( DB ) ) ) {
			$this->db = $this->create_db();
		} else {
			$this->db = new SQLite3( DB );
			if ( ! $this->db ) {
				EE::error( $this->db->lastErrorMsg() );
			}
		}
		EE::debug( print_r( $this->db, true ) );
	}

	/**
	 * Sqlite database creation.
	 */
	private function create_db() {
		EE::debug( __FUNCTION__ );
		$this->db = new SQLite3( DB );
		$query    = "CREATE TABLE sites (
						id INTEGER NOT NULL, 
						sitename VARCHAR, 
						site_type VARCHAR, 
						proxy_type VARCHAR, 
						cache_type VARCHAR, 
						site_path VARCHAR, 
						created_on DATETIME, 
						is_enabled BOOLEAN DEFAULT 1, 
						is_ssl BOOLEAN DEFAULT 0, 
						storage_fs VARCHAR, 
						storage_db VARCHAR, 
						db_name VARCHAR, 
						db_user VARCHAR, 
						db_password VARCHAR, 
						db_host VARCHAR, 
						is_hhvm BOOLEAN DEFAULT 0, 
						is_pagespeed BOOLEAN DEFAULT 0, 
						php_version VARCHAR, 
						PRIMARY KEY (id), 
						UNIQUE (sitename), 
						CHECK (is_enabled IN (0, 1)), 
						CHECK (is_ssl IN (0, 1)), 
						CHECK (is_hhvm IN (0, 1)), 
						CHECK (is_pagespeed IN (0, 1))
					);";
		$this->db->exec( $query );
		EE::debug( print_r( $this->db, true ) );
	}

	/**
	 * Insert row in table.
	 *
	 * @param array $data
	 *
	 * @return bool
	 */
	private function insert_db( $data ) {
		EE::debug( __FUNCTION__ );
		EE::debug( print_r( $this->db, true ) );

		if ( empty ( $this->db ) ) {
			$this->init_db();
		}

		$table_name = TABLE;

		$fields  = '`' . implode( '`, `', array_keys( $data ) ) . '`';
		$formats = '"' . implode( '", "', $data ) . '"';

		$insert_query = "INSERT INTO `$table_name` ($fields) VALUES ($formats);";

		$insert_query_exec = $this->db->exec( $insert_query );

		if ( ! $insert_query_exec ) {
			EE::debug( $this->db->lastErrorMsg() );
			$this->db->close();
		} else {
			$this->db->close();

			return true;
		}

		return false;
	}

	/**
	 * @param array $columns
	 * @param array $where
	 * Select data from the database.
	 *
	 * @return array|bool
	 */
	private function select_db( $columns = array(), $where = array() ) {

		if ( empty ( $this->db ) ) {
			$this->init_db();
		}

		$table_name = TABLE;

		$conditions = array();
		if ( empty( $columns ) ) {
			$columns = '*';
		} else {
			$columns = implode( ', ', $columns );
		}

		foreach ( $where as $key => $value ) {
			$conditions[] = "`$key`='" . $value . "'";
		}

		$conditions = implode( ' AND ', $conditions );

		$select_data_query = "SELECT {$columns} FROM `$table_name`";

		if ( ! empty( $conditions ) ) {
			$select_data_query .= " WHERE $conditions";
		}

		$select_data_exec = $this->db->query( $select_data_query );
		$select_data      = array();
		if ( $select_data_exec ) {
			while ( $row = $select_data_exec->fetchArray( SQLITE3_ASSOC ) ) {
				$select_data[] = $row;
			}
		}
		if ( empty( $select_data ) ) {
			return false;
		}

		return $select_data;
	}
}