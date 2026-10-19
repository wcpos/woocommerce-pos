<?php
namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;
use WCPOS\WooCommercePOS\Payments\Contract\Settlement_Change;

class Test_Settlement_Change extends \WP_UnitTestCase {
	/** Tax lines are settlement lines: identity and money fields both count as a change. */
	public function test_tax_line_fields_compare_identity_and_money(): void {
		$order = new \WC_Order();
		$item = new \WC_Order_Item_Tax();
		$item->set_id( 42 );
		$item->set_rate_code( 'VAT' );
		$item->set_rate_id( 7 );
		$item->set_tax_total( '5' );
		$order->add_item( $item );
		$line = array(
			'id'                 => 42,
			'rate_id'            => 7,
			'rate_code'          => 'VAT',
			'tax_total'          => '5.000000',
			'shipping_tax_total' => '0.00',
			'compound'           => false,
		);
		$edits = array(
			'rate_id'            => 8,
			'rate_code'          => 'OTHER',
			'tax_total'          => '6',
			'shipping_tax_total' => '1',
			'compound'           => true,
		);
		$this->assertSame( array(), Settlement_Change::changes( array( 'tax_lines' => array( $line ) ), $order ) );
		foreach ( $edits as $key => $value ) {
			$this->assertSame( array( 'tax_lines' ), Settlement_Change::changes( array( 'tax_lines' => array( array_replace( $line, array( $key => $value ) ) ) ), $order ), $key );
		}
	}

	/** Numeric pairing must not let the line's sync identity bypass the epoch gate. */
	public function test_numeric_line_id_detects_uuid_replacement_and_first_assignment(): void {
		foreach ( array( 'line_items' => 'WC_Order_Item_Product', 'fee_lines' => 'WC_Order_Item_Fee', 'shipping_lines' => 'WC_Order_Item_Shipping', 'coupon_lines' => 'WC_Order_Item_Coupon' ) as $type => $class ) {
			$order = new \WC_Order();
			$item = new $class();
			$item->set_id( 42 );
			$order->add_item( $item );
			$uuid = wp_generate_uuid4();
			$line = array( 'id' => 42, 'meta_data' => array( array( 'key' => '_woocommerce_pos_uuid', 'value' => $uuid ) ) );
			$this->assertSame( array( $type ), Settlement_Change::changes( array( $type => array( $line ) ), $order ), 'First assignment changes identity.' );
			$item->update_meta_data( '_woocommerce_pos_uuid', $uuid );
			$this->assertSame( array(), Settlement_Change::changes( array( $type => array( $line ) ), $order ) );
			foreach ( array( wp_generate_uuid4(), null, '' ) as $replacement ) {
				$line['meta_data'][0]['value'] = $replacement;
				$this->assertSame( array( $type ), Settlement_Change::changes( array( $type => array( $line ) ), $order ) );
			}
			$this->assertSame( array(), Settlement_Change::changes( array( $type => array( array( 'id' => 42 ) ) ), $order ), 'Omitted meta is not a claim.' );
		}
	}

	/** @dataProvider taxed_item_types */
	public function test_rest_tax_rows_compare_by_rate_and_money( string $type, string $class ): void {
		$order = new \WC_Order();
		$order->update_meta_data( '_wcpos_money_epoch', '1' );
		$item = new $class();
		$item->set_id( 42 );
		$item->set_taxes( array( 'total' => array( 7 => '2', 3 => '1' ), 'subtotal' => array( 7 => '2', 3 => '1' ) ) );
		$order->add_item( $item );
		$line = array( 'id' => 42, 'taxes' => array( array( 'id' => 3, 'total' => '1.00', 'subtotal' => '1.00' ), array( 'id' => 7, 'total' => '2.00', 'subtotal' => '2.00' ) ) );
		$this->assertSame( array(), Settlement_Change::changes( array( $type => array( $line ) ), $order ) );
		$line['taxes'][0]['total'] = '1.50';
		$this->assertSame( array( $type ), Settlement_Change::changes( array( $type => array( $line ) ), $order ) );
		$line['taxes'] = array();
		$this->assertSame( array( $type ), Settlement_Change::changes( array( $type => array( $line ) ), $order ) );
		$item->set_taxes( array() );
		$this->assertSame( array(), Settlement_Change::changes( array( $type => array( $line ) ), $order ) );
	}
	public function taxed_item_types(): array {
		return array( array( 'line_items', 'WC_Order_Item_Product' ), array( 'fee_lines', 'WC_Order_Item_Fee' ), array( 'shipping_lines', 'WC_Order_Item_Shipping' ) );
	}
	public function test_malformed_ledger_wrappers_are_settlement_changes(): void {
		$order = new \WC_Order();
		$order->update_meta_data( '_wcpos_money_epoch', 1 );
		foreach ( array( array( 'schema' => 2, 'payments' => array() ), array( 'schema' => 1, 'payments' => 'invalid' ), null, '{}' ) as $value ) {
			$this->assertSame( array( '_wcpos_payments' ), Settlement_Change::changes( array( 'meta_data' => array( array( 'key' => '_wcpos_payments', 'value' => $value ) ) ), $order ) );
		}
	}
	/** @dataProvider shipping_identity_fields */
	public function test_shipping_identity_changes_are_detected( string $field, $value ): void {
		$order = new \WC_Order();
		$item = new \WC_Order_Item_Shipping();
		$item->set_id( 42 );
		$order->add_item( $item );
		$this->assertSame( array( 'shipping_lines' ), Settlement_Change::changes( array( 'shipping_lines' => array( array( 'id' => 42, $field => $value ) ) ), $order ) );
	}
	public function shipping_identity_fields(): array {
		return array( array( 'method_title', 'Express' ), array( 'instance_id', 9 ) );
	}
	public function test_stale_read_only_paid_dates_beside_note_are_not_settlement_changes(): void {
		$this->assertSame( array(), Settlement_Change::changes( array( 'customer_note' => 'Note', 'date_paid' => '2026-10-18T12:00:00', 'date_paid_gmt' => '2026-10-18T12:00:00' ), new \WC_Order() ) );
	}
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


