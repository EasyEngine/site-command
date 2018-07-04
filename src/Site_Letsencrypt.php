<?php

use AcmePhp\Cli\Repository\Repository;
use AcmePhp\Cli\Serializer\PemEncoder;
use AcmePhp\Cli\Serializer\PemNormalizer;
use AcmePhp\Core\AcmeClient;
use AcmePhp\Core\Challenge\ChainValidator;
use AcmePhp\Core\Challenge\WaitingValidator;
use AcmePhp\Core\Challenge\Http\SimpleHttpSolver;
use AcmePhp\Core\Challenge\Http\HttpValidator;
use AcmePhp\Core\Challenge\Dns\SimpleDnsSolver;
use AcmePhp\Core\Challenge\Dns\DnsValidator;
use AcmePhp\Core\Exception\Protocol\ChallengeNotSupportedException;
use AcmePhp\Core\Http\SecureHttpClient;
use AcmePhp\Core\Http\Base64SafeEncoder;
use AcmePhp\Core\Http\ServerErrorHandler;
use AcmePhp\Ssl\Parser\KeyParser;
use AcmePhp\Ssl\Parser\CertificateParser;
use AcmePhp\Ssl\Generator\KeyPairGenerator;
use AcmePhp\Ssl\Signer\CertificateRequestSigner;
use AcmePhp\Ssl\Signer\DataSigner;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Adapter\NullAdapter;
use GuzzleHttp\Client;


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

	function __construct() {
		$this->setRepository();
		$this->setAcmeClient();
	}

	private function setAcmeClient() {

		if ( ! $this->repository->hasAccountKeyPair() ) {
			EE::debug( 'No account key pair was found, generating one...' );
			EE::debug( 'Generating a key pair' );

			$keygen         = new KeyPairGenerator();
			$accountKeyPair = $keygen->generateKeyPair();
			EE::debug( 'Key pair generated, storing' );
			$this->repository->storeAccountKeyPair( $accountKeyPair );
		} else {
			EE::debug( 'Loading account keypair' );
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
		$this->master ?? $this->master = new Filesystem( new Local( EE_CONF_ROOT . '/le-client-keys' ) );
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


	public function register( $email ) {
		$this->client->registerAccount( null, $email );
		EE::log( "Account with email id: $email registered successfully!" );
	}

	public function check( Array $domains, $wildcard = false ) {
		EE::debug( 'Starting check with solver ' . $wildcard ? 'dns' : 'http' );
		$solver    = $wildcard ? new SimpleDnsSolver() : new SimpleHttpSolver();
		$validator = new ChainValidator([
			new WaitingValidator( new HttpValidator() ),
			new WaitingValidator( new DnsValidator() )
		]);

		$order = null;
		if ( $this->repository->hasCertificateOrder( $domains ) ) {
			$order = $this->repository->loadCertificateOrder( $domains );
			EE::debug( sprintf( 'Loading the authorization token for domains %s ...', implode( ', ', $domains ) ) );
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
					EE::error( "Domain: $domain not yet authorized." );
				}
				$authorizationChallenge = $this->repository->loadDomainAuthorizationChallenge( $domain );
				if ( ! $solver->supports( $authorizationChallenge ) ) {
					throw new ChallengeNotSupportedException();
				}
			}
			EE::debug( 'Challenge loaded.' );

			$authorizationChallenge = $this->client->reloadAuthorization( $authorizationChallenge );
			if ( $authorizationChallenge->isValid() ) {
				EE::warning( sprintf( 'The challenge is alread validated for domain %s ...', $domain ) );
			} else {
				if ( ! $input->getOption( 'no-test' ) ) {
					EE::log( sprintf( 'Testing the challenge for domain %s...', $domain ) );
					if ( ! $validator->isValid( $authorizationChallenge ) ) {
						EE::warning( sprintf( 'Can not valid challenge for domain %s ...', $domain ) );
					}
				}

				EE::log( sprintf( 'Requesting authorization check for domain %s ...', $domain ) );
				$this->client->challengeAuthorization( $authorizationChallenge );
				$authorizationChallengeToCleanup[] = $authorizationChallenge;
			}
		}

		EE::log( 'The authorization check was successful!' );

		if ( $solver instanceof MultipleChallengesSolverInterface ) {
			$solver->cleanupAll( $authorizationChallengeToCleanup );
		} else {
			/** @var AuthorizationChallenge $authorizationChallenge */
			foreach ( $authorizationChallengeToCleanup as $authorizationChallenge ) {
				$solver->cleanup( $authorizationChallenge );
			}
		}

	}

	public function authorize( $domains, $wildcard = false ) {
		$http_client = $this->getSecureHttpClient();
		$solver      = $wildcard ? new SimpleDnsSolver() : new SimpleHttpSolver();
		$solverName  = $wildcard ? 'dns-01' : 'http-01';
		$order       = $http_client->requestOrder( $domains );

		$authorizationChallengesToSolve = [];
		foreach ( $order->getAuthorizationsChallenges() as $domainKey => $authorizationChallenges ) {
			$authorizationChallenge = null;
			foreach ( $authorizationChallenges as $candidate ) {
				if ( $solver->supports( $candidate ) ) {
					$authorizationChallenge = $candidate;
					EE::debug( 'Authorization challenge supported by solver. Solver: ' . $solverName . ' Challenge: ' . $candidate->getType() );
					break;
				}
				// Should not get here as we are handling it.
				EE::error( 'Authorization challenge supported by solver. Solver: ' . $solverName . ' Challenge: ' . $candidate->getType() );
			}
			if ( null === $authorizationChallenge ) {
				throw new ChallengeNotSupportedException();
			}
			EE::debug( 'Storing authorization challenge. Domain: ' . $domainKey . ' Challenge: ' . $authorizationChallenge->toArray() );

			$this->repository->storeDomainAuthorizationChallenge( $domainKey, $authorizationChallenge );
			$authorizationChallengesToSolve[] = $authorizationChallenge;
		}

		/** @var AuthorizationChallenge $authorizationChallenge */
		foreach ( $authorizationChallengesToSolve as $authorizationChallenge ) {
			EE::debug( 'Solving authorization challenge: Domain: ' . $authorizationChallenge->getDomain() . '' . $authorizationChallenge->toArray() );
			$solver->solve( $authorizationChallenge );
		}

		$this->repository->storeCertificateOrder( $domains, $order );
	}

	public function status() {
		$this->master ?? $this->master = new Filesystem( new Local( EE_CONF_ROOT . '/le-client-keys' ) );

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
}
