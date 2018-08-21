<?php

declare( ticks=1 );

/**
 * Creates a simple html Website.
 *
 * ## EXAMPLES
 *
 *     # Create simple html site
 *     $ ee site create example.com
 *
 * @package ee-cli
 */

use \Symfony\Component\Filesystem\Filesystem;

class Site_Command extends EE_Site_Command {

	/**
	 * @var string $command Name of the command being run.
	 */
	private $command;

	/**
	 * @var array $site Associative array containing essential site related information.
	 */
	private $site;

	/**
	 * @var object $docker Object to access `EE::docker()` functions.
	 */
	private $docker;

	/**
	 * @var int $level The level of creation in progress. Essential for rollback in case of failure.
	 */
	private $level;

	/**
	 * @var object $logger Object of logger.
	 */
	private $logger;

	/**
	 * @var bool $ssl Whether the site is has SSL enabled.
	 */
	private $ssl;

	/**
	 * @var bool $ssl_wildcard Whether the site SSL is wildcard.
	 */
	private $ssl_wildcard;

	/**
	 * @var bool $skip_chk To skip site status check pre-installation.
	 */
	private $skip_chk;

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	/**
	 * @var Object $db Object to access `EE::db()` functions.
	 */
	private $db;

	public function __construct() {

		$this->level   = 0;
		$this->command = 'site';
		pcntl_signal( SIGTERM, [ $this, "rollback" ] );
		pcntl_signal( SIGHUP, [ $this, "rollback" ] );
		pcntl_signal( SIGUSR1, [ $this, "rollback" ] );
		pcntl_signal( SIGINT, [ $this, "rollback" ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, "cleanup" ], [ &$this ] );
		$this->db     = EE::db();
		$this->docker = EE::docker();
		$this->logger = EE::get_file_logger()->withName( 'site_command' );
		$this->fs     = new Filesystem();
	}

