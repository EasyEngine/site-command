<?php

declare( ticks=1 );

namespace EE\Site\Type;

use EE;
use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Utils\get_flag_value;
use function EE\Utils\mustache_render;
use function EE\Utils\get_value_if_flag_isset;
use function EE\Utils\trailingslashit;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;

/**
 * Adds html site type to `site` command.
 *
 * @package ee-cli
 */
class HTML extends EE_Site_Command {

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
		$this->logger = \EE::get_file_logger()->withName( 'html_type' );
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
	 * [--ssl]
	 * : Enables ssl via letsencrypt certificate.
	 * ---
	 * options:
	 *      - le
	 *      - self
	 *      - inherit
	 * ---
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
	 * [--public-dir]
	 * : Set custom source directory for site.
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
	 *     # Create html site with self signed certificate
	 *     $ ee site create example.com --ssl=self
	 *
	 *     # Create html site with custom source directory
	 *     $ ee site create example.com --public-dir=src
	 *
	 */
	public function create( $args, $assoc_args ) {

		$this->check_site_count();
		\EE\Utils\delem_log( 'site create start' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? [ 'NULL' ] : $assoc_args );
		$this->site_data['site_url']  = strtolower( \EE\Utils\remove_trailing_slash( $args[0] ) );
		$this->site_data['site_type'] = \EE\Utils\get_flag_value( $assoc_args, 'type', 'html' );
		if ( 'html' !== $this->site_data['site_type'] ) {
			\EE::error( sprintf( 'Invalid site-type: %s', $this->site_data['site_type'] ) );
		}
		if ( Site::find( $this->site_data['site_url'] ) ) {
			\EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $this->site_data['site_url'] ) );
		}

		$this->site_data['site_fs_path']      = WEBROOT . $this->site_data['site_url'];
		$this->site_data['site_ssl_wildcard'] = \EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$this->skip_status_check              = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );

		$this->site_data['site_ssl'] = get_value_if_flag_isset( $assoc_args, 'ssl', [ 'le', 'self', 'inherit' ], 'le' );
		// Create container fs path for site.
		$public_root                               = \EE\Utils\get_flag_value( $assoc_args, 'public-dir' );
		$this->site_data['site_container_fs_path'] = empty( $public_root ) ? '/var/www/htdocs' : sprintf( '/var/www/htdocs/%s', trim( $public_root, '/' ) );

		\EE\Service\Utils\nginx_proxy_check();

		\EE::log( 'Configuring project.' );

		$this->create_site();
		\EE\Utils\delem_log( 'site create end' );
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

		\EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_data['site_url'] ) ) {
			$args            = auto_site_name( $args, 'site', __FUNCTION__ );
			$this->site_data = get_site_info( $args, false );
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

		\EE\Utils\format_table( $info );

		\EE\Utils\delem_log( 'site info end' );
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

		\EE::log( sprintf( 'Creating site %s.', $this->site_data['site_url'] ) );
		\EE::log( 'Copying configuration files.' );

		$data = [
			'server_name'   => $this->site_data['site_url'],
			'document_root' => $this->site_data['site_container_fs_path'],
		];
		$default_conf_content = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', $data );

		$env_data    = [
			'virtual_host' => $this->site_data['site_url'],
			'user_id'      => $process_user['uid'],
			'group_id'     => $process_user['gid'],
		];
		$env_content = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );

		try {
			$this->dump_docker_compose_yml( [ 'nohttps' => true ] );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			if ( ! IS_DARWIN ) {
				\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'] );
			}
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );
			$this->fs->copy( $custom_conf_source, $custom_conf_dest );
			$this->fs->remove( $this->site_data['site_fs_path'] . '/app/html' );
			if ( IS_DARWIN ) {
				\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], [ 'nginx' ] );
			} else {
				\EE\Site\Utils\restart_site_containers( $this->site_data['site_fs_path'], [ 'nginx' ] );
			}

			// Get site src path from container fs path.
			$public_dir_path = str_replace( '/var/www/htdocs/', '', trailingslashit( $this->site_data['site_container_fs_path'] ) );
			$site_src_dir    = empty( $public_dir_path ) ? $site_src_dir : $site_src_dir . '/' . rtrim( $public_dir_path, '/' );

			$index_data = [
				'version'       => 'v' . EE_VERSION,
				'site_src_root' => $site_src_dir,
			];

			$index_html = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/index.html.mustache', $index_data );
			$this->fs->dumpFile( $site_src_dir . '/index.html', $index_html );

			// Assign www-data user ownership.
			chdir( $this->site_data['site_fs_path'] );
			EE::exec( sprintf( 'docker-compose exec --user=root nginx chown -R www-data: %s', $this->site_data['site_container_fs_path'] ) );

			\EE::success( 'Configuration files copied.' );
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

		if ( ! IS_DARWIN && empty( \EE_DOCKER::get_volumes_by_label( $this->site_data['site_url'] ) ) ) {
			\EE_DOCKER::create_volumes( $this->site_data['site_url'], $volumes );
		}

		$site_docker_yml = $this->site_data['site_fs_path'] . '/docker-compose.yml';

		$filter                = [];
		$filter[]              = $this->site_data['site_type'];
		$filter['site_prefix'] = \EE_DOCKER::get_docker_style_prefix( $this->site_data['site_url'] );
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

		$this->level                     = 1;
		try {
			if ( 'inherit' === $this->site_data['site_ssl'] ) {
				$this->check_parent_site_certs( $this->site_data['site_url'] );
			}

			\EE\Site\Utils\create_site_root( $this->site_data['site_fs_path'], $this->site_data['site_url'] );
			$this->level = 3;
			$this->configure_site_files();

			if ( ! $this->site_data['site_ssl'] || 'self' === $this->site_data['site_ssl'] ) {
				\EE\Site\Utils\create_etc_hosts_entry( $this->site_data['site_url'] );
			}
			if ( ! $this->skip_status_check ) {
				$this->level = 4;
				\EE\Site\Utils\site_status_check( $this->site_data['site_url'] );
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

		$ssl_wildcard = $this->site_data['site_ssl_wildcard'] ? 1 : 0;

		$site = Site::create( [
			'site_url'               => $this->site_data['site_url'],
			'site_type'              => $this->site_data['site_type'],
			'site_fs_path'           => $this->site_data['site_fs_path'],
			'site_ssl'               => $this->site_data['site_ssl'],
			'site_ssl_wildcard'      => $ssl_wildcard,
			'created_on'             => date( 'Y-m-d H:i:s', time() ),
			'site_container_fs_path' => rtrim( $this->site_data['site_container_fs_path'], '/' ),
		] );

		try {
			if ( $site ) {
				\EE::log( 'Site entry created.' );
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

		\EE\Utils\delem_log( 'site cleanup start' );
		\EE::warning( $e->getMessage() );
		\EE::warning( 'Initiating clean-up.' );
		$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		\EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	protected function rollback() {

		\EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		}

		\EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

}
