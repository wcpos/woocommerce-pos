<?php
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;
use WCPOS\WooCommercePOS\Payments\Contract\Settlement_Change;

class Test_Settlement_Change extends \WP_UnitTestCase {
	public function test_sparse_payload_only_detects_changed_settlement_intent(): void {
		$this->assertTrue( class_exists( Settlement_Change::class ) );
		$order = new \WC_Order();
		$item = new \WC_Order_Item_Product();
		$item->set_id( 42 );
		$item->set_quantity( 2 );
		$item->set_total( '50' );
		$order->add_item( $item );
		$this->assertSame( array(), Settlement_Change::changes( array( 'status' => 'pos-open', 'customer_note' => 'note', 'meta_data' => array( array( 'key' => 'ordinary', 'value' => 1 ) ) ), $order ) );
		$this->assertSame( array(), Settlement_Change::changes( array( 'line_items' => array( array( 'id' => 42, 'quantity' => '2', 'total' => '50.000000' ) ) ), $order ) );
		$this->assertSame( array( 'line_items' ), Settlement_Change::changes( array( 'line_items' => array( array( 'id' => 42, 'quantity' => 3 ) ) ), $order ) );
		$this->assertSame( array( 'line_items' ), Settlement_Change::changes( array( 'line_items' => array() ), $order ) );
		$this->assertSame( array( 'customer_id', 'billing' ), Settlement_Change::changes( array( 'customer_id' => 8, 'billing' => array( 'city' => 'Madrid' ) ), $order ) );
		$rows = array( array( 'id' => 'cash', 'amount' => '50', 'status' => 'captured' ) );
		$order->update_meta_data( '_wcpos_payments', array( 'schema' => 1, 'payments' => $rows ) );
		$payload = array( 'meta_data' => array( array( 'key' => '_wcpos_payments', 'value' => array( 'schema' => 1, 'payments' => $rows ) ) ) );
		$this->assertSame( array(), Settlement_Change::changes( $payload, $order ) );
		$payload['meta_data'][0]['value']['payments'][0]['amount'] = '51';
		$this->assertSame( array( '_wcpos_payments' ), Settlement_Change::changes( $payload, $order ) );
	}
	/** @dataProvider settlement_fields */
	public function test_tax_and_decimal_edits_are_detected( string $type, string $field, $value, bool $changed ): void {
		$order = new \WC_Order();
		$classes = array( 'line_items' => 'WC_Order_Item_Product', 'fee_lines' => 'WC_Order_Item_Fee', 'shipping_lines' => 'WC_Order_Item_Shipping' );
		$item = new $classes[ $type ]();
		$item->set_id( 42 );
		$item->set_total( '50' );
		if ( 'line_items' === $type ) {
			$item->set_quantity( 50 );
		}
		$order->add_item( $item );
		$line = array( 'id' => 42, $field => $value );
		$this->assertSame( $changed ? array( $type ) : array(), Settlement_Change::changes( array( $type => array( $line ) ), $order ) );
	}
	public function settlement_fields(): array {
		return array(
			array( 'line_items', 'total', '5e1', true ),
			array( 'line_items', 'total', '50.000000', false ),
			array( 'line_items', 'quantity', '5e1', true ),
			array( 'fee_lines', 'total', '5e1', true ),
			array( 'shipping_lines', 'total', '5e1', true ),
			array( 'line_items', 'tax_class', 'reduced-rate', true ),
			array( 'fee_lines', 'tax_class', 'reduced-rate', true ),
			array( 'fee_lines', 'tax_status', 'none', true ),
			array( 'shipping_lines', 'method_id', 'flat_rate', true ),
			array( 'line_items', 'meta_data', array( array( 'key' => '_woocommerce_pos_data', 'value' => array( 'tax_status' => 'none' ) ) ), true ),
		);
	}
	/** @dataProvider ledger_fields */
	public function test_ledger_epoch_refund_and_decimal_edits_are_detected( string $field, $value ): void {
		$order = new \WC_Order();
		$ledger = array( 'schema' => 1, 'payments' => array( array( 'id' => 'cash', 'status' => 'captured', 'amount' => '50', 'refunded_amount' => '50', 'epoch' => 0 ) ) );
		$order->update_meta_data( '_wcpos_payments', $ledger );
		$ledger['payments'][0][ $field ] = $value;
		$this->assertSame( array( '_wcpos_payments' ), Settlement_Change::changes( array( 'meta_data' => array( array( 'key' => '_wcpos_payments', 'value' => $ledger ) ) ), $order ) );
	}
	public function ledger_fields(): array {
		return array( array( 'epoch', 1 ), array( 'refunded_amount', '0' ), array( 'amount', '5e1' ), array( 'refunded_amount', '5e1' ) );
	}
	public function test_order_tax_basis_is_settlement_intent(): void {
		$this->assertSame( array( '_woocommerce_pos_tax_based_on' ), Settlement_Change::changes( array( 'meta_data' => array( array( 'key' => '_woocommerce_pos_tax_based_on', 'value' => 'billing' ) ) ), new \WC_Order() ) );
	}

	/** @dataProvider writable_money_fields */
	public function test_remaining_item_money_fields_are_settlement_changes( string $type, string $class, string $field, $value ): void {
		$order = new \WC_Order();
		$item = new $class();
		$item->set_id( 42 );
		$order->add_item( $item );
		$this->assertSame( array( $type ), Settlement_Change::changes( array( $type => array( array( 'id' => 42, $field => $value ) ) ), $order ) );
	}
	public function writable_money_fields(): array {
		$cases = array();
		foreach ( array( 'line_items' => 'WC_Order_Item_Product', 'fee_lines' => 'WC_Order_Item_Fee', 'shipping_lines' => 'WC_Order_Item_Shipping', 'coupon_lines' => 'WC_Order_Item_Coupon' ) as $type => $class ) {
			$fields = 'coupon_lines' === $type ? array( 'discount', 'discount_tax' ) : array( 'total_tax', 'taxes' );
			if ( 'line_items' === $type ) { $fields[] = 'subtotal_tax'; }
			foreach ( $fields as $field ) {
				foreach ( 'taxes' === $field ? array( array( 'total' => array( 1 => '1' ) ) ) : array( '1', '0e1' ) as $value ) {
					$cases[] = array( $type, $class, $field, $value );
				}
			}
		}
		return $cases;
	}
	public function test_duplicate_ledger_rows_are_a_settlement_change(): void {
		$order = new \WC_Order();
		$row = array( 'id' => 'cash', 'status' => 'captured', 'amount' => '50' );
		$order->update_meta_data( '_wcpos_payments', array( 'schema' => 1, 'payments' => array( $row ) ) );
		$this->assertSame( array( '_wcpos_payments' ), Settlement_Change::changes( array( 'meta_data' => array( array( 'key' => '_wcpos_payments', 'value' => array( 'schema' => 1, 'payments' => array( $row, $row ) ) ) ) ), $order ) );
	}

}
