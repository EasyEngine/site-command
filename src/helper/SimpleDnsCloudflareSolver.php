<?php

namespace AcmePhp\Core\Challenge\Dns;

use EE;
use AcmePhp\Core\Challenge\SolverInterface;
use AcmePhp\Core\Protocol\AuthorizationChallenge;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use function EE\Utils\get_config_value;

/**
 * ACME DNS solver with cloudflare integration.
 */
class SimpleDnsCloudflareSolver implements SolverInterface {
	/**
	 * @var DnsDataExtractor
	 */
	private $extractor;

	/**
	 * @var OutputInterface
	 */
	protected $output;

	/**
	 * @var \Cloudflare\API\Endpoints\DNS
	 */
	protected $dns;

	/**
	 * @var \Cloudflare\API\Endpoints\Zones
	 */
	protected $zones;

	/**
	 * @param DnsDataExtractor $extractor
	 * @param OutputInterface $output
	 */
	public function __construct( DnsDataExtractor $extractor = null, OutputInterface $output = null ) {
		$this->extractor = null === $extractor ? new DnsDataExtractor() : $extractor;
		$this->output    = null === $output ? new NullOutput() : $output;
		$key             = new \Cloudflare\API\Auth\APIKey( get_config_value( 'le-mail' ), get_config_value( 'cloudflare-api-key' ) );
		$adapter         = new \Cloudflare\API\Adapter\Guzzle( $key );
		$this->dns       = new \Cloudflare\API\Endpoints\DNS( $adapter );
		$this->zones     = new \Cloudflare\API\Endpoints\Zones( $adapter );


	}

	/**
	 * {@inheritdoc}
	 */
	public function supports( AuthorizationChallenge $authorizationChallenge ) {
		return 'dns-01' === $authorizationChallenge->getType();
	}

	/**
	 * {@inheritdoc}
	 */
	public function solve( AuthorizationChallenge $authorizationChallenge ) {
		$recordName  = $this->extractor->getRecordName( $authorizationChallenge );
		$recordValue = $this->extractor->getRecordValue( $authorizationChallenge );

		$zone_guess = $this->get_zone_name( $authorizationChallenge->getDomain() );
		$manual     = empty( $zone_guess ) ? true : false;

		if ( ! $manual ) {
			$zoneID = $this->zones->getZoneID( $zone_guess );

			try {
				if ( $this->dns->addRecord( $zoneID, "TXT", $recordName, $recordValue, 0, false ) === true ) {
					EE::log( "Created DNS record: $recordName with value $recordValue." . PHP_EOL );
				} else {
					$manual = true;
				}
			} catch ( \Exception $e ) {
				EE::warning( $e->getMessage() );
			}
		}

		if ( $manual ) {

			EE::log( "Couldn't add dns record using cloudlfare API. Re-check the config values of `le-mail` and `cloudflare-api-key`." );

			$this->output->writeln(
				sprintf(
					<<<'EOF'
		Add the following TXT record to your DNS zone
			Domain: %s
			TXT value: %s
			
		<comment>Wait for the propagation before moving to the next step</comment>
		Tips: Use the following command to check the propagation
	
			host -t TXT %s	
EOF
					,
					$recordName,
					$recordValue,
					$recordName
				)
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function cleanup( AuthorizationChallenge $authorizationChallenge ) {

		$recordName = $this->extractor->getRecordName( $authorizationChallenge );
		$zone       = $this->get_zone_name( $authorizationChallenge->getDomain() );
		$zoneID     = $this->zones->getZoneID( $zone );
		$record_ids = $this->get_record_id( $authorizationChallenge->getDomain(), $recordName, 'TXT' );

		foreach ( $record_ids as $record_id ) {
			if ( $this->dns->deleteRecord( $zoneID, $record_id ) ) {
				EE::log( "Cleaned up DNS record: _acme-challenge.$recordName" );
			} else {
				$this->output->writeln(
					sprintf(
						<<<'EOF'
		You can now cleanup your DNS by removing the domain <comment>_acme-challenge.%s.</comment>
EOF
						,
						$recordName
					)
				);
			}
		}
	}

	/**
	 * Function to get zone name of clouflare account from the given domain name.
	 * Guessing zone name using the method in:
	 * https://github.com/certbot/certbot/blob/f90561012241171ed8e0dd9996c703c384357eba/certbot/plugins/dns_common.py#L31
	 * https://github.com/certbot/certbot/blob/f90561012241171ed8e0dd9996c703c384357eba/certbot-dns-cloudflare/certbot_dns_cloudflare/dns_cloudflare.py#L131
	 *
	 * @param string $domain domain name.
	 *
	 * @return string found zone name.
	 */
	private function get_zone_name( $domain ) {

		$zone_guess     = '';
		$possible_zones = [];
		$zone_list      = [];

		foreach ( $this->zones->listZones( '', '', 1, 1000 )->result as $zone ) {
			$zone_list[] = $zone->name;
		}

		$guesses = explode( '.', $domain );
		do {
			$possible_zones[] = implode( '.', $guesses );
			array_shift( $guesses );
		} while ( ! empty( $guesses ) );

		foreach ( $possible_zones as $possible_zone ) {
			if ( in_array( $possible_zone, $zone_list, true ) ) {
				$zone_guess = $possible_zone;

				return $zone_guess;
			}
		}

		return $zone_guess;
	}

	/**
	 * Function to get record id of cloudflare for a give record name and type.
	 *
	 * @param string $domain
	 * @param $record_name
	 * @param $record_type
	 *
	 * @return array of found record ids.
	 * @throws \Cloudflare\API\Endpoints\EndpointException
	 */
	private function get_record_id( $domain, $record_name, $record_type ) {

		$zone        = $this->get_zone_name( $domain );
		$zoneID      = $this->zones->getZoneID( $zone );
		$record_id   = [];
		$record_name = rtrim( $record_name, '.' );

		foreach ( $this->dns->listRecords( $zoneID, '', '', '', 1, 1000 )->result as $record ) {
			if ( ( $record->name === $record_name ) && ( $record->type === $record_type ) ) {
				$record_id[] = $record->id;
			}
		}

		return $record_id;
	}
}
