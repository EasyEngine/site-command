<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Option;
use EE\Model\Site;

class UpdateNginxConfig extends Base {

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

		$version = Option::where( 'key', 'version' );
		$is_rc2  = '4.0.0-rc.2';
		if ( $this->skip_this_migration || ( $is_rc2 === substr( $version[0]->value, 0, strlen( $is_rc2 ) ) ) ) {
			EE::debug( 'Skipping nginx-config update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();
		\EE\Auth\Utils\init_global_admin_tools_auth( false );

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting nginx-config updates for: $site->site_url" );

			$old_nginx_conf    = $site->site_fs_path . '/config/nginx/conf.d/main.conf';
			$nginx_conf_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/main.conf.backup';
			$new_nginx_conf    = EE_BACKUP_DIR . '/' . $site->site_url . '/main.conf';

			$nginx_conf_content = '';
			switch ( $site->site_type ) {
				case 'html':
					$data               = [
						'server_name'   => $site->site_url,
						'document_root' => empty( $site->site_container_fs_path ) ? '/var/www/htdocs' : $site->site_container_fs_path,
					];
					$nginx_conf_content = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', $data );
					break;

				case 'php':
					$default_conf_data['server_name']        = $site->site_url;
					$default_conf_data['site_url']           = $site->site_url;
					$default_conf_data['document_root']      = empty( $site->site_container_fs_path ) ? '/var/www/htdocs' : rtrim( $site->site_container_fs_path, '/' );
					$default_conf_data['include_php_conf']   = ! $site->cache_nginx_fullpage;
					$default_conf_data['include_redis_conf'] = (bool) $site->cache_nginx_fullpage;
					$default_conf_data['cache_host']         = $site->cache_host;

					$nginx_conf_content = \EE\Utils\mustache_render( SITE_PHP_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', $default_conf_data );
					break;
				case 'wp':
					$server_name = ( 'subdom' === $site->app_sub_type ) ? $site->site_url . ' *.' . $site->site_url : $site->site_url;

					$default_conf_data['site_type']             = $site->site_type;
					$default_conf_data['site_url']              = $site->site_url;
					$default_conf_data['document_root']         = empty( $site->site_container_fs_path ) ? '/var/www/htdocs' : rtrim( $site->site_container_fs_path, '/' );
					$default_conf_data['server_name']           = $server_name;
					$default_conf_data['include_php_conf']      = ! $site->cache_nginx_fullpage;
					$default_conf_data['include_wpsubdir_conf'] = 'subdir' === $site->site_type;
					$default_conf_data['include_redis_conf']    = (bool) $site->cache_nginx_fullpage;
					$default_conf_data['cache_host']            = $site->cache_host;

					$nginx_conf_content = \EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', $default_conf_data );
					break;
			}

			$this->fs->dumpFile( $new_nginx_conf, $nginx_conf_content );

			self::$rsp->add_step(
				"take-$site->site_url-nginx-conf-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $old_nginx_conf, $nginx_conf_backup ],
				[ $nginx_conf_backup, $old_nginx_conf ]
			);

			self::$rsp->add_step(
				"replace-$site->site_url-with-new-nginx-conf",
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
			throw new \Exception( 'Unable to run nginx-config upadte migrations.' );
		}

	}

	/**
	 * Bring back the existing old nginx config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		$version = Option::where( 'key', 'version' );
		$is_rc2  = '4.0.0-rc.2';
		if ( $this->skip_this_migration || ( $is_rc2 === substr( $version[0]->value, 0, strlen( $is_rc2 ) ) ) ) {
			EE::debug( 'Skipping nginx-config update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			EE::debug( "Reverting nginx-config updates for: $site->site_url" );

			$old_nginx_conf    = $site->site_fs_path . '/config/nginx/conf.d/main.conf';
			$nginx_conf_backup = EE_BACKUP_DIR . '/' . $site->site_url . '/main.conf.backup';
			$new_nginx_conf    = EE_BACKUP_DIR . '/' . $site->site_url . '/main.conf';

			if ( ! $this->fs->exists( $new_nginx_conf ) ) {
				continue;
			}

			self::$rsp->add_step(
				"revert-to-$site->site_url-nginx-conf-backup",
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
