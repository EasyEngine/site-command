<?php

namespace EE\Migration;

use EE;

class UpdateDaemonJson extends Base {

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = false;
		}
	}

	/**
	 * Execute create table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( php_uname( 's' ) === 'Linux' ) {
			if ( file_exists( '/etc/docker/daemon.json' ) ) {
				$existin_config = file_get_contents( '/etc/docker/daemon.json' );
				$existin_config = json_decode( $existin_config, true );
			} else {
				$existin_config = [];
			}

			$existin_config['log-driver'] = 'json-file';
			$existin_config['log-opts']   = json_decode( '{"max-size":"10m"}', true );

			file_put_contents( '/etc/docker/daemon.json', json_encode( $existin_config ) );

			EE::launch( 'command -v systemctl && systemctl restart docker || service docker restart' );
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
