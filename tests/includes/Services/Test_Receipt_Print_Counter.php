<?php
/**
 * Receipt print counter tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */
namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Print_Counter;

/** Tests server counting and render-copy marking. */
class Test_Receipt_Print_Counter extends \WC_REST_Unit_Test_Case {
	/** The count persists across newly loaded order instances. */
	public function test_count_absent_meta_increments_and_saves(): void {
		$order = OrderHelper::create_order();
		$counter = new Receipt_Print_Counter();
		$this->assertSame( '', $order->get_meta( '_wcpos_receipt_print_count' ) );
		$this->assertSame( 1, $counter->count( $order ) );
		$this->assertSame( 2, $counter->count( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 2, (int) wc_get_order( $order->get_id() )->get_meta( '_wcpos_receipt_print_count' ) );
	}

	/** Marking handles boundaries without mutating the source data. */
	public function test_mark_boundaries_preserve_source(): void {
		$data = array( 'fiscal' => array( 'receipt_number' => 'FROZEN' ) );
		foreach ( array( array( -1, false, 0 ), array( 0, false, 0 ), array( 1, false, 0 ), array( 2, true, 1 ), array( 5, true, 4 ) ) as $case ) {
			$marked = ( new Receipt_Print_Counter() )->mark( $data, $case[0] );
			$this->assertSame( $case[1], $marked['fiscal']['is_reprint'] );
			$this->assertSame( $case[2], $marked['fiscal']['reprint_count'] );
			$this->assertSame( 'FROZEN', $marked['fiscal']['receipt_number'] );
			$this->assertArrayNotHasKey( 'is_reprint', $data['fiscal'] );
		}
	}
}
