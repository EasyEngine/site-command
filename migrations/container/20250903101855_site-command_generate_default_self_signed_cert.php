<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;

class GenerateDefaultSelfSignedCert extends Base {

	private $certs_dir;
	private $key_path;
	private $crt_path;

	public function __construct() {
		parent::__construct();
		$this->certs_dir = EE_ROOT_DIR . '/services/nginx-proxy/certs';
		$this->key_path  = $this->certs_dir . '/default.key';
		$this->crt_path  = $this->certs_dir . '/default.crt';
	}

	/**
	 * Generate default self-signed cert if not present.
	 * @throws EE\ExitException
	 */
	public function up() {
		if ( $this->fs->exists( $this->key_path ) && $this->fs->exists( $this->crt_path ) ) {
			EE::debug( 'Default self-signed cert already exists. Skipping generation.' );

			return;
		}
		EE::debug( 'Generating default self-signed cert (default.key, default.crt) with CN=default using internal logic' );
		$self_signed = new \EE\Site\Type\Site_Self_signed();
		$self_signed->create_certificate( 'default' );
		EE::debug( 'Self-signed cert generation complete using internal logic.' );
	}

	/**
	 * Remove default self-signed cert (for rollback).
	 * @throws EE\ExitException
	 */
	public function down() {
		if ( $this->fs->exists( $this->key_path ) ) {
			$this->fs->remove( $this->key_path );
		}
		if ( $this->fs->exists( $this->crt_path ) ) {
			$this->fs->remove( $this->crt_path );
		}
		EE::debug( 'Removed default self-signed cert (default.key, default.crt)' );
	}
}
