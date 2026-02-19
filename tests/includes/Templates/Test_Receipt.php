<?php
/**
 * Tests for receipt template behavior.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Templates\Receipt;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt extends WC_REST_Unit_Test_Case {
	/**
	 * Test fiscal mode falls back to live data when snapshot is unavailable.
	 */
	public function test_get_receipt_data_fiscal_without_snapshot_returns_live_mode_payload(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$method = new \ReflectionMethod( Receipt::class, 'get_receipt_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $receipt, $order, 'fiscal' );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertEquals( 'live', $data['meta']['mode'] );
	}
}
