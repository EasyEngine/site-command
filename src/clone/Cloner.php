<?php

namespace EE\Site\Cloner;

use Composer\Semver\Comparator;
use EE;
use function EE\Site\Cloner\Utils\rsync_command;

class Site {
	public $name, $host, $user, $ssh_string, $site_details;

	function __construct( string $name, string $host, string $user, string $ssh_string, array $site_details ) {
		$this->name         = $name;
		$this->host         = $host;
		$this->user         = $user;
		$this->ssh_string   = $ssh_string;
		$this->site_details = $site_details;
	}

	public static function from_location( string $location ): Site {
		$user = '';
		$ssh  = '';

		$location = trim( $location );

		// Remote
		$details = null;
		preg_match( "/^(?'ssh'(?'username'\S+)@(?'host'[^:]+)):(?'sitename'\S+)/", $location, $details );

		if ( ! $details && ( strpos( $location, ':' ) || strpos( $location, '@' ) ) ) {
			EE::error( 'Invalid format for remote site. Please use [user@ssh-hostname:] sitename' );
		}
		if ( $details ) {
			$host     = $details['host'];
			$user     = $details['username'];
			$ssh      = $details['ssh'];
			$sitename = $details['sitename'] ?? null;

			$site = new EE\Site\Cloner\Site( $sitename, $host, $user, $ssh, [] );

			return $site;
		}

		// Local
		$host     = 'localhost';
		$sitename = $location === '.' ? '' : $location;

		$site = new EE\Site\Cloner\Site( $sitename, $host, $user, $ssh, [] );

		return $site;
	}

	public function execute( string $command ): EE\ProcessRun {
		return EE::launch( $this->get_ssh_command( $command ) );
	}

	private function get_ssh_command( string $command ): string {
		return $this->ssh_string ? 'ssh -t ' . $this->ssh_string . ' "' . $command . '"' : $command;
	}

	public function get_rsync_path( string $path ): string {
		return $this->ssh_string ? $this->ssh_string . ':' . $path : $path;
	}

	public function get_site_root_dir(): string {
		return $this->get_rsync_path( $this->site_details['site_fs_path'] . '/app/htdocs/' );
	}

	public function validate_ee_version(): void {
		$result  = $this->execute( 'ee cli version' );
		$matches = null;

		preg_match( "/^EE (?'version'\S+)/", $result->stdout, $matches );
		$matches['version'] = preg_replace('/-nightly.*$/', '', $matches['version']);
	}