	/** A string "false" set_paid is the same non-assertion the writer sees; only a true flag is settlement intent. */
	public function test_set_paid_reads_the_boolean_value(): void {
		$order = new \WC_Order();
		$this->assertSame( array(), Settlement_Change::changes( array( 'set_paid' => 'false' ), $order ) );
		$this->assertSame( array(), Settlement_Change::changes( array( 'set_paid' => false ), $order ) );
		$this->assertSame( array( 'set_paid' ), Settlement_Change::changes( array( 'set_paid' => 'true' ), $order ) );
		$this->assertSame( array( 'set_paid' ), Settlement_Change::changes( array( 'set_paid' => true ), $order ) );
	}
	public function test_malformed_line_entries_are_settlement_changes(): void {
		foreach ( array_keys( Settlement_Change::TYPES ) as $key ) {
			$this->assertSame( array( $key ), Settlement_Change::changes( array( $key => array( 'x' ) ), new \WC_Order() ) );
		}
	}
	public function test_ledger_projection_compares_extras_and_canonical_money(): void {
		$order = new \WC_Order();
		$row = array( 'id' => 'cash', 'amount' => '50', 'tendered' => '60', 'provider_refs' => array( 'a' => 1, 'b' => 2 ) );
		$order->update_meta_data( '_wcpos_payments', array( 'schema' => 1, 'payments' => array( $row ) ) );
		foreach ( array( 'method_id', 'currency', 'capture_mode', 'kind', 'transport', 'receipt', 'register_id', 'session_id', 'created_at_gmt', 'captured_at_gmt', 'recorded_offline', 'source', 'tendered' ) as $field ) {
			$changed = array_merge( $row, array( $field => 'different' ) );
			$this->assertSame( array( '_wcpos_payments' ), Settlement_Change::changes( array( 'meta_data' => array( array( 'key' => '_wcpos_payments', 'value' => array( 'schema' => 1, 'payments' => array( $changed ) ) ) ) ), $order ), $field );
		}
		$row['tendered'] = '60.000000';
		$row['provider_refs'] = array( 'b' => 2, 'a' => 1 );
		$row['closure_id'] = 'ignored';
		$this->assertSame( array(), Settlement_Change::changes( array( 'meta_data' => array( array( 'key' => '_wcpos_payments', 'value' => array( 'schema' => 1, 'payments' => array( $row ) ) ) ) ), $order ) );
	}

	public function test_malformed_ledger_entry_is_a_settlement_change(): void {
		$ledger = array( 'schema' => 1, 'payments' => array( 'x' ) );
		$this->assertSame( array( '_wcpos_payments' ), Settlement_Change::changes( array( 'meta_data' => array( array( 'key' => '_wcpos_payments', 'value' => $ledger ) ) ), new \WC_Order() ) );
	}
	public function test_matching_payment_identity_and_in_progress_status_are_unchanged(): void {
		$order = new \WC_Order();
		$order->set_status( 'pos-open' );
		$order->set_date_paid( '2026-10-18T12:00:00Z' );
		$payload = array( 'status' => 'pos-open', 'currency' => $order->get_currency(), 'payment_method' => '', 'payment_method_title' => '', 'transaction_id' => '', 'date_paid' => wc_rest_prepare_date_response( $order->get_date_paid(), false ), 'date_paid_gmt' => wc_rest_prepare_date_response( $order->get_date_paid() ) );
		$this->assertSame( array(), Settlement_Change::changes( $payload, $order ) );
	}

}
