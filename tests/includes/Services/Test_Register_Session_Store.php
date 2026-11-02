<?php
/**
 * Register session storage tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Session persistence and ledger-derived balances. */
class Test_Register_Session_Store extends WCPOS_REST_Unit_Test_Case {
	use Session_Expected_Tests;
	use Closure_Test_Fixture;

	/** The session columns a read returned before closures folded into the session row. */
	public const SESSION_KEYS = array( 'id', 'register_id', 'store_id', 'status', 'opened_at_gmt', 'business_day', 'opened_by', 'expected_float', 'counted_float', 'opening_variance', 'counting_started_at_gmt', 'counting_by', 'float_source', 'carried_from_closure_id', 'closed_at_gmt', 'closed_by', 'approved_by', 'approved_counted', 'approved_expected', 'counted', 'closure_id', 'created_at_gmt' );

	/** Carry provenance persists; ordinary openings have none. */
	public function test_create_persists_float_source_and_carried_from_closure_id(): void {
		$store = new Register_Session_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'register_id' => wp_generate_uuid4(),
			'opened_at_gmt' => '2026-09-11 10:00:00',
			'opened_by' => get_current_user_id(),
			'expected_float' => '100',
			'counted_float' => '100',
		);
		$closure_id = wp_generate_uuid4();
		$store->create(
			$fields + array(
				'float_source' => 'carried',
				'carried_from_closure_id' => $closure_id,
			)
		);
		$row = $store->get( $fields['id'] );
		$this->assertSame( 'carried', $row['float_source'] );
		$this->assertSame( $closure_id, $row['carried_from_closure_id'] );
		$fields['id'] = wp_generate_uuid4();
		$fields['register_id'] = wp_generate_uuid4();
		$row = $store->create( $fields );
		$this->assertNull( $row['float_source'] );
		$this->assertNull( $row['carried_from_closure_id'] );
	}

	/** A closure on the session row never widens a session read. */
	public function test_session_read_after_closure_returns_exactly_the_session_columns(): void {
		// Arrange.
		$session = $this->closure_session();
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$store = new Register_Session_Store();

		// Act.
		$row = $store->get( $session['id'] );
		$listed = $store->list( array( 'register_id' => $session['register_id'] ) );

		// Assert.
		$this->assertSame( $closure['id'], $row['closure_id'] );
		$this->assertSame( self::SESSION_KEYS, array_keys( $row ) );
		$this->assertCount( 1, $listed );
		$this->assertSame( self::SESSION_KEYS, array_keys( $listed[0] ) );
	}

	/** Movements on the session row never widen a session read either: they are read through the movement store. */
	public function test_session_read_after_movement_returns_exactly_the_session_columns(): void {
		// Arrange.
		$session = $this->closure_session( null, 'open' );
		$movement = ( new Cash_Movement_Store() )->create(
			array(
				'id' => wp_generate_uuid4(),
				'session_id' => $session['id'],
				'type' => 'paid_in',
				'amount' => '7',
				'reason' => 'Float top-up',
				'actor' => get_current_user_id(),
				'created_at_gmt' => '2026-09-11 10:00:00',
			)
		);
		$store = new Register_Session_Store();

		// Act.
		$row = $store->get( $session['id'] );
		$listed = $store->list( array( 'register_id' => $session['register_id'] ) );

		// Assert.
		$this->assertSame( array( $movement ), ( new Cash_Movement_Store() )->list( $session['id'] ) );
		$this->assertSame( self::SESSION_KEYS, array_keys( $row ) );
		$this->assertCount( 1, $listed );
		$this->assertSame( self::SESSION_KEYS, array_keys( $listed[0] ) );
	}

	/** Opening must fail without inserting when another connection owns the register. */
	public function test_create_register_locked_throws_without_inserting(): void {
		// Arrange.
		$store = new Register_Session_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'register_id' => wp_generate_uuid4(),
			'opened_at_gmt' => '2026-09-11 10:00:00',
			'opened_by' => get_current_user_id(),
			'expected_float' => null,
			'counted_float' => '100',
		);
		$contender = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name = 'wcpos_register_' . $fields['register_id'];
		try {
			$this->assertSame( '1', (string) $contender->get_var( $contender->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) ) );
			// Act.
			try {
				$store->create( $fields );
				$this->fail( 'Expected the register lock failure to be surfaced.' );
			} catch ( \RuntimeException $error ) {
				// Assert.
				$this->assertSame( 'Session register lock failed.', $error->getMessage() );
				$this->assertNull( $store->get( $fields['id'] ) );
			}
		} finally {
			$contender->get_var( $contender->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
			$contender->close();
		}
	}

	/** A same-id opening committed while waiting for the lock is a replay. */
	public function test_create_same_id_appears_at_lock_returns_open_session(): void {
		// Arrange.
		global $wpdb;
		$store = new Register_Session_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'register_id' => wp_generate_uuid4(),
			'opened_at_gmt' => '2026-09-11 10:00:00',
			'opened_by' => get_current_user_id(),
			'expected_float' => null,
			'counted_float' => '100',
		);
		$inserted = null;
		$race = static function ( $sql ) use ( $wpdb, $store, $fields, &$inserted ) {
			if ( false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $fields['register_id'] ) ) {
				$inserted = $wpdb->insert(
					$store->table_name(),
					$fields + array(
						'status' => 'open',
						'created_at_gmt' => '2026-09-11 10:00:00',
					)
				);
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
		// Assert.
		$this->assertSame( 1, $inserted );
		$this->assertNotWPError( $row );
		$this->assertSame( $fields['id'], $row['id'] );
		$this->assertSame( 'open', $row['status'] );
		$this->assertSame( $store->get( $fields['id'] ), $row );
	}

	/** A same-id session opened and closed while waiting remains a replay. */
	public function test_create_same_id_closed_at_lock_returns_closed_session(): void {
		// Arrange.
		global $wpdb;
		$store = new Register_Session_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'register_id' => wp_generate_uuid4(),
			'opened_at_gmt' => '2026-09-11 10:00:00',
			'opened_by' => get_current_user_id(),
			'expected_float' => null,
			'counted_float' => '100',
		);
		$inserted = null;
		$race = static function ( $sql ) use ( $wpdb, $store, $fields, &$inserted ) {
			if ( false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $fields['register_id'] ) ) {
				$inserted = $wpdb->insert(
					$store->table_name(),
					$fields + array(
						'status' => 'closed',
						'created_at_gmt' => '2026-09-11 10:00:00',
					)
				);
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
		// Assert.
		$this->assertSame( 1, $inserted );
		$this->assertNotWPError( $row );
		$this->assertSame( $fields['id'], $row['id'] );
		$this->assertSame( 'closed', $row['status'] );
		$this->assertCount( 1, $store->list( array( 'register_id' => $fields['register_id'] ) ) );
		$this->assertSame( $store->get( $fields['id'] ), $row );
	}

	/** An under-lock refusal must not persist an opening. */
	public function test_create_under_lock_refusal_returns_error_without_inserting(): void {
		// Arrange.
		$store = new Register_Session_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'register_id' => wp_generate_uuid4(),
			'opened_at_gmt' => '2026-09-11 10:00:00',
			'opened_by' => get_current_user_id(),
			'expected_float' => null,
			'counted_float' => '100',
		);
		$error = new \WP_Error( 'rest_invalid_param', 'Unknown register.' );
		// Act.
		$result = $store->create(
			$fields,
			static function () use ( $error ) {
				return $error;
			}
		);
		// Assert.
		$this->assertSame( $error, $result );
		$this->assertNull( $store->get( $fields['id'] ) );
	}

	/** Opening variance, identity replay, non-closed uniqueness and transitions. */
	public function test_create_uniqueness_and_transitions(): void {
		$store = new Register_Session_Store();
		$fields = array(
			'id' => wp_generate_uuid4(),
			'register_id' => wp_generate_uuid4(),
			'opened_at_gmt' => '2026-09-11 10:00:00',
			'opened_by' => get_current_user_id(),
			'expected_float' => '95',
			'counted_float' => '100',
		);
		$row = $store->create( $fields );
		$this->assertSame( '5.0000', $row['opening_variance'] );
		$this->assertSame( 'open', $row['status'] );
		$this->assertSame( $row, $store->create( array( 'id' => $row['id'] ) ) );
		$fields['id'] = wp_generate_uuid4();
		foreach ( array( 'open', 'counting' ) as $status ) {
			$row = $store->transition(
				$row,
				array(
					'status' => $status,
					'counting_started_at_gmt' => 'counting' === $status ? '2026-09-11 11:00:00' : null,
					'counting_by' => 'counting' === $status ? get_current_user_id() : null,
				)
			);
			$this->assertSame( 'counting' === $status ? get_current_user_id() : null, $row['counting_by'] );
			$error = $store->create( $fields );
			$this->assertWPError( $error );
			$this->assertSame( 'wcpos_session_already_open', $error->get_error_code() );
			$this->assertSame( $row['id'], $error->get_error_data()['session_id'] );
		}
		$row = $store->transition(
			$row,
			array(
				'status' => 'open',
				'counting_started_at_gmt' => null,
				'counting_by' => null,
			)
		);
		$this->assertNull( $row['counting_started_at_gmt'] );
		$this->assertNull( $row['counting_by'] );
		$stale = $row;
		$row = $store->transition( $row, array( 'status' => 'counting' ) );
		$row = $store->transition(
			$row,
			array(
				'status' => 'closed',
				'closed_by' => get_current_user_id(),
				'closed_at_gmt' => '2026-09-11 12:00:00',
				'counted' => array( 'cash' => '101.0000' ),
			)
		);
		$this->assertSame( 'closed', $row['status'] );
		$error = $store->transition( $stale, array( 'status' => 'counting' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'wcpos_session_transition_refused', $error->get_error_code() );
		$this->assertSame( array( 'cash' => '101.0000' ), $row['counted'] );
		$fields['expected_float'] = null;
		$this->assertNull( $store->create( $fields )['opening_variance'] );
	}
	/** A read the table cannot answer (a column a stalled upgrade has not added) throws; it is never an empty list. */
	public function test_list_throws_when_the_read_fails(): void {
		global $wpdb;
		$store = new Register_Session_Store();
		$table = $store->table_name();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN counting_by" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, test setup.
		try {
			$this->expectException( \RuntimeException::class );
			$store->list( array() );
		} finally {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN counting_by BIGINT NULL AFTER counting_started_at_gmt" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, test teardown.
		}
	}
}
