<?php

namespace EE\Site\Cloner\Utils;

use EE;
use EE\Site\Cloner\Site;
use function EE\Utils\get_temp_dir;
use function EE\Utils\random_password;
use function EE\Utils\remove_trailing_slash;
use function EE\Utils\trailingslashit;

function copy_site_db( Site $source, Site $destination ) {

	$site_type = $source->site_details['site_type'];
	$db_host   = $source->site_details['db_host'];

	if ( 'php' === $site_type && ! empty( $db_host ) ) {
		EE::log( 'Please sync the database manually from the remote site. Automated database sync for non-WP PHP sites will be supported soon.' );

		return;
	}

	if ( 'wp' === $site_type ) {
		// `ee shell --command` still runs inside a double-quoted `bash -c` on that host, where escaping can't protect these.
		foreach ( [ 'db_user', 'db_password', 'db_host', 'db_name' ] as $field ) {
			if ( has_shell_special_chars( $destination->site_details[ $field ] ?? '' ) ) {
				throw new \Exception( 'Unable to import the database: ' . $field . ' of ' . $destination->name . ' contains quotes, $, ` or \\.' );
			}
		}

		EE::log( 'Exporting database from source' );

		$filename = $source->site_details['site_url'] . '-' . random_password() . '.sql';

		$source->rsp->add_step( 'clone-export-db', function () use ( $source, $destination, $filename ) {
			$source_site_name = $source->site_details['site_url'];
			$export_command   = shell_command( $source_site_name, 'wp db export --skip-ssl ' . escapeshellarg( '../' . $filename ) );

			if ( $source->execute( $export_command )->return_code ) {
				throw new \Exception( 'Unable to export database on source. Please check for file system permissions and disk space.' );
			}

			EE::log( 'Copying database to destination' );

			$source_fs_path      = trailingslashit( $source->site_details['site_fs_path'] ) . 'app/' . $filename;
			$destination_fs_path = trailingslashit( $destination->site_details['site_fs_path'] ) . 'app/' . $filename;

			$copy_db_command = rsync_command( $source->get_rsync_path( $source_fs_path ), $destination->get_rsync_path( $destination_fs_path ) );

			if ( ! EE::exec( $copy_db_command ) ) {
				throw new \Exception( 'Unable to copy database to destination. Please check for file system permissions and disk space.' );
			}
		}, function () use ( $source, $destination, $filename ) {
			remove_db_files( $source, $destination, $filename );
		} );

		$destination->rsp->add_step( 'clone-import-db', function () use ( $source, $destination, $filename ) {
			$source_site_name      = $source->site_details['site_url'];
			$destination_site_name = $destination->site_details['site_url'];

			EE::log( 'Importing database in destination' );

			$import_command = shell_command(
				$destination_site_name,
				sprintf(
					'mysql --skip-ssl -u%s -p%s -h%s %s < %s',
					escapeshellarg( $destination->site_details['db_user'] ),
					escapeshellarg( $destination->site_details['db_password'] ),
					escapeshellarg( $destination->site_details['db_host'] ),
					escapeshellarg( $destination->site_details['db_name'] ),
					escapeshellarg( '../' . $filename )
				)
			);

			if ( $destination->execute( $import_command )->return_code ) {
				throw new \Exception( 'Unable to import database on destination. Please check for file system permissions and disk space.' );
			}

			EE::log( 'Executing search-replace' );

			$http_search_replace_command  = search_replace_command( $destination_site_name, 'http://' . $source_site_name, 'http://' . $destination_site_name );
			$https_search_replace_command = search_replace_command( $destination_site_name, 'https://' . $source_site_name, 'https://' . $destination_site_name );

			if ( $destination->execute( $http_search_replace_command )->return_code ) {
				throw new \Exception( 'Unable to execute http search-replace on database at destination.' );
			}

			if ( $destination->execute( $https_search_replace_command )->return_code ) {
				throw new \Exception( 'Unable to execute https search-replace on database at destination.' );
			}

			if ( empty ( $source->site_details['site_ssl'] ) !== empty ( $destination->site_details['site_ssl'] ) ) {
				$source_site_name_http      = empty ( $source->site_details['site_ssl'] ) ? 'http://' . $destination_site_name : 'https://' . $destination_site_name;
				$destination_site_name_http = empty ( $destination->site_details['site_ssl'] ) ? 'http://' . $destination_site_name : 'https://' . $destination_site_name;

				$http_https_search_replace_command = search_replace_command( $destination_site_name, $source_site_name_http, $destination_site_name_http );

				if ( $destination->execute( $http_https_search_replace_command )->return_code ) {
					throw new \Exception( 'Unable to execute http-https search-replace on database at destination.' );
				}
			}

			remove_db_files( $source, $destination, $filename );
			clear_site_cache( $destination );
		}, function () use ( $source, $destination, $filename ) {
			remove_db_files( $source, $destination, $filename );
		} );

		// A failed step has already been rolled back; don't run the import after a failed export or report success.
		if ( ! $source->rsp->execute() || ! $destination->rsp->execute() ) {
			throw new \Exception( 'Unable to copy the database.' );
		}
	}
}

