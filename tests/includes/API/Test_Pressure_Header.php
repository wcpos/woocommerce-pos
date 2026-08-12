<?php
/**
 * Pressure response header tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use ReflectionProperty;
use WCPOS\WooCommercePOS\API;
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
