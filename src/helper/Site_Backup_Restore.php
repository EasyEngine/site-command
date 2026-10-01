<?php

namespace EE\Site\Type;

use EE;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Utils\get_config_value;
use function EE\Utils\delem_log;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;

class Site_Backup_Restore {

	// Error type constants for categorized error reporting
	const ERROR_TYPE_VALIDATION = 'validation_error';       // Invalid input
	const ERROR_TYPE_CONFIG = 'configuration_error';        // Missing config
	const ERROR_TYPE_FILESYSTEM = 'filesystem_error';       // File/dir issues
	const ERROR_TYPE_NETWORK = 'network_error';             // Upload/download
	const ERROR_TYPE_DATABASE = 'database_error';           // DB operations
	const ERROR_TYPE_DISK_SPACE = 'disk_space_error';       // Insufficient space
	const ERROR_TYPE_LOCK = 'lock_error';                   // Concurrent operation
	const ERROR_TYPE_FATAL = 'fatal_error';                 // PHP fatal
	const ERROR_TYPE_INTERRUPTED = 'interrupted';           // Killed/stopped
	const ERROR_TYPE_UNKNOWN = 'unknown_error';             // Unexpected

	// Minimum rclone version that supports each download flag added after the
	// original multi-thread support. Older rclone rejects unknown flags and
	// aborts the copy, so these are only passed when the installed rclone is new
	// enough. (Update if upstream confirms different introduction versions.)
	const RCLONE_MIN_VERSION_MT_WRITE_BUFFER = '1.63.0'; // --multi-thread-write-buffer-size (rclone v1.63.0)
	const RCLONE_MIN_VERSION_MT_CHUNK_SIZE   = '1.64.0'; // --multi-thread-chunk-size (rclone v1.64.0)

	// 2: backups live under <rclone-path>/<site>/<host-id>/ and WP archives hold all of app/ except WP core.
	const BACKUP_FORMAT         = 2;
	const BACKUP_ID_PATTERN     = '/^\d+_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/';
	const DEFAULT_NO_OF_BACKUPS = 7;
	const METADATA_SECRET_KEYS  = [ 'db_password', 'db_root_password', 'app_admin_password', 'site_auth_password' ];
	// DB names that pass both shell layers of `ee shell --command` as they are; no leading '-' (it would be an option).
	const SHELL_SAFE_DB_NAME = '/^[A-Za-z0-9_.@%+=,:][A-Za-z0-9_.@%+=,:-]*$/';

	// app/.ee-restore is the php container's scratch space during a restore; the staged files live next to app/ (get_restore_stage_dir()).
	const RESTORE_STAGE_DIR = '.ee-restore';

	// Root entries of a WordPress install that `wp core download` recreates.
	const WP_CORE_ENTRIES = [
		'wp-admin',
		'wp-includes',
		'index.php',
		'license.txt',
		'readme.html',
		'wp-activate.php',
		'wp-blog-header.php',
		'wp-comments-post.php',
		'wp-config-sample.php',
		'wp-cron.php',
		'wp-links-opml.php',
		'wp-load.php',
		'wp-login.php',
		'wp-mail.php',
		'wp-settings.php',
		'wp-signup.php',
		'wp-trackback.php',
		'xmlrpc.php',
	];

	// Peak of the checked core download on top of the extracted archive: package, its extraction in the container's
	// /tmp, the copy into the stage and WP-CLI's cache (WordPress 7.1: 35 MB package, 117 MB extracted).
	const WP_CORE_SIZE_ESTIMATE = 335544320;

	private $fs;
	public $site_data;
	private $rclone_config_path;

	// Properties for EasyDash callback handling
	private $dash_auth_enabled = false;
	private $dash_backup_id;
	private $dash_verify_token;
	private $dash_api_url;
	private $dash_backup_metadata;
	private $dash_backup_completed = false;
	private $dash_new_backup_path; // Track new backup path for potential rollback

	// Error tracking for EasyDash failure callbacks
	private $dash_error_message = '';
	private $dash_error_type = 'unknown';
	private $dash_error_code = 0;

	// Global backup lock handle for serializing backups
	private $global_backup_lock_handle = null;

	private $backup_host_id = null;

	// State of the running restore (see begin_restore_transaction()); null when none is in progress.
	private $restore_txn = null;

	// metadata.json of the backup being restored.
	private $restore_metadata = [];

	private $restore_in_progress = false;

	// WordPress root the running backup excludes core from ('' = core is archived).
	private $backup_wp_root = null;

	// stderr of the last failed `rclone lsf`.
	private $rclone_error = '';

	// DB dump of the running backup while it is in the scratch dir (backup_db()).
	private $backup_dump_file    = null;
	private $backup_dump_cleanup = false;

	// MySQL option files with the site's DB credentials (write_mysql_defaults_file()).
	private $mysql_defaults_files   = [];
	private $mysql_defaults_cleanup = false;

	// The restore replaced the top-level entries of app/ (htdocs included).
	private $restore_replaced_app = false;

	// The per-site lock file this process created (released on any exit).
	private $site_lock_file = null;

	// Set while a shutdown handler runs: EE::error() would exit and skip the remaining handlers.
	private $in_shutdown = false;

	public function __construct() {
		$this->fs = new Filesystem();
	}

	public function backup( $args, $assoc_args = [] ) {
		delem_log( 'site backup start' );
		$args         = auto_site_name( $args, 'site', __FUNCTION__ );
		$list_backups = \EE\Utils\get_flag_value( $assoc_args, 'list' );
		$dash_auth    = \EE\Utils\get_flag_value( $assoc_args, 'dash-auth' );

		// For --list or --dash-auth, we handle site disabled state specially
		// --list is read-only and should work regardless of site state
		// --dash-auth needs to send API callback instead of just exiting with error
		if ( $list_backups || $dash_auth ) {
			$this->site_data = get_site_info( $args, false, true, true );
		} else {
			$this->site_data = get_site_info( $args, true, true, true );
		}

		// Handle --list flag to display available backups
		if ( $list_backups ) {
			$this->check_rclone_available();
			$this->list_remote_backups();

			return; // Exit after listing backups
		}

		// Handle --dash-auth flag for EasyDash integration
		if ( $dash_auth ) {
			// Parse backup-id:backup-verification-token format
			$auth_parts = explode( ':', $dash_auth, 2 );
			if ( count( $auth_parts ) !== 2 || empty( $auth_parts[0] ) || empty( $auth_parts[1] ) ) {
				EE::error( 'Invalid --dash-auth format. Expected: backup-id:backup-verification-token' );
			}

			// Check for ed-api-url configuration
			$ed_api_url = get_config_value( 'ed-api-url', '' );
			if ( empty( $ed_api_url ) ) {
				EE::error( 'ed-api-url is not configured. Please set it in /opt/easyengine/config/config.yml' );
			}

			// Store dash auth info in class properties for shutdown handler
			// Must be set before any capture_error calls so API callbacks work
			$this->dash_auth_enabled = true;
			$this->dash_backup_id    = $auth_parts[0];
			$this->dash_verify_token = $auth_parts[1];
			$this->dash_api_url      = $ed_api_url;
			// The token also goes into the logged callback payloads.
			if ( method_exists( 'EE', 'obfuscate' ) ) {
				EE::obfuscate( [ $dash_auth, $this->dash_verify_token ] );
			}

			// Debug: Log parsed values
			EE::debug( 'Parsed backup_id: ' . $this->dash_backup_id );
			EE::debug( 'API URL: ' . $this->dash_api_url );

			// Register shutdown handler to send failure callback if backup doesn't complete
			register_shutdown_function( [ $this, 'dash_shutdown_handler' ] );

			// Check if site is disabled - send API callback with error
			if ( ! $this->site_data['site_enabled'] ) {
				$error_msg = sprintf( 'Site %s is disabled. Enable it with `ee site enable %s` to create backup.', $this->site_data['site_url'], $this->site_data['site_url'] );
				$this->capture_error( $error_msg, self::ERROR_TYPE_VALIDATION, 1003 );
				EE::error( $error_msg );
			}
		}

		// Fails on an invalid backup-host-id before any lock is taken.
		$this->get_backup_host_id();

		$this->run_guarded( 'create_backup' );

		delem_log( 'site backup end' );
	}

	/**
	 * Everything of `ee site backup` after the argument checks.
	 */
	private function create_backup() {
		// Acquire global lock to serialize backups (prevents OOM from concurrent backups)
		$this->acquire_global_backup_lock();

		// Register shutdown handler to release lock on any exit (error, crash, etc.)
		register_shutdown_function( [ $this, 'release_global_backup_lock' ] );

		$this->pre_backup_check();
		$this->ensure_no_interrupted_restore();
		$backup_dir = EE_BACKUP_DIR . '/' . $this->site_data['site_url'];

		$this->fs->remove( $backup_dir );
		$this->fs->mkdir( $backup_dir );

		$this->dash_backup_metadata = $this->backup_site_details( $backup_dir );

		switch ( $this->site_data['site_type'] ) {
			case 'html':
				$this->backup_html( $backup_dir );
				break;
			case 'php':
			case 'wp':
				$this->backup_php_wp( $backup_dir );
				break;
			default:
				$this->capture_error(
					sprintf( 'Backup is not supported for site type: %s', $this->site_data['site_type'] ),
					self::ERROR_TYPE_VALIDATION,
					1002
				);
				EE::error( 'Backup is not supported for this site type.' );
		}

		$this->rclone_upload( $backup_dir );
		$this->fs->remove( $backup_dir );

		$this->release_site_lock();

		// Mark backup as completed and send success callback
		$this->dash_backup_completed = true;

		// The callback retries for up to 15 minutes: other sites' backups must not wait for it (BKP-11).
		$this->release_global_backup_lock();

		if ( $this->dash_auth_enabled ) {
			$api_success = $this->send_dash_success_callback(
				$this->dash_api_url,
				$this->dash_backup_id,
				$this->dash_verify_token,
				$this->dash_backup_metadata
			);

			// Only cleanup old backups if API callback succeeded
			// If API failed, rollback the newly uploaded backup
			if ( $api_success ) {
				$this->cleanup_old_backups();
			} else {
				$this->rollback_failed_backup();
			}
		}

		EE::success( 'Backup created successfully.' );
	}

	/**
	 * Shutdown handler to send failure callback to EasyDash if backup didn't complete.
	 * This is called when script terminates (including via EE::error which calls exit).
	 *
	 * Automatically captures fatal errors and interrupted processes if no error was
	 * explicitly captured during backup execution.
	 */
	public function dash_shutdown_handler() {
		// Only send failure callback if dash auth was enabled and backup didn't complete
		if ( $this->dash_auth_enabled && ! $this->dash_backup_completed ) {

			// If no error was captured yet, try to capture shutdown error
			if ( empty( $this->dash_error_message ) ) {
				$last_error = error_get_last();

				// Check if this was a fatal PHP error
				if ( $last_error && in_array( $last_error['type'], [
						E_ERROR,
						E_PARSE,
						E_CORE_ERROR,
						E_COMPILE_ERROR,
						E_USER_ERROR
					] ) ) {
					$this->capture_error(
						sprintf(
							'PHP Fatal Error: %s in %s:%d',
							$last_error['message'],
							basename( $last_error['file'] ),
							$last_error['line']
						),
						self::ERROR_TYPE_FATAL,
						5001
					);
				} else {
					// Script was killed, interrupted, or failed unexpectedly
					$this->capture_error(
						'Backup process was interrupted or killed unexpectedly',
						self::ERROR_TYPE_INTERRUPTED,
						6000
					);
				}
			}

			// The later shutdown handlers would only run after the callback's retries (up to 15 minutes): clean up and
			// release the locks first, so other backups don't wait for EasyDash (BKP-11).
			$this->remove_backup_dump();
			$this->remove_mysql_defaults_files();
			$this->release_site_lock();
			$this->release_global_backup_lock();

			$this->send_dash_failure_callback(
				$this->dash_api_url,
				$this->dash_backup_id,
				$this->dash_verify_token
			);
		}
	}

	/**
	 * Capture error details for EasyDash failure callback.
	 * Stores error information to be sent when backup fails.
	 *
	 * @param string $message Error message describing what went wrong.
	 * @param string $type    Error type category (use ERROR_TYPE_* constants).
	 * @param int $code       Error code for additional context (optional).
	 */
	private function capture_error( $message, $type = self::ERROR_TYPE_UNKNOWN, $code = 0 ) {
		// Only capture the first error (root cause)
		if ( empty( $this->dash_error_message ) ) {
			$this->dash_error_message = $message;
			$this->dash_error_type    = $type;
			$this->dash_error_code    = $code;

			EE::debug( sprintf(
				'Captured error for EasyDash: [%s] %s (code: %d)',
				$type,
				$message,
				$code
			) );
		}
	}

	public function restore( $args, $assoc_args = [] ) {

		delem_log( 'site restore start' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, true, true, true );

		$backup_id  = \EE\Utils\get_flag_value( $assoc_args, 'id' );
		$backup_dir = EE_BACKUP_DIR . '/' . $this->site_data['site_url'];

		// Fails on an invalid backup-host-id before any lock is taken.
		$this->get_backup_host_id();

		if ( $backup_id ) {

			// resolve_restore_path() lists remote backups (rclone lsf) before the
			// pre_restore_check() preflight below, so check rclone here too --
			// otherwise a missing rclone surfaces as a misleading "Invalid backup
			// ID" instead of the friendly "rclone is not installed" message.
			$this->check_rclone_available();

			$this->rclone_config_path = $this->resolve_restore_path( $backup_id );
		}

		// Only once the id is valid: a refused --id left an empty download dir behind (BKP-22).
		if ( ! $this->fs->exists( $backup_dir ) ) {
			$this->fs->mkdir( $backup_dir );
		}

		// Registered before the site lock's release handler so a rollback runs while the lock is still held.
		register_shutdown_function( [ $this, 'rollback_restore_on_shutdown' ] );

		$this->run_guarded( 'run_restore', $backup_dir );

		delem_log( 'site restore end' );
	}

	/**
	 * Everything of `ee site restore` from the pre-checks on.
	 *
	 * @param string $backup_dir Local download dir.
	 */
	private function run_restore( $backup_dir ) {
		$this->pre_restore_check();

		if ( 'wp' === $this->site_data['site_type'] ) {
			$this->restore_wp( $backup_dir );
		} else {
			$this->restore_site( $backup_dir );
		}

		// Only now, with files, DB and config committed; restore_site()/restore_wp() no longer call it (BKP-13).
		$this->maybe_restore_custom_docker_compose( $backup_dir );

		$this->clear_download_dir();
		$this->restore_in_progress = false;

		if ( $this->restore_replaced_app ) {
			// Mounts inside the containers below /var/www/htdocs (admin-tools' ee-admin, custom compose volumes) stay on the old
			// directory until the containers start again.
			EE::log( 'Restarting the site containers.' );
			EE::run_command( [ 'site', 'restart', $this->site_data['site_url'] ], [], [] );
		} else {
			EE::log( 'Reloading site.' );
			EE::run_command( [ 'site', 'reload', $this->site_data['site_url'] ], [], [] );
		}

		$this->release_site_lock();

		EE::success( 'Site restored successfully.' );
	}

	/**
	 * Run a method and turn an uncaught exception into EE::error(): left uncaught, it ends in the site command's
	 * fatal-error handler, which exits before the restore rollback and the lock release handlers run.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 */
	private function run_guarded( $method, ...$args ) {
		try {
			$this->$method( ...$args );
		} catch ( \Throwable $e ) {
			if ( $e instanceof \EE\ExitException ) {
				throw $e;
			}
			$this->capture_error( 'Unexpected error: ' . $e->getMessage(), self::ERROR_TYPE_FATAL, 5004 );
			EE::error( 'Unexpected error: ' . $e->getMessage() );
		}
	}

	private function run_wp_cli_command( $command, $skip_plugins_themes = false ) {
		$shell_command = 'timeout -k 10 --preserve-status 120 wp ';
		if ( $skip_plugins_themes ) {
			$shell_command .= ' --skip-plugins --skip-themes ';
		}
		$shell_command .= $command;
		$output        = EE::launch( "ee shell " . $this->site_data['site_url'] . " --skip-tty --command=\"$shell_command\"" );
		$clean_output  = trim( $output->stdout );

		return empty( $clean_output ) ? '-' : $clean_output;
	}

	/**
	 * Build a child `ee shell` command that runs the current EE binary, not whatever `ee` is on PATH.
	 *
	 * @param string $command Command to run inside the site's php container.
	 *
	 * @return string
	 */
	private function get_ee_shell_command( $command ) {
		$ee_script = class_exists( 'Phar' ) ? \Phar::running( false ) : '';
		if ( '' === $ee_script ) {
			$ee_script = EE_ROOT . '/php/boot-fs.php';
		}

		return sprintf(
			'%s %s shell %s --skip-tty --command=%s',
			escapeshellarg( \EE\Utils\get_php_binary() ),
			escapeshellarg( $ee_script ),
			escapeshellarg( $this->site_data['site_url'] ),
			escapeshellarg( $command )
		);
	}

