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

	/**
	 * Execute create table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		$query = 'CREATE TABLE sites (
			id                   INTEGER  NOT NULL,
			site_url             VARCHAR  NOT NULL,
			site_type            VARCHAR  NOT NULL,
			site_fs_path         VARCHAR  NOT NULL,
			site_container_fs_path VARCHAR NOT NULL,
			site_enabled         BOOLEAN  NOT NULL DEFAULT 1,
			site_ssl             VARCHAR,
			site_ssl_wildcard    BOOLEAN  NOT NULL DEFAULT 0,
			cache_nginx_browser  BOOLEAN  NOT NULL DEFAULT 0,
			cache_nginx_fullpage BOOLEAN  NOT NULL DEFAULT 0,
			cache_mysql_query    BOOLEAN  NOT NULL DEFAULT 0,
			cache_app_object     BOOLEAN  NOT NULL DEFAULT 0,
			cache_host           VARCHAR,
			proxy_cache          VARCHAR NOT NULL DEFAULT \'off\',
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
			alias_domains        VARCHAR,
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

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while creating table: ' . $exception->getMessage(), false );
		}
	}

	/**
	 * Execute drop table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		$query = 'DROP TABLE sites;';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while dropping table: ' . $exception->getMessage(), false );
		}
	}
}
