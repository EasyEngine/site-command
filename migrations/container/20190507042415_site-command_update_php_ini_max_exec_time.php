<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class UpdatePhpIniMaxExecTime extends Base {

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
			EE::debug( 'Skipping php-ini-max-exec-time update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting php-ini-max-exec-time updates for: $site->site_url" );

			$old_php_ini    = $site->site_fs_path . '/config/php/php/php.ini';
			$php_ini_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/php.ini.backup';
			$new_php_ini    = EE_BACKUP_DIR . '/' . $site->site_url . '/php.ini';

			$this->fs->mkdir( dirname( $new_php_ini ) );
			$download_url = 'https://raw.githubusercontent.com/EasyEngine/dockerfiles/04114d03fec1485cf23deda0f910751ebdc76dbc/php/php.ini';
			$headers      = [];
			$options      = [
				'timeout'  => 600,  // 10 minutes ought to be enough for everybody.
				'filename' => $new_php_ini,
			];
			\EE\Utils\http_request( 'GET', $download_url, null, $headers, $options );

			self::$rsp->add_step(
				"take-$site->site_url-php-ini-max-exec-time-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_php_ini, $php_ini_backup ],
				[ $php_ini_backup, $old_php_ini ]
			);

			self::$rsp->add_step(
				"replace-$site->site_url-with-new-php-ini-max-exec-time",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $new_php_ini, $old_php_ini ],
				[ $old_php_ini, $new_php_ini ]
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"reload-$site->site_url-php-containers",
					'EE\Migration\UpdatePhpIniMaxExecTime::reload_php',
					null,
					[ $site->site_fs_path ],
					null
				);
			}
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run php-ini-max-exec-time upadte migrations.' );
		}

	}

	/**
	 * Bring back the existing old php config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping php-ini-max-exec-time update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Reverting php-ini-max-exec-time updates for: $site->site_url" );

			$old_php_ini    = $site->site_fs_path . '/config/php/php/php.ini';
			$php_ini_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/php.ini.backup';
			$new_php_ini    = EE_BACKUP_DIR . '/' . $site->site_url . '/php.ini';

			if ( ! $this->fs->exists( $new_php_ini ) ) {
				continue;
			}

			self::$rsp->add_step(
				"revert-to-$site->site_url-php-ini-max-exec-time-backup",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $php_ini_backup, $old_php_ini ],
				null
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"reload-$site->site_url-php-containers",
					'EE\Migration\UpdatePhpIniMaxExecTime::reload_php',
					null,
					[ $site->site_fs_path ],
					null
				);
			}
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to revert php config-update migrations.' );
		}
	}
	public static function reload_php( $site_fs_path ) {
		chdir( $site_fs_path );
		EE::exec( "docker-compose restart php" );
	}
}