	private function backup_site_details( $backup_dir ) {

		$backup_data = [];
		if ( 'wp' === $this->site_data['site_type'] ) {

			$post_count    = $this->run_wp_cli_command( 'post list --format=count', true );
			$page_count    = $this->run_wp_cli_command( 'post list --post_type=page --format=count', true );
			$comment_count = $this->run_wp_cli_command( 'comment list --format=count', true );
			$table_prefix  = $this->run_wp_cli_command( 'config get table_prefix', true );

			$query      = 'SELECT COUNT(*) FROM ' . $table_prefix . 'posts WHERE post_type = "attachment"';
			$query_file = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app/htdocs/query.sql';
			$this->fs->dumpFile( $query_file, $query );
			$upload_count = $this->run_wp_cli_command( 'db query < /var/www/htdocs/query.sql --skip-column-names | tr -d \'[:space:]\'', true );
			$upload_count = empty( $upload_count ) ? 0 : $upload_count;
			$this->fs->remove( $query_file );

			$plugin_count = $this->run_wp_cli_command( 'plugin list --format=count' );
			// if it is not a number, then make it -
			$plugin_count = is_numeric( $plugin_count ) ? $plugin_count : '-';
			$theme_count  = $this->run_wp_cli_command( 'theme list --format=count' );
			// if it is not a number, then make it -
			$theme_count = is_numeric( $theme_count ) ? $theme_count : '-';
			$user_count  = $this->run_wp_cli_command( 'user list --format=count', true );
			$wp_version  = $this->run_wp_cli_command( 'core version', true );

			$backup_data = array(
				'site_url'      => $this->site_data['site_url'],
				'site_type'     => $this->site_data['site_type'],
				'post_count'    => $post_count,
				'page_count'    => $page_count,
				'comment_count' => $comment_count,
				'upload_count'  => $upload_count,
				'plugin_count'  => $plugin_count,
				'theme_count'   => $theme_count,
				'user_count'    => $user_count,
				'wp_version'    => $wp_version,
			);

			$plugin_list    = "plugin list --format=json";
			$plugins_output = $this->run_wp_cli_command( $plugin_list );
			$plugins        = [];
			if ( '-' !== $plugins_output && ! empty( $plugins_output ) ) {

				// Check if the output is a valid JSON
				if ( ! json_decode( $plugins_output ) ) {
					EE::warning( 'Failed to get plugin list.' );
				} else {
					$plugins = json_decode( $plugins_output, true );
					$plugins = array_map(
						function ( $plugin ) {
							return [
								'name'    => $plugin['name'],
								'status'  => $plugin['status'],
								'version' => $plugin['version'],
							];
						}, $plugins
					);
				}
			}

			$theme_list    = "theme list --format=json";
			$themes_output = $this->run_wp_cli_command( $theme_list );
			$themes        = [];
			if ( '-' !== $themes_output && ! empty( $themes_output ) ) {

				// Check if the output is a valid JSON
				if ( ! json_decode( $themes_output ) ) {
					EE::warning( 'Failed to get theme list.' );
				} else {
					$themes = json_decode( $themes_output, true );
					$themes = array_map(
						function ( $theme ) {
							return [
								'name'    => $theme['name'],
								'status'  => $theme['status'],
								'version' => $theme['version'],
							];
						}, $themes
					);
				}
			}


			$meta_data = [
				'siteUrl'          => $this->site_data['site_url'],
				'phpVersion'       => $this->site_data['php_version'],
				'wordpressVersion' => $wp_version,
				'plugins'          => [ $plugins ],
				'themes'           => [ $themes ],
			];

			$meta_file = $backup_dir . '/meta.json';
			$this->fs->dumpFile( $meta_file, json_encode( $meta_data, JSON_PRETTY_PRINT ) );
		} else {
			$backup_data = [
				'site_url'  => $this->site_data['site_url'],
				'site_type' => $this->site_data['site_type'],
			];
		}

		$remote_path                = $this->get_remote_path();
		$backup_data['remote_path'] = explode( ':', $remote_path )[1];

		$backup_data['backup_format']   = self::BACKUP_FORMAT;
		$backup_data['backup_host_id']  = $this->get_backup_host_id();
		$backup_data['backup_hostname'] = (string) gethostname();
		if ( 'wp' === $this->site_data['site_type'] ) {
			// Where restore puts WordPress core back; '' = core is in the archive.
			$this->backup_wp_root = (string) $this->get_wp_root_rel();
			// Only releases can be downloaded again (6.8.1, 6.7-RC1, 6.7-beta1); nightlies like 6.9-alpha-60123 can't.
			if ( '' !== $this->backup_wp_root && ! preg_match( '/^\d+\.\d+(\.\d+)?(-(beta|RC)\d+)?$/', (string) $backup_data['wp_version'] ) ) {
				if ( preg_match( '/^\d+\.\d+/', (string) $backup_data['wp_version'] ) ) {
					EE::warning( sprintf( 'WordPress %s can\'t be downloaded again at restore time, so WordPress core is included in the backup.', $backup_data['wp_version'] ) );
				} else {
					EE::warning( 'Could not read the WordPress version, so WordPress core is included in the backup.' );
				}
				$this->backup_wp_root = '';
			}
			$backup_data['wp_root'] = $this->backup_wp_root;
		}

		$backup_data = array_merge( $this->site_data, $backup_data );
		// Credentials stay out of the backup: restore uses the current site's, and the metadata is also kept unencrypted on the remote and in EE_BACKUP_DIR.
		foreach ( self::METADATA_SECRET_KEYS as $key ) {
			unset( $backup_data[ $key ] );
		}

		$backup_data_file = $backup_dir . '/metadata.json';
		$metadata_copy    = EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.metadata.json';

		$this->fs->dumpFile( $backup_data_file, json_encode( $backup_data, JSON_PRETTY_PRINT ) );
		$this->fs->copy( $backup_data_file, $metadata_copy );

		return $backup_data;
	}


	private function maybe_backup_custom_docker_compose( $backup_dir ) {

		$custom_docker_compose = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/docker-compose-custom.yml';
		if ( $this->fs->exists( $custom_docker_compose ) ) {
			$this->fs->copy( $custom_docker_compose, $backup_dir . '/docker-compose-custom.yml' );
		}

		$custom_docker_compose_dir = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/user-docker-compose';

		if ( $this->fs->exists( $custom_docker_compose_dir ) ) {
			$custom_docker_compose_dir_archive = $backup_dir . '/user-docker-compose.zip';
			$archive_command                   = sprintf( 'cd %s && 7z a -mx=1 %s .', $custom_docker_compose_dir, $custom_docker_compose_dir_archive );
			$result                            = EE::launch( $archive_command );

			// 7z exit codes: 0=success, 1=warning (non-fatal), 2+=fatal error
			// This is optional, so we just log a warning instead of failing
			if ( $result->return_code >= 2 ) {
				EE::warning( 'Failed to backup custom docker-compose directory. Continuing with backup.' );
			}
		}
	}

	private function backup_site_dir( $backup_dir, $exclude = [] ) {

		EE::log( 'Backing up site files.' );
		EE::log( 'This may take some time.' );
		$site_dir    = $this->get_site_app_dir();
		$backup_file = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';

		// -snl on every build: 7-Zip 23+ follows symlinks by default (a link to / would pull host files into the backup),
		// p7zip 16.02 and Debian's 26.02 store them as links. Link targets inside app/ are archived at their real path (BKP-10).
		$exclude[] = self::RESTORE_STAGE_DIR;
		// app/ is www-data's home in the php container: its tool caches (WP-CLI keeps every downloaded package) are no site data.
		$exclude = array_merge( $exclude, [ '.wp-cli/cache', '.composer/cache', '.cache' ] );
		if ( ! empty( $this->site_data['db_name'] ) ) {
			// Added before the files: a site file at this path must not replace the fresh dump.
			$exclude[] = 'sql/' . $this->site_data['site_url'] . '.sql';
		}
		$backup_command = sprintf( 'cd %s && 7z a -snl -mx=1 %s . %s', escapeshellarg( $site_dir ), escapeshellarg( $backup_file ), $this->get_7z_exclude_switches( $exclude ) );

		$result = EE::launch( $backup_command );

		// 7z exit codes: 0=success, 1=warning (non-fatal), 2+=fatal error
		// Exit code 1 means warnings (e.g., missing symlink targets) but archive is still created
		if ( $result->return_code >= 2 || ! $this->fs->exists( $backup_file ) ) {
			$this->capture_error(
				'Failed to create site backup archive',
				self::ERROR_TYPE_FILESYSTEM,
				3002
			);
			EE::error( 'Failed to create backup archive. Please check disk space and file permissions.' );
		}

		return $backup_file;
	}

	/**
	 * `-x!` switches for paths relative to the archived directory (anchored there by 7z on all supported builds).
	 *
	 * @param string[] $paths Relative paths.
	 *
	 * @return string
	 */
	private function get_7z_exclude_switches( $paths ) {
		return implode(
			' ',
			array_map(
				function ( $path ) {
					return escapeshellarg( '-x!' . $path );
				},
				array_unique( $paths )
			)
		);
	}

	/**
	 * WordPress root relative to app/ ('htdocs', 'htdocs/current', ...), or null when it has no wp-content.
	 *
	 * @param bool $real Resolve symlinks (e.g. current -> releases/<n>): 7z archives the files at their real path.
	 *
	 * @return string|null
	 */
	private function get_wp_root_rel( $real = true ) {
		$rel     = trim( str_replace( '/var/www/', '', $this->site_data['site_container_fs_path'] ), '/' );
		$app_dir = $this->get_site_app_dir();

		foreach ( [ $rel, $rel . '/current' ] as $candidate ) {
			if ( ! $this->fs->exists( $app_dir . '/' . $candidate . '/wp-content' ) ) {
				continue;
			}

			$root_real = realpath( $app_dir . '/' . $candidate );
			$app_real  = realpath( $app_dir );
			if ( $real && false !== $root_real && false !== $app_real && 0 === strpos( $root_real, $app_real . '/' ) ) {
				return substr( $root_real, strlen( $app_real ) + 1 );
			}

			return $candidate;
		}

		return null;
	}

	private function get_site_app_dir() {
		return EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app';
	}

	private function backup_wp_content_dir( $backup_dir ) {
		$wp_root = $this->get_wp_root_rel();

		if ( null === $wp_root ) {
			EE::warning( 'wp-content directory not found in the site.' );
			EE::log( 'Backing up complete site directory.' );

			return $this->backup_site_dir( $backup_dir ); // Backup all if wp-content not found
		}
		if ( '' === $this->backup_wp_root ) {
			return $this->backup_site_dir( $backup_dir );
		}

		$public_dir = $this->get_site_app_dir() . '/' . trim( str_replace( '/var/www/', '', $this->site_data['site_container_fs_path'] ), '/' );
		if ( $public_dir !== $this->get_site_app_dir() . '/' . $this->get_wp_root_rel( false ) && ! $this->fs->exists( $public_dir . '/wp-cli.yml' ) ) {
			$this->fs->dumpFile( $public_dir . '/wp-cli.yml', 'path: current/' );
		}

		// Everything in app/ except WordPress core, which restore downloads again for meta.json's version (BKP-9).
		// meta.json itself stays next to the archive, so it can't clash with a site file of that name.
		$exclude = [];
		foreach ( self::WP_CORE_ENTRIES as $entry ) {
			$exclude[] = $wp_root . '/' . $entry;
		}
		// Temp files this backup writes into the webroot.
		foreach ( [ 'query.sql', 'db_size_query.sql', $this->site_data['site_url'] . '.sql' ] as $temp_file ) {
			$exclude[] = 'htdocs/' . $temp_file;
		}

		// Symlinks are archived as links, so a target outside app/ is not in the backup.
		$uploads = $this->get_site_app_dir() . '/' . $wp_root . '/wp-content/uploads';
		if ( is_link( $uploads ) ) {
			$target = realpath( $uploads );
			if ( false === $target || 0 !== strpos( $target, realpath( $this->get_site_app_dir() ) . '/' ) ) {
				EE::warning( sprintf( 'wp-content/uploads links to %s, outside the site directory: only the link is backed up, not the uploaded files.', readlink( $uploads ) ) );
			}
		}

		return $this->backup_site_dir( $backup_dir, $exclude );
	}

	private function backup_nginx_conf( $backup_dir ) {
		EE::log( 'Backing up nginx configuration.' );

		$conf_dir       = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/config';
		$backup_file    = $backup_dir . '/conf.zip';
		$backup_command = sprintf( 'cd %s && 7z a -mx=1 %s nginx', $conf_dir, $backup_file );

		$result = EE::launch( $backup_command );

		// 7z exit codes: 0=success, 1=warning (non-fatal), 2+=fatal error
		if ( $result->return_code >= 2 || ! $this->fs->exists( $backup_file ) ) {
			$this->capture_error(
				'Failed to create nginx configuration backup archive',
				self::ERROR_TYPE_FILESYSTEM,
				3002
			);
			EE::error( 'Failed to create nginx configuration backup archive. Please check disk space and file permissions.' );
		}
	}

	private function backup_php_conf( $backup_dir ) {
		EE::log( 'Backing up php configuration.' );

		$conf_dir       = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/config';
		$backup_file    = $backup_dir . '/conf.zip';
		$backup_command = sprintf( 'cd %s && 7z u -mx=1 %s php', $conf_dir, $backup_file );

		$result = EE::launch( $backup_command );

		// 7z exit codes: 0=success, 1=warning (non-fatal), 2+=fatal error
		if ( $result->return_code >= 2 || ! $this->fs->exists( $backup_file ) ) {
			$this->capture_error(
				'Failed to create PHP configuration backup archive',
				self::ERROR_TYPE_FILESYSTEM,
				3002
			);
			EE::error( 'Failed to create PHP configuration backup archive. Please check disk space and file permissions.' );
		}
	}

	private function backup_html( $backup_dir ) {
		$this->backup_site_dir( $backup_dir );
		$this->maybe_backup_custom_docker_compose( $backup_dir );
		$this->backup_nginx_conf( $backup_dir );
	}


	private function backup_php_wp( $backup_dir ) {
		$this->maybe_backup_custom_docker_compose( $backup_dir );
		$this->backup_nginx_conf( $backup_dir );
		$this->backup_php_conf( $backup_dir );

		if ( ! empty( $this->site_data['db_name'] ) ) {
			$this->backup_db( $backup_dir );
		}

		if ( 'wp' === $this->site_data['site_type'] ) {
			$this->backup_wp_content_dir( $backup_dir );
		} else {
			$this->backup_site_dir( $backup_dir );
		}
	}

	private function backup_db( $backup_dir ) {
		// Flush MySQL privileges before backup
		if ( 'running' === \EE_DOCKER::container_status( GLOBAL_DB_CONTAINER ) ) {
			EE::exec( 'docker exec -it ' . GLOBAL_DB_CONTAINER . " bash -c 'mysql --skip-ssl -uroot -p\$MYSQL_ROOT_PASSWORD -e\"FLUSH PRIVILEGES\"'" );
		}

		EE::log( 'Backing up database.' );
		$db_name      = $this->site_data['db_name'];
		$db_user      = $this->site_data['db_user'];
		$db_password  = $this->site_data['db_password'];
		$db_host      = $this->site_data['db_host'];
		$backup_file  = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';
		$sql_filename = $this->site_data['site_url'] . '.sql';
		$sql_file     = $backup_dir . '/sql/' . $sql_filename;

		$this->fs->mkdir( $backup_dir . '/sql' );

		// Dumped into the scratch dir next to the webroot, never into htdocs/, and removed on any failure or exit (BKP-2).
		$dump = $this->prepare_scratch_dir() . '/' . $sql_filename;
		if ( $this->path_exists( $dump ) ) {
			unlink( $dump );
		}
		$this->backup_dump_file = $dump;
		if ( ! $this->backup_dump_cleanup ) {
			register_shutdown_function( [ $this, 'remove_backup_dump' ] );
			$this->backup_dump_cleanup = true;
		}

		$backup_command = sprintf( 'mysqldump --defaults-extra-file=%s --skip-ssl --single-transaction %s > /var/www/%s/%s', $this->write_mysql_defaults_file(), $this->get_shell_safe_db_name(), self::RESTORE_STAGE_DIR, $sql_filename );
		// Checked: the redirect creates the file even when mysqldump fails (e.g. it can't read the option file).
		$result = EE::launch( $this->get_ee_shell_command( $backup_command ) );
		$this->remove_mysql_defaults_files();

		clearstatcache();
		if ( $result->return_code || is_link( $dump ) || ! is_file( $dump ) || 0 === filesize( $dump ) ) {
			$this->remove_backup_dump();
			$this->capture_error(
				sprintf( 'Database backup failed for database: %s', $db_name ),
				self::ERROR_TYPE_DATABASE,
				4002
			);
			EE::error( 'Database backup failed. Please check database credentials and connectivity. ' . trim( $result->stderr ) );
		}

		// mv, not rename(): across filesystems rename() copies a symlink's target, mv moves the link itself.
		$this->assert_scratch_dir();
		$moved = EE::exec( sprintf( 'mv -f %s %s', escapeshellarg( $dump ), escapeshellarg( $sql_file ) ) );
		clearstatcache();
		// The site's code can swap the dump for a symlink until it is moved; 7z would archive the link's host target as root.
		if ( ! $moved || is_link( $sql_file ) || ! is_file( $sql_file ) ) {
			$this->fs->remove( $sql_file );
			$this->capture_error( 'Failed to move the database dump into the backup', self::ERROR_TYPE_DATABASE, 4002 );
			EE::error( 'Database backup failed: the dump could not be moved into the backup, or it was replaced (e.g. by a symlink).' );
		}
		$this->backup_dump_file = null;
		$this->remove_backup_dump();

		$backup_command = sprintf( 'cd %s && 7z u -snl -mx=1 %s sql', $backup_dir, $backup_file );

		$result = EE::launch( $backup_command );

		// 7z exit codes: 0=success, 1=warning (non-fatal), 2+=fatal error
		if ( $result->return_code >= 2 || ! $this->fs->exists( $backup_file ) ) {
			$this->capture_error(
				'Failed to compress database backup into archive',
				self::ERROR_TYPE_FILESYSTEM,
				3002
			);
			EE::error( 'Failed to compress database backup. Please check disk space.' );
		}

		$this->fs->remove( $backup_dir . '/sql' );
	}

