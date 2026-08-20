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
use WCPOS\WooCommercePOS\Init;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @covers \WCPOS\WooCommercePOS\Init::rest_pre_serve_request
 */
class Test_Cache_Headers extends WCPOS_REST_Unit_Test_Case {
	/**
	 * A literal current-lane route, so the lane-coverage scanner can classify
	 * these cases (a route assembled at runtime is invisible to it). The
	 * route-table loop asserts it actually visited this route.
	 */
	private const CURRENT_LANE_ROUTE = '/wcpos/v2/products';

	/**
	 * The relay's consent callback is served WITHOUT constructing API (see
	 * Init::register_public_relay_routes()), which is why the cache contract
	 * lives on Init's unconditional rest_pre_serve_request hook.
	 */
	private const UNMARKED_RELAY_ROUTE = '/wcpos/v1/print-jobs/relay-verification';

	/**
	 * Every registered WCPOS route and method receives the cache contract.
	 */
	public function test_all_registered_wcpos_routes_send_cache_defeating_headers(): void {
		// Arrange.
		$init    = $this->new_init();
		$server  = $this->new_spy_server();
		$visited = array();

		foreach ( API::ROUTE_NAMESPACES as $namespace ) {
			$routes = $this->server->get_routes( $namespace );
			$this->assertNotEmpty( $routes, "No routes registered for {$namespace}." );

			foreach ( $routes as $route => $handlers ) {
				foreach ( $this->allowed_methods( $handlers ) as $method ) {
					// Act.
					$server->sent_headers = array();
					$init->rest_pre_serve_request(
						false,
						new WP_REST_Response( null, 200, array( 'Vary' => 'Accept-Encoding, origin' ) ),
						new WP_REST_Request( $method, $route ),
						$server
					);
					$visited[ $route ] = true;

					// Assert.
					$this->assertEquals(
						'private, no-store',
						$server->sent_headers['Cache-Control'] ?? null,
						"{$method} {$route} did not send the required Cache-Control header."
					);
					$this->assertEquals(
						1,
						$this->count_header_fields( $server->sent_headers, 'Cache-Control' ),
						"{$method} {$route} sent more than one Cache-Control field."
					);
					$this->assertEquals(
						1,
						$this->count_header_fields( $server->sent_headers, 'Vary' ),
						"{$method} {$route} sent more than one Vary field."
					);
					$this->assertEqualsCanonicalizing(
						array( 'Accept-Encoding', 'Origin', 'Authorization', 'X-WCPOS-Store' ),
						array_map( 'trim', explode( ',', $server->sent_headers['Vary'] ?? '' ) ),
						"{$method} {$route} must merge Origin, Authorization and X-WCPOS-Store into the existing Vary tokens without duplicates or clobbering."
					);
				}
			}
		}

		$this->assertArrayHasKey( self::CURRENT_LANE_ROUTE, $visited, 'The route-table loop must cover the current-lane products route.' );
	}

	/**
	 * Non-WCPOS routes stay untouched and LiteSpeed is notified once per WCPOS request.
	 */
	public function test_cache_contract_is_isolated_to_wcpos_routes(): void {
		// Arrange.
		$init   = $this->new_init();
		$server = $this->new_spy_server();
		$before = did_action( 'litespeed_control_set_nocache' );

		// Act: a non-WCPOS route.
		$init->rest_pre_serve_request( false, new WP_REST_Response(), new WP_REST_Request( 'GET', '/wp/v2/types' ), $server );

		// Assert.
		$this->assertArrayNotHasKey( 'Cache-Control', $server->sent_headers );
		$this->assertArrayNotHasKey( 'Vary', $server->sent_headers );
		$this->assertEquals( $before, did_action( 'litespeed_control_set_nocache' ) );

		// Act: a current-lane WCPOS route.
		$init->rest_pre_serve_request( false, new WP_REST_Response(), new WP_REST_Request( 'GET', self::CURRENT_LANE_ROUTE ), $server );

		// Assert.
		$this->assertEquals( $before + 1, did_action( 'litespeed_control_set_nocache' ) );
		$this->assertEquals( 'private, no-store', $server->sent_headers['Cache-Control'] ?? null );
	}

