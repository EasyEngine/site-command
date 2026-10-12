<?php

namespace EE\Migration;

use EE;

/**
 * Removes the site credentials that older backups wrote to the local copies of their metadata.
 *
 * Remote copies are not changed; restore no longer reads credentials from metadata, so old backups still restore.
 */
class StripBackupMetadataCredentials extends Base {

	const SECRET_KEYS = [ 'db_password', 'db_root_password', 'app_admin_password' ];

	public function up() {

		if ( ! is_dir( EE_BACKUP_DIR ) ) {
			return;
		}

		$files = array_merge(
			glob( EE_BACKUP_DIR . '/*.metadata.json' ) ?: [],
			glob( EE_BACKUP_DIR . '/*/metadata.json' ) ?: []
		);

		foreach ( $files as $file ) {
			if ( is_link( $file ) || ! is_file( $file ) ) {
				continue;
			}

			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $data ) || ! array_intersect_key( $data, array_flip( self::SECRET_KEYS ) ) ) {
				continue;
			}

			foreach ( self::SECRET_KEYS as $key ) {
				unset( $data[ $key ] );
			}

			// A half-written file would make restore refuse the backup, so it is replaced only when complete.
			$tmp = $file . '.ee-tmp';
			if ( false === file_put_contents( $tmp, json_encode( $data, JSON_PRETTY_PRINT ) ) || ! chmod( $tmp, fileperms( $file ) & 0777 ) || ! rename( $tmp, $file ) ) {
				@unlink( $tmp );
				EE::warning( "Could not remove the credentials from $file." );
				continue;
			}
			EE::debug( "Removed the credentials from $file." );
		}
	}

	/**
	 * Removed credentials are not restored.
	 */
	public function down() {
	}
}
