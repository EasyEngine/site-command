<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use EE\Migration\Base;

class FixSslEntries extends Base {

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Execute create table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping ssl-entries migration as it is not needed.' );

			return;
		}
		foreach ( $this->sites as $site ) {

			if ( empty( $site->site_ssl ) ) {
				continue;
			}

			switch ( $site->site_ssl ) {

				case 'wildcard':
					$site->site_ssl = 'le';
					break;

				case 1:
				case '1':
				case 'letsencrypt':
					$cert_dir    = EE_SERVICE_DIR . '/nginx-proxy/certs';
					$parent_name = implode( '.', array_slice( explode( '.', $site->site_url ), 1 ) );
					if ( $this->fs->exists( $cert_dir . '/' . $site->site_url . '.crt' ) ) {
						$site->site_ssl = 'le';
					} elseif ( $this->fs->exists( $cert_dir . '/' . $parent_name . '.crt' ) ) {
						$site->site_ssl = 'inherit';
					}
					break;

				case 'self':
					$site->site_ssl_wildcard = 1;
					break;

				default:
					break;
			}
			$site->save();
		}
	}

	/**
	 * No changes in case of down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}
