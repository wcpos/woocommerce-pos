<?php
/**
 * Sale_State tests, at the Ledger::sale_state() interface.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact scenarios.

use WCPOS\WooCommercePOS\Payments\Contract\Attempts;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\Ledger_Seed;

/**
 * @covers \WCPOS\WooCommercePOS\Payments\Contract\Sale_State
 * @covers \WCPOS\WooCommercePOS\Payments\Contract\Ledger::sale_state
 */
class Test_Sale_State extends WCPOS_REST_Unit_Test_Case {
	protected function sale( string $status ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( ProductHelper::create_simple_product(), 1 );
		$order->set_created_via( 'woocommerce-pos' );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	protected function paid_sale( string $status ): \WC_Order {
		$order = $this->sale( 'pending' );
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );
		if ( $status !== $order->get_status() ) {
			$order->set_status( $status );
			$order->save();
		}

		return wc_get_order( $order->get_id() );
	}

	protected function row( string $status, array $extra = array() ): array {
		return array(
			'id'        => wp_generate_uuid4(),
			'method_id' => 'pos_card',
			'kind'      => 'card',
			'amount'    => '5.00',
			'status'    => $status,
		) + $extra;
	}

	protected function with_rows( \WC_Order $order, array $rows ): \WC_Order {
		Ledger_Seed::rows( $order, $rows, false );

		return wc_get_order( $order->get_id() );
	}

	/** Write a standing completion entry (latest, not reopened) onto the order. */
	protected function standing_completion( \WC_Order $order ): \WC_Order {
		$order->update_meta_data(
			Completion_Stamp::META_KEY,
			wp_json_encode(
				array(
					array(
						'n'      => 1,
						'id'     => wp_generate_uuid4(),
						'at_gmt' => gmdate( 'c' ),
					),
				)
			)
		);
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	public function test_sale_state_with_a_pending_row_names_it_in_motion(): void {
		// Arrange.
		$row   = $this->row( 'pending' );
		$order = $this->with_rows( $this->sale( 'pos-open' ), array( $this->row( 'captured' ), $row ) );

		// Act.
		$state = Ledger::instance()->sale_state( $order );

		// Assert.
		$this->assertSame(
			array(
				'payment_id' => $row['id'],
				'attempt_id' => null,
			),
			$state->in_motion()
		);
	}

	public function test_sale_state_with_a_pending_refund_allocation_names_its_row_in_motion(): void {
		// Arrange.
		$row   = $this->row(
			'captured',
			array(
				'refunds' => array(
					array(
						'id'     => 1234,
						'amount' => '2.00',
						'status' => 'pending',
					),
				),
			)
		);
		$order = $this->with_rows( $this->sale( 'pos-open' ), array( $row ) );

		// Act.
		$motion = Ledger::instance()->sale_state( $order )->in_motion();

		// Assert.
		$this->assertSame( $row['id'], $motion['payment_id'] );
	}

	public function test_sale_state_with_a_sent_attempt_names_the_attempt_in_motion(): void {
		// Arrange.
		$attempt = wp_generate_uuid4();
		$order   = $this->sale( 'pos-open' );
		Ledger_Seed::attempt( $order, $attempt, 'pos_cash', 'sent' );

		// Act.
		$state = Ledger::instance()->sale_state( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame(
			array(
				'payment_id' => null,
				'attempt_id' => $attempt,
			),
			$state->in_motion()
		);
		$this->assertTrue( $state->has_money() );
	}

	public function test_sale_state_with_captured_and_voided_rows_has_nothing_in_motion(): void {
		// Arrange.
		$order = $this->with_rows( $this->sale( 'pos-open' ), array( $this->row( 'captured' ), $this->row( 'voided' ), $this->row( 'failed' ) ) );

		// Act.
		$state = Ledger::instance()->sale_state( $order );

		// Assert.
		$this->assertNull( $state->in_motion() );
		$this->assertTrue( $state->has_money() );
	}

	public function test_sale_state_on_a_paid_processing_order_is_locked_and_reopens_to_pos_open(): void {
		// Arrange.
		$order = $this->paid_sale( 'processing' );

		// Act.
		$state = Ledger::instance()->sale_state( $order );

		// Assert.
		$this->assertTrue( $state->locked() );
		$this->assertFalse( $state->open() );
		$this->assertTrue( $state->reopens( 'pos-open' ) );
		$this->assertTrue( $state->reopens( 'on-hold' ) );
	}

	public function test_sale_state_on_a_completion_standing_on_on_hold_is_locked(): void {
		// Arrange: a merchant's POS status stored as on-hold, which is_editable() allows.
		$order = $this->standing_completion( $this->sale( 'on-hold' ) );

		// Act.
		$state = Ledger::instance()->sale_state( $order );

		// Assert.
		$this->assertTrue( $order->is_editable() );
		$this->assertTrue( $state->stands() );
		$this->assertTrue( $state->locked() );
		$this->assertTrue( $state->open() );
		$this->assertTrue( $state->reopens( 'pos-open' ) );
		$this->assertFalse( $state->takes_tender() );
		$this->assertTrue( $state->already_paid() );
	}

	public function test_sale_state_reopens_is_false_for_cancelled_and_for_the_current_status(): void {
		// Arrange.
		$order = $this->standing_completion( $this->paid_sale( 'on-hold' ) );

		// Act.
		$state = Ledger::instance()->sale_state( $order );

		// Assert.
		$this->assertTrue( $state->locked() );
		$this->assertFalse( $state->reopens( 'cancelled' ) );
		$this->assertFalse( $state->reopens( 'on-hold' ) );
		$this->assertFalse( $state->reopens( 'processing' ) );
	}

	public function test_sale_state_on_a_gateway_paid_order_without_rows_has_money(): void {
		// Arrange: paid by a WooCommerce gateway outside the ledger.
		$order = $this->sale( 'pos-open' );
		$order->set_payment_method( 'bacs' );
		$order->set_transaction_id( 'txn_1' );
		$order->set_date_paid( time() - HOUR_IN_SECONDS );
		$order->set_status( 'processing' );
		$order->save();

		// Act.
		$state = Ledger::instance()->sale_state( wc_get_order( $order->get_id() ) );

		// Assert.
		$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		$this->assertTrue( $state->has_money() );
		$this->assertNull( $state->in_motion() );
		$this->assertTrue( $state->already_paid() );
	}

	public function test_sale_state_on_a_fresh_cart_has_no_money_and_takes_tender(): void {
		// Arrange.
		$order = $this->sale( 'pos-open' );

		// Act.
		$state = Ledger::instance()->sale_state( $order );

		// Assert.
		$this->assertFalse( $state->has_money() );
		$this->assertTrue( $state->takes_tender() );
		$this->assertFalse( $state->already_paid() );
		$this->assertFalse( $state->locked() );
		$this->assertTrue( $state->open() );
		$this->assertNull( $state->in_motion() );
		$this->assertFalse( $state->reopens( 'pending' ) );
	}

	public function test_sale_state_on_a_cancelled_paid_sale_takes_no_tender_and_is_already_paid(): void {
		// Arrange.
		$order = $this->paid_sale( 'cancelled' );

		// Act.
		$state = Ledger::instance()->sale_state( $order );

		// Assert.
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertFalse( $state->takes_tender() );
		$this->assertTrue( $state->already_paid() );
		$this->assertFalse( $state->open() );
		$this->assertFalse( $state->stands() );
	}
}
