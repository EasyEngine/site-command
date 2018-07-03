<?php

use AcmePhp\Cli\Serializer\PemEncoder;
use AcmePhp\Cli\Serializer\PemNormalizer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Adapter\NullAdapter;

class Site_Letsencrypt {

	public function getAcmeClient(){
		// Get repository class working.
		$serializer = new Serializer(
			[new PemNormalizer(), new GetSetMethodNormalizer()],
			[new PemEncoder(), new JsonEncoder()]
		);
		// TODO: change directory for master fs
		$master = new Filesystem( new Local( EE_CONF_ROOT . '/master' ) );
		$backup = new Filesystem( new NullAdapter() );
		$repository = new \AcmePhp\Cli\Repository\Repository( $serializer, $master, $backup, false );

		if (!$repository->hasAccountKeyPair()) {
            EE::log('No account key pair was found, generating one...');
            EE::log('Generating a key pair');

			/** @var KeyPair $accountKeyPair */
			$keygen = new AcmePhp\Ssl\Generator\KeyPairGenerator();
            $accountKeyPair = $keygen->generateKeyPair();

            EE::log('Key pair generated, storing');
            $repository->storeAccountKeyPair($accountKeyPair);
		}
		EE::log('Loading acc keypair');

		// $core = new \AcmePhp\Core\AcmeClient();
		// $ssl = new \AcmePhp\Ssl\Certificate();
	}

}