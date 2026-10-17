<?php
/**
 * Closure storage tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Provenance_Health;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Immutable numbers, decimal counters and atomic writes. */
class Test_Closure_Store extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** Raw negative and refund-kind legs both belong in period refunds. */
	public function test_closure_period_totals_count_negative_and_refund_kind_legs_as_refunds(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$ledger = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance();
		$rows = $ledger->read( $order );
		foreach ( array( array( '-5', 'cash' ), array( '7', 'refund' ) ) as list( $amount, $kind ) ) {
			$rows[] = array_merge(
				$rows[0],
				array(
					'id' => wp_generate_uuid4(),
					'amount' => $amount,
					'kind' => $kind,
				)
			);
		}
		$ledger->save( $order, $rows, false );
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$this->assertSame( '80.0000', $closure['period_sales_total'] );
		$this->assertSame( '12.0000', $closure['period_refunds_total'] );
	}

	/** Late sales and refunds must enter perpetual totals once, never period totals.
	 *
	 * @dataProvider late_correction_types
	 * @param string $type Correction type.
	 * @param string $kind Counter kind.
	 * @param string $amount Signed tender amount.
	 */
	public function test_perpetual_totals_carry_late_corrections_once( string $type, string $kind, string $amount ): void {
		$store = new Closure_Store();
		$session = $this->closure_session();
		$first = $store->create( $this->closure_fields( $session ) );
		$correction_order = new \WC_Order();
		$correction_order->save();
		$record = ( new Fiscal_Record_Store() )->record(
			array(
				'type' => $type,
				'order_id' => $correction_order->get_id(),
				'source_id' => wp_generate_uuid4(),
				'closure_id' => $first['id'],
				'register_id' => $session['register_id'],
				'payload' => array(
					'tender_rows' => array(
						array(
							'method_id' => 'pos_cash',
							'kind' => 'cash',
							'amount' => $amount,
						),
					),
				),
			)
		);
		$this->assertIsArray( $record );
		$corrections = $store->corrections_for( $first['id'] );
		$other = $store->create( $this->closure_fields( $this->closure_session() ) );
		$this->assertSame( '0.0000', $other[ 'perpetual_' . $kind . '_total' ] );
		$second_session = $this->closure_session( $session['register_id'] );
		$order = $this->closure_ledger( $second_session );
		$ledger = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance();
		$rows = $ledger->read( $order );
		$rows[0]['amount'] = 'sales' === $kind ? '5.0000' : '-5.0000';
		$ledger->save( $order, array( $rows[0] ), false );
		$second = $store->create( $this->closure_fields( $second_session, 2 ) );
		// Before the fix this is 5.0000: the late 10.0000 is omitted.
		$this->assertSame( '15.0000', $second[ 'perpetual_' . $kind . '_total' ] );
		$this->assertSame( '5.0000', $second[ 'period_' . $kind . '_total' ] );
		$this->assertSame( $first, $store->get( $first['id'] ) );
		$this->assertSame( $corrections, $store->corrections_for( $first['id'] ) );
		$this->assertSame( $second, $store->create( array( 'id' => $second['id'] ) ) );
		$third = $store->create( $this->closure_fields( $this->closure_session( $session['register_id'] ), 3 ) );
		$this->assertSame( '15.0000', $third[ 'perpetual_' . $kind . '_total' ] );
		$this->assertSame( '0.0000', $third[ 'period_' . $kind . '_total' ] );
		$record['order_id'] = $order->get_id();
		$record['source_id'] = wp_generate_uuid4();
		$record = ( new Fiscal_Record_Store() )->record( $record );
		$this->assertIsArray( $record );
		$fourth_session = $this->closure_session( $session['register_id'] );
		global $wpdb;
		$fail = static function ( $sql ) use ( $store ) {
			return 0 === strpos( $sql, 'INSERT INTO `' . $store->table_name() . '`' ) ? 'INVALID CLOSURE INSERT' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			$store->create( $this->closure_fields( $fourth_session, 4 ) );
			$this->fail( 'Expected closure failure after carrying corrections.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Closure write failed.', $error->getMessage() );
			$this->assertNull( ( new Fiscal_Record_Store() )->get( $record['id'] )['carried_by_closure_id'] );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		$fourth = $store->create( $this->closure_fields( $fourth_session, 4 ) );
		$this->assertSame( '25.0000', $fourth[ 'perpetual_' . $kind . '_total' ] );
		$correction_order->delete( true );
		$order->delete( true );
	}

	/** Correction tender signs match fiscal writer payloads. */
	public static function late_correction_types(): array {
		return array(
			'late sale' => array( 'late_sale', 'sales', '10.0000' ),
			'late refund' => array( 'late_refund', 'refunds', '-10.0000' ),
		);
	}

	/** The aggregate upgrade adds missing columns and backfills only unstamped rows. */
	public function test_business_day_upgrade_adds_columns_and_preserves_stamps(): void {
		global $wpdb;
		$session = $this->closure_session();
		$closures = new Closure_Store();
		$closure = $closures->create( $this->closure_fields( $session ) );
		$stores = array( new Register_Session_Store(), $closures );
		$timezone = get_option( 'timezone_string' );
		$schema = get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION );
		$activator = new \WCPOS\WooCommercePOS\Activator();
		try {
			update_option( 'timezone_string', 'America/New_York' );
			foreach ( $stores as $store ) {
				$table = $store->table_name();
				$wpdb->update( $table, array( 'opened_at_gmt' => '2026-09-11 02:00:00' ), array( 'id' => $store instanceof Closure_Store ? $closure['id'] : $session['id'] ) );
				$wpdb->query( "ALTER TABLE {$table} DROP COLUMN business_day" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.
			}
			$fiscal_table = ( new Fiscal_Record_Store() )->table_name();
			$wpdb->query( "ALTER TABLE {$fiscal_table} DROP COLUMN carried_by_closure_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '6' );
			$activator->install_sync_schema();
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertSame( 'carried_by_closure_id', $wpdb->get_var( "SHOW COLUMNS FROM {$fiscal_table} LIKE 'carried_by_closure_id'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.
			foreach ( $stores as $store ) {
				$id = $store instanceof Closure_Store ? $closure['id'] : $session['id'];
				$this->assertSame( '2026-09-10', $store->get( $id )['business_day'] );
				$wpdb->update( $store->table_name(), array( 'business_day' => '2026-09-09' ), array( 'id' => $id ) );
			}
			$other = $this->closure_session();
			$other_closure = $closures->create( $this->closure_fields( $other ) );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '6' );
			$activator->install_sync_schema();
			$this->assertSame( '2026-09-09', $closures->get( $closure['id'] )['business_day'] );
			$this->assertSame( '2026-09-09', $stores[0]->get( $session['id'] )['business_day'] );
			$this->assertSame( '2026-09-11', $closures->get( $other_closure['id'] )['business_day'] );
			$this->assertSame( '2026-09-11', $stores[0]->get( $other['id'] )['business_day'] );
		} finally {
			update_option( 'timezone_string', $timezone );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '6' );
			$activator->install_sync_schema();
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, $schema );
		}
	}

	/** The projection must not inherit either fiscal list page limit. */
	public function test_corrections_reads_more_than_one_maximum_page(): void {
		global $wpdb;
		$store = new Closure_Store();
		$closure = $store->create( $this->closure_fields( $this->closure_session() ) );
		$fiscal = new Fiscal_Record_Store();
		for ( $i = 1; $i <= 205; ++$i ) {
			$payload = wp_json_encode(
				array(
					'counted' => array(
						'cash' => '999999999999999.9999',
						'card' => '00012.3',
					),
					'variance' => array(
						'cash' => '-0.0001',
						'card' => '-000.0',
					),
					'reason' => 'Bulk recount',
				)
			);
			$this->assertSame(
				1,
				$wpdb->insert(
					$fiscal->table_name(),
					array(
						'type' => 'recount',
						'series' => $closure['id'],
						'number' => $i,
						'source_id' => wp_generate_uuid4(),
						'closure_id' => $closure['id'],
						'register_id' => $closure['register_id'],
						'cashier_id' => get_current_user_id(),
						'received_at_gmt' => '2026-09-12 10:00:00',
						'payload' => $payload,
						'checksum' => hash( 'sha256', $payload ),
					)
				)
			);
		}
		$queries = $wpdb->num_queries;
		$rows = $store->corrections_for( $closure['id'] );
		$this->assertLessThanOrEqual( 3, $wpdb->num_queries - $queries );
		$this->assertCount( 205, $rows );
		$this->assertSame( '12.3000', $rows[204]['figures']['counted']['card'] );
		$this->assertSame( '0.0000', $rows[204]['figures']['variance']['card'] );
		$queries = $wpdb->num_queries;
		$counted = $store->with_correction_counts( array( $closure ) );
		$this->assertSame( 1, $wpdb->num_queries - $queries );
		$this->assertSame( 205, $counted[0]['corrections_count'] );
		$this->assertSame( '999999999999999.9999', $rows[204]['figures']['counted']['cash'] );
		$this->assertSame( '-0.0001', $rows[204]['figures']['variance']['cash'] );
	}

	/** First closure derives totals and replay is immutable. */
	public function test_first_closure_derives_totals_and_replay_is_immutable(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$store = new Closure_Store();
		$fields = $this->closure_fields( $session );
		$row = $store->create( $fields );
		$this->assertIsArray( $row );
		$this->assertSame( 1, $row['number'] );
		$this->assertSame( '80.0000', $row['period_sales_total'] );
		$this->assertSame( '0.0000', $row['period_refunds_total'] );
		$this->assertSame( '80.0000', $row['perpetual_sales_total'] );
		$this->assertSame( '0.0000', $row['perpetual_refunds_total'] );
		$this->assertSame(
			array(
				'cash' => '150.0000',
				'card' => '30.0000',
			),
			$row['expected']
		);
		$this->assertSame( array( 'cash' => '-49.0000' ), $row['variance'] );
		$this->assertSame( array( 'cash' => '100.0000' ), $row['till_expected'] );
		$this->assertSame( '0.0000', $row['findings']['total_mismatch']['period_sales_total']['reported'] );
		$this->assertSame( '80.0000', $row['findings']['total_mismatch']['period_sales_total']['derived'] );
		$health = ( new Provenance_Health() )->report( array( ( new Register_Store() )->get( $session['register_id'] ) ), array( $session['register_id'] ) )['registers'][0];
		$this->assertSame( $row['findings'], $health['closure_total_mismatches'][0]['findings'] );
		$this->assertSame( $row, $store->create( array( 'id' => $row['id'] ) ) );
		$closed = ( new Register_Session_Store() )->get( $session['id'] );
		$this->assertSame( 'closed', $closed['status'] );
		$this->assertSame( $row['id'], $closed['closure_id'] );
		$this->assertSame( $row['counted'], $closed['counted'] );
		$register = ( new Register_Store() )->get( $session['register_id'] );
		$this->assertSame( '2026-09-11T08:00:00Z', $register['counters_started_at_gmt'] );
		$this->assertSame( '80.0000', $register['counters']['perpetual_sales_total'] );
		$this->assertSame( '0.0000', $register['counters']['perpetual_refunds_total'] );
		$second = $store->create( $this->closure_fields( $this->closure_session( $session['register_id'] ), 2 ) );
		$this->assertSame( '0.0000', $second['period_sales_total'] );
		$this->assertSame( '80.0000', $second['perpetual_sales_total'] );
		$this->assertSame( '0.0000', $second['perpetual_refunds_total'] );
		$order->delete( true );
	}

	/** Numbers conflicts and gaps are reported without repair. */
	public function test_numbers_conflicts_and_gaps_are_reported_without_repair(): void {
		$store = new Closure_Store();
		$session = $this->closure_session();
		$register = $session['register_id'];
		$store->create( $this->closure_fields( $session ) );
		$store->create( $this->closure_fields( $this->closure_session( $register ), 2 ) );
		$duplicate = $store->create( $this->closure_fields( $this->closure_session( $register ), 2 ) );
		$this->assertSame( 3, $duplicate['number'] );
		$this->assertSame( 2, $duplicate['printed_number'] );
		$gap = $store->create( $this->closure_fields( $this->closure_session( $register ), 5 ) );
		$this->assertSame( 5, $gap['number'] );
		$health = ( new Provenance_Health() )->report( array( ( new Register_Store() )->get( $register ) ), array( $register ) )['registers'][0];
		$this->assertSame(
			array(
				array(
					'after' => 3,
					'before' => 5,
					'missing' => 1,
				),
			),
			$health['closure_gaps']
		);
		$this->assertSame( $duplicate['id'], $health['closure_conflicts'][0]['id'] );
		$this->assertSame( array(), $health['closure_total_mismatches'] );
		$this->assertSame( 5, $store->last_number( $register ) );
	}

	/** Existing session closure writes one recount. */
	public function test_existing_session_closure_writes_one_recount(): void {
		$session = $this->closure_session();
		$store = new Closure_Store();
		$first = $store->create( $this->closure_fields( $session ) );
		$fields = $this->closure_fields( $session );
		$fields['counted'] = array( 'cash' => '99.0000' );
		foreach ( array( 1, 2 ) as $attempt ) {
			$error = $store->create( $fields );
			$this->assertWPError( $error );
			$this->assertSame( 'wcpos_closure_exists', $error->get_error_code() );
		}
		$records = ( new Fiscal_Record_Store() )->list(
			array(
				'closure_id' => $first['id'],
				'type' => 'recount',
			)
		);
		$this->assertCount( 1, $records );
		$this->assertSame( $fields['id'], $records[0]['source_id'] );
		$this->assertSame( array( 'cash' => '99.0000' ), $records[0]['payload']['counted'] );
		$this->assertSame( array( 'cash' => '-1.0000' ), $records[0]['payload']['variance'] );
		$this->assertSame( get_current_user_id(), $records[0]['cashier_id'] );
		$this->assertSame( $first, $store->get( $first['id'] ) );
	}

	/** Failed insert rolls back session and counter start. */
	public function test_failed_insert_rolls_back_session_and_counter_start(): void {
		global $wpdb;
		$session = $this->closure_session();
		$store = new Closure_Store();
		$fields = $this->closure_fields( $session );
		$fail = static function ( $sql ) use ( $store ) {
			return 0 === strpos( $sql, 'INSERT INTO `' . $store->table_name() . '`' ) ? 'INVALID CLOSURE INSERT' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			try {
				$store->create( $fields );
				$this->fail( 'Expected a surfaced database failure.' );
			} catch ( \RuntimeException $error ) {
				$this->assertNull( $store->get( $fields['id'] ) );
				$this->assertSame( $session, ( new Register_Session_Store() )->get( $session['id'] ) );
				$this->assertNull( ( new Register_Store() )->get( $session['register_id'] )['counters_started_at_gmt'] );
			}
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}
	/** The closure route must not bypass the existing manager variance requirement. */
	public function test_close_requires_approval_and_copies_approver(): void {
		$session = $this->closure_session();
		$fields = $this->closure_fields( $session );
		$fields['counted']['cash'] = '110.0000';
		$threshold = static function () {
			return '5';
		};
		add_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		try {
			$store = new Closure_Store();
			$error = $store->create( $fields );
			$this->assertWPError( $error );
			$this->assertSame( 'wcpos_override_refused', $error->get_error_code() );
			$this->assertNull( $store->get( $fields['id'] ) );
			$manager = self::factory()->user->create();
			( new Register_Session_Store() )->approve( $session, $manager, $fields['counted'] );
			$this->assertSame( $manager, $store->create( $fields )['approved_by'] );
		} finally {
			remove_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		}
	}

	/** A previously closed status row receives only the closure link. */
	public function test_closed_session_status_fields_are_preserved(): void {
		$session = $this->closure_session( null, 'closed' );
		$fields = $this->closure_fields( $session );
		$fields['counted'] = array( 'cash' => '100.0000' );
		$fields['closed_at_gmt'] = '2026-09-11 13:00:00';
		$row = ( new Closure_Store() )->create( $fields );
		$this->assertIsArray( $row );
		$session['closure_id'] = $row['id'];
		$this->assertSame( $session, ( new Register_Session_Store() )->get( $session['id'] ) );
		$this->assertSame( array( 'cash' => '0.0000' ), $row['variance'] );
	}

	/** A forward jump to four stays four, and its missing three is listed. */
	public function test_number_four_gap_is_accepted(): void {
		$session = $this->closure_session();
		$store = new Closure_Store();
		$store->create( $this->closure_fields( $session ) );
		$store->create( $this->closure_fields( $this->closure_session( $session['register_id'] ), 2 ) );
		$row = $store->create( $this->closure_fields( $this->closure_session( $session['register_id'] ), 4 ) );
		$this->assertSame( 4, $row['number'] );
		$this->assertSame(
			array(
				array(
					'after' => 2,
					'before' => 4,
					'missing' => 1,
				),
			),
			$store->health( $session['register_id'] )['closure_gaps']
		);
	}

	/** SQL decimals retain sub-cent differences even at the storage boundary. */
	public function test_variance_uses_all_nineteen_digits(): void {
		$this->assertSame(
			array(
				'cash' => '0.0001',
				'card' => '5.0000',
			),
			( new Closure_Store() )->variance(
				array(
					'cash' => '999999999999999.9999',
					'card' => '5',
				),
				array( 'cash' => '999999999999999.9998' )
			)
		);
	}
	/** Captured session legs alone receive the closure stamp. */
	public function test_closure_stamps_every_leg_it_counted_and_no_other(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$ledger = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance();
		$before = $ledger->read( $order );
		$store = new Closure_Store();
		$row = $store->create( $this->closure_fields( $session ) );
		$after = $ledger->read( wc_get_order( $order->get_id() ) );
		$this->assertSame( $row['id'], $after[0]['closure_id'] );
		$this->assertSame( $row['id'], $after[1]['closure_id'] );
		$this->assertArrayNotHasKey( 'closure_id', $after[2] );
		$this->assertArrayNotHasKey( 'closure_id', $after[3] );
		$this->assertSame( array_column( $before, 'updated_at_gmt' ), array_column( $after, 'updated_at_gmt' ) );
		$this->assertSame( $row, $store->get( $row['id'] ) );
	}

	/** A failed closure leaves neither stamps nor held locks behind. */
	public function test_failed_closure_leaves_no_stamps_and_releases_its_locks(): void {
		global $wpdb;
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$store = new Closure_Store();
		$fields = $this->closure_fields( $session );
		$fail = static function ( $sql ) use ( $store ) {
			return 0 === strpos( $sql, 'INSERT INTO `' . $store->table_name() . '`' ) ? 'INVALID CLOSURE INSERT' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			$store->create( $fields );
			$this->fail( 'Expected closure failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assertNull( $store->get( $fields['id'] ) );
			$this->assertSame( $session, ( new Register_Session_Store() )->get( $session['id'] ) );
			$this->assertNull( ( new Register_Store() )->get( $session['register_id'] )['counters_started_at_gmt'] );
			$order->read_meta_data( true );
			foreach ( \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance()->read( $order ) as $row ) {
				$this->assertArrayNotHasKey( 'closure_id', $row );
			}
			$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', sprintf( 'wcpos_order_%d_%d', $order->get_id(), get_current_blog_id() ) ) ) );
			$lock = \WCPOS\WooCommercePOS\Payments\Contract\Order_Lock::instance();
			$this->assertTrue( $lock->acquire( $order->get_id() ) );
			$lock->release( $order->get_id() );
			$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', 'wcpos_register_' . $session['register_id'] ) ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}

	/** The register is locked before the transaction and released after commit. */
	public function test_closure_holds_the_register_lock_across_its_transaction(): void {
		$session = $this->closure_session();
		$queries = array();
		$held_at_commit = null;
		$trace = static function ( $sql ) use ( &$queries, &$held_at_commit, $session ) {
			global $wpdb;
			if ( 'COMMIT' === $sql ) {
				$held_at_commit = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', 'wcpos_register_' . $session['register_id'] ) );
			}
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $trace );
		try {
			( new Closure_Store() )->create( $this->closure_fields( $session ) );
		} finally {
			remove_filter( 'query', $trace );
		}
		$sql = implode( "\n", $queries );
		$lock = strpos( $sql, "GET_LOCK( 'wcpos_register_" . $session['register_id'] );
		$release = strrpos( $sql, "RELEASE_LOCK( 'wcpos_register_" . $session['register_id'] );
		$this->assertSame( '0', $held_at_commit );
		$this->assertNotFalse( $lock );
		$this->assertNotFalse( $release );
		$this->assertLessThan( strpos( $sql, 'START TRANSACTION' ), $lock );
		$this->assertGreaterThan( strpos( $sql, 'COMMIT' ), $release );
	}
}
