<?php

namespace EE\Site\Type;

use EE;
use EE\Model\Option;
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

	/**
	 * @var string $root_key Key of the root Certificate.
	 */
	private $root_key;

	/**
	 * @var string $root_pem Pem file of the root Certificate.
	 */
	private $root_pem;

	/**
	 * @var string $password Password used in generating the certs.
	 */
	private $password;

	public function __construct() {

		$this->fs       = new Filesystem();
		$this->conf_dir = EE_SERVICE_DIR . '/nginx-proxy/self-signed-certs';
		$this->root_key = $this->conf_dir . '/rootCA.key';
		$this->root_pem = $this->conf_dir . '/rootCA.pem';
		$this->password = Option::get( 'self-signed-secret' );
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

		$this->maybe_gen_root_cert();

		$key_path  = $this->conf_dir . '/' . $url . '.key';
		$csr_path  = $this->conf_dir . '/' . $url . '.csr';
		$crt_path  = $this->conf_dir . '/' . $url . '.crt';
		$conf_path = $this->conf_dir . '/' . $url . '.conf';
		$v3_path   = $this->conf_dir . '/' . $url . '.v3.ext';

		$this->build_certificate_conf( $conf_path, $url );
		$this->build_v3_ext_conf( $v3_path, $url );
		$this->generate_certificate( $key_path, $crt_path, $csr_path, $conf_path, $v3_path );
		$this->move_certs_to_nginx_proxy( $url, $key_path, $crt_path );

		// Cleanup files.
		$this->fs->remove( [ $key_path, $csr_path, $crt_path, $conf_path, $v3_path ] );
	}

	/**
	 * Generate root certificate if required.
	 */
	private function maybe_gen_root_cert() {

		$root_conf = $this->conf_dir . '/rootCA.conf';

		if ( $this->fs->exists( $this->root_pem ) && ! empty( $this->password ) ) {
			return true;
		}

		$this->password = \EE\Utils\random_password();

		$this->build_certificate_conf( $root_conf, 'EasyEngine' );

		EE::exec( sprintf( 'openssl genrsa -des3 -passout pass:%s -out %s 2048', $this->password, $this->root_key ) );
		EE::exec( sprintf( 'openssl req -x509 -new -nodes -key %s -sha256 -days 1024 -passin pass:%s -out %s -config %s', $this->root_key, $this->password, $this->root_pem, $root_conf ) );

		$this->trust_certificate( $this->root_pem );

		Option::set( 'self-signed-secret', $this->password );
		// Cleanup files.
		$this->fs->remove( $root_conf );
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
	 * Build the v3 ext config for the given URL.
	 *
	 * @param  string $url
	 *
	 * @return string
	 */
	private function build_v3_ext_conf( $path, $url ) {
		$config = str_replace( 'EE_DOMAIN', $url, file_get_contents( SITE_TEMPLATE_ROOT . '/config/self-signed-certs/v3.ext.mustache' ) );
		$this->fs->dumpFile( $path, $config );
	}

	/**
	 * Create the signing request for the TLS certificate.
	 *
	 * @param  string $key_path
	 *
	 * @return void
	 */
	private function generate_certificate( $key_path, $crt_path, $csr_path, $conf_path, $v3_path ) {

		EE::exec( sprintf( 'openssl req -new -sha256 -nodes -passout pass:%s -out %s -newkey rsa:2048 -keyout %s -config %s', $this->password, $csr_path, $key_path, $conf_path ) );

		EE::exec( sprintf( 'openssl x509 -req -in %s -CA %s -CAkey %s -CAcreateserial -passin pass:%s -out %s -days 1024 -sha256 -extfile %s', $csr_path, $this->root_pem, $this->root_key, $this->password, $crt_path, $v3_path ) );
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
		} else {
			$cert_path = '/usr/local/share/ca-certificates';
			$this->fs->copy( $this->root_pem, '/usr/local/share/ca-certificates/easyengine.crt' );
			EE::exec( 'update-ca-certificates ' );
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
