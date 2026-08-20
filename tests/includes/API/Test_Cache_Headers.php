<?php
/**
 * WCPOS REST response cache-header contract tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact contract-focused documentation.

use ReflectionClass;
use WCPOS\WooCommercePOS\API;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @covers \WCPOS\WooCommercePOS\API::rest_pre_serve_request
 */
class Test_Cache_Headers extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Every registered WCPOS route and method receives the cache contract.
	 */
	public function test_all_registered_wcpos_routes_send_cache_defeating_headers(): void {
		$reflection = new ReflectionClass( API::class );
		$api        = $reflection->newInstanceWithoutConstructor();
		$server     = new class() extends WP_REST_Server {
			public array $sent_headers = array();

			public function send_header( $key, $value ) {
				$this->sent_headers[ $key ] = $value;
			}
		};

		foreach ( API::ROUTE_NAMESPACES as $namespace ) {
			$routes = $this->server->get_routes( $namespace );
			$this->assertNotEmpty( $routes, "No routes registered for {$namespace}." );

			foreach ( $routes as $route => $handlers ) {
				foreach ( $this->allowed_methods( $handlers ) as $method ) {
					$request  = new WP_REST_Request( $method, $route );
					$response = new WP_REST_Response( null, 200, array( 'Vary' => 'Accept-Encoding, origin' ) );

					$server->sent_headers = array();
					$api->rest_pre_serve_request( false, $response, $request, $server );

					$this->assertSame(
						'private, no-store',
						$server->sent_headers['Cache-Control'] ?? null,
						"{$method} {$route} did not send the required Cache-Control header."
					);
					$this->assertSame(
						1,
						count(
							array_filter(
								array_keys( $server->sent_headers ),
								static function ( string $header ): bool {
									return 0 === strcasecmp( 'Cache-Control', $header );
								}
							)
						),
						"{$method} {$route} sent more than one Cache-Control field."
					);

					$this->assertSame(
						1,
						count(
							array_filter(
								array_keys( $server->sent_headers ),
								static function ( string $header ): bool {
									return 0 === strcasecmp( 'Vary', $header );
								}
							)
						),
						"{$method} {$route} sent more than one Vary field."
					);
					$vary = array_map( 'trim', explode( ',', $server->sent_headers['Vary'] ?? '' ) );
					$this->assertContains( 'Accept-Encoding', $vary, "{$method} {$route} clobbered the existing Vary value." );
					$this->assertContains( 'Origin', $vary, "{$method} {$route} is missing Vary: Origin." );
					$this->assertContains( 'Authorization', $vary, "{$method} {$route} is missing Vary: Authorization." );
					$this->assertCount( 3, $vary, "{$method} {$route} sent duplicate Vary tokens." );
				}
			}
		}
	}

	/**
	 * Non-WCPOS routes stay untouched and LiteSpeed is notified once for WCPOS.
	 */
	public function test_cache_contract_is_isolated_to_wcpos_routes(): void {
		$reflection = new ReflectionClass( API::class );
		$api        = $reflection->newInstanceWithoutConstructor();
		$before     = did_action( 'litespeed_control_set_nocache' );
		$server     = new class() extends WP_REST_Server {
			public array $sent_headers = array();

			public function send_header( $key, $value ) {
				$this->sent_headers[ $key ] = $value;
			}
		};

		$api->rest_pre_serve_request(
			false,
			new WP_REST_Response(),
			new WP_REST_Request( 'GET', '/wp/v2/types' ),
			$server
		);

		$this->assertArrayNotHasKey( 'Cache-Control', $server->sent_headers );
		$this->assertArrayNotHasKey( 'Vary', $server->sent_headers );
		$this->assertSame( $before, did_action( 'litespeed_control_set_nocache' ) );

		$api->rest_pre_serve_request(
			false,
			new WP_REST_Response(),
			new WP_REST_Request( 'GET', '/wcpos/v1/products' ),
			$server
		);

		$this->assertSame( $before + 1, did_action( 'litespeed_control_set_nocache' ) );
	}

	/**
	 * Collect the HTTP verbs a registered route responds to.
	 *
	 * @param array $handlers Route handlers as stored by the REST server.
	 *
	 * @return string[]
	 */
	private function allowed_methods( array $handlers ): array {
		$allowed = array();

		foreach ( $handlers as $handler ) {
			foreach ( array_keys( $handler['methods'] ) as $method ) {
				$allowed[ $method ] = true;
			}
		}

		return array_keys( $allowed );
	}
}
