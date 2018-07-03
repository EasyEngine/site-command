<?php

use AcmePhp\Cli\Repository\Repository;
use AcmePhp\Cli\Serializer\PemEncoder;
use AcmePhp\Cli\Serializer\PemNormalizer;
use AcmePhp\Core\AcmeClient;
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
use GuzzleHttp\HandlerStack;


class Site_Letsencrypt {

	public function getAcmeClient() {

		$serializer = new Serializer(
			[ new PemNormalizer(), new GetSetMethodNormalizer() ],
			[ new PemEncoder(), new JsonEncoder() ]
		);

		$master     = new Filesystem( new Local( EE_CONF_ROOT . '/master' ) );
		$backup     = new Filesystem( new NullAdapter() );
		$repository = new Repository( $serializer, $master, $backup, false );

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

		$httpClient         = new Client();
		$serverErrorHandler = new ServerErrorHandler();
		$base64SafeEncoder  = new Base64SafeEncoder();
		$keyParser          = new KeyParser();
		$dataSigner         = new DataSigner();

		$secureHttpClient = new SecureHttpClient(
			$accountKeyPair,
			$httpClient,
			$base64SafeEncoder,
			$keyParser,
			$dataSigner,
			$serverErrorHandler
		);
		$csrSigner        = new CertificateRequestSigner();

		return new AcmeClient( $secureHttpClient, 'https://acme-v02.api.letsencrypt.org/directory', $csrSigner );

	}

	public function register( $email ) {
		$client = $this->getAcmeClient();
		$client->registerAccount( null, $email );
		EE::log( "Account with email id: $email registered successfully!" );
	}

}
