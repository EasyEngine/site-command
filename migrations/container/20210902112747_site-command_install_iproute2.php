<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class InstallIproute2 extends Base {

	private $sites;
	/** @var RevertableStepProcessor $rsp Keeps track of migration state. Reverts on error */
	private static $rsp;

	/**
	 * Execute php config updates.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping add-ext-config-8.0 update migration as it is not needed.' );

			return;
		}

		$os = EE::launch( 'lsb_release -i | awk \'{print $3}\'' )->stdout;
		$ip_command_present = EE::launch( 'command -v ip' )->return_code === 0;

		if ( strpos( $os, 'Ubuntu') !== false && $ip_command_present ) {
			EE::exec( 'bash -c \'if command -v apt; then apt install iproute2; fi\'' );
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
