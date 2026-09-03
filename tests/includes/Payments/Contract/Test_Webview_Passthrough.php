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
		// Arrange.
		$order = $this->create_order( true );

		// Act.
		Ledger::instance()->record(
			$order,
			array(
				'id'        => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount'    => '92.95',
				'currency'  => 'USD',
			)
		);
		$order->payment_complete();
		$order = wc_get_order( $order->get_id() );

		// Assert.
		$this->assertCount( 1, Ledger::instance()->read( $order ) );
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

	/** A generic POS request changing status is not evidence of payment. */
	public function test_status_change_outside_order_pay_does_not_mint_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();
		$previous_query_vars = $GLOBALS['wp']->query_vars;
		$GLOBALS['wp']->query_vars['wcpos'] = 1;

		try {
			// Act.
			$order->set_status( 'processing' );
			$order->save();

			// Assert.
			$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		} finally {
			$GLOBALS['wp']->query_vars = $previous_query_vars;
		}
	}

	/** An offline gateway status transition from the matching order-pay form is captured. */
	public function test_order_pay_status_change_mints_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();
		$previous_query_vars = $GLOBALS['wp']->query_vars;
		$previous_post       = $_POST;
		$GLOBALS['wp']->query_vars['wcpos']     = 1;
		$GLOBALS['wp']->query_vars['order-pay'] = $order->get_id();
		$_POST['woocommerce_pay']               = '1';

		try {
			// Act.
			$order->set_status( 'processing' );
			$order->save();

			// Assert.
			$this->assertCount( 1, Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		} finally {
			$GLOBALS['wp']->query_vars = $previous_query_vars;
			$_POST                       = $previous_post;
		}
	}

	/** An order-pay transition that is not paid does not mint a captured row. */
	public function test_order_pay_on_hold_status_does_not_mint_webview_row(): void {
		// Arrange.
		$order = $this->create_order( true );
		$order->set_payment_method( 'bacs' );
		$order->save();
		$previous_query_vars = $GLOBALS['wp']->query_vars;
		$previous_post       = $_POST;
		$GLOBALS['wp']->query_vars['wcpos']     = 1;
		$GLOBALS['wp']->query_vars['order-pay'] = $order->get_id();
		$_POST['woocommerce_pay']               = '1';

		try {
			// Act.
			$order->set_status( 'on-hold' );
			$order->save();

			// Assert.
			$this->assertSame( array(), Ledger::instance()->read( wc_get_order( $order->get_id() ) ) );
		} finally {
			$GLOBALS['wp']->query_vars = $previous_query_vars;
			$_POST                       = $previous_post;
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