	private function maybe_restore_custom_docker_compose( $backup_dir ) {
		$custom_compose_update             = false;
		$custom_docker_compose             = $backup_dir . '/docker-compose-custom.yml';
		$custom_docker_compose_dir_archive = $backup_dir . '/user-docker-compose.zip';

		if ( $this->fs->exists( $custom_docker_compose ) ) {
			$custom_compose_update = true;
			$this->fs->copy( $custom_docker_compose, EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/docker-compose-custom.yml', true );
		}

		if ( $this->fs->exists( $custom_docker_compose_dir_archive ) ) {
			$custom_compose_update     = true;
			$custom_docker_compose_dir = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/user-docker-compose';
			if ( ! $this->fs->exists( $custom_docker_compose_dir ) ) {
				$this->fs->mkdir( $custom_docker_compose_dir );
			}
			$unzip_command = sprintf( 'unzip -o %s -d %s', $custom_docker_compose_dir_archive, $custom_docker_compose_dir );
			EE::exec( $unzip_command );
		}

		if ( $custom_compose_update ) {
			EE::log( 'Custom docker-compose file(s) updated.' );
			EE::run_command( [ 'site', 'enable', $this->site_data['site_url'] ], [ 'force' => true ] );
		}
	}

	private function set_wp_config_db_constants() {
		$db_name     = $this->site_data['db_name'];
		$db_user     = $this->site_data['db_user'];
		$db_password = $this->site_data['db_password'];
		$db_host     = $this->site_data['db_host'];

		// The restored wp-config.php still has the backed-up site's credentials. Each value is read from a file in the
		// container (`\$(cat …)` runs there), so none of them passes the host shells of `ee shell --command` (BKP-15).
		$constants = [
			'DB_NAME'     => $db_name,
			'DB_USER'     => $db_user,
			'DB_PASSWORD' => $db_password,
			'DB_HOST'     => $db_host,
		];
		foreach ( $constants as $constant => $value ) {
			$value_file = $this->write_scratch_file( strtolower( $constant ), (string) $value );
			// `&&`: an unreadable file must fail the step, not set an empty value.
			$command = sprintf( 'value=\\$(cat /var/www/%s/%s) && wp config set %s \\"\\$value\\" --quiet', self::RESTORE_STAGE_DIR, strtolower( $constant ), $constant );
			$this->run_checked_shell_command( $command, sprintf( 'Failed to set %s in wp-config.', $constant ) );
			unlink( $value_file );
		}
	}

	private function restore_db( $sql_file, $container_path ) {
		EE::log( 'Restoring database.' );

		$sql_path = "/var/www/$container_path/" . basename( $sql_file ); // Use basename for safety

		// Keep mysql's stderr (it never echoes the password) so a failed import says why.
		$restore_command = sprintf( 'mysql --defaults-extra-file=%s --skip-ssl %s < %s 2>&1', $this->write_mysql_defaults_file(), $this->get_shell_safe_db_name(), $sql_path );

		// A failed import must abort the restore (and roll it back) instead of being reported as success.
		$this->run_checked_shell_command( $restore_command, 'Database restore failed.' );
		$this->remove_mysql_defaults_files();
	}

	/**
	 * Write the site's DB credentials to a MySQL option file the php container reads, so no credential ever reaches
	 * a shell: `ee shell --command` runs through two shells on the host, where `$(…)` in a password ran as root (BKP-15).
	 *
	 * @return string Path of the file inside the container.
	 */
	private function write_mysql_defaults_file() {
		$name  = 'mysql-' . bin2hex( random_bytes( 6 ) ) . '.cnf';
		$quote = function ( $value ) {
			return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], (string) $value ) . '"';
		};

		if ( ! $this->mysql_defaults_cleanup ) {
			register_shutdown_function( [ $this, 'remove_mysql_defaults_files' ] );
			$this->mysql_defaults_cleanup = true;
		}
		$this->mysql_defaults_files[] = $this->get_restore_scratch_dir() . '/' . $name;

		$this->write_scratch_file(
			$name,
			sprintf( "[client]\nuser=%s\npassword=%s\nhost=%s\n", $quote( $this->site_data['db_user'] ), $quote( $this->site_data['db_password'] ), $quote( $this->site_data['db_host'] ) )
		);

