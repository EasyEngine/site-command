<?php
namespace EE\Migration;

use EE;
use EE\Migration\Base;

class CreateTableSiteMigration extends Base {

	private static $pdo;

	public function __construct() {

		try {
			self::$pdo = new \PDO( 'sqlite:' . DB );
			self::$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		} catch ( \PDOException $exception ) {
			EE::error( $exception->getMessage() );
		}

	}

	public function up() {

		$query = 'CREATE TABLE IF NOT EXISTS sites (
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

		$query .= 'CREATE TABLE site_meta (
			id INTEGER,
			site_id INTEGER NOT NULL,
			meta_key VARCHAR,
			meta_value VARCHAR,
			PRIMARY KEY (id),
			FOREIGN KEY (site_id) REFERENCES sites(id)
		);';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while creating table: ' . $exception->getMessage() );
		}
	}

	public function down() {

		$query = 'DROP TABLE IF EXISTS sites;';

		$query .= 'DROP TABLE IF EXISTS site_meta';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while dropping table: ' . $exception->getMessage() );
		}
	}
}
