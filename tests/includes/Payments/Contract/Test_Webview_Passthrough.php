<?php
/**
 * Webview payment passthrough tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Webview payment passthrough tests. */
class Test_Webview_Passthrough extends WCPOS_REST_Unit_Test_Case {
	/** Payment completion mints a captured webview row. */
	public function test_payment_complete_on_pos_order_mints_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'pos_card' );
		$order->set_transaction_id( 'ch_123' );
		$order->update_meta_data( '_pos_user', 123 );
		$order->save();

		// Act.
		$order->payment_complete();
		// The hook mints on its own wc_get_order() instance; re-read, as any later request would.
		$order = wc_get_order( $order->get_id() );
		$rows  = Ledger::instance()->read( $order );

		// Assert.
		$this->assertCount( 1, $rows );
		$this->assertSame( 'webview', $rows[0]['source'] );
		$this->assertSame( 'webview', $rows[0]['capture_mode'] );
		$this->assertSame( '92.95', $rows[0]['amount'] );
		$this->assertSame( 123, $rows[0]['cashier_id'] );
		$this->assertSame( 'ch_123', $rows[0]['provider_refs']['transaction_id'] );
		$this->assertSame( array( 'pos_card' ), array_values( wp_list_pluck( $order->get_meta( Ledger::INDEX_META_KEY, false ), 'value' ) ) );
	}

	/** Ledger-driven completion does not mint a duplicate webview row. */
	public function test_payment_complete_after_ledger_record_does_not_duplicate(): void {
		// Arrange: a live row that leaves the order short of its total, so the order is
		// still payable and payment_complete() actually fires the passthrough hook.
		$order = $this->create_order( true );
		Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '50.00',
			)
		);

		// Act.
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );

		// Assert.
		$rows = Ledger::instance()->read( $order );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pos_cash', $rows[0]['method_id'] );
		$this->assertSame( 'app', $rows[0]['source'] );
	}

	/** Non-POS payment completion is ignored. */
	public function test_payment_complete_on_non_pos_order_is_ignored(): void {
		// Arrange.
		$order = $this->create_order( false );
		$order->set_payment_method( 'pos_card' );
		$order->save();

		// Act.
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );

		// Assert.
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
	}

	/** A status edit during a POS request is not evidence of payment. */
	public function test_status_change_outside_order_pay_does_not_mint_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->in_pos_request(
			function () use ( $order ): void {
				// Act.
				$order->set_status( 'processing' );
				$order->save();

				// Assert.
				$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
			}
		);
	}

	/** An offline gateway landing a paid status from the order-pay form is captured. */
	public function test_order_pay_status_change_mints_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->in_pos_request(
			function () use ( $order ): void {
				// Act: WooCommerce fires this after the pay nonce and order key check,
				// immediately before it calls the gateway's process_payment().
				do_action( 'woocommerce_before_pay_action', $order );
				$order->set_status( 'processing' );
				$order->save();

				// Assert.
				$rows = Ledger::instance()->read( wc_get_order( $order->get_id() ) );
				$this->assertCount( 1, $rows );
				$this->assertSame( 'webview', $rows[0]['source'] );
			}
		);
	}

	/** An order-pay transition to an unpaid status does not mint a captured row. */
	public function test_order_pay_on_hold_status_does_not_mint_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->in_pos_request(
			function () use ( $order ): void {
				// Act.
				do_action( 'woocommerce_before_pay_action', $order );
				$order->set_status( 'on-hold' );
				$order->save();

				// Assert.
				$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
			}
		);
	}

	/**
	 * Run a callback with the POS request query var set.
	 *
	 * @param callable $callback Act and assert steps.
	 */
	private function in_pos_request( callable $callback ): void {
		$previous_query_vars                = $GLOBALS['wp']->query_vars;
		$GLOBALS['wp']->query_vars['wcpos'] = 1;

		try {
			$callback();
		} finally {
			$GLOBALS['wp']->query_vars = $previous_query_vars;
		}
	}

	/**
	 * Create an order at the passthrough contract total.
	 *
	 * @param bool $pos Whether the order is a POS order.
	 */
	private function create_order( bool $pos ): \WC_Order {
		$order = OrderHelper::create_order();
		if ( $pos ) {
			$order->set_created_via( 'woocommerce-pos' );
			$order->set_status( 'pos-open' );
		}
		$order->set_total( '92.95' );
		$order->save();

		return $order;
	}
}
