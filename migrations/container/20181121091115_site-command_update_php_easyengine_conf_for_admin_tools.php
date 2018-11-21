<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class UpdatePhpEasyengineConfForAdminTools extends Base {

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
	 * Execute easyengine_conf config updates.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping php-easyengine-conf update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting php-easyengine-conf updates for: $site->site_url" );

			$old_php_easyengine_conf    = $site->site_fs_path . '/config/php/php-fpm.d/easyengine.conf';
			$php_easyengine_conf_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/php/easyengine.conf.backup';
			$new_php_easyengine_conf    = EE_BACKUP_DIR . '/' . $site->site_url . '/php/easyengine.conf';

			$this->fs->mkdir( dirname( $new_php_easyengine_conf ) );
			$download_url = 'https://raw.githubusercontent.com/EasyEngine/dockerfiles/059116031df049855df07d0e2050882a9c1cfa92/php/easyengine.conf';
			$headers      = array();
			$options      = array(
				'timeout'  => 600,  // 10 minutes ought to be enough for everybody.
				'filename' => $new_php_easyengine_conf,
			);
			\EE\Utils\http_request( 'GET', $download_url, null, $headers, $options );

			self::$rsp->add_step(
				"take-$site->site_url-php-easyengine-conf-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_php_easyengine_conf, $php_easyengine_conf_backup ],
				[ $php_easyengine_conf_backup, $old_php_easyengine_conf ]
			);

			self::$rsp->add_step(
				"replace-$site->site_url-with-new-php-easyengine-conf",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $new_php_easyengine_conf, $old_php_easyengine_conf ],
				[ $old_php_easyengine_conf, $new_php_easyengine_conf ]
			);
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run php-easyengine-conf upadte migrations.' );
		}

	}

	/**
	 * Bring back the existing old easyengine_conf config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping php-easyengine-conf update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Reverting php-easyengine-conf updates for: $site->site_url" );

			$old_php_easyengine_conf    = $site->site_fs_path . '/config/php/php-fpm.d/easyengine.conf';
			$php_easyengine_conf_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/php/easyengine.conf.backup';
			$new_php_easyengine_conf    = EE_BACKUP_DIR . '/' . $site->site_url . '/php/easyengine.conf';

			if ( ! $this->fs->exists( $new_php_easyengine_conf ) ) {
				continue;
			}

			self::$rsp->add_step(
				"revert-to-$site->site_url-php-easyengine-conf-backup",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $php_easyengine_conf_backup, $old_php_easyengine_conf ],
				null
			);
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to revert easyengine_conf config-update migrations.' );
		}

	}
}
