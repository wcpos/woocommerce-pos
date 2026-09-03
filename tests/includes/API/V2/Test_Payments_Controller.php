<?php
/**
 * Payments controller tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Payments controller tests. */
class Test_Payments_Controller extends WCPOS_REST_Unit_Test_Case {
	/** Recording cash returns the captured row and paid order summary. */
	public function test_record_cash_payment_returns_row_and_summary(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95', array( 'tendered' => '100.00' ) );

		// Act.
		$response = $this->record( $order, $payment );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'captured', $data['payment']['status'] );
		$this->assertSame( '7.05', $data['payment']['change'] );
		$this->assertSame( '0.00', $data['order']['balance'] );
		$this->assertSame( 'completed', $data['order']['status'] );
		$this->assertSame( 'pos_cash', $data['order']['payment_method'] );
	}

	/** An identical id replays without duplicating the ledger row. */
	public function test_record_replay_same_id_returns_same_row_without_duplicate(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '92.95' );

		// Act.
		$first  = $this->record( $order, $payment );
		$second = $this->record( $order, $payment );

		// Assert.
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first->get_data()['payment']['id'], $second->get_data()['payment']['id'] );
		$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
	}

	/** A second payment on a paid order returns its stable failed row. */
	public function test_record_overpay_returns_409_with_failed_row(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$this->record( $order, $this->payment( 'pos_cash', '92.95' ) );

		// Act.
		$response = $this->record( $order, $this->payment( 'pos_cash', '10.00' ) );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_order_already_paid', $data['code'] );
		$this->assertSame( 'failed', $data['data']['payment']['status'] );
		$this->assertSame( 'order_already_paid', $data['data']['payment']['failure_reason'] );
	}

	/** A reused id with different money conflicts. */
	public function test_record_conflicting_replay_returns_409_conflict(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );

		// Act.
		$response = $this->record( $order, array_merge( $payment, array( 'amount' => '21.00' ) ) );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_payment_conflict', $response->get_data()['code'] );
	}

	/** Unknown payment methods return the ledger error. */
	public function test_record_unknown_method_returns_404(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$response = $this->record( $order, $this->payment( 'missing_gateway', '20.00' ) );

		// Assert.
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_payment_method_not_found', $response->get_data()['code'] );
	}

	/** Status returns the matching row and order summary. */
	public function test_status_returns_row_and_summary(): void {
		// Arrange.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( $this->payment_path( $order, $payment['id'] ) . '/status' ) );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $payment['id'], $data['payment']['id'] );
		$this->assertSame( '20.00', $data['order']['paid'] );
	}

	/** Status returns 404 for an unknown UUID. */
	public function test_status_unknown_uuid_returns_404(): void {
		// Arrange.
		$order = $this->create_pos_order();

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( $this->payment_path( $order, wp_generate_uuid4() ) . '/status' ) );

		// Assert.
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_payment_not_found', $response->get_data()['code'] );
	}

	/** Voiding the only pending row returns the order to POS open. */
	public function test_void_pending_row_returns_order_to_open(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$row   = $this->payment(
			'pos_cash',
			'10.00',
			array(
				'status'       => 'pending',
				'kind'         => 'cash',
				'capture_mode' => 'manual',
			)
		);
		Ledger::instance()->save( $order, array( $row ) );

		// Act.
		$response = $this->void( $order, $row['id'] );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'voided', $data['payment']['status'] );
		$this->assertSame( 'pos-open', $data['order']['status'] );
	}

	/** Captured rows cannot be voided. */
	public function test_void_captured_cash_leg_mid_split_returns_200_voided(): void {
		// Arrange: a partial cash leg on an order still in progress (cancel mid-split).
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', '20.00' );
		$this->record( $order, $payment );

		// Act.
		$response = $this->void( $order, $payment['id'] );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'voided', $response->get_data()['status'] );
	}

	public function test_void_captured_cash_leg_on_completed_order_returns_409(): void {
		// Arrange: cash covering the whole order completes it; a void is no longer the path.
		$order   = $this->create_pos_order();
		$payment = $this->payment( 'pos_cash', (string) $order->get_total() );
		$this->record( $order, $payment );

		// Act.
		$response = $this->void( $order, $payment['id'] );

		// Assert.
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpos_invalid_transition', $response->get_data()['code'] );
	}

	/** Payment routes additionally require order publishing capability. */
	public function test_routes_require_publish_shop_orders(): void {
		// Arrange.
		$order = $this->create_pos_order();
		$user  = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user->ID );

		// Act.
		$response = $this->record( $order, $this->payment( 'pos_cash', '20.00' ) );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/** Create an open POS order at the contract total. */
	private function create_pos_order(): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_total( '92.95' );
		$order->save();

		return $order;
	}

	/**
	 * Build a valid payment request row.
	 *
	 * @param string $method_id Payment method ID.
	 * @param string $amount    Payment amount.
	 * @param array  $extra     Extra row fields.
	 */
	private function payment( string $method_id, string $amount, array $extra = array() ): array {
		return array_merge(
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => $method_id,
				'amount'    => $amount,
				'currency'  => 'USD',
			),
			$extra
		);
	}

	/**
	 * Dispatch the payment record request.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param array     $payment Payment row.
	 */
	private function record( \WC_Order $order, array $payment ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/payments' );
		$request->set_body_params( array( 'payment' => $payment ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch a payment void request.
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $id    Payment UUID.
	 */
	private function void( \WC_Order $order, string $id ): \WP_REST_Response {
		$request = $this->wp_rest_post_request( $this->payment_path( $order, $id ) . '/void' );
		$request->set_body_params( array( 'reason' => 'Customer cancelled' ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Build a payment-row route prefix.
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $id    Payment UUID.
	 */
	private function payment_path( \WC_Order $order, string $id ): string {
		return '/wcpos/v2/orders/' . $order->get_id() . '/payments/' . $id;
	}
}
