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

	public function get_site_root_dir() : string {
		return $this->get_rsync_path( $this->site_details['site_fs_path'] . '/app/htdocs/' );
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

	private function get_ssl_args( Site $source_site ) : string {
		$site_details = $source_site->site_details;
		$ssl_args='';
		$add_wildcard=false;

		if ( $this->name === $source_site->name ) {
			if ( $site_details['site_ssl'] === 'le' || $site_details['site_ssl'] === 'custom' ) {
				EE\Site\Cloner\Utils\copy_site_certs( $source_site, $this );
				$ssl_args .= ' --ssl=custom --ssl-key=\'' . '/tmp/' . $source_site->name . '.key\' --ssl-crt=\'/tmp/' . $source_site->name . '.crt\'';
			} elseif ( $site_details['site_ssl'] === 'inherit' ) {
				EE::warning( 'Unable to enable SSL for ' . $this->name . ' as the source site was created with --ssl=custom. You can enable SSL with \'ee site update\' once site is cloned.' );
			} else {
				$ssl_args .= ' --ssl=' . $site_details['site_ssl'];
				$add_wildcard=true;
			}
		} else {
			if ( $site_details['site_ssl'] === 'custom' || $site_details['site_ssl'] === 'inherit' ) {
				EE::warning( 'Unable to enable SSL for ' . $this->name . ' as the source site was created with --ssl=custom or --ssl=inherited. You can enable SSL with \'ee site update\' once site is cloned.' );
			} else {
				$ssl_args .= ' --ssl=' . $site_details['site_ssl'];
				$add_wildcard=true;
			}
		}

		if ( $add_wildcard ) {
			if ( $site_details['site_ssl_wildcard'] ) {
				$ssl_args .= ' --wildcard';
			}
		}
		return $ssl_args;
	}

	private function get_site_create_command( Site $source_site ) : string {
		$site_details = $source_site->site_details;
		$command = 'ee site create ' . $this->name . ' --type=' . $site_details['site_type'] ;

		if ( in_array( $site_details['site_type'], [ 'html', 'php', 'wp'] ) ) {
			if ( '/var/www/htdocs' !== $site_details['site_container_fs_path'] ) {
				$path = str_replace( '/var/www/htdocs/', '', $site_details['site_container_fs_path'] );
				$command .= " --public-dir=$path";
			}
			$command .= $this->get_ssl_args( $source_site );
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
//				$command .= " --admin-pass=${site_details['app_admin_password']}";
			}
			// TODO: vip, proxy-cache-max-time, proxy-cache-max-size
		}
		return $command;
	}

	public function create_site( Site $source_site ) : EE\ProcessRun {
		$new_site = $this->execute( $this->get_site_create_command( $source_site ) );
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
