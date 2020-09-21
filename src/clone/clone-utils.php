<?php

namespace EE\Site\Cloner\Utils;

use EE;
use EE\Site\Cloner\Site;
use function EE\Utils\random_password;
use function EE\Utils\trailingslashit;

function copy_site_db( Site $source, Site $destination ) {

	$site_type = $source->site_details['site_type'];
	$db_host = $source->site_details['db_host'];

	if ( 'wp' === $site_type || 'php' === $site_type && ! empty( $db_host ) ) {
		$source_site_name = $source->site_details['site_url'];
		$destination_site_name = $destination->site_details['site_url'];

		EE::log( 'Exporting database from source' );

		$filename = $source_site_name . '-' . random_password() . '.sql';
		$export_command = 'ee shell --skip-tty ' . $source_site_name . ' --command=\'wp db export ../' . $filename . '\'';

		if ( $source->execute( $export_command )->return_code ) {
			throw new \Exception( 'Unable to export database on source. Please check for file system permissions and disk space.' );
		}

		EE::log( 'Copying database to destination' );

		$source_fs_path = trailingslashit( $source->site_details['site_fs_path'] ) . 'app/' . $filename ;
		$destination_fs_path = trailingslashit( $destination->site_details['site_fs_path'] ) . 'app/' . $filename ;

		$copy_db_command = rsync_command( $source->get_rsync_path( $source_fs_path ), $destination->get_rsync_path( $destination_fs_path ) );

		if ( ! EE::exec( $copy_db_command ) ) {
			throw new \Exception( 'Unable to copy database to destination. Please check for file system permissions and disk space.' );
		}

		EE::log( 'Importing database in destination' );

		$import_command = 'ee shell --skip-tty ' . $destination_site_name . ' --command=\'wp db import ../' . $filename . '\'';

		if ( $destination->execute( $import_command )->return_code ) {
			throw new \Exception( 'Unable to import database on destination. Please check for file system permissions and disk space.' );
		}

		EE::log( 'Executing search-replace' );

		$search_replace_command = 'ee shell ' . $destination_site_name . ' --command=\'wp search-replace ' . $source_site_name . ' ' .$destination_site_name . ' --network --all-tables\'' ;

		if ( $destination->execute( $search_replace_command )->return_code ) {
			throw new \Exception( 'Unable to execute search-replace on database at destination.' );
		}

		if ( empty ( $source->site_details['site_ssl'] ) !== empty( $destination->site_details['site_ssl'] ) ) {
			$source_site_name_http = empty ( $source->site_details['site_ssl'] ) ? 'http://' . $destination_site_name : 'https://' . $destination_site_name;
			$destination_site_name_http = empty ( $destination->site_details['site_ssl'] ) ? 'http://' . $destination_site_name : 'https://' . $destination_site_name;

			$http_https_search_replace_command = 'ee shell ' . $destination_site_name . ' --command=\'wp search-replace ' . $source_site_name_http . ' ' . $destination_site_name_http . ' --network --all-tables\'' ;

			if ( $destination->execute( $http_https_search_replace_command )->return_code ) {
				throw new \Exception( 'Unable to execute http-https search-replace on database at destination.' );
			}
		}


		$rm_db_src_command = 'rm ' . $source_fs_path;
		$rm_db_dest_command =  'rm ' . $destination_fs_path;

		EE::log( 'Cleanup export file from source and destination' );

		$source->execute( $rm_db_src_command );
		$destination->execute( $rm_db_dest_command );
	}
}

function copy_site_certs( Site $source, Site $destination ) {
	$rsync_command = rsync_command( $source->get_rsync_path( '/opt/easyengine/services/nginx-proxy/certs/' . $source->name . '.{key,crt}' ) , $destination->get_rsync_path( '/tmp/' ) );

	if ( ! EE::exec( $rsync_command ) || $destination->execute( 'ls -1 /tmp/' . $destination->name . '.* | wc -l' )->stdout !== "2\n" ) {
		throw new \Exception( 'Unable to sync certs.' );
	}
}

function copy_site_files( Site $source, Site $destination ) {
	$rsync_command = rsync_command( $source->get_site_root_dir(), $destination->get_site_root_dir() );

	if ( ! EE::exec( $rsync_command ) ) {
		throw new \Exception( 'Unable to sync files.' );
	}
}

function rsync_command( string $source, string $destination ) {
	$ssh_command = 'ssh -i ' . get_ssh_key_path();
	return 'rsync -avzhP --delete-after --ignore-errors -e "' . $ssh_command . '" ' . $source . ' ' . $destination ;
}

function get_transfer_details( string $source, string $destination ) : array {

	$source_site = Site::from_location( $source );
	$destination_site = Site::from_location( $destination );

	if( ! $source_site->name && ! $destination_site->name ) {
		throw new \Exception( "No sitename found in source and destination site." );
	} elseif( $source_site->ssh_string && $destination_site->ssh_string ) {
		throw new \Exception( "Both source and destination sites cannot be remote." );
	} elseif( ! $source_site->name ) {
		$source_site->name = $destination_site->name;
	} elseif( ! $destination_site->name ) {
		$destination_site->name = $source_site->name;
	}

	if( 'localhost' === $source_site->host && 'localhost' === $destination_site->host && $source_site->name === $destination_site->name) {
		throw new \Exception( 'Cannot copy \'' . $source_site->name . '\' on \'' . $source_site->host . '\' to \'' . $destination_site->name . '\' on \'' . $destination_site->host . '\'' );
	}

	EE::log( 'Checking access to both sites' );

	$source_site->ensure_ssh_success();
	$source_site->validate_ee_version();

	$destination_site->ensure_ssh_success();
	$destination_site->validate_ee_version();

	$source_site->set_site_details();

	return [ $source_site, $destination_site ];
}

function get_ssh_key_path() {
	$user_home = get_user_home_dir(get_current_user());
	return $user_home . '/.ssh/id_rsa';
}

function get_user_home_dir( string $user ) {
	static $path;

	if ( $path ) {
		return $path;
	}

	$path = EE::launch( "printf ~$user" )->stdout;
	return $path;
}
