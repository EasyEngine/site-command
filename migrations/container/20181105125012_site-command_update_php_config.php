<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
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

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping no-overlap migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		$first_execution = true;

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Starting php-config path and volume changes for: $site->site_url" );

			$docker_yml                 = $site->site_fs_path . '/docker-compose.yml';
			$docker_yml_backup          = EE_BACKUP_DIR . '/' . $site->site_url . '/docker-compose.yml.backup';
			$prefix                     = \EE::docker()->get_docker_style_prefix( $site->site_url );
			$config_volume_name         = 'config_php';
			$log_volume_name            = 'log_php';
			$postfix_config_volume_name = 'config_postfix';
			$log_volume_to_check        = $prefix . '_log_php';
			$postfix_volume_to_check    = $prefix . '_config_postfix';
			$volume_to_be_deleted       = $prefix . '_config_php';
			$log_symlink                = $site->site_fs_path . '/logs/php';
			$postfix_config_symlink     = $site->site_fs_path . '/config/postfix';
			$config_symlink_path_old    = $site->site_fs_path . '/config/php-fpm';
			$config_symlink_path_new    = $site->site_fs_path . '/config/php';
			$restore_file_path          = $site->site_fs_path . '/config/php/php';
			$backup_file_path           = EE_BACKUP_DIR . '/' . $site->site_url . '/php-fpm';
			$ee_site_object             = SiteContainers::get_site_object( $site->site_type );
			$data_in_array              = (array) $site;
			$array_site_data            = array_pop( $data_in_array );
			$backup_to_restore          = $backup_file_path;

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

			if ( ! in_array( $postfix_volume_to_check, $existing_volumes ) ) {
				self::$rsp->add_step(
					"create-$site->site_url-postfix-config-volume",
					'EE\Migration\SiteContainers::create_volume',
					'EE\Migration\SiteContainers::delete_volume',
					[ $site->site_url, $postfix_config_volume_name, $postfix_config_symlink ],
					[ $postfix_volume_to_check, $postfix_config_symlink ]
				);
			}

			self::$rsp->add_step(
				"create-$site->site_url-config-php-volume",
				'EE\Migration\SiteContainers::create_volume',
				'EE\Migration\SiteContainers::delete_volume',
				[ $site->site_url, $config_volume_name, $config_symlink_path_new ],
				[ $volume_to_be_deleted, $config_symlink_path_new ]
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
				"restore-$site->site_url-config-php-vol-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $backup_to_restore, $restore_file_path ],
				[ $backup_file_path, $config_symlink_path_old ]
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
			throw new \Exception( 'Unable to run config-php migrations.' );
		}

	}

	/**
	 * Bring back the existing old config and path.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
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

	}
}
