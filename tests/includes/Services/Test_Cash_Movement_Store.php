<?php
/**
 * Cash movement storage tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WP_UnitTestCase;

/** Tests write-once movements in the session's array and atomic void refusal. */
class Test_Cash_Movement_Store extends WP_UnitTestCase {
	use Closure_Test_Fixture;

	/** Lock failure must precede rejection of a movement after the counting cutoff. */
	public function test_create_register_locked_throws_before_session_rejection(): void {
		// Arrange.
		$session = $this->closure_session();
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_in',
			'amount' => '7',
			'reason' => 'After counting cutoff',
			'actor' => get_current_user_id(),
			'created_at_gmt' => '2026-09-11 12:00:00',
		);
		$contender = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name = 'wcpos_register_' . $session['register_id'];
		try {
			$this->assertSame( '1', (string) $contender->get_var( $contender->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ) );
			// Act.
			try {
				$store->create( $fields );
				$this->fail( 'Expected lock failure, not the session-not-open return.' );
			} catch ( \RuntimeException $error ) {
				// Assert.
				$this->assertSame( 'Movement register lock failed.', $error->getMessage() );
				$this->assertNull( $store->get( $session['id'], $fields['id'] ) );
			}
		} finally {
			$contender->get_var( $contender->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
			$contender->close();
		}
		$queries = array();
		$trace = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $trace );
		try {
			// Act: with the register available, rejection must use a fresh session read.
			$error = $store->create( $fields );
		} finally {
			remove_filter( 'query', $trace );
		}
		// Assert.
		$sql = implode( "\n", $queries );
		$lock = strpos( $sql, "GET_LOCK( 'wcpos_register_" . $session['register_id'] );
		$this->assertNotFalse( $lock );
		// Anchor on the session read's projection tail: the movements read hits the same table.
		$lookup = strpos( $sql, 'closure_id, created_at_gmt FROM ' . ( new Register_Session_Store() )->table_name() . ' WHERE ', $lock );
		$this->assertNotFalse( $lookup );
		$this->assertGreaterThan( $lock, $lookup );
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_session_not_open', $error->get_error_code() );
		$this->assertNull( $store->get( $session['id'], $fields['id'] ) );
	}

	/** Only nested fiscal locking warns, once even across multiple movements. */
	public function test_create_nested_locks_unsupported_logs_one_warning(): void {
		// Arrange.
		$session = $this->closure_session();
		( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		$reflection = new \ReflectionProperty( Order_Lock::class, 'multi_lock' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, false );
		$previous_level = Logger::$log_level;
		Logger::set_log_level( 'info' );
		Logger::reset_dedup_state();
		$warnings = array();
		$capture = static function ( $enabled, $message ) use ( &$warnings ) {
			if ( 'warning' === Logger::$log_level ) {
				$warnings[] = $message;
			}
			return false;
		};
		add_filter( 'woocommerce_pos_logging', $capture, 10, 2 );
		try {
			// Act / Assert: a register lock alone must not warn.
			$registers = new Register_Store();
			$locked = $registers->lock( $session['register_id'] );
			if ( $locked ) {
				$registers->unlock( $session['register_id'] );
			}
			$this->assertTrue( $locked );
			$this->assertSame( array(), $warnings );
			// Act: both writes reach the nested fiscal sequence lock.
			for ( $i = 0; $i < 2; ++$i ) {
				$row = ( new Cash_Movement_Store() )->create(
					array(
						'id' => wp_generate_uuid4(),
						'session_id' => $session['id'],
						'type' => 'paid_in',
						'amount' => '7',
						'reason' => 'Late arrival',
						'actor' => get_current_user_id(),
						'created_at_gmt' => '2026-09-11 10:00:00',
					)
				);
				$this->assertNotWPError( $row );
			}
			// Assert.
			$this->assertSame( array( 'Register lock nesting is unsupported on this database server; a concurrent close and movement may interleave' ), $warnings );
		} finally {
			remove_filter( 'woocommerce_pos_logging', $capture, 10 );
			Logger::reset_dedup_state();
			Logger::$log_level = $previous_level;
			$reflection->setValue( null, null );
		}
	}

	/** A movement committed while waiting for the register lock is a replay. */
	public function test_create_same_id_appears_at_lock_returns_existing_movement(): void {
		// Arrange.
		global $wpdb;
		$session = $this->closure_session();
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_in',
			'amount' => '7.0000',
			'reason' => 'Concurrent replay',
			'actor' => get_current_user_id(),
			'created_at_gmt' => '2026-09-11 12:00:00',
		);
		$inserted = null;
		$race = static function ( $sql ) use ( $wpdb, $fields, $session, &$inserted ) {
			if ( null === $inserted && false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $session['register_id'] ) ) {
				// The retry that won the race wrote its array while this one waited for the lock.
				$inserted = $wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'movements' => wp_json_encode( array( Cash_Movement_Store::entry( $fields ) ) ) ), array( 'id' => $session['id'] ) );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			// Act.
			$row = $store->create( $fields );
		} finally {
			remove_filter( 'query', $race );
		}
		// Assert: replay succeeds even though a new write would fail the cutoff.
		$this->assertSame( 1, $inserted );
		$this->assertNotWPError( $row );
		$this->assertSame( $fields['id'], $row['id'] );
		$this->assertSame( $store->get( $session['id'], $fields['id'] ), $row );
		$this->assertCount( 1, $store->list( $session['id'] ) );
	}

	/** Create, replay and stamp without changing the original movement. */
	public function test_create_void_and_double_void_refused(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_out',
			'amount' => '7',
			'reason' => 'Change',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		$target = $store->create( $fields );
		$void_fields = array_merge(
			$fields,
			array(
				'id' => wp_generate_uuid4(),
				'type' => 'void',
				'amount' => '0',
				'voids' => $target['id'],
			)
		);

		// Act.
		$target_replay = $store->create(
			array(
				'id' => $target['id'],
				'session_id' => $session['id'],
			)
		);
		$writes = 0;
		$sessions_table = ( new Register_Session_Store() )->table_name();
		$count = static function ( $sql ) use ( $sessions_table, &$writes ) {
			$writes += 0 === strpos( $sql, "UPDATE {$sessions_table} SET movements" ) ? 1 : 0;
			return $sql;
		};
		add_filter( 'query', $count );
		try {
			$void = $store->create( $void_fields );
		} finally {
			remove_filter( 'query', $count );
		}
		$replay = $store->create( $void_fields );
		$void_fields['id'] = wp_generate_uuid4();
		$error = $store->create( $void_fields );

		// Assert.
		$this->assertSame(
			array(
				'id' => $fields['id'],
				'session_id' => $session['id'],
				'type' => 'paid_out',
				'amount' => '7.0000',
				'reason' => 'Change',
				'actor' => 1,
				'voids' => null,
				'voided_by' => null,
				'created_at_gmt' => '2026-09-11 10:00:00',
			),
			$target
		);
		$this->assertSame( $target, $target_replay );
		// The reversal and its target's stamp are one array write.
		$this->assertSame( 1, $writes );
		$this->assertSame( $void, $replay );
		$this->assertSame( '0.0000', $void['amount'] );
		$this->assertSame( $target['id'], $void['voids'] );
		$target['voided_by'] = $void['id'];
		$this->assertSame( $target, $store->get( $session['id'], $target['id'] ) );
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_movement_void_refused', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertNull( $store->get( $session['id'], $void_fields['id'] ) );
		$this->assertCount( 2, $store->list( $session['id'] ) );
	}

	/** A database error on the array write must leave neither the void nor its stamp. */
	public function test_failed_stamp_rolls_back_void_insert(): void {
		// Arrange.
		global $wpdb;
		$session = $this->closure_session( null, 'open' );
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'no_sale',
			'amount' => '0',
			'reason' => 'Check',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		$target = $store->create( $fields );
		$fields = array_merge(
			$fields,
			array(
				'id' => wp_generate_uuid4(),
				'type' => 'void',
				'voids' => $target['id'],
			)
		);
		$sessions = ( new Register_Session_Store() )->table_name();
		$writes = 0;
		$fail = static function ( $sql ) use ( $sessions, &$writes ) {
			if ( 0 === strpos( $sql, "UPDATE {$sessions} SET movements" ) ) {
				++$writes;
				return 'INVALID VOID STAMP';
			}
			return $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			// Act.
			try {
				$store->create( $fields );
				$this->fail( 'Expected the stamp failure to be surfaced.' );
			} catch ( \RuntimeException $error ) {
				// Assert.
				$this->assertSame( 'Movement write failed.', $error->getMessage() );
			}
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
		// One statement carries the reversal and the stamp, so its failure leaves neither.
		$this->assertSame( 1, $writes );
		$this->assertNull( $store->get( $session['id'], $fields['id'] ) );
		$this->assertSame( array( $target ), $store->list( $session['id'] ) );
	}

	/**
	 * An amount the strict DECIMAL(19,4) column refused is refused, never clamped or read as zero.
	 *
	 * @dataProvider unstorable_amounts
	 * @param string $amount Submitted amount.
	 */
	public function test_create_unstorable_amount_throws_without_writing( string $amount ): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_in',
			'amount' => $amount,
			'reason' => 'Bad amount',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);

		// Act.
		try {
			$store->create( $fields );
			$this->fail( 'Expected the amount to be refused.' );
		} catch ( \RuntimeException $error ) {
			// Assert.
			$this->assertSame( 'Movement amount cast failed.', $error->getMessage() );
		}
		$this->assertSame( array(), $store->list( $session['id'] ) );
	}

	/** Amounts the old column could not hold. */
	public function unstorable_amounts(): array {
		return array(
			'non-numeric' => array( 'seven' ),
			'out of range' => array( '1234567890123456' ),
		);
	}

	/** A movement lives on its session row, so one for a session that does not exist is refused, never orphaned. */
	public function test_create_without_session_throws_without_writing(): void {
		// Arrange.
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => wp_generate_uuid4(),
			'type' => 'paid_in',
			'amount' => '7',
			'reason' => 'No drawer',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);

		// Act.
		try {
			$store->create( $fields );
			$this->fail( 'Expected a movement without a session to be refused.' );
		} catch ( \RuntimeException $error ) {
			// Assert.
			$this->assertSame( 'Movement session missing.', $error->getMessage() );
		}
		$this->assertSame( array(), $store->list( $fields['session_id'] ) );
	}

	/** The key is (session, uuid): the same uuid in another session is a distinct movement, not a replay. */
	public function test_create_same_uuid_in_another_session_records_a_distinct_movement(): void {
		// Arrange.
		$first = $this->closure_session( null, 'open' );
		$second = $this->closure_session( null, 'open' );
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $first['id'],
			'type' => 'paid_in',
			'amount' => '7',
			'reason' => 'First drawer',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		$original = $store->create( $fields );

		// Act.
		$distinct = $store->create(
			array_merge(
				$fields,
				array(
					'session_id' => $second['id'],
					'amount' => '9',
					'reason' => 'Second drawer',
				)
			)
		);

		// Assert.
		$this->assertSame( $second['id'], $distinct['session_id'] );
		$this->assertSame( '9.0000', $distinct['amount'] );
		$this->assertSame( array( $original ), $store->list( $first['id'] ) );
		$this->assertSame( array( $distinct ), $store->list( $second['id'] ) );
	}

	/** A uuid already holding another session's late movement is refused (its correction would be absorbed); a same-session replay still dedupes. */
	public function test_late_movement_uuid_reused_in_another_session_returns_409_and_keeps_one_correction(): void {
		// Arrange.
		$sessions = array( $this->closure_session(), $this->closure_session() );
		$closures = array();
		foreach ( $sessions as $session ) {
			$closures[] = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		}
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $sessions[0]['id'],
			'type' => 'paid_in',
			'amount' => '7',
			'reason' => 'Late arrival',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		$first = $store->create( $fields );

		// Act.
		$reused = $store->create( array_merge( $fields, array( 'session_id' => $sessions[1]['id'] ) ) );
		$replay = $store->create( $fields );

		// Assert.
		$this->assertIsArray( $first );
		$this->assertWPError( $reused );
		$this->assertSame( 'wcpos_movement_id_reused', $reused->get_error_code() );
		$this->assertSame( 409, $reused->get_error_data()['status'] );
		$this->assertSame( array(), $store->list( $sessions[1]['id'] ) );
		$this->assertSame( $first, $replay );
		$fiscal = new Fiscal_Record_Store();
		foreach ( array( 1, 0 ) as $index => $expected ) {
			$this->assertCount(
				$expected,
				$fiscal->list(
					array(
						'type' => 'late_movement',
						'closure_id' => $closures[ $index ]['id'],
					)
				)
			);
		}
		$this->assertSame( array( '7.0000' ), array_column( array_column( ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->corrections_for( $closures[0]['id'] ), 'figures' ), 'cash_delta' ) );
	}

	/** Two registers race the same uuid past the register-lock check: the loser's late movement is refused, never committed without its correction. */
	public function test_late_movement_uuid_claimed_by_another_session_during_write_returns_409_and_stores_nothing(): void {
		// Arrange.
		global $wpdb;
		$sessions = array( $this->closure_session(), $this->closure_session() );
		$closures = array();
		foreach ( $sessions as $session ) {
			$closures[] = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		}
		$store = new Cash_Movement_Store();
		$fiscal = new Fiscal_Record_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $sessions[0]['id'],
			'type' => 'paid_in',
			'amount' => '7',
			'reason' => 'Late arrival',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		$competitor = array_merge( $fields, array( 'session_id' => $sessions[1]['id'] ) );
		$raced = false;
		$race = static function ( $sql ) use ( $fiscal, $competitor, $closures, $sessions, &$race, &$raced ) {
			if ( 'START TRANSACTION' === $sql ) {
				// The other register's writer records its correction first.
				remove_filter( 'query', $race );
				$raced = null !== $fiscal->record(
					array(
						'type' => 'late_movement',
						'source_id' => $competitor['id'],
						'closure_id' => $closures[1]['id'],
						'session_id' => $sessions[1]['id'],
						'register_id' => $sessions[1]['register_id'],
						'store_id' => 456,
						'cashier_id' => 1,
						'payload' => $competitor,
					)
				);
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			// Act.
			$result = $store->create( $fields );
		} finally {
			remove_filter( 'query', $race );
		}

		// Assert.
		$this->assertTrue( $raced );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_movement_id_reused', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $store->list( $sessions[0]['id'] ) );
		$this->assertSame( $sessions[1]['id'], $fiscal->find_by_source( 'late_movement', $fields['id'] )['session_id'] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fiscal->table_name()} WHERE type = 'late_movement' AND source_id = %s", $fields['id'] ) ) );
	}

	/** Mid-fold, a void old code wrote after the session was copied stamps its legacy target: the stamp wins over the stale folded copy. */
	public function test_list_mid_fold_legacy_void_stamp_wins_over_folded_target(): void {
		// Arrange.
		global $wpdb;
		$session = $this->closure_session( null, 'open' );
		$store = new Cash_Movement_Store();
		$target = $store->create(
			array(
				'id' => wp_generate_uuid4(),
				'session_id' => $session['id'],
				'type' => 'paid_out',
				'amount' => '5',
				'reason' => 'Change',
				'actor' => 1,
				'created_at_gmt' => '2026-09-11 10:00:00',
			)
		);
		$void = array_merge(
			$target,
			array(
				'id' => wp_generate_uuid4(),
				'type' => 'void',
				'amount' => '0.0000',
				'reason' => '',
				'voids' => $target['id'],
				'created_at_gmt' => '2026-09-11 10:05:00',
			)
		);
		$stamped = array_merge( $target, array( 'voided_by' => $void['id'] ) );
		$legacy = $wpdb->prefix . Cash_Movement_Store::LEGACY_TABLE;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table, fixed DDL.
		$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$legacy} ( id CHAR(36) NOT NULL, session_id CHAR(36) NOT NULL, type VARCHAR(16) NOT NULL, amount DECIMAL(19,4) NOT NULL, reason TEXT NOT NULL, actor BIGINT NOT NULL, voids CHAR(36) NULL, voided_by CHAR(36) NULL, created_at_gmt DATETIME NOT NULL, PRIMARY KEY  (id) )" ) );
		try {
			$this->assertSame( 1, $wpdb->insert( $legacy, $stamped ) );
			$this->assertSame( 1, $wpdb->insert( $legacy, $void ) );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );

			// Act.
			$listed = $store->list( $session['id'] );
		} finally {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
		}

		// Assert.
		$this->assertSame( array( $stamped, $void ), $listed );
	}

	/** A void whose target another request voided while this one waited is refused on the fresh read. */
	public function test_create_void_target_voided_at_lock_refuses_without_writing(): void {
		// Arrange.
		global $wpdb;
		$session = $this->closure_session( null, 'open' );
		$store = new Cash_Movement_Store();
		$target = $store->create(
			array(
				'id' => wp_generate_uuid4(),
				'session_id' => $session['id'],
				'type' => 'paid_out',
				'amount' => '5',
				'reason' => 'Change',
				'actor' => 1,
				'created_at_gmt' => '2026-09-11 10:00:00',
			)
		);
		$winner = array_merge(
			$target,
			array(
				'id' => wp_generate_uuid4(),
				'type' => 'void',
				'amount' => '0.0000',
				'voids' => $target['id'],
			)
		);
		$won = array( Cash_Movement_Store::entry( array_merge( $target, array( 'voided_by' => $winner['id'] ) ) ), Cash_Movement_Store::entry( $winner ) );
		$sessions = ( new Register_Session_Store() )->table_name();
		$raced = false;
		$race = static function ( $sql ) use ( $wpdb, $sessions, $session, $won, &$raced ) {
			if ( ! $raced && false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $session['register_id'] ) ) {
				$raced = true;
				$wpdb->update( $sessions, array( 'movements' => wp_json_encode( $won ) ), array( 'id' => $session['id'] ) );
			}
			return $sql;
		};
		$loser = array_merge( $winner, array( 'id' => wp_generate_uuid4() ) );
		add_filter( 'query', $race );
		try {
			// Act.
			$error = $store->create( $loser );
		} finally {
			remove_filter( 'query', $race );
		}

		// Assert.
		$this->assertTrue( $raced );
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_movement_void_refused', $error->get_error_code() );
		$this->assertNull( $store->get( $session['id'], $loser['id'] ) );
		$this->assertSame( $winner['id'], $store->get( $session['id'], $target['id'] )['voided_by'] );
		$this->assertCount( 2, $store->list( $session['id'] ) );
	}

	/**
	 * Mid-fold a session's movements still in a legacy table, or its renamed copy, are merged in, once each, in order.
	 *
	 * @dataProvider legacy_table_suffixes
	 * @param string $suffix Legacy name suffix.
	 */
	public function test_list_mid_fold_merges_legacy_rows_without_duplicates( string $suffix ): void {
		// Arrange.
		global $wpdb;
		$session = $this->closure_session( null, 'open' );
		$store = new Cash_Movement_Store();
		$folded = $store->create(
			array(
				'id' => wp_generate_uuid4(),
				'session_id' => $session['id'],
				'type' => 'paid_in',
				'amount' => '7',
				'reason' => 'Already folded',
				'actor' => 1,
				'created_at_gmt' => '2026-09-11 10:00:00',
			)
		);
		$pending = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'paid_out',
			'amount' => '3.0000',
			'reason' => 'Still legacy',
			'actor' => 1,
			'voids' => null,
			'voided_by' => null,
			'created_at_gmt' => '2026-09-11 09:00:00',
		);
		$legacy = $wpdb->prefix . Cash_Movement_Store::LEGACY_TABLE . $suffix;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table, fixed DDL.
		$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$legacy} ( id CHAR(36) NOT NULL, session_id CHAR(36) NOT NULL, type VARCHAR(16) NOT NULL, amount DECIMAL(19,4) NOT NULL, reason TEXT NOT NULL, actor BIGINT NOT NULL, voids CHAR(36) NULL, voided_by CHAR(36) NULL, created_at_gmt DATETIME NOT NULL, PRIMARY KEY  (id) )" ) );
		try {
			$this->assertSame( 1, $wpdb->insert( $legacy, $pending ) );
			// A movement in both places (copied, table not yet dropped) is listed once.
			$this->assertSame( 1, $wpdb->insert( $legacy, $folded ) );
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );

			// Act.
			$listed = $store->list( $session['id'] );
			$found = $store->get( $session['id'], $pending['id'] );
		} finally {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION );
			$wpdb->query( "DROP TABLE IF EXISTS {$legacy}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
		}

		// Assert.
		$this->assertSame( array( $pending, $folded ), $listed );
		$this->assertSame( $pending, $found );
	}

	/** The legacy name and the name the fold renames it to. */
	public function legacy_table_suffixes(): array {
		return array(
			'legacy' => array( '' ),
			'renamed' => array( '_folded' ),
		);
	}

	/** Closure classification and movement insertion are inside the register lock. */
	public function test_movement_reads_the_closure_only_under_the_register_lock(): void {
		$session = $this->closure_session();
		$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		$queries = array();
		$trace = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		$store = new Cash_Movement_Store();
		add_filter( 'query', $trace );
		try {
			$store->create(
				array(
					'id' => wp_generate_uuid4(),
					'session_id' => $session['id'],
					'type' => 'paid_in',
					'amount' => '7',
					'reason' => 'Late arrival',
					'actor' => 1,
					'created_at_gmt' => '2026-09-11 10:00:00',
				)
			);
		} finally {
			remove_filter( 'query', $trace );
		}
		$sql = implode( "\n", $queries );
		$lock = strpos( $sql, "GET_LOCK( 'wcpos_register_" . $session['register_id'] );
		$lookup = strpos( $sql, ', closure FROM ' . ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->table_name() . ' WHERE number IS NOT NULL AND id =' );
		$insert = strpos( $sql, 'UPDATE ' . ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->table_name() . ' SET movements' );
		$release = strpos( $sql, "RELEASE_LOCK( 'wcpos_register_" . $session['register_id'] );
		foreach ( array( $lock, $lookup, $insert, $release ) as $position ) {
			$this->assertNotFalse( $position );
		}
		$this->assertLessThan( $lookup, $lock );
		$this->assertGreaterThan( $insert, $release );
		$this->assertCount(
			1,
			( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->list(
				array(
					'type' => 'late_movement',
					'closure_id' => $closure['id'],
				)
			)
		);
	}

	/**
	 * A competing connection cannot allocate until the movement commits or rolls back.
	 *
	 * @dataProvider sequence_transaction_outcomes
	 * @param bool $fail_commit Whether to force a rollback after the fiscal write.
	 */
	public function test_late_movements_hold_sequence_until_transaction_ends( bool $fail_commit ): void {
		global $wpdb;
		$sessions = array( $this->closure_session(), $this->closure_session() );
		foreach ( $sessions as $session ) {
			( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create( $this->closure_fields( $session ) );
		}
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $sessions[0]['id'],
			'type' => 'paid_in',
			'amount' => '7',
			'reason' => 'Late arrival',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		$store = new Cash_Movement_Store();
		$fiscal = new Fiscal_Record_Store();
		$before = (int) get_option( 'wcpos_fiscal_sequence_late_movement', 0 );
		$contender = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$attempts = array();
		$probe = static function ( $sql ) use ( $contender, &$attempts, $fail_commit ) {
			if ( in_array( $sql, array( 'COMMIT', 'ROLLBACK' ), true ) ) {
				// Interleave the second writer's first step before the outer transaction ends.
				$attempts[] = (int) $contender->get_var( "SELECT GET_LOCK( 'wcpos_fiscal_sequence_lock', 0 )" );
				$contender->get_var( "SELECT RELEASE_LOCK( 'wcpos_fiscal_sequence_lock' )" );
			}
			return $fail_commit && 'COMMIT' === $sql ? 'INVALID MOVEMENT COMMIT' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $probe );
		try {
			try {
				$this->assertIsArray( $store->create( $fields ) );
				$this->assertFalse( $fail_commit, 'Expected commit failure.' );
			} catch ( \RuntimeException $error ) {
				$this->assertTrue( $fail_commit );
				$this->assertSame( 'Movement commit failed.', $error->getMessage() );
			}
		} finally {
			remove_filter( 'query', $probe );
			$wpdb->suppress_errors( $previous );
			$available = (int) $contender->get_var( "SELECT GET_LOCK( 'wcpos_fiscal_sequence_lock', 0 )" );
			$contender->get_var( "SELECT RELEASE_LOCK( 'wcpos_fiscal_sequence_lock' )" );
			$contender->close();
		}
		$this->assertSame( $fail_commit ? array( 0, 0 ) : array( 0 ), $attempts );
		$this->assertSame( 1, $available );
		$first = $fiscal->find_by_source( 'late_movement', $fields['id'] );
		if ( $fail_commit ) {
			$this->assertNull( $store->get( $fields['session_id'], $fields['id'] ) );
			$this->assertNull( $first );
		} else {
			$this->assertSame( $before + 1, $first['number'] );
		}
		// Resume the other register's writer after the first has released serialization.
		$fields['id'] = wp_generate_uuid4();
		$fields['session_id'] = $sessions[1]['id'];
		$this->assertIsArray( $store->create( $fields ) );
		$second = $fiscal->find_by_source( 'late_movement', $fields['id'] );
		$this->assertSame( $before + ( $fail_commit ? 1 : 2 ), $second['number'] );
	}

	/** Transaction exits that must retain sequence ownership. */
	public function sequence_transaction_outcomes(): array {
		return array(
			'commit' => array( false ),
			'rollback' => array( true ),
		);
	}
}
