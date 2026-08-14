<?php
/**
 * REST integration test for namespace-based WCPOS request detection.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * REST integration coverage for namespace-based WCPOS request detection.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Wcpos_Request_REST_Integration extends WC_REST_Unit_Test_Case {
	/**
	 * Set the outer REST route before the server fires rest_api_init.
	 */
	public function setUp(): void {
		global $wp;

		$wp->query_vars['rest_route'] = '/wcpos/v1/products';
		unset( $wp->query_vars['wcpos'], $_SERVER['HTTP_X_WCPOS'] );
		delete_transient( 'wcpos_missing_request_marker_v1' );

		parent::setUp();
	}

	/**
	 * Clean up the outer REST route after each dispatch.
	 */
	public function tearDown(): void {
		global $wp;

		unset( $wp->query_vars['wcpos'], $wp->query_vars['rest_route'], $_SERVER['HTTP_X_WCPOS'] );

		parent::tearDown();
	}

	/**
	 * The v1 products route is registered without the X-WCPOS header.
	 */
	public function test_wcpos_v1_products_without_header_does_not_return_rest_no_route(): void {
		// Arrange.
		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );

		// Act.
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Assert.
		$this->assertEquals( false, isset( $data['code'] ) && 'rest_no_route' === $data['code'] );
	}

	/**
	 * The missing-marker diagnostic still fires through the real wiring.
	 */
	public function test_namespace_only_request_sets_missing_marker_log_transient(): void {
		// Arrange/Act: the REST server booted in setUp with a wcpos rest_route
		// and no marker, so init_rest_api() took the namespace-detected branch.
		$transient = get_transient( 'wcpos_missing_request_marker_v1' );

		// Assert: the rate-limit transient proves the logger ran.
		$this->assertNotEquals( false, $transient );

		delete_transient( 'wcpos_missing_request_marker_v1' );
	}
}
