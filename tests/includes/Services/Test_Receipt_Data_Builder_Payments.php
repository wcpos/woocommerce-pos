<?php
/**
 * Tests for receipt data builder payments.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

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
	 * Test pending orders with a total emit no payment row.
	 */
	public function test_build_pending_order_with_total_emits_no_payment_row(): void {
		// Arrange.
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '11.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'pending' );
		$order->calculate_totals( true );
		$order->save();

		// Act.
		$data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Assert.
		$this->assertGreaterThan( 0, (float) $order->get_total() );
		$this->assertSame( array(), $data['payments'] );
		$this->assertTrue( $data['order']['needs_payment'] );
		$this->assertSame( 0.0, $data['totals']['paid_total'] );
	}

	/**
	 * Test completed orders emit one payment row.
	 */
	public function test_build_completed_order_emits_one_payment_row(): void {
		// Arrange.
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '11.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'completed' );
		$order->set_payment_method( 'cash' );
		$order->set_payment_method_title( 'Cash' );
		$order->calculate_totals( true );
		$order->save();

		// Act.
		$data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Assert.
		$this->assertCount( 1, $data['payments'] );
		$this->assertSame( 'Cash', $data['payments'][0]['method_title'] );
		$this->assertSame( (float) $order->get_total(), $data['payments'][0]['amount'] );
		$this->assertSame( (float) $order->get_total(), $data['totals']['paid_total'] );
		$this->assertFalse( $data['order']['needs_payment'] );
	}

	/**
	 * Test POS open orders emit no payment row.
	 */
	public function test_build_pos_open_order_emits_no_payment_row(): void {
		// Arrange.
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '11.00' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals( true );
		$order->set_status( 'pos-open' );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		// Act.
		$data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Assert.
		$this->assertSame( array(), $data['payments'] );
	}
}
