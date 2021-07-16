<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class UpdateNginxFastcgiParams extends Base {

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
	 * Execute nginx config updates.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping nginx-fastcgi-param update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting nginx-fastcgi-param updates for: $site->site_url" );

			$old_nginx_conf    = $site->site_fs_path . '/config/nginx/fastcgi_params';
			$nginx_conf_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/fastcgi_params.backup';
			$new_nginx_conf    = EE_BACKUP_DIR . '/' . $site->site_url . '/fastcgi_params';

			$this->fs->mkdir( dirname( $new_nginx_conf ) );
			$download_url = 'https://raw.githubusercontent.com/EasyEngine/dockerfiles/04114d03fec1485cf23deda0f910751ebdc76dbc/nginx/conf/fastcgi_params';
			$headers      = [];
			$options      = [
				'timeout'  => 600,  // 10 minutes ought to be enough for everybody.
				'filename' => $new_nginx_conf,
			];
			\EE\Utils\http_request( 'GET', $download_url, null, $headers, $options );

			self::$rsp->add_step(
				"take-$site->site_url-nginx-fastcgi-param-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_nginx_conf, $nginx_conf_backup ],
				[ $nginx_conf_backup, $old_nginx_conf ]
			);

			self::$rsp->add_step(
				"replace-$site->site_url-with-new-nginx-fastcgi-param",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $new_nginx_conf, $old_nginx_conf ],
				[ $old_nginx_conf, $new_nginx_conf ]
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"reload-$site->site_url-nginx-containers",
					'EE\Migration\SiteContainers::reload_nginx',
					null,
					[ $site->site_fs_path ],
					null
				);
			}
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run nginx-fastcgi-param upadte migrations.' );
		}

	}

	/**
	 * Bring back the existing old nginx config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping nginx-fastcgi-param update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Reverting nginx-fastcgi-param updates for: $site->site_url" );

			$old_nginx_conf    = $site->site_fs_path . '/config/nginx/fastcgi_params';
			$nginx_conf_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/fastcgi_params.backup';
			$new_nginx_conf    = EE_BACKUP_DIR . '/' . $site->site_url . '/fastcgi_params';

			if ( ! $this->fs->exists( $new_nginx_conf ) ) {
				continue;
			}

			self::$rsp->add_step(
				"revert-to-$site->site_url-nginx-fastcgi-param-backup",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $nginx_conf_backup, $old_nginx_conf ],
				null
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"reload-$site->site_url-nginx-containers",
					'EE\Migration\SiteContainers::reload_nginx',
					null,
					[ $site->site_fs_path ],
					null
				);
			}
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to revert nginx config-update migrations.' );
		}

	}
}
