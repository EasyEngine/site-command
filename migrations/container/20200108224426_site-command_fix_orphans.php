<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class FixOrphans extends Base {

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
			EE::debug( 'Skipping fix-orphans update migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting fix-orphans updates for: $site->site_url" );

			// Skip if site is not enabled.
			if ( $site->site_enabled ) {
				$site_command = new \Site_Command();
				$site_class   = $site_command::get_site_types()[ $site->site_type ];
				$obj          = new $site_class();
				$obj->enable( [ $site->site_url ], [ 'refresh' => true ] );
			}

		}
	}

	/**
	 * Bring back the existing old php config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}
