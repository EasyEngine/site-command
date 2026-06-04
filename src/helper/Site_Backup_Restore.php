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
			$this->list_remote_backups();

			return; // Exit after listing backups
		}

		// Handle --dash-auth flag for EasyDash integration
		if ( $dash_auth ) {
			// Debug: Log the raw dash_auth value received
			EE::debug( 'Received --dash-auth value: ' . $dash_auth );

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

			// Debug: Log parsed values
			EE::debug( 'Parsed backup_id: ' . $this->dash_backup_id );
			EE::debug( 'Parsed verify_token: ' . $this->dash_verify_token );
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

		// Acquire global lock to serialize backups (prevents OOM from concurrent backups)
		$this->acquire_global_backup_lock();

		// Register shutdown handler to release lock on any exit (error, crash, etc.)
		register_shutdown_function( [ $this, 'release_global_backup_lock' ] );

		$this->pre_backup_check();
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

		$this->fs->remove( EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.lock' );

		// Mark backup as completed and send success callback
		$this->dash_backup_completed = true;
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

		// Release global backup lock (also released by shutdown handler as safety net)
		$this->release_global_backup_lock();

		EE::success( 'Backup created successfully.' );

		delem_log( 'site backup end' );
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

		if ( ! $this->fs->exists( $backup_dir ) ) {
			$this->fs->mkdir( $backup_dir );
		}

		if ( $backup_id ) {

			if ( ! $this->verify_backup_id( $backup_id ) ) {
				EE::error( "Invalid backup ID provided.\nPlease provide a valid ID from the list using 'ee site backup --list " . $this->site_data['site_url'] . "'." );
			}
			// Set the config path to specified backup ID.
			$this->rclone_config_path = \EE\Utils\trailingslashit( $this->get_rclone_config_path() ) . $backup_id;
		}

		$this->pre_restore_check();

		if ( 'wp' === $this->site_data['site_type'] ) {
			$this->restore_wp( $backup_dir );
		} else {
			$this->restore_site( $backup_dir );
		}

		// restore custom compose files
		$this->maybe_restore_custom_docker_compose( $backup_dir );

		$this->fs->remove( $backup_dir );

		EE::log( 'Reloading site.' );
		EE::run_command( [ 'site', 'reload', $this->site_data['site_url'] ], [], [] );

		$this->fs->remove( EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.lock' );

		EE::success( 'Site restored successfully.' );

		delem_log( 'site restore end' );
	}

	private function verify_backup_id( $backup_id ) {

		$backups = $this->list_remote_backups( true );

		if ( empty( $backups ) ) {
			return false;
		}

		return in_array( $backup_id, $backups, true );
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
		$backup_data                = array_merge( $this->site_data, $backup_data );

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

	private function backup_site_dir( $backup_dir ) {

		EE::log( 'Backing up site files.' );
		EE::log( 'This may take some time.' );
		$site_dir       = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app';
		$backup_file    = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';
		$backup_command = sprintf( 'cd %s && 7z a -mx=1 %s .', $site_dir, $backup_file );

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

	private function backup_wp_content_dir( $backup_dir ) {
		EE::log( 'Backing up site files.' );
		EE::log( 'This may take some time.' );

		$container_fs_path = $this->site_data['site_container_fs_path'];
		$container_fs_path = str_replace( '/var/www/', '', $container_fs_path );
		$site_dir          = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app/' . $container_fs_path;
		$backup_file       = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';

		if ( ! $this->fs->exists( $site_dir . '/wp-content' ) ) {
			if ( $this->fs->exists( $site_dir . '/current/wp-content' ) ) {
				if ( ! $this->fs->exists( $site_dir . '/wp-cli.yml' ) ) {
					$this->fs->dumpFile( $site_dir . '/wp-cli.yml', "path: current/" );
				}
				$site_dir = $site_dir . '/current';
			} else {
				EE::warning( 'wp-content directory not found in the site.' );
				EE::log( 'Backing up complete site directory.' );

				return $this->backup_site_dir( $backup_dir ); // Backup all if wp-content not found
			}
		}

		$backup_command = sprintf( 'cd %s && 7z a -mx=1 %s wp-config.php', $site_dir . '/../', $backup_file );
		$result         = EE::launch( $backup_command );

		// 7z exit codes: 0=success, 1=warning (non-fatal), 2+=fatal error
		if ( $result->return_code >= 2 || ! $this->fs->exists( $backup_file ) ) {
			$this->capture_error(
				'Failed to create WordPress content backup archive',
				self::ERROR_TYPE_FILESYSTEM,
				3002
			);
			EE::error( 'Failed to create backup archive. Please check disk space and file permissions.' );
		}

		// meta.json path
		$meta_file = $backup_dir . '/meta.json';

		// Include meta.json in the zip archive (Corrected logic)
		$backup_command = sprintf( 'cd %s && 7z u -snl -mx=1 %s %s wp-content', $site_dir, $backup_file, $meta_file );
		$result         = EE::launch( $backup_command );
		// Remove the file
		$this->fs->remove( $meta_file );

		// 7z exit codes: 0=success, 1=warning (non-fatal), 2+=fatal error
		if ( $result->return_code >= 2 || ! $this->fs->exists( $backup_file ) ) {
			$this->capture_error(
				'Failed to create WordPress content backup archive',
				self::ERROR_TYPE_FILESYSTEM,
				3002
			);
			EE::error( 'Failed to create backup archive. Please check disk space and file permissions.' );
		}

		$uploads_dir = $site_dir . '/wp-content/uploads';
		if ( is_link( $uploads_dir ) ) {
			$backup_command = sprintf( 'cd %s && 7z u -mx=1 %s wp-content/uploads', $site_dir, $backup_file );
			$result         = EE::launch( $backup_command );

			// 7z exit codes: 0=success, 1=warning (non-fatal), 2+=fatal error
			if ( $result->return_code >= 2 ) {
				$this->capture_error(
					'Failed to create WordPress content backup archive',
					self::ERROR_TYPE_FILESYSTEM,
					3002
				);
				EE::error( 'Failed to create backup archive. Please check disk space and file permissions.' );
			}
		}

		// Final check that backup file was created successfully
		if ( ! $this->fs->exists( $backup_file ) ) {
			$this->capture_error(
				'Failed to create WordPress content backup archive',
				self::ERROR_TYPE_FILESYSTEM,
				3002
			);
			EE::error( 'Failed to create backup archive. Please check disk space and file permissions.' );
		}

		return $backup_file;
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

		$backup_command = sprintf( 'mysqldump --skip-ssl -u %s -p%s -h %s --single-transaction %s > /var/www/htdocs/%s', $db_user, $db_password, $db_host, $db_name, $sql_filename );
		$args           = [ 'shell', $this->site_data['site_url'] ];
		$assoc_args     = [ 'command' => $backup_command ];
		$options        = [ 'skip-tty' => true ];

		EE::run_command( $args, $assoc_args, $options );

		$sql_dump_path = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app/htdocs/' . $sql_filename;

		// Check if database dump was created successfully
		if ( ! $this->fs->exists( $sql_dump_path ) ) {
			$this->capture_error(
				sprintf( 'Database backup failed for database: %s', $db_name ),
				self::ERROR_TYPE_DATABASE,
				4002
			);
			EE::error( 'Database backup failed. Please check database credentials and connectivity.' );
		}

		EE::exec( sprintf( 'mv %s %s', $sql_dump_path, $sql_file ) );
		$backup_command = sprintf( 'cd %s && 7z u -mx=1 %s sql', $backup_dir, $backup_file );

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

	private function maybe_restore_wp_config( $backup_dir ) {
		if ( 'wp' !== $this->site_data['site_type'] ) {
			return false;
		}

		$backup_file       = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';
		$container_fs_path = $this->site_data['site_container_fs_path'];
		$container_fs_path = str_replace( '/var/www/', '', $container_fs_path );
		$site_dir          = $this->site_data['site_fs_path'] . '/app/' . $container_fs_path;
		$wp_config_path    = $site_dir . '/../';

		$unzip_command = sprintf( 'unzip -o %s wp-config.php -d %s', $backup_file, $wp_config_path );
		EE::exec( $unzip_command );

		$chown_command = sprintf( 'chown -R www-data:www-data %s', $wp_config_path );
		EE::exec( $chown_command );

		$db_name     = $this->site_data['db_name'];
		$db_user     = $this->site_data['db_user'];
		$db_password = $this->site_data['db_password'];
		$db_host     = $this->site_data['db_host'];
		$args        = [ 'shell', $this->site_data['site_url'] ];
		$options     = [ 'skip-tty' => true ];

		$command = sprintf( 'wp config set DB_NAME %s', $db_name );
		EE::run_command( $args, [ 'command' => $command ], $options );

		$command = sprintf( 'wp config set DB_USER %s', $db_user );
		EE::run_command( $args, [ 'command' => $command ], $options );

		$command = sprintf( 'wp config set DB_PASSWORD %s', $db_password );
		EE::run_command( $args, [ 'command' => $command ], $options );

		$command = sprintf( 'wp config set DB_HOST %s', $db_host );
		EE::run_command( $args, [ 'command' => $command ], $options );
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

	private function restore_db( $sql_file, $container_path ) {
		EE::log( 'Restoring database.' );

		$site_url    = $this->site_data['site_url'];
		$db_user     = $this->site_data['db_user'];
		$db_password = $this->site_data['db_password'];
		$db_host     = $this->site_data['db_host'];
		$db_name     = $this->site_data['db_name'];
		$sql_path    = "/var/www/$container_path/" . basename( $sql_file ); // Use basename for safety

		// Corrected command with proper escaping and error suppression for password
		$restore_command = sprintf( "mysql --skip-ssl -u '%s' -p'%s' -h '%s' '%s' < '%s' 2>/dev/null", $db_user, $db_password, $db_host, $db_name, $sql_path );

		$args       = [ 'shell', $site_url ];
		$assoc_args = [ 'command' => $restore_command ];
		$options    = [ 'skip-tty' => true ];
		EE::run_command( $args, $assoc_args, $options );
	}

	private function restore_site( $backup_dir ) {
		$backup_app = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';

		if ( ! $this->fs->exists( $backup_app ) ) {
			$this->rclone_download( $backup_dir );
		}

		EE::log( 'Restoring site files.' );

		$site_app_dir = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app';
		// Remote the existing content inside the app directory but not the app directory itself
		$remove_command = sprintf( 'rm -rf %s/*', $site_app_dir );
		EE::exec( $remove_command );

		$restore_command = sprintf( 'unzip -o %s -d %s', $backup_app, $site_app_dir );
		EE::exec( $restore_command );

		$chown_command = sprintf( 'chown -R www-data:www-data %s', \EE\Utils\trailingslashit( $site_app_dir ) );
		EE::exec( $chown_command );

		$backup_db = $site_app_dir . '/sql/' . $this->site_data['site_url'] . '.sql';
		if ( $this->fs->exists( $backup_db ) ) {
			$this->restore_db( $backup_db, 'sql' );
			$this->fs->remove( $site_app_dir . '/sql' );
		}

		$this->maybe_restore_custom_docker_compose( $backup_dir );
		$this->restore_nginx_conf( $backup_dir );

		if ( in_array( $this->site_data['site_type'], [ 'php', 'wp' ], true ) ) {
			$this->restore_php_conf( $backup_dir );
		}
	}

	private function restore_wp( $backup_dir ) {
		$backup_app = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';

		if ( ! $this->fs->exists( $backup_app ) ) {
			$this->rclone_download( $backup_dir );
		}

		EE::log( 'Restoring site files.' );

		$container_fs_path = $this->site_data['site_container_fs_path'];
		$container_fs_path = str_replace( '/var/www/', '', $container_fs_path );
		$site_dir          = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app/' . $container_fs_path;

		$unzip_meta_command = sprintf( 'unzip -o %s meta.json -d %s', $backup_app, $backup_dir );
		EE::exec( $unzip_meta_command );

		$meta_data  = json_decode( file_get_contents( $backup_dir . '/meta.json' ), true );
		$wp_version = $meta_data['wordpressVersion'];

		$args       = [ 'shell', $this->site_data['site_url'] ];
		$assoc_args = [ 'command' => sprintf( 'wp core download --force --version=%s', $wp_version ) ];
		$options    = [ 'skip-tty' => true ];
		EE::run_command( $args, $assoc_args, $options );

		$this->maybe_restore_wp_config( $backup_dir );

		$restore_command = sprintf( 'unzip -o %s sql/%s.sql -d %s/app/', $backup_app, $this->site_data['site_url'], $this->site_data['site_fs_path'] );
		EE::exec( $restore_command );

		$this->restore_db( $this->site_data['site_url'] . '.sql', 'sql' );
		$this->fs->remove( $this->site_data['site_fs_path'] . '/app/sql' );

		$uploads_moved = false;
		// if wp-content/uploads is symlink, then move it one level up
		if ( is_link( $site_dir . '/wp-content/uploads' ) ) {
			// move the symlink one level up for time being
			$mv_command = sprintf( 'mv %s/wp-content/uploads %s/uploads', $site_dir, $site_dir );
			EE::exec( $mv_command );
			$uploads_moved = true;
		}

		// Remove all files from wp-content except uploads
		$this->fs->remove( $site_dir . '/wp-content' );

		$wp_content_command = sprintf( "unzip -o %s 'wp-content/*' -x 'wp-content/uploads/*' -d %s", $backup_app, $site_dir );
		EE::exec( $wp_content_command );

		if ( $uploads_moved ) {
			// move the uploads directory back to wp-content
			$mv_command = sprintf( 'mv %s/uploads %s/wp-content/uploads', $site_dir, $site_dir );
			EE::exec( $mv_command );
		}

		$uploads_command = sprintf( "unzip -o %s 'wp-content/uploads/*' -d %s", $backup_app, $site_dir );
		EE::exec( $uploads_command );

		$this->maybe_restore_custom_docker_compose( $backup_dir );

		$chown_command = sprintf( 'chown -R www-data:www-data %s/app/', $this->site_data['site_fs_path'] );
		EE::exec( $chown_command );

		$this->restore_nginx_conf( $backup_dir );
		$this->restore_php_conf( $backup_dir );

		$args       = [ 'shell', $this->site_data['site_url'] ];
		$assoc_args = [ 'command' => 'wp cache flush --skip-plugins --skip-themes' ];
		$options    = [ 'skip-tty' => true ];

		EE::run_command( $args, $assoc_args, $options );
	}

	private function pre_backup_restore_checks() {
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

		if ( strpos( $output->stdout, $rclone_path ) === false ) {
			$this->capture_error(
				sprintf( 'rclone backend "%s" is not configured. Please create it using `rclone config`', $rclone_backend ),
				self::ERROR_TYPE_CONFIG,
				2002
			);
			EE::error( sprintf( 'rclone backend "%s" does not exist. Please create it using `rclone config`', $rclone_backend ) );
		}

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
			EE::error( 'Another backup/restore process is running. Please wait for it to complete.' );
		} else {
			$this->fs->dumpFile( $lock_file, 'lock' );
		}
	}

	private function pre_backup_check() {
		$this->pre_backup_restore_checks();

		$site_path = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app/htdocs';
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

			$this->fs->remove( EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.lock' );
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
					EE::exec( 'apt-get update' );
					EE::exec( "apt-get install -y $name" );
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

			$container_fs_path = rtrim( $container_fs_path, '/' );
			$wp_cli_yml_path   = str_replace( 'htdocs', '', $container_fs_path );
			$wp_cli_yml_path   = ltrim( $wp_cli_yml_path, '/' );

			if ( ! empty( $wp_cli_yml_path ) ) {
				$this->fs->dumpFile( $this->site_data['site_fs_path'] . '/app/htdocs/wp-cli.yml', "path: $wp_cli_yml_path/" );
			}
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
		$user     = escapeshellarg( $this->site_data['db_user'] );
		$password = escapeshellarg( $this->site_data['db_password'] );
		$host     = escapeshellarg( $this->site_data['db_host'] );
		$db_name  = escapeshellarg( $this->site_data['db_name'] );

		$query = "
			SELECT
				table_schema AS 'Database',
				SUM(data_length + index_length) AS 'Size (Bytes)'
			FROM
				information_schema.TABLES
			WHERE
				table_schema = '" . $this->site_data['db_name'] . "'
			GROUP BY
				table_schema;
		";


		$query_file = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app/htdocs/db_size_query.sql';
		$this->fs->dumpFile( $query_file, $query );


		$command = sprintf( "mysql --skip-ssl -u %s -p%s -h %s %s < /var/www/htdocs/db_size_query.sql", $user, $password, $host, $db_name );

		$output = EE::launch( "ee shell " . $this->site_data['site_url'] . " --skip-tty --command=\"$command\"" );


		$this->fs->remove( $query_file );


		$size        = 0;
		$size_output = explode( "\n", $output->stdout );

		if ( count( $size_output ) > 1 ) {
			$size_array = explode( "\t", $size_output[1] );
			$size       = isset( $size_array[1] ) ? $size_array[1] : 0;
		}

		EE::debug( "DB size: $size" );

		return (int) $size;
	}

	private function list_remote_backups( $return = false ) {

		$remote_path = $this->get_rclone_config_path(); // Get remote path without creating a new timestamped folder

		$command = sprintf( 'rclone lsf --dirs-only %s', escapeshellarg( $remote_path ) ); // List only directories
		$output  = EE::launch( $command );

		if ( $output->return_code !== 0 && ! $return ) {
			EE::error( "Error listing remote backups: " . $output->stderr ); // Display specific error
		} elseif ( $output->return_code !== 0 ) {
			return [];
		}

		$backups = explode( PHP_EOL, trim( $output->stdout ) );  // Remove extra whitespace and split

		if ( empty( $backups ) ) {
			if ( ! $return ) {
				EE::log( 'No remote backups found.' );
			}

			return [];
		}

		$backups = array_map(
			function ( $backup ) {
				return rtrim( $backup, '/' );
			}, $backups
		);

		$backups = array_filter(
			$backups, function ( $backup ) {
			return preg_match( '/\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}/', $backup );
		}
		);

		rsort( $backups );

		if ( $return ) {
			return $backups;
		}

		foreach ( $backups as $backup ) {
			EE::log( $backup );
		}

	}

	private function get_rclone_config_path() {

		$rclone_config_path = get_config_value( 'rclone-path', 'easyengine:easyengine' );
		$rclone_config_path = \EE\Utils\trailingslashit( $rclone_config_path ) . $this->site_data['site_url'];

		return $rclone_config_path;
	}

	private function get_remote_path( $upload = true ) {
		if ( ! empty( $this->rclone_config_path ) ) {
			return $this->rclone_config_path;
		}

		$this->rclone_config_path = $this->get_rclone_config_path();

		$backups   = $this->list_remote_backups( true );
		$timestamp = time() . '_' . date( 'Y-m-d-H-i-s' );

		if ( ! empty( $backups ) ) {

			if ( ! $upload ) {
				// For restore: use the most recent backup
				$timestamp = $backups[0];
				EE::log( 'Restoring from backup: ' . $timestamp );
			}
		}

		$this->rclone_config_path .= '/' . $timestamp;

		return $this->rclone_config_path;
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

		EE::debug( sprintf(
			'rclone download tuning: available_ram=%dMB budget=%dMB transfers=%d buffer-size=%s multi-thread-streams=%d mt-write-buffer=%dKi mt-chunk-size=%dM (est. peak ~%dMB)',
			$available_ram,
			$budget,
			$transfers,
			$buffer_size,
			$mt_streams,
			$mt_write_buffer,
			$mt_chunk_size,
			(int) ( $transfers * ( $buffer_mb + $mt_streams * $per_stream_mem ) )
		) );

		$command = sprintf( "rclone copy -P --transfers %d --buffer-size %s --multi-thread-streams %d --multi-thread-write-buffer-size %dKi --multi-thread-chunk-size %dM %s %s", $transfers, $buffer_size, $mt_streams, $mt_write_buffer, $mt_chunk_size, escapeshellarg( $this->get_remote_path( false ) ), escapeshellarg( $path ) );
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

			$command     = sprintf( 'rclone lsf %s', escapeshellarg( $this->get_remote_path( false ) ) );
			$output      = EE::launch( $command );
			$remote_path = $output->stdout;
			EE::success( 'Backup uploaded to remote storage. Remote path: ' . $remote_path );

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
		$no_of_backups = intval( get_config_value( 'no-of-backups', 7 ) );

		// Get fresh list of backups after the new upload
		$backups = $this->list_remote_backups( true );

		if ( empty( $backups ) ) {
			return;
		}

		// Check if we have more backups than allowed
		if ( count( $backups ) > ( $no_of_backups + 1 ) ) {
			$backups_to_delete = array_slice( $backups, $no_of_backups );

			EE::log( sprintf( 'Cleaning up old backups. Keeping %d most recent backups.', $no_of_backups ) );
			foreach ( $backups_to_delete as $backup ) {
				EE::log( 'Deleting old backup: ' . $backup );
				$result = EE::launch( sprintf( 'rclone purge %s/%s', escapeshellarg( $this->get_rclone_config_path() ), escapeshellarg( $backup ) ) );
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

		$restore_command = sprintf( 'cd %s && unzip -o conf.zip', $backup_dir );
		EE::exec( $restore_command );

		if ( $this->fs->exists( $backup_dir . '/nginx' ) ) {
			$restore_command = sprintf( 'rsync -a %s/nginx/ %s/config/nginx/', $backup_dir, EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] );
			EE::exec( $restore_command );
		}
	}


	private function restore_php_conf( $backup_dir ) {
		$backup_file = $backup_dir . '/conf.zip';

		EE::log( 'Restoring php configuration.' );
		if ( ! $this->fs->exists( $backup_file ) ) {
			$this->rclone_download( $backup_dir );
		}
		if ( ! $this->fs->exists( sprintf( '%s/php', $backup_dir ) ) ) {
			$restore_command = sprintf( 'cd %s && unzip -o conf.zip', $backup_dir );
			EE::exec( $restore_command );
		}

		if ( $this->fs->exists( sprintf( '%s/php', $backup_dir ) ) ) {
			$restore_command = sprintf( 'rsync -a %s/php/php-fpm.d/ %s/config/php/php-fpm.d/', $backup_dir, EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] );
			EE::exec( $restore_command );

			$restore_command = sprintf( 'rsync -a %s/php/php/php.ini %s/config/php/php/php.ini', $backup_dir, EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] );
			EE::exec( $restore_command );

			$restore_command = sprintf( 'rsync -a %s/php/php/conf.d/custom.ini %s/config/php/php/conf.d/custom.ini', $backup_dir, EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] );
			EE::exec( $restore_command );
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
		EE::debug( 'Sending success callback with verify_token: ' . $verify_token );

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

		EE::debug( 'Payload being sent: ' . json_encode( $payload ) );

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
