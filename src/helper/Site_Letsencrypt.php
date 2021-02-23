<?php

namespace EE\Site\Type;

use AcmePhp\Cli\Exception\AcmeCliException;
use AcmePhp\Cli\Repository\Repository;
use AcmePhp\Cli\Serializer\PemEncoder;
use AcmePhp\Cli\Serializer\PemNormalizer;
use AcmePhp\Core\AcmeClient;
use AcmePhp\Core\Challenge\ChainValidator;
use AcmePhp\Core\Challenge\Dns\DnsValidator;
use AcmePhp\Core\Challenge\Dns\SimpleDnsSolver;
use AcmePhp\Core\Challenge\Dns\SimpleDnsCloudflareSolver;
use AcmePhp\Core\Challenge\Http\HttpValidator;
use AcmePhp\Core\Challenge\Http\SimpleHttpSolver;
use AcmePhp\Core\Challenge\WaitingValidator;
use AcmePhp\Core\Exception\Protocol\ChallengeNotSupportedException;
use AcmePhp\Core\Exception\Protocol\CertificateRevocationException;
use AcmePhp\Core\Protocol\AuthorizationChallenge;
use AcmePhp\Core\Protocol\ResourcesDirectory;
use AcmePhp\Core\Protocol\RevocationReason;
use AcmePhp\Core\Http\Base64SafeEncoder;
use AcmePhp\Core\Http\SecureHttpClient;
use AcmePhp\Core\Http\ServerErrorHandler;
use AcmePhp\Ssl\CertificateRequest;
use AcmePhp\Ssl\DistinguishedName;
use AcmePhp\Ssl\Generator\KeyPairGenerator;
use AcmePhp\Ssl\Parser\CertificateParser;
use AcmePhp\Ssl\Parser\KeyParser;
use AcmePhp\Ssl\Signer\CertificateRequestSigner;
use AcmePhp\Ssl\Signer\DataSigner;
use GuzzleHttp\Client;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Adapter\NullAdapter;
use League\Flysystem\Filesystem;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;
use function EE\Site\Utils\reload_global_nginx_proxy;
use function EE\Utils\get_config_value;

//TODO: Try to get this code merged in upstream
class EEAcmeClient extends AcmeClient {

	/**
	 * @var string
	 */
	private $account;

	/**
	 * Retrieve the resource account.
	 *
	 * @return string
	 */
	private function getResourceAccount()
	{
		if (!$this->account) {
			$payload = [
				'onlyReturnExisting' => true,
			];

			$this->requestResource('POST', ResourcesDirectory::NEW_ACCOUNT, $payload);
			$this->account = $this->getHttpClient()->getLastLocation();
		}

		return $this->account;
	}

	public function revokeAuthorizationChallenge(AuthorizationChallenge $challenge)
	{
		$payload = [
			'identifiers' => [[
						'type' => 'dns',
						'value' => $challenge->getDomain(),
				]]
		];

		$client = $this->getHttpClient();
		$resourceUrl = $this->getResourceUrl(ResourcesDirectory::NEW_ORDER);
		$response = $client->request('POST', $resourceUrl, $client->signKidPayload($resourceUrl, $this->getResourceAccount(), $payload));
		if (!isset($response['authorizations']) || !$response['authorizations']) {
			throw new ChallengeNotSupportedException();
		}

		$orderEndpoint = $client->getLastLocation();
		foreach ($response['authorizations'] as $authorizationEndpoint) {
			$authorizationsResponse = $client->request('POST', $authorizationEndpoint, $client->signKidPayload($authorizationEndpoint, $this->getResourceAccount(), [ 'status' => 'deactivated' ]));
		}
		return;
	}
}


class Site_Letsencrypt {

	private $accountKeyPair;
	private $httpClient;
	private $base64SafeEncoder;
	private $keyParser;
	private $dataSigner;
	private $serverErrorHandler;
	private $serializer;
	private $master;
	private $backup;
	private $client;
	private $repository;
	private $conf_dir;

