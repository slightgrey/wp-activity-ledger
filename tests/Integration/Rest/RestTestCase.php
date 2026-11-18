<?php
/**
 * Shared helpers for REST tests.
 *
 * @package WPActivityLedger
 */

declare( strict_types=1 );

namespace WPActivityLedger\Tests\Integration\Rest;

use WPActivityLedger\Plugin;
use WPActivityLedger\Tests\Integration\IntegrationTestCase;

/**
 * Dispatches requests to the plugin's REST routes in-process.
 */
abstract class RestTestCase extends IntegrationTestCase {

	/**
	 * Dispatches a request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below wpal/v1, e.g. "/events".
	 * @param array<string, mixed> $params Query parameters (GET) or JSON body (other methods).
	 *
	 * @return \WP_REST_Response Response.
	 */
	protected static function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		rest_get_server();

		$request = new \WP_REST_Request( $method, '/wpal/v1' . $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} elseif ( array() !== $params ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Flushes buffered events.
	 *
	 * @return void
	 */
	protected static function flush(): void {
		Plugin::instance()->recorder()->flush();
	}
}