// Its object and page cache still hold what the site's own install cached, not the imported data.
function clear_site_cache( Site $site ) {
	$flags = '';
	if ( ! empty( $site->site_details['cache_mysql_query'] ) ) {
		$flags .= ' --object';
	}
	if ( ! empty( $site->site_details['cache_nginx_fullpage'] ) ) {
		$flags .= ' --page';
	}
	if ( ! $flags ) {
		return;
	}

	EE::log( 'Clearing the cache of ' . $site->name );
	if ( $site->execute( 'ee site clean ' . escapeshellarg( $site->name ) . $flags )->return_code ) {
		EE::warning( 'Unable to clear the cache of ' . $site->name . '. Run `ee site clean ' . $site->name . '` on ' . $site->host . ', or it serves stale data until the cache expires.' );
	}
}

// `ee shell <site> --command=<command>`; values inside $command must already be escaped.
function shell_command( string $site_name, string $command ): string {
	return 'ee shell --skip-tty ' . escapeshellarg( $site_name ) . ' --command=' . escapeshellarg( $command );
}

function search_replace_command( string $site_name, string $from, string $to ): string {
	return shell_command( $site_name, 'wp search-replace ' . escapeshellarg( $from ) . ' ' . escapeshellarg( $to ) . ' --network --all-tables' );
}

function remove_db_files( $source, $destination, $filename ) {
	$source_fs_path      = trailingslashit( $source->site_details['site_fs_path'] ) . 'app/' . $filename;
	$destination_fs_path = trailingslashit( $destination->site_details['site_fs_path'] ) . 'app/' . $filename;

	EE::log( 'Cleanup export file from source and destination' );

	$source->execute( 'rm ' . escapeshellarg( $source_fs_path ) );
	$destination->execute( 'rm ' . escapeshellarg( $destination_fs_path ) );
}

function copy_site_certs( Site $source, Site $destination ) {
	$certs_dir = EE_ROOT_DIR . '/services/nginx-proxy/certs/';
	$temp_dir  = get_temp_dir();
	// One rsync per file: /bin/sh has no brace expansion, and newer rsync doesn't let the remote shell expand it.
	$files = [ $source->name . '.key', $source->name . '.crt' ];

	$rsp = new EE\RevertableStepProcessor();
	$rsp->add_step( 'clone-cert-copy', function () use ( $source, $destination, $certs_dir, $temp_dir, $files ) {
		foreach ( $files as $file ) {
			if ( ! EE::exec( rsync_command( $source->get_rsync_path( $certs_dir . $file ), $destination->get_rsync_path( $temp_dir ) ) ) ) {
				throw new \Exception( 'Unable to sync certs from source site ' . $source->name . ' on host ' . $source->host );
			}
		}
		// Exit status, not output: over `ssh -t` the output ends in \r\n.
		$check = 'test -f ' . escapeshellarg( $temp_dir . $files[0] ) . ' && test -f ' . escapeshellarg( $temp_dir . $files[1] );
		if ( $destination->execute( $check )->return_code ) {
			throw new \Exception( 'Unable to sync certs from source site ' . $source->name . ' on host ' . $source->host );
		}
	}, function () use ( $destination, $temp_dir, $files ) {
		$destination->execute( 'rm -f ' . escapeshellarg( $temp_dir . $files[0] ) . ' ' . escapeshellarg( $temp_dir . $files[1] ) );
	} );

	if ( ! $rsp->execute( false ) ) {
		throw new \Exception( 'Unable to sync certs.' );
	}
}

function copy_site_files( Site $source, Site $destination, array $sync_type ) {

	$source_public_path      = str_replace( '/var/www/htdocs', '', $source->site_details['site_container_fs_path'] );
	$destination_public_path = str_replace( '/var/www/htdocs', '', $destination->site_details['site_container_fs_path'] );

	$excludes = [ '/wp-config.php' ];

	// Exclude wp-config.php from source's custom public directory structure.
	// rsync --exclude patterns are relative to the source directory being synced.
	if ( ! empty( $source_public_path ) ) {
		$excludes[] = $source_public_path . '/wp-config.php';

		// Also exclude wp-config.php one level up from public directory (WordPress convention).
		$parent_dir = dirname( $source_public_path );
		if ( $parent_dir !== '.' && $parent_dir !== '/' ) {
			$excludes[] = $parent_dir . '/wp-config.php';
		}
	}

	$source_uploads_path      = $source_public_path . '/wp-content/uploads';
	$destination_uploads_path = $destination_public_path . '/wp-content/uploads';
	$uploads_path_share       = '/shared/wp-content/uploads';

	$source_dir      = remove_trailing_slash( $source->get_site_root_dir() );
	$destination_dir = remove_trailing_slash( $destination->get_site_root_dir() );

	if ( $sync_type['uploads'] && ! $sync_type['files'] ) {
		$source_dir      .= $source_uploads_path;
		$destination_dir .= $destination_uploads_path;
	}

	if ( $sync_type['files'] && ! $sync_type['uploads'] ) {
		$excludes[] = $source_uploads_path;
		$excludes[] = $uploads_path_share;
	}

	$options = array_map(
		function ( $pattern ) {
			return '--exclude=' . $pattern;
		},
		$excludes
	);

	$rsync_command = rsync_command( trailingslashit( $source_dir ), trailingslashit( $destination_dir ), $options );
	if ( ! EE::exec( $rsync_command ) ) {
		throw new \Exception( 'Unable to sync files.' );
	}
}

