<?php

use function \EE\Utils\mustache_render;
use Yosymfony\Toml\TomlBuilder;

class Site_Docker {

	/**
	 * Generate docker-compose.yml according to requirement.
	 *
	 * @param array $filters Array of flags to determine the docker-compose.yml generation.
	 *                       Empty/Default -> Generates default WordPress docker-compose.yml
	 *                       ['le']        -> Enables letsencrypt in the generation.
	 *
	 * @return String docker-compose.yml content string.
	 */
	public function generate_docker_compose_yml( array $filters = [] ) {
		$base = array();

		$restart_default = array( 'name' => 'always' );
		$network_default = array( 'name' => 'site-network' );

		$frontend_entrypoints = ( in_array( 'le', $filters ) ) ? 'http,https' : 'http';

		// db configuration.
		$db['service_name'] = array( 'name' => 'db' );
		$db['image']        = array( 'name' => 'easyengine/mariadb' );
		$db['restart']      = $restart_default;
		$db['volumes']      = array( array( 'vol' => array( 'name' => './app/db:/var/lib/mysql' ) ) );

		$db['healthcheck']  = array(
			'health' => array(
				array( 'name' => 'test: "/etc/init.d/mysql status"' ),
				array( 'name' => 'interval: 1s' ),
				array( 'name' => 'start_period: 5s' ),
				array( 'name' => 'retries: 120' ),
			),
		);

		$db['environment'] = array(
			'env' => array(
				array( 'name' => 'MYSQL_ROOT_PASSWORD' ),
				array( 'name' => 'MYSQL_DATABASE' ),
				array( 'name' => 'MYSQL_USER' ),
				array( 'name' => 'MYSQL_PASSWORD' ),
			),
		);
		$db['networks']    = $network_default;

		// PHP configuration.
		$php['service_name'] = array( 'name' => 'php' );
		$php['image']        = array( 'name' => 'easyengine/php' );
		$php['depends_on']   = array( 'name' => 'db' );
		$php['restart']      = $restart_default;
		$php['volumes']      = array(
			'vol' => array(
				array( 'name' => './app/src:/var/www/html' ),
				array( 'name' => './config/php-fpm/php.ini:/usr/local/etc/php/php.ini' )
			)
		);
		$php['environment']  = array(
			'env' => array(
				array( 'name' => 'WORDPRESS_DB_HOST' ),
				array( 'name' => 'WORDPRESS_DB_USER=${MYSQL_USER}' ),
				array( 'name' => 'WORDPRESS_DB_PASSWORD=${MYSQL_PASSWORD}' ),
				array( 'name' => 'USER_ID=${USER_ID}' ),
				array( 'name' => 'GROUP_ID=${GROUP_ID}' ),
			),
		);
		$php['networks']     = $network_default;


		// nginx configuration..
		$nginx['service_name'] = array( 'name' => 'nginx' );
		$nginx['image']        = array( 'name' => 'easyengine/nginx' );
		$nginx['depends_on']   = array( 'name' => 'php' );
		$nginx['restart']      = $restart_default;
		$v_host                = in_array( 'wpsubdom', $filters ) ? 'HostRegexp:{subdomain:.+}.${VIRTUAL_HOST},${VIRTUAL_HOST}' : 'Host:${VIRTUAL_HOST}';

		$nginx['labels']  = array(
			'label' => array(
				array( 'name' => 'traefik.port=80' ),
				array( 'name' => 'traefik.enable=true' ),
				array( 'name' => 'traefik.protocol=http' ),
				array( 'name' => 'traefik.docker.network=site-network' ),
				array( 'name' => "traefik.frontend.entryPoints=$frontend_entrypoints" ),
				array( 'name' => "traefik.frontend.rule=$v_host" ),
			),
		);
		$nginx['volumes'] = array(
			'vol' => array(
				array( 'name' => './app/src:/var/www/html' ),
				array( 'name' => './config/nginx/default.conf:/etc/nginx/conf.d/default.conf' ),
				array( 'name' => './logs/nginx:/var/log/nginx' ),
			),
		);

		$nginx['networks'] = $network_default;

		// PhpMyAdmin configuration.
		$phpmyadmin['service_name'] = array( 'name' => 'phpmyadmin' );
		$phpmyadmin['image']        = array( 'name' => 'easyengine/phpmyadmin' );
		$phpmyadmin['restart']      = $restart_default;
		$phpmyadmin['environment']  = array(
			'env' => array(
				array( 'name' => 'PMA_ABSOLUTE_URI=http://${VIRTUAL_HOST}/ee-admin/pma/' ),
			),
		);
		$phpmyadmin['labels']       = array(
			'label' => array(
				array( 'name' => 'traefik.port=80' ),
				array( 'name' => 'traefik.enable=true' ),
				array( 'name' => 'traefik.protocol=http' ),
				array( 'name' => "traefik.frontend.entryPoints=$frontend_entrypoints" ),
				array( 'name' => 'traefik.frontend.rule=Host:${VIRTUAL_HOST};PathPrefixStrip:/ee-admin/pma/' ),
			),
		);

		$phpmyadmin['networks'] = $network_default;

		// mailhog configuration.
		$mail['service_name'] = array( 'name' => 'mail' );
		$mail['image']        = array( 'name' => 'easyengine/mail' );
		$mail['restart']      = $restart_default;
		$mail['command']      = array( 'name' => '["-invite-jim=false"]' );
		$mail['labels']       = array(
			'label' => array(
				array( 'name' => 'traefik.port=8025' ),
				array( 'name' => 'traefik.enable=true' ),
				array( 'name' => 'traefik.protocol=http' ),
				array( 'name' => "traefik.frontend.entryPoints=$frontend_entrypoints" ),
				array( 'name' => 'traefik.frontend.rule=Host:${VIRTUAL_HOST};PathPrefixStrip:/ee-admin/mailhog/' ),
			),
		);

		$mail['networks'] = $network_default;

		// redis configuration.
		$redis['service_name'] = array( 'name' => 'redis' );
		$redis['image']        = array( 'name' => 'easyengine/redis' );
		$redis['networks']     = $network_default;

		if ( in_array( 'wpredis', $filters, true ) ) {
			$base[] = $redis;
		}

		$base[] = $db;
		$base[] = $php;
		$base[] = $nginx;
		$base[] = $mail;
		$base[] = $phpmyadmin;

		$binding = array(
			'services' => $base,
			'network'  => true,
		);

		$docker_compose_yml = mustache_render( EE_ROOT . '/vendor/easyengine/site-command/templates/docker-compose.mustache', $binding );

		return $docker_compose_yml;
	}

	public function generate_traefik_toml() {
		$tb     = new TomlBuilder();
		$result = $tb->addComment( 'Traefik Configuration' )
			->addValue( 'defaultEntryPoints', array( 'http' ) )
			->addValue( 'InsecureSkipVerify', true )
			->addValue( 'logLevel', 'DEBUG' )
			->addTable( 'entryPoints' )
			->addTable( 'entryPoints.traefik' )
			->addValue( 'address', ':8080' )
			->addTable( 'entryPoints.traefik.auth.basic' )
			->addValue( 'users', array( 'easyengine:$apr1$CSR8Nxt6$h/Mid6X/vb6ozs4lrXrcw1' ) )
			->addTable( 'entryPoints.http' )
			->addValue( 'address', ':80' )
			->addTable( 'api' )
			->addValue( 'entryPoint', 'traefik' )
			->addValue( 'dashboard', true )
			->addValue( 'debug', true )
			->addTable( 'docker' )
			->addValue( 'domain', 'docker.local' )
			->addValue( 'watch', true )
			->addValue( 'exposedByDefault', false )
			->addTable( 'file' )
			->addValue( 'directory', '/etc/traefik/endpoints/' )
			->addValue( 'watch', true )
			->getTomlString();

		return $result;
	}
}