	private function get_ssl_args( Site $source_site, $assoc_args ): string {
		$site_details = $source_site->site_details;
		$ssl_args     = '';
		$add_wildcard = false;

		if ( $assoc_args['ssl'] ?? false ) {
			if ( $assoc_args['ssl'] !== 'off' ) {
				$ssl_args .= ' --ssl=' . $assoc_args['ssl'];
				if ( $assoc_args['ssl'] === 'custom' ) {
					if ( ! ( $assoc_args['ssl-key'] ?? false && $assoc_args['ssl-crt'] ?? false ) ) {
						EE::error( 'You need to specify --ssl-crt and --ssl-key with --ssl=custom' );
					}
					if ( ! is_file( $assoc_args['ssl-crt'] ) ) {
						EE::error( 'Unable to find file specified in --ssl-crt at \'' . $assoc_args['ssl-crt'] . '\'' );
					}
					if ( ! is_file( $assoc_args['ssl-key'] ) ) {
						EE::error( 'Unable to find file specified in --ssl-key at \'' . $assoc_args['ssl-key'] . '\'' );
					}

					$rsync_command_crt = rsync_command( $assoc_args['ssl-crt'], $this->get_rsync_path( '/tmp/' ) );
					$rsync_command_key = rsync_command( $assoc_args['ssl-key'], $this->get_rsync_path( '/tmp/' ) );

					if ( ! ( EE::exec( $rsync_command_key ) && EE::exec( $rsync_command_crt ) ) ) {
						EE::error( 'Unable to sync certs.' );
					}

					$ssl_args .= ' --ssl-crt=\'' . '/tmp/' . basename( $assoc_args['ssl-crt'] ) . '\'';
					$ssl_args .= ' --ssl-key=\'' . '/tmp/' . basename( $assoc_args['ssl-key'] ) . '\'';
				}
				if ( $assoc_args['wildcard'] ?? false ) {
					$ssl_args .= ' --wildcard';
				}
			}

			return $ssl_args;
		}

		if ( ! empty( $site_details['site_ssl'] ) ) {
			// If name of src and dest site are same
			if ( $this->name === $source_site->name ) {
				if ( $site_details['site_ssl'] === 'le' || $site_details['site_ssl'] === 'custom' ) {
					EE\Site\Cloner\Utils\copy_site_certs( $source_site, $this );
					$ssl_args .= ' --ssl=custom --ssl-key=\'' . '/tmp/' . $source_site->name . '.key\' --ssl-crt=\'/tmp/' . $source_site->name . '.crt\'';
					if ( $site_details['site_ssl_wildcard'] ) {
						$ssl_args .= ' --wildcard';
					}
				} elseif ( $site_details['site_ssl'] === 'inherit' ) {
					EE::warning( 'Unable to enable SSL for ' . $this->name . ' as the source site was created with --ssl=custom. You can enable SSL with \'ee site update\' once site is cloned.' );
				} elseif ( $site_details['site_ssl'] === 'self' ) {
					$ssl_args .= ' --ssl=' . $site_details['site_ssl'];
					if ( $site_details['site_ssl_wildcard'] ) {
						$ssl_args .= ' --wildcard';
					}
				}
			} else {
				// If name of src and dest site are note same
				if ( $site_details['site_ssl'] === 'custom' || $site_details['site_ssl'] === 'inherit' ) {
					EE::warning( 'Unable to enable SSL for ' . $this->name . ' as the source site was created with --ssl=custom or --ssl=inherited. You can enable SSL with \'ee site update\' once site is cloned.' );
				} elseif ( $site_details['site_ssl'] === 'self' ) {
					$ssl_args .= ' --ssl=' . $site_details['site_ssl'];
					if ( $site_details['site_ssl_wildcard'] ) {
						$ssl_args .= ' --wildcard';
					}
				}
			}
		}

		return $ssl_args;
	}

	private function get_site_create_command( Site $source_site, $assoc_args ): string {
		$site_details = $source_site->site_details;
		$command      = 'ee site create ' . $this->name . ' --type=' . $site_details['site_type'];

		if ( '/var/www/htdocs' !== $site_details['site_container_fs_path'] ) {
			$path    = str_replace( '/var/www/htdocs/', '', $site_details['site_container_fs_path'] );
			$command .= " --public-dir=$path";
		}
		$command .= $this->get_ssl_args( $source_site, $assoc_args );

		if ( in_array( $site_details['site_type'], [ 'php', 'wp' ] ) ) {
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

	public function create_site( Site $source_site, $assoc_args ): EE\ProcessRun {
		EE::log( 'Creating site' );
		EE::debug( 'Creating site "' . $this->name . '" on "' . $this->host . '"' );

		$this->ensure_site_not_exists();
		$new_site = $this->execute( $this->get_site_create_command( $source_site, $assoc_args ) );
		$this->set_site_details();

		return $new_site;
	}

	public function site_exists(): bool {
		return 0 === $this->execute( 'ee site info ' . $this->name )->return_code;
	}

	public function ensure_site_exists(): void {
		if ( ! $this->site_exists() ) {
			EE::error( 'Unable to find \'' . $this->name . '\' on \'' . $this->host . '\'' );
		}
	}

	public function ensure_site_not_exists(): void {
		if ( $this->site_exists() ) {
			EE::error( 'Site  \'' . $this->name . '\' already exists on \'' . $this->host . '\'' );
		}
	}

	public function ssh_success(): bool {
		return 0 === $this->execute( 'exit' )->return_code;
	}

	public function ensure_ssh_success(): void {
		if ( ! $this->ssh_success() ) {
			EE::error( 'Unable to SSH to ' . $this->host );
		}
	}

	function set_site_details(): void {
		$output  = $this->execute( 'ee site info ' . $this->name . ' --format=json' );
		$details = json_decode( $output->stdout, true );
		if ( empty( $details ) ) {
			EE::error( 'Unable to get site info for site ' . $this->name . '. The output of command is: ' . $output->stdout . $output->stderr );
		}
		$this->site_details = $details;
	}

}
