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
			$this->assertSame(
				array(
					'ahead' => 0,
					'behind' => 0,
				),
				$row['skew_counts']
			);
		}
	}

	/** A duplicate counter carries the true order count beside its capped id sample. */
	public function test_health_duplicate_counter_reports_true_count_beside_capped_sample(): void {
		// Arrange.
		$id = wp_generate_uuid4();
		$created = array();
		for ( $i = 0; $i < 25; ++$i ) {
			$created[] = $this->provenance_order( $id, '7' );
		}
		$registers = array(
			array(
				'id' => $id,
				'name' => 'Front',
			),
		);
		$number = static function ( $number, $order ) {
			return 'SALE-' . $order->get_id();
		};
		add_filter( 'woocommerce_order_number', $number, 10, 2 );
		// Act.
		try {
			$row = ( new Provenance_Health() )->report( $registers, array( $id ) )['registers'][0];
		} finally {
			remove_filter( 'woocommerce_order_number', $number );
		}
		// Assert.
		$this->assertCount( 1, $row['duplicates'] );
		$duplicate = $row['duplicates'][0];
		$this->assertSame( 7, $duplicate['counter'] );
		$this->assertSame( 25, $duplicate['orders'] );
		$this->assertCount( 20, $duplicate['order_ids'] );
		$this->assertEmpty( array_diff( $duplicate['order_ids'], $created ) );
		$this->assertCount( 20, $duplicate['order_numbers'] );
		foreach ( $duplicate['order_ids'] as $i => $order_id ) {
			$this->assertSame( 'SALE-' . $order_id, $duplicate['order_numbers'][ $i ] );
		}
	}

	/** Skew is sampled and counted per direction, so many late syncs cannot hide an ahead clock. */
	public function test_health_skew_samples_each_direction_so_late_syncs_cannot_hide_ahead_clocks(): void {
		// Arrange: 25 offline sales synced an hour or more late, and 2 sales from a clock running ahead.
		$id = wp_generate_uuid4();
		$received = '2026-09-10T12:00:00Z';
		$base = strtotime( $received );
		$behind = array();
		for ( $i = 0; $i < 25; ++$i ) {
			$seconds = -( 3600 + $i * 60 );
			$behind[ $this->provenance_order( $id, (string) ( $i + 1 ), gmdate( 'Y-m-d\TH:i:s\Z', $base + $seconds ), $received ) ] = $seconds;
		}
		$ahead = array(
			$this->provenance_order( $id, '26', gmdate( 'Y-m-d\TH:i:s\Z', $base + 900 ), $received ) => 900,
			$this->provenance_order( $id, '27', gmdate( 'Y-m-d\TH:i:s\Z', $base + 1200 ), $received ) => 1200,
		);
		$registers = array(
			array(
				'id' => $id,
				'name' => 'Front',
			),
		);
		// Act.
		$row = ( new Provenance_Health() )->report( $registers, array( $id ) )['registers'][0];
		// Assert.
		arsort( $ahead );
		asort( $behind );
		$expected = array_merge(
			array_map( null, array_keys( $ahead ), array_values( $ahead ) ),
			array_slice( array_map( null, array_keys( $behind ), array_values( $behind ) ), 0, 20 )
		);
		$this->assertSame(
			array(
				'ahead' => 2,
				'behind' => 25,
			),
			$row['skew_counts']
		);
		$this->assertSame( array_merge( array( 'ahead', 'ahead' ), array_fill( 0, 20, 'behind' ) ), array_column( $row['skew'], 'direction' ) );
		$this->assertSame( array_column( $expected, 0 ), array_column( $row['skew'], 'order_id' ) );
		$this->assertSame( array_column( $expected, 1 ), array_column( $row['skew'], 'skew_seconds' ) );
		$this->assertSame( array_map( 'strval', array_column( $expected, 0 ) ), array_column( $row['skew'], 'order_number' ) );
	}

	public function test_health_skew_uses_offsets_lists_ahead_first_each_direction_by_magnitude_and_counts_both(): void {
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
			array( 'order_id' => $ahead, 'order_number' => (string) $ahead, 'sale_time' => '2026-09-11T00:57:10+02:00', 'received_gmt' => $received, 'skew_seconds' => 900, 'direction' => 'ahead' ),
			array( 'order_id' => $behind, 'order_number' => (string) $behind, 'sale_time' => '2026-09-10T22:42:10+02:00', 'received_gmt' => $received, 'skew_seconds' => -7200, 'direction' => 'behind' ),
		), $report['registers'][0]['skew'] );
		$this->assertSame(
			array(
				'ahead' => 1,
				'behind' => 1,
			),
			$report['registers'][0]['skew_counts']
		);
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

	public function test_health_invalid_till_falls_back_to_register_for_counters(): void {
		$id = wp_generate_uuid4();
		$ids = array(
			$this->provenance_order( $id ),
			$this->provenance_order( $id ),
		);
		$order = wc_get_order( $ids[1] );
		$order->update_meta_data( '_wcpos_till', 'invalid-till' );
		$order->save();
		$row = ( new Provenance_Health() )->report( array( array( 'id' => $id, 'name' => 'Front' ) ), array( $id ) )['registers'][0];
		$this->assertCount( 1, $row['duplicates'] );
		$this->assertSame( $id, $row['duplicates'][0]['till'] );
		$this->assertEqualsCanonicalizing( $ids, $row['duplicates'][0]['order_ids'] );
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
	/**
	 * Unassigned scope includes both missing and zero order store metadata.
	 */
	public function test_report_unassigned_scope_includes_orders_without_a_store(): void {
		// Arrange.
		$session = wp_generate_uuid4();
		foreach ( array( null, '0', 2 ) as $store_id ) {
			$order = wc_get_order( $this->provenance_order( '' ) );
			$order->update_meta_data( '_wcpos_session', $session );
			if ( null !== $store_id ) {
				$order->update_meta_data( '_pos_store', $store_id );
			}
			$order->save();
		}
		// Act.
		$included = ( new Provenance_Health() )->report( array(), array(), array( 1, null ) );
		$excluded = ( new Provenance_Health() )->report( array(), array(), array( 1 ) );
		// Assert.
		$this->assertSame( array( $session ), array_column( $included['unknown_sessions'], 'session_id' ) );
		$this->assertSame( 2, $included['unknown_sessions'][0]['orders'] );
		$this->assertSame( array(), $excluded['unknown_sessions'] );
	}
}
