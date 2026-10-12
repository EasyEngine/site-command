<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use function EE\Site\Utils\db_name_fits_exact_grant;
use function EE\Site\Utils\run_global_db_sql;
use function EE\Site\Utils\sql_quote_grant_database;
use function EE\Site\Utils\sql_quote_identifier;
use function EE\Site\Utils\sql_quote_string;
use function EE\Site\Utils\wait_for_global_db;

/**
 * Restricts the database grant of each global-db site to its own database.
 *
 * Sites created before the grant escaped `_` and `%` hold a grant whose database name is a pattern, and a long escaped name was stored cut to 64 characters. This adds the current grant, then revokes those.
 * Grants that differ from the one EasyEngine creates, or that another site of the same user relies on, are left to the admin.
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

		$global_sites = array_filter(
			Site::all( [ 'site_url', 'db_host', 'db_name', 'db_user' ] ),
			function ( $site ) {
				return GLOBAL_DB === $site->db_host && ! empty( $site->db_user ) && ! empty( $site->db_name );
			}
		);
		// A name without pattern characters already matches only itself.
		$sites = array_values(
			array_filter(
				$global_sites,
				function ( $site ) {
					return false !== strpbrk( $site->db_name, '\\_%' );
				}
			)
		);

		if ( empty( $sites ) ) {
			EE::debug( 'Skipping restrict-global-db-grants migration: no global-db site needs it.' );

			return;
		}

		if ( 'running' !== \EE_DOCKER::container_status( GLOBAL_DB_CONTAINER ) ) {
			$this->warn_manual( $sites, 'the global database is not running' );

			return;
		}

		wait_for_global_db();

		$columns = $this->get_privilege_columns();
		$state   = $columns ? $this->read_state( $sites, $columns ) : false;
		if ( false === $state ) {
			$this->warn_manual( $sites, 'their grants could not be read' );

			return;
		}

		$restricted = 0;
		foreach ( $sites as $i => $site ) {
			if ( $this->restrict_grant( $site, $state[ $i ], $global_sites ) ) {
				$restricted++;
			}
		}

		if ( $restricted ) {
			EE::log( sprintf( 'Restricted the database grant of %d site(s) to their own database.', $restricted ) );
		}
	}

	/**
	 * Privilege columns of `mysql.db`, which differ between MariaDB versions.
	 *
	 * @return string[]
	 */
	private function get_privilege_columns() {

		$result = run_global_db_sql( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'mysql' AND TABLE_NAME = 'db' AND COLUMN_NAME LIKE '%\\\\_priv' ORDER BY ORDINAL_POSITION;" );
		if ( ! $result || 0 !== $result->return_code ) {
			$this->debug_result( $result );

			return [];
		}
		$columns = preg_split( '/\s+/', trim( $result->stdout ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! in_array( 'Grant_priv', $columns, true ) || preg_grep( '/^\w+_priv$/', $columns, PREG_GREP_INVERT ) ) {
			return [];
		}

		return $columns;
	}

	/**
	 * Reads what every site needs in one round trip: its users, grant rows and the other databases its old grants match.
	 *
	 * @param Site[]   $sites   Sites to check.
	 * @param string[] $columns Privilege columns of `mysql.db`.
	 *
	 * @return array|false Per site: users, hosts, grants (name => whether it is the grant EasyEngine creates), matches (old name => database names).
	 */
	private function read_state( array $sites, array $columns ) {

		// The GRANT ALL row EasyEngine creates: every privilege except GRANT OPTION.
		$checks = [];
		foreach ( $columns as $column ) {
			$checks[] = sprintf( "%s = '%s'", sql_quote_identifier( $column ), 'Grant_priv' === $column ? 'N' : 'Y' );
		}
		$is_ee_grant = implode( ' AND ', $checks );

		$sql   = '';
		$state = [];
		foreach ( $sites as $i => $site ) {
			$user  = sql_quote_string( $site->db_user );
			$names = array_merge( [ $this->get_grant_name( $site->db_name ) ], $this->get_stale_grant_names( $site->db_name ) );
			// HEX(): `mysql -B` escapes backslashes in its output.
			$sql .= sprintf( "SELECT 'user', %d, COUNT(*), COUNT(IF(Host = '%%', 1, NULL)) FROM mysql.user WHERE User = %s;\n", $i, $user );
			$sql .= sprintf(
				"SELECT 'grant', %d, HEX(Db), IF(%s, 1, 0) FROM mysql.db WHERE User = %s AND Host = '%%' AND Db IN (%s);\n",
				$i,
				$is_ee_grant,
				$user,
				implode( ', ', array_map( 'EE\\Site\\Utils\\sql_quote_string', $names ) )
			);
			// BINARY LIKE matches databases the way a GRANT pattern does (case-sensitive, byte-wise `_`).
			foreach ( $this->get_stale_grant_names( $site->db_name ) as $name ) {
				$sql .= sprintf(
					"SELECT 'match', %d, HEX(%s), HEX(SCHEMA_NAME) FROM information_schema.SCHEMATA WHERE BINARY SCHEMA_NAME LIKE %s AND SCHEMA_NAME <> %s;\n",
					$i,
					sql_quote_string( $name ),
					sql_quote_string( $name ),
					sql_quote_string( $site->db_name )
				);
			}
			$state[ $i ] = [
				'users'   => 0,
				'hosts'   => 0,
				'grants'  => [],
				'matches' => [],
			];
		}

		$result = run_global_db_sql( $sql );
		if ( ! $result || 0 !== $result->return_code ) {
			$this->debug_result( $result );

			return false;
		}

		foreach ( explode( "\n", trim( $result->stdout ) ) as $line ) {
			$row = explode( "\t", $line );
			if ( 4 !== count( $row ) || ! isset( $state[ $row[1] ] ) ) {
				continue;
			}
			$i = (int) $row[1];
			if ( 'user' === $row[0] ) {
				$state[ $i ]['users'] = (int) $row[2];
				$state[ $i ]['hosts'] = (int) $row[3];
			} elseif ( 'grant' === $row[0] ) {
				$state[ $i ]['grants'][ hex2bin( $row[2] ) ] = '1' === $row[3];
			} elseif ( 'match' === $row[0] ) {
				$state[ $i ]['matches'][ hex2bin( $row[2] ) ][] = hex2bin( $row[3] );
			}
		}

		return $state;
	}

	/**
	 * Replaces the stale grants of the site's user with the exact one, where that is safe.
	 *
	 * @param Site   $site         Site row.
	 * @param array  $state        What read_state() found for it.
	 * @param Site[] $global_sites All global-db site rows.
	 *
	 * @return bool Whether the grant was changed.
	 */
	private function restrict_grant( $site, array $state, array $global_sites ) {

		$user = sprintf( "%s@'%%'", sql_quote_string( $site->db_user ) );

		if ( ! $state['users'] ) {
			EE::debug( sprintf( 'Site %s: database user %s does not exist, skipping.', $site->site_url, $site->db_user ) );

			return false;
		}
		if ( ! $state['hosts'] ) {
			EE::warning( sprintf( "Did not restrict the database grant of site %s: its database user %s exists only for hosts other than '%%'. Please check its grants with SHOW GRANTS.", $site->site_url, $site->db_user ) );

			return false;
		}

		$current = $this->get_grant_name( $site->db_name );
		$exact   = db_name_fits_exact_grant( $site->db_name );
		$stale   = array_values( array_intersect( $this->get_stale_grant_names( $site->db_name ), array_keys( $state['grants'] ) ) );

		if ( empty( $stale ) ) {
			if ( ! $exact && isset( $state['grants'][ $current ] ) ) {
				$this->warn_not_exact( $site );
			} else {
				EE::debug( sprintf( 'Site %s: database grant is already exact.', $site->site_url ) );
			}

			return false;
		}

		$custom = array_keys( array_diff( $state['grants'], [ true ] ) );
		if ( $custom ) {
			$steps = '';
			foreach ( $stale as $name ) {
				$steps .= sprintf( "REVOKE ALL PRIVILEGES ON %1\$s.* FROM %2\$s; -- and, if it holds GRANT OPTION: REVOKE GRANT OPTION ON %1\$s.* FROM %2\$s;\n", sql_quote_identifier( $name ), $user );
			}
			EE::warning(
				sprintf(
					"Did not restrict the database grant of site %s: the grants of database user %s on %s differ from the ALL PRIVILEGES grant EasyEngine creates. To restrict them by hand, grant the privileges the site needs on %s, then run as root on the global database:\n%sFLUSH PRIVILEGES;",
					$site->site_url,
					$site->db_user,
					implode( ', ', array_map( 'EE\\Site\\Utils\\sql_quote_identifier', $custom ) ),
					sql_quote_grant_database( $site->db_name ),
					$steps
				)
			);

			return false;
		}

		// Another site of the same user may reach its database only through this grant.
		$others = [];
		foreach ( $global_sites as $other ) {
			if ( $other->db_user === $site->db_user && $other->site_url !== $site->site_url ) {
				$others[ $other->db_name ] = $other->site_url;
			}
		}
		$revoke = [];
		$kept   = [];
		foreach ( $stale as $name ) {
			$matches = isset( $state['matches'][ $name ] ) ? $state['matches'][ $name ] : [];
			$shared  = array_intersect_key( $others, array_flip( $matches ) );
			if ( $shared ) {
				$kept[ $name ] = $shared;
			} else {
				$revoke[] = $name;
			}
		}

		// Nothing to change when the only stale grants are kept and the current one is there already.
		if ( $revoke || ! isset( $state['grants'][ $current ] ) ) {
			$sql = sprintf( "GRANT ALL PRIVILEGES ON %s.* TO %s;\n", sql_quote_grant_database( $site->db_name ), $user );
			foreach ( $revoke as $name ) {
				$sql .= sprintf( "REVOKE ALL PRIVILEGES ON %s.* FROM %s;\n", sql_quote_identifier( $name ), $user );
			}
			// One batch: it stops at the first error, so nothing is revoked unless the new GRANT worked.
			$result = run_global_db_sql( $sql . 'FLUSH PRIVILEGES;' );
			if ( ! $result || 0 !== $result->return_code ) {
				$this->debug_result( $result );
				EE::warning( sprintf( "Could not restrict the database grant of site %s to its own database. Please run this as root on the global database:\n%sFLUSH PRIVILEGES;", $site->site_url, $sql ) );

				return false;
			}
		}

		foreach ( $kept as $name => $shared ) {
			EE::warning(
				sprintf(
					'Kept the database grant of site %1$s on %2$s: it also covers the database of site(s) %3$s, which use the same database user %4$s. Once that user has its own grant on each of those databases, run REVOKE ALL PRIVILEGES ON %2$s.* FROM %5$s.',
					$site->site_url,
					sql_quote_identifier( $name ),
					implode( ', ', $shared ),
					$site->db_user,
					$user
				)
			);
		}
		if ( ! $exact ) {
			$this->warn_not_exact( $site );
		}
		EE::debug( sprintf( 'Site %s: database grant restricted to %s.', $site->site_url, $site->db_name ) );

		return ! empty( $revoke );
	}

	/**
	 * @param string $db_name Database name.
	 *
	 * @return string Database name as the current GRANT writes it.
	 */
	private function get_grant_name( $db_name ) {

		return str_replace( '``', '`', substr( sql_quote_grant_database( $db_name ), 1, -1 ) );
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

		return array_values( array_diff( array_unique( $names ), [ $this->get_grant_name( $db_name ) ] ) );
	}

	/**
	 * @param Site $site Site row.
	 */
	private function warn_not_exact( $site ) {

		EE::warning( sprintf( 'The database grant of site %s may also cover databases whose names differ from %s only in the last characters: the name is too long for a grant on exactly that database.', $site->site_url, $site->db_name ) );
	}

	/**
	 * One warning for sites whose grants could not be checked, with the SQL for the common case.
	 *
	 * @param Site[] $sites  Sites.
	 * @param string $reason Why they were not checked.
	 */
	private function warn_manual( array $sites, $reason ) {

		$sql  = '';
		$urls = [];
		foreach ( $sites as $site ) {
			$urls[] = $site->site_url;
			// Only the unescaped grant of older versions, and only where the new grant differs from it.
			if ( ! in_array( $site->db_name, $this->get_stale_grant_names( $site->db_name ), true ) ) {
				continue;
			}
			$user = sprintf( "%s@'%%'", sql_quote_string( $site->db_user ) );
			$sql .= sprintf( "GRANT ALL PRIVILEGES ON %s.* TO %s; REVOKE ALL PRIVILEGES ON %s.* FROM %s;\n", sql_quote_grant_database( $site->db_name ), $user, sql_quote_identifier( $site->db_name ), $user );
		}

		EE::warning(
			sprintf(
				'Could not restrict the database grants of site(s) %s to their own database: %s.%s',
				implode( ', ', $urls ),
				$reason,
				'' === $sql ? '' : " For each user whose SHOW GRANTS lists the old grant, run as root on the global database:\n" . $sql . 'FLUSH PRIVILEGES;'
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
