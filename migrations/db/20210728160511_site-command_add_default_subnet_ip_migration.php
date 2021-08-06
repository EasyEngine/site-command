<?php
namespace EE\Migration;

use EE;
use EE\Model\Site;
use PDOException;

class AddDefaultSubnetIpMigration extends Base {

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

		$sites = Site::all();

		foreach ( $sites as $site ) {
			$site->subnet_ip = EE\Site\Utils\get_subnet_ip();
			$site->save();

			$site_type = $site->site_type === 'html' ? new EE\Site\Type\HTML() :
						( $site->site_type === 'php' ? new EE\Site\Type\PHP() :
						( $site->site_type === 'wp' ? new EE\Site\Type\WordPress()  : EE::error('Unknown site type') ) );

			if ( $site->site_enabled ) {
				$site_type->refresh( [ $site->site_url ], [] );
			}
		}
	}

	/**
	 * Execute drop table query for site and sitemeta table.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		/**
		 * Reset Subnet IP column in table.
		 */
		$query = 'UPDATE sites set subnet_ip=\'\';';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while dropping table: ' . $exception->getMessage(), false );
		}
	}
}