		return '/var/www/' . self::RESTORE_STAGE_DIR . '/' . $name;
	}

	/**
	 * Shutdown safety net as well, so no credential file outlives the command.
	 */
	public function remove_mysql_defaults_files() {
		foreach ( $this->mysql_defaults_files as $file ) {
			if ( $this->path_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->mysql_defaults_files = [];

		$this->remove_empty_scratch_dir();
	}

	/**
	 * Shutdown safety net as well: a failed or interrupted backup leaves no DB dump in the site.
	 */
	public function remove_backup_dump() {
		if ( null !== $this->backup_dump_file && $this->path_exists( $this->backup_dump_file ) ) {
			unlink( $this->backup_dump_file );
		}
		$this->backup_dump_file = null;

		$this->remove_empty_scratch_dir();
	}

	/**
	 * A backup leaves no empty scratch dir behind in the site.
	 */
	private function remove_empty_scratch_dir() {
		$scratch = $this->get_restore_scratch_dir();
		if ( null === $this->restore_txn && ! is_link( $scratch ) && is_dir( $scratch ) && empty( $this->list_dir( $scratch ) ) ) {
			rmdir( $scratch );
		}
	}

	/**
	 * Empty the restore's download dir, EE_BACKUP_DIR/<site>/, except the DB dump `ee site update --ssl` keeps there
	 * (<site>.db) and tells the user to restore from.
	 */
	private function clear_download_dir() {
		$dir = EE_BACKUP_DIR . '/' . $this->site_data['site_url'];
		foreach ( $this->list_dir( $dir ) as $entry ) {
			if ( $this->site_data['site_url'] . '.db' !== $entry ) {
				$this->fs->remove( $dir . '/' . $entry );
			}
		}
		if ( is_dir( $dir ) && empty( $this->list_dir( $dir ) ) ) {
			rmdir( $dir );
		}
	}

	/**
	 * The php container's scratch dir, as a real directory it can write to. app/ belongs to the site's own user, who can
	 * put a symlink there that resolves anywhere on the host, so root checks it right before every use.
	 *
	 * @return string Host path.
	 */
	private function prepare_scratch_dir() {
		$scratch = $this->get_restore_scratch_dir();

		clearstatcache();
		if ( is_link( $scratch ) || ( file_exists( $scratch ) && ! is_dir( $scratch ) ) ) {
			unlink( $scratch );
		}
		if ( ! is_dir( $scratch ) && ! mkdir( $scratch, 0755 ) && ! is_dir( $scratch ) ) {
			$this->fail( sprintf( 'Could not create %s.', $scratch ) );
		}
		$this->assert_scratch_dir();
		if ( ! $this->chown_to_site_user( $scratch ) ) {
			$this->fail( sprintf( 'Could not hand %s to the site user.', $scratch ) );
		}

		return $scratch;
	}

	/**
	 * Fail unless app/.ee-restore is still the real directory inside app/ (not a symlink the site put in its place).
	 */
	private function assert_scratch_dir() {
		$scratch  = $this->get_restore_scratch_dir();
		$app_real = realpath( $this->get_site_app_dir() );

		clearstatcache();
		if ( is_link( $scratch ) || ! is_dir( $scratch ) || false === $app_real || realpath( $scratch ) !== $app_real . '/' . self::RESTORE_STAGE_DIR ) {
			$this->fail( sprintf( '%s is not a directory inside the site (it was replaced, e.g. by a symlink); stopping.', $scratch ) );
		}
	}

	/**
	 * Create a file only the container's site user can read, without following symlinks at the file or its dir.
	 *
	 * @param string $name    File name in the scratch dir.
	 * @param string $content Content.
	 *
	 * @return string Host path.
	 */
	private function write_scratch_file( $name, $content ) {
		$file = $this->prepare_scratch_dir() . '/' . $name;

		// A leftover or planted entry of that name goes first: 'x' (O_EXCL) refuses to open through a symlink.
		if ( $this->path_exists( $file ) ) {
			unlink( $file );
		}
		$umask  = umask( 0077 );
		$handle = @fopen( $file, 'x' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported below.
		umask( $umask );
		if ( false === $handle || false === fwrite( $handle, $content ) || ! fclose( $handle ) ) {
			$this->fail( sprintf( 'Could not write %s.', $file ) );
		}
		if ( ! $this->chown_to_site_user( $file ) ) {
			unlink( $file );
			$this->fail( sprintf( 'Could not hand %s to the site user.', $file ) );
		}

		return $file;
	}

	/**
	 * chown to www-data without following symlinks (-h, and -R never follows them).
	 *
	 * @param string $path      Host path.
	 * @param bool   $recursive Whole tree.
	 *
	 * @return bool
	 */
	private function chown_to_site_user( $path, $recursive = false ) {
		// Docker Desktop maps file ownership itself, and macOS has no www-data user.
		if ( IS_DARWIN ) {
			return true;
		}

		return EE::exec( sprintf( 'chown %s www-data:www-data %s', $recursive ? '-R' : '-h', escapeshellarg( $path ) ) );
	}

	/**
	 * Remove the scratch dir without following symlinks: a symlink is unlinked, a directory goes with `rm -rf`, which
	 * doesn't follow links it meets while deleting.
	 */
	private function remove_scratch_dir() {
		$scratch = $this->get_restore_scratch_dir();

		clearstatcache();
		if ( is_link( $scratch ) || is_file( $scratch ) ) {
			unlink( $scratch );
		} elseif ( is_dir( $scratch ) ) {
			EE::exec( sprintf( 'rm -rf %s', escapeshellarg( $scratch ) ) );
		}
	}

	/**
	 * EE::error() normally; an exception inside shutdown handlers, where exiting would skip the remaining handlers.
	 *
	 * @param string $message Message.
	 */
	private function fail( $message ) {
		if ( $this->in_shutdown ) {
			throw new \RuntimeException( $message );
		}
		EE::error( $message );
	}

	/**
	 * The DB name goes into container commands as is, so it must not hold anything a shell would interpret.
	 *
	 * @return string
	 */
	private function get_shell_safe_db_name() {
		$db_name = (string) $this->site_data['db_name'];

		if ( ! preg_match( self::SHELL_SAFE_DB_NAME, $db_name ) ) {
			$this->capture_error( 'Unsupported database name', self::ERROR_TYPE_VALIDATION, 1005 );
			EE::error( sprintf( 'The database name "%s" contains characters that are not supported for backup and restore.', $db_name ) );
		}

		return $db_name;
	}

	/**
	 * Run a command inside the site container via `ee shell` and abort on failure.
	 *
	 * EE::run_command() does not surface the in-container command's exit code, so
	 * critical restore steps are launched as a child `ee shell` process (which exits
	 * non-zero when the command fails) and the return code is checked here.
	 *
	 * @param string $command       Command to run inside the container.
	 * @param string $error_message Message for EE::error() if the command fails.
	 *
	 * @return object The finished process (stdout, stderr).
	 */
	private function run_checked_shell_command( $command, $error_message ) {
		$output = EE::launch( $this->get_ee_shell_command( $command ) );

		if ( $output->return_code ) {
			EE::error( $error_message . ' ' . trim( $output->stdout . ' ' . $output->stderr ) );
		}

		return $output;
	}

	/**
	 * html and php sites: the archive is app/ as a whole (plus sql/<site>.sql), swapped in as a whole.
	 *
	 * @param string $backup_dir Local directory holding the downloaded backup.
	 */
	private function restore_site( $backup_dir ) {
		$backup_app = $this->get_downloaded_archive( $backup_dir );

		$this->begin_restore_transaction( $backup_app, 0 );

		EE::log( 'Restoring site files.' );
		$this->extract_to_stage( $backup_app, $this->restore_txn['new'] );
		$this->stage_db_dump( $this->restore_txn['new'] . '/sql' );
		$this->plan_app_swap();

		$this->apply_restore_transaction( $backup_dir );
	}

	/**
	 * WordPress: backup format 2 is app/ without core (swapped in as a whole, core downloaded next to it);
	 * older backups hold only wp-config.php, wp-content and the DB, which replace just those parts.
	 *
	 * @param string $backup_dir Local directory holding the downloaded backup.
	 */
	private function restore_wp( $backup_dir ) {
		$backup_app = $this->get_downloaded_archive( $backup_dir );
		$format     = (int) ( isset( $this->restore_metadata['backup_format'] ) ? $this->restore_metadata['backup_format'] : 1 );

		if ( $format >= 2 ) {
			$wp_root = $this->get_backup_wp_root();
			// No version is needed (and none may be known) when core is in the archive.
			$wp_version = '' === $wp_root ? '' : $this->get_backup_wp_version( $backup_dir, $backup_app );

			$this->begin_restore_transaction( $backup_app, '' === $wp_root ? 0 : self::WP_CORE_SIZE_ESTIMATE );

			EE::log( 'Restoring site files.' );
			$this->extract_to_stage( $backup_app, $this->restore_txn['new'] );
			$this->stage_db_dump( $this->restore_txn['new'] . '/sql' );
			if ( '' !== $wp_root ) {
				$this->stage_wp_core( $wp_version, $wp_root, false );
			}
			$this->plan_app_swap();
		} else {
			// Where develop's backup took wp-content from (it also looked in <public dir>/current).
			$wp_root = $this->get_wp_root_rel( false );
			if ( null === $wp_root ) {
				$wp_root = trim( str_replace( '/var/www/', '', $this->site_data['site_container_fs_path'] ), '/' );
			}

			// These backups hold no core; without a version the current core stays.
			$wp_version = $this->get_backup_wp_version( $backup_dir, $backup_app, false );
			$this->begin_restore_transaction( $backup_app, '' === $wp_version ? 0 : self::WP_CORE_SIZE_ESTIMATE );

			EE::log( 'Restoring site files.' );
			$this->stage_legacy_wp_archive( $backup_app, $wp_root );
			if ( '' === $wp_version ) {
				EE::warning( 'The backup does not record its WordPress version, so the current WordPress core is kept.' );
			} else {
				$this->stage_wp_core( $wp_version, $wp_root, true );
			}
		}

		$this->apply_restore_transaction( $backup_dir );
	}

	/**
	 * @param string $backup_dir Local directory holding the downloaded backup.
	 *
	 * @return string Path of the site archive.
	 */
	private function get_downloaded_archive( $backup_dir ) {
		$backup_app = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';

		if ( ! $this->fs->exists( $backup_app ) ) {
			$this->rclone_download( $backup_dir );
		}
		if ( ! $this->fs->exists( $backup_app ) ) {
			EE::error( sprintf( 'The backup has no site archive (%s). The site was not changed.', basename( $backup_app ) ) );
		}

		return $backup_app;
	}

	/**
	 * WordPress version of the backup, validated before anything is changed.
	 *
	 * @param string $backup_dir Local directory holding the downloaded backup.
	 * @param string $backup_app Site archive.
	 *
	 * @return string
	 */
	private function get_backup_wp_version( $backup_dir, $backup_app, $required = true ) {
		$meta_file = $backup_dir . '/meta.json';

		// Format 2 uploads meta.json next to the archive; older backups keep it inside.
		if ( ! $this->fs->exists( $meta_file ) ) {
			EE::exec( sprintf( 'unzip -o %s meta.json -d %s', escapeshellarg( $backup_app ), escapeshellarg( $backup_dir ) ) );
		}

		$meta_data = $this->fs->exists( $meta_file ) ? json_decode( (string) file_get_contents( $meta_file ), true ) : null;
		// Only [0-9A-Za-z.-]: the version ends up in a shell command in the container.
		$wp_version = is_array( $meta_data ) && isset( $meta_data['wordpressVersion'] ) ? preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) $meta_data['wordpressVersion'] ) : '';

		// '-' is what backup_site_details() stores when WP-CLI failed.
		if ( '' === $wp_version || '-' === $wp_version ) {
			if ( ! $required ) {
				return '';
			}
			EE::error( 'Backup metadata (meta.json) is missing or has no WordPress version. The site was not changed.' );
		}

		return $wp_version;
	}

	/**
	 * WordPress root recorded by a format 2 backup, relative to app/ ('' = core is inside the archive).
	 *
	 * @return string
	 */
	private function get_backup_wp_root() {
		$wp_root = trim( (string) ( isset( $this->restore_metadata['wp_root'] ) ? $this->restore_metadata['wp_root'] : '' ), '/' );

		// Only used in PHP file operations (no shell), checked again with realpath() in stage_wp_core().
		if ( preg_match( '#[\x00-\x1f]|(^|/)\.\.?(/|$)#', $wp_root ) ) {
			EE::error( 'Backup metadata has an invalid wp_root. The site was not changed.' );
		}

		return $wp_root;
	}

	/**
	 * Start a restore: nothing live changes until apply_restore_transaction(), and every change after that is undone
	 * on failure (rollback_restore_on_shutdown()) or, after a kill, by the next restore (recover_interrupted_restore()).
	 *
	 * @param string $backup_app  Site archive.
	 * @param int    $extra_bytes Space needed on top of the extracted archive.
	 */
	private function begin_restore_transaction( $backup_app, $extra_bytes ) {
		$app_dir = $this->get_site_app_dir();
		$stage   = $this->get_restore_stage_dir();

		$this->check_restore_disk_space( $backup_app, $app_dir, $extra_bytes );

		// Renaming a directory moves any mount below it along with the old files, and the cleanup would then empty it.
		$mounts = $this->get_mount_points_under( $app_dir );
		if ( ! empty( $mounts ) ) {
			EE::error( sprintf( "These mount points are inside the site directory:\n%s\nUnmount them before a restore. The site was not changed.", implode( "\n", $mounts ) ) );
		}

		if ( 0 === strpos( $stage, $this->get_restore_scratch_dir() . '/' ) ) {
			EE::warning( 'app/ is on a filesystem of its own, so the restore stages the files inside it, where the site\'s own code can reach them.' );
		}
		$this->remove_scratch_dir();
		$this->fs->remove( $stage );
		$this->fs->mkdir( [ $stage . '/new', $stage . '/old' ], 0700 );

		$this->restore_txn = [
			'stage' => $stage,
			'new'   => $stage . '/new',
			'pairs' => [],
			'db'    => 'none',
			'conf'  => false,
		];
		$this->write_restore_journal();
	}

	/**
	 * Private staging dir on the filesystem of app/: on Linux the htdocs volume's own dir (root only, not in any
	 * container), on macOS the site dir. Falls back to app/ itself when that isn't possible.
	 *
	 * @return string
	 */
	private function get_restore_stage_dir() {
		$app_dir  = $this->get_site_app_dir();
		$app_real = realpath( $app_dir );

		if ( false !== $app_real ) {
			$parent     = dirname( $app_real );
			// Both exist: realpath() resolved them.
			$parent_stat = stat( $parent );
			$app_stat    = stat( $app_real );
			if ( $parent_stat['dev'] === $app_stat['dev'] && is_writable( $parent ) ) {
				return $parent . '/' . self::RESTORE_STAGE_DIR;
			}
		}

		return $app_dir . '/' . self::RESTORE_STAGE_DIR . '/stage';
	}

	/**
	 * Dir the php container reads and writes during a restore (core download, DB dumps); /var/www/.ee-restore inside.
	 *
	 * @return string
	 */
	private function get_restore_scratch_dir() {
		return $this->get_site_app_dir() . '/' . self::RESTORE_STAGE_DIR;
	}

	/**
	 * @param string $dir Directory.
	 *
	 * @return string[] Mount points strictly below $dir (Linux; none elsewhere).
	 */
	private function get_mount_points_under( $dir ) {
		$real   = realpath( $dir );
		$mounts = [];
		$info   = ( false !== $real && is_readable( '/proc/self/mountinfo' ) ) ? file( '/proc/self/mountinfo', FILE_IGNORE_NEW_LINES ) : [];

		foreach ( $info as $line ) {
			$fields = explode( ' ', $line );
			if ( ! isset( $fields[4] ) ) {
				continue;
			}
			// Spaces and friends are octal-escaped (\040).
			$point = preg_replace_callback(
				'/\\\\([0-7]{3})/',
				function ( $m ) {
					return chr( octdec( $m[1] ) );
				},
				$fields[4]
			);
			if ( 0 === strpos( $point, $real . '/' ) ) {
				$mounts[] = $point;
			}
		}

		return $mounts;
	}

	private function get_restore_journal_file() {
		return EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.restore.json';
	}

	private function write_restore_journal() {
		$journal = json_encode( $this->restore_txn, JSON_PRETTY_PRINT );
		$this->fs->dumpFile( $this->get_restore_journal_file(), $journal );
		// Second copy with the files it describes, in case EE_BACKUP_DIR is cleaned by hand.
		$this->fs->dumpFile( $this->restore_txn['stage'] . '/journal.json', $journal );
	}

	/**
	 * Journal of an unfinished restore that changed the site, or null.
	 *
	 * @return array|null
	 */
	private function read_unfinished_restore_journal() {
		$unfinished = null;
		foreach ( [ $this->get_restore_journal_file(), $this->get_restore_stage_dir() . '/journal.json' ] as $file ) {
			$journal = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
			if ( ! is_array( $journal ) || ! isset( $journal['stage'], $journal['pairs'], $journal['db'] ) ) {
				continue;
			}
			// The copies are written one after the other: a kill in between leaves one committed and one not.
			if ( ! empty( $journal['committed'] ) ) {
				return null;
			}
			// A journal without its stage dir is from a deleted site: nothing to roll back.
			if ( null === $unfinished && is_dir( $journal['stage'] ) ) {
				$unfinished = $journal;
			}
		}

		return null !== $unfinished && $this->restore_has_changes( $unfinished ) ? $unfinished : null;
	}

	/**
	 * Refuse to extract a backup that doesn't fit next to the current files, which stay until the restore is done (BKP-6).
	 *
	 * @param string $backup_app  Site archive.
	 * @param string $app_dir     Site app/ dir.
	 * @param int    $extra_bytes Space needed on top of the extracted archive.
	 */
	private function check_restore_disk_space( $backup_app, $app_dir, $extra_bytes ) {
		$listing = EE::launch( sprintf( 'unzip -Zt %s', escapeshellarg( $backup_app ) ) );

		if ( $listing->return_code || ! preg_match( '/([0-9]+) bytes uncompressed/', $listing->stdout, $matches ) ) {
			EE::error( sprintf( 'Could not read the backup archive %s; it may be corrupt. The site was not changed.', basename( $backup_app ) ) );
		}

		$required = (float) $matches[1] + $extra_bytes;
		if ( ! empty( $this->site_data['db_name'] ) ) {
			$required += $this->get_db_size(); // Copy of the current DB, kept for a rollback.
		}
		$required = (int) ceil( $required * 1.1 );

		$free_space = disk_free_space( $app_dir );
		if ( false === $free_space ) {
			EE::error( 'Unable to determine free disk space for the site directory.' );
		}

		EE::debug( sprintf( 'Restore needs %d bytes next to the site files, %d free.', $required, $free_space ) );

		if ( $required > $free_space ) {
			EE::error(
				sprintf(
					"Not enough disk space to restore the backup next to the current site files.\n" .
					"Required: %s (%s bytes)\n" .
					"Available: %s (%s bytes)\n" .
					'Please free up some space and try again. The site was not changed.',
					$this->format_bytes( $required ),
					number_format( $required ),
					$this->format_bytes( $free_space ),
					number_format( $free_space )
				)
			);
		}
	}

	/**
	 * @param string   $zip      Archive.
	 * @param string   $dest     Directory to extract into.
	 * @param string[] $patterns Entries to extract (all when empty).
	 * @param string[] $excludes Entries to skip.
	 *
	 * @return bool False when no entry matched $patterns.
	 */
	private function extract_to_stage( $zip, $dest, $patterns = [], $excludes = [] ) {
		$command = sprintf( 'unzip -qq -o %s', escapeshellarg( $zip ) );
		foreach ( $patterns as $pattern ) {
			$command .= ' ' . escapeshellarg( $pattern );
		}
		if ( ! empty( $excludes ) ) {
			$command .= ' -x';
			foreach ( $excludes as $exclude ) {
				$command .= ' ' . escapeshellarg( $exclude );
			}
		}
		// No stdin: on a full disk unzip asks "Continue? (y/n)" and would wait on the user's terminal.
		$command .= ' -d ' . escapeshellarg( $dest ) . ' < /dev/null';

		$result = EE::launch( $command );

		// unzip: 1 = warnings only, 11 = no matching entry.
		if ( 11 === $result->return_code && ! empty( $patterns ) ) {
			return false;
		}
		if ( $result->return_code > 1 ) {
			EE::error( sprintf( 'Failed to extract the backup archive (unzip exit code %d). %s', $result->return_code, trim( $result->stdout . ' ' . $result->stderr ) ) );
		}

		return true;
	}

	/**
	 * Move the backup's DB dump out of the staged files.
	 *
	 * @param string $sql_dir Directory the archive's sql/ was extracted to.
	 */
	private function stage_db_dump( $sql_dir ) {
		if ( empty( $this->site_data['db_name'] ) ) {
			return;
		}

		$dump = $sql_dir . '/' . $this->site_data['site_url'] . '.sql';
		if ( ! $this->fs->exists( $dump ) ) {
			EE::error( sprintf( 'The backup has no database dump (sql/%s). The site was not changed.', basename( $dump ) ) );
		}

		$this->rename_or_fail( $dump, $this->restore_txn['stage'] . '/db.sql' );
		// Only the dump is ours; a site's own sql/ dir stays.
		if ( is_dir( $sql_dir ) && empty( $this->list_dir( $sql_dir ) ) ) {
			rmdir( $sql_dir );
		}

		$this->restore_txn['has_db'] = true;
	}

	/**
	 * Swap every top-level entry of app/ (dotfiles included, BKP-14) for the staged one; entries missing from the backup go.
	 */
	private function plan_app_swap() {
		$app_dir = $this->get_site_app_dir();
		$new     = $this->restore_txn['new'];
		$names   = array_unique( array_merge( $this->list_dir( $app_dir ), $this->list_dir( $new ) ) );
		sort( $names );

		foreach ( $names as $name ) {
			if ( self::RESTORE_STAGE_DIR !== $name ) {
				$this->add_restore_pair( $app_dir . '/' . $name, $new . '/' . $name );
			}
		}
		$this->restore_replaced_app = true;
	}

	/**
	 * Stage a pre-format-2 WordPress archive: wp-config.php, wp-content and the DB replace only those parts of the site.
	 *
	 * @param string $backup_app Site archive.
	 * @param string $wp_root    WordPress root relative to app/.
	 */
	private function stage_legacy_wp_archive( $backup_app, $wp_root ) {
		$app_dir  = $this->get_site_app_dir();
		$stage    = $this->restore_txn['stage'];
		$new_root = $this->restore_txn['new'] . '/' . $wp_root;
		$live_wc  = $app_dir . '/' . $wp_root . '/wp-content';
		$live_up  = $live_wc . '/uploads';
		$new_wc   = $new_root . '/wp-content';
		$new_up   = $new_wc . '/uploads';

		$this->fs->mkdir( $new_root );

		// Never the wp-content/uploads symlink entry some 7-Zip builds stored: restoring it made an uploads/uploads loop (BKP-10).
		if ( ! $this->extract_to_stage( $backup_app, $new_root, [ 'wp-content/*' ], [ 'wp-content/uploads' ] ) ) {
			EE::error( 'The backup archive has no wp-content directory. The site was not changed.' );
		}

		// wp-config.php was taken from one level above the WordPress root.
		if ( $this->extract_to_stage( $backup_app, dirname( $new_root ), [ 'wp-config.php' ] ) ) {
			$this->add_restore_pair( dirname( $app_dir . '/' . $wp_root ) . '/wp-config.php', dirname( $new_root ) . '/wp-config.php' );
		}

		if ( ! empty( $this->site_data['db_name'] ) ) {
			$this->extract_to_stage( $backup_app, $stage, [ 'sql/' . $this->site_data['site_url'] . '.sql' ] );
			$this->stage_db_dump( $stage . '/sql' );
		}

		$keep_live_uploads = false;
		if ( is_link( $live_up ) ) {
			$keep_live_uploads = true;
			$target            = realpath( $live_up );
			$app_real          = realpath( $app_dir );
			$wc_real           = realpath( $live_wc );

			if ( ! $this->path_exists( $new_up ) ) {
				EE::warning( 'The backup has no uploaded files (only the wp-content/uploads symlink); the current uploads are kept.' );
			} elseif ( false !== $target && is_dir( $target ) && 0 === strpos( $target, $app_real . '/' ) && ( false === $wc_real || 0 !== strpos( $target . '/', $wc_real . '/' ) ) ) {
				// The files go where the link points, swapped like the rest.
				$this->rename_or_fail( $new_up, $stage . '/uploads' );
				$this->add_restore_pair( $target, $stage . '/uploads' );
			} else {
				// Inside wp-content they are already in the archive; outside the site they are not written through the link.
				$this->fs->remove( $new_up );
				if ( false === $target || 0 !== strpos( $target, $app_real . '/' ) ) {
					EE::warning( sprintf( 'wp-content/uploads links outside the site (%s); the uploaded files in the backup were not restored.', readlink( $live_up ) ) );
				}
			}
		} elseif ( ! $this->path_exists( $new_up ) && $this->path_exists( $live_up ) ) {
			$keep_live_uploads = true;
			EE::warning( 'The backup has no uploaded files; the current uploads are kept.' );
		}

		if ( $keep_live_uploads ) {
			// Moved aside, then back into the restored wp-content.
			$aside = count( $this->restore_txn['pairs'] );
			$this->add_restore_pair( $live_up );
			$this->add_restore_pair( $live_wc, $new_wc );
			$this->add_restore_pair( $live_up, $this->restore_txn['pairs'][ $aside ]['old'] );
		} else {
			$this->add_restore_pair( $live_wc, $new_wc );
		}
	}

	/**
	 * Download WordPress core in the container and move it into the staged WordPress root.
	 *
	 * @param string $wp_version Version from meta.json.
	 * @param string $wp_root    WordPress root relative to app/.
	 * @param bool   $legacy     Also swap each core entry of the live site (pre-format-2 backups).
	 */
	private function stage_wp_core( $wp_version, $wp_root, $legacy ) {
		$scratch  = $this->get_restore_scratch_dir();
		$core_dir = $scratch . '/core';
		$new_root = $this->restore_txn['new'] . '/' . $wp_root;

		// Check the deepest existing part first: a symlink from the archive must not make root create dirs elsewhere.
		$real_new = realpath( $this->restore_txn['new'] );
		$existing = $new_root;
		while ( ! $this->path_exists( $existing ) ) {
			$existing = dirname( $existing );
		}
		$real_existing = realpath( $existing );
		if ( false === $real_existing || ( $real_existing !== $real_new && 0 !== strpos( $real_existing, $real_new . '/' ) ) ) {
			EE::error( 'The WordPress root of the backup is outside the site. The site was not changed.' );
		}

		$this->fs->mkdir( $new_root );
		$real_root = realpath( $new_root );
		if ( false === $real_root || ( $real_root !== $real_new && 0 !== strpos( $real_root, $real_new . '/' ) ) ) {
			EE::error( 'The WordPress root of the backup is outside the site. The site was not changed.' );
		}
		// Only the download dir goes to the site user; a fallback stage inside the scratch dir stays root's.
		$this->prepare_scratch_dir();
		if ( ! mkdir( $core_dir, 0755 ) || ! $this->chown_to_site_user( $core_dir ) ) {
			EE::error( 'Failed to prepare the WordPress core download. The site was not changed.' );
		}

		// Same download, extraction and checksum gate as site creation, but into the private core dir.
		$core_download_command = \EE\Site\Utils\get_wp_core_download_command(
			'/var/www/' . self::RESTORE_STAGE_DIR . '/core',
			[
				'version' => $wp_version,
				'force'   => true,
			]
		);
		$result = $this->run_checked_shell_command( $core_download_command, 'Failed to download WordPress core for restore. The site was not changed.' );
		// The output is captured, so pass on the checksum gate's warning (checksums unavailable from WordPress.org).
		if ( preg_match_all( '/^Warning: Could not verify WordPress core checksums.*$/m', $result->stdout . "\n" . $result->stderr, $warnings ) ) {
			foreach ( array_unique( $warnings[0] ) as $warning ) {
				EE::warning( substr( $warning, strlen( 'Warning: ' ) ) );
			}
		}

		// Out of the site user's reach before anything in it is listed or moved.
		$this->assert_scratch_dir();
		$downloaded = $this->restore_txn['stage'] . '/core';
		$this->rename_or_fail( $core_dir, $downloaded );
		if ( is_link( $downloaded ) || ! is_dir( $downloaded ) ) {
			EE::error( 'The WordPress core download is not a directory. The site was not changed.' );
		}

		$core_entries = array_diff( $this->list_dir( $downloaded ), [ 'wp-content' ] );
		if ( ! in_array( 'wp-includes', $core_entries, true ) ) {
			EE::error( 'The WordPress core download is incomplete. The site was not changed.' );
		}

		foreach ( $core_entries as $entry ) {
			if ( $this->path_exists( $new_root . '/' . $entry ) ) {
				$this->fs->remove( $new_root . '/' . $entry );
			}
			$this->rename_or_fail( $downloaded . '/' . $entry, $new_root . '/' . $entry );
		}
		$this->fs->remove( $downloaded );

		if ( $legacy ) {
			$live_root = $this->get_site_app_dir() . '/' . $wp_root;
			foreach ( array_unique( array_merge( $core_entries, self::WP_CORE_ENTRIES ) ) as $entry ) {
				$this->add_restore_pair( $live_root . '/' . $entry, $new_root . '/' . $entry );
			}
		}
	}

	/**
	 * Replace the live files, DB and config with the staged ones; any failure from here on is rolled back.
	 *
	 * @param string $backup_dir Local directory holding the downloaded backup.
	 */
	private function apply_restore_transaction( $backup_dir ) {
		$stage = $this->restore_txn['stage'];

		if ( ! $this->chown_to_site_user( $this->restore_txn['new'], true ) || ( $this->fs->exists( $stage . '/uploads' ) && ! $this->chown_to_site_user( $stage . '/uploads', true ) ) ) {
			EE::error( 'Failed to set the owner of the restored files. The site was not changed.' );
		}

		if ( ! empty( $this->restore_txn['has_db'] ) ) {
			$this->snapshot_current_db();

			$this->move_db_dump( $stage . '/db.sql', $this->get_restore_scratch_dir() . '/db.sql' );
			$this->restore_txn['db'] = 'importing';
			$this->write_restore_journal();
			$this->restore_db( 'db.sql', self::RESTORE_STAGE_DIR );
		}

		$this->swap_in_restore_pairs();

		if ( 'wp' === $this->site_data['site_type'] ) {
			$this->ensure_wp_cli_yml();
			$this->set_wp_config_db_constants();
		}

		$this->snapshot_site_config();
		$this->restore_nginx_conf( $backup_dir );
		if ( in_array( $this->site_data['site_type'], [ 'php', 'wp' ], true ) ) {
			$this->restore_php_conf( $backup_dir );
		}

		$this->commit_restore_transaction();

		if ( 'wp' === $this->site_data['site_type'] ) {
			$flush = EE::launch( $this->get_ee_shell_command( 'wp cache flush --skip-plugins --skip-themes' ) );
			if ( $flush->return_code ) {
				EE::warning( 'Could not flush the WordPress object cache.' );
			}
		}
	}

	/**
	 * Dump the current DB so a failed restore can put it back.
	 */
	private function snapshot_current_db() {
		EE::log( 'Saving the current database.' );

		$scratch = $this->prepare_scratch_dir();

		$command = sprintf( 'mysqldump --defaults-extra-file=%s --skip-ssl --single-transaction %s > /var/www/%s/db-before.sql', $this->write_mysql_defaults_file(), $this->get_shell_safe_db_name(), self::RESTORE_STAGE_DIR );
		$result  = EE::launch( $this->get_ee_shell_command( $command ) );
		$this->remove_mysql_defaults_files();

		clearstatcache();
		if ( $result->return_code || ! is_file( $scratch . '/db-before.sql' ) || 0 === filesize( $scratch . '/db-before.sql' ) ) {
			EE::error( 'Could not save the current database before the restore. The site was not changed. ' . trim( $result->stderr ) );
		}

		// Out of the container's reach; a rollback reads it through a hard link, so it never leaves the stage.
		$saved = $this->restore_txn['stage'] . '/db-before.sql';
		$this->move_db_dump( $scratch . '/db-before.sql', $saved );
		// The site user could have left a symlink of that name; chown() and chmod() would follow it.
		if ( is_link( $saved ) || ! is_file( $saved ) ) {
			EE::error( 'The saved copy of the current database is not a regular file. The site was not changed.' );
		}
		chown( $saved, 0 );
		chmod( $saved, 0600 );
		$this->restore_txn['db'] = 'saved';
		$this->write_restore_journal();
	}

	private function move_db_dump( $from, $to ) {
		// Checked right before the rename: moving into or out of a replaced scratch dir would touch whatever it points to.
		$this->assert_scratch_dir();
		$this->rename_or_fail( $from, $to );
		if ( 0 === strpos( $to, $this->get_restore_scratch_dir() . '/' ) ) {
			$this->chown_to_site_user( $to );
		}
	}

	/**
	 * Copy the site's nginx and php config so a rollback can put it back.
	 */
	private function snapshot_site_config() {
		$config_dir = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/config';
		$saved_dir  = $this->restore_txn['stage'] . '/config-before';

		foreach ( [ 'nginx', 'php' ] as $dir ) {
			if ( is_dir( $config_dir . '/' . $dir ) ) {
				$this->fs->mkdir( $saved_dir . '/' . $dir );
				$this->exec_or_fail( sprintf( 'rsync -a %s/ %s/', escapeshellarg( $config_dir . '/' . $dir ), escapeshellarg( $saved_dir . '/' . $dir ) ), 'Could not save the current site configuration.' );
			}
		}

		$this->restore_txn['conf'] = true;
		$this->write_restore_journal();
	}

	private function swap_in_restore_pairs() {
		foreach ( $this->restore_txn['pairs'] as $i => $pair ) {
			if ( $this->path_exists( $pair['live'] ) ) {
				$this->set_restore_pair_state( $i, 'out' );
				$this->rename_or_fail( $pair['live'], $pair['old'] );
			}
			if ( null !== $pair['new'] && $this->path_exists( $pair['new'] ) ) {
				$this->set_restore_pair_state( $i, 'in' );
				$this->fs->mkdir( dirname( $pair['live'] ) );
				$this->rename_or_fail( $pair['new'], $pair['live'] );
			}
		}
	}

	private function set_restore_pair_state( $index, $state ) {
		$this->restore_txn['pairs'][ $index ]['state'] = $state;
		$this->write_restore_journal();
	}

	/**
	 * @param string      $live Live path.
	 * @param string|null $new  Staged replacement; none = the live entry is removed.
	 */
	private function add_restore_pair( $live, $new = null ) {
		$this->restore_txn['pairs'][] = [
			'live'  => $live,
			'new'   => $new,
			'old'   => $this->restore_txn['stage'] . '/old/' . count( $this->restore_txn['pairs'] ),
			'state' => 'pending',
		];
	}

	private function commit_restore_transaction() {
		$stage = $this->restore_txn['stage'];

		// Marked first: a kill during the cleanup below must not roll back a finished restore.
		$this->restore_txn['committed'] = true;
		$this->write_restore_journal();
		$this->restore_txn = null;
		try {
			// The previous files go first: a kill in the middle must not leave them without the (committed) journal.
			$this->fs->remove( [ $stage . '/old', $stage . '/new' ] );
			$this->remove_scratch_dir();
			$this->fs->remove( [ $this->get_restore_journal_file(), $stage ] );
		} catch ( \Throwable $e ) {
			EE::warning( sprintf( 'Could not remove the previous site files in %s: %s', $stage, $e->getMessage() ) );
		}
	}

	/**
	 * Undo a restore in reverse order: config, files, then DB. Best effort; keeps the stage when anything fails.
	 *
	 * @return bool
	 */
	private function rollback_restore_transaction() {
		$txn        = $this->restore_txn;
		$ok         = true;
		$config_dir = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/config';

		if ( ! empty( $txn['conf'] ) ) {
			foreach ( [ 'nginx', 'php' ] as $dir ) {
				$saved = $txn['stage'] . '/config-before/' . $dir;
				if ( is_dir( $saved ) && ! EE::exec( sprintf( 'rsync -a --delete %s/ %s/', escapeshellarg( $saved ), escapeshellarg( $config_dir . '/' . $dir ) ) ) ) {
					EE::warning( sprintf( 'Could not put back the %s configuration.', $dir ) );
					$ok = false;
				}
			}
		}

		for ( $i = count( $txn['pairs'] ) - 1; $i >= 0; $i-- ) {
			$pair = $txn['pairs'][ $i ];
			if ( 'in' === $pair['state'] && $this->path_exists( $pair['live'] ) && ! $this->path_exists( $pair['new'] ) ) {
				$this->fs->mkdir( dirname( $pair['new'] ) );
				$ok = $this->rename_quietly( $pair['live'], $pair['new'] ) && $ok;
			}
			if ( $this->path_exists( $pair['old'] ) ) {
				// Something (a running request) recreated the live path between the two renames: move it aside.
				if ( $this->path_exists( $pair['live'] ) ) {
					$this->rename_quietly( $pair['live'], $txn['stage'] . '/displaced-' . $i );
				}
				$ok = ! $this->path_exists( $pair['live'] ) && $this->rename_quietly( $pair['old'], $pair['live'] ) && $ok;
			}
		}

		if ( 'importing' === $txn['db'] ) {
			$scratch = $this->prepare_scratch_dir();
			$result  = null;
			$saved  = $txn['stage'] . '/db-before.sql';
			$view   = $scratch . '/db-before.sql';
			if ( ! file_exists( $saved ) && file_exists( $view ) ) {
				$this->rename_quietly( $view, $saved );
			}
			if ( $this->path_exists( $view ) ) {
				unlink( $view );
			}
			// Read-only for the container; a kill during the import still leaves the copy in the stage. The mode is set
			// on the stage's own file (same inode), never through the path the site user can replace.
			if ( file_exists( $saved ) && chmod( $saved, 0644 ) && ( @link( $saved, $view ) || copy( $saved, $view ) ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- falls back to a copy.
				// Checked again here: no EE::error() may run in a shutdown handler.
				if ( ! preg_match( self::SHELL_SAFE_DB_NAME, (string) $this->site_data['db_name'] ) ) {
					throw new \RuntimeException( 'The database name is not supported for restore.' );
				}
				$command = sprintf( 'mysql --defaults-extra-file=%s --skip-ssl %s < /var/www/%s/db-before.sql 2>&1', $this->write_mysql_defaults_file(), $this->site_data['db_name'], self::RESTORE_STAGE_DIR );
				$result  = EE::launch( $this->get_ee_shell_command( $command ) );
				$this->remove_mysql_defaults_files();
				if ( $this->path_exists( $view ) ) {
					unlink( $view );
				}
			}
			if ( null === $result || $result->return_code ) {
				EE::warning( 'Could not put back the previous database. ' . ( $result ? trim( $result->stdout . ' ' . $result->stderr ) : '' ) );
				$ok = false;
			}
		}

		if ( $ok ) {
			$this->restore_txn = null;
			$this->remove_scratch_dir();
			$this->fs->remove( [ $this->get_restore_journal_file(), $txn['stage'] ] );
		}

		return $ok;
	}

	/**
	 * Shutdown handler: a restore that exits before its commit is rolled back while the site lock is still held.
	 */
	public function rollback_restore_on_shutdown() {
		$this->in_shutdown = true;
		try {
			$this->rollback_unfinished_restore();
		} catch ( \Throwable $e ) {
			// An exception here would also skip the shutdown handlers registered after this one.
			$where = null === $this->restore_txn ? '' : sprintf( ' The previous site files are in %1$s/old and the previous database in %1$s/db-before.sql; the next `ee site restore` retries the rollback.', $this->restore_txn['stage'] );
			EE::warning( 'The rollback stopped: ' . $e->getMessage() . $where );
		}
		$this->in_shutdown = false;
	}

	private function rollback_unfinished_restore() {
		// A failed restore must not leave its download behind to be taken for another backup next time.
		if ( $this->restore_in_progress ) {
			$this->clear_download_dir();
		}

		if ( null === $this->restore_txn ) {
			return;
		}

		if ( ! $this->restore_has_changes( $this->restore_txn ) ) {
			$this->remove_scratch_dir();
			$this->fs->remove( [ $this->get_restore_journal_file(), $this->restore_txn['stage'] ] );
			$this->restore_txn = null;
			EE::log( 'The site was not changed.' );

			return;
		}

		$stage = $this->restore_txn['stage'];
		EE::warning( 'The restore did not complete. Rolling the site back.' );
		if ( $this->rollback_restore_transaction() ) {
			EE::log( 'Rolled back: the site files and configuration are as they were before the restore, and the database was re-imported from the copy taken before it.' );
		} else {
			EE::warning( sprintf( 'The rollback did not complete. The previous site files are in %s/old and the previous database in %s/db-before.sql; the next `ee site restore` retries the rollback.', $stage, $stage ) );
		}
	}

	/**
	 * Roll back a restore that was killed before it finished (its journal is left in EE_BACKUP_DIR).
	 */
	private function recover_interrupted_restore() {
		$journal_file = $this->get_restore_journal_file();
		$journal      = $this->read_unfinished_restore_journal();
		$stage        = $this->get_restore_stage_dir();

		if ( null === $journal && ! empty( $this->list_dir( $stage . '/old' ) ) && ! is_file( $stage . '/journal.json' ) ) {
			EE::error( sprintf( 'An earlier restore left the previous site files in %s/old without a journal. Check them, put back what is missing, remove %s and run the restore again.', $stage, $stage ) );
		}

		if ( null !== $journal ) {
			EE::warning( 'An earlier restore of this site did not finish. Rolling it back first.' );
			$this->restore_txn = $journal;
			if ( ! $this->rollback_restore_transaction() ) {
				$this->restore_txn = null;
				EE::error( sprintf( 'Could not roll back the earlier restore; its files are in %s. Fix the problem above and run the restore again.', $journal['stage'] ) );
			}
			EE::log( 'The earlier restore was rolled back.' );
		}

		$this->remove_scratch_dir();
		$this->fs->remove( [ $journal_file, $stage ] );
	}

	/**
	 * A backup of a site left half-restored would replace good backups through retention.
	 */
	private function ensure_no_interrupted_restore() {
		if ( null !== $this->read_unfinished_restore_journal() ) {
			$this->capture_error( 'An interrupted restore left the site in an inconsistent state', self::ERROR_TYPE_VALIDATION, 1004 );
			$this->release_site_lock();
			EE::error( sprintf( 'An earlier restore of %1$s did not finish, so the site may be half restored. Run `ee site restore %1$s` (optionally with --id=<backup-id>): it rolls the earlier restore back first and then restores that backup (by default this host\'s newest). Then back up again.', $this->site_data['site_url'] ) );
		}
	}

	private function restore_has_changes( $txn ) {
		if ( ! empty( $txn['conf'] ) || 'importing' === $txn['db'] ) {
			return true;
		}
		foreach ( $txn['pairs'] as $pair ) {
			if ( 'pending' !== $pair['state'] ) {
				return true;
			}
		}

		return false;
	}

	private function ensure_wp_cli_yml() {
		$container_fs_path = rtrim( str_replace( '/var/www/', '', $this->site_data['site_container_fs_path'] ), '/' );
		$wp_cli_yml_path   = ltrim( str_replace( 'htdocs', '', $container_fs_path ), '/' );

		$file = $this->site_data['site_fs_path'] . '/app/htdocs/wp-cli.yml';
		// A file that sets the path (e.g. restored from the backup, maybe with more settings) is kept.
		if ( ! empty( $wp_cli_yml_path ) && ! ( is_file( $file ) && preg_match( '/^path:/m', (string) file_get_contents( $file ) ) ) ) {
			$this->fs->dumpFile( $file, "path: $wp_cli_yml_path/" );
		}
	}

	private function exec_or_fail( $command, $error_message ) {
		if ( ! EE::exec( $command ) ) {
			EE::error( $error_message );
		}
	}

	private function rename_or_fail( $from, $to ) {
		if ( ! $this->rename_quietly( $from, $to ) ) {
			EE::error( sprintf( 'Failed to move %s to %s.', $from, $to ) );
		}
	}

	private function rename_quietly( $from, $to ) {
		$renamed = @rename( $from, $to ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- callers report the failure.
		clearstatcache();

		return $renamed;
	}

	private function path_exists( $path ) {
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * @param string $dir Directory.
	 *
	 * @return string[] Entry names, dotfiles included.
	 */
	private function list_dir( $dir ) {
		$entries = is_dir( $dir ) ? scandir( $dir ) : false;

		return false === $entries ? [] : array_values( array_diff( $entries, [ '.', '..' ] ) );
	}

	/**
	 * Verify rclone is installed and the configured backend exists.
	 *
	 * Extracted from pre_backup_restore_checks() so read-only paths (e.g.
	 * `ee site backup --list`) can run it before invoking rclone and surface a
	 * friendly message instead of a raw "sh: 1: rclone: not found".
	 */
	private function check_rclone_available() {
		$command     = 'rclone --version';
		$return_code = EE::exec( $command );

		if ( ! $return_code ) {
			$this->capture_error(
				'rclone is not installed',
				self::ERROR_TYPE_CONFIG,
				2001
			);
			EE::error( 'rclone is not installed. Please install rclone for backup/restore: https://rclone.org/downloads/#script-download-and-install' );
		}

		$command = 'rclone listremotes';
		$output  = EE::launch( $command );

		$rclone_path    = get_config_value( 'rclone-path', 'easyengine:easyengine' );
		$rclone_backend = explode( ':', $rclone_path )[0];
		$rclone_path    = $rclone_backend . ':';

		// Exact match: `myeasyengine:` must not satisfy `easyengine:` (BKP-12). On-the-fly remotes (":s3,…:bucket") have no name to check.
		if ( '' !== $rclone_backend && ! in_array( $rclone_path, array_map( 'trim', explode( "\n", $output->stdout ) ), true ) ) {
			$this->capture_error(
				sprintf( 'rclone backend "%s" is not configured. Please create it using `rclone config`', $rclone_backend ),
				self::ERROR_TYPE_CONFIG,
				2002
			);
			EE::error( sprintf( 'rclone backend "%s" does not exist. Please create it using `rclone config`', $rclone_backend ) );
		}
	}

	private function pre_backup_restore_checks() {
		$this->check_rclone_available();

		$this->check_and_install( 'zip', 'zip' );
		$this->check_and_install( '7z', 'p7zip-full' );
		$this->check_and_install( 'unzip', 'unzip' );
		$this->check_and_install( 'rsync', 'rsync' );


		if ( ! $this->fs->exists( EE_BACKUP_DIR ) ) {
			$this->fs->mkdir( EE_BACKUP_DIR );
		}


		$lock_file = EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.lock';

		if ( $this->fs->exists( $lock_file ) ) {
			$this->capture_error(
				'Another backup/restore process is already running for this site',
				self::ERROR_TYPE_LOCK,
				2003
			);
			EE::error( sprintf( 'Another backup/restore process is running. Please wait for it to complete. If none is running (e.g. one was killed), remove %s.', $lock_file ) );
		} else {
			$this->fs->dumpFile( $lock_file, 'lock' );
			$this->site_lock_file = $lock_file;
			// A failed or refused run must not block the next one (and the next restore rolls back a half-finished one).
			register_shutdown_function( [ $this, 'release_site_lock' ] );
		}
	}

	/**
	 * Shutdown handler: remove the per-site lock file this process created.
	 */
	public function release_site_lock() {
		if ( null !== $this->site_lock_file && file_exists( $this->site_lock_file ) ) {
			unlink( $this->site_lock_file );
		}
		$this->site_lock_file = null;
	}

	private function pre_backup_check() {
		$this->pre_backup_restore_checks();

		// The archive holds all of app/, not only htdocs/.
		$site_path = $this->get_site_app_dir();
		$site_size = $this->dir_size( $site_path );

		EE::debug( 'Site size: ' . $site_size );

		if ( in_array( $this->site_data['site_type'], [ 'php', 'wp' ] ) && ! empty( $this->site_data['db_name'] ) ) {
			$site_size += $this->get_db_size();
			EE::debug( 'Site size with db: ' . $site_size );
		}

		$free_space = disk_free_space( EE_BACKUP_DIR );
		EE::debug( 'Free space: ' . $free_space );

		if ( $site_size > $free_space ) {
			$error_message = $this->build_disk_space_error_message( 'backup', $site_size, $free_space );

			$this->capture_error(
				sprintf(
					'Insufficient disk space for backup. Required: %s, Available: %s',
					$this->format_bytes( $site_size ),
					$this->format_bytes( $free_space )
				),
				self::ERROR_TYPE_DISK_SPACE,
				3001
			);

			$this->release_site_lock();
			EE::error( $error_message );
		}
	}

	/**
	 * Build a disk space error message for backup/restore operations.
	 *
	 * @param string $operation   The operation name ('backup' or 'restore').
	 * @param int $required_space The required disk space in bytes.
	 * @param int $free_space     The available free space in bytes.
	 *
	 * @return string The formatted error message.
	 */
	private function build_disk_space_error_message( $operation, $required_space, $free_space ) {
		$additional_space    = $required_space - $free_space;
		$required_formatted  = $this->format_bytes( $required_space );
		$available_formatted = $this->format_bytes( $free_space );
		$needed_formatted    = $this->format_bytes( $additional_space );

		return sprintf(
			"Not enough disk space to take %s.\n" .
			"Required: %s (%s bytes)\n" .
			"Available: %s (%s bytes)\n" .
			"Additional space needed: %s (%s bytes)\n" .
			"Please free up some space and try again.",
			$operation,
			$required_formatted,
			number_format( $required_space ),
			$available_formatted,
			number_format( $free_space ),
			$needed_formatted,
			number_format( $additional_space )
		);
	}

	private function check_and_install( $command, $name ) {
		$status = EE::exec( "command -v $command" );
		if ( ! $status ) {
			if ( IS_DARWIN ) {
				$this->capture_error(
					sprintf( '%s is not installed (required for backup/restore)', $name ),
					self::ERROR_TYPE_CONFIG,
					2010
				);
				EE::error( "$name is not installed. Please install $name for backup/restore. You can install it using `brew install $name`." );
			} else {
				$status = EE::exec( 'apt-get --version' );
				if ( $status ) {
					// Backups run unattended (cron, EasyDash): no debconf, conffile or needrestart prompt may wait for an
					// answer, and needrestart must not restart services such as Docker in the middle of one (BKP-3).
					$apt = 'DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';
					EE::exec( "$apt update < /dev/null" );
					EE::exec( "$apt install -y $name < /dev/null" );
					if ( ! EE::exec( "command -v $command" ) ) {
						$this->capture_error(
							sprintf( '%s is not installed and could not be installed with apt-get', $name ),
							self::ERROR_TYPE_CONFIG,
							2013
						);
						EE::error( "Failed to install $name with apt-get. Please install $name for backup/restore." );
					}
				} else {
					$this->capture_error(
						sprintf( '%s is not installed and could not be auto-installed (apt-get not available)', $name ),
						self::ERROR_TYPE_CONFIG,
						2011
					);
					EE::error( "$name is not installed. Please install $name for backup/restore." );
				}
			}
		}
	}

	private function pre_restore_check() {

		$this->pre_backup_restore_checks();
		// Only with the lock held: a restore refused by another run's lock must leave that run's download dir alone.
		$this->restore_in_progress = true;

		// A restore killed mid-way (SIGKILL, OOM) is rolled back before anything else.
		$this->recover_interrupted_restore();

		// Never reuse what a failed backup or restore left in the download dir: it may be another backup (BKP-1).
		$this->clear_download_dir();

		$remote_path = $this->get_remote_path( false );
		$command     = sprintf( 'rclone size --json %s', escapeshellarg( $remote_path ) );
		$output      = EE::launch( $command );

		if ( $output->return_code ) {
			EE::error( 'Failed to get remote backup size.' );
		}

		$remote_size = json_decode( $output->stdout, true )['bytes'];
		EE::debug( 'Remote backup size: ' . $remote_size );

		$free_space = disk_free_space( EE_BACKUP_DIR );
		if ( false === $free_space ) {
			EE::error( 'Unable to determine free disk space for backup directory.' );
		}

		if ( $remote_size > $free_space ) {
			$required_space      = $remote_size;
			$additional_space    = $required_space - $free_space;
			$required_formatted  = $this->format_bytes( $required_space );
			$available_formatted = $this->format_bytes( $free_space );
			$needed_formatted    = $this->format_bytes( $additional_space );

			$error_message = sprintf(
				"Not enough disk space to restore backup.\n" .
				"Required: %s (%s bytes)\n" .
				"Available: %s (%s bytes)\n" .
				"Additional space needed: %s (%s bytes)\n" .
				"Please free up some space and try again.",
				$required_formatted,
				number_format( $required_space ),
				$available_formatted,
				number_format( $free_space ),
				$needed_formatted,
				number_format( $additional_space )
			);

			EE::error( $error_message );
		}


		$backup_dir = EE_BACKUP_DIR . '/' . $this->site_data['site_url'];

		if ( ! $this->fs->exists( $backup_dir ) ) {
			$this->fs->mkdir( $backup_dir );
		}

		$backup_site_info = $backup_dir . '/metadata.json';

		if ( ! $this->fs->exists( $backup_site_info ) ) {
			$this->rclone_download( $backup_dir );
		}


		$backup_site_data = json_decode( file_get_contents( $backup_site_info ), true );

		// A missing/corrupt metadata.json (or one lacking the keys compared below)
		// would otherwise silently bypass the site-type / public-dir match checks.
		if ( ! is_array( $backup_site_data ) || ! isset( $backup_site_data['site_type'], $backup_site_data['site_container_fs_path'] ) ) {
			EE::error( 'Backup metadata is missing or corrupt. Cannot safely restore this backup.' );
		}

		$this->restore_metadata = $backup_site_data;

		if ( $this->site_data['site_type'] !== $backup_site_data['site_type'] ) {
			EE::error( 'Site type does not match with the backed up site.' );
		}


		if ( ( ! empty( $this->site_data['db_name'] ) && empty( $backup_site_data['db_name'] ) ) || ( empty( $this->site_data['db_name'] ) && ! empty( $backup_site_data['db_name'] ) ) ) {
			EE::error( 'Database mismatch between backup and current site.' );
		}


		if ( $this->site_data['site_container_fs_path'] !== $backup_site_data['site_container_fs_path'] ) {
			EE::error( 'Site public-dir does not match with the backed up site.' );
		}


		$container_fs_path = $this->site_data['site_container_fs_path'];
		$container_fs_path = str_replace( '/var/www/', '', $container_fs_path );
		$site_dir          = $this->site_data['site_fs_path'] . '/app/' . $container_fs_path;

		$this->fs->mkdir( $site_dir );
		$this->fs->chmod( $site_dir, 0755 );

		if ( 'wp' === $this->site_data['site_type'] ) {
			$this->ensure_wp_cli_yml();
		}

		$chown_command = sprintf( 'chown -R www-data:www-data %s/app/', $this->site_data['site_fs_path'] );
		EE::exec( $chown_command );
	}


	private function format_bytes( $bytes, $precision = 2 ) {
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$size = $bytes / pow( 1024, $pow );

		return round( $size, $precision ) . ' ' . $units[ $pow ];
	}

	private function dir_size( string $directory ) {
		$size = 0;

		EE::debug( "Calculating size of $directory" );

		if ( ! $this->fs->exists( $directory ) ) {
			EE::error( "Directory does not exist: $directory" );
		}

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( ! $file->isReadable() ) {
				continue;
			}
			$size += $file->getSize();
		}

		EE::debug( "Size of $directory: $size" );

		return $size;
	}


	private function get_db_size() {
		$query = "
			SELECT
				table_schema AS 'Database',
				SUM(data_length + index_length) AS 'Size (Bytes)'
			FROM
				information_schema.TABLES
			WHERE
				table_schema = '" . str_replace( [ '\\', "'" ], [ '\\\\', "''" ], $this->site_data['db_name'] ) . "'
			GROUP BY
				table_schema;
		";


		$query_file = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app/htdocs/db_size_query.sql';
		$this->fs->dumpFile( $query_file, $query );


		// No credential in the command: it passes two shells on the host (BKP-15).
		$defaults_file = $this->write_mysql_defaults_file();
		$command       = sprintf( 'mysql --defaults-extra-file=%s --skip-ssl < /var/www/htdocs/db_size_query.sql', $defaults_file );

		$output = EE::launch( 'ee shell ' . $this->site_data['site_url'] . ' --skip-tty --command=' . escapeshellarg( $command ) );


		$this->fs->remove( $query_file );
		$this->remove_mysql_defaults_files();


		$size        = 0;
		$size_output = explode( "\n", $output->stdout );

		if ( count( $size_output ) > 1 ) {
			$size_array = explode( "\t", $size_output[1] );
			$size       = isset( $size_array[1] ) ? $size_array[1] : 0;
		}

		EE::debug( "DB size: $size" );

		return (int) $size;
	}

	private function list_remote_backups() {

		// Listing is read-only: it doesn't create this host's id.
		$backups = $this->get_remote_backups( false );

		if ( null === $backups ) {
			EE::error( 'Error listing remote backups: ' . $this->rclone_error ); // Display specific error
		}

		// This host's backups first (restore picks the newest of them), then pre-host-id ones, then other hosts' as <host-id>/<id>.
		$all = array_merge( $backups['own'], $backups['legacy'], $backups['foreign'] );

		if ( empty( $all ) ) {
			EE::log( 'No remote backups found.' );

			return;
		}

		foreach ( $all as $backup ) {
			EE::log( $backup );
		}
	}

	/**
	 * Backups of this site on the remote, each list newest first; null when rclone failed.
	 *
	 * @return array{own:string[],legacy:string[],foreign:string[]}|null own: this host's ids, legacy: ids directly under
	 *                                                                   <rclone-path>/<site>/ (before BKP-8), foreign: <host-id>/<id> of other hosts.
	 */
	private function get_remote_backups( $create_host_id = true ) {
		$site_root = $this->get_rclone_config_path();
		$entries   = $this->list_remote_dirs( $site_root );

		if ( null === $entries ) {
			return null;
		}

		// null: this host has no id yet, so every host dir is another host's.
		$own     = $this->get_backup_host_id( $create_host_id );
		$backups = [
			'own'     => [],
			'legacy'  => [],
			'foreign' => [],
		];

		foreach ( $entries as $entry ) {
			if ( $this->is_backup_id( $entry ) ) {
				$backups['legacy'][] = $entry;
				continue;
			}
			if ( ! $this->is_valid_backup_host_id( $entry ) ) {
				continue;
			}

			$ids = $this->list_remote_dirs( $site_root . '/' . $entry );
			if ( null === $ids ) {
				if ( $entry === $own ) {
					return null;
				}
				// Another host's dir we can't read must not block this host's own backups.
				EE::warning( sprintf( 'Could not list the backups of host %s: %s', $entry, $this->rclone_error ) );
				continue;
			}
			$ids = array_values( array_filter( $ids, [ $this, 'is_backup_id' ] ) );

			if ( $entry === $own ) {
				$backups['own'] = $ids;
			} else {
				foreach ( $ids as $id ) {
					$backups['foreign'][] = $entry . '/' . $id;
				}
			}
		}

		rsort( $backups['own'] );
		rsort( $backups['legacy'] );
		usort(
			$backups['foreign'],
			function ( $a, $b ) {
				return strcmp( basename( $b ), basename( $a ) );
			}
		);

		return $backups;
	}

	/**
	 * Directory names directly under $remote_dir; null when rclone failed.
	 *
	 * @param string $remote_dir rclone path.
	 *
	 * @return string[]|null
	 */
	private function list_remote_dirs( $remote_dir ) {
		$output = EE::launch( sprintf( 'rclone lsf --dirs-only %s', escapeshellarg( $remote_dir ) ) );

		if ( $output->return_code ) {
			// Local and SFTP remotes fail for a missing dir where bucket remotes list nothing (SC-11).
			if ( false !== stripos( $output->stderr, 'directory not found' ) ) {
				return [];
			}
			$this->rclone_error = trim( $output->stderr );

			return null;
		}

		return array_values(
			array_filter(
				array_map(
					function ( $line ) {
						return rtrim( trim( $line ), '/' );
					},
					explode( "\n", $output->stdout )
				),
				'strlen'
			)
		);
	}

	private function is_backup_id( $name ) {
		return 1 === preg_match( self::BACKUP_ID_PATTERN, $name );
	}

	/**
	 * Host directory names never look like backup ids, not even to the unanchored check of older EE versions,
	 * so those neither list nor prune them.
	 *
	 * @param string $id Candidate.
	 *
	 * @return bool
	 */
	private function is_valid_backup_host_id( $id ) {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $id ) && ! preg_match( '/\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}/', $id );
	}

	/**
	 * Per-host directory under <rclone-path>/<site>/, so hosts sharing a remote neither restore nor prune each other's
	 * backups (BKP-8). `backup-host-id` in config.yml overrides the id generated once and kept in the EE database.
	 *
	 * @return string
	 */
	private function get_backup_host_id( $create = true ) {
		if ( null !== $this->backup_host_id ) {
			return $this->backup_host_id;
		}

		$configured = trim( (string) get_config_value( 'backup-host-id', '' ) );
		if ( '' !== $configured ) {
			if ( ! $this->is_valid_backup_host_id( $configured ) ) {
				$this->capture_error( 'Invalid backup-host-id in config.yml', self::ERROR_TYPE_CONFIG, 2012 );
				EE::error( sprintf( 'Invalid backup-host-id "%s" in config.yml: use up to 63 letters, digits, ".", "_" or "-", starting with a letter or digit.', $configured ) );
			}

			return $this->backup_host_id = $configured;
		}

		$stored = \EE\Model\Option::get( 'backup-host-id' );
		if ( is_string( $stored ) && $this->is_valid_backup_host_id( $stored ) ) {
			return $this->backup_host_id = $stored;
		}
		if ( ! $create ) {
			return null;
		}

		// Concurrent first backups (cron) must agree on one id.
		$this->fs->mkdir( EE_BACKUP_DIR );
		$lock = fopen( EE_BACKUP_DIR . '/backup-host-id.lock', 'c' );
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}

		$stored = \EE\Model\Option::get( 'backup-host-id' );
		if ( is_string( $stored ) && $this->is_valid_backup_host_id( $stored ) ) {
			$host_id = $stored;
		} else {
			// Readable in the bucket; the random part keeps two hosts with the same hostname apart.
			$hostname = trim( substr( trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) gethostname() ) ), '-' ), 0, 40 ), '-' );
			$host_id  = ( '' === $hostname ? 'host' : $hostname ) . '-' . bin2hex( random_bytes( 4 ) );
			if ( ! $this->is_valid_backup_host_id( $host_id ) ) {
				$host_id = 'host-' . bin2hex( random_bytes( 4 ) );
			}
			\EE\Model\Option::set( 'backup-host-id', $host_id );
		}

		if ( $lock ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}

		return $this->backup_host_id = $host_id;
	}

	/**
	 * Remote path of the backup to restore: $backup_id (own, pre-host-id, or <host-id>/<id>), else this host's newest.
	 *
	 * @param string|null $backup_id Value of --id.
	 *
	 * @return string
	 */
	private function resolve_restore_path( $backup_id = null ) {
		$backups = $this->get_remote_backups();

		if ( null === $backups ) {
			EE::error( 'Error listing remote backups: ' . $this->rclone_error );
		}

		$site_root = $this->get_rclone_config_path();
		$host_root = $this->get_host_backup_root();

		if ( $backup_id ) {
			$backup_id = trim( $backup_id, '/' );
			$own_id    = preg_replace( '#^' . preg_quote( $this->get_backup_host_id() . '/', '#' ) . '#', '', $backup_id );

			if ( in_array( $own_id, $backups['own'], true ) ) {
				return $host_root . '/' . $own_id;
			}
			if ( in_array( $backup_id, $backups['legacy'], true ) || in_array( $backup_id, $backups['foreign'], true ) ) {
				return $site_root . '/' . $backup_id;
			}

			// A bare id seen on another host (e.g. restoring onto a replacement server).
			$matches = array_values(
				array_filter(
					$backups['foreign'],
					function ( $foreign ) use ( $backup_id ) {
						return basename( $foreign ) === $backup_id;
					}
				)
			);
			if ( 1 === count( $matches ) ) {
				EE::warning( sprintf( 'Backup %s was made on another host; restoring %s.', $backup_id, $matches[0] ) );

				return $site_root . '/' . $matches[0];
			}
			if ( count( $matches ) > 1 ) {
				EE::error( sprintf( "Backup %s exists on several hosts:\n%s\nChoose one with --id=<host-id>/<backup-id>.", $backup_id, implode( "\n", $matches ) ) );
			}

			EE::error( "Invalid backup ID provided.\nPlease provide a valid ID from the list using 'ee site backup --list " . $this->site_data['site_url'] . "'." );
		}

		if ( ! empty( $backups['own'] ) ) {
			// E.g. made by an older EE version after a downgrade; it may also come from another host, so it's not taken.
			if ( ! empty( $backups['legacy'] ) && (int) $backups['legacy'][0] > (int) $backups['own'][0] ) {
				EE::warning( sprintf( 'A newer backup in the old shared layout exists (%1$s), possibly from another host. Restore it with --id=%1$s.', $backups['legacy'][0] ) );
			}
			EE::log( 'Restoring from backup: ' . $backups['own'][0] );

			return $host_root . '/' . $backups['own'][0];
		}

		if ( ! empty( $backups['legacy'] ) ) {
			EE::warning( 'This host has no backup of the site in the per-host layout yet; using the newest backup of the old shared layout, which may come from another host that uses the same rclone-path.' );
			EE::log( 'Restoring from backup: ' . $backups['legacy'][0] );

			return $site_root . '/' . $backups['legacy'][0];
		}

		if ( ! empty( $backups['foreign'] ) ) {
			EE::error(
				sprintf(
					"No backup of %s was made on this host (backup host id: %s). Backups made on other hosts:\n%s\nRestore one of them with --id=<host-id>/<backup-id>.",
					$this->site_data['site_url'],
					$this->get_backup_host_id(),
					implode( "\n", $backups['foreign'] )
				)
			);
		}

		EE::error( sprintf( 'No backups found for %s.', $this->site_data['site_url'] ) );
	}

	/**
	 * Remote dir of every backup of the site: this layout's <host-id>/ dirs plus pre-host-id backup ids.
	 *
	 * @return string
	 */
	private function get_rclone_config_path() {

		$rclone_config_path = get_config_value( 'rclone-path', 'easyengine:easyengine' );
		$rclone_config_path = \EE\Utils\trailingslashit( $rclone_config_path ) . $this->site_data['site_url'];

		return $rclone_config_path;
	}

	private function get_host_backup_root() {
		return $this->get_rclone_config_path() . '/' . $this->get_backup_host_id();
	}

	private function get_remote_path( $upload = true ) {
		if ( ! empty( $this->rclone_config_path ) ) {
			return $this->rclone_config_path;
		}

		if ( $upload ) {
			$this->rclone_config_path = $this->get_host_backup_root() . '/' . $this->get_new_backup_id();
		} else {
			// For restore: this host's most recent backup.
			$this->rclone_config_path = $this->resolve_restore_path();
		}

		return $this->rclone_config_path;
	}

	/**
	 * An id this host hasn't used yet. Ids have one-second resolution: a backup that started in the same second as the
	 * previous one uploaded into its directory, and listing and retention counted the two as one (BKP-5).
	 *
	 * @return string
	 */
	private function get_new_backup_id() {
		// The site lock keeps other backups of the site on this host from running meanwhile, so the listing stays valid.
		$existing = $this->list_remote_dirs( $this->get_host_backup_root() );

		for ( $attempt = 1; ; $attempt++ ) {
			$now = time();
			$id  = $now . '_' . date( 'Y-m-d-H-i-s', $now );
			if ( empty( $existing ) || ! in_array( $id, $existing, true ) || $attempt > 3 ) {
				return $id;
			}
			sleep( 1 );
		}
	}

	/**
	 * `no-of-backups` from config.yml; 0, negative or non-numeric values used to delete every backup (BKP-7).
	 *
	 * @return int At least 1.
	 */
	private function get_no_of_backups() {
		$value = get_config_value( 'no-of-backups', self::DEFAULT_NO_OF_BACKUPS );

		if ( is_int( $value ) || ( is_float( $value ) && floor( $value ) === $value ) || ( is_string( $value ) && ctype_digit( trim( $value ) ) ) ) {
			if ( (int) $value >= 1 ) {
				return (int) $value;
			}
		}

		EE::warning( sprintf( 'Invalid no-of-backups value %s in config.yml: it must be a whole number of at least 1. Keeping the default of %d backups.', is_scalar( $value ) ? '"' . $value . '"' : gettype( $value ), self::DEFAULT_NO_OF_BACKUPS ) );

		return self::DEFAULT_NO_OF_BACKUPS;
	}

	/**
	 * Read currently-available memory in MB.
	 *
	 * Pins `LC_ALL=C` so the `Mem:` label and column layout stay stable across
	 * locales, locates the "available" column by its header name (rather than a
	 * fixed field index, which differs across `free`/procps versions) and falls
	 * back to the "free" column on older builds that have no "available" column.
	 *
	 * @return int Available memory in MB (0 if it cannot be determined, in which
	 *             case the resource helpers fall back to their safe minimums).
	 */
	private function get_available_ram_mb() {
		$command = "LC_ALL=C free -m | awk 'NR==1{for(i=1;i<=NF;i++) if(\$i==\"available\") c=i+1} /^Mem:/{print (c ? \$c : \$4)}'";

		return intval( EE::launch( $command )->stdout );
	}

	/**
	 * Installed rclone version as a dotted string (e.g. "1.66.0"), or '' if it
	 * cannot be determined. Used to gate download flags that older rclone lacks.
	 *
	 * @return string
	 */
	private function get_rclone_version() {
		// `rclone version` prints e.g. "rclone v1.66.0" on its first line.
		$output  = EE::launch( "rclone version | head -n1 | awk '{print $2}'" )->stdout;
		$version = ltrim( trim( $output ), 'v' );

		return preg_match( '/^[0-9]+\.[0-9]+/', $version ) ? $version : '';
	}

	private function rclone_download( $path ) {
		$cpu_cores     = intval( EE::launch( 'nproc' )->stdout );
		$available_ram = $this->get_available_ram_mb();

		// Derive the memory-safe transfer count and the total RAM budget from the
		// shared helper (is_s3 = false: downloads allocate no S3 multipart-upload
		// buffers). The budget is reused below rather than recomputed.
		$res        = $this->compute_rclone_resources( $cpu_cores, $available_ram, false );
		$transfers  = $res['transfers'];
		$budget     = $res['budget'];     // MB; available_ram * rclone-mem-fraction.
		$max_buffer = $res['max_buffer']; // MB.

		// Per concurrent --transfers a download holds one --buffer-size read-ahead
		// buffer plus, for files above rclone's --multi-thread-cutoff,
		// --multi-thread-streams streams -- each with a
		// --multi-thread-write-buffer-size buffer and one in-flight
		// --multi-thread-chunk-size range. The whole footprint must fit ONE budget:
		//
		//     transfers * ( buffer_size + multi_thread_streams * per_stream_mem ) <= budget
		//
		// so each transfer's share of the budget is split between the read-ahead
		// buffer (up to half, capped at $max_buffer) and the multi-thread streams.
		// Both scale with available RAM; on a tight budget streams floor at 1. The
		// previous code budgeted the streams against the full budget independently
		// of the read-ahead buffers, so the two pools could together reach ~2x the
		// intended fraction and still OOM during restore/rollback.
		$mt_write_buffer = max( 1, intval( get_config_value( 'rclone-mt-write-buffer-size', 128 ) ) ); // KiB; rclone default.
		$mt_chunk_size   = max( 1, intval( get_config_value( 'rclone-mt-chunk-size', 64 ) ) );         // MB; rclone default.
		$per_stream_mem  = ( $mt_write_buffer / 1024 ) + $mt_chunk_size;                               // MB.

		// Reduce transfers until each transfer's share of the budget can hold the
		// minimum read-ahead buffer plus at least one stream, so the combined
		// footprint stays within budget whenever the budget allows it at all.
		$min_per_transfer = 16 + (int) ceil( $per_stream_mem );
		while ( $transfers > 1 && intval( floor( $budget / $transfers ) ) < $min_per_transfer ) {
			$transfers--;
		}
		$per_transfer = max( $min_per_transfer, intval( floor( $budget / $transfers ) ) );

		// Give the read-ahead buffer up to half the share (capped at $max_buffer)
		// but always leave room for at least one stream; spend the rest on streams.
		$buffer_mb     = min( intval( floor( $per_transfer / 2 ) ), $max_buffer, $per_transfer - (int) ceil( $per_stream_mem ) );
		$buffer_mb     = max( 16, $buffer_mb );
		$stream_budget = $per_transfer - $buffer_mb;
		$mt_streams    = max( 1, min( $cpu_cores * 2, 32, intval( floor( $stream_budget / $per_stream_mem ) ) ) );
		$buffer_size   = $buffer_mb . 'M';

		// --multi-thread-write-buffer-size and --multi-thread-chunk-size were added
		// after the original multi-thread support; older rclone aborts on unknown
		// flags, so only pass them when the installed rclone is new enough. The
		// budget above already assumes their defaults, so gating them out changes
		// only which knobs reach rclone, not the computed footprint.
		$rclone_version = $this->get_rclone_version();
		$mt_flags       = sprintf( '--multi-thread-streams %d', $mt_streams );
		if ( $rclone_version && version_compare( $rclone_version, self::RCLONE_MIN_VERSION_MT_WRITE_BUFFER, '>=' ) ) {
			$mt_flags .= sprintf( ' --multi-thread-write-buffer-size %dKi', $mt_write_buffer );
		}
		if ( $rclone_version && version_compare( $rclone_version, self::RCLONE_MIN_VERSION_MT_CHUNK_SIZE, '>=' ) ) {
			$mt_flags .= sprintf( ' --multi-thread-chunk-size %dM', $mt_chunk_size );
		}

		EE::debug( sprintf(
			'rclone download tuning: rclone=%s available_ram=%dMB budget=%dMB transfers=%d buffer-size=%s multi-thread-streams=%d mt-write-buffer=%dKi mt-chunk-size=%dM (est. peak ~%dMB)',
			$rclone_version ?: 'unknown',
			$available_ram,
			$budget,
			$transfers,
			$buffer_size,
			$mt_streams,
			$mt_write_buffer,
			$mt_chunk_size,
			(int) ( $transfers * ( $buffer_mb + $mt_streams * $per_stream_mem ) )
		) );

		$command = sprintf( "rclone copy -P --transfers %d --buffer-size %s %s %s %s", $transfers, $buffer_size, $mt_flags, escapeshellarg( $this->get_remote_path( false ) ), escapeshellarg( $path ) );
		$output  = EE::launch( $command );

		if ( $output->return_code ) {
			EE::error( 'Error downloading backup from remote storage.' );
		} else {
			EE::success( "Backup downloaded from remote storage." );
		}
	}


	/**
	 * Whether the configured rclone remote is an S3 backend.
	 *
	 * Resolves the remote name from `rclone-path` (instead of assuming
	 * `easyengine`) and compares the backend's exact `type` value to `s3`,
	 * rather than substring-matching the raw `rclone config show` output -- which
	 * could both miss a non-`easyengine` remote and false-positive on any line
	 * whose value merely contains the substring `s3`. All S3-compatible providers
	 * (AWS, Spaces, Wasabi, MinIO, ...) share `type = s3`, so an exact match on
	 * the type value covers them.
	 *
	 * @return bool
	 */
	private function is_s3_remote() {
		$rclone_path = get_config_value( 'rclone-path', 'easyengine:easyengine' );
		$remote      = explode( ':', $rclone_path )[0];

		$command = sprintf( "rclone config show %s | awk -F '=' '/^[[:space:]]*type[[:space:]]*=/ {gsub(/[[:space:]]/, \"\", \$2); print \$2; exit}'", escapeshellarg( $remote ) );
		$type    = trim( EE::launch( $command )->stdout );

		return ( 's3' === $type );
	}

	private function rclone_upload( $path ) {
		$cpu_cores     = intval( EE::launch( 'nproc' )->stdout );
		$available_ram = $this->get_available_ram_mb();

		// Detect S3 backends, which require additional multipart-upload tuning.
		$is_s3 = $this->is_s3_remote();

		$res         = $this->compute_rclone_resources( $cpu_cores, $available_ram, $is_s3 );
		$transfers   = $res['transfers'];
		$buffer_size = $res['buffer_size'] . 'M';

		$s3_flag = '';
		if ( $is_s3 ) {
			$s3_flag = sprintf( ' --s3-chunk-size=%dM --s3-upload-concurrency %d', $res['s3_chunk_size'], $res['s3_concurrency'] );
		}

		EE::debug( sprintf(
			'rclone upload tuning: available_ram=%dMB transfers=%d buffer-size=%s s3=%s s3-chunk-size=%dM s3-upload-concurrency=%d (est. peak ~%dMB)',
			$available_ram,
			$transfers,
			$buffer_size,
			$is_s3 ? 'yes' : 'no',
			$res['s3_chunk_size'],
			$res['s3_concurrency'],
			$transfers * ( $res['buffer_size'] + ( $res['s3_chunk_size'] * $res['s3_concurrency'] ) )
		) );

		$command = sprintf( "rclone copy -P %s --transfers %d --checkers %d --buffer-size %s %s %s", $s3_flag, $transfers, $transfers, $buffer_size, escapeshellarg( $path ), escapeshellarg( $this->get_remote_path() ) );
		$output  = EE::launch( $command );

		if ( $output->return_code ) {
			$this->capture_error(
				'Failed to upload backup to remote storage via rclone',
				self::ERROR_TYPE_NETWORK,
				4001
			);
			EE::error( 'Error uploading backup to remote storage.' );
		} else {

			EE::success( 'Backup uploaded to remote storage. Remote path: ' . $this->get_remote_path() );

			// Store the new backup path for potential rollback (only when using dash-auth)
			if ( $this->dash_auth_enabled ) {
				$this->dash_new_backup_path = $this->get_remote_path();
			}

			// Only delete old backups immediately if NOT using dash-auth
			// If using dash-auth, cleanup happens after API callback succeeds
			if ( ! $this->dash_auth_enabled ) {
				$this->cleanup_old_backups();
			}
		}
	}

	/**
	 * Compute memory-safe rclone transfer settings shared by upload and download.
	 *
	 * rclone allocates one `--buffer-size` read-ahead buffer per concurrent
	 * `--transfers`, and, for S3 uploads, an additional
	 * `--s3-chunk-size * --s3-upload-concurrency` multipart buffer per transfer.
	 * The upload in-memory footprint is therefore:
	 *
	 *     transfers * ( buffer_size + s3_chunk_size * s3_upload_concurrency )
	 *
	 * The previous implementation set `buffer_size = available_ram / transfers`,
	 * which made the read-ahead buffers alone consume ~100% of available memory
	 * (e.g. `--buffer-size 1328M --transfers 2` on a 4 GB host) and routinely
	 * triggered the OOM killer during backups. This helper instead caps rclone's
	 * total footprint at a fraction of currently-available memory, while still
	 * scaling parallelism and buffer size up on larger hosts so spare RAM is used.
	 *
	 * The returned `budget` (and `max_buffer`) let callers that allocate further
	 * buffer pools -- e.g. `rclone_download()` sizing `--multi-thread-streams` --
	 * stay within the same single budget instead of recomputing their own.
	 *
	 * Tunable via global config: `rclone-mem-fraction` (default 0.5) and
	 * `rclone-max-buffer-size` in MB (default 256).
	 *
	 * @param int  $cpu_cores     Number of CPU cores (nproc).
	 * @param int  $available_ram Currently available memory in MB.
	 * @param bool $is_s3         Whether the remote is an S3 backend.
	 *
	 * @return array{transfers:int,buffer_size:int,s3_concurrency:int,s3_chunk_size:int,budget:int,max_buffer:int}
	 */
	private function compute_rclone_resources( $cpu_cores, $available_ram, $is_s3 ) {
		$cpu_cores     = max( 1, intval( $cpu_cores ) );
		$available_ram = max( 0, intval( $available_ram ) );

		// Fraction of *available* RAM rclone may use, clamped to a safe range so
		// a backup never starves the host (MariaDB, PHP-FPM, nginx) or itself.
		$mem_fraction = floatval( get_config_value( 'rclone-mem-fraction', 0.5 ) );
		$mem_fraction = min( 0.9, max( 0.1, $mem_fraction ) );

		$min_buffer    = 16; // rclone's default; never go below it.
		$max_buffer    = max( $min_buffer, intval( get_config_value( 'rclone-max-buffer-size', 256 ) ) );
		$s3_chunk_size = $is_s3 ? 64 : 0;

		// Total memory budget for rclone transfer/multipart buffers.
		$budget = (int) floor( $available_ram * $mem_fraction );

		// Desired parallelism, scaled with cores but capped to sane bounds.
		$transfers      = max( 2, min( $cpu_cores, 8 ) );
		$s3_concurrency = $is_s3 ? max( 2, min( $cpu_cores * 2, 32 ) ) : 0;

		// If the budget can't fit the desired parallelism even at the minimum
		// buffer size, first shrink S3 multipart concurrency (the biggest memory
		// lever at 64M/chunk), then the number of parallel transfers. Both may
		// fall to 1 on extremely tight budgets so the helper honours its own cap
		// whenever the budget allows it at all (the desired floor stays at 2).
		while ( $is_s3 && $s3_concurrency > 1 &&
			$transfers * ( $min_buffer + $s3_chunk_size * $s3_concurrency ) > $budget ) {
			$s3_concurrency = max( 1, intval( $s3_concurrency / 2 ) );
		}
		while ( $transfers > 1 &&
			$transfers * ( $min_buffer + $s3_chunk_size * $s3_concurrency ) > $budget ) {
			$transfers--;
		}

		// Spend whatever budget remains after reserving S3 multipart buffers on
		// the read-ahead buffer, clamped to [$min_buffer, $max_buffer].
		$per_transfer_budget = intval( floor( $budget / $transfers ) );
		$buffer_size         = $per_transfer_budget - ( $s3_chunk_size * $s3_concurrency );
		$buffer_size         = max( $min_buffer, min( $buffer_size, $max_buffer ) );

		return [
			'transfers'      => $transfers,
			'buffer_size'    => $buffer_size,
			's3_concurrency' => $s3_concurrency,
			's3_chunk_size'  => $s3_chunk_size,
			'budget'         => $budget,
			'max_buffer'     => $max_buffer,
		];
	}

	/**
	 * Delete old backups from remote storage after successful upload.
	 * Keeps only the configured number of most recent backups.
	 */
	private function cleanup_old_backups() {
		$no_of_backups = $this->get_no_of_backups();

		// Only this host's backups: pre-host-id ones and other hosts' are never pruned here (BKP-8).
		$backups = $this->list_remote_dirs( $this->get_host_backup_root() );

		if ( null === $backups ) {
			EE::warning( 'Could not list remote backups; old backups were not cleaned up.' );

			return;
		}

		$backups = array_values( array_filter( $backups, [ $this, 'is_backup_id' ] ) );
		rsort( $backups );

		if ( empty( $backups ) ) {
			return;
		}

		// Exactly no-of-backups are kept; `> N + 1` kept N + 1 every other run (SC-10).
		if ( count( $backups ) > $no_of_backups ) {
			// Never the backup this run just uploaded, whatever the sort order says (BKP-7).
			$backups_to_delete = array_diff( array_slice( $backups, $no_of_backups ), [ basename( $this->get_remote_path() ) ] );

			EE::log( sprintf( 'Cleaning up old backups. Keeping %d most recent backups.', $no_of_backups ) );
			foreach ( $backups_to_delete as $backup ) {
				EE::log( 'Deleting old backup: ' . $backup );
				$result = EE::launch( sprintf( 'rclone purge %s', escapeshellarg( $this->get_host_backup_root() . '/' . $backup ) ) );
				if ( $result->return_code ) {
					EE::warning( 'Failed to delete old backup: ' . $backup );
				} else {
					EE::debug( 'Successfully deleted old backup: ' . $backup );
				}
			}
			EE::success( sprintf( 'Cleaned up %d old backup(s).', count( $backups_to_delete ) ) );
		} else {
			EE::debug( sprintf( 'No cleanup needed. Current backups: %d, Maximum allowed: %d', count( $backups ), $no_of_backups ) );
		}
	}

	/**
	 * Rollback (delete) the newly uploaded backup when EasyDash API callback fails.
	 * This prevents orphaned backups in remote storage that aren't tracked by EasyDash.
	 */
	private function rollback_failed_backup() {
		if ( empty( $this->dash_new_backup_path ) ) {
			EE::warning( 'Cannot rollback backup: backup path not found.' );

			return;
		}

		EE::warning( 'EasyDash API callback failed. Rolling back newly uploaded backup...' );
		EE::log( 'Deleting unregistered backup: ' . $this->dash_new_backup_path );

		$result = EE::launch( sprintf( 'rclone purge %s', escapeshellarg( $this->dash_new_backup_path ) ) );

		if ( $result->return_code ) {
			EE::warning( sprintf(
				'Failed to delete backup from remote storage. Please manually delete: %s',
				$this->dash_new_backup_path
			) );
		} else {
			EE::success( 'Successfully removed unregistered backup from remote storage.' );
		}
	}

	private function restore_nginx_conf( $backup_dir ) {
		$backup_file = $backup_dir . '/conf.zip';

		EE::log( 'Restoring nginx configuration.' );

		if ( ! $this->fs->exists( $backup_file ) ) {
			$this->rclone_download( $backup_dir );
		}

		$restore_command = sprintf( 'cd %s && unzip -o conf.zip < /dev/null', escapeshellarg( $backup_dir ) );
		$this->exec_or_fail( $restore_command, 'Failed to extract the configuration from the backup.' );

		if ( $this->fs->exists( $backup_dir . '/nginx' ) ) {
			$restore_command = sprintf( 'rsync -a %s %s', escapeshellarg( $backup_dir . '/nginx/' ), escapeshellarg( EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/config/nginx/' ) );
			$this->exec_or_fail( $restore_command, 'Failed to restore the nginx configuration.' );
		}
	}


	private function restore_php_conf( $backup_dir ) {
		$backup_file = $backup_dir . '/conf.zip';

		EE::log( 'Restoring php configuration.' );
		if ( ! $this->fs->exists( $backup_file ) ) {
			$this->rclone_download( $backup_dir );
		}
		if ( ! $this->fs->exists( sprintf( '%s/php', $backup_dir ) ) ) {
			$restore_command = sprintf( 'cd %s && unzip -o conf.zip < /dev/null', escapeshellarg( $backup_dir ) );
			$this->exec_or_fail( $restore_command, 'Failed to extract the configuration from the backup.' );
		}

		if ( $this->fs->exists( sprintf( '%s/php', $backup_dir ) ) ) {
			$site_root = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'];
			foreach ( [ 'php-fpm.d/', 'php/php.ini', 'php/conf.d/custom.ini' ] as $conf ) {
				// Only what the backup has; a failed copy rolls the restore back.
				if ( $this->fs->exists( $backup_dir . '/php/' . rtrim( $conf, '/' ) ) ) {
					// rsync creates the file or last dir, not missing parents.
					$this->fs->mkdir( dirname( $site_root . '/config/php/' . rtrim( $conf, '/' ) ) );
					$restore_command = sprintf( 'rsync -a %s %s', escapeshellarg( $backup_dir . '/php/' . $conf ), escapeshellarg( $site_root . '/config/php/' . $conf ) );
					$this->exec_or_fail( $restore_command, 'Failed to restore the php configuration.' );
				}
			}
		}
	}

	/**
	 * Send success callback to EasyDash API after successful backup.
	 *
	 * @param string $ed_api_url     The EasyDash API URL.
	 * @param string $backup_id      The backup ID.
	 * @param string $verify_token   The verification token.
	 * @param array $backup_metadata The backup metadata.
	 *
	 * @return bool True if API request succeeded, false otherwise.
	 */
	private function send_dash_success_callback( $ed_api_url, $backup_id, $verify_token, $backup_metadata ) {
		$endpoint = rtrim( $ed_api_url, '/' ) . '/easydash.easydash.doctype.site_backup.site_backup.on_ee_backup_success';

		// Debug: Log the values being used for callback
		EE::debug( 'Sending success callback with backup_id: ' . $backup_id );

		// Build metadata for the API call - always include all fields
		$metadata = [
			'post_count'    => $this->sanitize_count( $backup_metadata['post_count'] ?? 0 ),
			'theme_count'   => $this->sanitize_count( $backup_metadata['theme_count'] ?? 0 ),
			'user_count'    => $this->sanitize_count( $backup_metadata['user_count'] ?? 0 ),
			'plugin_count'  => $this->sanitize_count( $backup_metadata['plugin_count'] ?? 0 ),
			'wp_version'    => $backup_metadata['wp_version'] ?? '',
			'comment_count' => $this->sanitize_count( $backup_metadata['comment_count'] ?? 0 ),
			'page_count'    => $this->sanitize_count( $backup_metadata['page_count'] ?? 0 ),
			'upload_count'  => $this->sanitize_count( $backup_metadata['upload_count'] ?? 0 ),
			'site_type'     => $backup_metadata['site_type'] ?? 'html',
			'remote_path'   => $backup_metadata['remote_path'] ?? '',
		];

		$payload = [
			'site'     => $this->site_data['site_url'],
			'backup'   => $backup_id,
			'verify'   => $verify_token,
			'metadata' => $metadata,
		];

		EE::debug( 'Payload being sent: ' . json_encode( array_merge( $payload, [ 'verify' => '******' ] ) ) );

		return $this->send_dash_request( $endpoint, $payload );
	}

	/**
	 * Send failure callback to EasyDash API after failed backup.
	 * Includes error details (message, type, code) for debugging and user feedback.
	 *
	 * @param string $ed_api_url   The EasyDash API URL.
	 * @param string $backup_id    The backup ID.
	 * @param string $verify_token The verification token.
	 */
	private function send_dash_failure_callback( $ed_api_url, $backup_id, $verify_token ) {
		$endpoint = rtrim( $ed_api_url, '/' ) . '/easydash.easydash.doctype.site_backup.site_backup.on_ee_backup_failure';

		$payload = [
			'site'          => $this->site_data['site_url'],
			'backup'        => $backup_id,
			'verify'        => $verify_token,
			'error_message' => $this->dash_error_message ?: 'Unknown error occurred',
			'error_type'    => $this->dash_error_type,
			'error_code'    => $this->dash_error_code,
		];

		EE::debug( 'Sending failure callback with error details: ' . json_encode( [
				'error_message' => $payload['error_message'],
				'error_type'    => $payload['error_type'],
				'error_code'    => $payload['error_code'],
			] ) );

		$this->send_dash_request( $endpoint, $payload );
	}

	/**
	 * Send HTTP request to EasyEngine Dashboard API with retry logic for 5xx errors and connection errors.
	 *
	 * @param string $endpoint The API endpoint URL.
	 * @param array $payload   The request payload.
	 *
	 * @return bool True if request succeeded, false otherwise.
	 */
	private function send_dash_request( $endpoint, $payload ) {
		$max_retries  = 3;
		$retry_delay  = 300; // 5 minutes in seconds
		$max_attempts = $max_retries + 1; // 1 initial attempt + 3 retries = 4 total
		$attempt      = 1;

		while ( $attempt <= $max_attempts ) {
			$ch = curl_init( $endpoint );

			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
			] );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );

			$response  = curl_exec( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error     = curl_error( $ch );

			curl_close( $ch );

			// Normalize response for safe string concatenation
			$response_text = ( false === $response ) ? 'No response received' : $response;

			// Check if request was successful
			if ( ! $error && $http_code >= 200 && $http_code < 300 ) {
				EE::log( 'EasyEngine Dashboard callback sent successfully.' );
				EE::debug( 'EasyEngine Dashboard response: ' . $response_text );

				return true; // Success
			}

			// Determine if this is a retryable error
			$is_5xx_error        = $http_code >= 500 && $http_code < 600;
			$is_connection_error = ! empty( $error ) || $http_code === 0;
			$should_retry        = ( $is_5xx_error || $is_connection_error ) && $attempt < $max_attempts;

			if ( $should_retry ) {
				// Retry on 5xx errors or connection errors
				if ( $is_5xx_error ) {
					EE::warning( sprintf(
						'EasyEngine Dashboard callback failed with HTTP %d (attempt %d/%d). Retrying in %d seconds...',
						$http_code,
						$attempt,
						$max_attempts,
						$retry_delay
					) );
					EE::debug( 'Response: ' . $response_text );
				} else {
					// Connection error
					$error_message = ! empty( $error ) ? $error : 'No HTTP response received';
					EE::warning( sprintf(
						'EasyEngine Dashboard connection error: %s (attempt %d/%d). Retrying in %d seconds...',
						$error_message,
						$attempt,
						$max_attempts,
						$retry_delay
					) );
				}
				sleep( $retry_delay );
				$attempt ++; // Increment at end of loop iteration
			} else {
				// Either not a retryable error, or we've exhausted all retries
				if ( $error ) {
					// cURL error occurred after all retries (network, DNS, timeout, etc.)
					EE::warning( sprintf(
						'Failed to send callback to EasyEngine Dashboard after %d retries: %s',
						$max_retries,
						$error
					) );
				} elseif ( $is_5xx_error ) {
					// 5xx error after all retries exhausted
					EE::warning( sprintf(
						'EasyEngine Dashboard callback failed after %d retries with HTTP %d. Response: %s',
						$max_retries,
						$http_code,
						$response_text
					) );
				} elseif ( $http_code === 0 ) {
					// No HTTP response received after all retries
					EE::warning( sprintf(
						'EasyEngine Dashboard callback failed after %d retries: No HTTP response received. Response: %s',
						$max_retries,
						$response_text
					) );
				} else {
					// 4xx or other HTTP error codes that shouldn't be retried
					EE::warning( 'EasyEngine Dashboard callback returned HTTP ' . $http_code . '. Response: ' . $response_text );
				}

				return false; // Failure
			}
		}

		return false; // Should never reach here, but return false as fallback
	}

	/**
	 * Sanitize count value for API payload.
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return int The sanitized integer value.
	 */
	private function sanitize_count( $value ) {
		if ( $value === '-' || ! is_numeric( $value ) ) {
			return 0;
		}

		return intval( $value );
	}

	/**
	 * Acquire a global backup lock to ensure only one backup runs at a time.
	 * Uses flock() for atomic, race-condition-free locking.
	 *
	 * This prevents multiple concurrent backups from exhausting system resources
	 * (RAM, CPU, disk I/O, network bandwidth) when triggered simultaneously.
	 *
	 * Note: flock() may not work reliably on NFS or other network filesystems.
	 * EE_BACKUP_DIR should be on a local filesystem for proper lock behavior.
	 *
	 * @return void
	 */
	private function acquire_global_backup_lock() {
		$lock_file = EE_BACKUP_DIR . '/backup-global.lock';
		$max_wait  = 86400; // 24 hours max wait
		$waited    = 0;
		$interval  = 60;    // Check every 60 seconds

		// Ensure backup directory exists
		if ( ! $this->fs->exists( EE_BACKUP_DIR ) ) {
			$this->fs->mkdir( EE_BACKUP_DIR );
		}

		// Open file handle (creates if doesn't exist)
		$this->global_backup_lock_handle = fopen( $lock_file, 'c+' );

		if ( ! $this->global_backup_lock_handle ) {
			$this->capture_error(
				'Cannot create backup lock file',
				self::ERROR_TYPE_FILESYSTEM,
				5002
			);
			EE::error( 'Cannot create backup lock file.' );
		}

		// Try to acquire exclusive lock (non-blocking first to log status)
		while ( ! flock( $this->global_backup_lock_handle, LOCK_EX | LOCK_NB ) ) {
			if ( $waited >= $max_wait ) {
				fclose( $this->global_backup_lock_handle );
				$this->global_backup_lock_handle = null;
				$this->capture_error(
					'Timeout waiting for another backup to complete',
					self::ERROR_TYPE_LOCK,
					5003
				);
				EE::error( 'Timeout waiting for another backup. Try again later.' );
			}

			// Read who has the lock
			rewind( $this->global_backup_lock_handle );
			$lock_info = stream_get_contents( $this->global_backup_lock_handle );

			EE::log( sprintf( 'Another backup in progress (%s). Waiting... (%d/%d sec)',
				trim( $lock_info ) ?: 'unknown', $waited, $max_wait ) );

			sleep( $interval );
			$waited += $interval;
		}

		// Got the lock! Write our info
		ftruncate( $this->global_backup_lock_handle, 0 );
		rewind( $this->global_backup_lock_handle );
		fwrite( $this->global_backup_lock_handle, $this->site_data['site_url'] . ' (PID: ' . getmypid() . ')' );
		fflush( $this->global_backup_lock_handle );

		EE::debug( 'Acquired global backup lock for: ' . $this->site_data['site_url'] );
	}

	/**
	 * Release the global backup lock.
	 * Safe to call multiple times (idempotent).
	 *
	 * @return void
	 */
	public function release_global_backup_lock() {
		if ( $this->global_backup_lock_handle ) {
			flock( $this->global_backup_lock_handle, LOCK_UN );
			fclose( $this->global_backup_lock_handle );
			$this->global_backup_lock_handle = null;
			EE::debug( 'Released global backup lock' );
		}
	}
}
