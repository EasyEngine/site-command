<?php

use EE\Dispatcher\CommandFactory;

/**
 * Add hook before the invocation of help command to appropriately handle the help for given site-type.
 */
function Before_Help_Command( $args, $assoc_args ) {

	if ( isset( $args[0] ) && 'site' === $args[0] ) {
		$site_types = Site_Command::get_site_types();
		if ( isset( $assoc_args['type'] ) ) {
			$type = $assoc_args['type'];
		} else {
			//TODO: get from config.
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
}

EE::add_hook( 'before_invoke:help', 'Before_Help_Command' );
