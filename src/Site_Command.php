<?php

namespace EE\Site;
use EE\Dispatcher\CommandFactory;
use EE\Model\Site;

class Site_Command {

	/**
	 * @var array $site_types Array to hold all the registered site types and their callback classes.
	 */
	protected static $site_types = [];

	/**
	 * @var Object $instance Hold an instance of the class.
	 */
	private static $instance;

	/**
	 * The singleton method to hold the instance of site-command.
	 *
	 * @return Object|Site_Command
	 */
	public static function instance() {

		if ( ! isset( self::$instance ) ) {
			self::$instance = new Site_Command();
		}

		return self::$instance;
	}

	/**
	 * Function to register different site-types.
	 *
	 * @param string $name     Name of the site-type.
	 * @param string $callback The callback function/class for that type.
	 */
	public static function add_site_type( $name, $callback ) {

		if ( isset( self::$instance->site_types[ $name ] ) ) {
			EE::warning( sprintf( '%s site-type has already been previously registered by %s. It will be over-written by the new package class %s. Please update your packages to resolve this.', $name, self::$instance->site_types[ $name ], $callback ) );
		}
		self::$instance->site_types[ $name ] = $callback;
	}

	/**
	 * Method to get the list of registered site-types.
	 *
	 * @return array associative array of site-types and their callbacks.
	 */
	public static function get_site_types() {
		return self::$instance->site_types;
	}

	/**
	 * Invoked function of site-type routing. Called when `ee site` is invoked.
	 * Performs the routing to respective site-type passed using either `--type=`,
	 * Or discovers the type from the site-name and fetches the type from it,
	 * Or falls down to the default site-type defined by the user,
	 * Or finally the most basic site-type and the default included in this package, type=html.
	 */
	public function __invoke( $args, $assoc_args ) {

		$site_types = self::get_site_types();

		if ( isset( $assoc_args['type'] ) ) {
			$type = $assoc_args['type'];
			unset( $assoc_args['type'] );
		} else {
			$type = $this->determine_type( $args );
		}
		array_unshift( $args, 'site' );

		if ( ! isset( $site_types[ $type ] ) ) {
			$error = sprintf(
				"'%s' is not a registered site type of 'ee site --type=%s'. See 'ee help site --type=%s' for available subcommands.",
				$type,
				$type,
				$type
			);
			EE::error( $error );
		}

		$callback = $site_types[ $type ];

		$command      = EE::get_root_command();
		$leaf_command = CommandFactory::create( 'site', $callback, $command );
		$command->add_subcommand( 'site', $leaf_command );

		EE::run_command( $args, $assoc_args );
	}

	/**
	 * Function to determine type.
	 *
	 * Discovers the type from the site-name and fetches the type from it,
	 * Or falls down to the default site-type defined by the user,
	 * Or finally the most basic site-type and the default included in this package, type=html.
	 *
	 * @param array $args Command line arguments passed to site-command.
	 *
	 * @return string site-type.
	 */
	private function determine_type( $args ) {

		// default site-type
		$type = 'html';

		// TODO: get type from config file as below
		// $config_type = EE::get_config('type');
		// $type        = empty( $config_type ) ? 'html' : $config_type;

		$last_arg = array_pop( $args );
		if ( substr( $last_arg, 0, 4 ) === 'http' ) {
			$last_arg = str_replace( [ 'https://', 'http://' ], '', $last_arg );
		}
		$url_path = EE\Utils\remove_trailing_slash( $last_arg );

		$arg_search = Site::find( $url_path, [ 'site_type' ] );

		if ( $arg_search ) {
			return $arg_search->site_type;
		}

		$site_name = EE\Site\Utils\get_site_name();
		if ( $site_name ) {
			if ( strpos( $url_path, '.' ) !== false ) {
				$args[] = $site_name;
				EE::error(
					sprintf(
						'%s is not a valid site-name. Did you mean `ee site %s`?',
						$last_arg,
						implode( ' ', $args )
					)
				);
			}
			$type = Site::find( $site_name, [ 'site_type' ] )->site_type;
		}

		return $type;
	}
}
