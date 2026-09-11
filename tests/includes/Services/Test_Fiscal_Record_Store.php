<?php
/** Fiscal record storage tests. @package WCPOS\WooCommercePOS\Tests\Services */
namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;
use WP_UnitTestCase;

class Test_Fiscal_Record_Store extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		foreach ( Fiscal_Record_Store::TYPES as $type ) {
			delete_option( 'wcpos_fiscal_sequence_' . $type );
		}
	}

	public function test_install_and_sale_replay_preserve_original_row(): void {
		global $wpdb;
		$store = new Fiscal_Record_Store();
		$store->install();
		$this->assertSame( $store->table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $store->table_name() ) ) ) );
		$fields = array( 'type' => 'sale', 'order_id' => 123, 'number' => 7, 'payload' => array( 'amount' => '12.00', 'label' => 'Café / cash' ) );
		$first = $store->record( $fields );
		$this->assertIsArray( $first );
		$this->assertSame( $fields['payload'], $first['payload'] );
		$this->assertSame( hash( 'sha256', wp_json_encode( $fields['payload'] ) ), $first['checksum'] );
		$this->assertSame( Receipt_Snapshot_Store::checksum( wp_json_encode( $fields['payload'] ) ), $first['checksum'] );
		$this->assertSame( $first, $store->get( $first['id'] ) );
		$fields['number'] = 8;
		$fields['payload'] = array( 'changed' => true );
		$this->assertSame( $first, $store->record( $fields ) );
		$this->assertSame( 1, $store->count( array( 'order_id' => 123 ) ) );
		$this->assertSame( $first, $store->find_sale( 123 ) );
		$this->assertSame( 0, $first['print_count'] );
		$this->assertNull( $first['last_printed_at_gmt'] );
	}

	public function test_each_identity_is_write_once_and_sequences_are_per_type(): void {
		$store = new Fiscal_Record_Store();
		foreach ( array( 'refund' => 'refund_id', 'void' => 'payment_id', 'cancellation' => 'order_id', 'late_sale' => 'order_id', 'late_movement' => 'source_id', 'recount' => 'source_id' ) as $type => $key ) {
			$identity = in_array( $key, array( 'refund_id', 'order_id' ), true ) ? 321 : wp_generate_uuid4();
			$fields = array( 'type' => $type, $key => $identity, 'payload' => array() );
			$first = $store->record( $fields );
			$this->assertSame( 1, $first['number'], $type );
			$fields['payload'] = array( 'changed' => true );
			$this->assertSame( $first, $store->record( $fields ), $type );
		}
		$second = $store->record( array( 'type' => 'refund', 'refund_id' => 322, 'payload' => array() ) );
		$this->assertSame( 2, $second['number'] );
		$this->assertNull( $store->record( array( 'type' => 'unknown', 'payload' => array() ) ) );
		$this->assertNull( $store->record( array( 'type' => 'sale', 'number' => 9, 'payload' => array() ) ) );
	}

	public function test_list_filters_bounds_and_paging_share_count_predicates(): void {
		$store = new Fiscal_Record_Store();
		$register = wp_generate_uuid4();
		$session = wp_generate_uuid4();
		$closure = wp_generate_uuid4();
		$base = array( 'type' => 'refund', 'order_id' => 555, 'register_id' => $register, 'session_id' => $session, 'closure_id' => $closure, 'store_id' => 12, 'payload' => array() );
		$one = $store->record( array_merge( $base, array( 'refund_id' => 1 ) ) );
		$two = $store->record( array_merge( $base, array( 'refund_id' => 2, 'store_id' => 13 ) ) );
		$store->record( array( 'type' => 'void', 'payment_id' => wp_generate_uuid4(), 'order_id' => 556, 'payload' => array() ) );
		foreach ( array( 'order_id' => 555, 'register_id' => $register, 'session_id' => $session, 'closure_id' => $closure, 'type' => 'refund' ) as $key => $value ) {
			$this->assertSame( array( $two['id'], $one['id'] ), array_column( $store->list( array( $key => $value ) ), 'id' ) );
			$this->assertSame( 2, $store->count( array( $key => $value ) ) );
		}
		$this->assertSame( array( $one ), $store->list( array( 'store_id' => 12 ) ) );
		$this->assertSame( 2, $store->count( array( 'store_id' => array( 12, 13 ) ) ) );
		$this->assertSame( array(), $store->list( array( 'store_id' => array() ) ) );
		$this->assertSame( array( $one ), $store->list( array( 'order_id' => 555, 'page' => 2, 'per_page' => 1 ) ) );
		$this->assertSame( 2, $store->count( array( 'order_id' => 555, 'page' => 2, 'per_page' => 1 ) ) );
		$this->assertSame( 2, $store->count( array( 'order_id' => 555, 'after' => $one['received_at_gmt'], 'before' => $two['received_at_gmt'] ) ) );
		$this->assertSame( array(), $store->list( array( 'after' => '2999-01-01 00:00:00' ) ) );
		$this->assertSame( 0, $store->count( array( 'before' => '2000-01-01 00:00:00' ) ) );
	}
	public function test_sequence_reads_current_database_value_not_request_cache(): void {
		global $wpdb;
		$store = new Fiscal_Record_Store();
		$option = 'wcpos_fiscal_sequence_refund';
		$store->record( array( 'type' => 'refund', 'refund_id' => 801, 'payload' => array() ) );
		$this->assertSame( 1, (int) get_option( $option ) );
		// Another request advances the database while this request still caches 1.
		$wpdb->update( $wpdb->options, array( 'option_value' => '2' ), array( 'option_name' => $option ) );
		$row = $store->record( array( 'type' => 'refund', 'refund_id' => 802, 'payload' => array() ) );
		$this->assertIsArray( $row );
		$this->assertSame( 3, $row['number'] );

		$option = 'wcpos_fiscal_sequence_void';
		$this->assertFalse( get_option( $option ) );
		// A concurrent first insert must also invalidate the cached absence.
		$wpdb->insert( $wpdb->options, array( 'option_name' => $option, 'option_value' => '1', 'autoload' => 'no' ) );
		$row = $store->record( array( 'type' => 'void', 'payment_id' => wp_generate_uuid4(), 'payload' => array() ) );
		$this->assertIsArray( $row );
		$this->assertSame( 2, $row['number'] );
	}

	public function test_number_collision_never_returns_another_orders_sale(): void {
		global $wpdb;
		$store = new Fiscal_Record_Store();
		$first = $store->record( array( 'type' => 'sale', 'order_id' => 901, 'number' => 91, 'payload' => array() ) );
		$previous = $wpdb->suppress_errors();
		try {
			$this->assertNull( $store->record( array( 'type' => 'sale', 'order_id' => 902, 'number' => 91, 'payload' => array() ) ) );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		$this->assertSame( $first, $store->find_sale( 901 ) );
		$this->assertNull( $store->find_sale( 902 ) );
	}

	public function test_callable_payload_receives_number_and_array_payload_still_works(): void {
		$store = new Fiscal_Record_Store();
		$seen = array();
		$fields = array( 'type' => 'refund', 'refund_id' => 950, 'payload' => static function ( int $number ) use ( &$seen ): array {
			$seen[] = $number;
			return array( 'receipt_number' => (string) $number );
		} );
		$record = $store->record( $fields );
		$this->assertSame( array( 1 ), $seen );
		$this->assertSame( array( 'receipt_number' => '1' ), $record['payload'] );
		$this->assertSame( $record, $store->record( $fields ) );
		$this->assertSame( array( 1 ), $seen );
		$array = $store->record( array( 'type' => 'refund', 'refund_id' => 951, 'payload' => array( 'array' => true ) ) );
		$this->assertSame( 2, $array['number'] );
		$this->assertSame( array( 'array' => true ), $array['payload'] );
	}

	public function test_array_callable_payload_is_invoked_not_serialized(): void {
		$builder = new class() {
			public function build( int $number ): array {
				return array( 'number' => $number, 'document_type' => 'refund' );
			}
		};
		$record = ( new Fiscal_Record_Store() )->record( array( 'type' => 'refund', 'refund_id' => 952, 'payload' => array( $builder, 'build' ) ) );
		$this->assertSame( array( 'number' => 1, 'document_type' => 'refund' ), $record['payload'] );
	}

}
