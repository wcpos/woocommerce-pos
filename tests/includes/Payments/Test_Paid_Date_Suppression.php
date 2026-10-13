<?php
/**
 * Tests for order-scoped paid-date suppression.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments
 */

namespace WCPOS\WooCommercePOS\Tests\Payments;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Payments\Paid_Date_Suppression;

/**
 * Test the shared predicate and filter factory.
 *
 * @covers \WCPOS\WooCommercePOS\Payments\Paid_Date_Suppression
 */
class Test_Paid_Date_Suppression extends \WC_Unit_Test_Case {
	/**
	 * Suppression requires the target status and an empty paid date.
	 */
	public function test_paid_date_suppression_withholds_only_while_the_order_reads_the_status_without_date_paid(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_status( 'on-hold' );
		$order->set_date_paid( null );
		$order->save();

		// Act / Assert.
		$this->assertTrue( Paid_Date_Suppression::withholds( $order, 'on-hold' ) );
		$this->assertFalse( Paid_Date_Suppression::withholds( $order, 'pending' ) );
		$this->assertFalse( Paid_Date_Suppression::withholds( $order, '' ) );
		$this->assertFalse( Paid_Date_Suppression::withholds( null, 'on-hold' ) );

		// Act.
		$order->set_date_paid( time() );

		// Assert.
		$this->assertFalse( Paid_Date_Suppression::withholds( $order, 'on-hold' ) );
	}

	/**
	 * The filter leaves other orders and missing order arguments untouched.
	 */
	public function test_paid_date_suppression_filter_answers_empty_only_for_its_own_order(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->set_status( 'on-hold' );
		$order->set_date_paid( null );
		$order->save();
		$other = OrderHelper::create_order();
		$other->set_status( 'on-hold' );
		$other->set_date_paid( null );
		$other->save();

		// Act.
		$f = Paid_Date_Suppression::filter_for( $order->get_id(), 'on-hold' );

		// Assert.
		$this->assertSame( '', $f( 'on-hold', $order->get_id(), $order ) );
		$this->assertSame( 'on-hold', $f( 'on-hold', $other->get_id(), $other ) );
		$this->assertSame( 'on-hold', $f( 'on-hold', $order->get_id() ) );
	}
}
