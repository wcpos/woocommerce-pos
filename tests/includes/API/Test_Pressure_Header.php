<?php
/**
 * Pressure response header tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionProperty;
use WCPOS\WooCommercePOS\API\V2\Ping;
use WP_REST_Request;

/**
 * Tests pressure headers on plugin and core REST responses.
 */
class Test_Pressure_Header extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Reset the request-scoped pressure memo between tests.
	 */
	public function tearDown(): void {
		$this->set_pressure_bucket( null, false );
		parent::tearDown();
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
	 * WCPOS v1 responses include the current pressure bucket.
	 */
	public function test_wcpos_v1_response_includes_pressure_header(): void {
		$this->set_pressure_bucket( 'low' );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/products' ) );

		$this->assertSame( 'low', $response->get_headers()['X-WCPOS-Pressure'] );
	}

	/**
	 * WCPOS error responses converted from WP_Error keep the pressure bucket.
	 */
	public function test_wcpos_error_response_includes_pressure_header(): void {
		$this->set_pressure_bucket( 'high' );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/no-such-route' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'high', $response->get_headers()['X-WCPOS-Pressure'] );
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
	 * Set the request-scoped host pressure memo on the Ping controller.
	 *
	 * @param string|null $bucket  Pressure bucket.
	 * @param bool        $checked Whether pressure has already been read.
	 */
	private function set_pressure_bucket( ?string $bucket, bool $checked = true ): void {
		$bucket_property = new ReflectionProperty( Ping::class, 'host_pressure_bucket' );
		$bucket_property->setAccessible( true );
		$bucket_property->setValue( null, $bucket );

		$checked_property = new ReflectionProperty( Ping::class, 'host_pressure_checked' );
		$checked_property->setAccessible( true );
		$checked_property->setValue( null, $checked );
	}
}
