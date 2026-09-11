<?php
/** Shared provenance diagnostics cases. @package WCPOS\WooCommercePOS\Tests\Services */
namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Provenance_Health;

trait Provenance_Health_Tests {

	private function provenance_order( string $register, string $counter = '1', string $sale = '', string $received = '', ?int $created = null ): int {
		$order = new \WC_Order();
		$order->set_date_created( $created ?? time() );
		foreach ( array( '_wcpos_register' => $register, '_wcpos_sale_counter' => $counter, '_wcpos_sale_time' => $sale, '_wcpos_sale_received_gmt' => $received ) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		return $order->save();
	}

	public function test_health_counters_report_gaps_and_duplicates(): void {
		$id = wp_generate_uuid4();
		$ids = array();
		foreach ( array( 1, 2, 3, 5, 5, 8 ) as $counter ) {
			$ids[] = $this->provenance_order( $id, (string) $counter );
		}
		$number = static function ( $number, $order ) { return 'SALE-' . $order->get_id(); };
		add_filter( 'woocommerce_order_number', $number, 10, 2 );
		try {
			$report = ( new Provenance_Health() )->report( array( array( 'id' => $id, 'name' => 'Front' ) ), array( $id ) );
		} finally {
			remove_filter( 'woocommerce_order_number', $number );
		}
		$row = $report['registers'][0];
		$this->assertSame( 6, $row['orders'] );
		$this->assertSame( 1, $row['first_counter'] );
		$this->assertSame( 8, $row['last_counter'] );
		$this->assertSame(
			array(
				array(
					'till' => $id,
					'after' => 3,
					'before' => 5,
					'missing' => 1,
				),
				array(
					'till' => $id,
					'after' => 5,
					'before' => 8,
					'missing' => 2,
				),
			),
			$row['gaps']
		);
		$this->assertCount( 1, $row['duplicates'] );
		$this->assertSame( 5, $row['duplicates'][0]['counter'] );
		$this->assertSame( $id, $row['duplicates'][0]['till'] );
		$this->assertEqualsCanonicalizing( array( $ids[3], $ids[4] ), $row['duplicates'][0]['order_ids'] );
		$this->assertSame( array_map( static function ( $id ) { return 'SALE-' . $id; }, $row['duplicates'][0]['order_ids'] ), $row['duplicates'][0]['order_numbers'] );
	}

	public function test_health_clean_and_empty_registers_preserve_input_order(): void {
		$id = wp_generate_uuid4();
		$empty = wp_generate_uuid4();
		foreach ( array( '11', '12', '13' ) as $counter ) {
			$this->provenance_order( $id, $counter );
		}
		$report = ( new Provenance_Health() )->report( array( array( 'id' => $empty, 'name' => 'Empty' ), array( 'id' => $id, 'name' => 'Clean' ) ), array( $empty, $id ) );
		$this->assertSame( array( $empty, $id ), array_column( $report['registers'], 'id' ) );
		$this->assertSame( array( 'Empty', 'Clean' ), array_column( $report['registers'], 'name' ) );
		$this->assertSame( 0, $report['registers'][0]['orders'] );
		$this->assertNull( $report['registers'][0]['first_counter'] );
		$this->assertNull( $report['registers'][0]['last_counter'] );
		foreach ( $report['registers'] as $row ) {
			$this->assertSame( array(), $row['gaps'] );
			$this->assertSame( array(), $row['duplicates'] );
			$this->assertSame( array(), $row['skew'] );
		}
	}

	public function test_health_skew_uses_offsets_and_orders_by_magnitude(): void {
		$id = wp_generate_uuid4();
		$received = '2026-09-10T22:42:10Z';
		$ahead = $this->provenance_order( $id, '1', '2026-09-11T00:57:10+02:00', $received );
		$behind = $this->provenance_order( $id, '2', '2026-09-10T22:42:10+02:00', $received );
		$this->provenance_order( $id, '3', '2026-09-11T00:42:40+02:00', $received );
		$this->provenance_order( $id, '4', '2026-09-11T00:52:10+02:00', $received );
		$this->provenance_order( $id, '5', 'not-a-time', $received );
		$this->provenance_order( $id, '6', '2026-02-30T00:00:00+02:00', $received );
		$this->provenance_order( $id, '7', '2026-09-11T00:42:10+02:00', '' );
		$report = ( new Provenance_Health() )->report( array( array( 'id' => $id, 'name' => 'Front' ) ), array( $id ) );
		$this->assertSame( array(
			array( 'order_id' => $behind, 'order_number' => (string) $behind, 'sale_time' => '2026-09-10T22:42:10+02:00', 'received_gmt' => $received, 'skew_seconds' => -7200, 'direction' => 'behind' ),
			array( 'order_id' => $ahead, 'order_number' => (string) $ahead, 'sale_time' => '2026-09-11T00:57:10+02:00', 'received_gmt' => $received, 'skew_seconds' => 900, 'direction' => 'ahead' ),
		), $report['registers'][0]['skew'] );
	}

	public function test_health_unknown_register_lists_sampled_orders(): void {
		$id = wp_generate_uuid4();
		$ids = array( $this->provenance_order( $id ), $this->provenance_order( $id ) );
		$report = ( new Provenance_Health() )->report( array(), array() );
		$this->assertSame( array(), $report['registers'] );
		$rows = array_column( $report['unregistered'], null, 'register_id' );
		$this->assertSame( 2, $rows[ $id ]['orders'] );
		$this->assertEqualsCanonicalizing( $ids, $rows[ $id ]['order_ids'] );
		$this->assertSame( array_map( 'strval', $rows[ $id ]['order_ids'] ), $rows[ $id ]['order_numbers'] );
	}

	public function test_health_old_orders_and_invalid_counters_are_ignored(): void {
		$id = wp_generate_uuid4();
		$unknown = wp_generate_uuid4();
		$this->provenance_order( $id, '99', '', '', time() - 31 * DAY_IN_SECONDS );
		$this->provenance_order( $unknown, '1', '', '', time() - 31 * DAY_IN_SECONDS );
		foreach ( array( '0', '-1', '1.5', '01', '9999999999999999999', 'abc', '' ) as $counter ) {
			$this->provenance_order( $id, $counter );
		}
		$report = ( new Provenance_Health() )->report( array( array( 'id' => $id, 'name' => 'Front' ) ), array( $id ) );
		$row = $report['registers'][0];
		$this->assertSame( 7, $row['orders'] );
		$this->assertNull( $row['first_counter'] );
		$this->assertNull( $row['last_counter'] );
		$this->assertSame( array(), $row['gaps'] );
		$this->assertSame( array(), $row['duplicates'] );
		$this->assertNotContains( $unknown, array_column( $report['unregistered'], 'register_id' ) );
	}

	public function test_health_samples_are_capped_and_only_samples_resolve_numbers(): void {
		$id = wp_generate_uuid4();
		$unknown = wp_generate_uuid4();
		for ( $i = 0; $i < 22; ++$i ) {
			$this->provenance_order( $id, '1', '2026-09-11T10:00:00Z', '2026-09-11T12:00:00Z' );
			$this->provenance_order( $unknown );
		}
		$resolved = array();
		$number = static function ( $number, $order ) use ( &$resolved ) {
			$resolved[] = $order->get_id();
			return $number;
		};
		add_filter( 'woocommerce_order_number', $number, 10, 2 );
		try {
			$report = ( new Provenance_Health() )->report( array( array( 'id' => $id, 'name' => 'Front' ) ), array( $id ) );
		} finally {
			remove_filter( 'woocommerce_order_number', $number );
		}
		$row = $report['registers'][0];
		$unregistered = array_column( $report['unregistered'], null, 'register_id' )[ $unknown ];
		$this->assertSame( 22, $row['orders'] );
		$this->assertSame( 22, $unregistered['orders'] );
		$this->assertCount( 20, $row['duplicates'][0]['order_ids'] );
		$this->assertCount( 20, $row['skew'] );
		$this->assertCount( 20, $unregistered['order_ids'] );
		$sampled = array_merge( $row['duplicates'][0]['order_ids'], array_column( $row['skew'], 'order_id' ), $unregistered['order_ids'] );
		$this->assertEqualsCanonicalizing( array_unique( $sampled ), array_unique( $resolved ) );
	}

	public function test_health_known_out_of_scope_register_is_omitted(): void {
		$id = wp_generate_uuid4();
		$other = wp_generate_uuid4();
		$this->provenance_order( $id );
		$this->provenance_order( $other );
		$report = ( new Provenance_Health() )->report( array( array( 'id' => $id, 'name' => 'Front' ) ), array( $id, $other ) );
		$this->assertSame( array( $id ), array_column( $report['registers'], 'id' ) );
		$this->assertNotContains( $other, array_column( $report['unregistered'], 'register_id' ) );
	}

	public function test_health_store_scope_ignores_other_store_orders(): void {
		$id = wp_generate_uuid4();
		$unknown = wp_generate_uuid4();
		foreach ( array( array( $id, 1 ), array( $id, 2 ), array( $unknown, 2 ) ) as list( $register, $store ) ) {
			$order = wc_get_order( $this->provenance_order( $register ) );
			$order->update_meta_data( '_pos_store', $store );
			$order->save();
		}
		$report = ( new Provenance_Health() )->report( array( array( 'id' => $id, 'name' => 'Front' ) ), array( $id ), array( 1 ) );
		$this->assertSame( 1, $report['registers'][0]['orders'] );
		$this->assertNotContains( $unknown, array_column( $report['unregistered'], 'register_id' ) );
	}

	public function test_health_uppercase_stamp_groups_under_lowercase_register(): void {
		$id = 'abcdefab-1234-4234-8234-abcdefabcdef';
		$this->provenance_order( strtoupper( $id ) );
		$report = ( new Provenance_Health() )->report( array( array( 'id' => $id, 'name' => 'Front' ) ), array( $id ) );
		$this->assertSame( $id, $report['registers'][0]['id'] );
		$this->assertSame( 1, $report['registers'][0]['orders'] );
		$this->assertNotContains( $id, array_column( $report['unregistered'], 'register_id' ) );
		$this->assertNotContains( strtoupper( $id ), array_column( $report['unregistered'], 'register_id' ) );
	}

	public function test_health_small_fixture_is_not_truncated(): void {
		$this->provenance_order( wp_generate_uuid4() );
		$report = ( new Provenance_Health() )->report( array(), array() );
		$this->assertArrayHasKey( 'truncated', $report );
		$this->assertFalse( $report['truncated'] );
	}
	/** Each till owns its counter sequence even on a shared register. */
	public function test_health_two_tills_keep_counter_sequences_separate(): void {
		$id = wp_generate_uuid4();
		$tills = array( wp_generate_uuid4(), wp_generate_uuid4() );
		foreach ( $tills as $till ) {
			foreach ( array( '1', '2', '3' ) as $counter ) {
				$order = wc_get_order( $this->provenance_order( $id, $counter ) );
				$order->update_meta_data( '_wcpos_till', $till );
				$order->save();
			}
		}
		$health = new Provenance_Health();
		$registers = array(
			array(
				'id' => $id,
				'name' => 'Front',
			),
		);
		$row = $health->report( $registers, array( $id ) )['registers'][0];
		$this->assertSame( array(), $row['gaps'] );
		$this->assertSame( array(), $row['duplicates'] );
		$order = wc_get_order( $this->provenance_order( $id, '5' ) );
		$order->update_meta_data( '_wcpos_till', $tills[0] );
		$order->save();
		$row = $health->report( $registers, array( $id ) )['registers'][0];
		$this->assertSame(
			array(
				array(
					'till' => $tills[0],
					'after' => 3,
					'before' => 5,
					'missing' => 1,
				),
			),
			$row['gaps']
		);
		$this->assertSame( array(), $row['duplicates'] );
	}
}
