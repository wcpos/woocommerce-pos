<?php
/**
 * Tests for receipt data builder fallback payments.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt_Data_Builder_Payments class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt_Data_Builder_Payments extends WC_REST_Unit_Test_Case {
	/**
	 * Test pending orders do not report an unpaid total as a payment.
	 */
	public function test_payments_pending_order_returns_empty_list(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_status( 'pending' );
		$order->set_total( 25 );
		$order->save();

		// Act.
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Assert.
		$this->assertSame( array(), $receipt_data['payments'] );
		$this->assertTrue( $receipt_data['order']['needs_payment'] );
		$this->assertSame( 0.0, $receipt_data['totals']['paid_total'] );
	}

	/**
	 * Test completed orders retain the fallback payment row.
	 */
	public function test_payments_completed_order_returns_cash_payment(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_status( 'completed' );
		$order->set_total( 25 );
		$order->set_payment_method( 'cash' );
		$order->set_payment_method_title( 'Cash' );
		$order->save();

		// Act.
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Assert.
		$this->assertCount( 1, $receipt_data['payments'] );
		$this->assertSame( 'Cash', $receipt_data['payments'][0]['method_title'] );
		$this->assertSame( (float) $order->get_total(), $receipt_data['payments'][0]['amount'] );
		$this->assertSame( (float) $order->get_total(), $receipt_data['totals']['paid_total'] );
	}

	/**
	 * Test persisted POS-open orders do not report a fallback payment.
	 */
	public function test_payments_pos_open_order_returns_empty_list(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_status( 'pos-open' );
		$order->set_total( 25 );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		// Act.
		$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Assert.
		$this->assertSame( array(), $receipt_data['payments'] );
	}
}
