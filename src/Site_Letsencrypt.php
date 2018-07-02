<?php

use \AcmePhp\Cli\Serializer\PemEncoder;
use \AcmePhp\Cli\Serializer\PemNormalizer;
use \Symfony\Component\Serializer\Encoder\JsonEncoder;
use \Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use \Symfony\Component\Serializer\Serializer;
use \League\Flysystem\Filesystem;
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
		$master = new Filesystem( new Local( __DIR__ ) );
		$backup = new Filesystem( new NullAdapter() );
		$repository = new \AcmePhp\Cli\Repository\Repository( $serializer, $master, $backup, false );

		// $core = new \AcmePhp\Core\AcmeClient();
		// $ssl = new \AcmePhp\Ssl\Certificate();
	}

}