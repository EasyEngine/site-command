<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class UpdateSendmailPathForMsmtp extends Base {

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
			EE::debug( 'Skipping update-sendmail-path update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting update-sendmail-path updates for: $site->site_url" );

			$old_custom_ini    = $site->site_fs_path . '/config/php/php/conf.d/custom.ini';
			$custom_ini_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/custom.ini.backup';

			$this->fs->mkdir( dirname( $custom_ini_backup ) );

			self::$rsp->add_step(
				"take-$site->site_url-update-sendmail-path-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_custom_ini, $custom_ini_backup ],
				[ $custom_ini_backup, $old_custom_ini ]
			);

			self::$rsp->add_step(
				"update-$site->site_url-custom-ini",
				'EE\Migration\UpdateSendmailPathForMsmtp::replace_path',
				null,
				[ $old_custom_ini ],
				null
			);
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run update-sendmail-path upadte migrations.' );
		}

	}

	/**
	 * Bring back the existing old php config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping update-sendmail-path update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Reverting update-sendmail-path updates for: $site->site_url" );

			$old_custom_ini    = $site->site_fs_path . '/config/php/php/conf.d/custom.ini';
			$custom_ini_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/php.ini.backup';

			if ( ! $this->fs->exists( $custom_ini_backup ) ) {
				continue;
			}

			self::$rsp->add_step(
				"revert-to-$site->site_url-update-sendmail-path-backup",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $custom_ini_backup, $old_custom_ini ],
				null
			);
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to revert php config-update migrations.' );
		}
	}

	public static function replace_path( $custom_ini_path ) {

		$custom_ini_data = file( $custom_ini_path );
		$custom_ini_data = array_map( function ( $custom_ini_data ) {
			return stristr( $custom_ini_data, 'sendmail_path' ) ? "sendmail_path = /usr/bin/msmtp -t\n" : $custom_ini_data;
		}, $custom_ini_data );
		file_put_contents( $custom_ini_path, implode( '', $custom_ini_data ) );
	}
}
