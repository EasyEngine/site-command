<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use function EE\Site\Utils\add_session_upload_progress_off;
use function EE\Site\Utils\ini_global_value;
use function EE\Site\Utils\ini_sets_globally;
use function EE\Site\Utils\open_container_file;
use function EE\Site\Utils\read_container_file;
use function EE\Site\Utils\rewrite_container_file;
use function EE\Site\Utils\site_php_conf_dir;

/**
 * Checks the custom.ini of every WordPress and PHP site again and turns off session.upload_progress where it is still not set globally.
 *
 * The v4.13.3 migrations took a setting inside a [PATH=]/[HOST=] section for a global one when the section header was quoted, shared its line with another header, was named like an earlier key, or ended with CRLF or CR, and they counted an array entry keyed by the directive too, so those sites kept PHP's default On.
 *
 * A global On or Off is the user's choice and is kept. Disabled sites pick the change up on their next start.
 */
class RecheckSessionUploadProgress extends Base {

	const SITE_TYPES = [ 'wp', 'php' ];

	const DIRECTIVE = 'session.upload_progress.enabled';

	/** Larger files are skipped with a warning: the check re-parses the text before every '[', and the file is the container's to fill. */
	const MAX_SIZE = 65536;

	/** @var Site[] */
	private $sites = [];

	/** @var array site_url => [ 'site' => Site, 'file' => string, 'dir' => string, 'backup' => string, 'written' => bool ] for every custom.ini changed by up(). */
	private $changed = [];

	/** @var array site_url => php container ID seen by up(), for running sites. */
	private $containers = [];

	/** @var string|null */
	private $backup_root;

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;

