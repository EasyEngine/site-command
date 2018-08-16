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

use EE\Model\Site;
use \Symfony\Component\Filesystem\Filesystem;

class Site_Command extends EE_Site_Command {

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
	 * @var bool $le Whether the site is letsencrypt or not.
	 */
	private $le;

	/**
	 * @var bool $skip_chk To skip site status check pre-installation.
	 */
	private $skip_chk;

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	public function __construct() {

		$this->level   = 0;
		pcntl_signal( SIGTERM, [ $this, "rollback" ] );
		pcntl_signal( SIGHUP, [ $this, "rollback" ] );
		pcntl_signal( SIGUSR1, [ $this, "rollback" ] );
		pcntl_signal( SIGINT, [ $this, "rollback" ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, "cleanup" ], [ &$this ] );
		$this->docker = EE::docker();
		$this->logger = EE::get_file_logger()->withName( 'site_command' );
		$this->fs     = new Filesystem();
	}

	/**
	 * Runs the standard WordPress site installation.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--letsencrypt]
	 * : Enables ssl via letsencrypt certificate.
	 *
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
		$this->site['url'] = strtolower( EE\Utils\remove_trailing_slash( $args[0] ) );
		$this->site['type'] = EE\Utils\get_flag_value( $assoc_args, 'type', 'html' );
		if ( 'html' !== $this->site['type'] ) {
			EE::error( sprintf( 'Invalid site-type: %s', $this->site['type'] ) );
		}

		if ( Site::find( $this->site['url'] ) ) {
			EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $this->site['url'] ) );
		}

		$this->le       = EE\Utils\get_flag_value( $assoc_args, 'letsencrypt' );
		$this->skip_chk = EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );

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
		if ( ! isset( $this->site['url'] ) ) {
			$args = EE\SiteUtils\auto_site_name( $args, 'site', __FUNCTION__ );
			$this->populate_site_info( $args );
		}
		$ssl    = $this->le ? 'Enabled' : 'Not Enabled';
		$prefix = ( $this->le ) ? 'https://' : 'http://';
		$info   = [
			[ 'Site', $prefix . $this->site['url'] ],
			[ 'Site Root', $this->site['root'] ],
			[ 'SSL', $ssl ],
		];

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

		EE::log( sprintf( 'Creating site %s.', $this->site['url'] ) );
		EE::log( 'Copying configuration files.' );

		$filter                 = [];
		$filter[]               = $this->site['type'];
		$filter[]               = $this->le;
		$site_docker            = new Site_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $default_conf_content = EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/default.conf.mustache', [ 'server_name' => $this->site['url'] ] );

		$env_data    = [
			'virtual_host' => $this->site['url'],
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

			EE\Siteutils\add_site_redirects( $this->site['url'], $this->le );

			EE::success( 'Configuration files copied.' );
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Function to create the site.
	 */
	private function create_site() {

		$this->site['root'] = WEBROOT . $this->site['url'];
		$this->level        = 1;
		try {
			EE\Siteutils\create_site_root( $this->site['root'], $this->site['url'] );
			$this->level = 2;
			EE\Siteutils\setup_site_network( $this->site['url'] );
			$this->level = 3;
			$this->configure_site_files();

			EE\Siteutils\start_site_containers( $this->site['root'] );

			EE\Siteutils\create_etc_hosts_entry( $this->site['url'] );
			if ( ! $this->skip_chk ) {
				$this->level = 4;
				EE\Siteutils\site_status_check( $this->site['url'] );
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

		if ( $this->le ) {
			$this->init_le( $this->site['url'], $this->site['root'], false );
		}
		$this->info( [ $this->site['url'] ], [] );
		$this->create_site_db_entry();
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {

		$ssl = $this->le ? 'letsencrypt' : null ;

		$site = Site::create([
			'site_url'     => $this->site['url'],
			'site_type'    => $this->site['type'],
			'site_fs_path' => $this->site['root'],
			'site_ssl'     => $ssl,
		]);

		try {
			if ( $site ) {
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

		$this->site['url'] = EE\Utils\remove_trailing_slash( $args[0] );

		$site = Site::find( $this->site['url'] );

		if ( $site ) {
			$this->site['type'] = $site->site_type;
			$this->site['root'] = $site->site_fs_path;
			$this->le           = $site->site_ssl;

		} else {
			EE::error( sprintf( 'Site %s does not exist.', $this->site['url'] ) );
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
		$this->delete_site( $this->level, $this->site['url'], $this->site['root'] );
		EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	private function rollback() {

		EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site( $this->level, $this->site['url'], $this->site['root'] );
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
