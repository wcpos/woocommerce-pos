<?php
/**
 * Ping endpoint tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionProperty;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\API\V2\Ping;
use WP_REST_Request;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Tests the public ping endpoint and its pure helpers.
 */
class Test_Ping_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Reset the request-scoped pressure memo between tests.
	 */
	public function tearDown(): void {
		$this->set_pressure_bucket( null, false );
		parent::tearDown();
	}

	/**
	 * GET and HEAD requests can reach the public route without authentication.
	 */
	public function test_ping_route_is_registered_for_get_and_head(): void {
		wp_set_current_user( 0 );
		$this->assertArrayHasKey( '/wcpos/v2/ping', $this->server->get_routes( 'wcpos/v2' ) );

		foreach ( array( 'GET', 'HEAD' ) as $method ) {
			$request = new WP_REST_Request( $method, '/wcpos/v2/ping' );
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		}
	}

	/**
	 * The response contains only the documented fields and matching pressure header.
	 */
	public function test_ping_payload_shape(): void {
		$before   = time();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/ping' ) );
		$after    = time();
		$data     = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertSame( array(), array_diff( array_keys( $data ), array( 'ok', 'ts', 'v', 'pressure' ) ) );
		$this->assertTrue( $data['ok'] );
		$this->assertIsInt( $data['ts'] );
		$this->assertGreaterThanOrEqual( $before, $data['ts'] );
		$this->assertLessThanOrEqual( $after, $data['ts'] );
		$this->assertSame( VERSION, $data['v'] );

		$headers = $response->get_headers();
		if ( isset( $data['pressure'] ) ) {
			$this->assertContains( $data['pressure'], array( 'low', 'elevated', 'high' ) );
			$this->assertSame( $data['pressure'], $headers['X-WCPOS-Pressure'] );
		} else {
			$this->assertArrayNotHasKey( 'X-WCPOS-Pressure', $headers );
		}
	}

	/**
	 * WCPOS v2 responses include the current pressure bucket.
	 */
	public function test_wcpos_v2_response_includes_pressure_header(): void {
		$this->set_pressure_bucket( 'elevated' );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings' ) );

		$this->assertSame( 'elevated', $response->get_headers()['X-WCPOS-Pressure'] );
	}

	/**
	 * Core REST responses are not modified.
	 */
	public function test_core_response_does_not_include_pressure_header(): void {
		$this->set_pressure_bucket( 'high' );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/types' ) );

		$this->assertArrayNotHasKey( 'X-WCPOS-Pressure', $response->get_headers() );
	}

	/**
	 * An unavailable pressure bucket produces no header.
	 */
	public function test_wcpos_v2_response_omits_pressure_header_when_bucket_is_null(): void {
		$this->set_pressure_bucket( null );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings' ) );

		$this->assertArrayNotHasKey( 'X-WCPOS-Pressure', $response->get_headers() );
	}

	/**
	 * Pressure thresholds include both exact boundaries.
	 *
	 * @dataProvider pressure_boundaries
	 *
	 * @param float  $load     Normalized load.
	 * @param string $expected Expected bucket.
	 */
	public function test_pressure_bucket_boundaries( float $load, string $expected ): void {
		$this->assertSame( $expected, Ping::pressure_bucket( $load ) );
	}

	/**
	 * Provide pressure threshold cases.
	 *
	 * @return array<string, array{float, string}>
	 */
	public function pressure_boundaries(): array {
		return array(
			'below 0.9' => array( 0.899, 'low' ),
			'at 0.9'    => array( 0.9, 'elevated' ),
			'at 1.8'    => array( 1.8, 'elevated' ),
			'above 1.8' => array( 1.801, 'high' ),
		);
	}

	/**
	 * Raw request matching accepts only the two exact ping forms.
	 *
	 * @dataProvider raw_request_cases
	 *
	 * @param string      $method      HTTP method.
	 * @param string      $request_uri Raw request URI.
	 * @param string|null $rest_route  Decoded REST route query parameter.
	 * @param bool        $expected    Expected result.
	 */
	public function test_raw_request_detection(
		string $method,
		string $request_uri,
		?string $rest_route,
		bool $expected
	): void {
		$this->assertSame( $expected, Ping::matches_request( $method, $request_uri, $rest_route ) );
	}

	/**
	 * Provide raw request matching cases.
	 *
	 * @return array<string, array{string, string, string|null, bool}>
	 */
	public function raw_request_cases(): array {
		return array(
			'pretty GET'          => array( 'GET', '/wp-json/wcpos/v2/ping', null, true ),
			'subdirectory HEAD'   => array( 'HEAD', '/shop/wp-json/wcpos/v2/ping?x=1', null, true ),
			'plain GET'           => array( 'GET', '/?rest_route=/wcpos/v2/ping', '/wcpos/v2/ping', true ),
			'encoded plain HEAD'  => array( 'HEAD', '/?rest_route=%2Fwcpos%2Fv2%2Fping', '/wcpos/v2/ping', true ),
			'POST'                => array( 'POST', '/wp-json/wcpos/v2/ping', null, false ),
			'path suffix only'    => array( 'GET', '/wcpos/v2/ping', null, false ),
			'path child'          => array( 'GET', '/wp-json/wcpos/v2/ping/extra', null, false ),
			'plain sibling'       => array( 'GET', '/?rest_route=/wcpos/v2/ping-extra', '/wcpos/v2/ping-extra', false ),
			'marker in unrelated' => array( 'GET', '/products?next=/wcpos/v2/ping', null, false ),
		);
	}

	/**
	 * Set the API's request-scoped pressure memo.
	 *
	 * @param string|null $bucket  Pressure bucket.
	 * @param bool        $checked Whether pressure has already been checked.
	 */
	private function set_pressure_bucket( ?string $bucket, bool $checked = true ): void {
		$bucket_property = new ReflectionProperty( API::class, 'pressure_bucket' );
		$bucket_property->setAccessible( true );
		$bucket_property->setValue( null, $bucket );

		$checked_property = new ReflectionProperty( API::class, 'pressure_bucket_checked' );
		$checked_property->setAccessible( true );
		$checked_property->setValue( null, $checked );
	}
}
