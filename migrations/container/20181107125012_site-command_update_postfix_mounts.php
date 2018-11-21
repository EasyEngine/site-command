<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Option;
use EE\Model\Site;

class UpdatePostfixMounts extends Base {

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

		foreach ( $this->sites as $site ) {

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Starting postfix path and volume changes for: $site->site_url" );

			$docker_yml              = $site->site_fs_path . '/docker-compose.yml';
			$docker_yml_backup       = EE_BACKUP_DIR . '/' . $site->site_url . '/docker-compose.yml.backup';
			$prefix                  = \EE::docker()->get_docker_style_prefix( $site->site_url );
			$config_volume_name      = 'config_postfix';
			$ssl_volume_name         = 'ssl_postfix';
			$data_volume_name        = 'data_postfix';
			$full_data_volume_name   = $prefix . '_data_postfix';
			$full_config_volume_name = $prefix . '_config_postfix';
			$full_ssl_volume_name    = $prefix . '_ssl_postfix';
			$ssl_symlink             = $site->site_fs_path . '/services/postfix/ssl';
			$data_symlink            = $site->site_fs_path . '/services/postfix/spool';
			$config_symlink          = $site->site_fs_path . '/config/postfix';
			$ee_site_object          = SiteContainers::get_site_object( $site->site_type );
			$data_in_array           = (array) $site;
			$array_site_data         = array_pop( $data_in_array );

			self::$rsp->add_step(
				"take-$site->site_url-docker-compose-backup",
				'EE\Migration\SiteContainers::backup_restore',
				'EE\Migration\SiteContainers::backup_restore',
				[ $docker_yml, $docker_yml_backup ],
				[ $docker_yml_backup, $docker_yml ]
			);

			self::$rsp->add_step(
				"generate-$site->site_url-docker-compose",
				'EE\Migration\SiteContainers::generate_site_docker_compose_file',
				null,
				[ $array_site_data, $ee_site_object ],
				null
			);

			if ( $site->site_enabled ) {
				self::$rsp->add_step(
					"stop-$site->site_url-postfix-containers",
					'EE\Site\Utils\stop_site_containers',
					'EE\Site\Utils\start_site_containers',
					[ $site->site_fs_path, [ 'postfix' ] ],
					[ $site->site_fs_path, [ 'postfix' ] ]
				);
			}

			self::$rsp->add_step(
				"delete-$site->site_url-postfix-data-volume",
				'EE\Migration\SiteContainers::delete_volume',
				'EE\Migration\SiteContainers::create_volume',
				[ $full_data_volume_name, $data_symlink ],
				[ $site->site_url, $data_volume_name, $data_symlink ]
			);

			self::$rsp->add_step(
				"create-$site->site_url-postfix-data-volume",
				'EE\Migration\SiteContainers::create_volume',
				'EE\Migration\SiteContainers::delete_volume',
				[ $site->site_url, $data_volume_name, $data_symlink ],
				[ $full_data_volume_name, $data_symlink ]
			);

			$existing_volumes = \EE::docker()->get_volumes_by_label( $site->site_url );
			if ( ! in_array( $full_config_volume_name, $existing_volumes ) ) {
				self::$rsp->add_step(
					"create-$site->site_url-postfix-config-volume",
					'EE\Migration\SiteContainers::create_volume',
					'EE\Migration\SiteContainers::delete_volume',
					[ $site->site_url, $config_volume_name, $config_symlink ],
					[ $full_config_volume_name, $config_symlink ]
				);
			}

			if ( ! in_array( $full_ssl_volume_name, $existing_volumes ) ) {
				self::$rsp->add_step(
					"create-$site->site_url-postfix-ssl-volume",
					'EE\Migration\SiteContainers::create_volume',
					'EE\Migration\SiteContainers::delete_volume',
					[ $site->site_url, $ssl_volume_name, $ssl_symlink ],
					[ $full_ssl_volume_name, $ssl_symlink ]
				);
			}

			self::$rsp->add_step(
				"start-$site->site_url-postfix-containers",
				'EE\Site\Utils\start_site_containers',
				'EE\Site\Utils\stop_site_containers',
				[ $site->site_fs_path, [ 'postfix' ] ],
				[ $site->site_fs_path, [ 'postfix' ] ]
			);

			self::$rsp->add_step(
				"set-$site->site_url-postfix-files",
				'EE\Site\Utils\set_postfix_files',
				null,
				[ $site->site_url, $site->site_fs_path . '/services' ],
				null
			);

			self::$rsp->add_step(
				"configure-$site->site_url-postfix",
				'EE\Site\Utils\configure_postfix',
				null,
				[ $site->site_url, $site->site_fs_path ],
				null
			);

			if ( ! $site->site_enabled ) {
				self::$rsp->add_step(
					"stop-and-remove-$site->site_url-postfix-containers",
					'EE\Site\Utils\stop_site_containers',
					'EE\Site\Utils\start_site_containers',
					[ $site->site_fs_path, [ 'postfix' ] ],
					[ $site->site_fs_path, [ 'postfix' ] ]
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
