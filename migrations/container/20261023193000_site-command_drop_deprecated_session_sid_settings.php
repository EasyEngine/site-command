<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;

/**
 * Removes session.sid_length and session.sid_bits_per_character, deprecated as of PHP 8.4, from the php.ini of PHP 8.4 and 8.5 sites.
 *
 * A site's php.ini comes from its image at creation and from the version's archive on a PHP switch, so existing sites keep them otherwise.
 */
class DropDeprecatedSessionSidSettings extends Base {

	/** `latest` sites run the php8.4 image. */
	const PHP_VERSIONS = [ '8.4', '8.5', 'latest' ];

	/** Values EasyEngine shipped; any other value was set by the user. */
	const SHIPPED_VALUES = [
		'session.sid_length'             => '26',
		'session.sid_bits_per_character' => '5',
	];

	/** Comments shipped right above each directive, removed together with it. */
	const SHIPPED_COMMENTS = [
		'session.sid_length'             => [
			'; Set session ID character length. This value could be between 22 to 256.',
			'; Shorter length than default is supported only for compatibility reason.',
			'; Users should use 32 or more chars.',
			'; http://php.net/session.sid-length',
			'; Default Value: 32',
			'; Development Value: 26',
			'; Production Value: 26',
		],
		'session.sid_bits_per_character' => [
			'; Define how many bits are stored in each character when converting',
			'; the binary hash data to something readable.',
			'; Possible values:',
			';   4  (4 bits: 0-9, a-f)',
			';   5  (5 bits: 0-9, a-v)',
			';   6  (6 bits: 0-9, a-z, A-Z, "-", ",")',
			'; Default Value: 4',
			'; Development Value: 5',
			'; Production Value: 5',
			'; http://php.net/session.hash-bits-per-character',
		],
	];

	/** Entry of the quick reference at the top of the shipped php.ini. */
	const SHIPPED_QUICK_REFERENCE = [
		'; session.sid_bits_per_character',
		';   Default Value: 4',
		';   Development Value: 5',
		';   Production Value: 5',
	];

	private $sites;

