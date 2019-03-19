<?php

declare( ticks=1 );

namespace EE\Site\Type;

use EE;
use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Utils\mustache_render;
use function EE\Utils\get_value_if_flag_isset;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\get_public_dir;
use function EE\Site\Utils\get_webroot;
use function EE\Utils\get_flag_value;

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
	 * @var bool $is_git_repo Check if git repo detail was provided for site creation.
	 */
	private $is_git_repo = false;

	/**
	 * @var string $git_repo SSH / HTTPS / USER:REPONAME of the repository.
	 */
	private $git_repo;

	public function __construct() {

		parent::__construct();
		$this->level  = 0;
		$this->logger = \EE::get_file_logger()->withName( 'html_type' );
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
	 * : Enables ssl on site.
	 * ---
	 * options:
	 *      - le
	 *      - self
	 *      - inherit
	 *      - custom
	 * ---
	 *
	 * [--ssl-key=<ssl-key-path>]
	 * : Path to the SSL key file.
	 *
	 * [--ssl-crt=<ssl-crt-path>]
	 * : Path ro the SSL crt file.
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
	 * : Set custom source directory for site inside htdocs.
	 *
	 * [--git]
	 * : Create your site using your git repo content. All content will be cloned into htdocs.
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
	 *     # Create html site with custom source directory inside htdocs ( SITE_ROOT/app/htdocs/src )
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

		$this->site_data['site_fs_path']           = WEBROOT . $this->site_data['site_url'];
		$this->site_data['site_ssl_wildcard']      = \EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$this->skip_status_check                   = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$this->site_data['site_container_fs_path'] = get_public_dir( $assoc_args );

		$this->site_data['site_ssl'] = get_value_if_flag_isset( $assoc_args, 'ssl', [ 'le', 'self', 'inherit', 'custom' ], 'le' );
		if ( 'custom' === $this->site_data['site_ssl'] ) {
			try {
				$this->validate_site_custom_ssl( get_flag_value( $assoc_args, 'ssl-key' ), get_flag_value( $assoc_args, 'ssl-crt' ) );
			} catch ( \Exception $e ) {
				$this->catch_clean( $e );
			}
		}

		\EE\Service\Utils\nginx_proxy_check();

		\EE::log( 'Configuring project.' );

		// Check if git repo URL was provided.
		$this->git_repo = \EE\Utils\get_flag_value( $assoc_args, 'git', '' );

		// Update variable data for further processing.
		if ( ! empty( $this->git_repo ) ) {
			$this->is_git_repo = true;
		}

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

			$site_src_dir = get_webroot( $site_src_dir, $this->site_data['site_container_fs_path'] );

			$index_data = [
				'version'       => 'v' . EE_VERSION,
				'site_src_root' => $site_src_dir,
			];

			// Create sample file if no git repo data was provided else clone into site root.
			if ( ! $this->is_git_repo ) {
				$index_html = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/index.html.mustache', $index_data );
				$this->fs->dumpFile( $site_src_dir . '/index.html', $index_html );
			} else {
				// Check if provided git repo is accessible.
				if ( ! $this->check_git_repo_access( $this->git_repo ) ) {
					EE::error( "Could not read from remote repository. Please make sure you have the correct access rights and the repository exists." );
				} else {
					EE::log( PHP_EOL . "Repo access check completed." . PHP_EOL );
					// Clone the repo content, defaults to htdocs directory.
					$this->complete_git_clone( $site_src_dir );
				}
			}

			// Assign www-data user ownership.
			chdir( $this->site_data['site_fs_path'] );
			EE::exec( sprintf( 'docker-compose exec --user="root" nginx bash -c "chown -R www-data: %s"', $this->site_data['site_container_fs_path'] ) );

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
	 *
	 * @param $assoc_args array of associative arguments.
	 */
	private function create_site() {

		$this->level = 1;
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

			if ( 'custom' === $this->site_data['site_ssl'] ) {
				$this->custom_site_ssl();
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

	/**
	 * Validates access for provided git repo URL.
	 *
	 * @param string $repo_url Git Repo URL.
	 *
	 * @return bool
	 * @throws EE\ExitException
	 */
	protected function check_git_repo_access( $repo_url ) {

		// Check git command availability.
		$git_check = \EE::exec( 'command -v git' );

		if ( ! $git_check ) {
			EE::error( 'git command not found! Please install git to clone github repo.' );
		}

		EE::log( PHP_EOL . 'Your repo will be cloned into the webroot.' . PHP_EOL );

		$check_repo_access = false;
		$is_valid_git_url  = false;

		// Check if valid git URL was provided.
		if ( 0 === strpos( $repo_url, 'git@github.com' ) ) {
			$is_valid_git_url = true;
		}
		if ( 0 === strpos( $repo_url, 'https://github.com' ) ) {
			$is_valid_git_url = true;
		}

		// If above checks fails, retry with USERNAME:REPONAME format.
		if ( false === $is_valid_git_url ) {
			$ssh_git_url      = 'git@github.com:' . $repo_url . '.git';
			$is_valid_git_url = EE::exec( 'git ls-remote --exit-code -h ' . $ssh_git_url );

			if ( $is_valid_git_url ) {
				$this->git_repo    = $ssh_git_url;
				$check_repo_access = true;
			} else {
				$https_git_url    = 'https://github.com/' . $repo_url . '.git';
				$is_valid_git_url = EE::exec( 'git ls-remote --exit-code -h ' . $https_git_url );
				if ( $is_valid_git_url ) {
					$this->git_repo    = $https_git_url;
					$check_repo_access = true;
				}
			}
		} else {
			$check_repo_access = true;
		}

		return $check_repo_access;
	}

	/**
	 * Clone the repo into provided destination.
	 *
	 * @param string $clone_dir Desitination directory for cloning git repo.
	 *
	 * @throws EE\ExitException
	 */
	protected function complete_git_clone( $clone_dir ) {

		$repo_clone_cmd    = 'git clone ' . $this->git_repo . " $clone_dir";
		$repo_clone_status = \EE::exec( $repo_clone_cmd, true, true );

		if ( ! $repo_clone_status ) {
			\EE::error( 'Git clone failed. Please check your repo access.' );
		}

		\EE::success( "Cloning complete." );
	}

}
