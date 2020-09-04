<?php

namespace EE\Site\Cloner;

use Composer\Semver\Comparator;
use EE;
use function EE\Site\Cloner\Utils\get_ssh_key_path;
use function EE\Utils\trailingslashit;

class Site {
	public $name, $host, $user, $ssh_string, $site_details;

	function __construct( string $name, string $host, string $user,  string $ssh_string, array $site_details ) {
		$this->name = $name;
		$this->host = $host;
		$this->user = $user;
		$this->ssh_string = $ssh_string;
		$this->site_details = $site_details;
	}

	public static function from_location( string $location ) : Site {
		$user = '';
		$ssh = '';

		$location = trim( $location );

		// Remote
		$details = null;
		preg_match( "/^(?'ssh'(?'username'\S+)@(?'host'[^:]+))(:(?'sitename'\S+))?/", $location, $details );

		if ( $details ) {
			$host = $details['host'];
			$user = $details['username'];
			$ssh = $details['ssh'];
			$sitename = $details['sitename'] ?? null;

			$site = new EE\Site\Cloner\Site( $sitename, $host, $user, $ssh, [] );
			return $site;
		}

		// Local
		$host = 'localhost';
		$sitename = $location === '.' ? '' : $location;

		$site = new EE\Site\Cloner\Site( $sitename, $host, $user, $ssh, [] );

		return $site;
	}

	public function execute( string $command ) : EE\ProcessRun {
		return EE::launch( $this->get_ssh_command( $command ) );
	}

	private function get_ssh_command( string $command ) : string {
		$key = get_ssh_key_path();
		return $this->ssh_string ? 'ssh -i ' . $key . ' ' . $this->ssh_string . ' "' . $command . '"' : $command ;
	}

	public function get_rsync_path( string $path ) : string {
		return $this->ssh_string ? $this->ssh_string . ':' . $path : $path;
	}

	public function get_public_dir() : string {
		$public_dir = str_replace( '/var/www/htdocs/', '', trailingslashit( $this->site_details['site_container_fs_path'] ) );
		$public_dir = $public_dir ? trailingslashit( $public_dir ) : $public_dir;

		return $this->get_rsync_path( $public_dir );
	}

	public function validate_ee_version() : void {
		$result = $this->execute( 'ee cli version' );
		$matches = null;

		preg_match( "/^EE (?'version'\S+)/", $result->stdout, $matches );

		$matches['version'] = preg_replace( '/-nightly.*$/', '', $matches['version'] );

		if ( Comparator::lessThan( $matches['version'], '4.1.3' ) ) {
			throw new \Exception( 'EasyEngine version on \'' . $this->host . '\' is \'' . $matches['version'] . '\' which is less than minimum required version \'4.1.3\' for cloning site.' );
		}
	}

	private function get_site_create_command( array $site_details ) : string {
		$command = 'ee site create ' . $this->name . ' --type=' . $site_details['site_type'] ;

		if ( in_array( $site_details['site_type'], [ 'html', 'php', 'wp'] ) ) {
			if ( '/var/www/htdocs' !== $site_details['site_container_fs_path'] ) {
				$path = str_replace( '/var/www/htdocs/', '', $site_details['site_container_fs_path'] );
				$command .= " --public-dir=$path";
			}
			if ( ! empty( $site_details['site_ssl'] ) ) {
				$command .= " --ssl=le";
			}
			if ( ! empty( $site_details['site_ssl_wildcard'] ) ) {
				$command .= " --wildcard";
			}
		}

		if ( in_array( $site_details['site_type'], [ 'php', 'wp'] ) ) {
			if ( ! empty( $site_details['cache_nginx_browser'] ) ) {
				$command .= " --cache";
			}
			if ( ! empty( $site_details['cache_host'] ) ) {
				$command .= " --with-local-redis";
			}
			if ( ( ! empty( $site_details['db_host'] ) && 'php' === $site_details['site_type'] ) || 'wp' === $site_details['site_type'] ) {
				if ( 'php' === $site_details['site_type'] ) {
					$command .= " --with-db";
				}
			}
			$command .= " --php=${site_details['php_version']}";
		}

		if ( 'wp' === $site_details['site_type'] ) {
			if ( ! empty( $site_details['proxy_cache'] ) ) {
				$command .= " --proxy-cache=on";
			}
			if ( ! empty( $site_details['app_sub_type'] ) && 'wp' !== $site_details['app_sub_type'] ) {
				$command .= " --mu=${site_details['app_sub_type']}";
			}
			if ( ! empty( $site_details['app_admin_username'] ) ) {
				$command .= " --admin-user=${site_details['app_admin_username']}";
			}
			if ( ! empty( $site_details['app_admin_email'] ) ) {
				$command .= " --admin-email=${site_details['app_admin_email']}";
			}
			if ( ! empty( $site_details['app_admin_password'] ) ) {
				$command .= " --admin-pass=${site_details['app_admin_password']}";
			}
			// TODO: vip, proxy-cache-max-time, proxy-cache-max-size
		}
		return $command;
	}

	public function create_site( array $site_details ) : EE\ProcessRun {
		$new_site = $this->execute( $this->get_site_create_command( $site_details ) );
		$this->set_site_details();
		return $new_site;
	}

	public function site_exists_on_host() : bool {
		return 0 === $this->execute( 'ee site info ' . $this->name )->return_code;
	}

	public function ensure_site_exists_on_host() : void {
		if( ! $this->site_exists_on_host() ) {
			throw new \Exception( 'Unable to find \'' . $this->name . '\' on \'' . $this->host . '\'');
		}
	}

	public function ssh_success() : bool {
		return 0 === $this->execute( 'exit' )->return_code;
	}

	public function ensure_ssh_success() : void {
		if( ! $this->ssh_success()) {
			throw new \Exception( 'Unable to SSH to ' . $this->host );
		}
	}

	function set_site_details() : void {
		$output = $this->execute('ee site info ' . $this->name . ' --format=json' );
		$details = json_decode( $output->stdout, true );
		if ( empty( $details ) ) {
			throw new \Exception( 'Unable to get site info for site ' . $this->name . '. The output of command is: ' . $output->stdout . $output->stderr );
		}
		$this->site_details = $details;
	}

}
