<?php

if ( ! defined( 'SITE_TEMPLATE_ROOT' ) ) {
	define( 'SITE_TEMPLATE_ROOT', __DIR__ . '/templates' );
}

if ( ! class_exists( 'EE' ) ) {
	return;
}

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

EE::add_command( 'site', 'Site_Command' );
Site_Command::add_site_type( 'html', 'EE\Site\Type\HTML' );
