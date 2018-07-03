<?php

use AcmePhp\Cli\Repository\Repository;
use AcmePhp\Cli\Serializer\PemEncoder;
use AcmePhp\Cli\Serializer\PemNormalizer;
use AcmePhp\Core\AcmeClient;
use AcmePhp\Core\Challenge\ChainValidator;
use AcmePhp\Core\Challenge\Http\SimpleHttpSolver;
use AcmePhp\Core\Challenge\Dns\SimpleDnsSolver;
use AcmePhp\Core\Exception\Protocol\ChallengeNotSupportedException;
use AcmePhp\Core\Http\SecureHttpClient;
use AcmePhp\Core\Http\Base64SafeEncoder;
use AcmePhp\Core\Http\ServerErrorHandler;
use AcmePhp\Ssl\Parser\KeyParser;
use AcmePhp\Ssl\Generator\KeyPairGenerator;
use AcmePhp\Ssl\Signer\CertificateRequestSigner;
use AcmePhp\Ssl\Signer\DataSigner;
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


	public function getSecureHttpClient() {
		$this->httpClient         ?? $this->httpClient         = new Client();
		$this->base64SafeEncoder  ?? $this->base64SafeEncoder  = new Base64SafeEncoder();
		$this->keyParser          ?? $this->keyParser          = new KeyParser();
		$this->dataSigner         ?? $this->dataSigner         = new DataSigner();
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

	public function getRepository( $enable_backup = false ) {
		$this->serializer ?? $this->serializer = new Serializer(
			[ new PemNormalizer(), new GetSetMethodNormalizer() ],
			[ new PemEncoder(), new JsonEncoder() ]
		);
		$this->master ?? $this->master = new Filesystem( new Local( EE_CONF_ROOT . '/le-client-keys' ) );
		$this->backup ?? $this->backup = new Filesystem( new NullAdapter() );

		return new Repository( $this->serializer, $this->master, $this->backup, $enable_backup );
	}

	public function getAcmeClient() {

		$repository = $this->getRepository();

		if ( ! $repository->hasAccountKeyPair() ) {
			EE::debug( 'No account key pair was found, generating one...' );
			EE::debug( 'Generating a key pair' );

			$keygen         = new KeyPairGenerator();
			$accountKeyPair = $keygen->generateKeyPair();
			EE::debug( 'Key pair generated, storing' );
			$repository->storeAccountKeyPair( $accountKeyPair );
		} else {
			EE::debug( 'Loading account keypair' );
			$accountKeyPair = $repository->loadAccountKeyPair();
		}

		$this->accountKeyPair ?? $this->accountKeyPair = $accountKeyPair;

		$secureHttpClient = $this->getSecureHttpClient();
		$csrSigner        = new CertificateRequestSigner();

		return new AcmeClient( $secureHttpClient, 'https://acme-v02.api.letsencrypt.org/directory', $csrSigner );

	}

	public function register( $email ) {
		$client = $this->getAcmeClient();
		$client->registerAccount( null, $email );
		EE::log( "Account with email id: $email registered successfully!" );
	}

	public function check( Array $domains, $wildcard = false ) {
		$repository = $this->getRepository();
		$client     = $this->getAcmeClient();
		EE::debug( 'Starting check with solver ' . $wildcard ? 'dns' : 'http' );
		$solver    = $wildcard ? new SimpleDnsSolver() : new SimpleHttpSolver();
		$validator = new ChainValidator();

		$order = null;
		if ( $this->getRepository()->hasCertificateOrder( $domains ) ) {
			$order = $this->getRepository()->loadCertificateOrder( $domains );
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
				if ( ! $repository->hasDomainAuthorizationChallenge( $domain ) ) {
					EE::error( "Domain: $domain not yet authorized." );
				}
				$authorizationChallenge = $repository->loadDomainAuthorizationChallenge( $domain );
				if ( ! $solver->supports( $authorizationChallenge ) ) {
					throw new ChallengeNotSupportedException();
				}
			}
			EE::debug( 'Challenge loaded.' );

			$authorizationChallenge = $client->reloadAuthorization( $authorizationChallenge );
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
				$client->challengeAuthorization( $authorizationChallenge );
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

}
