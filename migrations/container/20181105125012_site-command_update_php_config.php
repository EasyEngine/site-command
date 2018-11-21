<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Option;
use EE\Model\Site;

class UpdatePhpConfig extends Base {

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

		$version = Option::where( 'key', 'version' );
		$is_rc2  = '4.0.0-rc.2';
		if ( $this->skip_this_migration || ( $is_rc2 === substr( $version[0]->value, 0, strlen( $is_rc2 ) ) ) ) {
			EE::debug( 'Skipping no-overlap migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		$first_execution = true;
		$updated_images  = [];

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Starting php-config path and volume changes for: $site->site_url" );

			$docker_yml              = $site->site_fs_path . '/docker-compose.yml';
			$docker_yml_backup       = EE_BACKUP_DIR . '/' . $site->site_url . '/docker-compose.yml.backup';
			$prefix                  = \EE::docker()->get_docker_style_prefix( $site->site_url );
			$config_volume_name      = 'config_php';
			$log_volume_name         = 'log_php';
			$config_postfix          = 'config_postfix';
			$ssl_postfix             = 'ssl_postfix';
			$log_volume_to_check     = $prefix . '_log_php';
			$volume_to_be_deleted    = $prefix . '_config_php';
			$log_symlink             = $site->site_fs_path . '/logs/php';
			$config_symlink_path_old = $site->site_fs_path . '/config/php-fpm';
			$config_symlink_path_new = $site->site_fs_path . '/config/php';
			$postfix_ssl_symlink     = $site->site_fs_path . '/services/postfix/ssl';
			$postfix_config_symlink  = $site->site_fs_path . '/config/postfix';
			$full_config_volume_name = $prefix . '_config_postfix';
			$full_ssl_volume_name    = $prefix . '_ssl_postfix';
			$restore_file_path       = $site->site_fs_path . '/config/php/php';
			$backup_file_path        = EE_BACKUP_DIR . '/' . $site->site_url . '/php-fpm';
			$ee_site_object          = SiteContainers::get_site_object( $site->site_type );
			$data_in_array           = (array) $site;
			$array_site_data         = array_pop( $data_in_array );
			$backup_to_restore       = $backup_file_path;

			if ( 'php' === $site->site_type ) {
				$config_data_path_old = $site->site_fs_path . '/config/php-fpm/php';
				self::$rsp->add_step(
					"take-$site->site_url-php-ini-backup",
					'EE\Migration\SiteContainers::backup_restore',
					null,
					[ $site->site_fs_path . '/config/php-fpm/php.ini', $config_data_path_old . '/php.ini' ],
					null
				);
				$backup_to_restore = $backup_file_path . '/php';
			}

			try {
				$this->fs->remove( $site->site_fs_path . '/config/postfix' );
			} catch ( \IOException $e ) {
			}

			self::$rsp->add_step(
				"take-$site->site_url-docker-compose-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $docker_yml, $docker_yml_backup ],
				[ $docker_yml_backup, $docker_yml ]
			);

			self::$rsp->add_step(
				"take-$site->site_url-config-php-vol-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $config_symlink_path_old, $backup_file_path ],
				[ $backup_file_path, $config_symlink_path_old ]
			);

			self::$rsp->add_step(
				"generate-$site->site_url-docker-compose",
				'EE\Migration\SiteContainers::generate_site_docker_compose_file',
				null,
				[ $array_site_data, $ee_site_object ],
				null
			);

			if ( $first_execution ) {
				self::$rsp->add_step(
					"pulling-images-for-$site->site_url",
					'EE\Migration\SiteContainers::docker_compose_pull',
					null,
					[ $site->site_fs_path ],
					null
				);

				$img_versions     = EE\Utils\get_image_versions();
				$current_versions = \EE\Migration\Containers::get_current_docker_images_versions();
				$old_img_backup   = EE_BACKUP_DIR . '/img-version-old.json';
				$this->fs->dumpFile( $old_img_backup, json_encode( $current_versions ) );

				foreach ( $img_versions as $img => $version ) {
					if ( $current_versions[ $img ] !== $version ) {
						$updated_images[] = $img;
					}
				}
				$first_execution = false;
			}

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"stop-$site->site_url-containers",
					'EE\Site\Utils\stop_site_containers',
					'EE\Site\Utils\start_site_containers',
					[ $site->site_fs_path, [ 'php' ] ],
					[ $site->site_fs_path, [ 'php' ] ]
				);
			}

