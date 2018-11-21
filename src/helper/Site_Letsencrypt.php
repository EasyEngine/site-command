<?php

namespace EE\Site\Type;

use AcmePhp\Cli\Repository\Repository;
use AcmePhp\Cli\Serializer\PemEncoder;
use AcmePhp\Cli\Serializer\PemNormalizer;
use AcmePhp\Core\AcmeClient;
use AcmePhp\Core\Challenge\ChainValidator;
use AcmePhp\Core\Challenge\Dns\DnsValidator;
use AcmePhp\Core\Challenge\Dns\SimpleDnsSolver;
use AcmePhp\Core\Challenge\Http\HttpValidator;
use AcmePhp\Core\Challenge\Http\SimpleHttpSolver;
use AcmePhp\Core\Challenge\WaitingValidator;
use AcmePhp\Core\Exception\Protocol\ChallengeNotSupportedException;
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

		$this->client = new AcmeClient( $secureHttpClient, 'https://acme-v02.api.letsencrypt.org/directory', $csrSigner );

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
		$solver        = $is_solver_dns ? new SimpleDnsSolver( null, new ConsoleOutput() ) : new SimpleHttpSolver();
		$solverName    = $is_solver_dns ? 'dns-01' : 'http-01';
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
				if ( $solver->supports( $candidate ) ) {
					$authorizationChallenge = $candidate;
					\EE::debug( 'Authorization challenge supported by solver. Solver: ' . $solverName . ' Challenge: ' . $candidate->getType() );
					break;
				}
				// Should not get here as we are handling it.
				\EE::debug( 'Authorization challenge not supported by solver. Solver: ' . $solverName . ' Challenge: ' . $candidate->getType() );
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

	public function check( Array $domains, $wildcard = false, $preferred_challenge = '' ) {
		$is_solver_dns = ( $wildcard || 'dns' === $preferred_challenge ) ? true : false;
		\EE::debug( ( 'Starting check with solver ' ) . ( $is_solver_dns ? 'dns' : 'http' ) );
		$solver    = $is_solver_dns ? new SimpleDnsSolver( null, new ConsoleOutput() ) : new SimpleHttpSolver();
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

					$site_name = isset( $domains[1] ) ? $domains[1] : $domains[0];
					$site_name = str_replace( '*.', '', $site_name, 1 );
					
					\EE::log( "Re-run `ee site ssl $site_name` after fixing the issue." );
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

				if ( $parsedCertificate->getValidTo()->format( 'U' ) - time() >= 604800 ) {

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
						'Current certificate will expire in less than a week (%s), renewal is required.',
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
			$distinguishedName = new DistinguishedName(
				$domain,
				// TODO: Ask and fill these values properly
				'US',
				'CA',
				'Mountain View',
				'Let\'s Encrypt',
				'Let\'s Encrypt Authority X3',
				$email,
				$alternativeNames
			);

		}

		$this->repository->storeDomainDistinguishedName( $domain, $distinguishedName );

		return $distinguishedName;
	}


	public function status() {
		$this->master ?? $this->master = new Filesystem( new Local( $this->conf_dir ) );

		$certificateParser = new CertificateParser();

		$table = new Table( $output );
		$table->setHeaders( [ 'Domain', 'Issuer', 'Valid from', 'Valid to', 'Needs renewal?' ] );

		$directories = $this->master->listContents( 'certs' );

		foreach ( $directories as $directory ) {
			if ( 'dir' !== $directory['type'] ) {
				continue;
			}

			$parsedCertificate = $certificateParser->parse( $this->repository->loadDomainCertificate( $directory['basename'] ) );
			if ( ! $input->getOption( 'all' ) && $parsedCertificate->isExpired() ) {
				continue;
			}
			$domainString = $parsedCertificate->getSubject();

			$alternativeNames = array_diff( $parsedCertificate->getSubjectAlternativeNames(), [ $parsedCertificate->getSubject() ] );
			if ( count( $alternativeNames ) ) {
				sort( $alternativeNames );
				$last = array_pop( $alternativeNames );
				foreach ( $alternativeNames as $alternativeName ) {
					$domainString .= "\n ├── " . $alternativeName;
				}
				$domainString .= "\n └── " . $last;
			}

			$table->addRow(
				[
					$domainString,
					$parsedCertificate->getIssuer(),
					$parsedCertificate->getValidFrom()->format( 'Y-m-d H:i:s' ),
					$parsedCertificate->getValidTo()->format( 'Y-m-d H:i:s' ),
					( $parsedCertificate->getValidTo()->format( 'U' ) - time() < 604800 ) ? '<comment>Yes</comment>' : 'No',
				]
			);
		}

		$table->render();
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
