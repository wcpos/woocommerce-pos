<?php
/** Order writer tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use WCPOS\WooCommercePOS\API\V2\Writers\Order_Writer;
use WP_UnitTestCase;

/** Pins server-authoritative order shaping. */
class Test_Order_Writer extends WP_UnitTestCase {
	/** Strip client-owned copies of server fields. */
	public function test_prepare_create_shapes_order_audit_fields(): void {
		$prepared = ( new Order_Writer() )->prepare_create(
			array( 'route' => '/wc/v3/orders' ),
			array( 'tax_ids' => array(), 'meta_data' => array( array( 'key' => '_pos_user', 'value' => '999' ), array( 'key' => 'public', 'value' => 'kept' ) ) ),
			static function () { return null; }
		);
		$this->assertSame( 'woocommerce-pos', $prepared['payload']['created_via'] );
		$this->assertArrayNotHasKey( 'tax_ids', $prepared['payload'] );
		$this->assertSame( array( array( 'key' => 'public', 'value' => 'kept' ) ), $prepared['payload']['meta_data'] );
	}
}
