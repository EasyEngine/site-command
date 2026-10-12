<?php

namespace EE\Site\Cloner;

use Composer\Semver\Comparator;
use EE;
use function EE\Site\Cloner\Utils\has_shell_special_chars;
use function EE\Site\Cloner\Utils\is_valid_site_name;
use function EE\Site\Cloner\Utils\rsync_command;
use function EE\Utils\get_temp_dir;

class Site {
	// First release with clone and sync (site-command v3.0.0).
	const MIN_EE_VERSION = '4.5.0';

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
		$location = trim( $location );

		// Remote. Strict user and host patterns keep ssh options and shell syntax out of the ssh and rsync commands.
		if ( false !== strpos( $location, ':' ) || false !== strpos( $location, '@' ) ) {
			$details = null;
			if ( ! preg_match( '/^(?<username>[A-Za-z0-9_][A-Za-z0-9_.-]*)@(?<host>[A-Za-z0-9](?:[A-Za-z0-9_.-]*[A-Za-z0-9])?):(?<sitename>\S+)$/D', $location, $details ) ) {
				EE::error( 'Invalid format for remote site. Please use [user@ssh-hostname:] sitename' );
			}
			$sitename = self::parse_site_name( $details['sitename'] );

			return new EE\Site\Cloner\Site( $sitename, $details['host'], $details['username'], $details['username'] . '@' . $details['host'], [] );
		}

