<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use EE\Migration\Base;

class FixSslFlagForExistingLeCerts extends Base {
	public function __construct() {
		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	public function up() {
		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping fix_ssl_flag_for_existing_le_certs migration as it is not needed.' );

			return;
		}
		$certs_dir  = EE_ROOT_DIR . '/services/nginx-proxy/certs/';
		$conf_dir   = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/';
		$backup_dir = defined( 'EE_BACKUP_DIR' ) ? EE_BACKUP_DIR : EE_ROOT_DIR . '/.backup';
		if ( ! is_dir( $backup_dir ) ) {
			@mkdir( $backup_dir, 0755, true );
		}
		$log_file    = $backup_dir . '/.ssl-fix.log';
		$log_entries = [];
		foreach ( $this->sites as $site ) {
			$site_url      = $site->site_url;
			$crt           = $certs_dir . $site_url . '.crt';
			$key           = $certs_dir . $site_url . '.key';
			$chain         = $certs_dir . $site_url . '.chain.pem';
			$redirect_conf = $conf_dir . $site_url . '-redirect.conf';

			$crt_exists   = file_exists( $crt );
			$key_exists   = file_exists( $key );
			$chain_exists = file_exists( $chain );
			$db_ssl       = $site->site_ssl;
			$actions      = [];

			// If redirect conf exists but no certs, remove conf and reload nginx proxy
			if ( file_exists( $redirect_conf ) && ( ! $crt_exists || ! $key_exists || ! $chain_exists ) ) {
				EE::log( "Removing orphan redirect conf for $site_url and reloading nginx proxy." );
				@unlink( $redirect_conf );
				$actions[] = "Removed redirect conf: $redirect_conf";
				\EE\Site\Utils\reload_global_nginx_proxy();
			}

			if ( $crt_exists && $key_exists && $chain_exists ) {
				if ( empty( $db_ssl ) || $db_ssl !== 'le' ) {
					// Check if the cert is a valid Let's Encrypt cert using CertificateParser
					try {
						$crt_pem = file_get_contents( $crt );
						if ( ! function_exists( 'openssl_x509_parse' ) ) {
							EE::warning( "openssl_x509_parse() not available in PHP. Cannot check issuer for $site_url." );
							$actions[] = "openssl_x509_parse() not available, skipping Let's Encrypt detection";
						} else {
							$cert_data     = openssl_x509_parse( $crt_pem );
							$issuer_full   = isset( $cert_data['issuer'] ) ? $cert_data['issuer'] : [];
							$issuer_json   = json_encode( $issuer_full );
							$subject_cn    = isset( $cert_data['subject']['CN'] ) ? $cert_data['subject']['CN'] : '';
							$crt_pem_lines = implode( ' | ', array_slice( explode( "\n", $crt_pem ), 0, 2 ) );
							$actions[]     = "Cert issuer: $issuer_json";
							$actions[]     = "Cert subject CN: '$subject_cn'";
							$actions[]     = "Cert PEM first lines: $crt_pem_lines";

							// Check all issuer fields for 'Let's Encrypt'
							$le_found = false;
							foreach ( $issuer_full as $field => $value ) {
								if ( stripos( $value, "Let's Encrypt" ) !== false ) {
									$le_found = true;
									break;
								}
							}
							if ( $le_found ) {
								EE::log( "Updating SSL flag for site $site_url: found valid Let's Encrypt cert." );
								$site->site_ssl = 'le';
								$site->save();
								$actions[] = "Updated DB: set site_ssl=le (valid LE cert)";
							} else {
								$actions[] = "Cert is not from Let's Encrypt, no DB update";
							}
						}
					} catch ( \Exception $e ) {
						EE::debug( "Failed to parse certificate for $site_url: " . $e->getMessage() );
						$actions[] = "Failed to parse certificate: " . $e->getMessage();
					}
				}
			}

			if ( empty( $actions ) ) {
				$actions[] = 'No action needed';
			}
			$log_entries[] = sprintf(
				"%s [%s] DB: '%s', crt: %s, key: %s, chain: %s -- %s",
				date( 'c' ),
				$site_url,
				$db_ssl === null ? '' : $db_ssl,
				$crt_exists ? 'yes' : 'no',
				$key_exists ? 'yes' : 'no',
				$chain_exists ? 'yes' : 'no',
				implode( '; ', $actions )
			);
		}
		if ( $log_entries ) {
			file_put_contents( $log_file, implode( "\n", $log_entries ) . "\n", FILE_APPEND );
		}
	}

	public function down() {
		// No-op: This migration is not reversible.
	}
}
