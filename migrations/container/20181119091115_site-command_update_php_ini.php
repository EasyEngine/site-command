<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class UpdatePhpIni extends Base {

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
			EE::debug( 'Skipping update-php-ini migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Starting php-ini path update for: $site->site_url" );
			EE::exec( 'sed -i "s/^#\(.*\)/;\1/g" ' . $old_php_ini );

			$old_php_ini           = $site->site_fs_path . '/config/php/php/php.ini';
			$new_php_ini           = $site->site_fs_path . '/config/php/php/conf.d/custom.ini';
			$old_production_ini    = $site->site_fs_path . '/config/php/php/php.ini-production';
			$new_production_ini    = $site->site_fs_path . '/config/php/php/php.ini';
			$backup_prefix         = EE_BACKUP_DIR . '/' . $site->site_url . '/php';
			$old_ini_backup        = $backup_prefix . '/php.ini';
			$old_production_backup = $backup_prefix . '/php.ini-production';

			self::$rsp->add_step(
				"take-$site->site_url-php-ini-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_php_ini, $old_ini_backup ],
				[ $old_ini_backup, $old_php_ini ]
			);

			self::$rsp->add_step(
				"take-$site->site_url-php-production-ini-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_production_ini, $old_production_backup ],
				[ $old_production_backup, $old_production_ini ]
			);

			self::$rsp->add_step(
				"move-$site->site_url-php-ini-to-custom-ini",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::delete',
				[ $old_ini_backup, $new_php_ini ],
				[ $new_php_ini ]
			);

			self::$rsp->add_step(
				"move-$site->site_url-php-production-ini-to-php-ini",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_production_backup, $new_production_ini ],
				[ $old_ini_backup, $new_production_ini ]
			);

			// Not restarting the container as it will happen in the container migration while updating the php image.
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable run update-php-ini migrations.' );
		}
	}

	/**
	 * Bring back the existing old config and path.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping update-php-ini migration down as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Reverting update-php-ini changes for: $site->site_url" );

			EE::debug( "Starting php-ini path update for: $site->site_url" );

			$old_php_ini    = $site->site_fs_path . '/config/php/php/php.ini';
			$new_php_ini    = $site->site_fs_path . '/config/php/php/conf.d/custom.ini';
			$backup_prefix  = EE_BACKUP_DIR . '/' . $site->site_url . '/php';
			$old_ini_backup = $backup_prefix . '/php.ini';

			self::$rsp->add_step(
				"restore-$site->site_url-php-ini-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_ini_backup, $old_php_ini ],
				[ $old_php_ini, $old_ini_backup ]
			);

			self::$rsp->add_step(
				"delete-$site->site_url-custom-ini",
				'EE\Migration\SiteContainers::delete',
				null,
				[ $new_php_ini ],
				null
			);
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to revert update-php-ini migrations.' );
		}
	}
}
