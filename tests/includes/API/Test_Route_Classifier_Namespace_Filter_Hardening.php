<?php
/**
 * Tests that the namespace filter cannot remove the core gate coverage.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * The woocommerce_pos_rest_namespaces filter is strictly additive: a consumer
 * replacing the list (dropping wcpos/v1) must not strip the central permission
 * gate off live v1 routes.
 *
 * @covers \WCPOS\WooCommercePOS\API::get_route_namespaces
 * @covers \WCPOS\WooCommercePOS\API::rest_pre_dispatch
 */
class Test_Route_Classifier_Namespace_Filter_Hardening extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Replace (rather than extend) the namespace list before REST registration.
	 */
	public function setUp(): void {
		add_filter( 'woocommerce_pos_rest_namespaces', array( $this, 'replace_namespaces' ) );
		parent::setUp();
	}

	/**
	 * Remove the replacing filter.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_rest_namespaces', array( $this, 'replace_namespaces' ) );
		parent::tearDown();
	}

	/**
	 * Return a namespace list that drops wcpos/v1 entirely.
	 *
	 * @param string[] $namespaces REST namespaces.
	 *
	 * @return string[] REST namespaces.
	 */
	public function replace_namespaces( array $namespaces ): array {
		return array( 'wcpos/v2' );
	}

	/**
	 * Core v1 routes keep the baseline gate even when the filter drops v1.
	 */
	public function test_v1_routes_keep_gate_when_filter_drops_v1(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/settings' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'] );
	}
}
