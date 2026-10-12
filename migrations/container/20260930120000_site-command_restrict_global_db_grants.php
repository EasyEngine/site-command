<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use function EE\Site\Utils\run_global_db_sql;
use function EE\Site\Utils\sql_quote_grant_database;
use function EE\Site\Utils\sql_quote_identifier;
use function EE\Site\Utils\sql_quote_string;
use function EE\Site\Utils\wait_for_global_db;

/**
 * Restricts the database grant of each global-db site to its own database.
 *
 * Sites created before the grant escaped `_` and `%` hold a grant whose database name is a pattern, and a long escaped name was stored cut to 64 characters. This adds the current grant, then revokes those.
 */
class RestrictGlobalDbGrants extends Base {

	/**
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->is_first_execution ) {
			EE::debug( 'Skipping restrict-global-db-grants migration on a fresh install.' );

			return;
		}

		$sites = array_filter(
			Site::all( [ 'site_url', 'db_host', 'db_name', 'db_user' ] ),
			function ( $site ) {
				// A name without pattern characters already matches only itself.
				return GLOBAL_DB === $site->db_host && ! empty( $site->db_user ) && ! empty( $site->db_name ) && false !== strpbrk( $site->db_name, '\\_%' );
			}
		);

		if ( empty( $sites ) ) {
			EE::debug( 'Skipping restrict-global-db-grants migration: no global-db site needs it.' );

			return;
		}

		if ( 'running' !== \EE_DOCKER::container_status( GLOBAL_DB_CONTAINER ) ) {
			foreach ( $sites as $site ) {
				$this->warn( $site, 'the global database is not running' );
			}

			return;
		}

		wait_for_global_db();

		$restricted = 0;
		foreach ( $sites as $site ) {
			if ( $this->restrict_grant( $site ) ) {
				$restricted++;
			}
		}

		if ( $restricted ) {
			EE::log( sprintf( 'Restricted the database grant of %d site(s) to their own database.', $restricted ) );
		}
	}

	/**
	 * Replaces the stale grants of the site's user with the exact one.
	 *
	 * @param Site $site Site row.
	 *
	 * @return bool Whether the grant was changed.
	 */
	private function restrict_grant( $site ) {

		$stale = $this->get_stale_grant_names( $site->db_name );
		if ( empty( $stale ) ) {
			EE::debug( sprintf( 'Site %s: database name %s is too long to narrow its grant.', $site->site_url, $site->db_name ) );

			return false;
		}

		$user_sql = sql_quote_string( $site->db_user );
		// HEX(): `mysql -B` escapes backslashes in its output.
		$state = run_global_db_sql(
			sprintf(
				"SELECT 'user', COUNT(*) FROM mysql.user WHERE User = %1\$s AND Host = '%%'; SELECT 'db', COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %2\$s; SELECT 'grant', HEX(Db) FROM mysql.db WHERE User = %1\$s AND Host = '%%' AND Db IN (%3\$s);",
				$user_sql,
				sql_quote_string( $site->db_name ),
				implode( ', ', array_map( 'EE\\Site\\Utils\\sql_quote_string', $stale ) )
			)
		);
		if ( ! $state || 0 !== $state->return_code || ! preg_match_all( '/^(user|db|grant)\t(\S+)$/m', $state->stdout, $rows, PREG_SET_ORDER ) ) {
			$this->debug_result( $state );
			$this->warn( $site, 'its grants could not be read' );

			return false;
		}

		$has_user = false;
		$has_db   = false;
		$found    = [];
		foreach ( $rows as $row ) {
			if ( 'grant' === $row[1] ) {
				$found[] = hex2bin( $row[2] );
			} elseif ( 'user' === $row[1] ) {
				$has_user = '0' !== $row[2];
			} else {
				$has_db = '0' !== $row[2];
			}
		}

		if ( ! $has_user ) {
			EE::debug( sprintf( 'Site %s: database user %s does not exist, skipping.', $site->site_url, $site->db_user ) );

			return false;
		}
		$found = array_values( array_intersect( $stale, $found ) );
		if ( empty( $found ) ) {
			EE::debug( sprintf( 'Site %s: database grant is already exact.', $site->site_url ) );

			return false;
		}
		if ( ! $has_db ) {
			// The stale grant still matches other databases, so it goes anyway.
			EE::debug( sprintf( 'Site %s: database %s does not exist, restricting its grant anyway.', $site->site_url, $site->db_name ) );
		}

		// One batch: it stops at the first error, so nothing is revoked unless the new GRANT worked.
		$result = run_global_db_sql( $this->get_fix_sql( $site, $found ) );
		if ( ! $result || 0 !== $result->return_code ) {
			$this->debug_result( $result );
			$this->warn( $site, 'the grant could not be changed', $found );

			return false;
		}

		EE::debug( sprintf( 'Site %s: database grant restricted to %s.', $site->site_url, $site->db_name ) );

		return true;
	}

	/**
	 * Database names in grants that earlier versions wrote for this database, other than the current grant.
	 *
	 * @param string $db_name Database name.
	 *
	 * @return string[]
	 */
	private function get_stale_grant_names( $db_name ) {

		// Unescaped, as granted before the pattern characters were escaped.
		$names = [ $db_name ];
		// MariaDB 10.x cut an escaped name longer than 64 characters to 64 when storing it.
		if ( preg_match( '/^(.{64})./su', addcslashes( $db_name, '\\_%' ), $match ) ) {
			$names[] = $match[1];
		}

		$current = sql_quote_grant_database( $db_name );

		return array_values(
			array_filter(
				array_unique( $names ),
				function ( $name ) use ( $current ) {
					return sql_quote_identifier( $name ) !== $current;
				}
			)
		);
	}

	/**
	 * @param Site     $site  Site row.
	 * @param string[] $stale Stale grant names to revoke.
	 *
	 * @return string SQL that replaces the stale grants with the exact one.
	 */
	private function get_fix_sql( $site, array $stale ) {

		$user = sql_quote_string( $site->db_user ) . "@'%'";
		$sql  = sprintf( "GRANT ALL PRIVILEGES ON %s.* TO %s;\n", sql_quote_grant_database( $site->db_name ), $user );
		foreach ( $stale as $name ) {
			$sql .= sprintf( "REVOKE ALL PRIVILEGES ON %s.* FROM %s;\n", sql_quote_identifier( $name ), $user );
		}

		return $sql . 'FLUSH PRIVILEGES;';
	}

	/**
	 * @param Site          $site   Site row.
	 * @param string        $reason Why the grant was not changed.
	 * @param string[]|null $stale  Stale grant names, if known.
	 */
	private function warn( $site, $reason, $stale = null ) {

		$known = null !== $stale;
		EE::warning(
			sprintf(
				"Could not restrict the database grant of site %s to its own database: %s. Run this as root on the global database (for example in `docker exec -it %s sh -c 'mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\"'`)%s:\n%s",
				$site->site_url,
				$reason,
				GLOBAL_DB_CONTAINER,
				$known ? '' : '; a REVOKE that fails with "There is no such grant" can be ignored',
				$this->get_fix_sql( $site, $known ? $stale : $this->get_stale_grant_names( $site->db_name ) )
			)
		);
	}

	/**
	 * @param bool|object $result Result of run_global_db_sql().
	 */
	private function debug_result( $result ) {

		if ( $result ) {
			EE::debug( sprintf( 'Global database returned %d: %s', $result->return_code, trim( $result->stderr ) ) );
		}
	}

	/**
	 * Re-granting the pattern would widen access again, so the exact grant stays.
	 */
	public function down() {
	}
}
