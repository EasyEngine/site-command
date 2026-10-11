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
		EE::log( 'Exporting database from source' );

		$filename = $source->site_details['site_url'] . '-' . random_password() . '.sql';

		$source->rsp->add_step( 'clone-export-db', function () use ( $source, $destination, $filename ) {
			$source_site_name = $source->site_details['site_url'];
			$export_command   = 'ee shell --skip-tty ' . $source_site_name . ' --command=\'wp db export --skip-ssl ../' . $filename . '\'';

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
			$source_fs_path        = trailingslashit( $source->site_details['site_fs_path'] ) . 'app/' . $filename;
			$destination_fs_path   = trailingslashit( $destination->site_details['site_fs_path'] ) . 'app/' . $filename;

			EE::log( 'Importing database in destination' );

			$DB_USER     = $destination->site_details['db_user'];
			$DB_PASSWORD = $destination->site_details['db_password'];
			$DB_HOST     = $destination->site_details['db_host'];
			$DB_NAME     = $destination->site_details['db_name'];

			$import_command = "ee shell --skip-tty $destination_site_name --command='mysql --skip-ssl -u\"$DB_USER\" -p\"$DB_PASSWORD\" -h\"$DB_HOST\" \"$DB_NAME\" < ../$filename'";

			if ( $destination->execute( $import_command )->return_code ) {
				throw new \Exception( 'Unable to import database on destination. Please check for file system permissions and disk space.' );
			}

			EE::log( 'Executing search-replace' );

			$http_search_replace_command  = 'ee shell --skip-tty ' . $destination_site_name . ' --command=\'wp search-replace http://' . $source_site_name . ' http://' . $destination_site_name . ' --network --all-tables\'';
			$https_search_replace_command = 'ee shell --skip-tty ' . $destination_site_name . ' --command=\'wp search-replace https://' . $source_site_name . ' https://' . $destination_site_name . ' --network --all-tables\'';

			if ( $destination->execute( $http_search_replace_command )->return_code ) {
				throw new \Exception( 'Unable to execute http search-replace on database at destination.' );
			}

			if ( $destination->execute( $https_search_replace_command )->return_code ) {
				throw new \Exception( 'Unable to execute https search-replace on database at destination.' );
			}

			if ( empty ( $source->site_details['site_ssl'] ) !== empty ( $destination->site_details['site_ssl'] ) ) {
				$source_site_name_http      = empty ( $source->site_details['site_ssl'] ) ? 'http://' . $destination_site_name : 'https://' . $destination_site_name;
				$destination_site_name_http = empty ( $destination->site_details['site_ssl'] ) ? 'http://' . $destination_site_name : 'https://' . $destination_site_name;

				$http_https_search_replace_command = 'ee shell --skip-tty ' . $destination_site_name . ' --command=\'wp search-replace ' . $source_site_name_http . ' ' . $destination_site_name_http . ' --network --all-tables\'';

				if ( $destination->execute( $http_https_search_replace_command )->return_code ) {
					throw new \Exception( 'Unable to execute http-https search-replace on database at destination.' );
				}
			}

			remove_db_files( $source, $destination, $filename );
		}, function () use ( $source, $destination, $filename ) {
			remove_db_files( $source, $destination, $filename );
		} );

		$source->rsp->execute();
		$destination->rsp->execute();
	}
}

function remove_db_files( $source, $destination, $filename ) {
	$source_fs_path      = trailingslashit( $source->site_details['site_fs_path'] ) . 'app/' . $filename;
	$destination_fs_path = trailingslashit( $destination->site_details['site_fs_path'] ) . 'app/' . $filename;

	$rm_db_src_command  = 'rm ' . $source_fs_path;
	$rm_db_dest_command = 'rm ' . $destination_fs_path;

	EE::log( 'Cleanup export file from source and destination' );

	$source->execute( $rm_db_src_command );
	$destination->execute( $rm_db_dest_command );
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

	$exclude = '--exclude \'/wp-config.php\'';

	// Exclude wp-config.php from source's custom public directory structure.
	// rsync --exclude patterns are relative to the source directory being synced.
	if ( ! empty( $source_public_path ) ) {
		$exclude .= ' --exclude \'' . $source_public_path . '/wp-config.php\'';

		// Also exclude wp-config.php one level up from public directory (WordPress convention).
		$parent_dir = dirname( $source_public_path );
		if ( $parent_dir !== '.' && $parent_dir !== '/' ) {
			$exclude .= ' --exclude \'' . $parent_dir . '/wp-config.php\'';
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
		$exclude .= ' --exclude \'' . $source_uploads_path . '\'';
		$exclude .= ' --exclude \'' . $uploads_path_share . '\'';
	}

	$rsync_command = rsync_command( trailingslashit( $source_dir ), trailingslashit( $destination_dir ), [ $exclude ] );
	if ( ! EE::exec( $rsync_command ) ) {
		throw new \Exception( 'Unable to sync files.' );
	}
}

function rsync_command( string $source, string $destination, array $options = [] ) {
	$ssh_command   = 'ssh -t';
	$extra_options = implode( ' ', $options );

	// No --ignore-errors: with --delete-after it deletes the destination's copy of files that failed to read.
	return 'rsync -azh --delete-after ' . $extra_options . ' -e "' . $ssh_command . '" ' . $source . ' ' . $destination;
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

	if ( 'localhost' === $source_site->host && 'localhost' === $destination_site->host && $source_site->name === $destination_site->name ) {
		EE::error( 'Cannot copy \'' . $source_site->name . '\' on \'' . $source_site->host . '\' to \'' . $destination_site->name . '\' on \'' . $destination_site->host . '\'' );
	}

	return [ $source_site, $destination_site ];
}

function get_user_home_dir( string $user ) {
	static $path;

	if ( $path ) {
		return $path;
	}

	$path = EE::launch( "printf ~$user" )->stdout;

	return $path;
}
