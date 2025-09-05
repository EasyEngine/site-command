<?php

namespace EE\Site\Type;

use EE;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Utils\get_config_value;
use function EE\Utils\delem_log;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;

class Site_Backup_Restore {

	private $fs;
	public $site_data;
	private $rclone_config_path;

	public function __construct() {
		$this->fs = new Filesystem();
	}

	public function backup( $args, $assoc_args = [] ) {
		delem_log( 'site backup start' );
		$args            = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data = get_site_info( $args, true, true, true );
		$list_backups    = \EE\Utils\get_flag_value( $assoc_args, 'list' );

		// Handle --list flag to display available backups
		if ( $list_backups ) {
			$this->list_remote_backups();

			return; // Exit after listing backups
		}

		$this->pre_backup_check();
		$backup_dir = EE_BACKUP_DIR . '/' . $this->site_data['site_url'];

		$this->fs->remove( $backup_dir );
		$this->fs->mkdir( $backup_dir );

		$this->backup_site_details( $backup_dir );

		switch ( $this->site_data['site_type'] ) {
			case 'html':
				$this->backup_html( $backup_dir );
				break;
			case 'php':
			case 'wp':
				$this->backup_php_wp( $backup_dir );
				break;
			default:
				EE::error( 'Backup is not supported for this site type.' );
		}

		$this->rclone_upload( $backup_dir );
		$this->fs->remove( $backup_dir );

		$this->fs->remove( EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.lock' );
		delem_log( 'site backup end' );
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
			EE::exec( $archive_command );
		}
	}

	private function backup_site_dir( $backup_dir ) {

		EE::log( 'Backing up site files.' );
		EE::log( 'This may take some time.' );
		$site_dir       = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app';
		$backup_file    = $backup_dir . '/' . $this->site_data['site_url'] . '.zip';
		$backup_command = sprintf( 'cd %s && 7z a -mx=1 %s .', $site_dir, $backup_file );

		EE::exec( $backup_command );

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
		EE::exec( $backup_command );

		// meta.json path
		$meta_file = $backup_dir . '/meta.json';

		// Include meta.json in the zip archive (Corrected logic)
		$backup_command = sprintf( 'cd %s && 7z u -snl -mx=1 %s %s wp-content', $site_dir, $backup_file, $meta_file );
		EE::exec( $backup_command );
		// Remove the file
		$this->fs->remove( $meta_file );


		$uploads_dir = $site_dir . '/wp-content/uploads';
		if ( is_link( $uploads_dir ) ) {
			$backup_command = sprintf( 'cd %s && 7z u -mx=1 %s wp-content/uploads', $site_dir, $backup_file );
			EE::exec( $backup_command );
		}

		return $backup_file;
	}

	private function backup_nginx_conf( $backup_dir ) {
		EE::log( 'Backing up nginx configuration.' );

		$conf_dir       = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/config';
		$backup_file    = $backup_dir . '/conf.zip';
		$backup_command = sprintf( 'cd %s && 7z a -snl -mx=1 %s nginx', $conf_dir, $backup_file );

		EE::exec( $backup_command );
	}

	private function backup_php_conf( $backup_dir ) {
		EE::log( 'Backing up php configuration.' );

		$conf_dir       = EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/config';
		$backup_file    = $backup_dir . '/conf.zip';
		$backup_command = sprintf( 'cd %s && 7z u -snl -mx=1 %s php', $conf_dir, $backup_file );

		EE::exec( $backup_command );
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
		EE::exec( sprintf( 'mv %s %s', EE_ROOT_DIR . '/sites/' . $this->site_data['site_url'] . '/app/htdocs/' . $sql_filename, $sql_file ) );
		$backup_command = sprintf( 'cd %s && 7z u -mx=1 %s sql', $backup_dir, $backup_file );

		EE::exec( $backup_command );
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
			EE::error( 'rclone is not installed. Please install rclone for backup/restore: https://rclone.org/downloads/#script-download-and-install' );
		}

		$command = 'rclone listremotes';
		$output  = EE::launch( $command );

		$rclone_path = get_config_value( 'rclone-path', 'easyengine:easyengine' );
		$rclone_path = explode( ':', $rclone_path )[0] . ':';

