<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class NginxAddWebpToCache extends Base {

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

		foreach ( $this->sites as $site ) {
			$main_conf_file = $site->site_fs_path . '/config/nginx/conf.d/main.conf';

			if ( file_exists( $main_conf_file ) ) {

				$search  = '|swf';
				$replace = '|swf|webp';

				$file_contents = file_get_contents( $main_conf_file );

				if ( strpos( $file_contents, $search ) !== false && strpos( $file_contents, 'webp' ) === false ) {
					$file_contents = str_replace( $search, $replace, $file_contents );
				}

				file_put_contents( $main_conf_file, $file_contents );
			}

		}
	}

	/**
	 * Bring back the existing old nginx config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}

}