		// Local
		return new EE\Site\Cloner\Site( self::parse_site_name( $location ), 'localhost', '', '', [] );
	}

	// `.` means "same name as the other side".
	private static function parse_site_name( string $sitename ): string {
		if ( '.' === $sitename ) {
			return '';
		}
		if ( ! is_valid_site_name( $sitename ) ) {
			EE::error( 'Invalid site name: ' . $sitename );
		}

		return $sitename;
	}

	public function execute( string $command ): EE\ProcessRun {
		return EE::launch( $this->get_ssh_command( $command ) );
	}

	private function get_ssh_command( string $command ): string {
		return $this->ssh_string ? 'ssh -t ' . escapeshellarg( $this->ssh_string ) . ' ' . escapeshellarg( $command ) : $command;
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

		if ( 0 !== $result->return_code || ! preg_match( '/^EE (?<version>\d+\.\d+\.\d+)/m', $result->stdout, $matches ) ) {
			throw new \Exception( 'Unable to find EasyEngine on ' . $this->host . ': `ee cli version` failed or printed no version.' );
		}

		if ( Comparator::lessThan( $matches['version'], self::MIN_EE_VERSION ) ) {
			throw new \Exception( 'EasyEngine ' . $matches['version'] . ' on ' . $this->host . ' is too old for clone and sync. Please update it to ' . self::MIN_EE_VERSION . ' or later.' );
		}
	}

	public function validate_parent_site_present_on_host( string $site ): void {
		$list_result = $this->execute( 'ee site list --format=json' );
		$list_result = json_decode( $list_result->stdout, true );

		foreach ( $list_result as $site_details ) {
			$parent_site  = $site_details['site'];
			$substr_match = strpos( $site, $parent_site );

			if ( $substr_match !== false && $substr_match !== 0 ) {
				if ( explode( '.', $site, 2 )[1] === $parent_site ) {
					$info_result = $this->execute( 'ee site info ' . escapeshellarg( $parent_site ) . ' --format=json' );
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

		// A bare --ssl means le, as in `ee site create`; --no-ssl and an empty --ssl= keep the source's SSL.
		$ssl = $assoc_args['ssl'] ?? false;
		if ( true === $ssl ) {
			$ssl = 'le';
		}

		if ( $ssl ) {
			if ( ! in_array( $ssl, [ 'le', 'off', 'self', 'inherit', 'custom' ], true ) ) {
				throw new \Exception( 'Invalid --ssl value: ' . $ssl );
			}
			if ( 'off' !== $ssl ) {
				$ssl_args .= ' --ssl=' . $ssl;
				if ( 'custom' === $ssl ) {
					if ( empty( $assoc_args['ssl-key'] ) || empty( $assoc_args['ssl-crt'] ) ) {
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

					$ssl_args .= ' --ssl-crt=' . escapeshellarg( get_temp_dir() . basename( $assoc_args['ssl-crt'] ) );
					$ssl_args .= ' --ssl-key=' . escapeshellarg( get_temp_dir() . basename( $assoc_args['ssl-key'] ) );
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
					$ssl_args .= ' --ssl=custom --ssl-key=' . escapeshellarg( get_temp_dir() . $source_site->name . '.key' ) . ' --ssl-crt=' . escapeshellarg( get_temp_dir() . $source_site->name . '.crt' );
				} elseif ( $site_details['site_ssl'] === 'inherit' ) {
					$this->validate_parent_site_present_on_host( $source_site->name );
					$ssl_args .= ' --ssl=inherit';
				}
			} else {
				// If name of src and dest site are not same
				if ( $site_details['site_ssl'] === 'custom' || $site_details['site_ssl'] === 'inherit' ) {
					EE::warning( 'Unable to enable SSL for ' . $this->name . ' as the source site was created with --ssl=custom or --ssl=inherited. You can enable SSL with \'ee site update\' once site is cloned.' );
				}
			}

			if ( $site_details['site_ssl'] === 'self' || $site_details['site_ssl'] === 'le' ) {
				$ssl_args .= ' --ssl=' . $site_details['site_ssl'];
			}

			if ( $site_details['site_ssl_wildcard'] ) {
				$ssl_args .= ' --wildcard';
			}
		}

		return $ssl_args;
	}

	private function get_site_create_command( Site $source_site, $assoc_args ): string {
		$site_details = $source_site->site_details;
		$command      = 'ee site create --skip-tty ' . escapeshellarg( $this->name ) . ' --type=' . escapeshellarg( $site_details['site_type'] );

		if ( '/var/www/htdocs' !== $site_details['site_container_fs_path'] ) {
			$path    = str_replace( '/var/www/htdocs/', '', $site_details['site_container_fs_path'] );
			$command .= ' --public-dir=' . escapeshellarg( $path );
		}
		$command .= $this->get_ssl_args( $source_site, $assoc_args );

		if ( in_array( $site_details['site_type'], [ 'php', 'wp' ] ) ) {
			if ( ! empty( $site_details['cache_nginx_browser'] ) ) {
				$command .= ' --cache';
			}
			if ( ! empty( $site_details['cache_host'] ) && 'redis' === $site_details['cache_host'] ) {
				$command .= ' --with-local-redis';
			}
			if ( ( ! empty( $site_details['db_host'] ) && 'php' === $site_details['site_type'] ) ) {
				$command .= ' --with-db';
			}
			$command .= ' --php=' . escapeshellarg( $site_details['php_version'] );
		}

		if ( 'wp' === $site_details['site_type'] ) {
			if ( 'on' === $site_details['proxy_cache'] ) {
				$command .= ' --proxy-cache=on';
			}
			if ( ! empty( $site_details['app_sub_type'] ) && 'wp' !== $site_details['app_sub_type'] ) {
				$command .= ' --mu=' . escapeshellarg( $site_details['app_sub_type'] );
			}
			$admin_args = [
				'app_admin_username' => 'admin-user',
				'app_admin_email'    => 'admin-email',
				'app_admin_password' => 'admin-pass',
			];
			foreach ( $admin_args as $field => $flag ) {
				if ( empty( $site_details[ $field ] ) ) {
					continue;
				}
				// site-type-wp pastes these into a quoted `bash -c` string on the destination, so escaping here isn't enough.
				if ( has_shell_special_chars( $site_details[ $field ] ) ) {
					EE::warning( 'Not copying --' . $flag . ' of ' . $source_site->name . ': it contains quotes, $, ` or \\. ' . $this->name . ' gets a generated value; copying the database brings the source\'s WordPress users.' );
					continue;
				}
				$command .= ' --' . $flag . '=' . escapeshellarg( $site_details[ $field ] );
				if ( 'admin-pass' === $flag ) {
					$command .= ' --allow-insecure-pass';
				}
			}
			// TODO: vip, proxy-cache-max-time, proxy-cache-max-size
		}

		return $command;
	}

	private function get_admin_tools_command( Site $source_site, $assoc_args ): string {
		$site_details = $source_site->site_details;

		$command = '';

		if ( $site_details['admin_tools'] ) {
			$command = 'ee admin-tools enable ' . escapeshellarg( $this->name );
		}

		return $command;
	}

	private function get_mailhog_command( Site $source_site, $assoc_args ): string {
		$site_details = $source_site->site_details;

		$command = '';

		if ( $site_details['mailhog_enabled'] ) {
			$command = 'ee mailhog enable --skip-tty ' . escapeshellarg( $this->name );
		}

		return $command;
	}

	public function create_site( Site $source_site, $assoc_args ): EE\ProcessRun {
		EE::log( 'Creating site. This may take a while...' );
		EE::debug( 'Creating site "' . $this->name . '" on "' . $this->host . '"' );

		$new_site = '';
		$this->ensure_site_not_exists();
		$this->rsp->add_step( 'clone-create-site', function () use ( $source_site, $assoc_args, &$new_site ) {
			$new_site = $this->execute( $this->get_site_create_command( $source_site, $assoc_args ) );
			$this->set_site_details();
		}, function () {
			$this->execute( 'ee site delete --skip-tty --yes ' . escapeshellarg( $this->name ) );
		} );

		if ( ! $this->rsp->execute() ) {
			throw new \Exception( 'Unable to create site.' );
		}

		$admin_tools_command = $this->get_admin_tools_command( $source_site, $assoc_args );
		if ( ! empty( $admin_tools_command ) ) {
			EE::log( 'Setting up admin-tools' );
			$this->rsp->add_step( 'clone-setup-admin-tools', function () use ( $admin_tools_command ) {
				$this->execute( $admin_tools_command );
				$this->set_site_details();
			}, function () {
				$this->execute( 'ee site delete --skip-tty --yes ' . escapeshellarg( $this->name ) );
			} );
		}

		if ( ! $this->rsp->execute() ) {
			throw new \Exception( 'Unable to enable admin-tools.' );
		}

		$mailhog_command = $this->get_mailhog_command( $source_site, $assoc_args );
		if ( ! empty( $mailhog_command ) ) {
			EE::log( 'Setting up mailhog' );
			$this->rsp->add_step( 'clone-setup-mailhog', function () use ( $mailhog_command ) {
				$this->execute( $mailhog_command );
				$this->set_site_details();
			}, function () {
				$this->execute( 'ee site delete --skip-tty --yes ' . escapeshellarg( $this->name ) );
			} );
		}

		if ( ! $this->rsp->execute() ) {
			throw new \Exception( 'Unable to enable mailhogs.' );
		}

		return $new_site;
	}

	public function site_exists(): bool {
		$site_list = $this->execute( 'ee site list --format=json --no-color' );

		if ( 1 === $site_list->return_code ) {
			$error = trim ( preg_replace( '#\\x1b[[][^A-Za-z]*[A-Za-z]#', '', $site_list->stdout ) );
			if ( 'Error: No sites found!' === $error ) {
				return false;
			}
		}

		if ( 0 !== $site_list->return_code ) {
			throw new \Exception( 'Unable to get site list on ' . $this->user . '@' . $this->host );
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

	public function is_production(): bool {
		$output = $this->execute( 'ee config get env' );
		return 'production' === strtolower( trim( $output->stdout ) );
	}

	function set_site_details(): void {
		$this->ensure_site_exists();

		$output  = $this->execute( 'ee site info ' . escapeshellarg( $this->name ) . ' --format=json' );
		$details = json_decode( $output->stdout, true );
		if ( empty( $details ) || ! is_array( $details ) ) {
			throw new \Exception( 'Unable to get info for site ' . $this->name );
		}
		$this->validate_site_details( $details );
		$this->site_details = $details;
	}

	// The info may come from another host, and these values end up in local paths and commands.
	private function validate_site_details( array $details ): void {
		$name     = preg_quote( $this->name, '#' );
		$required = [
			'site_url'               => '#^' . $name . '$#D',
			'site_type'              => '#^[a-z0-9_-]+$#D',
			'site_fs_path'           => '#^(?:/[A-Za-z0-9_@+-][A-Za-z0-9_@+.-]*)*/sites/' . $name . '$#D',
			'site_container_fs_path' => '#^/var/www/htdocs(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$#D',
		];
		$optional = [
			'php_version'  => '#^[A-Za-z0-9.]*$#D',
			'app_sub_type' => '#^[a-z]*$#D',
		];

		foreach ( $required + $optional as $field => $pattern ) {
			$value = $details[ $field ] ?? null;
			if ( null === $value && isset( $optional[ $field ] ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) || ! preg_match( $pattern, (string) $value ) ) {
				throw new \Exception( 'Unexpected ' . $field . ' in the site info of ' . $this->name . ' on ' . $this->host );
			}
		}
	}

}
