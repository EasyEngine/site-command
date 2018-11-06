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

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Starting php-config path and volume changes for: $site->site_url" );

			$docker_yml           = $site->site_fs_path . '/docker-compose.yml';
			$docker_yml_backup    = $site->site_fs_path . '/docker-compose.yml.backup';
			$prefix               = \EE::docker()->get_docker_style_prefix( $site->site_url );
			$volume_name          = 'config_php';
			$volume_to_be_deleted = $prefix . '_config_php';
			$symlink_path_old     = $site->site_fs_path . '/config/php-fpm';
			$symlink_path_new     = $site->site_fs_path . '/config/php';
			$restore_file_path    = $site->site_fs_path . '/config/php';
			$backup_file_source   = EE_ROOT_DIR . '/.backup/php-fpm';

			self::$rsp->add_step(
				"take-$site->site_url-docker-compose-backup",
				'EE\Migration\SiteContainers::backup_site_docker_compose_file',
				'EE\Migration\SiteContainers::revert_site_docker_compose_file',
				[ $docker_yml, $docker_yml_backup ],
				[ $docker_yml_backup, $docker_yml ]
			);

			self::$rsp->add_step(
				"take-$site->site_url-config-php-vol-backup",
				'EE\Migration\SiteContainers::backup_files',
				'EE\Migration\SiteContainers::restore_files',
				[ $symlink_path_old ],
				[ $symlink_path_old ]
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
				"delete-$site->site_url-config-php-volume",
				'EE\Migration\SiteContainers::delete_volume',
				'EE\Migration\SiteContainers::create_volume',
				[ $volume_to_be_deleted, $symlink_path_old ],
				[ $site->site_url, $volume_name, $symlink_path_old ]
			);

			self::$rsp->add_step(
				"create-$site->site_url-config-php-volume",
				'EE\Migration\SiteContainers::create_volume',
				'EE\Migration\SiteContainers::delete_volume',
				[ $site->site_url, $volume_name, $symlink_path_new ],
				[ $volume_to_be_deleted, $symlink_path_new ]
			);

			self::$rsp->add_step(
				"restore-$site->site_url-config-php-vol-backup",
				'EE\Migration\SiteContainers::restore_files',
				'EE\Migration\SiteContainers::backup_files',
				[ $restore_file_path, $backup_file_source ],
				[ $backup_file_source ]
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

	}
}
