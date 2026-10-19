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
	public function test_coupon_discount_divergence_pairs_by_id_or_unique_code_and_stamps(): void {
		$order = wc_create_order();
		$item = new \WC_Order_Item_Coupon();
		$item->set_code( 'sale' );
		$item->set_discount( '5' );
		$item->set_discount_tax( '1' );
		$order->add_item( $item );
		$order->save();
		foreach ( array( array( 'id' => $item->get_id(), 'code' => 'wrong' ), array( 'code' => 'sale' ) ) as $pair ) {
			$payload = array( 'coupon_lines' => array( $pair + array( 'discount' => '6', 'discount_tax' => '2' ) ) );
			$this->assertSame( array( 'coupon_lines[0].discount', 'coupon_lines[0].discount_tax' ), Money_Owner::divergence( $payload, null, $order ) );
		}
		Money_Owner::stamp( $payload, null, $order );
		$this->assertSame( 'server', wc_get_order( $order->get_id() )->get_meta( '_wcpos_money_owner' ) );
		$duplicate = new \WC_Order_Item_Coupon();
		$duplicate->set_code( 'sale' );
		$order->add_item( $duplicate );
		$this->assertSame( array(), Money_Owner::divergence( $payload, null, $order ) );
	}
	public function test_malformed_lines_are_skipped_by_money_owner(): void {
		foreach ( array( 'line_items', 'fee_lines', 'shipping_lines', 'coupon_lines' ) as $key ) {
			$this->assertSame( array(), Money_Owner::divergence( array( $key => array( 'x' ) ), null, new \WC_Order() ) );
		}
	}

}
