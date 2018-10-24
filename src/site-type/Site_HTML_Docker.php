<?php

namespace EE\Site\Type;

use function EE\Utils\mustache_render;

class Site_HTML_Docker {

	/**
	 * Generate docker-compose.yml according to requirement.
	 *
	 * @param array $filters Array to determine the docker-compose.yml generation.
	 *
	 * @return String docker-compose.yml content string.
	 */
	public function generate_docker_compose_yml( array $filters = [] ) {
		$img_versions = \EE\Utils\get_image_versions();
		$base         = [];

		$restart_default = [ 'name' => 'always' ];

		// nginx configuration.
		$nginx['service_name'] = [ 'name' => 'nginx' ];
		$nginx['image']        = [ 'name' => 'easyengine/nginx:' . $img_versions['easyengine/nginx'] ];
		$nginx['restart']      = $restart_default;

		$v_host = 'VIRTUAL_HOST';

		$nginx['environment'] = [
			'env' => [
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/' ],
				[ 'name' => 'HSTS=off' ],
			],
		];
		if ( ! empty( $filters['nohttps'] ) ) {
			$nginx['environment']['env'][] = [ 'name' => 'HTTPS_METHOD=nohttps' ];
		}
		$nginx['volumes']  = [
			'vol' => [
				[ 'name' => 'htdocs:/var/www' ],
				[ 'name' => 'config_nginx:/etc/nginx' ],
				[ 'name' => 'log_nginx:/var/log/nginx' ],
			],
		];
		$nginx['labels']   = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$nginx['networks'] = [
			'net' => [
				[
					'name'    => 'site-network',
					'aliases' => [
						'alias' => [
							'name' => '${VIRTUAL_HOST}',
						],
					],
				],
				[ 'name' => 'global-frontend-network' ],
			]
		];

		$volumes = [
			'external_vols' => [
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'htdocs' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'config_nginx' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'log_nginx' ],
			],
		];

		$base[] = $nginx;

		$binding = [
			'services'        => $base,
			'network'         => [
				'networks_labels' => [
					'label' => [
						[ 'name' => 'org.label-schema.vendor=EasyEngine' ],
						[ 'name' => 'io.easyengine.site=${VIRTUAL_HOST}' ],
					],
				],
			],
			'created_volumes' => $volumes,
		];

		$docker_compose_yml = mustache_render( SITE_TEMPLATE_ROOT . '/docker-compose.mustache', $binding );

		return $docker_compose_yml;
	}
}
