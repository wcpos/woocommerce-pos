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

		// Assert.
		$this->assertSame( array(), Ledger::instance()->read( $order ) );
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
