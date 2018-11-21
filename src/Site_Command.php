<?php

use EE\Dispatcher\CommandFactory;
use EE\Model\Site;

/**
 * Adds site related functionality to EasyEngine
 */
class Site_Command {

	/**
	 * @var array $site_types Array to hold all the registered site types and their callback classes.
	 */
	protected static $site_types = [];

	/**
	 * @var Site_Command $instance Hold an instance of the class.
	 */
	private static $instance;

	/**
	 * The singleton method to hold the instance of site-command.
	 *
	 * @return Site_Command
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

		if ( ! empty( $args[0] ) && 'cmd-dump' === $args[0] ) {
			$this->cmd_dump();

			return;
		}

		$last_arg = array_pop( $args );
		if ( substr( $last_arg, 0, 4 ) === 'http' ) {
			$last_arg = str_replace( [ 'https://', 'http://' ], '', $last_arg );
		}
		if ( ! empty( $last_arg ) ) {
			$args[] = EE\Utils\remove_trailing_slash( $last_arg );
		}

		$site_types = self::get_site_types();
		$assoc_args = $this->convert_old_args_to_new_args( $args, $assoc_args );

		// default site-type.
		$type = 'html';

		if ( in_array( reset( $args ), [ 'create', 'update' ], true ) || empty( $args ) ) {
			\EE\Auth\Utils\init_global_admin_tools_auth( false );
			if ( isset( $assoc_args['type'] ) ) {
				$type = $assoc_args['type'];
				unset( $assoc_args['type'] );
			}
		} else {
			$type = $this->determine_type( $type, $args );
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
	 * @param array $args          Command line arguments passed to site-command.
	 * @param string $default_type Default site-type.
	 *
	 * @throws \EE\ExitException
	 *
	 * @return string site-type.
	 */
	private function determine_type( $default_type, $args ) {

		$type = $default_type;

		$last_arg = array_pop( $args );

		$arg_search = Site::find( $last_arg, [ 'site_type' ] );

		if ( $arg_search ) {
			return $arg_search->site_type;
		}

		$site_name = EE\Site\Utils\get_site_name();
		if ( $site_name ) {
			if ( strpos( $last_arg, '.' ) !== false ) {
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

		if (
			( ! in_array( reset( $args ), [ 'create', 'update' ], true ) &&
			  ! empty( $args ) ) ||
			! empty( $assoc_args['type'] )
		) {
			return $assoc_args;
		}

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
		$unsupported_create_old_args = [
			'w3tc',
			'wpsc',
			'wpfc',
			'pagespeed',
			'hhvm',
		];

		$old_arg = array_intersect( $unsupported_create_old_args, array_keys( $assoc_args ) );

		$old_args = implode( ' --', $old_arg );
		if ( isset( $args[1] ) && 'create' === $args[1] && ! empty ( $old_arg ) ) {
			\EE::error( "Sorry, --$old_args flag/s is/are no longer supported in EE v4.\nPlease run `ee help " . implode( ' ', $args ) . '`.' );
		}

		return $assoc_args;
	}

	private function cmd_dump() {

		$site_types = self::get_site_types();
		$command    = EE::get_root_command();
		foreach ( $site_types as $name => $callback ) {
			$site_type_name = 'site_type_' . $name;
			$leaf_command   = CommandFactory::create( $site_type_name, $callback, $command );
			$command->add_subcommand( $site_type_name, $leaf_command );
		}
		$command_doc      = $this->command_to_array( $command );
		$site_command_key = array_search( 'site', array_column( $command_doc['subcommands'], 'name' ) );

		$site_subcommands = [];
		foreach ( $command_doc['subcommands'] as $key => $subcommand ) {
			if ( strpos( $subcommand['name'], 'site_type_' ) !== false ) {
				$get_type = explode( '_', $subcommand['name'] );
				if ( empty( $get_type[2] ) ) {
					continue;
				}
				$site_subcommands[ $subcommand['name'] ] = $subcommand['subcommands'];
				unset( $command_doc['subcommands'][ $key ] );

			}
		}
		$common_commands  = reset( $site_subcommands );
		$total_site_types = count( $site_subcommands );

		$comparator = [];
		foreach ( $common_commands as $command ) {
			$comparator[ $command['name'] ] = [ 'longdesc' => $command['longdesc'], 'common_count' => 0 ];
		}
		foreach ( $site_subcommands as $site_type_sub_commands ) {
			foreach ( $site_type_sub_commands as $site_type_sub_command ) {
				if ( ! empty( $comparator[ $site_type_sub_command['name'] ] ) && ( 0 === strcmp( $comparator[ $site_type_sub_command['name'] ]['longdesc'], $site_type_sub_command['longdesc'] ) ) ) {
					$comparator[ $site_type_sub_command['name'] ]['common_count'] ++;
				}
			}
		}

		foreach ( $comparator as $command_name => $data ) {
			if ( $total_site_types !== $data['common_count'] ) {
				$key_to_unset = array_search( $command_name, array_column( $common_commands, 'name' ) );
				if ( isset( $common_commands[ $key_to_unset ] ) ) {
					unset( $common_commands[ $key_to_unset ] );
					$common_commands = array_values( $common_commands );
				}
			}
		}
		$site_type_specific_commands = [];
		foreach ( $site_subcommands as $key_type => $sub_commands ) {
			$get_type = explode( '_', $key_type );
			if ( empty( $get_type[2] ) ) {
				continue;
			}
			$type = $get_type[2];

			$specific_commands           = array_udiff( $site_subcommands[ $key_type ], $common_commands, [
				$this,
				'compare_command_names'
			] );
			$mapped_array                = array_map( function ( $cmd ) use ( $type ) {
				$cmd['name'] = $cmd['name'] . ' --type=' . $type;

				return $cmd;
			}, $specific_commands );
			$site_type_specific_commands = array_merge( $site_type_specific_commands, $mapped_array );
		}

		$final_site_sub_commands = array_merge( $common_commands, $site_type_specific_commands );

		$names = array_column( $final_site_sub_commands, 'name' );
		array_multisort( $names, SORT_ASC, $final_site_sub_commands );

		$command_doc['subcommands'][ $site_command_key ]['subcommands'] = $final_site_sub_commands;

		echo json_encode( $command_doc );
	}

	private function compare_command_names( $a, $b ) {

		if ( $a['name'] === $b['name'] ) {
			return 0;
		} else {
			return ( $a['name'] < $b['name'] ? - 1 : 1 );
		}
	}

	private function command_to_array( $command ) {

		$dump = array(
			'name'        => $command->get_name(),
			'description' => $command->get_shortdesc(),
			'longdesc'    => $command->get_longdesc(),
		);
		foreach ( $command->get_subcommands() as $subcommand ) {
			$dump['subcommands'][] = $this->command_to_array( $subcommand );
		}
		if ( empty( $dump['subcommands'] ) ) {
			$dump['synopsis'] = (string) $command->get_synopsis();
		}

		return $dump;
	}
}
