<?php

use EE\Dispatcher\CommandFactory;

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

function Before_Help_Command() {

	$all_args   = EE::get_runner()->get_args();
	$args       = $all_args[0];
	$assoc_args = $all_args[1];

	if ( isset( $args[1] ) && 'site' === $args[1] ) {
		$site_types = Site_Command::get_site_types();
		if ( ! isset( $assoc_args['type'] ) ) {
			EE::error( 'No `--type` passed.' );
		}
		$type = $assoc_args['type'];
		if ( isset( $site_types[ $type ] ) ) {
			$callback = $site_types[ $type ];

			$command      = EE::get_root_command();
			$leaf_command = CommandFactory::create( 'site', $callback, $command );
			$command->add_subcommand( 'site', $leaf_command );
		} else {
			$error = sprintf(
				"'%s' is not a registered site type of 'ee site --type=%s'. See 'ee help site --type=%s' for available subcommands.",
				$type,
				$type,
				$type
			);
			EE::error( $error );
		}
	}
}

EE::add_command( 'site', 'Site_Command' );
EE::add_hook( 'before_invoke:help', 'Before_Help_Command' );
Site_Command::add_site_type( 'html', 'EE\Site\Type\HTML' );