	/**
	 * Runs the standard WordPress Site installation.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--ssl=<value>]
	 * : Enables ssl via letsencrypt certificate.
	 *
	 * [--wildcard]
	 * : Gets wildcard SSL .
	 * [--type=<type>]
	 * : Type of the site to be created. Values: html,php,wp.
	 *
	 * [--skip-status-check]
	 * : Skips site status check.
	 */
	public function create( $args, $assoc_args ) {

		EE\Utils\delem_log( 'site create start' );
		EE::warning( 'This is a beta version. Please don\'t use it in production.' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? [ 'NULL' ] : $assoc_args );
		$this->site['name'] = strtolower( EE\Utils\remove_trailing_slash( $args[0] ) );
		$this->site['type'] = EE\Utils\get_flag_value( $assoc_args, 'type', 'html' );
		if ( 'html' !== $this->site['type'] ) {
			EE::error( sprintf( 'Invalid site-type: %s', $this->site['type'] ) );
		}

		if ( $this->db::site_in_db( $this->site['name'] ) ) {
			EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $this->site['name'] ) );
		}

		$this->ssl          = EE\Utils\get_flag_value( $assoc_args, 'ssl' );
		$this->ssl_wildcard = EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$this->skip_chk      = EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );

		EE\SiteUtils\init_checks();

		EE::log( 'Configuring project.' );

		$this->create_site();
		EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 */
	public function info( $args, $assoc_args ) {

		EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site['name'] ) ) {
			$args = EE\SiteUtils\auto_site_name( $args, $this->command, __FUNCTION__ );
			$this->populate_site_info( $args );
		}
		$ssl    = $this->ssl ? 'Enabled' : 'Not Enabled';
		$prefix = ( $this->ssl ) ? 'https://' : 'http://';
		$info   = [
			[ 'Site', $prefix . $this->site['name'] ],
			[ 'Site Root', $this->site['root'] ],
			[ 'SSL', $ssl ],
		];

		if ( $this->ssl ) {
			$info[] = [ 'SSL Wildcard', $this->ssl_wildcard ? 'Yes': 'No' ];
		}

		EE\Utils\format_table( $info );

		EE\Utils\delem_log( 'site info end' );
	}


	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files() {

		$site_conf_dir           = $this->site['root'] . '/config';
		$site_docker_yml         = $this->site['root'] . '/docker-compose.yml';
		$site_conf_env           = $this->site['root'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/default.conf';
		$site_src_dir            = $this->site['root'] . '/app/src';
		$process_user            = posix_getpwuid( posix_geteuid() );

		EE::log( sprintf( 'Creating site %s.', $this->site['name'] ) );
		EE::log( 'Copying configuration files.' );

		$filter                 = [];
		$filter[]               = $this->site['type'];
		$site_docker            = new Site_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $default_conf_content = EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/default.conf.mustache', [ 'server_name' => $this->site['name'] ] );

		$env_data    = [
			'virtual_host' => $this->site['name'],
			'user_id'      => $process_user['uid'],
			'group_id'     => $process_user['gid'],
		];
		$env_content = EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );

		try {
			$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			$this->fs->mkdir( $site_conf_dir );
			$this->fs->mkdir( $site_conf_dir . '/nginx' );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );

			$index_data = [
				'version'       => 'v' . EE_VERSION,
				'site_src_root' => $this->site['root'] . '/app/src',
			];
			$index_html = EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/index.html.mustache', $index_data );
			$this->fs->mkdir( $site_src_dir );
			$this->fs->dumpFile( $site_src_dir . '/index.html', $index_html );

			EE::success( 'Configuration files copied.' );
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Function to create the site.
	 */
	private function create_site() {

		$this->site['root'] = WEBROOT . $this->site['name'];
		$this->level        = 1;
		try {
			EE\SiteUtils\create_site_root( $this->site['root'], $this->site['name'] );
			$this->level = 2;
			EE\SiteUtils\setup_site_network( $this->site['name'] );
			$this->level = 3;
			$this->configure_site_files();

			EE\SiteUtils\start_site_containers( $this->site['root'] );

			EE\SiteUtils\create_etc_hosts_entry( $this->site['name'] );
			if ( ! $this->skip_chk ) {
				$this->level = 4;
				EE\Siteutils\site_status_check( $this->site['name'] );
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
		EE::debug( 'Starting SSL procedure' );

		if ( 'le' === $this->ssl ) {
			EE::debug( 'Initializing LE' );
			$this->init_le( $this->site['name'], $this->site['root'], $this->ssl_wildcard );
		} elseif ( 'inherit' === $this->ssl ) {
			EE::debug( 'Inheriting certs' );
			$this->inherit_certs( $this->site['name'], $this->ssl_wildcard );
		} else {
			EE::error( "Unrecognized value in --ssl flag: $this->ssl" );
		}

		EE\Siteutils\add_site_redirects( $this->site['name'], $this->ssl );

		$this->info( [ $this->site['name'] ], [] );
		$this->create_site_db_entry();
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {

		$ssl  = $this->ssl ? 1 : 0;
		$ssl_wildcard = $this->ssl_wildcard ? 1 : 0;
		$data = [
			'sitename'     => $this->site['name'],
			'site_type'    => $this->site['type'],
			'site_path'    => $this->site['root'],
			'site_command' => $this->command,
			'is_ssl'       => $ssl,
			'site_ssl_wildcard' => $ssl_wildcard,
			'created_on'   => date( 'Y-m-d H:i:s', time() ),
		];

		try {
			if ( $this->db::insert( $data ) ) {
				EE::log( 'Site entry created.' );
			} else {
				throw new Exception( 'Error creating site entry in database.' );
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Populate basic site info from db.
	 */
	private function populate_site_info( $args ) {

		$this->site['name'] = EE\Utils\remove_trailing_slash( $args[0] );

		if ( $this->db::site_in_db( $this->site['name'] ) ) {

			$db_select = $this->db::select( [], [ 'sitename' => $this->site['name'] ], 'sites', 1 );

			$this->site['type'] = $db_select['site_type'];
			$this->site['root'] = $db_select['site_path'];
			$this->ssl           = $db_select['is_ssl'];
			$this->ssl_wildcard  = $db_select['site_ssl_wildcard'];

		} else {
			EE::error( sprintf( 'Site %s does not exist.', $this->site['name'] ) );
		}
	}

	/**
	 * @inheritdoc
	 */
	public function restart( $args, $assoc_args, $whitelisted_containers = [] ) {
		$whitelisted_containers = [ 'nginx' ];
		parent::restart( $args, $assoc_args, $whitelisted_containers );
	}

	/**
	 * @inheritdoc
	 */
	public function reload( $args, $assoc_args, $whitelisted_containers = [], $reload_commands = [] ) {
		$whitelisted_containers = [ 'nginx' ];
		parent::reload( $args, $assoc_args, $whitelisted_containers, $reload_commands = [] );
	}

	/**
	 * Catch and clean exceptions.
	 *
	 * @param Exception $e
	 */
	private function catch_clean( $e ) {

		EE\Utils\delem_log( 'site cleanup start' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up.' );
		$this->delete_site( $this->level, $this->site['name'], $this->site['root'] );
		EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	private function rollback() {

		EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site( $this->level, $this->site['name'], $this->site['root'] );
		}
		EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

	/**
	 * Shutdown function to catch and rollback from fatal errors.
	 */
	private function shutDownFunction() {

		$error = error_get_last();
		if ( isset( $error ) && $error['type'] === E_ERROR ) {
			EE::warning( 'An Error occurred. Initiating clean-up.' );
			$this->logger->error( 'Type: ' . $error['type'] );
			$this->logger->error( 'Message: ' . $error['message'] );
			$this->logger->error( 'File: ' . $error['file'] );
			$this->logger->error( 'Line: ' . $error['line'] );
			$this->rollback();
		}
	}
}
