<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class AddAdminToolsConf extends Base {

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
			EE::debug( 'Skipping nginx-config update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		if ( $this->fs->exists( EE_ROOT_DIR . '/admin-tools/index.php' ) ) {
			$this->fs->copy( EE_ROOT_DIR . '/admin-tools/index.php', EE_BACKUP_DIR . '/old-admin-tools-index.php' );
		}

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Starting nginx-config updates for: $site->site_url" );

			$nginx_conf      = $site->site_fs_path . '/config/nginx/custom/admin-tools.conf';
			$new_nginx_conf  = EE_BACKUP_DIR . '/' . $site->site_url . '/admin-tools.conf';
			$ee_admin_path   = $site->site_container_fs_path . '/ee-admin';
			$new_admin_path  = '/var/www/htdocs/ee-admin';
			$admin_file      = $site->site_fs_path . '/docker-compose-admin.yml';
			$admin_index_new = EE_BACKUP_DIR . '/20201030170225-admin-index.php';
			$admin_index     = EE_ROOT_DIR . '/admin-tools/index.php';

			if ( $site->admin_tools ) {
				file_put_contents( $admin_file, str_replace( $ee_admin_path, $new_admin_path, file_get_contents( $admin_file ) ) );
				chdir( $site->site_fs_path );
				if ( EE::exec( 'docker-compose -f docker-compose.yml -f docker-compose-admin.yml up -d nginx' ) ) {
					EE::debug( 'Update successful.' );
				} else {
					EE::debug( 'Update failed.' );
				}
			}

			$this->fs->mkdir( dirname( $new_nginx_conf ) );
			$download_url = 'https://raw.githubusercontent.com/EasyEngine/site-type-wp/7b69b310eeb2dd79e85bef33f3f19977917291ba/templates/config/nginx/admin-tools.conf.mustache';
			$headers      = [];
			$options      = [
				'timeout'  => 600,  // 10 minutes ought to be enough for everybody.
				'filename' => $new_nginx_conf,
			];
			\EE\Utils\http_request( 'GET', $download_url, null, $headers, $options );

			if ( ! $this->fs->exists( $admin_index_new ) ) {

				$download_url = 'https://raw.githubusercontent.com/EasyEngine/admin-tools-command/6e0fc0def72403910eeedd912626f2322c674f4a/templates/index.mustache';
				$headers      = [];
				$options      = [
					'timeout'  => 600,  // 10 minutes ought to be enough for everybody.
					'filename' => $admin_index_new,
				];
				\EE\Utils\http_request( 'GET', $download_url, null, $headers, $options );
				file_put_contents( $admin_index_new, str_replace( '{{db_path}}', EE_ROOT_DIR . '/db/ee.sqlite', file_get_contents( $admin_index_new ) ) );
			}

			self::$rsp->add_step(
				"add-$site->site_url-new-nginx-conf",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $new_nginx_conf, $nginx_conf ],
				[ $nginx_conf, $new_nginx_conf ]
			);

			self::$rsp->add_step(
				"update-$site->site_url-admin-tools-index",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $admin_index_new, $admin_index ],
				[ EE_BACKUP_DIR . '/old-admin-tools-index.php', $admin_index_new ]
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
			throw new \Exception( 'Unable to run nginx-config update migrations.' );
		}

	}

	/**
	 * Bring back the existing old nginx config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping nginx-config update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Reverting nginx-config updates for: $site->site_url" );

			$nginx_conf     = $site->site_fs_path . '/config/nginx/custom/admin-tools.conf';
			$new_nginx_conf = EE_BACKUP_DIR . '/' . $site->site_url . '/admin-tools.conf';

			$ee_admin_path  = $site->site_container_fs_path . '/ee-admin';
			$new_admin_path = '/var/www/htdocs/ee-admin';
			$admin_file     = $site->site_fs_path . '/docker-compose-admin.yml';
			$admin_index    = EE_ROOT_DIR . '/admin-tools/index.php';

			if ( $site->admin_tools ) {
				file_put_contents( $admin_file, str_replace( $new_admin_path, $ee_admin_path, file_get_contents( $admin_file ) ) );
				chdir( $site->site_fs_path );
				$this->fs->remove( $ee_admin_path );
				if ( EE::exec( 'docker-compose -f docker-compose.yml -f docker-compose-admin.yml up -d nginx' ) ) {
					EE::debug( 'Update successful.' );
				} else {
					EE::debug( 'Update failed.' );
				}
			}

			if ( ! $this->fs->exists( $new_nginx_conf ) ) {
				continue;
			}

			self::$rsp->add_step(
				"revert-to-$site->site_url-previous-state",
				'EE\Migration\SiteContainers::backup_restore',
				null,
				[ $nginx_conf, $new_nginx_conf ],
				null
			);

			if ( $this->fs->exists( EE_BACKUP_DIR . '/old-admin-tools-index.php' ) && $this->fs->exists( $admin_index ) ) {

				self::$rsp->add_step(
					"restore-$site->site_url-admin-tools-index",
					'EE\Migration\SiteContainers::backup_restore',
					'EE\Migration\SiteContainers::backup_restore',
					[ EE_BACKUP_DIR . '/old-admin-tools-index.php', $admin_index ],
					[ EE_BACKUP_DIR . '/old-admin-tools-index.php', $admin_index ]
				);
			}

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
