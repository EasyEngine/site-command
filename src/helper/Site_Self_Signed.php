<?php

namespace EE\Site\Type;

use EE;
use Symfony\Component\Filesystem\Filesystem;

class Site_Self_signed {

	/**
	 * @var string $conf_dir THe configuration directory for self-signed certs.
	 */
	private $conf_dir;
	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	public function __construct() {

		$this->fs       = new Filesystem();
		$this->conf_dir = EE_SERVICE_DIR . '/nginx-proxy/self-signed-certs';
	}

	/**
	 * Create and trust a certificate for the given URL.
	 *
	 * @param  string $url
	 *
	 * @return void
	 */
	public function create_certificate( $url ) {
		EE::debug( 'Starting self signed cert generation' );
		$key_path  = $this->conf_dir . '/' . $url . '.key';
		$csr_path  = $this->conf_dir . '/' . $url . '.csr';
		$crt_path  = $this->conf_dir . '/' . $url . '.crt';
		$conf_path = $this->conf_dir . '/' . $url . '.conf';

		$this->build_certificate_conf( $conf_path, $url );
		$this->create_private_key( $key_path );
		$this->create_signing_request( $url, $key_path, $csr_path, $conf_path );

		EE::exec( sprintf(
			'openssl x509 -req -days 365 -in %s -signkey %s -out %s -extensions v3_req -extfile %s',
			$csr_path, $key_path, $crt_path, $conf_path
		) );

		$this->trust_certificate( $crt_path );
		$this->move_certs_to_nginx_proxy( $url, $key_path, $crt_path );

		// Cleanup files.
		$this->fs->remove( [ $key_path, $csr_path, $conf_path ] );
	}

	/**
	 * Build the SSL config for the given URL.
	 *
	 * @param  string $url
	 *
	 * @return string
	 */
	private function build_certificate_conf( $path, $url ) {
		$config = str_replace( 'EE_DOMAIN', $url, file_get_contents( SITE_TEMPLATE_ROOT . '/config/self-signed-certs/openssl.conf.mustache' ) );
		$this->fs->dumpFile( $path, $config );
	}


	/**
	 * Create the private key for the TLS certificate.
	 *
	 * @param  string $key_path
	 *
	 * @return void
	 */
	private function create_private_key( $key_path ) {
		EE::exec( sprintf( 'openssl genrsa -out %s 2048', $key_path ) );
	}

	/**
	 * Create the signing request for the TLS certificate.
	 *
	 * @param  string $key_path
	 *
	 * @return void
	 */
	private function create_signing_request( $url, $key_path, $csr_path, $conf_path ) {
		EE::exec( sprintf(
			'openssl req -new -key %s -out %s -subj "/C=IN/ST=MH/O=EasyEngine/localityName=Pune/commonName=*.%s/organizationalUnitName=EasyEngine/emailAddress=ee@easyengine.io/" -config %s -passin pass:',
			$key_path, $csr_path, $url, $conf_path
		) );
	}

	/**
	 * Trust the given certificate file.
	 *
	 * @param  string $crt_path
	 *
	 * @return void
	 */
	private function trust_certificate( $crt_path ) {
		if ( IS_DARWIN ) {
			EE::exec( sprintf(
				'sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain %s', $crt_path
			) );
		}
	}

	/**
	 * Move generated certificates to nginx-proxy certs directory.
	 *
	 * @param string $url      Domain for which cert is to be installed.
	 * @param string $key_path Path of cert key.
	 * @param string $crt_path Path of cert crt.
	 */
	private function move_certs_to_nginx_proxy( $url, $key_path, $crt_path ) {

		$key_dest_file = EE_ROOT_DIR . '/services/nginx-proxy/certs/' . $url . '.key';
		$crt_dest_file = EE_ROOT_DIR . '/services/nginx-proxy/certs/' . $url . '.crt';

		$this->fs->copy( $key_path, $key_dest_file );
		$this->fs->copy( $crt_path, $crt_dest_file );
	}
}
