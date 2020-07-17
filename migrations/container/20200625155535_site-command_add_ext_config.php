<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class AddExtConfig extends Base {

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
			EE::debug( 'Skipping add-ext-config update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			if ( in_array( $site->php_version, [ '5.6', '7.0' ] ) ) {
				continue;
			}

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting add-ext-config updates for: $site->site_url" );

			$intl_config_file = EE_BACKUP_DIR . '/docker-php-ext-intl.ini';
			$this->fs->dumpFile( $intl_config_file, 'extension=intl.so' );

			$pdo_mysql_config_file = EE_BACKUP_DIR . '/docker-php-ext-pdo_mysql.ini';
			$this->fs->dumpFile( $pdo_mysql_config_file, 'extension=pdo_mysql.so' );

			$bcmath_config_file = EE_BACKUP_DIR . '/docker-php-ext-bcmath.ini';
			$this->fs->dumpFile( $bcmath_config_file, 'extension=bcmath.so' );

			$bcmath_config_site_path    = $site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-bcmath.ini';
			$intl_config_site_path      = $site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-intl.ini';
			$pdo_mysql_config_site_path = $site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-pdo_mysql.ini';

			self::$rsp->add_step(
				"to-$site->site_url-add-intl-config",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $intl_config_file, $intl_config_site_path ],
				null
			);

			self::$rsp->add_step(
				"to-$site->site_url-add-pdo_mysql-config",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $pdo_mysql_config_file, $pdo_mysql_config_site_path ],
				null
			);

			self::$rsp->add_step(
				"to-$site->site_url-add-bcmath-config",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $bcmath_config_file, $bcmath_config_site_path ],
				null
			);

		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run add-ext-config upadte migrations.' );
		}

		$configs = [ $intl_config_file, $pdo_mysql_config_file, $bcmath_config_file ];

		foreach ( $configs as $config ) {

			if ( $this->fs->exists( $config ) ) {
				$this->fs->remove( $config );
			}
		}
	}

	/**
	 * Bring back the existing old php config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping add-ext-config update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Reverting add-ext-config updates for: $site->site_url" );

			$intl_config_site_path      = $site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-intl.ini';
			$pdo_mysql_config_site_path = $site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-pdo_mysql.ini';
			$bcmath_config_site_path    = $site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-bcmath.ini';

			$configs = [ $intl_config_site_path, $pdo_mysql_config_site_path, $bcmath_config_site_path ];

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			if ( in_array( $site->php_version, [ '5.6', '7.0' ] ) ) {
				continue;
			}

			foreach ( $configs as $config ) {

				if ( $this->fs->exists( $config ) ) {
					$this->fs->remove( $config );
				}
			}
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to revert php add-ext-config migrations.' );
		}
	}
}