	function __construct() {
		$this->conf_dir = EE_ROOT_DIR . '/services/nginx-proxy/acme-conf';
		$this->setRepository();
		$this->setAcmeClient();
	}

	private function setAcmeClient() {

		if ( ! $this->repository->hasAccountKeyPair() ) {
			\EE::debug( 'No account key pair was found, generating one.' );
			\EE::debug( 'Generating a key pair' );

			$keygen         = new KeyPairGenerator();
			$accountKeyPair = $keygen->generateKeyPair();
			\EE::debug( 'Key pair generated, storing' );
			$this->repository->storeAccountKeyPair( $accountKeyPair );
		} else {
			\EE::debug( 'Loading account keypair' );
			$accountKeyPair = $this->repository->loadAccountKeyPair();
		}

		$this->accountKeyPair ?? $this->accountKeyPair = $accountKeyPair;

		$secureHttpClient = $this->getSecureHttpClient();
		$csrSigner        = new CertificateRequestSigner();

		$this->client = new EEAcmeClient( $secureHttpClient, 'https://acme-v02.api.letsencrypt.org/directory', $csrSigner );

	}

	private function setRepository( $enable_backup = false ) {
		$this->serializer ?? $this->serializer = new Serializer(
			[ new PemNormalizer(), new GetSetMethodNormalizer() ],
			[ new PemEncoder(), new JsonEncoder() ]
		);
		$this->master ?? $this->master = new Filesystem( new Local( $this->conf_dir ) );
		$this->backup ?? $this->backup = new Filesystem( new NullAdapter() );

		$this->repository = new Repository( $this->serializer, $this->master, $this->backup, $enable_backup );
	}

	private function getSecureHttpClient() {
		$this->httpClient ?? $this->httpClient = new Client();
		$this->base64SafeEncoder ?? $this->base64SafeEncoder = new Base64SafeEncoder();
		$this->keyParser ?? $this->keyParser = new KeyParser();
		$this->dataSigner ?? $this->dataSigner = new DataSigner();
		$this->serverErrorHandler ?? $this->serverErrorHandler = new ServerErrorHandler();

		return new SecureHttpClient(
			$this->accountKeyPair,
			$this->httpClient,
			$this->base64SafeEncoder,
			$this->keyParser,
			$this->dataSigner,
			$this->serverErrorHandler
		);
	}

	/**
	 * Function to register mail to letsencrypt.
	 *
	 * @param string $email Mail id to be registered.
	 *
	 * @throws \Exception
	 * @return bool Success.
	 */
	public function register( $email ) {
		try {
			$this->client->registerAccount( null, $email );
		} catch ( \Exception $e ) {
			\EE::warning( 'It seems you\'re in local environment or used invalid email or there is some issue with network, please check logs. Skipping letsencrypt.' );
			throw  $e;
		}
		\EE::debug( "Account with email id: $email registered successfully!" );

		return true;
	}

