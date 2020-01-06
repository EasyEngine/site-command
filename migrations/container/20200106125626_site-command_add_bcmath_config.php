<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class AddBcmathConfig extends Base {

	private $sites;
	/** @var RevertableStepProcessor $rsp Keeps track of migration state. Reverts on error */
	private static $rsp;

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Execute php config updates.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping add-bcmath-config update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			if ( '5.6' === $site->php_version ) {
				continue;
			}

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting add-bcmath-config updates for: $site->site_url" );

			// Create bcmath config file.
			$bcmath_config_file = EE_BACKUP_DIR . '/docker-php-ext-bcmath.ini';
			$this->fs->dumpFile( $bcmath_config_file, 'extension=bcmath.so' );

			$bcmath_config_site_path    = $site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-bcmath.ini';

			self::$rsp->add_step(
				"to-$site->site_url-add-bcmath-config",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $bcmath_config_file, $bcmath_config_site_path ],
				null
			);
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run add-bcmath-config upadte migrations.' );
		}

	}

	/**
	 * Bring back the existing old php config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping add-bcmath-config update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Reverting add-bcmath-config updates for: $site->site_url" );

			$bcmath_config_site_path    = $site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-bcmath.ini';

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			if ( '5.6' === $site->php_version ) {
				continue;
			}

			if ( ! $this->fs->exists( $bcmath_config_site_path ) ) {
				continue;
			} else {
				$this->fs->remove( $bcmath_config_site_path );
			}
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to revert php add-bcmath-config migrations.' );
		}
	}
}
