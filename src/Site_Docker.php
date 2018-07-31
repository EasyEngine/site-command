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
		$base = [];

		$restart_default = [ 'name' => 'always' ];
		$network_default = [ 'name' => 'site-network' ];

		// nginx configuration.
		$nginx['service_name'] = [ 'name' => 'nginx' ];
		$nginx['image']        = [ 'name' => 'easyengine/nginx:v' . EE_VERSION ];
		$nginx['restart']      = $restart_default;

		$v_host = 'VIRTUAL_HOST';

		$nginx['environment'] = [
			'env' => [
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/' ],
				[ 'name' => 'HSTS=off' ],
			],
		];
		$nginx['volumes']     = [
			'vol' => [
				[ 'name' => './app/src:/var/www/htdocs' ],
				[ 'name' => './config/nginx/default.conf:/etc/nginx/conf.d/default.conf' ],
				[ 'name' => './logs/nginx:/var/log/nginx' ],
				[ 'name' => './config/nginx/common:/usr/local/openresty/nginx/conf/common' ],
			],
		];
		$nginx['labels']      = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$nginx['networks']    = $network_default;

		// mailhog configuration.
		$mailhog['service_name'] = [ 'name' => 'mailhog' ];
		$mailhog['image']        = [ 'name' => 'easyengine/mailhog:v' . EE_VERSION ];
		$mailhog['restart']      = $restart_default;
		$mailhog['command']      = [ 'name' => '["-invite-jim=false"]' ];
		$mailhog['environment']  = [
			'env' => [
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/ee-admin/mailhog/' ],
				[ 'name' => 'VIRTUAL_PORT=8025' ],
			],
		];
		$mailhog['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$mailhog['networks']     = $network_default;

		$base[] = $nginx;
		$base[] = $mailhog;

		$binding = [
			'services' => $base,
			'network'  => true,
		];

		$docker_compose_yml = mustache_render( SITE_TEMPLATE_ROOT . '/docker-compose.mustache', $binding );

		return $docker_compose_yml;
	}
}
