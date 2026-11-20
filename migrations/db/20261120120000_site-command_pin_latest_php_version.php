<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;

/**
 * Pins sites stored as `latest` to the PHP version they run.
 *
 * `latest` now maps to a newer image, and a regenerated docker-compose.yml would move these sites onto it with php config made for the old version.
 */
class PinLatestPhpVersion extends Base {

	/** What `latest` mapped to before this release (4.13.x–4.14.x). */
	const FALLBACK_VERSION = '8.4';

	/** Versions with an easyengine/php<version> image. */
	const KNOWN_VERSIONS = [ '5.6', '7.0', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6' ];

	/** The unversioned easyengine/php image, `latest` before 2024, is PHP 7.4. */
	const UNVERSIONED_IMAGE_VERSION = '7.4';

	/** Largest docker-compose.yml read. */
	const MAX_SIZE = 1048576;

	private $sites;

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Rewrite every `latest` php_version to the version in the site's docker-compose.yml.
	 *
	 * @throws \Exception When a row can't be saved.
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping pin-latest-php-version migration as it is not needed.' );

			return;
		}
		foreach ( $this->sites as $site ) {

			if ( 'latest' !== $site->php_version ) {
				continue;
			}
			$version = $this->running_version( $site );
			if ( null === $version ) {
				$version = self::FALLBACK_VERSION;
				EE::debug( "Could not read the PHP image of $site->site_url from its docker-compose.yml; using PHP $version." );
			}
			$site->php_version = $version;
			$error = '';
			try {
				$saved = $site->save();
			} catch ( \Throwable $e ) {
				$saved = false;
				$error = ': ' . $e->getMessage();
			}
			// Fail so the migration isn't recorded and runs again; rows already pinned are skipped then.
			if ( ! $saved ) {
				throw new \Exception( "Could not set the PHP version of $site->site_url to $version$error" );
			}
			EE::log( "Set the PHP version of $site->site_url from latest to $version, the version it runs." );
		}
	}

	/**
	 * The PHP version of the php image in the site's docker-compose.yml.
	 *
	 * @param Site $site
	 *
	 * @return string|null Null when the file is missing or unreadable, or names no single known version.
	 */
	private function running_version( $site ) {

		if ( empty( $site->site_fs_path ) ) {
			return null;
		}
		$file = rtrim( $site->site_fs_path, '/' ) . '/docker-compose.yml';
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$content = @file_get_contents( $file, false, null, 0, self::MAX_SIZE );
		// The image name after "easyengine/php", up to its tag or digest.
		if ( false === $content || ! preg_match_all( '#^\s*image:\s*["\']?easyengine/php([^\s"\'@:]*)#m', $content, $matches ) ) {
			return null;
		}
		$versions = [];
		foreach ( $matches[1] as $suffix ) {
			if ( '' === $suffix ) {
				$versions[] = self::UNVERSIONED_IMAGE_VERSION;
			} elseif ( preg_match( '#^\d+\.\d+$#D', $suffix ) ) {
				$versions[] = $suffix;
			} else {
				// Another image (e.g. php8.3-custom): the site isn't on a plain EasyEngine php image.
				return null;
			}
		}
		$versions = array_unique( $versions );
		if ( 1 !== count( $versions ) ) {
			return null;
		}
		$version = reset( $versions );

		return in_array( $version, self::KNOWN_VERSIONS, true ) ? $version : null;
	}

	/**
	 * No changes in case of down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}
