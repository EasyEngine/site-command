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
	 * @param DnsDataExtractor $extractor
	 * @param OutputInterface $output
	 */
	public function __construct( DnsDataExtractor $extractor = null, OutputInterface $output = null ) {
		$this->extractor = null === $extractor ? new DnsDataExtractor() : $extractor;
		$this->output    = null === $output ? new NullOutput() : $output;
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

		$key     = new \Cloudflare\API\Auth\APIKey( get_config_value( 'le-mail' ), get_config_value( 'cloudflare-api-key' ) );
		$adapter = new \Cloudflare\API\Adapter\Guzzle( $key );
		$zones   = new \Cloudflare\API\Endpoints\Zones( $adapter );

		$zone_guess     = '';
		$possible_zones = [];
		$zone_list      = [];

		foreach ( $zones->listZones()->result as $zone ) {
			$zone_list[] = $zone->name;
		}

		// Guessing zone name using the method in: https://github.com/certbot/certbot/blob/f90561012241171ed8e0dd9996c703c384357eba/certbot/plugins/dns_common.py#L319
		// https://github.com/certbot/certbot/blob/f90561012241171ed8e0dd9996c703c384357eba/certbot-dns-cloudflare/certbot_dns_cloudflare/dns_cloudflare.py#L131

		$guesses = explode( '.', $authorizationChallenge->getDomain() );
		do {
			$possible_zones[] = implode( '.', $guesses );
			array_shift( $guesses );
		} while ( ! empty( $guesses ) );

		foreach ( $possible_zones as $possible_zone ) {
			if ( in_array( $possible_zone, $zone_list, true ) ) {
				$zone_guess = $possible_zone;
				break;
			}
		}

		$manual = empty( $zone_guess ) ? true : false;

		if ( ! $manual ) {
			$zoneID = $zones->getZoneID( $zone_guess );
			$dns    = new \Cloudflare\API\Endpoints\DNS( $adapter );
			if ( $dns->addRecord( $zoneID, "TXT", $recordName, $recordValue, 0, false ) === true ) {
				EE::log( "Created DNS record: $recordName with value $recordValue." . PHP_EOL );
				EE::log( 'Waiting for the changes to propogate.' );
			} else {
				$manual = true;
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
