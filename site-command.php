<?php

define( 'EE_CONFIG_TEMPLATE_ROOT', EE_ROOT . '/vendor/easyengine/site-command/templates/config' );

if ( ! class_exists( 'EE' ) ) {
	return;
}

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

EE::add_command( 'site', 'Site_Command' );