	/**
	 * The unmarked relay consent callback gets the contract even though API is
	 * never constructed for it — a cached verification token could otherwise
	 * be replayed as stale proof.
	 */
	public function test_unmarked_relay_verification_route_receives_cache_contract(): void {
		// Arrange.
		$init   = $this->new_init();
		$server = $this->new_spy_server();

		// Act.
		$init->rest_pre_serve_request( false, new WP_REST_Response(), new WP_REST_Request( 'GET', self::UNMARKED_RELAY_ROUTE ), $server );

		// Assert.
		$this->assertEquals( 'private, no-store', $server->sent_headers['Cache-Control'] ?? null );
		$this->assertEquals( 'Origin, Authorization, X-WCPOS-Store', $server->sent_headers['Vary'] ?? null );
	}

	/**
	 * WP dispatches REST routes case-insensitively, so the guard must too.
	 */
	public function test_mixed_case_wcpos_route_receives_cache_contract(): void {
		// Arrange.
		$init   = $this->new_init();
		$server = $this->new_spy_server();

		// Act.
		$init->rest_pre_serve_request( false, new WP_REST_Response(), new WP_REST_Request( 'GET', '/WCPOS/V2/products' ), $server );

		// Assert.
		$this->assertEquals( 'private, no-store', $server->sent_headers['Cache-Control'] ?? null );
	}

	/**
	 * Vary merging edge cases: empty values produce no empty tokens, and a
	 * wildcard is preserved alone ('*' is grammatically an alternative to a
	 * field-name list, so '*, Origin' would be invalid and uncacheable-safe
	 * intermediaries could misparse it).
	 */
	public function test_vary_merge_edge_cases(): void {
		// Arrange.
		$init   = $this->new_init();
		$server = $this->new_spy_server();

		// Act: an empty existing Vary value.
		$init->rest_pre_serve_request(
			false,
			new WP_REST_Response( null, 200, array( 'Vary' => '' ) ),
			new WP_REST_Request( 'GET', self::CURRENT_LANE_ROUTE ),
			$server
		);

		// Assert: no empty tokens survive the merge.
		$this->assertEquals( 'Origin, Authorization, X-WCPOS-Store', $server->sent_headers['Vary'] ?? null );

		// Act: a wildcard existing Vary value.
		$server->sent_headers = array();
		$init->rest_pre_serve_request(
			false,
			new WP_REST_Response( null, 200, array( 'Vary' => '*' ) ),
			new WP_REST_Request( 'GET', self::CURRENT_LANE_ROUTE ),
			$server
		);

		// Assert: the wildcard stays alone.
		$this->assertEquals( '*', $server->sent_headers['Vary'] ?? null );
	}

	/**
	 * Instantiate Init without running its constructor (hook registration).
	 *
	 * @return Init
	 */
	private function new_init(): Init {
		$reflection = new ReflectionClass( Init::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * A REST server that records sent headers instead of emitting them.
	 *
	 * @return WP_REST_Server
	 */
	private function new_spy_server(): WP_REST_Server {
		return new class() extends WP_REST_Server {
			public array $sent_headers = array();

			public function send_header( $key, $value ) {
				$this->sent_headers[ $key ] = $value;
			}
		};
	}

	/**
	 * Count sent header fields matching a name case-insensitively.
	 *
	 * @param array  $sent_headers Recorded headers.
	 * @param string $name         Header field name.
	 *
	 * @return int
	 */
	private function count_header_fields( array $sent_headers, string $name ): int {
		return count(
			array_filter(
				array_keys( $sent_headers ),
				static function ( string $header ) use ( $name ): bool {
					return 0 === strcasecmp( $name, $header );
				}
			)
		);
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
