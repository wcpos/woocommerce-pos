<?php
/**
 * Tests for the POS gateway bootstrap controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * Gateway bootstrap controller tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Gateway_Bootstrap_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * It returns the manual gateway bootstrap payload.
	 */
	public function test_bootstrap_returns_ready_for_manual_gateway(): void {
		$request  = $this->wp_rest_post_request( '/wcpos/v1/payment-gateways/pos_cash/bootstrap' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pos_cash', $data['gateway_id'] );
		$this->assertSame( 'ready', $data['status'] );
		$this->assertSame( array(), $data['provider_data'] );
		$this->assertArrayHasKey( 'expires_at', $data );
		$this->assertNull( $data['expires_at'] );
	}

	/**
	 * It rejects non-array bootstrap context values.
	 */
	public function test_bootstrap_rejects_scalar_context(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/payment-gateways/pos_cash/bootstrap' );
		$request->set_body_params(
			array(
				'context' => 'invalid',
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
	}
}