// $options are single rsync arguments; everything is escaped here.
function rsync_command( string $source, string $destination, array $options = [] ) {
	$options = array_map( 'escapeshellarg', $options );

	// No --ignore-errors: with --delete-after it deletes the destination's copy of files that failed to read.
	return 'rsync -azh --delete-after ' . ( $options ? implode( ' ', $options ) . ' ' : '' ) . "-e 'ssh -t' " . escapeshellarg( $source ) . ' ' . escapeshellarg( $destination );
}

function check_site_access( Site $source_site, Site $destination_site, $sync = false ) {

	EE::log( 'Checking access to both sites' );

	$source_site->ensure_ssh_success();
	$source_site->validate_ee_version();
	$source_site->ensure_site_exists();

	$destination_site->ensure_ssh_success();
	$destination_site->validate_ee_version();

	$source_site->set_site_details();

	if ( $sync ) {
		if ( $destination_site->is_production() ) {
			EE::error( 'Can not sync to a production server.' );
		}
		$destination_site->ensure_site_exists();
		$destination_site->set_site_details();
	}

}

function get_transfer_details( string $source, string $destination ): array {

	$source_site      = Site::from_location( $source );
	$destination_site = Site::from_location( $destination );

	if ( ! $source_site->name && ! $destination_site->name ) {
		EE::error( 'No sitename found in source and destination site.' );
	} elseif ( $source_site->ssh_string && $destination_site->ssh_string ) {
		EE::error( 'Both source and destination sites cannot be remote.' );
	} elseif ( ! $source_site->name ) {
		$source_site->name = $destination_site->name;
	} elseif ( ! $destination_site->name ) {
		$destination_site->name = $source_site->name;
	}

	if ( $source_site->name === $destination_site->name && is_local_host( $source_site->host ) && is_local_host( $destination_site->host ) ) {
		EE::error( 'Cannot copy \'' . $source_site->name . '\' on \'' . $source_site->host . '\' to \'' . $destination_site->name . '\' on \'' . $destination_site->host . '\'' );
	}

	return [ $source_site, $destination_site ];
}

// True when $host (the local side is 'localhost') is this machine: loopback, its own name or address, or an ssh alias of them.
function is_local_host( string $host ): bool {
	if ( 'localhost' === $host ) {
		return true;
	}

	// Judge what ssh connects to (the alias itself when there's no HostName); the typed name only when `ssh -G` fails.
	$name   = strtolower( $host );
	$config = EE::launch( 'ssh -G ' . escapeshellarg( $host ) );
	if ( 0 === $config->return_code && preg_match( '/^hostname (\S+)/m', $config->stdout, $matches ) ) {
		$name = strtolower( $matches[1] );

		// Best effort against syncing a site onto itself: a proxy or another port (tunnels, VM forwards) likely reaches another machine.
		if ( preg_match( '/^proxy(?:jump|command) (?!none$)\S/m', $config->stdout ) || ( preg_match( '/^port (\d+)$/m', $config->stdout, $port ) && '22' !== $port[1] ) ) {
			return false;
		}
	}

	$local_names = [ 'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback', strtolower( (string) gethostname() ) ];
	$local_ips   = [ '::1', '0.0.0.0' ];
	foreach ( function_exists( 'net_get_interfaces' ) ? (array) net_get_interfaces() : [] as $interface ) {
		foreach ( $interface['unicast'] ?? [] as $address ) {
			if ( isset( $address['address'] ) ) {
				$local_ips[] = $address['address'];
			}
		}
	}

	if ( in_array( $name, $local_names, true ) ) {
		return true;
	}
	$ips = filter_var( $name, FILTER_VALIDATE_IP ) ? [ $name ] : ( gethostbynamel( $name ) ?: [] );
	foreach ( $ips as $ip ) {
		if ( 0 === strpos( $ip, '127.' ) || in_array( $ip, $local_ips, true ) ) {
			return true;
		}
	}

	return false;
}

// Hostname labels, plus underscores that older sites may have; the name ends up in commands and paths.
function is_valid_site_name( string $name ): bool {
	$label = '[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?';

	return strlen( $name ) <= 253 && 1 === preg_match( '/^' . $label . '(?:\.' . $label . ')*$/iD', $name );
}

// Quotes, $, ` and \ (still special inside double quotes) and control characters.
function has_shell_special_chars( $value ): bool {
	return 1 === preg_match( '/[\'"`$\\\\\x00-\x1f\x7f]/', (string) $value );
}

function get_user_home_dir( string $user ) {
	static $path;

	if ( $path ) {
		return $path;
	}

	$path = EE::launch( "printf ~$user" )->stdout;

	return $path;
}
