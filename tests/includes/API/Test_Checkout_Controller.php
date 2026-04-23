<?php
/**
 * Tests for the POS checkout controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;

/**
 * Checkout controller tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Checkout_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * It returns a completed state for POS cash checkout.
	 */
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
		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertArrayHasKey( 'checkout_id', $data );
		$this->assertArrayHasKey( 'provider_data', $data );
		$this->assertArrayHasKey( 'terminal', $data );
		$this->assertTrue( $data['terminal'] );
	}

	/**
	 * It returns the previously stored checkout state.
	 */
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
		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertArrayHasKey( 'checkout_id', $data );
		$this->assertArrayHasKey( 'provider_data', $data );
		$this->assertArrayHasKey( 'terminal', $data );
		$this->assertTrue( $data['terminal'] );
	}

	/**
	 * It scopes idempotent checkout replays to a single order.
	 */
	public function test_checkout_idempotency_key_is_scoped_per_order(): void {
		$key    = wp_generate_uuid4();
		$order1 = OrderHelper::create_order(
			array(
				'payment_method' => 'pos_cash',
				'total'          => '50.00',
			)
		);
		$order2 = OrderHelper::create_order(
			array(
				'payment_method' => 'pos_cash',
				'total'          => '30.00',
			)
		);

		$request1 = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order1->get_id() . '/checkout' );
		$request1->set_header( 'X-WCPOS-Idempotency-Key', $key );
		$request1->set_body_params(
			array(
				'gateway_id'   => 'pos_cash',
				'action'       => 'start',
				'payment_data' => array(
					'amount_tendered' => '50.00',
				),
			)
		);

		$request2 = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order2->get_id() . '/checkout' );
		$request2->set_header( 'X-WCPOS-Idempotency-Key', $key );
		$request2->set_body_params(
			array(
				'gateway_id'   => 'pos_cash',
				'action'       => 'start',
				'payment_data' => array(
					'amount_tendered' => '30.00',
				),
			)
		);

		$response1 = $this->server->dispatch( $request1 );
		$response2 = $this->server->dispatch( $request2 );

		$this->assertSame( 200, $response1->get_status() );
		$this->assertSame( 200, $response2->get_status() );
		$this->assertSame( $order1->get_id(), $response1->get_data()['order_id'] );
		$this->assertSame( $order2->get_id(), $response2->get_data()['order_id'] );
		$this->assertNotSame( $response1->get_data()['checkout_id'], $response2->get_data()['checkout_id'] );
	}

	/**
	 * It rejects gateways that are not available to POS checkout.
	 */
	public function test_checkout_rejects_gateway_not_available_for_pos(): void {
		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'bacs',
				'total'          => '50.00',
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/checkout' );
		$request->set_header( 'X-WCPOS-Idempotency-Key', wp_generate_uuid4() );
		$request->set_body_params(
			array(
				'gateway_id' => 'bacs',
				'action'     => 'start',
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpos_payment_gateway_not_available', $data['code'] );
	}

	/**
	 * It rejects negative tendered cash amounts.
	 */
	public function test_checkout_rejects_negative_tendered_amount(): void {
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
					'amount_tendered' => '-1.00',
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_tendered_amount', $data['code'] );
	}

	/**
	 * It rejects negative cashback amounts.
	 */
	public function test_checkout_rejects_negative_cashback_amount(): void {
		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'pos_card',
				'total'          => '50.00',
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/checkout' );
		$request->set_header( 'X-WCPOS-Idempotency-Key', wp_generate_uuid4() );
		$request->set_body_params(
			array(
				'gateway_id'   => 'pos_card',
				'action'       => 'start',
				'payment_data' => array(
					'cashback_amount' => '-1.00',
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_cashback_amount', $data['code'] );
	}
}
