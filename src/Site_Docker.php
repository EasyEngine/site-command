<?php

use function \EE\Utils\mustache_render;

class Site_Docker {

	/**
	 * Generate docker-compose.yml according to requirement.
	 *
	 * @param array $filters Array of flags to determine the docker-compose.yml generation.
	 * @return String docker-compose.yml content string.
	 */
	public function generate_docker_compose_yml( array $filters = [] ) {
		$base = array();

		$restart_default = array( 'name' => 'always' );
		$network_default = array( 'name' => 'site-network' );

		// db configuration.
		$db['service_name'] = array( 'name' => 'db' );
		$db['image']        = array( 'name' => 'easyengine/mariadb:v' . EE_VERSION );
		$db['restart']      = $restart_default;
		$db['volumes']      = array(
			array(
				'vol' => array(
					'name' => './app/db:/var/lib/mysql',
				),
			),
		);
		$db['environment']  = array(
			'env' => array(
				array( 'name' => 'MYSQL_ROOT_PASSWORD' ),
				array( 'name' => 'MYSQL_DATABASE' ),
				array( 'name' => 'MYSQL_USER' ),
				array( 'name' => 'MYSQL_PASSWORD' ),
			),
		);
		$db['networks']     = $network_default;
		// PHP configuration.
		$php['service_name'] = array( 'name' => 'php' );
		$php['image']        = array( 'name' => 'easyengine/php:v' . EE_VERSION );
		$php['depends_on']   = array( 'name' => 'db' );
		$php['restart']      = $restart_default;
		$php['volumes']      = array(
			array(
				'vol' => array(
					array( 'name' => '/opt/easyengine/ee.sqlite:/opt/easyengine/ee.sqlite:ro' ),
					array( 'name' => './app/src:/var/www/html' ),
					array( 'name' => './config/php-fpm/php.ini:/usr/local/etc/php/php.ini' ),
				),
			),
		);
		$php['environment']  = array(
			'env' => array(
				array( 'name' => 'WORDPRESS_DB_HOST' ),
				array( 'name' => 'WORDPRESS_DB_NAME' ),
				array( 'name' => 'WORDPRESS_DB_USER' ),
				array( 'name' => 'WORDPRESS_DB_PASSWORD' ),
				array( 'name' => 'USER_ID' ),
				array( 'name' => 'GROUP_ID' ),
				array( 'name' => 'VIRTUAL_HOST' ),
			),
		);
		$php['networks']     = $network_default;

		// nginx configuration.
		$nginx['service_name'] = array( 'name' => 'nginx' );
		$nginx['image']        = array( 'name' => 'easyengine/nginx:v' . EE_VERSION );
		$nginx['depends_on']   = array( 'name' => 'php' );
		$nginx['restart']      = $restart_default;

		$v_host = ( 'wpsubdom' === $filters['site_type'] ) ? 'VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST}' : 'VIRTUAL_HOST';

		$nginx['environment'] = array(
			'env' => array(
				array( 'name' => $v_host ),
				array( 'name' => 'VIRTUAL_PATH=/' ),
				array( 'name' => 'HSTS=off' ),
			),
		);
		$nginx['volumes']     = array(
			'vol' => array(
				array( 'name' => './app/src:/var/www/html' ),
				array( 'name' => './config/nginx/default.conf:/etc/nginx/conf.d/default.conf' ),
				array( 'name' => './logs/nginx:/var/log/nginx' ),
				array( 'name' => './config/nginx/common:/usr/local/openresty/nginx/conf/common' ),
			),
		);
		$nginx['networks']    = $network_default;

		// PhpMyAdmin configuration.
		$phpmyadmin['service_name'] = array( 'name' => 'phpmyadmin' );
		$phpmyadmin['image']        = array( 'name' => 'easyengine/phpmyadmin:v' . EE_VERSION );
		$phpmyadmin['restart']      = $restart_default;
		$phpmyadmin['environment']  = array(
			'env' => array(
				array( 'name' => 'PMA_ABSOLUTE_URI=http://${VIRTUAL_HOST}/ee-admin/pma/' ),
				array( 'name' => $v_host ),
				array( 'name' => 'VIRTUAL_PATH=/ee-admin/pma/' ),
			),
		);
		$phpmyadmin['networks']     = $network_default;

		// mailhog configuration.
		$mailhog['service_name'] = array( 'name' => 'mailhog' );
		$mailhog['image']        = array( 'name' => 'easyengine/mailhog:v' . EE_VERSION );
		$mailhog['restart']      = $restart_default;
		$mailhog['command']      = array( 'name' => '["-invite-jim=false"]' );
		$mailhog['environment']  = array(
			'env' => array(
				array( 'name' => $v_host ),
				array( 'name' => 'VIRTUAL_PATH=/ee-admin/mailhog/' ),
				array( 'name' => 'VIRTUAL_PORT=8025' ),
			),
		);
		$mailhog['networks']     = $network_default;

		// redis configuration.
		$redis['service_name'] = array( 'name' => 'redis' );
		$redis['image']        = array( 'name' => 'easyengine/redis:v' . EE_VERSION );
		$redis['networks']     = $network_default;

		// phpredisadmin configuration.
		$phpredisadmin['service_name'] = array( 'name' => 'phpredisadmin' );
		$phpredisadmin['image']        = array( 'name' => 'easyengine/phpredisadmin:v' . EE_VERSION );
		$phpredisadmin['environment']  = array(
			'env' => array(
				array( 'name' => $v_host ),
				array( 'name' => 'REDIS_1_HOST=redis' ),
				array( 'name' => 'REDIS_1_PORT=6379' ),
				array( 'name' => 'VIRTUAL_PATH=/ee-admin/phpredisadmin/' ),
			),
		);
		$phpredisadmin['networks']     = $network_default;

		if ( 'db' === $filters['db_host'] ) {
			$base[] = $db;
		}

		$base[] = $php;
		$base[] = $nginx;
		$base[] = $mailhog;
		$base[] = $phpmyadmin;
		$base[] = $phpredisadmin;

		if ( 'wpredis' === $filters['cache_type'] ) {
			$base[] = $redis;
		}

		$binding = array(
			'services' => $base,
			'network'  => true,
		);

		$docker_compose_yml = mustache_render( SITE_TEMPLATE_ROOT . '/docker-compose.mustache', $binding );

		return $docker_compose_yml;
	}
}
