<?php
/**
 * Tests for the POS gateway bootstrap controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Gateway_Bootstrap_Controller extends WCPOS_REST_Unit_Test_Case {
	public function test_bootstrap_returns_ready_for_manual_gateway(): void {
		$request  = $this->wp_rest_post_request( '/wcpos/v1/payment-gateways/pos_cash/bootstrap' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pos_cash', $data['gateway_id'] );
		$this->assertSame( 'ready', $data['status'] );
		$this->assertSame( array(), $data['provider_data'] );
	}
}
