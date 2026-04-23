<?php
/**
 * Tests for the POS checkout controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Checkout_Controller extends WCPOS_REST_Unit_Test_Case {
	public function test_post_checkout_start_returns_completed_state_for_pos_cash(): void {
		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'pos_cash',
				'total'          => '50.00',
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/checkout' );
		$request->set_header( 'X-WCPOS-Idempotency-Key', wp_generate_uuid4() );
		$request->set_body_params(
			array(
				'gateway_id'   => 'pos_cash',
				'action'       => 'start',
				'payment_data' => array(
					'amount_tendered' => '50.00',
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $data['status'] );
		$this->assertSame( 'pos_cash', $data['gateway_id'] );
	}

	public function test_get_checkout_returns_last_known_state(): void {
		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'pos_cash',
				'total'          => '50.00',
			)
		);

		$post_request = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/checkout' );
		$post_request->set_header( 'X-WCPOS-Idempotency-Key', wp_generate_uuid4() );
		$post_request->set_body_params(
			array(
				'gateway_id'   => 'pos_cash',
				'action'       => 'start',
				'payment_data' => array(
					'amount_tendered' => '50.00',
				),
			)
		);
		$this->server->dispatch( $post_request );

		$get_request = $this->wp_rest_get_request( '/wcpos/v1/orders/' . $order->get_id() . '/checkout' );
		$response    = $this->server->dispatch( $get_request );
		$data        = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $data['status'] );
		$this->assertSame( 'pos_cash', $data['gateway_id'] );
	}
}
