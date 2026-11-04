<?php

namespace EE\Migration;

use EE;

class RepairSelfSignedRootCa extends Base {

	/** @var string|null Root certificate before the repair, restored by down(). */
	private $old_pem;

	private $root_pem;

	public function __construct() {
		parent::__construct();
		$this->root_pem = EE_SERVICE_DIR . '/nginx-proxy/self-signed-certs/rootCA.pem';

		if ( $this->is_first_execution || ! $this->fs->exists( $this->root_pem ) ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Re-issue a self-signed root CA that lacks basicConstraints CA:TRUE (generated with OpenSSL >= 3.2).
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping the self-signed root CA repair: not needed.' );

			return;
		}

		$old_pem = file_get_contents( $this->root_pem );
		try {
			if ( ( new \EE\Site\Type\Site_Self_signed() )->repair_root_cert() ) {
				$this->old_pem = $old_pem;
			} else {
				EE::debug( 'Self-signed root CA needs no repair.' );
			}
		} catch ( \Throwable $e ) {
			// Not worth failing the upgrade over: the next --ssl=self site retries the repair.
			EE::warning( 'Could not repair the self-signed root CA: ' . $e->getMessage() );
		}
	}

	/**
	 * Restore the previous root certificate (same key, so site certificates are unaffected).
	 */
	public function down() {

		if ( null === $this->old_pem ) {
			return;
		}

		$this->fs->dumpFile( $this->root_pem, $this->old_pem );
		( new \EE\Site\Type\Site_Self_signed() )->retrust_root_cert();
	}
}
