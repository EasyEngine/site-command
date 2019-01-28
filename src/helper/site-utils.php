<?php

namespace EE\Site\Utils;

use EE;
use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Utils\get_flag_value;
use function EE\Utils\get_config_value;
use function EE\Utils\sanitize_file_folder_name;
use function EE\Utils\remove_trailing_slash;
use function EE\Utils\trailingslashit;

/**
 * Get the site-name from the path from where ee is running if it is a valid site path.
 *
 * @return bool|String Name of the site or false in failure.
 */
function get_site_name() {

	$sites = Site::all( [ 'site_url' ] );

	if ( ! empty( $sites ) ) {
		if ( IS_DARWIN ) {
			$cwd = getcwd();
		} else {
			$launch = EE::launch( 'pwd' );
			$cwd    = trim( $launch->stdout );
		}
		$name_in_path = explode( '/', $cwd );

		$site_url = array_intersect( array_column( $sites, 'site_url' ), $name_in_path );

		if ( 1 === count( $site_url ) ) {
			$name = reset( $site_url );
			$path = Site::find( $name );
			if ( $path ) {
				$site_path = $path->site_fs_path;
				if ( substr( $cwd, 0, strlen( $site_path ) ) === $site_path ) {
					return $name;
				}
			}
		}
	}

	return false;
}

/**
 * Function to set the site-name in the args when ee is running in a site folder and the site-name has not been passed
 * in the args. If the site-name could not be found it will throw an error.
 *
 * @param array $args      The passed arguments.
 * @param String $command  The command passing the arguments to auto-detect site-name.
 * @param String $function The function passing the arguments to auto-detect site-name.
 * @param integer $arg_pos Argument position where Site-name will be present.
 *
 * @return array Arguments with site-name set.
 */
function auto_site_name( $args, $command, $function, $arg_pos = 0 ) {

	if ( isset( $args[ $arg_pos ] ) ) {
		$possible_site_name = $args[ $arg_pos ];
		if ( substr( $possible_site_name, 0, 4 ) === 'http' ) {
			$possible_site_name = str_replace( [ 'https', 'http' ], '', $possible_site_name );
		}
		$url_path = parse_url( EE\Utils\remove_trailing_slash( $possible_site_name ), PHP_URL_PATH );
		if ( Site::find( $url_path ) ) {
			return $args;
		}
	}
	$site_url = get_site_name();
	if ( $site_url ) {
		if ( isset( $args[ $arg_pos ] ) ) {
			EE::error( $args[ $arg_pos ] . " is not a valid site-name. Did you mean `ee $command $function $site_url`?" );
		}
		array_splice( $args, $arg_pos, 0, $site_url );
	} else {
		EE::error( "Could not find the site you wish to run $command $function command on.\nEither pass it as an argument: `ee $command $function <site-name>` \nor run `ee $command $function` from inside the site folder." );
	}

	return $args;
}

/**
 * Populate basic site info from db.
 *
 * @param bool $site_enabled_check Check if site is enabled. Throw error message if not enabled.
 * @param bool $exit_if_not_found  Check if site exists. Throw error message if not, else return false.
 * @param bool $return_array       Return array of data or object.
 *
 * @return mixed $site_data Site data from db.
 */
function get_site_info( $args, $site_enabled_check = true, $exit_if_not_found = true, $return_array = true ) {

	$site_url   = \EE\Utils\remove_trailing_slash( $args[0] );
	$data       = Site::find( $site_url );
	$array_data = ( array ) $data;
	$site_data  = $return_array ? reset( $array_data ) : $data;

	if ( ! $data ) {
		if ( $exit_if_not_found ) {
			\EE::error( sprintf( 'Site %s does not exist.', $site_url ) );
		}

		return false;
	}

	if ( ! $data->site_enabled && $site_enabled_check ) {
		\EE::error( sprintf( 'Site %1$s is not enabled. Use `ee site enable %1$s` to enable it.', $data->site_url ) );
	}

	return $site_data;
}

/**
 * Create user in remote or global db.
 *
 * @param string $db_host Database Hostname.
 * @param string $db_name Database name to be created.
 * @param string $db_user Database user to be created.
 * @param string $db_pass Database password to be created.
 *
 * @return array|bool Finally created database name, user and password.
 */
