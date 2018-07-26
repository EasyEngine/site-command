<?php

declare( ticks=1 );

/**
 * Creates a simple WordPress Website.
 *
 * ## EXAMPLES
 *
 *     # Create simple WordPress site
 *     $ ee site create example.com --wp
 *
 * @package ee-cli
 */

use \Symfony\Component\Filesystem\Filesystem;

class Site_Command extends EE_Site_Command {
	private $command;
	private $site_name;
	private $site_root;
	private $site_type;
	private $db;
	private $docker;
	private $level;
	private $logger;
	private $le;
	private $skip_chk;
	private $le_mail;
	private $fs;

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

		\EE\Utils\delem_log( 'site create start' );
		EE::warning( 'This is a beta version. Please don\'t use it in production.' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site_name = strtolower( \EE\Utils\remove_trailing_slash( $args[0] ) );
		$this->site_type = \EE\Utils\get_flag_value( $assoc_args, 'type', 'html' );
		if ( 'html' !== $this->site_type ) {
			EE::error( "Invalid site-type: $this->site_type" );
		}

		if ( $this->db::site_in_db( $this->site_name ) ) {
			EE::error( "Site $this->site_name already exists. If you want to re-create it please delete the older one using:\n`ee site delete $this->site_name`" );
		}

		$this->le       = \EE\Utils\get_flag_value( $assoc_args, 'letsencrypt' );
		$this->skip_chk = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );

		\EE\SiteUtils\init_checks();

		EE::log( 'Configuring project.' );

		$this->create_site();
		\EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * @inheritdoc
	 */
	public function init_le( $site_name, $site_root, $wildcard = false ) {
		return parent::init_le( $site_name, $site_root, $wildcard );
	}

	/**
	 * @inheritdoc
	 */
	public function le( $args = [], $assoc_args = [], $wildcard = false ) {
		return parent::le( $args, $assoc_args, $wildcard );
	}

	/**
	 * @inheritdoc
	 */
	public function _list( $args, $assoc_args ) {
		parent::_list( $args, $assoc_args );
	}

	/**
	 * @inheritdoc
	 */
	public function delete( $args, $assoc_args ) {
		parent::delete( $args, $assoc_args );
	}

	/**
	 * @inheritdoc
	 */
	public function restart( $args, $assoc_args ) {
		parent::restart( $args, $assoc_args );
	}

	/**
	 * @inheritdoc
	 */
	public function reload( $args, $assoc_args ) {
		parent::reload( $args, $assoc_args );
	}


	/**
	 * @inheritdoc
	 */
	public function up( $args, $assoc_args ) {
		parent::up( $args, $assoc_args );
	}

	/**
	 * @inheritdoc
	 */
	public function down( $args, $assoc_args ) {
		parent::down( $args, $assoc_args );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 */
	public function info( $args, $assoc_args ) {
		\EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_name ) ) {
			$args = \EE\SiteUtils\auto_site_name( $args, $this->command, __FUNCTION__ );
			$this->populate_site_info( $args );
		}
		$ssl = $this->le ? 'Enabled' : 'Not Enabled';
		EE::log( "Details for site $this->site_name:" );
		$prefix = ( $this->le ) ? 'https://' : 'http://';
		$info   = array(
			array( 'Site', $prefix . $this->site_name ),
			array( 'Access mailhog', $prefix . $this->site_name . '/ee-admin/mailhog/' ),
			array( 'Site Root', $this->site_root ),
			array( 'SSL', $ssl ),
		);

		\EE\Utils\format_table( $info );

