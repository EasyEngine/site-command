<?php

use function \EE\Utils\mustache_render;

class Site_Docker {

	/**
	 * Generate docker-compose.yml according to requirement.
	 *
	 * @param array $filters Array to determine the docker-compose.yml generation.
	 *
	 * @return String docker-compose.yml content string.
	 */
	public function generate_docker_compose_yml( array $filters = [] ) {
		$base = array();

		$restart_default = array( 'name' => 'always' );
		$network_default = array( 'name' => 'site-network' );

		// nginx configuration.
		$nginx['service_name'] = array( 'name' => 'nginx' );
		$nginx['image']        = array( 'name' => 'easyengine/nginx:v' . EE_VERSION );
		$nginx['restart']      = $restart_default;

		$v_host = 'VIRTUAL_HOST';

		$nginx['environment'] = array(
			'env' => array(
				array( 'name' => $v_host ),
				array( 'name' => 'VIRTUAL_PATH=/' ),
				array( 'name' => 'HSTS=off' ),
			),
		);
		$nginx['volumes']     = array(
			'vol' => array(
				array( 'name' => './app/src:/var/www/htdocs' ),
				array( 'name' => './config/nginx/default.conf:/etc/nginx/conf.d/default.conf' ),
				array( 'name' => './logs/nginx:/var/log/nginx' ),
				array( 'name' => './config/nginx/common:/usr/local/openresty/nginx/conf/common' ),
			),
		);
		$nginx['labels']      = array(
			array(
				'label' => array(
					'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
				),
			),
		);
		$nginx['networks']    = $network_default;

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
		$mailhog['labels']       = array(
			array(
				'label' => array(
					'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
				),
			),
		);
		$mailhog['networks']     = $network_default;

		$base[] = $nginx;
		$base[] = $mailhog;

		$binding = array(
			'services' => $base,
			'network'  => true,
		);

		$docker_compose_yml = mustache_render( SITE_TEMPLATE_ROOT . '/docker-compose.mustache', $binding );

		return $docker_compose_yml;
	}
}