function create_user_in_db( $db_host, $db_name = '', $db_user = '', $db_pass = '' ) {

	$db_name = empty( $db_name ) ? \EE\Utils\random_password( 5 ) : $db_name;
	$db_user = empty( $db_user ) ? \EE\Utils\random_password( 5 ) : $db_user;
	$db_pass = empty( $db_pass ) ? \EE\Utils\random_password() : $db_pass;

	$create_string = sprintf( "CREATE USER '%1\$s'@'%%' IDENTIFIED BY '%2\$s'; CREATE DATABASE %3\$s; GRANT ALL PRIVILEGES ON %3\$s.* TO '%1\$s'@'%%'; FLUSH PRIVILEGES;", $db_user, $db_pass, $db_name );

	if ( GLOBAL_DB === $db_host ) {

		$health_script  = 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e"exit"';
		$db_script_path = \EE\Utils\get_temp_dir() . 'db_exec';
		file_put_contents( $db_script_path, $health_script );
		$mysql_unhealthy = true;
		EE::exec( sprintf( 'docker cp %s %s:/db_exec', $db_script_path, GLOBAL_DB_CONTAINER ) );
		$count = 0;
		while ( $mysql_unhealthy ) {
			$mysql_unhealthy = ! EE::exec( sprintf( 'docker exec %s sh db_exec', GLOBAL_DB_CONTAINER ) );
			if ( $count ++ > 60 ) {
				break;
			}
			sleep( 1 );
		}

		$db_script_path = \EE\Utils\get_temp_dir() . 'db_exec';
		file_put_contents( $db_script_path, sprintf( 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e"%s"', $create_string ) );

		EE::exec( sprintf( 'docker cp %s %s:/db_exec', $db_script_path, GLOBAL_DB_CONTAINER ) );
		if ( ! EE::exec( sprintf( 'docker exec %s sh db_exec', GLOBAL_DB_CONTAINER ) ) ) {
			return false;
		}
	} else {
		//TODO: Handle remote case.
	}

	return [
		'db_name' => $db_name,
		'db_user' => $db_user,
		'db_pass' => $db_pass,
	];
}

/**
 * Function to cleanup database.
 *
 * @param string $db_host Database host from which database is to be removed.
 * @param string $db_name Database name to be removed.
 * @param string $db_user Database user to remove the host.
 * @param string $db_pass Database password of the user.
 */
function cleanup_db( $db_host, $db_name, $db_user = '', $db_pass = '' ) {

	$cleanup_string = sprintf( 'DROP DATABASE %s;', $db_name );

	if ( GLOBAL_DB === $db_host ) {
		$db_script_path = \EE\Utils\get_temp_dir() . 'db_exec';
		file_put_contents( $db_script_path, sprintf( 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e"%s"', $cleanup_string ) );

		EE::exec( sprintf( 'docker cp %s %s:/db_exec', $db_script_path, GLOBAL_DB_CONTAINER ) );
		EE::exec( sprintf( 'docker exec %s sh db_exec', GLOBAL_DB_CONTAINER ) );
	}

}

/**
 * Function to cleanup database user.
 *
 * @param string $db_host               Database host from which user is to be removed.
 * @param string $db_user_to_be_cleaned Database user to be removed.
 * @param string $db_privileged_pass    User having sufficient privilege to delete the given user.
 * @param string $db_privileged_user    Password of that privileged user.
 */
function cleanup_db_user( $db_host, $db_user_to_be_cleaned, $db_privileged_pass = '', $db_privileged_user = 'root' ) {

	$cleanup_string = sprintf( 'DROP USER \'%s\'@\'%%\';', $db_user_to_be_cleaned );

	if ( GLOBAL_DB === $db_host ) {
		$db_script_path = \EE\Utils\get_temp_dir() . 'db_exec';
		file_put_contents( $db_script_path, sprintf( 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e"%s"', $cleanup_string ) );

		EE::exec( sprintf( 'docker cp %s %s:/db_exec', $db_script_path, GLOBAL_DB_CONTAINER ) );
		EE::exec( sprintf( 'docker exec %s sh db_exec', GLOBAL_DB_CONTAINER ) );
	}
}

/**
 * Creates site root directory if does not exist.
 * Throws error if it does exist.
 *
 * @param string $site_fs_path Root directory of the site.
 * @param string $site_url     Name of the site.
 */
function create_site_root( $site_fs_path, $site_url ) {

	$fs = new Filesystem();
	if ( $fs->exists( $site_fs_path ) ) {
		EE::error( "Webroot directory for site $site_url already exists." );
	}

	$whoami            = EE::launch( 'whoami', false, true );
	$terminal_username = rtrim( $whoami->stdout );

	$fs->mkdir( $site_fs_path );
	$fs->chown( $site_fs_path, $terminal_username );
}

/**
 * Adds www to non-www redirection to site
 *
 * @param string $site_url name of the site.
 * @param bool $ssl        enable ssl or not.
 * @param bool $inherit    inherit cert or not.
 */
function add_site_redirects( string $site_url, bool $ssl, bool $inherit ) {

	$fs               = new Filesystem();
	$confd_path       = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/';
	$config_file_path = $confd_path . $site_url . '-redirect.conf';
	$has_www          = strpos( $site_url, 'www.' ) === 0;
	$cert_site_name   = $site_url;
	$ssl_policy       = get_ssl_policy();

	$conf_ssl_policy = 'ssl_policy_' . str_replace( '-', '_', $ssl_policy );

	if ( $inherit ) {
		$cert_site_name = implode( '.', array_slice( explode( '.', $site_url ), 1 ) );
	}

	if ( $has_www ) {
		$server_name = ltrim( $site_url, '.www' );
	} else {
		$server_name = 'www.' . $site_url;
	}

	$conf_data = [
		'site_name'      => $site_url,
		'cert_site_name' => $cert_site_name,
		'server_name'    => $server_name,
		'ssl'            => $ssl,
		$conf_ssl_policy => true,
	];

	$content = EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/redirect.conf.mustache', $conf_data );
	$fs->dumpFile( $config_file_path, ltrim( $content, PHP_EOL ) );
}

/**
 * Function to check config and return a valid ssl-policy.
 *
 * @return string Valid ssl-policy.
 */
function get_ssl_policy() {

	$ssl_policy = get_config_value( 'ssl-policy', 'Mozilla-Modern' );

	$valid_configurations = [
		'Mozilla-Old',
		'Mozilla-Intermediate',
		'Mozilla-Modern',
		'AWS-TLS-1-2-2017-01',
		'AWS-TLS-1-1-2017-01',
		'AWS-2016-08',
		'AWS-2015-05',
		'AWS-2015-03',
		'AWS-2015-02',
	];

	return in_array( $ssl_policy, $valid_configurations, true ) ? $ssl_policy : 'Mozilla-Modern';
}

/**
 * Function to create entry in /etc/hosts.
 *
 * @param string $site_url Name of the site.
 */
function create_etc_hosts_entry( $site_url ) {

	if ( IS_DARWIN ) {

		// setup_dnsmasq_for_darwin only if domain ends with `.test`
		$ends_with_string = '.test';
		$diff             = strlen( $site_url ) - strlen( $ends_with_string );
		if ( $diff >= 0 && false !== strpos( $site_url, $ends_with_string, $diff ) ) {
			setup_dnsmasq_for_darwin();
		}

		return;
	}
	$host_line = LOCALHOST_IP . "\t$site_url";
	$etc_hosts = file_get_contents( '/etc/hosts' );
	if ( ! preg_match( "/\s+$site_url\$/m", $etc_hosts ) ) {
		if ( EE::exec( "/bin/bash -c 'echo \"$host_line\" >> /etc/hosts'" ) ) {
			EE::success( 'Host entry successfully added.' );
		} else {
			EE::warning( "Failed to add $site_url in host entry, Please do it manually!" );
		}
	} else {
		EE::log( 'Host entry already exists.' );
	}
}

/**
 * Setup dnsmasq for darwin to resolve `*.test` domain.
 *
 * @return bool success.
 */
function setup_dnsmasq_for_darwin() {

	if ( ! IS_DARWIN ) {
		return false;
	}

	// check if brew is installed.
	if ( EE::exec( 'command -v brew' ) ) {
		$fs = new Filesystem();
		if ( $fs->exists( '/etc/resolver/test' ) ) {
			return true;
		}
	} else {
		return false;
	}

	// check if dnsmasq is installed.
	if ( ! EE::exec( 'brew ls --versions dnsmasq' ) ) {
		return false;
	}

	// create config directory.
	EE::exec( 'mkdir -p $(brew --prefix)/etc/' );

	// Setup `*.test` domain.
	EE::exec( "echo 'address=/.test/127.0.0.1' > $(brew --prefix)/etc/dnsmasq.conf" );

	EE::log( 'Setting up dnsmasq for *.test domain. You might need to enter password.' );

	// Add to LaunchDaemons so that it works after reboot.
	EE::exec( 'sudo cp -v $(brew --prefix dnsmasq)/homebrew.mxcl.dnsmasq.plist /Library/LaunchDaemons' );

	// Create resolver directory.
	EE::exec( 'sudo mkdir -v /etc/resolver' );

	// Adding 127.0.0.1 nameserver to resolvers.
	EE::exec( "sudo bash -c 'echo \"nameserver 127.0.0.1\" > /etc/resolver/test'" );

	// start it.
	if ( EE::exec( 'sudo launchctl load -w /Library/LaunchDaemons/homebrew.mxcl.dnsmasq.plist' ) ) {
		return true;
	}

	return false;
}

/**
 * Checking site is running or not.
 *
 * @param string $site_url Name of the site.
 *
 * @throws \Exception when fails to connect to site.
 */
function site_status_check( $site_url ) {

	EE::log( 'Checking and verifying site-up status. This may take some time.' );
	$config_80_port = \EE\Utils\get_config_value( 'proxy_80_port', 80 );
	$httpcode       = \EE\Utils\get_curl_info( $site_url, $config_80_port );
	$i              = 0;
	$auth           = false;
	while ( 200 !== $httpcode && 302 !== $httpcode && 301 !== $httpcode ) {
		EE::debug( "$site_url status httpcode: $httpcode" );
		if ( 401 === $httpcode ) {
			$user_pass = get_global_auth();
			$auth      = $user_pass['username'] . ':' . $user_pass['password'];
		}
		$httpcode = \EE\Utils\get_curl_info( $site_url, $config_80_port, false, $auth, true );
		echo '.';
		sleep( 2 );
		if ( $i ++ > 60 ) {
			break;
		}
	}
	EE::debug( "$site_url status httpcode: $httpcode" );
	echo PHP_EOL;
	if ( 200 !== $httpcode && 302 !== $httpcode && 301 !== $httpcode ) {
		throw new \Exception( 'Problem connecting to site!' );
	}

}

/**
 * Function to pull the latest images and bring up the site containers and set EasyEngine header.
 *
 * @param string $site_fs_path Root directory of the site.
 * @param array $containers    The minimum required conatainers to start the site. Default null, leads to starting of all containers.
 *
 * @throws \Exception when docker-compose up fails.
 */
function start_site_containers( $site_fs_path, $containers = [] ) {

	chdir( $site_fs_path );
	EE::log( 'Starting site\'s services.' );
	if ( ! \EE_DOCKER::docker_compose_up( $site_fs_path, $containers ) ) {
		throw new \Exception( 'There was some error in docker-compose up.' );
	}
}

/**
 * Function to restart given containers for a site and update EasyEngine header.
 *
 * @param string $site_fs_path     Root directory of the site.
 * @param string|array $containers Containers to restart.
 */
function restart_site_containers( $site_fs_path, $containers ) {

	chdir( $site_fs_path );
	$all_containers = is_array( $containers ) ? implode( ' ', $containers ) : $containers;
	EE::exec( "docker-compose restart $all_containers" );
}

/**
 * Function to stop given containers for a site.
 *
 * @param string $site_fs_path     Root directory of the site.
 * @param string|array $containers Containers to stop.
 */
function stop_site_containers( $site_fs_path, $containers ) {

	chdir( $site_fs_path );
	$all_containers = is_array( $containers ) ? implode( ' ', $containers ) : $containers;
	EE::exec( "docker-compose stop $all_containers" );
	EE::exec( "docker-compose rm -f $all_containers" );
}

/**
 * Generic function to run a docker compose command. Must be ran inside correct directory.
 *
 * @param string $action             docker-compose action to run.
 * @param string $container          The container on which action has to be run.
 * @param string $action_to_display  The action message to be displayed.
 * @param string $service_to_display The service message to be displayed.
 */
function run_compose_command( $action, $container, $action_to_display = null, $service_to_display = null ) {

	$display_action  = $action_to_display ? $action_to_display : $action;
	$display_service = $service_to_display ? $service_to_display : $container;

	EE::log( ucfirst( $display_action ) . 'ing ' . $display_service );
	EE::exec( "docker-compose $action $container", true, true );
}

/**
 * Function to copy and configure files needed for postfix.
 *
 * @param string $site_url         Name of the site to configure postfix files for.
 * @param string $site_service_dir Configuration directory of the site `site_root/services`.
 */
function set_postfix_files( $site_url, $site_service_dir ) {

	$fs = new Filesystem();
	$fs->mkdir( $site_service_dir . '/postfix/ssl' );
	$ssl_dir = $site_service_dir . '/postfix/ssl';

	if ( ! EE::exec( sprintf( "openssl req -new -x509 -nodes -days 365 -subj \"/CN=smtp.%s\" -out $ssl_dir/server.crt -keyout $ssl_dir/server.key", $site_url ) )
	     && EE::exec( "chmod 0600 $ssl_dir/server.key" ) ) {
		throw new \Exception( 'Unable to generate ssl key for postfix' );
	}
}

/**
 * Function to execute docker-compose exec calls to postfix to get it configured and running for the site.
 *
 * @param string $site_url     Name of the for which postfix has to be configured.
 * @param string $site_fs_path Site root.
 */
function configure_postfix( $site_url, $site_fs_path ) {

	chdir( $site_fs_path );
	EE::exec( 'docker-compose exec postfix postconf -e \'relayhost =\'' );
	EE::exec( 'docker-compose exec postfix postconf -e \'smtpd_recipient_restrictions = permit_mynetworks\'' );
	$launch      = EE::launch( sprintf( 'docker inspect -f \'{{ with (index .IPAM.Config 0) }}{{ .Subnet }}{{ end }}\' %s', $site_url ) );
	$subnet_cidr = trim( $launch->stdout );
	EE::exec( sprintf( 'docker-compose exec postfix postconf -e \'mynetworks = %s 127.0.0.0/8\'', $subnet_cidr ) );
	EE::exec( sprintf( 'docker-compose exec postfix postconf -e \'myhostname = %s\'', $site_url ) );
	EE::exec( 'docker-compose exec postfix postconf -e \'syslog_name = $myhostname\'' );
	EE::exec( 'docker-compose restart postfix' );
}

/**
 * Reload the global nginx proxy.
 */
function reload_global_nginx_proxy() {

	if ( \EE::launch( sprintf( 'docker exec %s sh -c "nginx -t"', EE_PROXY_TYPE ) ) ) {
		return \EE::launch( sprintf( 'docker exec %s sh -c "/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf; /usr/sbin/nginx -s reload"', EE_PROXY_TYPE ) );
	}

	return false;
}

/**
 * Get global auth if it exists.
 */
function get_global_auth() {
	if ( ! class_exists( '\EE\Model\Auth' ) ) {
		return false;
	}

	$auth = \EE\Model\Auth::where( [
		'site_url' => 'default',
	] );

	if ( empty( $auth ) ) {
		return false;
	}

	return [
		'username' => $auth[0]->username,
		'password' => $auth[0]->password,
	];

}

/**
 * Clear site cache with specific key.
 *
 * @param string $key Cache key to clear.
 */
function clean_site_cache( $key ) {
	EE::exec( sprintf( 'docker exec -it %s redis-cli --eval purge_all_cache.lua 0 , "%s*"', GLOBAL_REDIS_CONTAINER, $key ) );
}

/**
 * Function to get the public-dir from assoc args with checks and sanitizations.
 *
 * @param $assoc_args
 *
 * @return string processed value for public-dir.
 */
function get_public_dir( $assoc_args ) {

	// Create container fs path for site.
	$public_root           = get_flag_value( $assoc_args, 'public-dir' );
	$public_root           = str_replace( '/var/www/htdocs/', '', trailingslashit( $public_root ) );
	$public_root           = remove_trailing_slash( $public_root );
	$sanitized_public_dir  = sanitize_file_folder_name( $public_root );
	$user_input_public_dir = sprintf( '/var/www/htdocs/%s', trim( $sanitized_public_dir, '/' ) );

	return empty( $public_root ) ? '/var/www/htdocs' : $user_input_public_dir;
}

/**
 * Get final source directory for site webroot.
 *
 * @param $original_src_dir  Default source directory.
 * @param $container_fs_path public directory set by user if any.
 *
 * @return string final webroot for site.
 */
function get_webroot( $original_src_dir, $container_fs_path ) {

	$public_dir_path = str_replace( '/var/www/htdocs/', '', trailingslashit( $container_fs_path ) );

	return empty( $public_dir_path ) ? $original_src_dir : $original_src_dir . '/' . rtrim( $public_dir_path, '/' );
}
