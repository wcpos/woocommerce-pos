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

	/** Break the one session UPDATE that writes a closure document. */
	private static function fail_closure_write(): callable {
		$table = ( new Register_Session_Store() )->table_name();
		return static function ( $sql ) use ( $table ) {
			return 0 === strpos( $sql, 'UPDATE `' . $table . '`' ) && false !== strpos( $sql, '`closure_id`' ) ? 'INVALID CLOSURE WRITE' : $sql;
		};
	}

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
		$fail = self::fail_closure_write();
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			$store->create( $this->closure_fields( $fourth_session, 4 ) );
			$this->fail( 'Expected closure failure after carrying corrections.' );
		} catch ( \RuntimeException $error ) {
			// The closure document is written by the session UPDATE that closes the session.
			$this->assertSame( 'Session write failed.', $error->getMessage() );
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
		$sessions = new Register_Session_Store();
		$timezone = get_option( 'timezone_string' );
		$schema = get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION );
		$activator = new \WCPOS\WooCommercePOS\Activator();
		try {
			update_option( 'timezone_string', 'America/New_York' );
			// A closure's business day is its session's, so the session row is the one to back-date.
			$table = $sessions->table_name();
			$wpdb->update( $table, array( 'opened_at_gmt' => '2026-09-11 02:00:00' ), array( 'id' => $session['id'] ) );
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN business_day" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.
			$fiscal_table = ( new Fiscal_Record_Store() )->table_name();
			$wpdb->query( "ALTER TABLE {$fiscal_table} DROP COLUMN carried_by_closure_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '6' );
			$activator->install_sync_schema();
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertSame( 'carried_by_closure_id', $wpdb->get_var( "SHOW COLUMNS FROM {$fiscal_table} LIKE 'carried_by_closure_id'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.
			$this->assertSame( '2026-09-10', $closures->get( $closure['id'] )['business_day'] );
			$this->assertSame( '2026-09-10', $sessions->get( $session['id'] )['business_day'] );
			$wpdb->update( $table, array( 'business_day' => '2026-09-09' ), array( 'id' => $session['id'] ) );
			$other = $this->closure_session();
			$other_closure = $closures->create( $this->closure_fields( $other ) );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '6' );
			$activator->install_sync_schema();
			$this->assertSame( '2026-09-09', $closures->get( $closure['id'] )['business_day'] );
			$this->assertSame( '2026-09-09', $sessions->get( $session['id'] )['business_day'] );
			$this->assertSame( '2026-09-11', $closures->get( $other_closure['id'] )['business_day'] );
			$this->assertSame( '2026-09-11', $sessions->get( $other['id'] )['business_day'] );
		} finally {
			update_option( 'timezone_string', $timezone );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '6' );
			$activator->install_sync_schema();
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, $schema );
		}
	}

	/** Existing sessions gain both approval snapshots without repeating DDL. */
	public function test_approval_schema_upgrade_adds_columns_once(): void {
		global $wpdb;
		$store = new Register_Session_Store();
		$store->install();
		$table = $store->table_name();
		$schema = get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION );
		$activator = new \WCPOS\WooCommercePOS\Activator();
		$alterations = 0;
		$observe = static function ( $sql ) use ( $table, &$alterations ) {
			if ( 0 === strpos( $sql, "ALTER TABLE {$table}" ) ) {
				++$alterations;
			}
			return $sql;
		};
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed columns.
			$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} DROP COLUMN approved_counted, DROP COLUMN approved_expected" ) );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '8' );
			$activator->install_sync_schema();
			foreach ( array( 'approved_counted', 'approved_expected' ) as $column ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed columns.
				$definition = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE '{$column}'", ARRAY_A );
				$this->assertNotNull( $definition );
				$this->assertSame( 'longtext', $definition['Type'] );
				$this->assertSame( 'YES', $definition['Null'] );
			}
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			add_filter( 'query', $observe );
			$activator->install_sync_schema();
			$this->assertSame( 0, $alterations );
		} finally {
			remove_filter( 'query', $observe );
			foreach ( array( 'approved_counted', 'approved_expected' ) as $column ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Restore schema even when testing the unfixed installer.
				if ( null === $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE '{$column}'" ) ) {
					$wpdb->query( "ALTER TABLE {$table} ADD {$column} LONGTEXT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed columns.
				}
			}
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
		$fail = self::fail_closure_write();
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
		// The row reads the session's close; the deferred POST's own claim stays in the document.
		$this->assertSame( '2026-09-11 12:00:00', $row['closed_at_gmt'] );
		$this->assertSame( '2026-09-11 13:00:00', $row['till_closed_at_gmt'] );
		$this->assertNull( $row['till_opened_at_gmt'] );
	}

	/** A deferred closure filters and sorts by its session's close, never the till's later claim. */
	public function test_deferred_closure_lists_by_session_closed_at(): void {
		// Arrange.
		$store = new Closure_Store();
		$session = $this->closure_session( null, 'closed' );
		$this->assertNull( $session['business_day'] );
		$fields = $this->closure_fields( $session );
		$fields['counted'] = array( 'cash' => '100.0000' );
		$fields['closed_at_gmt'] = '2026-09-11 13:00:00';
		$deferred = $store->create( $fields );
		$later = $this->closure_fields( $this->closure_session( $session['register_id'] ), 2 );
		$later['closed_at_gmt'] = '2026-09-11 12:30:00';
		$later = $store->create( $later );

		// Act.
		$after_early = $store->list(
			array(
				'register_id' => $session['register_id'],
				'after' => '2026-09-11 11:30:00',
			)
		);
		$after_late = $store->list(
			array(
				'register_id' => $session['register_id'],
				'after' => '2026-09-11 12:30:00',
			)
		);

		// Assert.
		$this->assertSame( array( $later['id'], $deferred['id'] ), array_column( $after_early, 'id' ) );
		$this->assertSame( '2026-09-11 12:00:00', $after_early[1]['closed_at_gmt'] );
		$this->assertSame( array( $later['id'] ), array_column( $after_late, 'id' ) );
	}

	/** A failed document write rolls the session close back with it. */
	public function test_failed_document_write_rolls_back_session_close(): void {
		// Arrange.
		global $wpdb;
		$session = $this->closure_session();
		$store = new Closure_Store();
		$fields = $this->closure_fields( $session );
		$table = ( new Register_Session_Store() )->table_name();
		$fail = static function ( $sql ) use ( $table ) {
			return 0 === strpos( $sql, "UPDATE {$table} SET closure = " ) ? 'INVALID DOCUMENT WRITE' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			// Act.
			try {
				$store->create( $fields );
				$this->fail( 'Expected the document write to fail.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure write failed.', $error->getMessage() );
			}
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}

		// Assert.
		$this->assertSame( $session, ( new Register_Session_Store() )->get( $session['id'] ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
		$this->assertSame( array( null, null ), array_values( $wpdb->get_row( $wpdb->prepare( "SELECT closure_id, number FROM {$table} WHERE id = %s", $session['id'] ), ARRAY_A ) ) );
		$this->assertNull( $store->get( $fields['id'] ) );
	}

	/** Mid-fold, a closure only the legacy table holds is still found; once latched, the legacy table is never read. */
	public function test_legacy_closure_is_read_only_while_the_fold_is_pending(): void {
		// Arrange.
		global $wpdb;
		$session = $this->closure_session();
		$legacy = $wpdb->prefix . Closure_Store::LEGACY_TABLE;
		$closure_id = wp_generate_uuid4();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$statements = array();
		$observe = static function ( $sql ) use ( $legacy, &$statements ) {
			if ( false !== strpos( $sql, Closure_Store::LEGACY_TABLE ) ) {
				$statements[] = $sql;
			}
			return $sql;
		};
		try {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$legacy} ( id CHAR(36) NOT NULL, register_id CHAR(36) NOT NULL, session_id CHAR(36) NOT NULL, number BIGINT NOT NULL, expected LONGTEXT NOT NULL, till_expected LONGTEXT NOT NULL, counted LONGTEXT NOT NULL, variance LONGTEXT NOT NULL, perpetual_sales_total DECIMAL(19,4) NOT NULL, breakdowns LONGTEXT NOT NULL, findings LONGTEXT NULL, PRIMARY KEY  (id) )" ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame(
				1,
				$wpdb->insert(
					$legacy,
					array(
						'id' => $closure_id,
						'register_id' => $session['register_id'],
						'session_id' => $session['id'],
						'number' => 7,
						'expected' => '{"cash":"150.0000"}',
						'till_expected' => '{"cash":"100.0000"}',
						'counted' => '{"cash":"101.0000"}',
						'variance' => '{"cash":"-49.0000"}',
						'perpetual_sales_total' => '80',
						'breakdowns' => '{"transaction_count":0}',
					)
				)
			);
			$store = new Closure_Store();

			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			$pending = $store->for_session( $session['id'] );
			$pending_last = $store->last( $session['register_id'] );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			add_filter( 'query', $observe );
			$latched = $store->for_session( $session['id'] );
			$latched_last = $store->last( $session['register_id'] );
			remove_filter( 'query', $observe );

			// Assert.
			$this->assertSame( $closure_id, $pending['id'] );
			$this->assertSame( $session['id'], $pending['session_id'] );
			$this->assertSame( array( 'cash' => '150.0000' ), $pending['expected'] );
			$this->assertSame( '80.0000', $pending['perpetual_sales_total'] );
			$this->assertSame( 7, $pending_last['number'] );
			$this->assertNull( $latched );
			$this->assertNull( $latched_last );
			$this->assertSame( array(), $statements );
		} finally {
			remove_filter( 'query', $observe );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
		}
	}

	/** While the fold is pending, create() refuses when only the renamed legacy table exists, and when its probe fails. */
	public function test_create_refuses_while_folded_table_exists_or_probe_fails(): void {
		// Arrange.
		global $wpdb;
		$session = $this->closure_session();
		$folded = $wpdb->prefix . Closure_Store::LEGACY_TABLE . '_folded';
		$store = new Closure_Store();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$break = static function ( $sql ) {
			return false !== strpos( $sql, 'SHOW TABLES LIKE' ) && false !== strpos( $sql, 'wcpos\\_closures' ) ? 'INVALID PROBE' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		$errors = array();
		try {
			$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$folded} ( id CHAR(36) NOT NULL, PRIMARY KEY  (id) )" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );

			// Act.
			foreach ( array( 'folded', 'probe' ) as $case ) {
				if ( 'probe' === $case ) {
					$wpdb->query( "DROP TABLE IF EXISTS {$folded}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
					add_filter( 'query', $break );
				}
				try {
					$store->create( $this->closure_fields( $session ) );
					$errors[ $case ] = null;
				} catch ( \RuntimeException $error ) {
					$errors[ $case ] = $error->getMessage();
				}
				remove_filter( 'query', $break );
			}

			// Assert.
			$this->assertSame(
				array(
					'folded' => 'Closure storage is migrating.',
					'probe' => 'Closure storage probe failed.',
				),
				$errors
			);
			$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $session['id'] )['status'] );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $previous );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$wpdb->query( "DROP TABLE IF EXISTS {$folded}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
		}
	}

	/** A recreated empty legacy table does not hide a closure only the renamed table holds; create() refuses while either name exists. */
	public function test_folded_closure_is_read_while_recreated_legacy_table_is_empty(): void {
		// Arrange.
		global $wpdb;
		$store = new Closure_Store();
		$first = $this->closure_session();
		$this->assertIsArray( $store->create( $this->closure_fields( $first ) ) );
		$second = $this->closure_session( $first['register_id'] );
		$legacy = $wpdb->prefix . Closure_Store::LEGACY_TABLE;
		$folded = $legacy . '_folded';
		$closure_id = wp_generate_uuid4();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$errors = array();
		try {
			foreach ( array( $legacy, $folded ) as $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy tables.
				$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$table} ( id CHAR(36) NOT NULL, register_id CHAR(36) NOT NULL, session_id CHAR(36) NOT NULL, number BIGINT NOT NULL, expected LONGTEXT NOT NULL, till_expected LONGTEXT NOT NULL, counted LONGTEXT NOT NULL, variance LONGTEXT NOT NULL, perpetual_sales_total DECIMAL(19,4) NOT NULL, breakdowns LONGTEXT NOT NULL, findings LONGTEXT NULL, PRIMARY KEY  (id) )" ) );
			}
			$this->assertSame(
				1,
				$wpdb->insert(
					$folded,
					array(
						'id' => $closure_id,
						'register_id' => $second['register_id'],
						'session_id' => $second['id'],
						'number' => 2,
						'expected' => '{"cash":"150.0000"}',
						'till_expected' => '{"cash":"100.0000"}',
						'counted' => '{"cash":"101.0000"}',
						'variance' => '{"cash":"-49.0000"}',
						'perpetual_sales_total' => '80',
						'breakdowns' => '{"transaction_count":0}',
					)
				)
			);
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );

			// Act.
			$for_session = $store->for_session( $second['id'] );
			$last = $store->last( $first['register_id'] );
			$cases = array(
				'both' => null,
				'legacy' => $folded,
			);
			foreach ( $cases as $case => $drop ) {
				if ( null !== $drop ) {
					$wpdb->query( "DROP TABLE IF EXISTS {$drop}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
				}
				try {
					$store->create( $this->closure_fields( $second, 3 ) );
					$errors[ $case ] = null;
				} catch ( \RuntimeException $error ) {
					$errors[ $case ] = $error->getMessage();
				}
			}

			// Assert.
			$this->assertSame( $closure_id, $for_session['id'] ?? null );
			$this->assertSame( $closure_id, $last['id'] ?? null );
			$this->assertSame( 2, $last['number'] );
			$this->assertSame(
				array(
					'both' => 'Closure storage is migrating.',
					'legacy' => 'Closure storage is migrating.',
				),
				$errors
			);
		} finally {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$wpdb->query( "DROP TABLE IF EXISTS {$legacy}, {$folded}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy tables.
		}
	}

	/** Mid-fold a register's newest closure may live only in a legacy table; once latched, no legacy table is probed. */
	public function test_latest_for_registers_while_fold_is_pending_returns_newer_legacy_closure(): void {
		// Arrange.
		global $wpdb;
		$store = new Closure_Store();
		$first = $this->closure_session();
		$this->assertIsArray( $store->create( $this->closure_fields( $first ) ) );
		$second = $this->closure_session( $first['register_id'] );
		$folded = $wpdb->prefix . Closure_Store::LEGACY_TABLE . '_folded';
		$closure_id = wp_generate_uuid4();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$probes = array();
		$observe = static function ( $sql ) use ( &$probes ) {
			if ( false !== stripos( $sql, 'SHOW TABLES' ) || false !== stripos( $sql, 'information_schema' ) || false !== strpos( $sql, Closure_Store::LEGACY_TABLE ) ) {
				$probes[] = $sql;
			}
			return $sql;
		};
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$folded} ( id CHAR(36) NOT NULL, register_id CHAR(36) NOT NULL, session_id CHAR(36) NOT NULL, number BIGINT NOT NULL, expected LONGTEXT NOT NULL, till_expected LONGTEXT NOT NULL, counted LONGTEXT NOT NULL, variance LONGTEXT NOT NULL, perpetual_sales_total DECIMAL(19,4) NOT NULL, breakdowns LONGTEXT NOT NULL, findings LONGTEXT NULL, PRIMARY KEY  (id) )" ) );
			$this->assertSame(
				1,
				$wpdb->insert(
					$folded,
					array(
						'id' => $closure_id,
						'register_id' => $second['register_id'],
						'session_id' => $second['id'],
						'number' => 2,
						'expected' => '{"cash":"150.0000"}',
						'till_expected' => '{"cash":"100.0000"}',
						'counted' => '{"cash":"101.0000"}',
						'variance' => '{"cash":"-49.0000"}',
						'perpetual_sales_total' => '80',
						'breakdowns' => '{"transaction_count":0}',
					)
				)
			);

			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			$pending = $store->latest_for_registers( array( $first['register_id'] ) );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			add_filter( 'query', $observe );
			$latched = $store->latest_for_registers( array( $first['register_id'] ) );
			remove_filter( 'query', $observe );

			// Assert.
			$this->assertSame( array( $first['register_id'] ), array_keys( $pending ) );
			$this->assertSame( $closure_id, $pending[ $first['register_id'] ]['id'] );
			$this->assertSame( 2, $pending[ $first['register_id'] ]['number'] );
			$this->assertSame( '80.0000', $pending[ $first['register_id'] ]['perpetual_sales_total'] );
			$this->assertSame( 1, $latched[ $first['register_id'] ]['number'] );
			$this->assertSame( array(), $probes );
		} finally {
			remove_filter( 'query', $observe );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$wpdb->query( "DROP TABLE IF EXISTS {$folded}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
		}
	}

	/** Mid-fold, last() applies its date filters to the legacy row as to the session table. */
	public function test_last_while_fold_is_pending_applies_date_filters_to_legacy_closure(): void {
		// Arrange.
		global $wpdb;
		$store = new Closure_Store();
		$first = $this->closure_session();
		$this->assertIsArray( $store->create( $this->closure_fields( $first ) ) );
		$second = $this->closure_session( $first['register_id'] );
		$legacy = $wpdb->prefix . Closure_Store::LEGACY_TABLE;
		$closure_id = wp_generate_uuid4();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$legacy} ( id CHAR(36) NOT NULL, register_id CHAR(36) NOT NULL, session_id CHAR(36) NOT NULL, number BIGINT NOT NULL, business_day CHAR(10) NULL, closed_at_gmt DATETIME NOT NULL, expected LONGTEXT NOT NULL, till_expected LONGTEXT NOT NULL, counted LONGTEXT NOT NULL, variance LONGTEXT NOT NULL, perpetual_sales_total DECIMAL(19,4) NOT NULL, breakdowns LONGTEXT NOT NULL, findings LONGTEXT NULL, PRIMARY KEY  (id) )" ) );
			$this->assertSame(
				1,
				$wpdb->insert(
					$legacy,
					array(
						'id' => $closure_id,
						'register_id' => $second['register_id'],
						'session_id' => $second['id'],
						'number' => 2,
						'business_day' => '2026-09-11',
						'closed_at_gmt' => '2026-09-11 12:00:00',
						'expected' => '{"cash":"150.0000"}',
						'till_expected' => '{"cash":"100.0000"}',
						'counted' => '{"cash":"101.0000"}',
						'variance' => '{"cash":"-49.0000"}',
						'perpetual_sales_total' => '80',
						'breakdowns' => '{"transaction_count":0}',
					)
				)
			);
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );

			// Act.
			$unfiltered = $store->last( $first['register_id'] );
			$after = $store->last( $first['register_id'], array( 'after' => '2026-09-11' ) );
			$before = $store->last( $first['register_id'], array( 'before' => '2026-09-10' ) );

			// Assert.
			$this->assertSame( array( $closure_id, 2 ), array( $unfiltered['id'], $unfiltered['number'] ) );
			$this->assertSame( array( $closure_id, 2 ), array( $after['id'], $after['number'] ) );
			$this->assertNull( $before );
		} finally {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
		}
	}

	/** A number more than 10000 past the register sequence is refused; the edge is accepted. */
	public function test_number_far_ahead_of_sequence_is_refused(): void {
		// Arrange.
		$store = new Closure_Store();
		$session = $this->closure_session();

		// Act.
		$refused = $store->create( $this->closure_fields( $session, 10002 ) );
		$status = ( new Register_Session_Store() )->get( $session['id'] )['status'];
		$accepted = $store->create( $this->closure_fields( $session, 10001 ) );

		// Assert.
		$this->assertWPError( $refused );
		$this->assertSame( 'wcpos_closure_number_invalid', $refused->get_error_code() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );
		$this->assertSame( 'counting', $status );
		$this->assertSame( 10001, $accepted['number'] );
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
		$fail = self::fail_closure_write();
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
