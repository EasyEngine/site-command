<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use function EE\Site\Utils\get_container_file_contents;
use function EE\Site\Utils\put_container_file_contents;
use function EE\Site\Utils\site_php_conf_dir;
use function EE\Site\Utils\strip_session_sid_settings;

/**
 * Removes session.sid_length and session.sid_bits_per_character, deprecated as of PHP 8.4, from the php.ini of PHP 8.4 and 8.5 sites.
 *
 * A site's php.ini comes from its image at creation and from the version's archive on a PHP switch, so existing sites keep them otherwise.
 */
class DropDeprecatedSessionSidSettings extends Base {

	/** Largest php.ini read; the shipped one is about 70 KB. */
	const MAX_SIZE = 1048576;

	/** `latest` sites run the php8.4 image. */
	const PHP_VERSIONS = [ '8.4', '8.5', 'latest' ];

	private $sites;

	/** @var array Files changed by up(), restored by down(). */
	private $changed = [];

	/** @var string|null Where up() saves the previous php.ini of each site it changes. */
	private $backup_root;

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

		// Not EE_BACKUP_DIR/<site>/: `ee site backup` and `ee site restore` empty that dir. Site names always contain a dot, so this one is never theirs.
		$this->backup_root = EE_BACKUP_DIR . '/session-sid-settings-' . date( 'Ymd-His' );
		if ( file_exists( $this->backup_root ) ) {
			$this->backup_root .= '-' . getmypid();
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
				put_container_file_contents( $change['ini'], $change['dir'], $old, null, self::MAX_SIZE );
				$this->remove_backup( $change['backup'] );
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
		if ( ! file_exists( $ini ) && ! is_link( $ini ) ) {
			EE::debug( "Skipping $site->site_url: no php.ini at $ini." );

			return;
		}

		// The site's php container can swap anything in its config volume, so php.ini is read and rewritten in place through a checked handle.
		$dir     = site_php_conf_dir( $site->site_fs_path, 'php' );
		$content = get_container_file_contents( $ini, $dir, self::MAX_SIZE );

		list( $new_content, $removed ) = strip_session_sid_settings( $content );
		if ( $new_content === $content ) {
			EE::debug( "$ini has no deprecated session ID settings." );

			return;
		}

		$backup = $this->backup_root . '/' . $site->site_url . '/php.ini';
		$this->fs->mkdir( dirname( $backup ), 0700 );
		$this->fs->dumpFile( $backup, $content );

		try {
			put_container_file_contents( $ini, $dir, $new_content, $content, self::MAX_SIZE );
		} catch ( \Exception $e ) {
			// A failed write may have left php.ini partly written; any other failure left it as it was.
			if ( 0 === strpos( $e->getMessage(), 'could not write' ) ) {
				throw new \Exception( $e->getMessage() . "; the previous file is $backup" );
			}
			$this->remove_backup( $backup );
			throw $e;
		}

		$this->changed[] = [
			'site'   => $site,
			'ini'    => $ini,
			'dir'    => $dir,
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
	 * @param string $backup Saved php.ini; its site dir and the backup root go too once empty.
	 */
	private function remove_backup( $backup ) {

		$this->fs->remove( $backup );
		// rmdir() refuses a dir that still holds other sites' files.
		@rmdir( dirname( $backup ) );
		@rmdir( dirname( $backup, 2 ) );
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
