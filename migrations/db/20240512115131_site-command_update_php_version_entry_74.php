<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use EE\Migration\Base;

class UpdatePhpVersionEntry74 extends Base {

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
			EE::debug( 'Skipping php-version migration as it is not needed.' );

			return;
		}
		foreach ( $this->sites as $site ) {

			if ( 'latest' === $site->php_version ) {
				$site->php_version = '7.4';
				$site->save();
			}
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
