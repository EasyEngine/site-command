<?php

if ( ! class_exists( 'EE' ) ) {
	return;
}

use EE\Dispatcher\CommandFactory;

/**
 * Callback function of `before_invoke:help` hook: Add routing for "ee help site" command before the invocation of help
 * command.
 *
 * @param array $args       Commandline arguments passed to help command.
 * @param array $assoc_args Associative arguments passed to help command.
 */
function ee_site_help_cmd_routing( $args, $assoc_args ) {

	if ( ( ! isset( $args[0] ) ) || ( 'site' !== $args[0] ) ) {
		return;
	}

	$site_types = Site_Command::get_site_types();
	if ( isset( $assoc_args['type'] ) ) {
		$type = $assoc_args['type'];
	} else {
		$type = 'html';
	}

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

EE::add_hook( 'before_invoke:help', 'ee_site_help_cmd_routing' );