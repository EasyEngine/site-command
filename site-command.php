<?php

if ( ! defined( 'SITE_TEMPLATE_ROOT' ) ) {
	define( 'SITE_TEMPLATE_ROOT', __DIR__ . '/templates' );
}

if ( ! defined( 'GLOBAL_DB' ) ) {
	define( 'GLOBAL_DB', 'global-db' );
}

if ( ! defined( 'GLOBAL_FRONTEND_NETWORK' ) ) {
	define( 'GLOBAL_FRONTEND_NETWORK', 'ee-global-frontend-network' );
}
if ( ! defined( 'GLOBAL_BACKEND_NETWORK' ) ) {
	define( 'GLOBAL_BACKEND_NETWORK', 'ee-global-backend-network' );
}

if ( ! class_exists( 'EE' ) ) {
	return;
}

$sites_path = EE_ROOT_DIR . '/sites';

if ( ! is_dir( $sites_path ) ) {
	mkdir( $sites_path );
}

define( 'WEBROOT', \EE\Utils\trailingslashit( $sites_path ) );

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

EE::add_command( 'site', 'Site_Command' );
Site_Command::add_site_type( 'html', 'EE\Site\Type\HTML' );