		\EE\Utils\delem_log( 'site info end' );
	}


	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files() {

		$site_conf_dir           = $this->site_root . '/config';
		$site_docker_yml         = $this->site_root . '/docker-compose.yml';
		$site_conf_env           = $this->site_root . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/default.conf';
		$site_src_dir            = $this->site_root . '/app/src';
		$process_user            = posix_getpwuid( posix_geteuid() );

		EE::log( "Creating site $this->site_name." );
		EE::log( 'Copying configuration files.' );

		$filter                 = array();
		$filter[]               = $this->site_type;
		$filter[]               = $this->le;
		$site_docker            = new Site_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter );
		$default_conf_content   = $default_conf_content = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/default.conf.mustache', [ 'server_name' => $this->site_name ] );

		$env_data    = [
			'virtual_host' => $this->site_name,
			'user_id'      => $process_user['uid'],
			'group_id'     => $process_user['gid'],
		];
		$env_content = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );

		try {
			$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			$this->fs->mkdir( $site_conf_dir );
			$this->fs->mkdir( $site_conf_dir . '/nginx' );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );

			$index_data = [
				'version'       => 'v' . EE_VERSION,
				'site_src_root' => $this->site_root . '/app/src',
			];
			$index_html = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/index.html.mustache', $index_data );
			$this->fs->mkdir( $site_src_dir );
			$this->fs->dumpFile( $site_src_dir . '/index.html', $index_html );

			EE\Siteutils\add_site_redirects( $this->site_name, $this->le );

			EE::success( 'Configuration files copied.' );
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Function to create the site.
	 */
	private function create_site() {
		$this->site_root = WEBROOT . $this->site_name;
		$this->level     = 1;
		try {
			EE\Siteutils\create_site_root( $this->site_root, $this->site_name );
			$this->level = 2;
			EE\Siteutils\setup_site_network( $this->site_name );
			$this->level = 3;
			$this->configure_site_files();

			EE\Siteutils\start_site_containers( $this->site_root );

			EE\Siteutils\create_etc_hosts_entry( $this->site_name );
			if ( ! $this->skip_chk ) {
				$this->level = 4;
				EE\Siteutils\site_status_check( $this->site_name );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}

		if ( $this->le ) {
			$this->init_le($this->site_name,$this->site_root,false);
		}
		$this->info( [ $this->site_name ], [] );
		$this->create_site_db_entry();
	}

	/**
	 * @inheritdoc
	 */
	public function delete_site( $level, $site_name, $site_root ) {
		parent::delete_site( $level, $site_name, $site_root );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {
		$ssl  = $this->le ? 1 : 0;
		$data = array(
			'sitename'   => $this->site_name,
			'site_type'  => $this->site_type,
			'site_path'  => $this->site_root,
			'is_ssl'     => $ssl,
			'created_on' => date( 'Y-m-d H:i:s', time() ),
		);

		try {
			if ( $this->db::insert( $data ) ) {
				EE::log( 'Site entry created.' );
			} else {
				throw new Exception( 'Error creating site entry in database.' );
			}
		}
		catch ( Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Populate basic site info from db.
	 */
	private function populate_site_info( $args ) {

		$this->site_name = \EE\Utils\remove_trailing_slash( $args[0] );

		if ( $this->db::site_in_db( $this->site_name ) ) {

			$db_select = $this->db::select( [], array( 'sitename' => $this->site_name ) );

			$this->site_type = $db_select[0]['site_type'];
			$this->site_root = $db_select[0]['site_path'];
			$this->le        = $db_select[0]['is_ssl'];

		} else {
			EE::error( "Site $this->site_name does not exist." );
		}
	}

	/**
	 * Catch and clean exceptions.
	 *
	 * @param Exception $e
	 */
	private function catch_clean( $e ) {
		\EE\Utils\delem_log( 'site cleanup start' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up.' );
		$this->delete_site( $this->level, $this->site_name, $this->site_root );
		\EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	private function rollback() {
		EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site( $this->level, $this->site_name, $this->site_root );
		}
		EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

	/**
	 * Shutdown function to catch and rollback from fatal errors.
	 */
	private function shutDownFunction() {
		$error = error_get_last();
		if ( isset( $error ) ) {
			if ( $error['type'] === E_ERROR ) {
				EE::warning( 'An Error occurred. Initiating clean-up.' );
				$this->logger->error( 'Type: ' . $error['type'] );
				$this->logger->error( 'Message: ' . $error['message'] );
				$this->logger->error( 'File: ' . $error['file'] );
				$this->logger->error( 'Line: ' . $error['line'] );
				$this->rollback();
			}
		}
	}
}
