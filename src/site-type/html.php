<?php

declare( ticks=1 );

namespace EE\Site\Type;

use EE;
use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use EE\Utils as EE_Utils;
use EE\Site\Utils as Site_Utils;
use EE\Service\Utils as Service_Utils;

/**
 * Adds html site type to `site` command.
 *
 * @package ee-cli
 */
class HTML extends EE_Site_Command {

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
	 * @var bool $skip_status_check To skip site status check pre-installation.
	 */
	private $skip_status_check;

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	public function __construct() {

		parent::__construct();
		$this->level  = 0;
		$this->docker = EE::docker();
		$this->logger = EE::get_file_logger()->withName( 'html_type' );
		$this->fs     = new Filesystem();
	}

	/**
	 * Runs the standard HTML site installation.
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
	 *
	 * [--type=<type>]
	 * : Type of the site to be created. Values: html,php,wp etc.
	 *
	 * [--skip-status-check]
	 * : Skips site status check.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create html site
	 *     $ ee site create example.com
	 *
	 *     # Create html site with ssl from letsencrypt
	 *     $ ee site create example.com --ssl=le
	 *
	 *     # Create html site with wildcard ssl
	 *     $ ee site create example.com --ssl=le --wildcard
	 *
	 */
	public function create( $args, $assoc_args ) {

		$this->check_site_count();
		EE_Utils\delem_log( 'site create start' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? [ 'NULL' ] : $assoc_args );
		$this->site_data['site_url']  = strtolower( EE_Utils\remove_trailing_slash( $args[0] ) );
		$this->site_data['site_type'] = EE_Utils\get_flag_value( $assoc_args, 'type', 'html' );
		if ( 'html' !== $this->site_data['site_type'] ) {
			EE::error( sprintf( 'Invalid site-type: %s', $this->site_data['site_type'] ) );
		}
		if ( Site::find( $this->site_data['site_url'] ) ) {
			EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $this->site_data['site_url'] ) );
		}

		$this->site_data['site_ssl']          = EE_Utils\get_flag_value( $assoc_args, 'ssl' );
		$this->site_data['site_ssl_wildcard'] = EE_Utils\get_flag_value( $assoc_args, 'wildcard' );
		$this->skip_status_check              = EE_Utils\get_flag_value( $assoc_args, 'skip-status-check' );

		Service_Utils\nginx_proxy_check();

		EE::log( 'Configuring project.' );

		$this->create_site();
		EE_Utils\delem_log( 'site create end' );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 *
	 * ## EXAMPLES
	 *
	 *     # Display site info
	 *     $ ee site info example.com
	 *
	 */
	public function info( $args, $assoc_args ) {

		EE_Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_data['site_url'] ) ) {
			$args            = Site_Utils\auto_site_name( $args, 'site', __FUNCTION__ );
			$this->site_data = Site_Utils\get_site_info( $args, false );
		}
		$ssl    = $this->site_data['site_ssl'] ? 'Enabled' : 'Not Enabled';
		$prefix = ( $this->site_data['site_ssl'] ) ? 'https://' : 'http://';
		$info   = [
			[ 'Site', $prefix . $this->site_data['site_url'] ],
			[ 'Site Root', $this->site_data['site_fs_path'] ],
			[ 'SSL', $ssl ],
		];

		if ( $this->site_data['site_ssl'] ) {
			$info[] = [ 'SSL Wildcard', $this->site_data['site_ssl_wildcard'] ? 'Yes' : 'No' ];
		}

		EE_Utils\format_table( $info );

		EE_Utils\delem_log( 'site info end' );
	}


	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files() {

		$site_conf_dir           = $this->site_data['site_fs_path'] . '/config';
		$site_conf_env           = $this->site_data['site_fs_path'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/conf.d/main.conf';
		$site_src_dir            = $this->site_data['site_fs_path'] . '/app/htdocs';
		$process_user            = posix_getpwuid( posix_geteuid() );
		$custom_conf_dest        = $site_conf_dir . '/nginx/custom/user.conf';
		$custom_conf_source      = SITE_TEMPLATE_ROOT . '/config/nginx/user.conf.mustache';

		EE::log( sprintf( 'Creating site %s.', $this->site_data['site_url'] ) );
		EE::log( 'Copying configuration files.' );

		$default_conf_content = EE_Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', [ 'server_name' => $this->site_data['site_url'] ] );

		$env_data    = [
			'virtual_host' => $this->site_data['site_url'],
			'user_id'      => $process_user['uid'],
			'group_id'     => $process_user['gid'],
		];
		$env_content = EE_Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );

		try {
			$this->dump_docker_compose_yml( [ 'nohttps' => true ] );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			if ( ! IS_DARWIN ) {
				Site_Utils\start_site_containers( $this->site_data['site_fs_path'] );
			}
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );
			$this->fs->copy( $custom_conf_source, $custom_conf_dest );
			$this->fs->remove( $this->site_data['site_fs_path'] . '/app/html' );
			if ( IS_DARWIN ) {
				Site_Utils\start_site_containers( $this->site_data['site_fs_path'], [ 'nginx' ] );
			} else {
				Site_Utils\restart_site_containers( $this->site_data['site_fs_path'], [ 'nginx' ] );
			}
			$index_data = [
				'version'       => 'v' . EE_VERSION,
				'site_src_root' => $this->site_data['site_fs_path'] . '/app/htdocs',
			];
			$index_html = EE_Utils\mustache_render( SITE_TEMPLATE_ROOT . '/index.html.mustache', $index_data );
			$this->fs->dumpFile( $site_src_dir . '/index.html', $index_html );

			EE::success( 'Configuration files copied.' );
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Generate and place docker-compose.yml file.
	 *
	 * @param array $additional_filters Filters to alter docker-compose file.
	 *
	 * @ignorecommand
	 */
	public function dump_docker_compose_yml( $additional_filters = [] ) {

		$site_conf_dir           = $this->site_data['site_fs_path'] . '/config';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/conf.d/main.conf';

		$volumes = [
			[
				'name'            => 'htdocs',
				'path_to_symlink' => $this->site_data['site_fs_path'] . '/app',
				'container_path'  => '/var/www',
			],
			[
				'name'            => 'config_nginx',
				'path_to_symlink' => dirname( dirname( $site_nginx_default_conf ) ),
				'container_path'  => '/usr/local/openresty/nginx/conf',
				'skip_darwin'     => true,
			],
			[
				'name'            => 'config_nginx',
				'path_to_symlink' => $site_nginx_default_conf,
				'container_path'  => '/usr/local/openresty/nginx/conf/conf.d/main.conf',
				'skip_linux'      => true,
				'skip_volume'     => true,
			],
			[
				'name'            => 'log_nginx',
				'path_to_symlink' => $this->site_data['site_fs_path'] . '/logs/nginx',
				'container_path'  => '/var/log/nginx',
			],
		];

		if ( ! IS_DARWIN && empty( $this->docker->get_volumes_by_label( $this->site_data['site_url'] ) ) ) {
			$this->docker->create_volumes( $this->site_data['site_url'], $volumes );
		}

		$site_docker_yml = $this->site_data['site_fs_path'] . '/docker-compose.yml';

		$filter                = [];
		$filter[]              = $this->site_data['site_type'];
		$filter['site_prefix'] = $this->docker->get_docker_style_prefix( $this->site_data['site_url'] );
		$filter['is_ssl']      = $this->site_data['site_ssl'];

		foreach ( $additional_filters as $key => $addon_filter ) {
			$filter[ $key ] = $addon_filter;
		}

		$site_docker            = new Site_HTML_Docker();
		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter, $volumes );
		$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
	}

	/**
	 * Function to create the site.
	 */
	private function create_site() {

		$this->site_data['site_fs_path'] = WEBROOT . $this->site_data['site_url'];
		$this->level                     = 1;
		try {
			Site_Utils\create_site_root( $this->site_data['site_fs_path'], $this->site_data['site_url'] );
			$this->level = 3;
			$this->configure_site_files();

			if ( ! $this->site_data['site_ssl'] ) {
				Site_Utils\create_etc_hosts_entry( $this->site_data['site_url'] );
			}
			if ( ! $this->skip_status_check ) {
				$this->level = 4;
				Site_Utils\site_status_check( $this->site_data['site_url'] );
			}

			$this->www_ssl_wrapper();
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}

		$this->info( [ $this->site_data['site_url'] ], [] );
		$this->create_site_db_entry();
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {

		$ssl          = $this->site_data['site_ssl'] ? 1 : 0;
		$ssl_wildcard = $this->site_data['site_ssl_wildcard'] ? 1 : 0;

		$site = Site::create( [
			'site_url'          => $this->site_data['site_url'],
			'site_type'         => $this->site_data['site_type'],
			'site_fs_path'      => $this->site_data['site_fs_path'],
			'site_ssl'          => $ssl,
			'site_ssl_wildcard' => $ssl_wildcard,
			'created_on'        => date( 'Y-m-d H:i:s', time() ),
		] );

		try {
			if ( $site ) {
				EE::log( 'Site entry created.' );
			} else {
				throw new \Exception( 'Error creating site entry in database.' );
			}
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
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
	 * @param \Exception $e
	 */
	private function catch_clean( $e ) {

		EE_Utils\delem_log( 'site cleanup start' );
		EE::warning( $e->getMessage() );
		EE::warning( 'Initiating clean-up.' );
		$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		EE_Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	protected function rollback() {

		EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		}
		EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

}
