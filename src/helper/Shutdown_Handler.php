<?php

namespace EE\Site\Type;
/**
 * Class Shutdown_Handler
 */
class Shutdown_Handler {

	/**
	 * Handle fatal errors. This function was created as the register_shutdown_function requires the callable function
	 * to be public and any public function inside site-command would be callable directly through command-line.
	 *
	 * @param array $site_command having Site_Command object.
	 */
	public function cleanup( $site_command ) {
		// Read it first: any later notice, even a masked one, replaces the fatal in error_get_last().
		$error     = error_get_last();
		$reflector = new \ReflectionObject( $site_command[0] );
		$method    = $reflector->getMethod( 'shut_down_function' );
		// A no-op since PHP 8.1 and deprecated in 8.5.
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$method->invoke( $site_command[0], $error );
	}
}
