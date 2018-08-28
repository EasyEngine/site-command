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

		$site_config['url']  = strtolower( EE\Utils\remove_trailing_slash( $args[0] ) );
		$site_config['type'] = EE\Utils\get_flag_value( $assoc_args, 'type', 'html' );

		if ( 'html' !== $site_config['type'] ) {
			EE::error( sprintf( 'Invalid site-type: %s', $site_config['type'] ) );
		}

		if ( Site::find( $site_config['url'] ) ) {
			EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $site_config['url'] ) );
		}

		$site_config['ssl']          = EE\Utils\get_flag_value( $assoc_args, 'ssl' );
		$site_config['ssl_wildcard'] = EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$site_config['skip_chk']     = EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$site_config['root']         = WEBROOT . $site_config['url'];

		$this->site['url']  = $site_config['url'];
		$this->site['root'] = $site_config['root'];

		EE\SiteUtils\init_checks();

		EE::log( 'Configuring project.' );

		$this->create_site( $site_config );
		EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 */
	public function info( $args, $assoc_args, $site_config = [] ) {

		EE\Utils\delem_log( 'site info start' );

		if ( ! isset( $site_config['url'] ) ) {
			$args                        = EE\SiteUtils\auto_site_name( $args, 'site', __FUNCTION__ );
			$site_url                    = \EE\Utils\remove_trailing_slash( $args[0] );
			$site                        = $this->get_site( $site_url );
			$site_config['url']          = $site_url;
			$site_config['ssl']          = $site->site_ssl;
			$site_config['ssl_wildcard'] = $site->site_ssl_wildcard;
			$site_config['root']         = $site->site_fs_path;
		}


		$ssl    = $site_config['ssl'] ? 'Enabled' : 'Not Enabled';
		$prefix = ( $site_config['ssl'] ) ? 'https://' : 'http://';
		$info   = [
			[ 'Site', $prefix . $site_config['url'] ],
			[ 'Site Root', $site_config['root'] ],
			[ 'SSL', $ssl ],
		];

		if ( $site_config['ssl'] ) {
			$info[] = [ 'SSL Wildcard', $site_config['ssl_wildcard'] ? 'Yes' : 'No' ];
		}

		EE\Utils\format_table( $info );

		EE\Utils\delem_log( 'site info end' );
	}


	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files( $site_config ) {

		$site_conf_dir           = $site_config['root'] . '/config';
		$site_docker_yml         = $site_config['root'] . '/docker-compose.yml';
		$site_conf_env           = $site_config['root'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/default.conf';
		$site_src_dir            = $site_config['root'] . '/app/src';
		$process_user            = posix_getpwuid( posix_geteuid() );

		EE::log( sprintf( 'Creating site %s.', $site_config['url'] ) );
		EE::log( 'Copying configuration files.' );

		$filter                 = [];
		$filter[]               = $site_config['type'];
		$site_docker            = new Site_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $default_conf_content = EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/default.conf.mustache', [ 'server_name' => $site_config['url'] ] );

		$env_data    = [
			'virtual_host' => $site_config['url'],
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
				'site_src_root' => $site_config['root'] . '/app/src',
			];
			$index_html = EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/index.html.mustache', $index_data );
			$this->fs->mkdir( $site_src_dir );
			$this->fs->dumpFile( $site_src_dir . '/index.html', $index_html );

			EE::success( 'Configuration files copied.' );
		} catch ( Exception $e ) {
			$this->catch_clean( $e, $site_config );
		}
	}

	/**
	 * Function to create the site.
	 */
	private function create_site( $site_config ) {

		$this->level        = 1;
		try {
			EE\SiteUtils\create_site_root( $site_config['root'], $site_config['url'] );
			$this->level = 3;
			$this->configure_site_files( $site_config );

			EE\SiteUtils\start_site_containers( $site_config['root'] );

			EE\SiteUtils\create_etc_hosts_entry( $site_config['url'] );
			if ( ! $site_config['skip_chk'] ) {
				$this->level = 4;
				EE\SiteUtils\site_status_check( $site_config['url'] );
			}

			/*
			 * This adds http www redirection which is needed for issuing cert for a site.
			 * i.e. when you create example.com site, certs are issued for example.com and www.example.com
			 *
			 * We're issuing certs for both domains as it is needed in order to perform redirection of
			 * https://www.example.com -> https://example.com
			 *
			 * We add redirection config two times in case of ssl as we need http redirection
			 * when certs are being requested and http+https redirection after we have certs.
			 */
			EE\SiteUtils\add_site_redirects( $site_config['url'], false, 'inherit' === $site_config['ssl'] );
			EE\SiteUtils\reload_proxy_configuration();

			if ( $site_config['ssl'] ) {
				$this->init_ssl( $site_config['url'], $site_config['root'], $site_config['ssl'], $site_config['ssl_wildcard'] );
				EE\SiteUtils\add_site_redirects( $site_config['url'], true, 'inherit' === $site_config['ssl'] );
				EE\SiteUtils\reload_proxy_configuration();
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e, $site_config );
		}

		$this->create_site_db_entry( $site_config );
		$this->info( [ $site_config['url'] ], [], $site_config );
	}

	/**
	 * Function to save the site configuration entry into database.
	 *
	 * @param $site_config array Current site configuration
	 *
	 * @throws Exception
	 */
	private function create_site_db_entry( array $site_config ) {

		$ssl = $site_config['ssl'] ? 1 : 0;

		$site = Site::create([
			'site_url'          => $site_config['url'],
			'site_type'         => $site_config['type'],
			'site_fs_path'      => $site_config['root'],
			'site_ssl'          => false != $site_config['ssl'],          //Intentional weak condition check.
			'site_ssl_wildcard' => false != $site_config['ssl_wildcard'], //Intentional weak condition check.
			'created_on'        => date( 'Y-m-d H:i:s', time() ),
		]);

		try {
			if ( $site ) {
				EE::log( 'Site entry created.' );
			} else {
				throw new Exception( 'Error creating site entry in database.' );
			}
		} catch ( Exception $e ) {
			$this->catch_clean( $e, $site_config );
		}
	}

	/**
	 * Populate basic site info from db.
	 *
	 * @param string $site_url URL of site to find
	 *
	 * @throws \EE\ExitException
	 * @return Site
	 */
	private function get_site( string $site_url ) : Site {
		$site = Site::find( $site_url );

		if ( $site ) {
			$this->site['url'] = $site_url;
			$this->site['root'] = $site->site_fs_path;
			return $site;
		} else {
			EE::error( sprintf( 'Site %s does not exist.', $site_url ) );
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
	 * @param $e
	 * @param $site_config array Configuration of current site
	 */
	private function catch_clean( Exception $e, array $site_config ) {

		EE\Utils\delem_log( 'site cleanup start' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up.' );
		$this->delete_site( $this->level, $site_config['url'], $site_config['root'] );
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
