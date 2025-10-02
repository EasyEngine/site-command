<?php

namespace EE\Site\Type;

use AcmePhp\Ssl\Certificate;
use AcmePhp\Ssl\Parser\CertificateParser;
use EE;
use EE\Model\Cron;
use EE\Model\Site;
use EE\Model\Option;
use EE\Model\Auth;
use EE\Model\Whitelist;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Site\Cloner\Utils\check_site_access;
use function EE\Site\Cloner\Utils\copy_site_db;
use function EE\Site\Cloner\Utils\copy_site_files;
use function EE\Site\Cloner\Utils\get_transfer_details;
use function EE\Site\Utils\get_domains_of_site;
use function EE\Site\Utils\get_preferred_ssl_challenge;
use function EE\Site\Utils\update_site_db_entry;
use function EE\Utils\download;
use function EE\Utils\extract_zip;
use function EE\Utils\get_flag_value;
use function EE\Utils\get_config_value;
use function EE\Utils\delem_log;
use function EE\Utils\remove_trailing_slash;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\reload_global_nginx_proxy;
use function EE\Site\Utils\get_parent_of_alias;

/**
 * Base class for Site command
 *
 * @package ee
 */
abstract class EE_Site_Command {
	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	protected $fs;

	/**
	 * @var bool $wildcard Whether the site is letsencrypt type is wildcard or not.
	 */
	private $wildcard;

	/**
	 * @var bool $ssl Whether the site has SSL or not.
	 */
	private $ssl;

	/**
	 * @var string $le_mail Mail id to be used for letsencrypt registration and certificate generation.
	 */
	private $le_mail;

	/**
	 * @var array $site_data Associative array containing essential site related information.
	 */
	public $site_data;

	/**
	 * @var array $site_meta Associative array containing essential site meta related information.
	 */
	public $site_meta;

	public function __construct() {

		$this->fs = new Filesystem();
		pcntl_signal( SIGTERM, [ $this, 'rollback' ] );
		pcntl_signal( SIGHUP, [ $this, 'rollback' ] );
		pcntl_signal( SIGUSR1, [ $this, 'rollback' ] );
		pcntl_signal( SIGINT, [ $this, 'rollback' ] );
		$shutdown_handler = new Shutdown_Handler();
		register_shutdown_function( [ $shutdown_handler, 'cleanup' ], [ &$this ] );
	}

	/**
	 * Lists the created websites.
	 * abstract list
	 *
	 * [--enabled]
	 * : List only enabled sites.
	 *
	 * [--disabled]
	 * : List only disabled sites.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - yaml
	 *   - json
	 *   - count
	 *   - text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all sites
	 *     $ ee site list
	 *
	 *     # List enabled sites
	 *     $ ee site list --enabled
	 *
	 *     # List disabled sites
	 *     $ ee site list --disabled
	 *
	 *     # List all sites in JSON
	 *     $ ee site list --format=json
	 *
	 *     # Count all sites
	 *     $ ee site list --format=count
	 *
	 * @subcommand list
	 */
	public function _list( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site list start' );
		$format   = \EE\Utils\get_flag_value( $assoc_args, 'format' );
		$enabled  = \EE\Utils\get_flag_value( $assoc_args, 'enabled' );
		$disabled = \EE\Utils\get_flag_value( $assoc_args, 'disabled' );

		$sites = Site::all();

		if ( $enabled && ! $disabled ) {
			$sites = Site::where( 'site_enabled', true );
		} elseif ( $disabled && ! $enabled ) {
			$sites = Site::where( 'site_enabled', false );
		}

		if ( empty( $sites ) ) {
			\EE::error( 'No sites found!' );
		}

		if ( 'text' === $format ) {
			foreach ( $sites as $site ) {
				\EE::log( $site->site_url );
			}
		} else {
			$result = array_map(
				function ( $site ) {
					$site->site   = $site->site_url;
					$site->status = $site->site_enabled ? 'enabled' : 'disabled';

					return $site;
				}, $sites
			);

			$formatter = new \EE\Formatter( $assoc_args, [ 'site', 'status' ] );

			$formatter->display_items( $result );
		}

		\EE\Utils\delem_log( 'site list end' );
	}


	/**
	 * Deletes a website.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website to be deleted.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete site
	 *     $ ee site delete example.com
	 *
	 */
	public function delete( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site delete start' );
		$this->site_data = get_site_info( $args, false );

		$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
			'db_host' => $this->site_data['db_host'],
			'db_user' => $this->site_data['db_user'],
			'db_name' => $this->site_data['db_name'],
		];

		\EE::confirm( sprintf( 'Are you sure you want to delete %s?', $this->site_data['site_url'] ), $assoc_args );

		if ( $this->site_data['site_ssl'] ) {
			$all_domains = array_unique( array_merge(
					explode( ',', $this->site_data['alias_domains'] ),
					[ $this->site_data['site_url'] ]
				)
			);

			EE::log( 'Revoking certificate.' );

			try {
				$client = new Site_Letsencrypt();
				$client->revokeAuthorizationChallenges( $all_domains );
				$client->revokeCertificates( $all_domains );
			} catch ( \Exception $e ) {
				EE::warning( $e->getMessage() );
			}
			$client->removeDomain( $all_domains );
		}

		$this->delete_site( 5, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );

