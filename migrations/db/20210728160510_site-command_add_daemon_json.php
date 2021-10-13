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

		if ( IS_DARWIN ) {
			// This message also needs to be show in installer too.
			EE::log( PHP_EOL . PHP_EOL . 'Hey there, we have recently introduced a feature
which would allow you to create more than 27 sites.

To enable it, you need to perform the following steps manually:
1. Please open Docker Desktop and in taskbar, go to Preferences > Daemon > Advanced.
2. If the file is empty, add the following:

{
 "default-address-pools": [{"base":"10.0.0.0/8","size":24}]
}

If the file already contains JSON, just add the key "default-address-pools": [{"base":"10.0.0.0/8","size":24}]
being careful to add a comma to the end of the line if it is not the last line before the closing bracket.

3. Restart Docker' . PHP_EOL );

			EE::confirm( 'Once done with above changes, please press y' );

		} elseif ( php_uname('s') === 'Linux' ) {
			if ( file_exists( '/etc/docker/daemon.json' ) ) {
				$existin_config = file_get_contents( '/etc/docker/daemon.json' );
				$existin_config = json_decode( $existin_config, true );
			} else {
				$existin_config = [];
			}

			$existin_config['default-address-pools'] = json_decode( '[{"base":"10.0.0.0/8","size":24}]', true );
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
