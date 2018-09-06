<?php

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
			EE::warning( sprintf( '%s site-type had already been previously registered by %s. It is overridden by the new package class %s. Please update your packages to resolve this.', $name, self::$instance->site_types[ $name ], $callback ) );
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
	 * Performs site operations. Check `ee help site` for more info.
	 * Invoked function of site-type routing. Called when `ee site` is invoked.
	 * Performs the routing to respective site-type passed using either `--type=`,
	 * Or discovers the type from the site-name and fetches the type from it,
	 * Or falls down to the default site-type defined by the user,
	 * Or finally the most basic site-type and the default included in this package, type=html.
	 */
	public function __invoke( $args, $assoc_args ) {

		$site_types = self::get_site_types();
		$assoc_args = $this->convert_old_args_to_new_args( $args, $assoc_args );

		if ( isset( $assoc_args['type'] ) ) {
			$type = $assoc_args['type'];
			unset( $assoc_args['type'] );
		} else {
			$type = $this->determine_type( $args );
		}
		array_unshift( $args, 'site' );

		if ( ! isset( $site_types[ $type ] ) ) {
			$error = sprintf(
				'\'%1$s\' is not a registered site type of \'ee site --type=%1$s\'. See \'ee help site --type=%1$s\' for available subcommands.',
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

	/**
	 * Convert EE v3 args to the newer syntax of arguments.
	 *
	 * @param array $args       Commandline arguments passed.
	 * @param array $assoc_args Commandline associative arguments passed.
	 *
	 * @return array Updated $assoc_args.
	 */
	private function convert_old_args_to_new_args( $args, $assoc_args ) {

		$ee3_compat_array_map_to_type = [
			'wp'          => [ 'type' => 'wp' ],
			'wpsubdom'    => [ 'type' => 'wp', 'mu' => 'subdom' ],
			'wpsubdir'    => [ 'type' => 'wp', 'mu' => 'subdir' ],
			'wpredis'     => [ 'type' => 'wp', 'cache' => true ],
			'html'        => [ 'type' => 'html' ],
			'php'         => [ 'type' => 'php' ],
			'mysql'       => [ 'type' => 'php', 'with-db' => true ],
			'le'          => [ 'ssl' => 'le' ],
			'letsencrypt' => [ 'ssl' => 'le' ],
		];

		foreach ( $ee3_compat_array_map_to_type as $from => $to ) {
			if ( isset( $assoc_args[ $from ] ) ) {
				$assoc_args = array_merge( $assoc_args, $to );
				unset( $assoc_args[ $from ] );
			}
		}

		if ( ! empty( $assoc_args['type'] ) && 'wp' === $assoc_args['type'] ) {

			// ee3 backward compatibility flags
			$wp_compat_array_map = [
				'user'  => 'admin-user',
				'pass'  => 'admin-pass',
				'email' => 'admin-email',
			];

			foreach ( $wp_compat_array_map as $from => $to ) {
				if ( isset( $assoc_args[ $from ] ) ) {
					$assoc_args[ $to ] = $assoc_args[ $from ];
					unset( $assoc_args[ $from ] );
				}
			}
		}

		// backward compatibility error for deprecated flags.
		$unsupported_create_old_args = array(
			'w3tc',
			'wpsc',
			'wpfc',
			'pagespeed',
		);

		$old_arg = array_intersect( $unsupported_create_old_args, array_keys( $assoc_args ) );

		$old_args = implode( ' --', $old_arg );
		if ( isset( $args[1] ) && 'create' === $args[1] && ! empty ( $old_arg ) ) {
			\EE::error( "Sorry, --$old_args flag/s is/are no longer supported in EE v4.\nPlease run `ee help " . implode( ' ', $args ) . '`.' );
		}

		return $assoc_args;
	}
}