		if ( strpos( $output->stdout, $rclone_path ) === false ) {
			EE::error( 'rclone backend easyengine does not exist. Please create it using `rclone config`' );
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
			EE::error( 'Not enough disk space to take backup. Please free up some space and try again.' );
			$this->fs->remove( EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '.lock' );
		}
	}

	private function check_and_install( $command, $name ) {
		$status = EE::exec( "command -v $command" );
		if ( ! $status ) {
			if ( IS_DARWIN ) {
				EE::error( "$name is not installed. Please install $name for backup/restore. You can install it using `brew install $name`." );
			} else {
				$status = EE::exec( 'apt-get --version' );
				if ( $status ) {
					EE::exec( 'apt-get update' );
					EE::exec( "apt-get install -y $name" );
				} else {
					EE::error( "$name is not installed. Please install $name for backup/restore." );
				}
			}
		}
	}

	private function pre_restore_check() {

		$this->pre_backup_restore_checks();

		$remote_path = $this->get_remote_path( false );
		$command     = sprintf( 'rclone size --json %s', $remote_path );
		$output      = EE::launch( $command );

		if ( $output->return_code ) {
			EE::error( 'Failed to get remote backup size.' );
		}

		$remote_size = json_decode( $output->stdout, true )['bytes'];
		EE::debug( 'Remote backup size: ' . $remote_size );

		$free_space = disk_free_space( EE_BACKUP_DIR );

		if ( $remote_size > $free_space ) {
			EE::error( 'Not enough disk space to restore backup. Please free up some space and try again.' );
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

		$command = sprintf( 'rclone lsf --dirs-only %s', $remote_path ); // List only directories
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

		$no_of_backups = intval( get_config_value( 'no-of-backups', 7 ) );

		$backups   = $this->list_remote_backups( true );
		$timestamp = time() . '_' . date( 'Y-m-d-H-i-s' );

		if ( ! empty( $backups ) ) {

			if ( $upload ) {
				if ( count( $backups ) > $no_of_backups ) {
					$backups_to_delete = array_slice( $backups, $no_of_backups );
					foreach ( $backups_to_delete as $backup ) {
						EE::log( 'Deleting old backup: ' . $backup );
						EE::launch( sprintf( 'rclone purge %s/%s', $this->rclone_config_path, $backup ) );
					}
				}
			} else {

				$timestamp = $backups[0];
				EE::log( 'Restoring from backup: ' . $timestamp );
			}
		}

		$this->rclone_config_path .= '/' . $timestamp;

		return $this->rclone_config_path;
	}


	private function rclone_download( $path ) {
		$cpu_cores     = intval( EE::launch( 'nproc' )->stdout );
		$multi_threads = min( intval( $cpu_cores ) * 2, 32 );
		$command       = sprintf( "rclone copy -P --multi-thread-streams %d %s %s", $multi_threads, $this->get_remote_path( false ), $path );
		$output        = EE::launch( $command );

		if ( $output->return_code ) {
			EE::error( 'Error downloading backup from remote storage.' );
		} else {
			EE::success( "Backup downloaded from remote storage." );
		}
	}


	private function rclone_upload( $path ) {
		$cpu_cores       = intval( EE::launch( 'nproc' )->stdout );
		$ram             = intval( EE::launch( "free -m | grep Mem | awk '{print $7}'" )->stdout );
		$transfers       = max( 2, min( intval( $cpu_cores / 2 ), 4 ) );
		$max_buffer_size = 4096;


		$buffer_size = min( floor( $ram / $transfers ), $max_buffer_size ) . 'M';


		$command = 'rclone config show easyengine | grep type';
		$output  = EE::launch( $command )->stdout;
		$s3_flag = '';

		if ( strpos( $output, 's3' ) !== false ) {
			$s3_flag = ' --s3-chunk-size=64M --s3-upload-concurrency ' . min( intval( $cpu_cores ) * 2, 32 );
		}

		$command = sprintf( "rclone copy -P %s --transfers %d --checkers %d --buffer-size %s %s %s", $s3_flag, $transfers, $transfers, $buffer_size, $path, $this->get_remote_path() );
		$output  = EE::launch( $command );

		if ( $output->return_code ) {
			EE::error( 'Error uploading backup to remote storage.' );
		} else {

			$command     = sprintf( 'rclone lsf %s', $this->get_remote_path( false ) );
			$output      = EE::launch( $command );
			$remote_path = $output->stdout;
			EE::success( 'Backup uploaded to remote storage. Remote path: ' . $remote_path );
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
}