		\EE\Utils\delem_log( 'site delete end' );
	}

	/**
	 * Function to delete the given site.
	 *
	 * @param int $level           Level of deletion.
	 *                             Level - 0: No need of clean-up.
	 *                             Level - 1: Clean-up only the site-root.
	 *                             Level - 2: Try to remove network. The network may or may not have been created.
	 *                             Level - 3: Disconnect & remove network and try to remove containers. The containers
	 *                             may not have been created. Level - 4: Remove containers. Level - 5: Remove db entry.
	 * @param string $site_url     Name of the site to be deleted.
	 * @param string $site_fs_path Webroot of the site.
	 * @param array $db_data       Database host, user and password to cleanup db.
	 *
	 * @throws \EE\ExitException
	 */
	protected function delete_site( $level, $site_url, $site_fs_path, $db_data = [] ) {

		$this->fs = new Filesystem();

		if ( $level >= 3 ) {
			if ( \EE_DOCKER::docker_compose_down( $site_fs_path ) ) {
				\EE::log( "[$site_url] Docker Containers removed." );
			} else {
				\EE::exec( "docker rm -f $(docker ps -q -f=label=created_by=EasyEngine -f=label=site_name=$site_url)" );
				if ( $level > 3 ) {
					\EE::warning( 'Error in removing docker containers.' );
				}
			}
		}

		$volumes = \EE_DOCKER::get_volumes_by_label( $site_url );
		foreach ( $volumes as $volume ) {
			\EE::exec( 'docker volume rm ' . $volume );
		}

		$db_script_path = \EE\Utils\get_temp_dir() . 'db_exec';

		if ( $this->fs->exists( $db_script_path ) ) {
			try {
				$this->fs->remove( $db_script_path );
			} catch ( \Exception $e ) {
				\EE::debug( $e );
			}
		}

		if ( ! empty( $db_data['db_host'] ) ) {
			\EE\Site\Utils\cleanup_db( $db_data['db_host'], $db_data['db_name'] );
			\EE\Site\Utils\cleanup_db_user( $db_data['db_host'], $db_data['db_user'] );
		}

		if ( $this->fs->exists( $site_fs_path ) ) {
			try {
				$this->fs->remove( $site_fs_path );
			} catch ( \Exception $e ) {
				\EE::debug( $e );
				\EE::error( 'Could not remove site root. Please check if you have sufficient rights.' );
			}
			\EE::log( "[$site_url] site root removed." );
		}

		\EE\Site\Utils\remove_etc_hosts_entry( $site_url );

		$config_file_path = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/' . $site_url . '-redirect.conf';

		if ( $this->fs->exists( $config_file_path ) ) {
			try {
				$this->fs->remove( $config_file_path );
			} catch ( \Exception $e ) {
				\EE::debug( $e );
				\EE::error( 'Could not remove site redirection file. Please check if you have sufficient rights.' );
			}
		}

		$proxy_vhost_location        = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $site_url . '_location';
		$proxy_conf_location         = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/' . $site_url . '.conf';
		$proxy_vhost_location_subdom = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/*.' . $this->site_data['site_url'] . '_location';

		$alias_domains = [];

		if ( ! empty( $this->site_data['alias_domains'] ) ) {
			$alias_domains = array_diff( explode( ',', $this->site_data['alias_domains'] ), [
				$this->site_data['site_url'],
				'*.' . $this->site_data['site_url'],
			] );
		}

		$conf_locations = [ $proxy_conf_location, $proxy_vhost_location, $proxy_vhost_location_subdom ];

		$reload = false;

		foreach ( $conf_locations as $cl ) {

			if ( $this->fs->exists( $cl ) ) {
				$this->fs->remove( $cl );
				$reload = true;
			}
		}

		foreach ( $alias_domains as $ad ) {

			$proxy_vhost_location = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $ad . '_location';
			if ( $this->fs->exists( $proxy_vhost_location ) ) {
				$this->fs->remove( $proxy_vhost_location );
				$reload = true;
			}
		}

		if ( $reload ) {
			\EE\Site\Utils\reload_global_nginx_proxy();
			EE::exec( 'docker exec ' . EE_PROXY_TYPE . " bash -c 'rm -rf /var/cache/nginx/$site_url'" );
		}

		try {
			$crons = Cron::where( [ 'site_url' => $site_url ] );
			if ( ! empty( $crons ) ) {
				\EE::log( 'Deleting cron entry' );
				foreach ( $crons as $cron ) {
					$cron->delete();
				}
				\EE\Cron\Utils\update_cron_config();
			}
		} catch ( \Exception $e ) {
			\EE::debug( $e->getMessage() );
		}

		/**
		 * Execute before site db data cleanup and after site services cleanup.
		 * Note: This can be use to cleanup site data added by any package command.
		 *
		 * @param string $site_url Url of site which data is cleanup.
		 */
		\EE::do_hook( 'site_cleanup', $site_url );

		if ( $level > 4 ) {
			if ( $this->site_data['site_ssl'] ) {
				\EE::log( 'Removing ssl certs and other config files.' );
				$crt_file      = EE_ROOT_DIR . "/services/nginx-proxy/certs/$site_url.crt";
				$key_file      = EE_ROOT_DIR . "/services/nginx-proxy/certs/$site_url.key";
				$pem_file      = EE_ROOT_DIR . "/services/nginx-proxy/certs/$site_url.chain.pem";
				$conf_certs    = EE_ROOT_DIR . "/services/nginx-proxy/acme-conf/certs/$site_url";
				$conf_var      = EE_ROOT_DIR . "/services/nginx-proxy/acme-conf/var/$site_url";
				$htpasswd_file = EE_ROOT_DIR . "/services/nginx-proxy/htpasswd/$site_url";

				$delete_files = [ $conf_certs, $conf_var, $crt_file, $key_file, $pem_file, $htpasswd_file ];
				try {
					$this->fs->remove( $delete_files );
				} catch ( \Exception $e ) {
					\EE::warning( $e );
				}
			}

			$site_auth_file = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $site_url;
			if ( $this->fs->exists( $site_auth_file ) ) {
				try {
					$this->fs->remove( $site_auth_file );
				} catch ( \Exception $e ) {
					\EE::warning( $e );
				}
				reload_global_nginx_proxy();
			}

			$whitelists = Whitelist::where( [
				'site_url' => $site_url,
			] );

			foreach ( $whitelists as $whitelist ) {
				$whitelist->delete();
			}

			$auths = Auth::where( [
				'site_url' => $site_url,
			] );

			foreach ( $auths as $auth ) {
				$auth->delete();
			}

			if ( Site::find( $site_url )->delete() ) {
				\EE::log( 'Removed database entry.' );
			} else {
				\EE::error( 'Could not remove the database entry' );
			}
		}
		\EE::success( "Site $site_url deleted." );
	}

	/**
	 * Supports updating and upgrading site.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--ssl[=<ssl>]]
	 * : Update ssl on site
	 * ---
	 * options:
	 *   - le
	 *   - self
	 *   - inherit
	 *   - custom
	 *   - "off"
	 * ---
	 *
	 * [--wildcard]
	 * : Enable wildcard SSL on site.
	 *
	 * [--php=<php-version>]
	 * : PHP version for site. Currently only supports PHP 5.6, 7.0, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4.
	 * ---
	 * options:
	 *  - 5.6
	 *  - 7.0
	 *  - 7.2
	 *  - 7.3
	 *  - 7.4
	 *  - 8.0
	 *  - 8.1
	 *  - 8.2
	 *  - 8.3
	 *  - 8.4
	 * ---
	 *
	 * [--proxy-cache=<on-or-off>]
	 * : Enable or disable proxy cache on site.
	 * ---
	 * options:
	 *  - on
	 *  - off
	 * ---
	 *
	 * [--proxy-cache-max-size=<size-in-m-or-g>]
	 * : Max size for proxy-cache.
	 *
	 * [--proxy-cache-max-time=<time-in-s-or-m>]
	 * : Max time for proxy cache to last.
	 *
	 * [--proxy-cache-key-zone-size=<size-in-m>]
	 * : Size of proxy cache key zone.
	 *
	 * [--add-alias-domains=<comma-seprated-domains-to-add>]
	 * : Comma seprated list of domains to add to site's alias domains.
	 *
	 * [--delete-alias-domains=<comma-seprated-domains-to-delete>]
	 * : Comma seprated list of domains to delete from site's alias domains.
	 *
	 * ## EXAMPLES
	 *
	 *     # Add SSL to non-ssl site
	 *     $ ee site update example.com --ssl=le
	 *
	 *     # Add SSL to non-ssl site
	 *     $ ee site update example.com --ssl=le --wildcard
	 *
	 *     # Add self-signed SSL to non-ssl site
	 *     $ ee site update example.com --ssl=self
	 *
	 *     # Update PHP version of site.
	 *     $ ee site update example.com --php=8.0
	 *
	 *     # Update proxy cache of site.
	 *     $ ee site update example.com --proxy-cache=on
	 *
	 *     # Update proxy cache of site.
	 *     $ ee site update example.com --proxy-cache=on --proxy-cache-max-size=1g --proxy-cache-max-time=30s
	 *
	 *     # Add alias domains to a WordPress subdom site.
	 *     $ ee site update example.com --add-alias-domains='a.com,*.a.com,b.com,c.com'
	 *
	 *     # Delete alias domains from a WordPress subdom site.
	 *     $ ee site update example.com --delete-alias-domains='a.com,*.a.com,b.com'
	 */
	public function update( $args, $assoc_args ) {

		delem_log( 'site update start' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, true, true, false );
		$ssl             = get_flag_value( $assoc_args, 'ssl', false );
		$php             = get_flag_value( $assoc_args, 'php', false );
		$proxy_cache     = get_flag_value( $assoc_args, 'proxy-cache', false );
		$add_domains     = get_flag_value( $assoc_args, 'add-alias-domains', false );
		$delete_domains  = get_flag_value( $assoc_args, 'delete-alias-domains', false );

		if ( $ssl ) {
			$this->update_ssl( $assoc_args );
		}

		if ( $php ) {
			$this->update_php( $args, $assoc_args );
		}

		if ( $proxy_cache ) {
			$this->update_proxy_cache( $args, $assoc_args );
		}

		if ( $add_domains || $delete_domains ) {
			$this->update_alias_domains( $args, $assoc_args );
		}
	}


	/**
	 * Function to update alias domains of a site.
	 */
	protected function update_alias_domains( $args, $assoc_args ) {

		$add_domains    = get_flag_value( $assoc_args, 'add-alias-domains', false );
		$delete_domains = get_flag_value( $assoc_args, 'delete-alias-domains', false );

		try {

			$site            = $this->site_data;
			$array_data      = (array) $this->site_data;
			$this->site_data = reset( $array_data );

			// Validate data.
			$existing_alias_domains = [];
			$domains_to_add         = [];
			$domains_to_delete      = [];

			if ( ! empty( $this->site_data['alias_domains'] ) ) {
				$existing_alias_domains = explode( ',', $this->site_data['alias_domains'] );
			}
			if ( ! empty( $add_domains ) ) {
				$domains_to_add = explode( ',', $add_domains );
			}
			if ( ! empty( $delete_domains ) ) {
				$domains_to_delete = explode( ',', $delete_domains );
			}

			$already_added_domains = array_intersect( $existing_alias_domains, $domains_to_add );
			$domains_to_add        = array_diff( $domains_to_add, $existing_alias_domains );

			if ( empty( $domains_to_add ) && $add_domains ) {
				$already_added_domains = implode( ',', $already_added_domains );
				EE::error( "Alias domains: $already_added_domains is/are already present on the site." );
			}

			if ( ! empty ( $already_added_domains ) ) {
				$already_added_domains = implode( ',', $already_added_domains );
				EE::warning( "Following domains: $already_added_domains is/are already present on site, skipping addition of those." );
			}

			if ( ! empty( $domains_to_delete ) ) {
				// Handle primary site in deletion.
				$diff_delete_domains = array_diff( $domains_to_delete, $existing_alias_domains );
				if ( in_array( $this->site_data['site_url'], $domains_to_delete ) ) {
					EE::error( 'Primary site domain: `' . $this->site_data['site_url'] . '` can not be deleted.' );
				}
				if ( ! empty( $diff_delete_domains ) ) {
					EE::error( "Domains to delete is/are not a subset of already existing alias domains." );
				}
			}

			foreach ( $domains_to_add as $ad ) {

				if ( Site::find( $ad ) ) {
					\EE::error( sprintf( "%1\$s already exists as a site. If you want to add it to alias domain of this site, then please delete the existing site using:\n`ee site delete %1\$s`", $ad ) );
				}

				$parent_site = get_parent_of_alias( $ad );
				if ( ! empty( $parent_site ) ) {
					\EE::error( sprintf( "Site %1\$s already exists as an alias domain for site: %2\$s. Please delete it from alias domains of %2\$s if you want to add it as an alias domain for this site.", $ad, $parent_site ) );
				}
			}

			$final_alias_domains = array_merge( $existing_alias_domains, $domains_to_add );
			$final_alias_domains = array_diff( $final_alias_domains, $domains_to_delete );

			$this->site_data['alias_domains'] = implode( ',', $final_alias_domains );
			$is_ssl                           = $this->site_data['site_ssl'] ? true : false;
			$preferred_ssl_challenge          = get_preferred_ssl_challenge( get_domains_of_site( $this->site_data['site_url'] ) );
			$nohttps                          = $is_ssl && 'dns' !== $preferred_ssl_challenge;
			$this->dump_docker_compose_yml( [ 'nohttps' => $nohttps ] );
			\EE_DOCKER::docker_compose_up( $this->site_data['site_fs_path'], [ 'nginx' ] );
		} catch ( \Exception $e ) {
			EE::error( $e->getMessage() );
		}

		$this->site_data['alias_domains'] = implode( ',', $final_alias_domains );

		$all_domains = $final_alias_domains;
		array_push( $all_domains, $this->site_data['site_url'] );
		$all_domains = array_unique( $all_domains );

		foreach ( $all_domains as $domain ) {
			if ( '*.' . $this->site_data['site_url'] === $domain ) {
				$this->site_data['site_ssl_wildcard'] = "1";
			}
		}

		$client = new Site_Letsencrypt();

		$old_certs = $client->loadDomainCertificates( $all_domains );

		if ( $is_ssl ) {
			// Update SSL.
			EE::log( 'Updating and force renewing SSL certificate to accomodated alias domain changes.' );
			try {
				$this->ssl_renew( [ $this->site_data['site_url'] ], [ 'force' => true ] );
			} catch ( \Exception $e ) {
				EE::warning( 'Certificate could not be issued. Reverting back to original state.' );
				$this->enable( [ $this->site_data['site_url'] ], [ 'refresh' => 'true' ] );
				EE::error( $e->getMessage() );
			}
		}

		// Revoke old certificate which will not be used
		$client->revokeCertificates( $old_certs );

		chdir( $this->site_data['site_fs_path'] );
		// Required as env variables have changed.
		\EE_DOCKER::docker_compose_up( $this->site_data['site_fs_path'], [ 'nginx' ] );
		EE::success( 'Alias domains updated on site ' . $this->site_data['site_url'] . '.' );

		try {
			update_site_db_entry( $this->site_data['site_url'], $this->site_data );
		} catch ( \Exception $e ) {
			EE::error( $e->getMessage() );
		}

		if ( ! empty( $this->site_data['proxy_cache'] ) && 'on' === $this->site_data['proxy_cache'] ) {
			EE::log( 'As proxy cache is enabled on this site, updating config to enable it in newly added alias domains.' );
			$this->site_data = get_site_info( $args, true, true, false );
			$assoc_args      = [
				'proxy-cache' => 'on',
				'force'       => true,
			];
			$this->update_proxy_cache( $args, $assoc_args );
		}
		delem_log( 'site alias domains update end' );
	}

	/**
	 * Function to enable/disable proxy cache of a site.
	 */
	protected function update_proxy_cache( $args, $assoc_args, $call_on_create = false ) {

		$proxy_cache = get_flag_value( $assoc_args, 'proxy-cache', 'on' );
		$force       = get_flag_value( $assoc_args, 'force', false );


		if ( ! $call_on_create ) {

			if ( $proxy_cache === $this->site_data->proxy_cache && ! $force ) {
				EE::error( 'Site ' . $this->site_data->site_url . ' already has proxy cache: ' . $proxy_cache );
			}
		}

		$log_message = ( $proxy_cache === 'on' ) ? 'Enabling' : 'Disabling';

		try {
			if ( ! $call_on_create ) {
				$site            = $this->site_data;
				$array_data      = (array) $this->site_data;
				$this->site_data = reset( $array_data );
			}
			$proxy_conf_location         = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/' . $this->site_data['site_url'] . '.conf';
			$proxy_vhost_location        = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $this->site_data['site_url'] . '_location';
			$proxy_vhost_location_subdom = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/*.' . $this->site_data['site_url'] . '_location';

			$proxy_cache_time          = strtolower( get_flag_value( $assoc_args, 'proxy-cache-max-time', '1s' ) );
			$proxy_cache_size          = strtolower( get_flag_value( $assoc_args, 'proxy-cache-max-size', '256m' ) );
			$proxy_cache_key_zone_size = strtolower( get_flag_value( $assoc_args, 'proxy-cache-key-zone-size', '10m' ) );
			$wrong_time                = false;
			$wrong_size                = false;
			$wrong_key_size            = false;

			in_array( substr( $proxy_cache_time, - 1 ), [ 's', 'm' ] ) ?: $wrong_time = true;
			in_array( substr( $proxy_cache_size, - 1 ), [ 'm', 'g' ] ) ?: $wrong_size = true;
			in_array( substr( $proxy_cache_key_zone_size, - 1 ), [ 'm' ] ) ?: $wrong_key_size = true;

			is_numeric( substr( $proxy_cache_time, 0, - 1 ) ) ?: $wrong_time = true;
			is_numeric( substr( $proxy_cache_size, 0, - 1 ) ) ?: $wrong_size = true;
			is_numeric( substr( $proxy_cache_key_zone_size, 0, - 1 ) ) ?: $wrong_key_size = true;

			if ( $wrong_time ) {
				EE::warning( "Wrong value `$proxy_cache_time` supplied to param: `proxy-cache-max-time`, replacing it with default:1s" );
				$proxy_cache_time = '1s';
			}

			if ( $wrong_size ) {
				EE::warning( "Wrong value `$proxy_cache_size` supplied to param: `proxy-cache-max-size`, replacing it with default:256m" );
				$proxy_cache_size = '256m';
			}

			if ( $wrong_key_size ) {
				EE::warning( "Wrong value `$proxy_cache_key_zone_size` supplied to param: `proxy-cache-key-zone-size`, replacing it with default:10m" );
				$proxy_cache_key_zone_size = '10m';
			}

			if ( $force ) {
				EE::log( $log_message . ' proxy cache for alias domains of site: ' . $this->site_data['site_url'] );
			} else {
				EE::log( $log_message . ' proxy cache for: ' . $this->site_data['site_url'] );
			}

			$alias_domains = [];
			if ( ! empty( $this->site_data['alias_domains'] ) ) {
				$alias_domains = array_diff( explode( ',', $this->site_data['alias_domains'] ), [
					$this->site_data['site_url'],
					'*.' . $this->site_data['site_url'],
				] );
			}

			if ( 'on' === $proxy_cache ) {

				$sanitized_site_url = str_replace( '.', '-', $this->site_data['site_url'] );

				$data               = [
					'site_url'                  => $this->site_data['site_url'],
					'sanitized_site_url'        => $sanitized_site_url,
					'proxy_cache_size'          => $proxy_cache_size,
					'proxy_cache_key_zone_size' => $proxy_cache_key_zone_size,
					'proxy_cache_time'          => $proxy_cache_time,
					'easyengine_version'        => 'v' . EE_VERSION,
				];
				$proxy_conf_content = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx-proxy/proxy.conf.mustache', $data );

				if ( ! $force ) {
					$this->fs->dumpFile( $proxy_conf_location, $proxy_conf_content );
				}

				$proxy_vhost_content = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx-proxy/vhost_location.conf.mustache', $data );
				$this->fs->dumpFile( $proxy_vhost_location, $proxy_vhost_content );

				if ( 'subdom' === $this->site_data['app_sub_type'] ) {
					$this->fs->dumpFile( $proxy_vhost_location_subdom, $proxy_vhost_content );
				}

				foreach ( $alias_domains as $ad ) {

					$proxy_vhost_location = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $ad . '_location';
					$proxy_vhost_content  = \EE\Utils\mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx-proxy/vhost_location.conf.mustache', $data );
					$this->fs->dumpFile( $proxy_vhost_location, $proxy_vhost_content );
				}
			} else {
				$reload = false;


				$conf_locations = [ $proxy_conf_location, $proxy_vhost_location, $proxy_vhost_location_subdom ];

				$reload = false;

				foreach ( $conf_locations as $cl ) {

					if ( $this->fs->exists( $cl ) ) {
						$this->fs->remove( $cl );
						$reload = true;
					}
				}

				foreach ( $alias_domains as $ad ) {

					$proxy_vhost_location = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $ad . '_location';
					if ( $this->fs->exists( $proxy_vhost_location ) ) {
						$this->fs->remove( $proxy_vhost_location );
						$reload = true;
					}
				}

				if ( $reload ) {
					\EE\Site\Utils\reload_global_nginx_proxy();
					EE::exec( 'docker exec ' . EE_PROXY_TYPE . " bash -c 'rm -rf /var/cache/nginx/" . $this->site_data['site_url'] . "'" );
				}
			}
			if ( EE::exec( 'docker exec ' . EE_PROXY_TYPE . " bash -c 'nginx -t'" ) ) {
				\EE\Site\Utils\reload_global_nginx_proxy();
				EE::exec( 'docker restart ' . EE_PROXY_TYPE );
			} else {
				$this->fs->remove( $proxy_conf_location );
				$this->fs->remove( $proxy_vhost_location );
				$this->fs->remove( $proxy_vhost_location_subdom );
				\EE\Site\Utils\reload_global_nginx_proxy();
			}
		} catch ( \Exception $e ) {
			EE::error( $e->getMessage() );
		}
		if ( ! $call_on_create ) {
			$site->proxy_cache = $proxy_cache;
			$site->save();
		}
		EE::success( 'Proxy cache update on site ' . $this->site_data['site_url'] . ' completed.' );
		delem_log( 'site proxy cache update end' );
	}

	/**
	 * Function to update php version of a site.
	 */
	protected function update_php( $args, $assoc_args ) {

		$php_version = get_flag_value( $assoc_args, 'php', false );

		if ( $php_version === $this->site_data->php_version ) {
			EE::error( 'Site ' . $this->site_data->site_url . ' is already at PHP version: ' . $php_version );
		}

		EE::log( 'Starting php version update for: ' . $this->site_data->site_url );

		try {
			$old_php_version              = $this->site_data->php_version;
			$this->site_data->php_version = $php_version;
			$no_https                     = $this->site_data->site_ssl ? false : true;
			$site                         = $this->site_data;
			$array_data                   = ( array ) $this->site_data;
			$this->site_data              = reset( $array_data );

			EE::log( 'Taking backup of old php config.' );
			$site_backup_dir     = $this->site_data['site_fs_path'] . '/.backup';
			$php_conf_backup_dir = $site_backup_dir . '/config/php-' . $old_php_version;
			$php_conf_dir        = $this->site_data['site_fs_path'] . '/config/php';
			$php_confd_dir       = $this->site_data['site_fs_path'] . '/config/php/php/conf.d';
			$this->fs->mkdir( $php_conf_backup_dir );
			$this->fs->mirror( $php_conf_dir, $php_conf_backup_dir );

			$this->dump_docker_compose_yml( [ 'nohttps' => $no_https ] );
			EE::log( 'Starting site with new PHP version: ' . $php_version . '. This may take sometime.' );
			$this->enable( $args, [ 'force' => true ] );

			EE::log( 'Updating php config.' );
			$temp_dir     = EE\Utils\get_temp_dir();
			$zip_path     = $temp_dir . "phpconf-$php_version.zip";
			$unzip_folder = $temp_dir . "php-$php_version";

			$scanned_files = scandir( $php_conf_dir );
			$diff          = [ '.', '..' ];

			$removal_files = array_diff( $scanned_files, $diff );

			$this->fs->copy( SITE_TEMPLATE_ROOT . '/config/php-fpm/php' . str_replace( '.', '', $php_version ) . '.zip', $zip_path );
			extract_zip( $zip_path, $unzip_folder );

			chdir( $php_conf_dir );
			$this->fs->remove( $removal_files );
			$this->fs->mirror( $unzip_folder, $php_conf_dir );
			$this->fs->remove( [ $zip_path, $unzip_folder ] );
			$this->fs->chown( $php_confd_dir, 'www-data', true );

			// Recover previous custom configs.
			EE::log( 'Re-applying previous custom.ini and easyengine.conf changes.' );
			$this->fs->copy( $php_conf_backup_dir . '/php/conf.d/custom.ini', $php_conf_dir . '/php/conf.d/custom.ini', true );
			$this->fs->copy( $php_conf_backup_dir . '/php-fpm.d/easyengine.conf', $php_conf_dir . '/php-fpm.d/easyengine.conf', true );

			if ( '5.6' == $old_php_version ) {
				$this->sendmail_path_update( true );
			} elseif ( '5.6' == $php_version ) {
				$this->sendmail_path_update( false );
			}

			EE::exec( "chown -R www-data: $php_conf_dir" );

		} catch ( \Exception $e ) {
			EE::error( $e->getMessage() );
		}
		$site->save();
		$this->restart( $args, [ 'php' => true ] );
		EE::success( 'Updated site ' . $this->site_data['site_url'] . ' to PHP version: ' . $php_version );
		delem_log( 'site php version update end' );
	}

	protected function sendmail_path_update( $msmtp ) {

		$custom_ini_path = $this->site_data['site_fs_path'] . '/config/php/php/conf.d/custom.ini';
		$custom_ini_data = file( $custom_ini_path );
		if ( $msmtp ) {
			$custom_ini_data = array_map( function ( $custom_ini_data ) {
				$sendmail_path = 'sendmail_path = /usr/bin/msmtp -t';

				return stristr( $custom_ini_data, 'sendmail_path' ) ? "$sendmail_path\n" : $custom_ini_data;
			}, $custom_ini_data );
		} else {
			$custom_ini_data = array_map( function ( $custom_ini_data ) {
				$sendmail_path = 'sendmail_path = /usr/sbin/sendmail -t -i -f no-reply@example.com';

				return stristr( $custom_ini_data, 'sendmail_path' ) ? "$sendmail_path\n" : $custom_ini_data;
			}, $custom_ini_data );
		}
		file_put_contents( $custom_ini_path, implode( '', $custom_ini_data ) );
	}

	/**
	 * Function to update ssl of a site.
	 */
	protected function update_ssl( $assoc_args ) {

		$ssl      = EE\Utils\get_value_if_flag_isset( $assoc_args, 'ssl', 'le' );
		$wildcard = get_flag_value( $assoc_args, 'wildcard', false );

		if ( $ssl === 'off' ) {
			$ssl = false;
		}

		if ( ! $this->site_data->site_ssl_wildcard && $wildcard ) {
			EE::error( 'Update from normal SSL to wildcard SSL is not supported yet.' );
		}

		if ( $this->site_data->site_ssl_wildcard && ! $wildcard ) {
			EE::error( 'Update from wildcard SSL to normal SSL is not supported yet.' );
		}

		if ( $this->site_data->site_ssl && $ssl ) {
			EE::error( 'SSL is already enabled on ' . $this->site_data->site_url );
		}

		if ( ! $this->site_data->site_ssl && ! $ssl ) {
			EE::error( 'SSL is already disabled on ' . $this->site_data->site_url );
		}

		if ( ! $ssl && $wildcard ) {
			EE::error( 'You cannot use --wildcard flag with --ssl=off' );
		}

		EE::log( 'Starting SSL update for: ' . $this->site_data->site_url );
		try {
			$this->site_data->site_ssl          = $ssl;
			$this->site_data->site_ssl_wildcard = $wildcard ? 1 : 0;

			$site                        = $this->site_data;
			$array_data                  = ( array ) $this->site_data;
			$this->site_data             = reset( $array_data );
			$this->site_data['site_ssl'] = $ssl;

			if ( $ssl ) {
				$this->www_ssl_wrapper( [ 'nginx' ] );
			} else {
				$this->disable_ssl();
			}

			$site->site_ssl = $ssl;
		} catch ( \Exception $e ) {
			EE::error( $e->getMessage() );
		}

		$site->save();

		if ( $ssl ) {
			EE::success( 'Enabled SSL for ' . $this->site_data['site_url'] );
		} else {
			EE::success( 'Disabled SSL for ' . $this->site_data['site_url'] );
		}

		delem_log( 'site ssl update end' );
	}

	/**
	 * Disables SSL on a site.
	 *
	 * @throws \Exception
	 */
	private function disable_ssl() {

		$this->dump_docker_compose_yml( [ 'nohttps' => true ] );

		\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], [ 'nginx' ] );
		\EE\Site\Utils\reload_global_nginx_proxy();
	}

	/**
	 * Enables a website. It will start the docker containers of the website if they are stopped.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be enabled.
	 *
	 * [--force]
	 * : Force execution of site enable.
	 *
	 * [--verify]
	 * : Verify if required global services are working.
	 *
	 * [--refresh]
	 * : Force enable after regenerating docker-compose.yml of a site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable site
	 *     $ ee site enable example.com
	 *
	 *     # Enable site with verification of dependent global services. (Note: This takes longer time to enable the
	 *     site.)
	 *     $ ee site enable example.com --verify
	 *
	 *     # Force enable a site.
	 *     $ ee site enable example.com --force
	 *
	 *     # Force enable after regenerating docker-compose.yml of a site.
	 *     $ ee site enable example.com --refresh
	 */
	public function enable( $args, $assoc_args, $exit_on_error = true ) {

		\EE\Utils\delem_log( 'site enable start' );
		$force           = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$verify          = \EE\Utils\get_flag_value( $assoc_args, 'verify' );
		$refresh         = \EE\Utils\get_flag_value( $assoc_args, 'refresh' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );

		if ( $this->site_data->site_enabled && ! ( $force || $refresh ) ) {
			\EE::error( sprintf( '%s is already enabled!', $this->site_data->site_url ) );
		}

		if ( $verify ) {
			$this->verify_services();
		}

		if ( $refresh ) {
			$no_https        = $this->site_data->site_ssl ? false : true;
			$site            = $this->site_data;
			$array_data      = ( array ) $this->site_data;
			$this->site_data = reset( $array_data );
			$this->dump_docker_compose_yml( [ 'nohttps' => $no_https ] );
			$this->site_data = $site;
		}

		chdir( $this->site_data->site_fs_path );

		\EE::log( sprintf( 'Enabling site %s.', $this->site_data->site_url ) );

		$success             = false;
		$containers_to_start = array_diff( \EE_DOCKER::get_services(), [ 'postfix', 'mailhog' ] );


		# Required when newrelic is enabled on site. Newrelic ini is updated via docker-entrypoint.
		$php_confd_dir = $this->site_data->site_fs_path . '/config/php/php/conf.d';
		if ( $this->fs->exists( $php_confd_dir ) ) {
			$this->fs->chown( $php_confd_dir, 'www-data', true );
		}

		if ( \EE_DOCKER::docker_compose_up( $this->site_data->site_fs_path, $containers_to_start ) ) {
			$this->site_data->site_enabled = 1;
			$this->site_data->save();
			$success = true;
		}

		if ( $success ) {
			\EE::success( sprintf( 'Site %s enabled.', $this->site_data->site_url ) );
		} else {
			$err_msg = sprintf( 'There was error in enabling %s. Please check logs.', $this->site_data->site_url );
			if ( $exit_on_error ) {
				\EE::error( $err_msg );
			}
			throw new \Exception( $err_msg );
		}

		if ( ! empty( \EE::get_runner()->config['custom-compose'] ) ) {
			\EE\Utils\delem_log( 'site enable end' );

			return true;
		}

		\EE::log( 'Running post enable configurations.' );

		$postfix_exists      = \EE_DOCKER::service_exists( 'postfix', $this->site_data->site_fs_path );
		$containers_to_start = $postfix_exists ? [ 'nginx', 'postfix' ] : [ 'nginx' ];

		\EE\Site\Utils\start_site_containers( $this->site_data->site_fs_path, $containers_to_start );

		$site_data_array = (array) $this->site_data;
		$this->site_data = reset( $site_data_array );
		$this->www_ssl_wrapper( $containers_to_start, true );
		try {
			update_site_db_entry( $this->site_data['site_url'], $this->site_data );
		} catch ( \Exception $e ) {
			EE::error( $e->getMessage() );
		}

		if ( $postfix_exists ) {
			\EE\Site\Utils\configure_postfix( $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		}

		if ( true === (bool) $this->site_data['admin_tools'] ) {
			$admin_tools = new \Admin_Tools_Command();
			$admin_tools->enable( [ $this->site_data['site_url'] ], [ 'force' => true ] );
		}

		if ( true === (bool) $this->site_data['mailhog_enabled'] ) {
			$mailhog = new \Mailhog_Command();
			$mailhog->enable( [ $this->site_data['site_url'] ], [ 'force' => true ] );
		}

		\EE::success( 'Post enable configurations complete.' );

		\EE\Utils\delem_log( 'site enable end' );
	}


	/**
	 * Re-create sites docker-compose file and update the containers containers.
	 * Syntactic sugar of `ee site enable --refresh`.
	 *
	 * ## EXAMPLES
	 *
	 *     # Refresh site services
	 *     $ ee site refresh <site-name>
	 */
	public function refresh( $args, $assoc_args ) {

		$this->site_data = get_site_info( $args );
		$this->enable( [ $this->site_data['site_url'] ], [ 'refresh' => 'true' ] );
	}

	/**
	 * Disables a website. It will stop and remove the docker containers of the website if they are running.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be disabled.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable site
	 *     $ ee site disable example.com
	 *
	 */
	public function disable( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site disable start' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );

		\EE::log( sprintf( 'Disabling site %s.', $this->site_data->site_url ) );

		$fs                        = new Filesystem();
		$redirect_config_file_path = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/' . $args[0] . '-redirect.conf';
		if ( $fs->exists( $redirect_config_file_path ) ) {
			$fs->remove( $redirect_config_file_path );
			\EE\Site\Utils\reload_global_nginx_proxy();
		}

		if ( \EE_DOCKER::docker_compose_down( $this->site_data->site_fs_path ) ) {
			$this->site_data->site_enabled = 0;
			$this->site_data->save();

			\EE::success( sprintf( 'Site %s disabled.', $this->site_data->site_url ) );
		} else {
			\EE::error( sprintf( 'There was error in disabling %s. Please check logs.', $this->site_data->site_url ) );
		}
		\EE\Utils\delem_log( 'site disable end' );
	}

	/**
	 * Restarts containers associated with site.
	 * When no service(--nginx etc.) is specified, all site containers will be restarted.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Restart all containers of site.
	 *
	 * [--nginx]
	 * : Restart nginx container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart all containers of site
	 *     $ ee site restart example.com
	 *
	 */
	public function restart( $args, $assoc_args, $whitelisted_containers = [] ) {

		\EE\Utils\delem_log( 'site restart start' );
		$args                 = auto_site_name( $args, 'site', __FUNCTION__ );
		$all                  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		$no_service_specified = count( $assoc_args ) === 0;

		$this->site_data = get_site_info( $args );

		chdir( $this->site_data['site_fs_path'] );

		if ( $all || $no_service_specified ) {
			$available_containers = \EE_DOCKER::get_services();
			if ( empty( \EE::get_runner()->config['custom-compose'] ) ) {
				$containers = array_unique( array_merge( $available_containers, $whitelisted_containers ) );
				$containers = array_diff( $containers, [ 'postfix', 'mailhog' ] );
			} else {
				$containers = $available_containers;
			}
		} else {
			$containers = array_keys( $assoc_args );
		}

		foreach ( $containers as $container ) {
			if ( 'nginx' === $container ) {
				if ( EE::exec( \EE_DOCKER::docker_compose_with_custom() . " exec $container nginx -t", true, true ) ) {
					\EE\Site\Utils\run_compose_command( 'restart', $container );
				} else {
					\EE\Utils\delem_log( 'site restart stop due to Nginx test failure' );
					\EE::error( 'Nginx test failed' );
				}
			} else {
				\EE\Site\Utils\run_compose_command( 'restart', $container );
			}
		}
		\EE\Utils\delem_log( 'site restart stop' );
	}

	/**
	 * Clears Object and Page cache for site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be enabled.
	 *
	 * [--page]
	 * : Clear page cache.
	 *
	 * [--object]
	 * : Clear object cache.
	 *
	 * [--proxy]
	 * : Clear proxy cache.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear all enabled caches for site.
	 *     $ ee site clean example.com
	 *
	 *     # Clear Object cache for site.
	 *     $ ee site clean example.com --object
	 *
	 *     # Clear Page cache for site.
	 *     $ ee site clean example.com --page
	 *
	 *     # Clear Proxy cache for site.
	 *     $ ee site clean example.com --proxy
	 */
	public function clean( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site clean start' );
		$object          = \EE\Utils\get_flag_value( $assoc_args, 'object' );
		$page            = \EE\Utils\get_flag_value( $assoc_args, 'page' );
		$proxy           = \EE\Utils\get_flag_value( $assoc_args, 'proxy' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );
		$purge_key       = '';
		$error           = [];

		// No param passed.
		if ( empty( $object ) && empty( $page ) && empty( $proxy ) ) {

			$object = true;
			$page   = true;

			if ( 'on' === $this->site_data->proxy_cache ) {
				$proxy = true;
			}
		}

		// Object cache clean.
		if ( ! empty( $object ) ) {
			if ( 1 === intval( $this->site_data->cache_mysql_query ) ) {
				$purge_key = $this->site_data->site_url . '_obj';
				EE\Site\Utils\clean_site_cache( $purge_key );
			} else {
				$error[] = 'Site object cache is not enabled.';
			}
		}

		// Page cache clean.
		if ( ! empty( $page ) ) {
			if ( 1 === intval( $this->site_data->cache_nginx_fullpage ) ) {
				$purge_key = $this->site_data->site_url . '_page';
				EE\Site\Utils\clean_site_cache( $purge_key );
			} else {
				$error[] = 'Site page cache is not enabled.';
			}
		}

		// Clear proxy cache.
		if ( ! empty( $proxy ) ) {
			if ( 'on' === $this->site_data->proxy_cache ) {
				EE::exec( sprintf( 'docker exec -it %s bash -c "rm -r /var/cache/nginx/%s/*"', EE_PROXY_TYPE, $this->site_data->site_url ) );
				EE::log( 'Restarting nginx-proxy after clearing proxy cache.' );
				EE::exec( sprintf( 'docker exec -it %1$s bash -c "nginx -t" && docker restart %1$s', EE_PROXY_TYPE ) );
			} else {
				$error[] = 'Proxy cache is not enabled on site.';
			}
		}

		if ( ! empty( $error ) ) {
			\EE::error( implode( ' ', $error ) );
		}

		$cache_flags = [ 'Page' => $page, 'Object' => $object, 'Proxy' => $proxy ];

		foreach ( $cache_flags as $name => $is_purged ) {

			if ( $is_purged ) {
				\EE::success( $name . ' cache cleared for ' . $this->site_data->site_url );
			}
		}

		\EE\Utils\delem_log( 'site clean end' );

	}

	/**
	 * Reload services in containers without restarting container(s) associated with site.
	 * When no service(--nginx etc.) is specified, all services will be reloaded.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Reload all services of site(which are supported).
	 *
	 * [--nginx]
	 * : Reload nginx service in container.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reload all containers of site
	 *     $ ee site reload example.com
	 *
	 */
	public function reload( $args, $assoc_args, $whitelisted_containers = [], $reload_commands = [] ) {

		\EE\Utils\delem_log( 'site reload start' );
		$args = auto_site_name( $args, 'site', __FUNCTION__ );
		$all  = \EE\Utils\get_flag_value( $assoc_args, 'all' );
		if ( ! array_key_exists( 'nginx', $reload_commands ) ) {
			$reload_commands['nginx'] = 'nginx sh -c \'nginx -t && nginx -s reload\'';
		}
		$no_service_specified = count( $assoc_args ) === 0;

		$this->site_data = get_site_info( $args );

		chdir( $this->site_data['site_fs_path'] );

		if ( $all || $no_service_specified ) {
			$this->reload_services( $whitelisted_containers, $reload_commands );
		} else {
			$this->reload_services( array_keys( $assoc_args ), $reload_commands );
		}
		\EE\Utils\delem_log( 'site reload stop' );
	}

	/**
	 * Executes reload commands. It needs separate handling as commands to reload each service is different.
	 *
	 * @param array $services        Services to reload.
	 * @param array $reload_commands Commands to reload the services.
	 */
	private function reload_services( $services, $reload_commands ) {

		foreach ( $services as $service ) {
			\EE\Site\Utils\run_compose_command( 'exec', $reload_commands[ $service ], 'reload', $service );
		}
	}

	/**
	 * Function to verify and check the global services dependent for given site.
	 * Enables the dependent service if it is down.
	 */
	private function verify_services() {

		if ( 'running' !== \EE_DOCKER::container_status( EE_PROXY_TYPE ) ) {
			EE\Service\Utils\nginx_proxy_check();
		}

		if ( 'global-db' === $this->site_data->db_host ) {
			EE\Service\Utils\init_global_container( GLOBAL_DB );
		}

		if ( 'global-redis' === $this->site_data->cache_host ) {
			EE\Service\Utils\init_global_container( GLOBAL_REDIS );
		}
	}

	/**
	 * Function to add site redirects and initialise ssl process.
	 *
	 * @param array $containers_to_start Containers to start for that site. Default, empty will start all.
	 * @param bool $force                Force ssl renewal.
	 * @param bool $renew                True if function is being used for cert renewal.
	 *
	 * @throws EE\ExitException
	 * @throws \Exception
	 */
	protected function www_ssl_wrapper( $containers_to_start = [], $site_enable = false, $force = false, $renew = false ) {
		/**
		 * This adds http www redirection which is needed for issuing cert for a site.
		 * i.e. when you create example.com site, certs are issued for example.com and www.example.com
		 *
		 * We're issuing certs for both domains as it is needed in order to perform redirection of
		 * https://www.example.com -> https://example.com
		 *
		 * We add redirection config two times in case of ssl as we need http redirection
		 * when certs are being requested and http+https redirection after we have certs.
		 */
		\EE\Site\Utils\add_site_redirects( $this->site_data['site_url'], false, 'inherit' === $this->site_data['site_ssl'] );
		\EE\Site\Utils\reload_global_nginx_proxy();
		// Need second reload sometimes for changes to reflect.
		\EE\Site\Utils\reload_global_nginx_proxy();

		$is_www_or_non_www_pointed = $this->check_www_or_non_www_domain( $this->site_data['site_url'], $this->site_data['site_fs_path'], $this->site_data['site_container_fs_path'] ) || $this->site_data['site_ssl_wildcard'];
		if ( ! $is_www_or_non_www_pointed ) {
			$fs          = new Filesystem();
			$confd_path  = EE_ROOT_DIR . '/services/nginx-proxy/conf.d/';
			$config_file = $confd_path . $this->site_data['site_url'] . '-redirect.conf';
			$fs->remove( $config_file );
			\EE\Site\Utils\reload_global_nginx_proxy();
		}

		if ( $this->site_data['site_ssl'] ) {
			if ( ! $site_enable ) {
				if ( 'custom' !== $this->site_data['site_ssl'] ) {

					$alias_domains = empty( $this->site_data['alias_domains'] ) ? [] : explode( ',', $this->site_data['alias_domains'] );
					$wildcard      = $this->site_data['site_ssl_wildcard'];

					if ( empty( $wildcard ) ) {
						foreach ( $alias_domains as $domain ) {
							if ( '*.' . $this->site_data['site_url'] === $domain ) {
								$wildcard = "1";
								break;
							}
						}
					}

					/**
					 * In cases like Re-enabling SSL on a site which had SSL at a time, or while renewing
					 * certificates, need to check if the existing certificate is valid. If it is valid,
					 * we don't need to create or get new certs. We can use the existing ones.
					 */
					if ( $force || EE\Site\Utils\ssl_needs_creation( $this->site_data['site_url'] ) ) {
						$this->init_ssl( $this->site_data['site_url'], $this->site_data['site_fs_path'], $this->site_data['site_ssl'], $wildcard, $is_www_or_non_www_pointed, $force, $alias_domains );
					}
				}

				$this->dump_docker_compose_yml( [ 'nohttps' => false ] );

				\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], $containers_to_start );
			}

			if ( $is_www_or_non_www_pointed ) {
				\EE\Site\Utils\add_site_redirects( $this->site_data['site_url'], true, 'inherit' === $this->site_data['site_ssl'] );
			}

			\EE\Site\Utils\reload_global_nginx_proxy();
		}
	}

	/**
	 * Runs the acme le registration and authorization.
	 *
	 * @param string $site_url Name of the site for ssl.
	 *
	 * @throws \Exception
	 */
	protected function check_parent_site_certs( $site_url ) {

		$parent_site_name = implode( '.', array_slice( explode( '.', $site_url ), 1 ) );
		$parent_site      = Site::find( $parent_site_name, [ 'site_ssl', 'site_ssl_wildcard' ] );

		if ( ! $parent_site ) {
			throw new \Exception( 'Unable to find existing site: ' . $parent_site_name );
		}

		if ( ! $parent_site->site_ssl ) {
			throw new \Exception( "Cannot inherit from $parent_site_name as site does not have SSL cert" . var_dump( $parent_site ) );
		}

		if ( ! $parent_site->site_ssl_wildcard ) {
			throw new \Exception( "Cannot inherit from $parent_site_name as site does not have wildcard SSL cert" );
		}
	}

	/**
	 * Runs SSL procedure.
	 *
	 * @param string $site_url     Name of the site for ssl.
	 * @param string $site_fs_path Webroot of the site.
	 * @param string $ssl_type     Type of ssl cert to issue.
	 * @param bool $wildcard       SSL with wildcard or not.
	 * @param bool $www_or_non_www Allow LetsEncrypt on www or non-www subdomain.
	 * @param bool $force          Force ssl renewal.
	 * @param array $alias_domains Array of alias domains if any.
	 *
	 * @throws \EE\ExitException If --ssl flag has unrecognized value.
	 * @throws \Exception
	 */
	protected function init_ssl( $site_url, $site_fs_path, $ssl_type, $wildcard = false, $www_or_non_www = false, $force = false, $alias_domains = [] ) {

		\EE::debug( 'Starting SSL procedure' );
		if ( 'le' === $ssl_type ) {
			\EE::debug( 'Initializing LE' );
			$this->init_le( $site_url, $site_fs_path, $wildcard, $www_or_non_www, $force, $alias_domains );
		} elseif ( 'inherit' === $ssl_type ) {
			if ( $wildcard ) {
				throw new \Exception( 'Cannot use --wildcard with --ssl=inherit', false );
			}
			// We don't have to do anything now as nginx-proxy handles everything for us.
			EE::success( 'Inherited certs from parent' );
		} elseif ( 'self' === $ssl_type ) {
			$client = new Site_Self_signed();
			$client->create_certificate( $site_url );
			// Update wildcard to 1 as self-signed certs are wildcard by default.
			$this->site_data['site_ssl_wildcard'] = 1;
		} else {
			throw new \Exception( "Unrecognized value in --ssl flag: $ssl_type" );
		}
	}

	/**
	 * Runs the acme le registration and authorization.
	 *
	 * @param string $site_url     Name of the site for ssl.
	 * @param string $site_fs_path Webroot of the site.
	 * @param bool $wildcard       SSL with wildcard or not.
	 * @param bool $www_or_non_www Allow LetsEncrypt on www or non-www subdomain.
	 * @param bool $force          Force ssl renewal.
	 * @param array $alias_domains Array of alias domains if any.
	 */
	protected function init_le( $site_url, $site_fs_path, $wildcard = false, $www_or_non_www, $force = false, $alias_domains = [] ) {
		$preferred_challenge = get_preferred_ssl_challenge( $alias_domains );
		$is_solver_dns       = ( $wildcard || 'dns' === $preferred_challenge ) ? true : false;
		\EE::debug( 'Wildcard in init_le: ' . ( bool ) $wildcard );

		$this->site_data['site_fs_path']      = $site_fs_path;
		$this->site_data['site_ssl_wildcard'] = $wildcard;
		$client                               = new Site_Letsencrypt();
		$this->le_mail                        = \EE::get_runner()->config['le-mail'] ?? \EE::input( 'Enter your mail id: ' );
		\EE::get_runner()->ensure_present_in_config( 'le-mail', $this->le_mail );
		if ( ! $client->register( $this->le_mail ) ) {
			$this->site_data['site_ssl'] = null;
			\EE::warning( 'SSL registration failed.' );

			return;
		}

		$domains = $this->get_cert_domains( $site_url, $wildcard, $www_or_non_www );
		$domains = array_unique( array_merge( $domains, $alias_domains ) );

		$client->revokeAuthorizationChallenges( $domains );

		if ( ! $client->authorize( $domains, $wildcard, $preferred_challenge ) ) {
			$this->site_data['site_ssl'] = null;
			\EE::warning( 'SSL authorization failed. Site will be created without SSL. You can fix the issue and re-run: ee site ssl-verify ' . $site_url );

			return;
		}
		$api_key_absent = empty( get_config_value( 'cloudflare-api-key' ) );
		if ( $is_solver_dns && $api_key_absent ) {
			echo \cli\Colors::colorize( '%YIMPORTANT:%n Run `ee site ssl-verify ' . $site_url . '` once the DNS changes have propagated to complete the certification generation and installation.', null );
		} else {
			if ( ! $api_key_absent && $is_solver_dns ) {
				EE::log( 'Waiting for DNS entry propagation.' );
				sleep( 10 );
			}
			if ( ! $this->ssl_verify( [], [ 'force' => $force ], $www_or_non_www ) ) {
				$this->site_data['site_ssl'] = null;
				\EE::warning( 'SSL verification failed. You can fix the issue and re-run: ee site ssl-verify ' . $site_url );

				return;
			}
		}
	}

	/**
	 * Returns all domains required by cert
	 *
	 * @param string $site_url     Name of site
	 * @param bool $wildcard       Wildcard cert required?
	 * @param bool $www_or_non_www Allow LetsEncrypt on www subdomain.
	 *
	 * @return array
	 */
	private function get_cert_domains( string $site_url, $wildcard, $www_or_non_www = false ): array {
		$preferred_challenge = get_config_value( 'preferred_ssl_challenge', '' );
		$is_solver_dns       = ( $wildcard || 'dns' === $preferred_challenge ) ? true : false;

		$domains = [ $site_url ];

		if ( ! empty( $wildcard ) ) {
			$domains[] = "*.{$site_url}";
		} elseif ( $www_or_non_www || $is_solver_dns ) {
			if ( 0 === strpos( $site_url, 'www.' ) ) {
				$www_or_non_www_site_url = ltrim( $site_url, 'www.' );
			} else {
				$www_or_non_www_site_url = 'www.' . $site_url;
			}
			$domains[] = $www_or_non_www_site_url;
		}

		return $domains;
	}

	/**
	 * Check www or non-www is working with site domain.
	 *
	 * @param string Site url.
	 * @param string Absolute path of site.
	 * @param string $site_container_path
	 *
 *
	 * @return bool
	 */
	protected function check_www_or_non_www_domain( $site_url, $site_path, $site_container_path ): bool {

		$random_string = EE\Utils\random_password();
		$successful    = false;
		$extra_path    = str_replace( '/var/www/htdocs', '', $site_container_path );
		$file_path     = $site_path . '/app/htdocs' . $extra_path . '/check.html';
		file_put_contents( $file_path, $random_string );

		if ( 0 === strpos( $site_url, 'www.' ) ) {
			$site_url = ltrim( $site_url, 'www.' );
		} else {
			$site_url = 'www.' . $site_url;
		}

		$site_url .= '/check.html';

		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, $site_url );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $curl, CURLOPT_HEADER, false );
		$data = curl_exec( $curl );
		curl_close( $curl );

		if ( ! empty( $data ) && $random_string === $data ) {
			$successful = true;
			EE::debug( "pointed for $site_url" );
		}

		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}

		return $successful;
	}

	/**
	 * Verifies ssl challenge and also renews certificates(if expired).
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--force]
	 * : Force renewal.
	 *
	 * @subcommand ssl-verify
	 * @alias      ssl
	 */
	public function ssl_verify( $args = [], $assoc_args = [], $www_or_non_www = false ) {

		EE::log( 'Starting SSL verification.' );

		// This checks if this method was called internally by ee or by user
		$called_by_ee   = ! empty( $this->site_data['site_url'] );
		$api_key_absent = empty( get_config_value( 'cloudflare-api-key' ) );

		if ( ! $called_by_ee ) {
			$this->site_data = get_site_info( $args );
		}

		if ( ! isset( $this->le_mail ) ) {
			$this->le_mail = \EE::get_config( 'le-mail' ) ?? \EE::input( 'Enter your mail id: ' );
		}

		$force         = \EE\Utils\get_flag_value( $assoc_args, 'force' );
		$alias_domains = empty( $this->site_data['alias_domains'] ) ? [] : explode( ',', $this->site_data['alias_domains'] );
		$domains       = $this->get_cert_domains( $this->site_data['site_url'], $this->site_data['site_ssl_wildcard'], $www_or_non_www );
		$domains       = array_unique( array_merge( $domains, $alias_domains ) );
		$client        = new Site_Letsencrypt();

		$preferred_challenge = get_preferred_ssl_challenge( $domains );

		if ( ! $client->check( $domains, $this->site_data['site_ssl_wildcard'], $preferred_challenge ) ) {
			$is_solver_dns   = ( $this->site_data['site_ssl_wildcard'] || 'dns' === $preferred_challenge ) ? true : false;
			$api_key_present = ! empty( get_config_value( 'cloudflare-api-key' ) );

			$warning = ( $is_solver_dns && $api_key_present )
				? "The dns entries have not yet propogated. Manually check: \nhost -t TXT _acme-challenge." . $this->site_data['site_url'] . "\nBefore retrying `ee site ssl " . $this->site_data['site_url'] . "`"
				: 'Failed to verify SSL.';

			EE::warning( $warning );
			EE::warning( sprintf( 'Check logs and retry `ee site ssl-verify %s` once the issue is resolved.', $this->site_data['site_url'] ) );

			return false;
		}

		$san = array_values( array_diff( $domains, [ $this->site_data['site_url'] ] ) );
		if ( ! $client->request( $this->site_data['site_url'], $san, $this->le_mail, $force ) ) {
			return false;
		}

		if ( ! $this->site_data['site_ssl_wildcard'] ) {
			$client->cleanup();
		}

		reload_global_nginx_proxy();

		EE::success( 'SSL verification completed.' );

		return true;
	}

	/**
	 * Shows SSL info and DNS challenge records for a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--get-dns-records]
	 * : Show DNS challenge records (if using DNS-01 challenge).
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: json
	 * options:
	 *   - table
	 *   - csv
	 *   - yaml
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show SSL info for a site
	 *     $ ee site ssl-info example.com
	 *
	 *     # Show DNS challenge info for a site
	 *     $ ee site ssl-info example.com --get-dns-records
	 *
	 * @subcommand ssl-info
	 */
	public function ssl_info( $args, $assoc_args ) {
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, false, true, false );

		$site_url      = $this->site_data->site_url;
		$wildcard      = ! empty( $this->site_data->site_ssl_wildcard );
		$alias_domains = empty( $this->site_data->alias_domains ) ? [] : explode( ',', $this->site_data->alias_domains );
		$domains       = $this->get_cert_domains( $site_url, $wildcard );
		$domains       = array_unique( array_merge( $domains, $alias_domains ) );

		$output   = [];
		$warnings = [];

		// If --get-dns-records is passed, show DNS challenge info (old behavior)
		if ( \EE\Utils\get_flag_value( $assoc_args, 'get-dns-records', false ) ) {
			$preferred_challenge = get_preferred_ssl_challenge( $domains );
			$is_dns              = $wildcard || $preferred_challenge === 'dns';

			if ( ! $is_dns ) {
				$warnings[] = 'This site does not use DNS-based (DNS-01) SSL challenge.';
			} else {
				$client = new \EE\Site\Type\Site_Letsencrypt();
				$rows   = [];
				foreach ( $domains as $domain ) {
					if ( $client->hasDomainAuthorizationChallenge( $domain ) ) {
						$challenge = $client->loadDomainAuthorizationChallenge( $domain );
						if ( method_exists( $challenge, 'toArray' ) ) {
							$data        = $challenge->toArray();
							// Always use _acme-challenge.base-domain for wildcard domains
							if ( strpos( $domain, '*.' ) === 0 ) {
								$base_domain = substr( $domain, 2 );
								$record_name = '_acme-challenge.' . $base_domain;
							} else {
								$record_name = isset( $data['dnsRecordName'] ) ? $data['dnsRecordName'] : '_acme-challenge.' . $domain;
							}
							if ( isset( $data['dnsRecordValue'] ) ) {
								$record_value = $data['dnsRecordValue'];
							} elseif ( isset( $data['payload'] ) ) {
								$keyAuthorization = $data['payload'];
								$digest           = rtrim( strtr( base64_encode( hash( 'sha256', $keyAuthorization, true ) ), '+/', '-_' ), '=' );
								$record_value     = $digest;
							} else {
								$record_value = '';
							}
							$rows[] = [
								'domain'       => $domain,
								'record_name'  => $record_name,
								'record_value' => $record_value,
							];
						} else {
							$warnings[] = "Could not extract DNS challenge for $domain.";
						}
					} else {
						$warnings[] = "No pending DNS challenge found for $domain. (Try running 'ee site ssl-verify $site_url' if you are setting up SSL)";
					}
				}
				$output['dns_challenges'] = $rows;
			}
			$output['warnings'] = $warnings;
			$formatter          = new \EE\Formatter( $assoc_args, array_keys( $output ) );
			$formatter->display_items( [ $output ] );

			return;
		}

		// Otherwise, show SSL status and cert details
		$ssl_type           = $this->site_data->site_ssl;
		$output['ssl_type'] = $ssl_type ? $ssl_type : 'off';

		if ( ! $ssl_type || $ssl_type === 'off' ) {
			$output['status']   = 'SSL is not enabled for this site.';
			$output['warnings'] = $warnings;
			$formatter          = new \EE\Formatter( $assoc_args, array_keys( $output ) );
			$formatter->display_items( [ $output ] );

			return;
		}

		// Determine which cert to show (le, self, inherit, custom)
		$cert_site_name = $site_url;
		if ( $ssl_type === 'inherit' ) {
			$cert_site_name = implode( '.', array_slice( explode( '.', $site_url ), 1 ) );
		}

		$certs_dir = EE_ROOT_DIR . '/services/nginx-proxy/certs/';
		$crt_file  = $certs_dir . $cert_site_name . '.crt';

		if ( ! file_exists( $crt_file ) ) {
			$warnings[]         = "Certificate file not found for $cert_site_name ($crt_file)";
			$output['status']   = 'Certificate file not found / yet to be issued.';
			$output['warnings'] = $warnings;
			$formatter          = new \EE\Formatter( $assoc_args, array_keys( $output ) );
			$formatter->display_items( [ $output ] );

			return;
		}

		try {
			$certificate       = new \AcmePhp\Ssl\Certificate( file_get_contents( $crt_file ) );
			$certificateParser = new \AcmePhp\Ssl\Parser\CertificateParser();
			$parsedCertificate = $certificateParser->parse( $certificate );

			$issuer    = $parsedCertificate->getIssuer();
			$subject   = $parsedCertificate->getSubject();
			$validFrom = $parsedCertificate->getValidFrom()->format( 'Y-m-d H:i:s' );
			$validTo   = $parsedCertificate->getValidTo()->format( 'Y-m-d H:i:s' );
			$serial    = $parsedCertificate->getSerialNumber();

			// Use openssl_x509_parse for CN fields, as in migration
			$crt_pem = file_get_contents( $crt_file );
			if ( function_exists( 'openssl_x509_parse' ) ) {
				$cert_data   = openssl_x509_parse( $crt_pem );
				$subjectCN   = isset( $cert_data['subject']['CN'] ) ? $cert_data['subject']['CN'] : '';
				$issuer_full = isset( $cert_data['issuer'] ) ? $cert_data['issuer'] : [];
				$le_found    = false;
				foreach ( $issuer_full as $field => $value ) {
					if ( stripos( $value, "Let's Encrypt" ) !== false ) {
						$le_found = true;
						break;
					}
				}
				if ( $le_found ) {
					$issuerCN = "Let's Encrypt";
				} else {
					$issuerCN = isset( $issuer_full['CN'] ) ? $issuer_full['CN'] : implode( ', ', $issuer_full );
				}
			} else {
				if ( is_object( $subject ) && method_exists( $subject, 'getField' ) ) {
					$subjectCN = $subject->getField( 'CN' );
				} else {
					$subjectCN  = is_string( $subject ) ? $subject : json_encode( $subject );
					$warnings[] = 'Could not parse subject CN: unexpected type.';
				}
				if ( is_object( $issuer ) && method_exists( $issuer, 'getField' ) ) {
					$issuerCN = $issuer->getField( 'CN' );
				} else {
					$issuerCN   = is_string( $issuer ) ? $issuer : json_encode( $issuer );
					$warnings[] = 'Could not parse issuer CN: unexpected type.';
				}
				$warnings[] = 'openssl_x509_parse() not available in PHP. Used fallback parser.';
			}
			$san = $parsedCertificate->getSubjectAlternativeNames();

			$output['cert_file']     = $crt_file;
			$output['issued_to_CN']  = $subjectCN;
			$output['issued_by_CN']  = $issuerCN;
			$output['valid_from']    = $validFrom;
			$output['valid_till']    = $validTo;
			$output['serial_number'] = $serial;
			$output['SANs']          = implode( ', ', $san );
			$output['status']        = 'SSL certificate details loaded.';
		} catch ( \Exception $e ) {
			$warnings[]       = 'Could not parse certificate: ' . $e->getMessage();
			$output['status'] = 'Could not parse certificate.';
		}
		$output['warnings'] = $warnings;

		$formatter = new \EE\Formatter( $assoc_args, array_keys( $output ) );
		$formatter->display_items( [ $output ] );
	}

	/**
	 * Renews letsencrypt ssl certificates.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--force]
	 * : Force renewal.
	 *
	 * [--all]
	 * : Run renewal for all ssl sites. (Skips renewal for dns/wildcards sites if cloudflare api is not set).
	 *
	 * ## EXAMPLES
	 *
	 *     # Renew ssl cert of a site
	 *     $ ee site ssl-renew example.com
	 *
	 *     # Renew all ssl certs
	 *     $ ee site ssl-renew --all
	 *
	 *     # Force renew ssl cert
	 *     $ ee site ssl-renew example.com --force
	 *
	 * @subcommand ssl-renew
	 */
	public function ssl_renew( $args, $assoc_args ) {

		EE::log( 'Starting SSL cert renewal' );

		if ( ! isset( $this->le_mail ) ) {
			$this->le_mail = EE::get_config( 'le-mail' ) ?? EE::input( 'Enter your mail id: ' );
		}

		$force = get_flag_value( $assoc_args, 'force', false );
		$all   = get_flag_value( $assoc_args, 'all', false );

		if ( $all ) {
			$sites                 = Site::all();
			$api_key_absent        = empty( get_config_value( 'cloudflare-api-key' ) );
			$skip_wildcard_warning = false;
			foreach ( $sites as $site ) {
				if ( 'le' !== $site->site_ssl || ! $site->site_enabled ) {
					continue;
				}
				if ( $site->site_ssl_wildcard && $api_key_absent ) {
					EE::warning( "Wildcard site found: $site->site_url, skipping it." );
					if ( ! $skip_wildcard_warning ) {
						EE::warning( "As this is a wildcard certificate, it cannot be automatically renewed.\nPlease run `ee site ssl-renew $site->site_url` to renew the certificate, or add cloudflare api key in EasyEngine config. Ref: https://rt.cx/eecf" );
						$skip_wildcard_warning = true;
					}
					continue;
				}
				$this->renew_ssl_cert( [ $site->site_url ], $force );
			}
		} else {
			$args = auto_site_name( $args, 'site', __FUNCTION__ );
			$this->renew_ssl_cert( $args, $force );
		}
		EE::success( 'SSL renewal completed.' );
	}

	/**
	 * Function to setup and execute ssl renewal.
	 *
	 * @param array $args User input args.
	 * @param bool $force Whether to force renewal of cert or not.
	 *
	 * @throws EE\ExitException
	 * @throws \Exception
	 */
	private function renew_ssl_cert( $args, $force ) {

		if ( empty( $this->site_data['site_ssl'] ) ) {
			$this->site_data = get_site_info( $args );
		}

		if ( 'inherit' === $this->site_data['site_ssl'] ) {
			EE::error( 'No need to renew certs for site who have inherited ssl. Please renew certificate of the parent site.' );
		}

		if ( 'le' !== $this->site_data['site_ssl'] ) {
			EE::error( 'Only Letsencrypt certificate renewal is supported.' );
		}

		$client              = new Site_Letsencrypt();
		$preferred_challenge = get_preferred_ssl_challenge( get_domains_of_site( $this->site_data['site_url'] ) );

		if ( $client->isAlreadyExpired( $this->site_data['site_url'] ) && $preferred_challenge !== 'dns' ) {
			$this->dump_docker_compose_yml( [ 'nohttps' => true ] );
			$this->enable( $args, [ 'force' => true ] );
		}

		if ( ! $force && ! $client->isRenewalNecessary( $this->site_data['site_url'] ) ) {
			return 0;
		}

		$postfix_exists      = \EE_DOCKER::service_exists( 'postfix', $this->site_data['site_fs_path'] );
		$containers_to_start = $postfix_exists ? [ 'nginx', 'postfix' ] : [ 'nginx' ];
		$this->www_ssl_wrapper( $containers_to_start, false, $force, true );

		reload_global_nginx_proxy();
	}

	/**
	 * Share a site online using ngrok.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--disable]
	 * : Take online link down.
	 *
	 * [--refresh]
	 * : Refresh site share if link has expired.
	 *
	 * [--token=<token>]
	 * : ngrok token.
	 *
	 * ## EXAMPLES
	 *
	 *     # Share a site online
	 *     $ ee site share example.com
	 *
	 *     # Refresh shareed link if expired
	 *     $ ee site share example.com --refresh
	 *
	 *     # Disable online link
	 *     $ ee site share example.com --disable
	 *
	 */
	public function share( $args, $assoc_args ) {

		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, true, true, false );
		$disable         = get_flag_value( $assoc_args, 'disable', false );
		$refresh         = get_flag_value( $assoc_args, 'refresh', false );
		$token           = get_flag_value( $assoc_args, 'token', false );
		$active_publish  = Option::get( 'publish_site' );
		$publish_url     = Option::get( 'publish_url' );

		$this->fs = new Filesystem();
		$ngrok    = EE_SERVICE_DIR . '/ngrok/ngrok';

		if ( $disable || $refresh ) {
			if ( $this->site_data->site_url === $active_publish ) {
				$this->ngrok_curl( false, $refresh );
			} else {
				EE::error( $this->site_data->site_url . ' does not have active share running.' );
			}

			if ( ! $refresh ) {
				return;
			}
		}

		if ( $this->site_data->site_ssl ) {
			EE::error( 'site share is not yet supported for ssl sites.' );
		}

		$this->maybe_setup_ngrok( $ngrok );
		if ( ! empty( $active_publish ) ) {
			if ( ( $this->site_data->site_url === $active_publish ) ) {
				$error = $refresh ? '' : "{$this->site_data->site_url} has already been shared. Visit link: $publish_url to view it online.\nNote: This link is only valid for few hours. In case it has expired run: `ee site share {$this->site_data->site_url} --refresh`";
			} else {
				$error = "$active_publish site is shared currently. Sharing of only one site at a time is supported.\nTo share {$this->site_data->site_url} , first run: `ee site share $active_publish --disable`";
			}
			if ( ! empty( $error ) ) {
				EE::error( $error );
			}
		}

		if ( ! empty( $token ) ) {
			EE::exec( "$ngrok authtoken $token" );
		}
		$config_80_port = get_config_value( 'proxy_80_port', 80 );
		if ( ! $refresh ) {
			EE::log( "Sharing site: {$this->site_data->site_url} online." );
		}
		EE::debug( "$ngrok http -host-header={$this->site_data->site_url} $config_80_port > /dev/null &" );
		EE::debug( shell_exec( "$ngrok http -host-header={$this->site_data->site_url} $config_80_port > /dev/null &" ) );
		$published_url = $this->ngrok_curl();
		if ( empty( $published_url ) ) {
			EE::error( 'Could not share site.' );
		}
		EE::success( "Successfully shared {$this->site_data->site_url} to url: $published_url" );
		Option::set( 'publish_site', $this->site_data->site_url );
		Option::set( 'publish_url', $published_url );
	}

	/**
	 * Check if ngrok binary is setup. If not, set it up.
	 *
	 * @param $ngrok Path to ngrok binary.
	 */
	private function maybe_setup_ngrok( $ngrok ) {

		if ( $this->fs->exists( $ngrok ) ) {
			return;
		}
		EE::log( 'Setting up ngrok. This may take some time.' );
		$ngrok_zip = $ngrok . '.zip';
		$this->fs->mkdir( dirname( $ngrok ) );
		$uname_m      = 'x86_64' === php_uname( 'm' ) ? 'amd64.zip' : '386.zip';
		$download_url = IS_DARWIN ? 'https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-darwin-' . $uname_m : 'https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-linux-' . $uname_m;
		download( $ngrok_zip, $download_url );
		extract_zip( $ngrok_zip, dirname( $ngrok ) );
		chmod( $ngrok, 0755 );
		$this->fs->remove( $ngrok_zip );
	}

	/**
	 * Function to curl and get data from ngrok api.
	 *
	 * @param bool $get_url To get url of tunnel or not.
	 * @param bool $refresh Whether to disable share refresh or not.
	 */
	private function ngrok_curl( $get_url = true, $refresh = false ) {

		EE::log( 'Checking ngrok api for tunnel details.' );
		$tries = 0;
		$loop  = true;
		while ( $loop ) {
			$ch = curl_init( 'http://127.0.0.1:4040/api/tunnels/' );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_AUTOREFERER, true );
			$ngrok_curl = curl_exec( $ch );

			if ( ! empty( $ngrok_curl ) ) {
				$ngrok_data = json_decode( $ngrok_curl, true );
				if ( ! empty( $ngrok_data['tunnels'] ) ) {
					$loop = false;
				}
			}
			$tries ++;
			EE::debug( $ngrok_curl );
			sleep( 1 );
			if ( $tries > 20 ) {
				$loop = false;
			}
		}

		$json_error = json_last_error();
		if ( $json_error !== JSON_ERROR_NONE ) {
			EE::debug( 'Json last error: ' . $json_error );
			EE::error( 'Error fetching ngrok url. Check logs.' );
		}

		$ngrok_tunnel = '';
		if ( ! empty( $ngrok_data['tunnels'] ) ) {
			if ( $get_url ) {
				return str_replace( 'https', 'http', $ngrok_data['tunnels'][0]['public_url'] );
			} else {
				$ngrok_tunnel = str_replace( '+', '%20', urlencode( $ngrok_data['tunnels'][0]['name'] ) );
			}
		} elseif ( $get_url ) {
			EE::error( 'Could not share site. Please check logs.' );
		}

		if ( $refresh ) {
			EE::log( 'Refreshing site share.' );
		} else {
			EE::log( 'Disabling share.' );
		}
		if ( ! empty( $ngrok_tunnel ) ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'http://127.0.0.1:4040/api/tunnels/' . $ngrok_tunnel );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "DELETE" );
			$disable = curl_exec( $ch );
			EE::debug( $disable );
			curl_close( $ch );
			EE::exec( 'killall ngrok' );
		}
		Option::set( 'publish_site', '' );
		Option::set( 'publish_url', '' );
		if ( ! $refresh ) {
			EE::success( 'Site share disabled.' );
		}
	}

	/**
	 * Shutdown function to catch and rollback from fatal errors.
	 */
	protected function shut_down_function() {

		$logger = \EE::get_file_logger()->withName( 'site-command' );
		$error  = error_get_last();

		// Check if the $this->site_data is set and it is array and  $this->site_data['site_url'] is set.
		if ( isset( $this->site_data ) && is_array( $this->site_data ) && isset( $this->site_data['site_url'] ) ) {
			// release lock if there.
			$lock_file = EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.lock';
			if ( $this->fs->exists( $lock_file ) ) {
				$this->fs->remove( $lock_file );
			}
		}

		if ( isset( $error ) && $error['type'] === E_ERROR ) {
			\EE::warning( 'An Error occurred. Initiating clean-up.' );
			$logger->error( 'Type: ' . $error['type'] );
			$logger->error( 'Message: ' . $error['message'] );
			$logger->error( 'File: ' . $error['file'] );
			$logger->error( 'Line: ' . $error['line'] );
			$this->rollback();
		}
	}

	/**
	 * Function to populate site-info from database.
	 *
	 * @param string $site_name Name of the site whose info needs to be populated.
	 * @param string $in_array  Populate info in array if true, else in obj form.
	 *
	 * @ignorecommand
	 */
	public function populate_site_info( $site_name, $in_array = true ) {
		$this->site_data = EE\Site\Utils\get_site_info( [ $site_name ], false, false, $in_array );
	}

	/**
	 * Validate ssl-key and ssl-crt paths.
	 *
	 * @param $ssl_key ssl-key file path.
	 * @param $ssl_crt ssl-crt file path.
	 *
	 * @throws \Exception
	 */
	protected function validate_site_custom_ssl( $ssl_key, $ssl_crt ) {
		if ( empty( $ssl_key ) || empty( $ssl_crt ) ) {
			throw new \Exception( 'Pass --ssl-key and --ssl-crt for custom SSL' );
		}

		if ( $this->fs->exists( $ssl_key ) && $this->fs->exists( $ssl_crt ) ) {
			$this->site_data['ssl_key'] = realpath( $ssl_key );
			$this->site_data['ssl_crt'] = realpath( $ssl_crt );
		} else {
			throw new \Exception( 'ssl-key OR ssl-crt path does not exist' );
		}
	}

	/**
	 * Allow custom SSL for site.
	 */
	protected function custom_site_ssl() {

		$ssl_key_dest = sprintf( '%1$s/nginx-proxy/certs/%2$s.key', remove_trailing_slash( EE_SERVICE_DIR ), $this->site_data['site_url'] );
		$ssl_crt_dest = sprintf( '%1$s/nginx-proxy/certs/%2$s.crt', remove_trailing_slash( EE_SERVICE_DIR ), $this->site_data['site_url'] );

		$this->fs->copy( $this->site_data['ssl_key'], $ssl_key_dest, true );
		$this->fs->copy( $this->site_data['ssl_crt'], $ssl_crt_dest, true );
	}

	/**
	 * Clones a website from source to a new website in destination.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Name of source website to be cloned. Format [user@ssh-hostname:]sitename
	 *
	 * <destination>
	 * : Name of destination website to be cloned. Format [user@ssh-hostname:]sitename
	 *
	 * [--files]
	 * : Sync only files.
	 *
	 * [--db]
	 * : Sync only database.
	 *
	 * [--uploads]
	 * : Sync only uploads.
	 *
	 * [--ssl]
	 * : Enables ssl on site.
	 * ---
	 * options:
	 *      - le
	 *      - off
	 *      - self
	 *      - inherit
	 *      - custom
	 * ---
	 *
	 * [--ssl-key=<ssl-key-path>]
	 * : Path to the SSL key file.
	 *
	 * [--ssl-crt=<ssl-crt-path>]
	 * : Path to the SSL crt file.
	 *
	 * [--wildcard]
	 * : Gets wildcard SSL.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clone site on same host
	 *     $ ee site clone foo.com bar.com
	 *
	 *     # Clone site from remote server
	 *     $ ee site clone root@foo.com:foo.com bar.com
	 *
	 *     # Clone site from remote with same name
	 *     $ ee site clone root@foo.com:foo.com .
	 *
	 *     # Clone site to remote
	 *     $ ee site clone foo.com root@foo.com:bar.com
	 *
	 *     # Clone site from remote. Only clone files and db, not uploads
	 *     $ ee site clone root@foo.com:foo.com bar.com --files --db
	 *
	 *     # Clone site from remote and enable ssl
	 *     $ ee site clone root@foo.com:foo.com bar.com --ssl=le --wildcard
	 *
	 */
	public function clone( $args, $assoc_args ) {

		list( $source, $destination ) = get_transfer_details( $args[0], $args[1] );

		try {

			check_site_access( $source, $destination );

			if ( 'wp' !== $source->site_details['site_type'] ) {
				EE::error( 'Only clone of WordPress sites is supported as of now.' );
			}

			$files_flag   = get_flag_value( $assoc_args, 'files', false );
			$uploads_flag = get_flag_value( $assoc_args, 'uploads', false );
			$db_flag      = get_flag_value( $assoc_args, 'db', false );
			if ( ! $files_flag && ! $uploads_flag && ! $db_flag ) {
				$files_flag = $uploads_flag = $db_flag = true;
			}

			$operations = [
				'files' => $files_flag,
				'uploads' => $uploads_flag,
				'db' => $db_flag,
			];

			if ( $destination->create_site( $source, $assoc_args )->return_code ) {
				EE::error( 'Cannot create site ' . $destination->name . '. Please check logs for more info or rerun the command with --debug flag.' );
			}

			$destination->ensure_site_exists();
			$destination->set_site_details();

			if ( $operations['files'] || $operations['uploads'] ) {
				EE::log( 'Syncing files' );
				copy_site_files( $source, $destination, $operations );
			}

			if ( $operations['db'] ) {
				EE::log( 'Syncing database' );
				copy_site_db( $source, $destination );
			}

			echo $destination->execute( 'ee site info ' . $destination->name )->stdout;
			$message = 'Site cloned successfully.';

			if ( $destination->site_details['site_type'] === 'wp' ) {
				$message .= PHP_EOL . 'You have to do these additional configurations manually (if required):' . PHP_EOL . '1. Update wp-config.php.' . PHP_EOL . '2. Add alias domains.';
			}

			EE::success( $message );
		} catch ( \Exception $e ) {
			EE::warning( 'Encountered error while cloning site. Rolling back.' );

			$source->rollback();
			$destination->rollback();

			EE::error( $e->getMessage() );
		}
	}

	/**
	 * Syncs a website from source to an existing website in destination.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Name of source website to be synced. Format [user@ssh-hostname:]sitename
	 *
	 * <destination>
	 * : Name of destination website to be synced. Format [user@ssh-hostname:]sitename
	 *
	 * [--files]
	 * : Sync only files.
	 *
	 * [--db]
	 * : Sync only database.
	 *
	 * [--uploads]
	 * : Sync only uploads.
	 *
	 * ## EXAMPLES
	 *
	 *     # Sync site on same host
	 *     $ ee site sync foo.com bar.com
	 *
	 *     # Sync site from remote server
	 *     $ ee site sync root@foo.com:foo.com bar.com
	 *
	 *     # Sync site from remote with same name
	 *     $ ee site sync root@foo.com:foo.com .
	 *
	 *     # Sync site to remote
	 *     $ ee site sync foo.com root@foo.com:bar.com
	 *
	 *     # Sync site from remote. Only clone files and db, not uploads
	 *     $ ee site sync root@foo.com:foo.com bar.com --files --db
	 *
	 *     # Sync site from remote and enable ssl
	 *     $ ee site sync root@foo.com:foo.com bar.com --ssl=le --wildcard
	 *
	 */
	public function sync( $args, $assoc_args ) {

		list( $source, $destination ) = get_transfer_details( $args[0], $args[1] );

		try {
			check_site_access( $source, $destination, true );

			if ( 'wp' !== $source->site_details['site_type'] || 'wp' !== $destination->site_details['site_type'] ) {
				EE::error( 'Only Sync of WordPress sites is supported as of now.' );
			}

			$files_flag   = get_flag_value( $assoc_args, 'files', false );
			$uploads_flag = get_flag_value( $assoc_args, 'uploads', false );
			$db_flag      = get_flag_value( $assoc_args, 'db', false );
			if ( ! $files_flag && ! $uploads_flag && ! $db_flag ) {
				$files_flag = $uploads_flag = $db_flag = true;
			}

			$operations = [
				'files'   => $files_flag,
				'uploads' => $uploads_flag,
				'db'      => $db_flag,
			];

			if ( $operations['files'] || $operations['uploads'] ) {
				EE::log( 'Syncing files' );
				copy_site_files( $source, $destination, $operations );
			}

			if ( $operations['db'] ) {
				EE::log( 'Syncing database' );
				copy_site_db( $source, $destination );
			}
			EE::success( 'Site synced successfully' );
		} catch ( \Exception $e ) {
			EE::warning( 'Encountered error while cloning site. Rolling back.' );

			$source->rollback();
			$destination->rollback();

			EE::error( $e->getMessage() );
		}
	}

	/**
	 * Function to take backup of site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be backed up.
	 *
	 * [--list]
	 * : List all available backups on remote.
	 *
	 * ## EXAMPLES
	 *
	 *     # Backup a site
	 *     $ ee site backup example.com
	 *
	 *     # List all available backups for a site.
	 *     $ ee site backup example.com --list
	 */
	public function backup( $args, $assoc_args ) {
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, true, true, true );
		$backup_restore  = new Site_Backup_Restore();
		$backup_restore->backup( $args, $assoc_args );
	}

	/**
	 * Restore a site from backup.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of the site to be restored.
	 *
	 * [--id=<backup_id>]
	 * : ID of the backup to restore. If not specified, the latest backup will be restored. To get the backup id, run `ee site backup <site_name> --list`
	 *
	 * ## EXAMPLES
	 *
	 *     # Restore latest backup of site.
	 *     $ ee site restore example.com
	 *
	 *     # Restore specific backup of site.
	 *     $ ee site restore example.com --id=1737560626_2025-01-22-15-43-46
	 *
	 */
	public function restore( $args, $assoc_args ) {
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, true, true, true );
		$backup_restore  = new Site_Backup_Restore();
		$backup_restore->restore( $args, $assoc_args );
	}

	abstract public function create( $args, $assoc_args );

	abstract protected function rollback();

	abstract public function dump_docker_compose_yml( $additional_filters = [] );

}