			return;
		}

		$this->sites = array_filter(
			Site::all() ?: [],
			function ( $site ) {
				return in_array( $site->site_type, self::SITE_TYPES, true );
			}
		);
		if ( empty( $this->sites ) ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Add the setting to every custom.ini that doesn't set it globally.
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping recheck-session-upload-progress migration as it is not needed.' );

			return;
		}

		$this->backup_root = EE_BACKUP_DIR . '/session-upload-progress-recheck-' . date( 'Ymd-His' );
		if ( file_exists( $this->backup_root ) ) {
			$this->backup_root .= '-' . getmypid();
		}

		foreach ( $this->sites as $site ) {
			// One broken site must not block the upgrade of the others.
			try {
				$this->update_site( $site );
			} catch ( \Throwable $e ) {
				if ( isset( $this->changed[ $site->site_url ] ) ) {
					$this->restore_file( $site->site_url );
				}
				unset( $this->containers[ $site->site_url ] );
				EE::warning( "Could not check session.upload_progress in the custom.ini of $site->site_url: {$e->getMessage()}. " . self::manual_fix( $site ) );
			}
		}

		if ( empty( $this->changed ) ) {
			$this->remove_empty_backup_root();

			return;
		}

		$written = array_filter( array_column( $this->changed, 'written' ) );
		if ( ! $written ) {
			return;
		}
		EE::log( 'Disabled session.upload_progress in custom.ini of ' . count( $written ) . ' more site(s) where it was not set globally. The original files are in ' . $this->backup_root );

		// The image migration may recreate php containers, which then read the new file anyway; reload only the others, once it is done.
		if ( method_exists( '\EE\Migration\CustomContainerMigrations', 'keep_executed_migrations' ) ) {
			EE::add_hook( 'after_docker_image_migration', [ $this, 'reload_unchanged_containers' ] );
		} else {
			$this->reload_unchanged_containers();
		}
	}

	/**
	 * Restore the original custom.ini files and reload the running sites.
	 */
	public function down() {

		if ( empty( $this->changed ) ) {
			return;
		}

		$sites = array_column( $this->changed, 'site' );
		$this->restore_files();

		// A rolled-back image migration may have restarted them on the new file.
		foreach ( $sites as $site ) {
			if ( $site->site_enabled ) {
				$id = $this->get_php_container( $site );
				if ( null !== $id && '' !== $id ) {
					$this->reload_php( $site->site_url, $id );
				}
			}
		}
	}

	/**
	 * Gracefully reload php-fpm of the sites whose php container is still the one up() saw.
	 */
	public function reload_unchanged_containers() {

		foreach ( $this->containers as $site_url => $id ) {
			if ( ! isset( $this->changed[ $site_url ] ) ) {
				continue;
			}
			$now = $this->get_php_container( $this->changed[ $site_url ]['site'] );
			if ( $now !== $id ) {
				if ( null !== $now ) {
					EE::debug( "$site_url: php container was recreated or stopped, no reload needed." );
				}
				continue;
			}
			$this->reload_php( $site_url, $id );
		}
		$this->containers = [];
	}

	/**
	 * Whether PHP reads an ini value as true, like zend_ini_parse_bool(), which falls back to atoi().
	 *
	 * @param string $value Value as the ini scanner gives it.
	 *
	 * @return bool
	 */
	public static function is_on( string $value ) {

		return in_array( strtolower( $value ), [ 'true', 'yes', 'on' ], true ) || 1 === preg_match( '/^\s*[+-]?0*[1-9]/', $value );
	}

	/**
	 * @param Site $site Site.
	 *
	 * @return string The manual fix, for warnings.
	 */
	private static function manual_fix( $site ) {

		return "Add 'session.upload_progress.enabled = Off' to the top of $site->site_fs_path/config/php/php/conf.d/custom.ini and run: ee site reload $site->site_url";
	}

	/**
	 * Add the setting to one site's custom.ini. Problems with one site are warnings, so they don't block the upgrade.
	 *
	 * @param Site $site Site to check.
	 *
	 * @throws \Exception
	 */
	private function update_site( $site ) {

		$file   = $site->site_fs_path . '/config/php/php/conf.d/custom.ini';
		$manual = self::manual_fix( $site );

		// Not created when missing: PHP can't create a file safely in a directory the site's container can swap. The v4.13.3 migration created it wherever conf.d existed.
		clearstatcache( true );
		if ( is_link( $file ) || ! is_file( $file ) ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: custom.ini is missing or not a regular file. $manual" );

			return;
		}

		// Read-only first: a file that needs no change may be read-only or hard-linked.
		$dir    = site_php_conf_dir( $site->site_fs_path );
		$handle = open_container_file( $file, 'r', $dir );
		try {
			$content = read_container_file( $handle, self::MAX_SIZE );
		} finally {
			fclose( $handle );
		}

		$new_content = add_session_upload_progress_off( $content );
		if ( null === $new_content ) {
			$value = ini_global_value( preg_replace( '/^\xEF\xBB\xBF/', '', $content ), self::DIRECTIVE );
			if ( null !== $value && self::is_on( $value ) ) {
				EE::log( "Kept session.upload_progress On for $site->site_url: its custom.ini turns it on. Set it to Off there unless your app needs it." );
			} else {
				EE::debug( "$site->site_url: custom.ini already turns off session.upload_progress." );
			}

			return;
		}

		if ( ! ini_sets_globally( preg_replace( '/^\xEF\xBB\xBF/', '', $new_content ), self::DIRECTIVE ) ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: could not add it to custom.ini safely. $manual" );

			return;
		}

		$handle = open_container_file( $file, 'r+', $dir );
		try {
			if ( read_container_file( $handle, self::MAX_SIZE ) !== $content ) {
				throw new \Exception( 'custom.ini changed while it was checked' );
			}
			$written = $this->write_ini( $site, $handle, $file, $dir, $content, $new_content, $manual );
		} finally {
			fclose( $handle );
		}

		if ( ! $written ) {
			return;
		}

		$id = $site->site_enabled ? $this->get_php_container( $site ) : '';
		if ( null !== $id && '' !== $id ) {
			$this->containers[ $site->site_url ] = $id;
		}
		EE::debug( "$site->site_url: disabled session.upload_progress in custom.ini." );
	}

	/**
	 * Back up and rewrite custom.ini through the handle from open_container_file().
	 *
	 * @param Site     $site        Site.
	 * @param resource $handle      custom.ini handle.
	 * @param string   $file        custom.ini path.
	 * @param string   $dir         Real path of conf.d.
	 * @param string   $content     Current content.
	 * @param string   $new_content New content.
	 * @param string   $manual      Manual fix, for warnings.
	 *
	 * @return bool Whether the file now holds the new content.
	 */
	private function write_ini( $site, $handle, string $file, string $dir, string $content, string $new_content, string $manual ) {

		$stat   = fstat( $handle );
		$backup = $this->backup_root . '/' . $site->site_url . '/custom.ini';
		try {
			$this->fs->mkdir( dirname( $backup ), 0700 );
			$this->fs->dumpFile( $backup, $content );
			$this->fs->chmod( $backup, $stat['mode'] & 0777 );
			$this->fs->touch( $backup, $stat['mtime'] );
		} catch ( \Throwable $e ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: could not back up custom.ini ({$e->getMessage()}). $manual" );

			return false;
		}

		$entry = [
			'site'    => $site,
			'file'    => $file,
			'dir'     => $dir,
			'backup'  => $backup,
			'written' => true,
		];

		try {
			rewrite_container_file( $handle, $new_content );
		} catch ( \Exception $e ) {
			EE::warning( "Could not disable session.upload_progress for $site->site_url: could not write custom.ini. $manual" );
			try {
				rewrite_container_file( $handle, $content );
			} catch ( \Exception $e2 ) {
				// Left for down() to retry from the backup.
				$entry['written']                 = false;
				$this->changed[ $site->site_url ] = $entry;
				EE::warning( "Could not restore $file; its original is $backup" );

				return false;
			}
			$this->remove_backup( $backup );

			return false;
		}

		$this->changed[ $site->site_url ] = $entry;

		return true;
	}

	/**
	 * Restore every file changed by up().
	 */
	private function restore_files() {

		foreach ( array_keys( $this->changed ) as $site_url ) {
			$this->restore_file( $site_url );
		}
		$this->containers = [];
		$this->remove_empty_backup_root();
	}

	/**
	 * Restore one file changed by up(); its backup is removed once restored. Never throws.
	 *
	 * @param string $site_url Site.
	 */
	private function restore_file( string $site_url ) {

		$entry = $this->changed[ $site_url ];
		unset( $this->changed[ $site_url ] );

		try {
			$content = @file_get_contents( $entry['backup'] );
			if ( false === $content ) {
				throw new \Exception( 'could not read the backup' );
			}
			$handle = open_container_file( $entry['file'], 'r+', $entry['dir'] );
			try {
				// Skip the write when it never happened; read no more than needed to tell.
				if ( stream_get_contents( $handle, strlen( $content ) + 1 ) !== $content ) {
					rewrite_container_file( $handle, $content );
				}
			} finally {
				fclose( $handle );
			}
		} catch ( \Throwable $e ) {
			EE::warning( "Could not restore {$entry['file']} ({$e->getMessage()}). Its original is {$entry['backup']}" );

			return;
		}
		$this->remove_backup( $entry['backup'] );
		EE::debug( "$site_url: restored custom.ini." );
	}

	/**
	 * @param string|null $backup Backup file; its directory is removed.
	 */
	private function remove_backup( $backup ) {

		if ( null === $backup ) {
			return;
		}
		try {
			$this->fs->remove( dirname( $backup ) );
		} catch ( \Throwable $e ) {
			EE::debug( "Could not remove $backup: {$e->getMessage()}" );
		}
	}

	private function remove_empty_backup_root() {

		try {
			if ( ! empty( $this->backup_root ) && is_dir( $this->backup_root ) && ! ( new \FilesystemIterator( $this->backup_root ) )->valid() ) {
				$this->fs->remove( $this->backup_root );
			}
		} catch ( \Throwable $e ) {
			EE::debug( "Could not remove the empty $this->backup_root: {$e->getMessage()}" );
		}
	}

	/**
	 * @param Site $site Site.
	 *
	 * @return string|null ID of the site's running php container, '' when none runs, null when the lookup failed (already warned).
	 */
	private function get_php_container( $site ) {

		$cwd    = getcwd();
		$result = @chdir( $site->site_fs_path ) ? EE::launch( \EE_DOCKER::docker_compose_with_custom() . ' ps -q php' ) : null;
		if ( false !== $cwd ) {
			chdir( $cwd );
		}

		if ( null === $result || 0 !== $result->return_code ) {
			EE::warning( "Could not check the php container of $site->site_url, so php-fpm may still run with the old session.upload_progress setting. Fix the error, then run: ee site reload $site->site_url" );

			return null;
		}

		return trim( $result->stdout );
	}

	/**
	 * Same graceful reload as `ee site reload`: php-fpm re-reads its ini files.
	 *
	 * @param string $site_url Site.
	 * @param string $id       php container ID.
	 */
	private function reload_php( string $site_url, string $id ) {

		$result = EE::launch( 'docker exec --user root ' . escapeshellarg( $id ) . " sh -c 'kill -USR2 1'" );
		if ( 0 === $result->return_code ) {
			EE::debug( "$site_url: reloaded php-fpm." );
		} else {
			EE::warning( "Could not reload php-fpm of $site_url. Run: ee site reload $site_url" );
		}
	}
}
