<?php
namespace EE\Migration;

use EE;

class AddDaemonJson extends Base {

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Execute create table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( php_uname('s') === 'Linux' ) {
			if ( file_exists( '/etc/docker/daemon.json' ) ) {
				$existin_config = file_get_contents( '/etc/docker/daemon.json' );
				$existin_config = json_decode( $existin_config, true );
			} else {
				$existin_config = [];
			}

			$existin_config['default-address-pools'] = json_decode( '[{"base":"10.0.0.0/8","size":24}]', true );
			file_put_contents( '/etc/docker/daemon.json', json_encode( $existin_config ) );
		}
	}

	/**
	 * Execute drop table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

	}
}