	/**
	 * Function to authorize the letsencrypt request and get the token for challenge.
	 *
	 * @param array $domains Domains to be authorized.
	 * @param bool $wildcard Is the authorization for wildcard or not.
	 *
	 * @throws \Exception
	 * @return bool Success.
	 */
	public function authorize( Array $domains, $wildcard = false, $preferred_challenge = '' ) {
		$is_solver_dns = ( $wildcard || 'dns' === $preferred_challenge ) ? true : false;
		if ( $is_solver_dns ) {
			$solver = empty ( get_config_value( 'cloudflare-api-key' ) ) ? new SimpleDnsSolver( null, new ConsoleOutput() ) : new SimpleDnsCloudflareSolver( null, new ConsoleOutput() );
		} else {
			$solver = new SimpleHttpSolver();
		}
		$solverName = $is_solver_dns ? 'dns-01' : 'http-01';
		try {
			$order = $this->client->requestOrder( $domains );
		} catch ( \Exception $e ) {
			\EE::warning( 'It seems you\'re in local environment or using non-public domain, please check logs. Skipping letsencrypt.' );
			throw $e;
		}

		$authorizationChallengesToSolve = [];
		foreach ( $order->getAuthorizationsChallenges() as $domainKey => $authorizationChallenges ) {
			$authorizationChallenge = null;
			foreach ( $authorizationChallenges as $candidate ) {
				if ( 'valid' === $candidate->getStatus() ) {
					\EE::debug( 'Authorization challenge already solved. Challenge: ' . print_r( $candidate, true ) );
					continue 2;
				}
				if ( $solver->supports( $candidate ) ) {
					$authorizationChallenge = $candidate;
					\EE::debug( 'Authorization challenge supported by solver. Solver: ' . $solverName . ' Challenge: ' . $candidate->getType() );
					break;
				}
				// Should not get here as we are handling it.
				\EE::debug( 'Authorization challenge not supported by solver. Solver: ' . $solverName . ' Challenge: ' . $candidate->getType() );
				\EE::debug( print_r( $candidate, true ) );
			}
			if ( null === $authorizationChallenge ) {
				throw new ChallengeNotSupportedException();
			}
			\EE::debug( 'Storing authorization challenge. Domain: ' . $domainKey . ' Challenge: ' . print_r( $authorizationChallenge->toArray(), true ) );

			$this->repository->storeDomainAuthorizationChallenge( $domainKey, $authorizationChallenge );
			$authorizationChallengesToSolve[] = $authorizationChallenge;
		}

		/** @var AuthorizationChallenge $authorizationChallenge */
		foreach ( $authorizationChallengesToSolve as $authorizationChallenge ) {
			\EE::debug( 'Solving authorization challenge: Domain: ' . $authorizationChallenge->getDomain() . ' Challenge: ' . print_r( $authorizationChallenge->toArray(), true ) );
			$solver->solve( $authorizationChallenge );

			if ( ! $is_solver_dns ) {
				$token   = $authorizationChallenge->toArray()['token'];
				$payload = $authorizationChallenge->toArray()['payload'];

				$fs = new \Symfony\Component\Filesystem\Filesystem();
				$fs->copy( SITE_TEMPLATE_ROOT . '/vhost.d_default_letsencrypt.mustache', EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/default' );
				$challange_dir = EE_ROOT_DIR . '/services/nginx-proxy/html/.well-known/acme-challenge';
				if ( ! $fs->exists( $challange_dir ) ) {
					$fs->mkdir( $challange_dir );
				}
				$challange_file = $challange_dir . '/' . $token;
				\EE::debug( 'Creating challange file ' . $challange_file );
				$fs->dumpFile( $challange_file, $payload );
				reload_global_nginx_proxy();
			}
		}

		$this->repository->storeCertificateOrder( $domains, $order );

		return true;
	}

	public function revokeAuthorizationChallenges( array $domains ) {
		foreach ( $domains as $domain ) {
			if ( $this->repository->hasDomainAuthorizationChallenge( $domain ) ) {
				$challenge = $this->repository->loadDomainAuthorizationChallenge( $domain );

				try {
					$this->client->revokeAuthorizationChallenge( $challenge );
					$this->repository->removeDomainAuthorizationChallenge( $domain );
					\EE::debug( 'Domain Authorization Challenge for ' . $domain . ' revoked successfully' );
				} catch ( CertificateRevocationException | AcmeCliException $e ) {
					\EE::debug( $e->getMessage() );
				}
			} else {
				\EE::debug( 'Domain Authorization Challenge for ' . $domain . ' not found locally' );
			}
		}
	}

	public function revokeCertificates( array $domains ) {
		$reasonCode = null; // ok to be null. LE expects 0 as default reason.

		try {
			$revocationReason = isset( $reasonCode[0] ) ? new RevocationReason( $reasonCode[0] ) : RevocationReason::createDefaultReason();
		} catch ( \InvalidArgumentException $e ) {
			\EE::error( 'Reason code must be one of: ' . PHP_EOL . implode( PHP_EOL, RevocationReason::getFormattedReasons() ) );
		}

		foreach ( $domains as $domain ) {
			if ( gettype( $domain ) === 'string' ) {
				if ( $this->repository->hasDomainCertificate( $domain ) ) {
					$certificate = $this->repository->loadDomainCertificate( $domain );
				} else {
					\EE::debug( 'Certificate for ' . $domain . ' not found locally' );
					continue;
				}
			} elseif ( get_class( $domain ) === 'AcmePhp\Ssl\Certificate' ) {
				$certificate = $domain;
			} else {
				\EE::error( 'Unknown type of certificate ' . get_class( $domain ) );
			}

			try {
				$this->client->revokeCertificate( $certificate, $revocationReason );
				$domain = ( gettype( $domain ) === 'string' ? $domain : get_class( $domain ) ) === 'AcmePhp\Ssl\Certificate' ? array_search( $domain, $domains ) : '';
				\EE::debug( 'Certificate for ' . $domain . ' revoked successfully' );
			} catch ( CertificateRevocationException $e ) {
				\EE::debug( $e->getMessage() );
			}
		}
	}

	public function removeDomain( array $domains ) {
		try {
			$this->repository->removeDomain( $domains );
		} catch ( AcmeCliException $e ) {
			\EE::debug( $e->getMessage() );
		}
	}

	public function loadDomainCertificates( array $domains ) {
		$certificates = [];

		foreach ( $domains as $domain ) {
			if ( $this->repository->hasDomainCertificate( $domain ) ) {
				$certificates[ $domain ] = $this->repository->loadDomainCertificate( $domain );
			}
		}

		return $certificates;
	}

	public function check( Array $domains, $wildcard = false, $preferred_challenge = '' ) {
		$is_solver_dns = ( $wildcard || 'dns' === $preferred_challenge ) ? true : false;
		\EE::debug( ( 'Starting check with solver ' ) . ( $is_solver_dns ? 'dns' : 'http' ) );
		if ( $is_solver_dns ) {
			$solver = empty ( get_config_value( 'cloudflare-api-key' ) ) ? new SimpleDnsSolver( null, new ConsoleOutput() ) : new SimpleDnsCloudflareSolver( null, new ConsoleOutput() );
		} else {
			$solver = new SimpleHttpSolver();
		}
		$validator = new ChainValidator(
			[
				new WaitingValidator( new HttpValidator() ),
				new WaitingValidator( new DnsValidator() )
			]
		);

		$order = null;
		if ( $this->repository->hasCertificateOrder( $domains ) ) {
			$order = $this->repository->loadCertificateOrder( $domains );
			\EE::debug( sprintf( 'Loading the authorization token for domains %s ...', implode( ', ', $domains ) ) );
		}

		$authorizationChallengeToCleanup = [];
		foreach ( $domains as $domain ) {
			if ( $order ) {
				$authorizationChallenge  = null;
				$authorizationChallenges = $order->getAuthorizationChallenges( $domain );
				foreach ( $authorizationChallenges as $challenge ) {
					if ( $solver->supports( $challenge ) ) {
						$authorizationChallenge = $challenge;
						break;
					}
				}
				if ( null === $authorizationChallenge ) {
					throw new ChallengeNotSupportedException();
				}
			} else {
				if ( ! $this->repository->hasDomainAuthorizationChallenge( $domain ) ) {
					\EE::error( "Domain: $domain not yet authorized/has not been started of with EasyEngine letsencrypt site creation." );
				}
				$authorizationChallenge = $this->repository->loadDomainAuthorizationChallenge( $domain );
				if ( ! $solver->supports( $authorizationChallenge ) ) {
					throw new ChallengeNotSupportedException();
				}
			}
			\EE::debug( 'Challenge loaded.' );

			$authorizationChallenge = $this->client->reloadAuthorization( $authorizationChallenge );
			if ( ! $authorizationChallenge->isValid() ) {
				\EE::debug( sprintf( 'Testing the challenge for domain %s', $domain ) );
				if ( ! $validator->isValid( $authorizationChallenge ) ) {
					throw new \Exception( sprintf( 'Can not validate challenge for domain %s', $domain ) );
				}

				\EE::debug( sprintf( 'Requesting authorization check for domain %s', $domain ) );
				try {
					$this->client->challengeAuthorization( $authorizationChallenge );
				} catch ( \Exception $e ) {
					\EE::debug( $e->getMessage() );
					\EE::warning( 'Challenge Authorization failed. Check logs and check if your domain is pointed correctly to this server.' );

					$site_name = $domains[0];
					$site_name = str_replace( '*.', '', $site_name );

					\EE::log( "Re-run `ee site ssl-verify $site_name` after fixing the issue." );
					throw $e;
				}
				$authorizationChallengeToCleanup[] = $authorizationChallenge;
			}
		}

		\EE::log( 'The authorization check was successful!' );

		if ( $solver instanceof MultipleChallengesSolverInterface ) {
			$solver->cleanupAll( $authorizationChallengeToCleanup );
		} else {
			/** @var AuthorizationChallenge $authorizationChallenge */
			foreach ( $authorizationChallengeToCleanup as $authorizationChallenge ) {
				$solver->cleanup( $authorizationChallenge );
			}
		}

		return true;
	}

	public function request( $domain, $altNames = [], $email, $force = false ) {
		$alternativeNames = array_unique( $altNames );
		sort( $alternativeNames );

		// Certificate renewal
		if ( $this->hasValidCertificate( $domain, $alternativeNames ) ) {
			\EE::debug( "Certificate found for $domain, executing renewal" );

			return $this->executeRenewal( $domain, $alternativeNames, $force );
		}

		\EE::debug( "No certificate found, executing first request for $domain" );

		// Certificate first request
		return $this->executeFirstRequest( $domain, $alternativeNames, $email );
	}

	/**
	 * Request a first certificate for the given domain.
	 *
	 * @param string $domain
	 * @param array $alternativeNames
	 */
	private function executeFirstRequest( $domain, array $alternativeNames, $email ) {
		\EE::log( 'Executing first request.' );

		// Generate domain key pair
		$keygen        = new KeyPairGenerator();
		$domainKeyPair = $keygen->generateKeyPair();
		$this->repository->storeDomainKeyPair( $domain, $domainKeyPair );

		\EE::debug( "$domain Domain key pair generated and stored" );

		$distinguishedName = $this->getOrCreateDistinguishedName( $domain, $alternativeNames, $email );
		// TODO: ask them ;)
		\EE::debug( 'Distinguished name informations have been stored locally for this domain (they won\'t be asked on renewal).' );

		// Order
		$domains = array_merge( [ $domain ], $alternativeNames );
		\EE::debug( sprintf( 'Loading the order related to the domains %s .', implode( ', ', $domains ) ) );
		if ( ! $this->repository->hasCertificateOrder( $domains ) ) {
			\EE::error( "$domain has not yet been authorized." );
		}
		$order = $this->repository->loadCertificateOrder( $domains );

		// Request
		\EE::log( sprintf( 'Requesting first certificate for domain %s.', $domain ) );
		$csr      = new CertificateRequest( $distinguishedName, $domainKeyPair );
		$response = $this->client->finalizeOrder( $order, $csr );
		\EE::log( 'Certificate received' );

		// Store
		$this->repository->storeDomainCertificate( $domain, $response->getCertificate() );
		\EE::log( 'Certificate stored' );

		// Post-generate actions
		$this->moveCertsToNginxProxy( $domain );
	}

	private function moveCertsToNginxProxy( string $domain ) {

		$key_source_file   = strtr( $this->conf_dir . '/' . Repository::PATH_DOMAIN_KEY_PRIVATE, [ '{domain}' => $domain ] );
		$crt_source_file   = strtr( $this->conf_dir . '/' . Repository::PATH_DOMAIN_CERT_FULLCHAIN, [ '{domain}' => $domain ] );
		$chain_source_file = strtr( $this->conf_dir . '/' . Repository::PATH_DOMAIN_CERT_CHAIN, [ '{domain}' => $domain ] );

		$key_dest_file   = EE_ROOT_DIR . '/services/nginx-proxy/certs/' . $domain . '.key';
		$crt_dest_file   = EE_ROOT_DIR . '/services/nginx-proxy/certs/' . $domain . '.crt';
		$chain_dest_file = EE_ROOT_DIR . '/services/nginx-proxy/certs/' . $domain . '.chain.pem';

		copy( $key_source_file, $key_dest_file );
		copy( $crt_source_file, $crt_dest_file );
		copy( $chain_source_file, $chain_dest_file );
	}

	/**
	 * Check expiry if a certificate is already expired.
	 *
	 * @param string $domain
	 */
	public function isAlreadyExpired( $domain ) {

		try {
			// Check expiration date to avoid too much renewal
			\EE::log( "Loading current certificate for $domain" );

			$certificate       = $this->repository->loadDomainCertificate( $domain );
			$certificateParser = new CertificateParser();
			$parsedCertificate = $certificateParser->parse( $certificate );

			if ( $parsedCertificate->getValidTo()->format( 'U' ) - time() < 0 ) {
				\EE::log(
					sprintf(
						'Current certificate is alerady expired on %s, renewal is necessary.',
						$parsedCertificate->getValidTo()->format( 'Y-m-d H:i:s' )
					)
				);

				return true;
			}
		} catch ( \Exception $e ) {
			\EE::warning( $e->getMessage() );
		}

		return false;
	}

	/**
	 * Check expiry of a certificate.
	 *
	 * @param string $domain
	 */
	public function isRenewalNecessary( $domain ) {

		// Check expiration date to avoid too much renewal
		\EE::log( "Loading current certificate for $domain" );

		$certificate       = $this->repository->loadDomainCertificate( $domain );
		$certificateParser = new CertificateParser();
		$parsedCertificate = $certificateParser->parse( $certificate );

		// 3024000 = 35 days.
		if ( $parsedCertificate->getValidTo()->format( 'U' ) - time() >= 3024000 ) {
			\EE::log(
				sprintf(
					'Current certificate is valid until %s, renewal is not necessary.',
					$parsedCertificate->getValidTo()->format( 'Y-m-d H:i:s' )
				)
			);

			return false;
		}

		return true;
	}

	/**
	 * Renew a given domain certificate.
	 *
	 * @param string $domain
	 * @param array $alternativeNames
	 * @param bool $force
	 */
	private function executeRenewal( $domain, array $alternativeNames, $force = false ) {
		try {
			// Check expiration date to avoid too much renewal
			\EE::log( "Loading current certificate for $domain" );

			$certificate = $this->repository->loadDomainCertificate( $domain );

			if ( ! $force ) {
				$certificateParser = new CertificateParser();
				$parsedCertificate = $certificateParser->parse( $certificate );

				// 3024000 = 35 days.
				if ( $parsedCertificate->getValidTo()->format( 'U' ) - time() >= 3024000 ) {

					\EE::log(
						sprintf(
							'Current certificate is valid until %s, renewal is not necessary.',
							$parsedCertificate->getValidTo()->format( 'Y-m-d H:i:s' )
						)
					);

					return;
				}

				\EE::log(
					sprintf(
						'Current certificate will expire in less than 25 days (%s), renewal is required.',
						$parsedCertificate->getValidTo()->format( 'Y-m-d H:i:s' )
					)
				);
			} else {
				\EE::log( 'Forced renewal.' );
			}

			// Key pair
			\EE::debug( 'Loading domain key pair...' );
			$domainKeyPair = $this->repository->loadDomainKeyPair( $domain );

			// Distinguished name
			\EE::debug( 'Loading domain distinguished name...' );
			$distinguishedName = $this->getOrCreateDistinguishedName( $domain, $alternativeNames, \EE\Utils\get_config_value( 'le-mail' ) );

			// Order
			$domains = array_merge( [ $domain ], $alternativeNames );
			\EE::debug( sprintf( 'Loading the order related to the domains %s.', implode( ', ', $domains ) ) );
			if ( ! $this->repository->hasCertificateOrder( $domains ) ) {
				\EE::error( "$domain has not yet been authorized." );
			}
			$order = $this->repository->loadCertificateOrder( $domains );

			// Renewal
			\EE::log( sprintf( 'Renewing certificate for domain %s.', $domain ) );
			$csr      = new CertificateRequest( $distinguishedName, $domainKeyPair );
			$response = $this->client->finalizeOrder( $order, $csr );
			\EE::log( 'Certificate received' );

			$this->repository->storeDomainCertificate( $domain, $response->getCertificate() );
			\EE::log( 'Certificate stored' );

			// Post-generate actions
			$this->moveCertsToNginxProxy( $domain );
			\EE::log( 'Certificate renewed successfully!' );

		} catch ( \Exception $e ) {
			\EE::warning( 'A critical error occured during certificate renewal' );
			\EE::debug( print_r( $e, true ) );

			throw $e;
		} catch ( \Throwable $e ) {
			\EE::warning( 'A critical error occured during certificate renewal' );
			\EE::debug( print_r( $e, true ) );

			throw $e;
		}
	}

	private function hasValidCertificate( $domain, array $alternativeNames ) {
		if ( ! $this->repository->hasDomainCertificate( $domain ) ) {
			return false;
		}

		if ( ! $this->repository->hasDomainKeyPair( $domain ) ) {
			return false;
		}

		if ( ! $this->repository->hasDomainDistinguishedName( $domain ) ) {
			return false;
		}

		if ( $this->repository->loadDomainDistinguishedName( $domain )->getSubjectAlternativeNames() !== $alternativeNames ) {
			return false;
		}

		return true;
	}

	/**
	 * Retrieve the stored distinguishedName or create a new one if needed.
	 *
	 * @param string $domain
	 * @param array $alternativeNames
	 *
	 * @return DistinguishedName
	 */
	private function getOrCreateDistinguishedName( $domain, array $alternativeNames, $email ) {
		if ( $this->repository->hasDomainDistinguishedName( $domain ) ) {
			$original = $this->repository->loadDomainDistinguishedName( $domain );

			$distinguishedName = new DistinguishedName(
				$domain,
				$original->getCountryName(),
				$original->getStateOrProvinceName(),
				$original->getLocalityName(),
				$original->getOrganizationName(),
				$original->getOrganizationalUnitName(),
				$original->getEmailAddress(),
				$alternativeNames
			);
		} else {
			// Ask DistinguishedName
			$countryName = ( ! empty( \EE\Utils\get_config_value( 'le-country' ) ) ) ? \EE\Utils\get_config_value( 'le-country' ) : 'US';
			$StateOrProvinceName = ( ! empty( \EE\Utils\get_config_value( 'le-state' ) ) ) ? \EE\Utils\get_config_value( 'le-state' ) : 'CA';
			$LocalityName = ( ! empty( \EE\Utils\get_config_value( 'le-locality' ) ) ) ? \EE\Utils\get_config_value( 'le-locality' ) : 'Mountain View';
			$OrganizationName = ( ! empty( \EE\Utils\get_config_value( 'le-orgname' ) ) ) ? \EE\Utils\get_config_value( 'le-orgname' ) : 'Let\'s Encrypt';
			$OrganizationalUnitName = ( ! empty( \EE\Utils\get_config_value( 'le-orgunit' ) ) ) ? \EE\Utils\get_config_value( 'le-orgunit' ) : 'Let\'s Encrypt Authority X3';
			$distinguishedName = new DistinguishedName(
				$domain,
				$countryName,
				$StateOrProvinceName,
				$LocalityName,
				$OrganizationName,
				$OrganizationalUnitName,
				$email,
				$alternativeNames
			);

		}

		$this->repository->storeDomainDistinguishedName( $domain, $distinguishedName );

		return $distinguishedName;
	}

	/**
	 * Cleanup created challenge files and specific rule sets for it.
	 */
	public function cleanup() {

		$fs = new \Symfony\Component\Filesystem\Filesystem();

		$challange_dir = EE_ROOT_DIR . '/services/nginx-proxy/html/.well-known';
		$challange_rule_file = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/default';
		if ( $fs->exists( $challange_rule_file ) ) {
			$fs->remove( $challange_rule_file );
		}
		if ( $fs->exists( $challange_dir ) ) {
			$fs->remove( $challange_dir );
		}
	}
}