	/** @var array Files changed by up(), restored by down(). */
	private $changed = [];

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping drop-deprecated-session-sid-settings migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {
			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) || ! in_array( (string) $site->php_version, self::PHP_VERSIONS, true ) ) {
				continue;
			}

			// One broken site must not block the upgrade of the others.
			try {
				$this->migrate_site( $site );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not remove the deprecated session ID settings from the php.ini of %s: %s', $site->site_url, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Restores the files changed by up().
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		foreach ( array_reverse( $this->changed ) as $change ) {
			try {
				$old = file_get_contents( $change['backup'] );
				if ( false === $old ) {
					throw new \Exception( 'Could not read ' . $change['backup'] );
				}
				$handle = self::open_ini( $change['ini'] );
				if ( ! $handle ) {
					throw new \Exception( $change['ini'] . ' is gone.' );
				}
				try {
					self::rewrite( $handle, $old, $change['ini'] );
				} finally {
					fclose( $handle );
				}
				$this->fs->remove( $change['backup'] );
				self::reload_fpm( $change['site'] );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not restore the php.ini of %s from %s: %s', $change['site']->site_url, $change['backup'], $e->getMessage() ) );
			}
		}
		$this->changed = [];
	}

	/**
	 * @param Site $site PHP 8.4 or 8.5 site.
	 *
	 * @throws \Exception
	 */
	private function migrate_site( $site ) {

		$ini = $site->site_fs_path . '/config/php/php/php.ini';

		// Missing on macOS, where php.ini stays in the container.
		$handle = self::open_ini( $ini );
		if ( ! $handle ) {
			EE::debug( "Skipping $site->site_url: no php.ini at $ini." );

			return;
		}

		try {
			$content = stream_get_contents( $handle );
			if ( false === $content ) {
				throw new \Exception( "Could not read $ini." );
			}

			list( $new_content, $removed ) = self::strip_settings( $content );
			if ( $new_content === $content ) {
				EE::debug( "$ini has no deprecated session ID settings." );

				return;
			}

			$backup = EE_BACKUP_DIR . '/' . $site->site_url . '/php.ini.with-session-sid-settings';
			$this->fs->dumpFile( $backup, $content );

			try {
				self::rewrite( $handle, $new_content, $ini );
			} catch ( \Exception $e ) {
				self::rewrite( $handle, $content, $ini );
				throw $e;
			}
		} finally {
			fclose( $handle );
		}

		$this->changed[] = [
			'site'   => $site,
			'ini'    => $ini,
			'backup' => $backup,
		];
		EE::debug( "Removed the deprecated session ID settings from $ini; the previous file is $backup." );

		foreach ( $removed as $line => $shipped ) {
			if ( ! $shipped ) {
				EE::warning( sprintf( 'Removed `%s` from the php.ini of %s: it is deprecated as of PHP 8.4, and PHP now uses its default. The previous file is saved as %s.', $line, $site->site_url, $backup ) );
			}
		}

		self::reload_fpm( $site );
	}

	/**
	 * Removes the two directives, wherever they are set, and the shipped comments that document them.
	 * Every other line is kept byte for byte.
	 *
	 * @param string $content php.ini content.
	 *
	 * @return array New content, and the removed directive lines => whether the value is the shipped one.
	 *
	 * @throws \Exception When the edit would change how PHP reads any other setting, or the file doesn't parse.
	 */
	public static function strip_settings( $content ) {

		$lines = preg_split( '/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY );
		$norm  = array_map(
			function ( $line ) {
				return rtrim( $line, " \t\r\n" );
			},
			$lines
		);
		$drop    = [];
		$removed = [];

		$quick_count = count( self::SHIPPED_QUICK_REFERENCE );
		foreach ( array_keys( $norm ) as $i ) {
			if ( array_slice( $norm, $i, $quick_count ) === self::SHIPPED_QUICK_REFERENCE ) {
				$drop += array_fill( $i, $quick_count, true );
				if ( isset( $norm[ $i + $quick_count ] ) && '' === $norm[ $i + $quick_count ] ) {
					$drop[ $i + $quick_count ] = true;
				}
			}
		}

		foreach ( $norm as $i => $line ) {
			if ( ! preg_match( '/^\s*(session\.sid_length|session\.sid_bits_per_character)\s*=(.*)$/', $line, $matches ) ) {
				continue;
			}
			$name       = $matches[1];
			$drop[ $i ] = true;

			// Inline comment and quotes don't change the value.
			$value                    = trim( preg_replace( '/;.*$/', '', $matches[2] ), " \t\"'" );
			$removed[ trim( $line ) ] = self::SHIPPED_VALUES[ $name ] === $value;

			$comments = self::SHIPPED_COMMENTS[ $name ];
			$count    = count( $comments );
			if ( $i >= $count && array_slice( $norm, $i - $count, $count ) === $comments ) {
				$drop += array_fill( $i - $count, $count, true );
				if ( isset( $norm[ $i + 1 ] ) && '' === $norm[ $i + 1 ] ) {
					$drop[ $i + 1 ] = true;
				}
			}
		}

		$new_content = implode( '', array_diff_key( $lines, $drop ) );

		// A quoted value can span lines, so PHP must read the result as the original minus the two directives.
		if ( $new_content !== $content ) {
			$expected = @parse_ini_string( $content, true, INI_SCANNER_NORMAL );
			if ( is_array( $expected ) ) {
				foreach ( $expected as $key => $value ) {
					if ( is_array( $value ) ) {
						$expected[ $key ] = array_diff_key( $value, self::SHIPPED_VALUES );
					}
				}
				$expected = array_diff_key( $expected, self::SHIPPED_VALUES );
			}
			if ( ! is_array( $expected ) || @parse_ini_string( $new_content, true, INI_SCANNER_NORMAL ) !== $expected ) {
				throw new \Exception( 'the file does not parse, or removing them line by line would change other settings (a quoted value spanning lines), so it was left as it is; remove session.sid_length and session.sid_bits_per_character by hand.' );
			}
		}

		return [ $new_content, $removed ];
	}

	/**
	 * Opens php.ini for rewriting in place, so no other path is created, followed or re-resolved.
	 * The directory is mounted into the site's php container, so nothing in it is trusted.
	 *
	 * @param string $file php.ini path.
	 *
	 * @return resource|null Null when there is no regular file.
	 * @throws \Exception
	 */
	private static function open_ini( $file ) {

		if ( is_link( $file ) || ! is_file( $file ) ) {
			return null;
		}

		$handle = fopen( $file, 'r+' );
		if ( false === $handle ) {
			throw new \Exception( "Could not open $file." );
		}

		// Swapped for a symlink after the check, or a hard link to another file.
		$opened = fstat( $handle );
		clearstatcache( true, $file );
		$linked = lstat( $file );
		if ( ! $opened || ! $linked || $opened['dev'] !== $linked['dev'] || $opened['ino'] !== $linked['ino'] || 0100000 !== ( $linked['mode'] & 0170000 ) || 1 !== $opened['nlink'] ) {
			fclose( $handle );
			throw new \Exception( "$file is not a regular file." );
		}

		return $handle;
	}

	/**
	 * The removal only shortens the file, so writing over it needs no new space; mode and owner stay.
	 *
	 * @param resource $handle  Handle from open_ini().
	 * @param string   $content New content.
	 * @param string   $file    php.ini path, for the error.
	 *
	 * @throws \Exception
	 */
	private static function rewrite( $handle, $content, $file ) {

		if ( ! rewind( $handle ) || strlen( $content ) !== fwrite( $handle, $content ) || ! fflush( $handle ) || ! ftruncate( $handle, strlen( $content ) ) ) {
			throw new \Exception( "Could not write $file." );
		}
	}

	/**
	 * PHP-FPM reads php.ini only when it starts; a graceful reload (as `ee site reload --php` does) applies it.
	 * Stopped containers read it when they start.
	 *
	 * @param Site $site Site.
	 */
	private static function reload_fpm( $site ) {

		if ( ! $site->site_enabled || ! chdir( $site->site_fs_path ) ) {
			return;
		}

		$compose = \EE_DOCKER::docker_compose_with_custom();
		$id      = trim( EE::launch( "$compose ps -q php" )->stdout );
		if ( '' === $id || 'running' !== \EE_DOCKER::container_status( $id ) ) {
			EE::debug( "The php container of $site->site_url is not running." );

			return;
		}

		if ( EE::launch( "$compose exec -T php bash -c 'kill -USR2 1'" )->return_code ) {
			EE::warning( sprintf( 'Could not reload PHP-FPM of %1$s. Run `ee site restart %1$s --php` to apply its new php.ini.', $site->site_url ) );
		}
	}
}