			self::$rsp->add_step(
				"delete-$site->site_url-config-php-volume",
				'EE\Migration\SiteContainers::delete_volume',
				'EE\Migration\SiteContainers::create_volume',
				[ $volume_to_be_deleted, $config_symlink_path_old ],
				[ $site->site_url, $config_volume_name, $config_symlink_path_old ]
			);

			$existing_volumes = \EE::docker()->get_volumes_by_label( $site->site_url );
			if ( ! in_array( $log_volume_to_check, $existing_volumes ) ) {
				self::$rsp->add_step(
					"create-$site->site_url-log-php-volume",
					'EE\Migration\SiteContainers::create_volume',
					'EE\Migration\SiteContainers::delete_volume',
					[ $site->site_url, $log_volume_name, $log_symlink ],
					[ $log_volume_to_check, $log_symlink ]
				);
			}

			self::$rsp->add_step(
				"create-$site->site_url-config-php-volume",
				'EE\Migration\SiteContainers::create_volume',
				'EE\Migration\SiteContainers::delete_volume',
				[ $site->site_url, $config_volume_name, $config_symlink_path_new ],
				[ $volume_to_be_deleted, $config_symlink_path_new ]
			);

			if ( ! in_array( $full_config_volume_name, $existing_volumes ) ) {
				self::$rsp->add_step(
					"create-$site->site_url-postfix-config-volume",
					'EE\Migration\SiteContainers::create_volume',
					'EE\Migration\SiteContainers::delete_volume',
					[ $site->site_url, $config_postfix, $postfix_config_symlink ],
					[ $full_config_volume_name, $postfix_config_symlink ]
				);
			}

