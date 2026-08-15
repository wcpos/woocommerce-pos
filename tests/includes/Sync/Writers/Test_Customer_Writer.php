<?php
/** Customer writer tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use WCPOS\WooCommercePOS\API\V2\Writers\Customer_Writer;
use WP_UnitTestCase;

/** Pins customer tax-ID and billing-email shaping. */
class Test_Customer_Writer extends WP_UnitTestCase {
	/** Keep POS fields out of the wc/v3 forward. */
	public function test_prepare_create_shapes_customer_payload(): void {
		$prepared = ( new Customer_Writer() )->prepare_create(
			array( 'route' => '/wc/v3/customers' ),
			array( 'billing' => array( 'email' => '', 'first_name' => 'Walk-in' ), 'tax_ids' => array() ),
			static function () { return null; }
		);
		$this->assertSame( array( 'first_name' => 'Walk-in' ), $prepared['payload']['billing'] );
		$this->assertArrayNotHasKey( 'tax_ids', $prepared['payload'] );
	}
}
