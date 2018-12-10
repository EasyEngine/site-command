<?php

use EE\Model\Site;
use EE\Model\Option;

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
			'\'%1$s\' is not a registered site type of \'ee site --type=%1$s\'. See \'ee help site --type=%1$s\' for available subcommands.',
			$type
		);
		EE::error( $error );
	}

}

/**
 * Hook to cleanup redis entries if any.
 *
 * @param string $site_url The site to be cleaned up.
 */
function cleanup_redis_entries( $site_url ) {

	$site_data = Site::find( $site_url );

	if ( ! $site_data || GLOBAL_REDIS !== $site_data->cache_host ) {
		return;
	}

	\EE\Site\Utils\clean_site_cache( $site_data->site_url );

}

/**
 * Hook to cleanup publishing if any on site delete.
 *
 * @param string $site_url The site to be cleaned up.
 */
function cleanup_publishing( $site_url ) {

	$active_publish = Option::get( 'publish_site' );
	$publish_url    = Option::get( 'publish_url' );
	if ( $site_url !== $active_publish ) {
		return;
	}
	EE::exec( 'killall ngrok' );
	Option::set( 'publish_site', '' );
	Option::set( 'publish_url', '' );
}

EE::add_hook( 'site_cleanup', 'cleanup_redis_entries' );
EE::add_hook( 'site_cleanup', 'cleanup_publishing' );
EE::add_hook( 'before_invoke:help', 'ee_site_help_cmd_routing' );