			if ( ! in_array( $full_ssl_volume_name, $existing_volumes ) ) {
				self::$rsp->add_step(
					"create-$site->site_url-postfix-ssl-volume",
					'EE\Migration\SiteContainers::create_volume',
					'EE\Migration\SiteContainers::delete_volume',
					[ $site->site_url, $ssl_postfix, $postfix_ssl_symlink ],
					[ $full_ssl_volume_name, $postfix_ssl_symlink ]
				);
			}

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"start-$site->site_url-containers",
					'EE\Site\Utils\start_site_containers',
					'EE\Site\Utils\stop_site_containers',
					[ $site->site_fs_path, [ 'php' ] ],
					[ $site->site_fs_path, [ 'php' ] ]
				);
			}

			self::$rsp->add_step(
				"restore-$site->site_url-config-php-vol-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $backup_to_restore, $restore_file_path ],
				[ $backup_file_path, $config_symlink_path_old ]
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"restart-$site->site_url-containers",
					'EE\Site\Utils\restart_site_containers',
					'EE\Site\Utils\restart_site_containers',
					[ $site->site_fs_path, [ 'php' ] ],
					[ $site->site_fs_path, [ 'php' ] ]
				);
			}
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run config-php migrations.' );
		}

		if ( ! empty( $updated_images ) && in_array( 'easyengine/php', $updated_images ) ) {
			EE\Model\Option::update( [ [ 'key', 'easyengine/php' ] ], [ 'value' => $img_versions['easyengine/php'] ] );
		}

	}

	/**
	 * Bring back the existing old config and path.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		$version = Option::where( 'key', 'version' );
		$is_rc2  = '4.0.0-rc.2';
		if ( $this->skip_this_migration || ( $is_rc2 === substr( $version[0]->value, 0, strlen( $is_rc2 ) ) ) ) {
			EE::debug( 'Skipping no-overlap migration down as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Reverting php-config path and volume changes for: $site->site_url" );

			$docker_yml              = $site->site_fs_path . '/docker-compose.yml';
			$docker_yml_backup       = EE_BACKUP_DIR . '/' . $site->site_url . '/docker-compose.yml.backup';
			$prefix                  = \EE::docker()->get_docker_style_prefix( $site->site_url );
			$config_volume_name      = 'config_php';
			$volume_to_be_deleted    = $prefix . '_config_php';
			$config_symlink_path_old = $site->site_fs_path . '/config/php-fpm';
			$config_symlink_path_new = $site->site_fs_path . '/config/php';
			$restore_file_path       = $site->site_fs_path . '/config/php/php';
			$backup_file_path        = EE_BACKUP_DIR . '/' . $site->site_url . '/php-fpm';
			$backup_to_restore       = ( 'php' === $site->site_type ) ? $site->site_fs_path . '/config/php-fpm/php' : $backup_file_path;

			self::$rsp->add_step(
				"revert-$site->site_url-docker-compose-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $docker_yml_backup, $docker_yml ],
				[ $docker_yml, $docker_yml_backup ]
			);

			self::$rsp->add_step(
				"revert-$site->site_url-config-php-vol-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $backup_file_path, $config_symlink_path_old ],
				[ $config_symlink_path_old, $backup_file_path ]
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"stop-$site->site_url-containers",
					'EE\Site\Utils\stop_site_containers',
					'EE\Site\Utils\start_site_containers',
					[ $site->site_fs_path, [ 'php' ] ],
					[ $site->site_fs_path, [ 'php' ] ]
				);
			}

			self::$rsp->add_step(
				"delete-new-$site->site_url-config-php-volume",
				'EE\Migration\SiteContainers::delete_volume',
				'EE\Migration\SiteContainers::create_volume',
				[ $volume_to_be_deleted, $config_symlink_path_new ],
				[ $site->site_url, $config_volume_name, $config_symlink_path_new ]
			);

			self::$rsp->add_step(
				"re-create-$site->site_url-config-php-volume",
				'EE\Migration\SiteContainers::create_volume',
				'EE\Migration\SiteContainers::delete_volume',
				[ $site->site_url, $config_volume_name, $config_symlink_path_old ],
				[ $volume_to_be_deleted, $config_symlink_path_old ]
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"start-$site->site_url-containers",
					'EE\Site\Utils\start_site_containers',
					'EE\Site\Utils\stop_site_containers',
					[ $site->site_fs_path, [ 'php' ] ],
					[ $site->site_fs_path, [ 'php' ] ]
				);
			}

			self::$rsp->add_step(
				"restore-$site->site_url-old-config-php-vol-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $backup_file_path, $config_symlink_path_old ],
				[ $backup_to_restore, $restore_file_path ]
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"start-$site->site_url-containers",
					'EE\Site\Utils\restart_site_containers',
					'EE\Site\Utils\restart_site_containers',
					[ $site->site_fs_path, [ 'php' ] ],
					[ $site->site_fs_path, [ 'php' ] ]
				);
			}
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to revert config-php migrations.' );
		}

		$img_versions   = EE\Utils\get_image_versions();
		$old_img_backup = EE_BACKUP_DIR . '/img-version-old.json';
		$updated_images = [];
		if ( $this->fs->exists( $old_img_backup ) ) {
			$old_img_versions = json_decode( $old_img_backup, true );
			$json_error       = json_last_error();
			if ( JSON_ERROR_NONE === $json_error ) {
				foreach ( $img_versions as $img => $version ) {
					if ( $old_img_versions[ $img ] !== $version ) {
						$updated_images[] = $img;
					}
				}
			}
		}
		if ( ! empty( $updated_images ) ) {
			if ( ! empty( $updated_images ) && in_array( 'easyengine/php', $updated_images ) ) {
				EE\Model\Option::update( [
					[
						'key',
						'easyengine/php'
					]
				], [ 'value' => $old_img_versions['easyengine/php'] ] );
			}
		}
	}
}
