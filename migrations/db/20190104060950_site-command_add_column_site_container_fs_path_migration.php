<?php
namespace EE\Migration;

use EE;
use EE\Migration\Base;

class AddColumnSiteContainerFsPathMigration extends Base {

	private static $pdo;

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;
		}

		try {
			self::$pdo = new \PDO( 'sqlite:' . DB );
			self::$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		} catch ( \PDOException $exception ) {
			EE::error( $exception->getMessage() );
		}

	}

	/**
	 * Execute create table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			return;
		}

		$query = 'ALTER TABLE sites ADD COLUMN site_container_fs_path VARCHAR NOT NULL DEFAULT \'/var/www/htdocs\';';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while adding column: ' . $exception->getMessage(), false );
		}
	}

	/**
	 * Execute drop table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		$query = 'PRAGMA foreign_keys=off;';

		/**
		 * Rename old site table.
		 */
		$query .= 'ALTER TABLE sites RENAME TO sites_backup;';

		/**
		 * Create new site table without 'site_container_fs_path' column.
		 */
		$query .= 'CREATE TABLE sites (
			id                   INTEGER  NOT NULL,
			site_url             VARCHAR  NOT NULL,
			site_type            VARCHAR  NOT NULL,
			site_fs_path         VARCHAR  NOT NULL,
			site_enabled         BOOLEAN  NOT NULL DEFAULT 1,
			site_ssl             VARCHAR,
			site_ssl_wildcard    BOOLEAN  NOT NULL DEFAULT 0,
			cache_nginx_browser  BOOLEAN  NOT NULL DEFAULT 0,
			cache_nginx_fullpage BOOLEAN  NOT NULL DEFAULT 0,
			cache_mysql_query    BOOLEAN  NOT NULL DEFAULT 0,
			cache_app_object     BOOLEAN  NOT NULL DEFAULT 0,
			cache_host           VARCHAR,
			php_version          VARCHAR,
			db_name              VARCHAR,
			db_user              VARCHAR,
			db_password          VARCHAR,
			db_root_password     VARCHAR,
			db_host              VARCHAR,
			db_port              VARCHAR,
			app_sub_type         VARCHAR,
			app_admin_url        VARCHAR,
			app_admin_email      VARCHAR,
			app_admin_username   VARCHAR,
			app_admin_password   VARCHAR,
			app_mail             VARCHAR,
			mailhog_enabled      BOOLEAN  NOT NULL DEFAULT 0,
			admin_tools          BOOLEAN  NOT NULL DEFAULT 0,
			created_on           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			modified_on          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			site_auth_scope      VARCHAR,
			site_auth_username   VARCHAR,
			site_auth_password   VARCHAR,
			PRIMARY KEY (id),
			UNIQUE (site_url),
			CHECK (site_enabled IN (0, 1))
		);';

		/**
		 * Insert data from backup site table.
		 */
		$query .= 'INSERT INTO sites (id, site_url, site_type, site_fs_path, site_enabled, site_ssl, site_ssl_wildcard,cache_nginx_browser,
			cache_nginx_fullpage, cache_mysql_query, cache_app_object, cache_host, php_version, db_name, db_user, db_password, db_root_password,
			db_host, db_port,app_sub_type,app_admin_url, app_admin_email, app_admin_password, app_mail, mailhog_enabled,
			admin_tools, created_on, site_auth_scope, site_auth_username, site_auth_password)
			
			SELECT id, site_url, site_type, site_fs_path, site_enabled, site_ssl, site_ssl_wildcard,cache_nginx_browser
			,cache_nginx_fullpage, cache_mysql_query, cache_app_object, cache_host, php_version, db_name, db_user, db_password, db_root_password,
			db_host, db_port,app_sub_type,app_admin_url, app_admin_email, app_admin_password, app_mail, mailhog_enabled,
			admin_tools, created_on, site_auth_scope, site_auth_username, site_auth_password
			FROM sites_backup;';

		/**
		 * Drop site backup table.
		 */
		$query .= 'DROP TABLE sites_backup;';

		$query .= 'PRAGMA foreign_keys=on;';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while dropping table: ' . $exception->getMessage(), false );
		}
	}
}
