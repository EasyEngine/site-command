<?php

namespace EE\Migration;

use EE;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class PostfixMsmtrpcFix extends Base {

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
			EE::debug( 'Skipping postfix-msmtrpc-fix migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {
			mkdir( $site->site_fs_path . '/config/php/misc' );
			touch( $site->site_fs_path . '/config/php/misc/msmtprc' );

			chdir( $site->site_fs_path );
			EE::exec( \EE_DOCKER::docker_compose_with_custom() . " exec --user=root php sh -c 'rm /etc/msmtprc'" );
			EE::exec( \EE_DOCKER::docker_compose_with_custom() . " exec --user=root php sh -c 'ln -s /usr/local/etc/misc/msmtprc /etc/msmtprc'" );
			EE::exec( \EE_DOCKER::docker_compose_with_custom() . " exec --user=root php sh -c 'chown -R www-data:www-data /usr/local/etc/misc'" );
			EE::exec( \EE_DOCKER::docker_compose_with_custom() . " exec --user=root php sh -c 'chown -R www-data:www-data /etc/msmtprc'" );

			EE\Site\Utils\configure_postfix( $site->site_url, $site->site_fs_path );
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
