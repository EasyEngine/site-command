<?php

namespace EE\Site\Cloner;

use Composer\Semver\Comparator;
use EE;
use function EE\Site\Cloner\Utils\rsync_command;
use function EE\Utils\get_temp_dir;

class Site {
	public $name, $host, $user, $ssh_string, $site_details, $rsp;

	function __construct( string $name, string $host, string $user, string $ssh_string, array $site_details ) {
		$this->name         = $name;
		$this->host         = $host;
		$this->user         = $user;
		$this->ssh_string   = $ssh_string;
		$this->site_details = $site_details;
		$this->rsp          = new EE\RevertableStepProcessor();
	}

	public function rollback() {
		$this->rsp->rollback();
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
		$matches['version'] = preg_replace( '/-nightly.*$/', '', $matches['version'] );
	}

	public function validate_parent_site_present_on_host( string $site ): void {
		$list_result = $this->execute( 'ee site list --format=json' );
		$list_result = json_decode( $list_result->stdout, true );

		foreach ( $list_result as $site_details ) {
			$parent_site  = $site_details['site'];
			$substr_match = strpos( $site, $parent_site );

			if ( $substr_match !== false && $substr_match !== 0 ) {
				if ( explode( '.', $site, 2 )[1] === $parent_site ) {
					$info_result = $this->execute( 'ee site info ' . $parent_site . ' --format=json' );
					$info_result = json_decode( $info_result->stdout, true );
					if ( $info_result['site_ssl'] !== '' && $info_result['site_ssl_wildcard'] === '1' ) {
						return;
					}
				}
			}
		}

		throw new \Exception( 'Parent site of ' . $site . ' not found on destination host ' . $this->host );
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
						throw new \Exception( 'You need to specify --ssl-crt and --ssl-key with --ssl=custom' );
					}
					if ( ! is_file( $assoc_args['ssl-crt'] ) ) {
						throw new \Exception( 'Unable to find file specified in --ssl-crt at \'' . $assoc_args['ssl-crt'] . '\'' );
					}
					if ( ! is_file( $assoc_args['ssl-key'] ) ) {
						throw new \Exception( 'Unable to find file specified in --ssl-key at \'' . $assoc_args['ssl-key'] . '\'' );
					}

					$rsync_command_crt = rsync_command( $assoc_args['ssl-crt'], $this->get_rsync_path( get_temp_dir() ) );
					$rsync_command_key = rsync_command( $assoc_args['ssl-key'], $this->get_rsync_path( get_temp_dir() ) );

					if ( ! ( EE::exec( $rsync_command_key ) && EE::exec( $rsync_command_crt ) ) ) {
						throw new \Exception( 'Unable to sync certs.' );
					}

					$ssl_args .= ' --ssl-crt=\'' . get_temp_dir() . basename( $assoc_args['ssl-crt'] ) . '\'';
					$ssl_args .= ' --ssl-key=\'' . get_temp_dir() . basename( $assoc_args['ssl-key'] ) . '\'';
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
				if ( $site_details['site_ssl'] === 'custom' ) {
					EE\Site\Cloner\Utils\copy_site_certs( $source_site, $this );
					$ssl_args .= ' --ssl=custom --ssl-key=\'' . get_temp_dir() . $source_site->name . '.key\' --ssl-crt=\'' . get_temp_dir() . $source_site->name . '.crt\'';
					if ( $site_details['site_ssl_wildcard'] ) {
						$ssl_args .= ' --wildcard';
					}
				} elseif ( $site_details['site_ssl'] === 'inherit' ) {
					$this->validate_parent_site_present_on_host( $source_site->name );
					$ssl_args .= ' --ssl=' . $site_details['site_ssl'];
				} elseif ( $site_details['site_ssl'] === 'self' || $site_details['site_ssl'] === 'le' ) {
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
				$command .= ' --cache';
			}
			if ( ! empty( $site_details['cache_host'] ) ) {
				$command .= ' --with-local-redis';
			}
			if ( ( ! empty( $site_details['db_host'] ) && 'php' === $site_details['site_type'] ) ) {
				$command .= ' --with-db';
			}
			$command .= " --php=${site_details['php_version']}";
		}

		if ( 'wp' === $site_details['site_type'] ) {
			if ( ! empty( $site_details['proxy_cache'] ) ) {
				$command .= ' --proxy-cache=on';
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

		$new_site = '';
		$this->ensure_site_not_exists();
		$this->rsp->add_step( 'clone-create-site', function () use ( $source_site, $assoc_args, &$new_site ) {
			$new_site = $this->execute( $this->get_site_create_command( $source_site, $assoc_args ) );
			$this->set_site_details();
		}, function () {
			$this->execute( 'ee site delete --yes ' . $this->name );
		} );

		if ( ! $this->rsp->execute() ) {
			throw new \Exception( 'Unable to create site.' );
		}

		return $new_site;
	}

	public function site_exists(): bool {
		$site_list = $this->execute( 'ee site list --format=json' );

		if ( 1 === $site_list->return_code && 'Error: No sites found!' . PHP_EOL === $site_list->stderr ) {
			return false;
		}

		if ( 0 !== $site_list->return_code ) {
			throw new \Exception( 'Unable to get site list on remote server.' );
		}

		$sites = json_decode( $site_list->stdout, true );

		foreach ( $sites as $site ) {
			if ( $site['site'] === $this->name ) {
				if ( 'disabled' === $site['status'] ) {
					throw new \Exception( 'The site that you want to clone has been disabled. Please enable the site and clone again.' );
				}

				return true;
			}
		}

		return false;
	}

	public function ensure_site_exists(): void {
		if ( ! $this->site_exists() ) {
			throw new \Exception( 'Unable to find \'' . $this->name . '\' on \'' . $this->host . '\'' );
		}
	}

	public function ensure_site_not_exists(): void {
		if ( $this->site_exists() ) {
			throw new \Exception( 'Site  \'' . $this->name . '\' already exists on \'' . $this->host . '\'' );
		}
	}

	public function ssh_success(): bool {
		return 0 === $this->execute( 'exit' )->return_code;
	}

	public function ensure_ssh_success(): void {
		if ( ! $this->ssh_success() ) {
			throw new \Exception( 'Unable to SSH to ' . $this->host );
		}
	}

	function set_site_details(): void {
		$this->ensure_site_exists();

		$output  = $this->execute( 'ee site info ' . $this->name . ' --format=json' );
		$details = json_decode( $output->stdout, true );
		if ( empty( $details ) ) {
			throw new \Exception( 'Unable to get info for site ' . $this->name );
		}
		$this->site_details = $details;
	}

}
