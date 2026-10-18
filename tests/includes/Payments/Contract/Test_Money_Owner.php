<?php
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Money_Owner;

class Test_Money_Owner extends \WP_UnitTestCase {
	public function test_divergence_compares_only_claimed_numeric_fields(): void {
		$this->assertTrue( class_exists( Money_Owner::class ) );
		$order = new \WC_Order();
		$order->set_total( '50.07' );
		$this->assertSame( array(), Money_Owner::divergence( array(), array( 'total' => '50.070001', 'unknown' => '5', 'cart_tax' => array() ), $order ) );
		$this->assertSame( array( 'total' ), Money_Owner::divergence( array(), array( 'total' => '50.00' ), $order ) );
		$this->assertSame( array(), Money_Owner::divergence( array(), array( 'total' => 50 ), $order ) );
		$this->assertSame( array(), Money_Owner::divergence( array(), array( 'total' => '50.0700014' ), $order ) );
		$this->assertSame( array( 'total' ), Money_Owner::divergence( array(), array( 'total' => '50.0700015' ), $order ) );
	}

	public function test_line_pairing_requires_id_or_unique_uuid_and_tolerates_one_micro_unit(): void {
		$this->assertTrue( class_exists( Money_Owner::class ) );
		$order = new \WC_Order();
		$item = new \WC_Order_Item_Product();
		$item->set_total( '50' );
		$item->update_meta_data( '_woocommerce_pos_uuid', 'item-uuid' );
		$order->add_item( $item );
		$line = array( 'meta_data' => array( array( 'key' => '_woocommerce_pos_uuid', 'value' => 'item-uuid' ) ), 'total' => '50.000001' );
		$this->assertSame( array(), Money_Owner::divergence( array( 'line_items' => array( $line ) ), null, $order ) );
		$line['total'] = '50.000002';
		$this->assertCount( 1, Money_Owner::divergence( array( 'line_items' => array( $line ) ), null, $order ) );
		$duplicate = clone $item;
		$order->add_item( $duplicate );
		$this->assertSame( array(), Money_Owner::divergence( array( 'line_items' => array( $line ) ), null, $order ) );
	}
}
