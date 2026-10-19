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

/** Tests write-once movements and atomic void refusal. */
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
				$this->assertNull( $store->get( $fields['id'] ) );
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
		$lookup = strpos( $sql, 'SELECT * FROM ' . ( new Register_Session_Store() )->table_name(), $lock );
		$this->assertNotFalse( $lookup );
		$this->assertGreaterThan( $lock, $lookup );
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_session_not_open', $error->get_error_code() );
		$this->assertNull( $store->get( $fields['id'] ) );
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
			'amount' => '7',
			'reason' => 'Concurrent replay',
			'actor' => get_current_user_id(),
			'created_at_gmt' => '2026-09-11 12:00:00',
		);
		$inserted = null;
		$race = static function ( $sql ) use ( $wpdb, $store, $fields, $session, &$inserted ) {
			if ( false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $session['register_id'] ) ) {
				$inserted = $wpdb->insert( $store->table_name(), $fields );
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
		$this->assertSame( $store->get( $fields['id'] ), $row );
	}

	/** Create, replay and stamp without changing the original movement. */
	public function test_create_void_and_double_void_refused(): void {
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => wp_generate_uuid4(),
			'type' => 'paid_out',
			'amount' => '7',
			'reason' => 'Change',
			'actor' => 1,
			'created_at_gmt' => '2026-09-11 10:00:00',
		);
		$target = $store->create( $fields );
		$this->assertSame( '7.0000', $target['amount'] );
		$this->assertSame( $target, $store->create( array( 'id' => $target['id'] ) ) );
		$fields = array_merge(
			$fields,
			array(
				'id' => wp_generate_uuid4(),
				'type' => 'void',
				'amount' => '0',
				'voids' => $target['id'],
			)
		);
		try {
			$void = $store->create( $fields );
			$this->assertSame( $void, $store->create( $fields ) );
			$target['voided_by'] = $void['id'];
			$this->assertSame( $target, $store->get( $target['id'] ) );
			$fields['id'] = wp_generate_uuid4();
			$error = $store->create( $fields );
			$this->assertWPError( $error );
			$this->assertSame( 'wcpos_movement_void_refused', $error->get_error_code() );
			$this->assertSame( 409, $error->get_error_data()['status'] );
			$this->assertNull( $store->get( $fields['id'] ) );
			$this->assertCount( 2, $store->list( $target['session_id'] ) );
		} finally {
			global $wpdb;
			$wpdb->query( 'ROLLBACK' );
			$wpdb->delete( $store->table_name(), array( 'session_id' => $target['session_id'] ) );
			$wpdb->query( 'COMMIT' );
		}
	}

	/** A database error after the insert must roll back the void row. */
	public function test_failed_stamp_rolls_back_void_insert(): void {
		global $wpdb;
		$store = new Cash_Movement_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'session_id' => wp_generate_uuid4(),
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
		$fail = static function ( $sql ) use ( $store ) {
			return 0 === strpos( $sql, 'UPDATE `' . $store->table_name() . '`' ) ? 'INVALID VOID STAMP' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			try {
				$store->create( $fields );
				$this->fail( 'Expected the stamp failure to be surfaced.' );
			} catch ( \RuntimeException $error ) {
				$this->assertNull( $store->get( $fields['id'] ) );
				$this->assertSame( $target, $store->get( $target['id'] ) );
			}
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
			$wpdb->query( 'ROLLBACK' );
			$wpdb->delete( $store->table_name(), array( 'session_id' => $target['session_id'] ) );
			$wpdb->query( 'COMMIT' );
		}
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
		$insert = strpos( $sql, 'INSERT INTO `' . $store->table_name() . '`' );
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
			$this->assertNull( $store->get( $fields['id'] ) );
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
