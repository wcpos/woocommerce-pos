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
	 * It releases the idempotency claim after a successful checkout.
	 */
	public function test_checkout_releases_idempotency_claim_after_success(): void {
		$key   = wp_generate_uuid4();
		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'pos_cash',
				'total'          => '50.00',
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/checkout' );
		$request->set_header( 'X-WCPOS-Idempotency-Key', $key );
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

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( get_option( $this->get_claim_option_key( $order->get_id(), $key ) ) );
	}

	/**
	 * It releases the idempotency claim when checkout processing throws.
	 */
	public function test_checkout_releases_idempotency_claim_when_gateway_throws(): void {
		$key   = wp_generate_uuid4();
		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'pos_cash',
				'total'          => '50.00',
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/checkout' );
		$request->set_header( 'X-WCPOS-Idempotency-Key', $key );
		$request->set_body_params(
			array(
				'gateway_id'   => 'pos_cash',
				'action'       => 'start',
				'payment_data' => array(
					'amount_tendered' => '50.00',
				),
			)
		);

		$callback = static function () {
			throw new \RuntimeException( 'boom' );
		};

		add_filter( 'wcpos_process_checkout_action_pos_cash', $callback, 1 );

		try {
			$this->server->dispatch( $request );
			$this->fail( 'Expected checkout processing to throw.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'boom', $exception->getMessage() );
			$this->assertFalse( get_option( $this->get_claim_option_key( $order->get_id(), $key ) ) );
		} finally {
			remove_filter( 'wcpos_process_checkout_action_pos_cash', $callback, 1 );
		}
	}

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

	/**
	 * It accepts a non-WCPOS provider when the gateway contract supports checkout.
	 */
	public function test_checkout_accepts_non_wcpos_provider_when_gateway_contract_supports_checkout(): void {
		$order = OrderHelper::create_order(
			array(
				'payment_method' => 'bacs',
				'total'          => '50.00',
			)
		);

		add_filter(
			'woocommerce_pos_payment_gateways_settings',
			static function ( $settings ) {
				$settings['gateways']['bacs']['enabled'] = true;
				return $settings;
			}
		);

		add_filter(
			'wcpos_payment_gateway_supports_checkout',
			static function ( $supports, $gateway ) {
				return 'bacs' === $gateway->id ? true : $supports;
			},
			10,
			2
		);

		add_filter(
			'wcpos_process_checkout_action_bacs',
			static function () use ( $order ) {
				return array(
					'checkout_id'   => 'chk_test',
					'order_id'      => $order->get_id(),
					'gateway_id'    => 'bacs',
					'status'        => 'completed',
					'provider_data' => array(),
					'terminal'      => true,
				);
			},
			10,
			0
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
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'bacs', $data['gateway_id'] );
	}

	/**
	 * Build the in-flight claim option key for an order-scoped checkout request.
	 *
	 * Keep this in sync with Checkout_Controller::get_idempotency_scope(), which
	 * currently returns 'checkout:' . $order_id before the hashed key suffix.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $key      Idempotency key.
	 */
	private function get_claim_option_key( int $order_id, string $key ): string {
		return 'wcpos_idempotency_claim_' . md5( 'checkout:' . $order_id . '|' . $key );
	}
}
