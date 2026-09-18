<?php
/**
 * Local register audit logging tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WCPOS\WooCommercePOS\Services\Closure_Print_Counter;
use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Exercise real stores, capturing the existing Logger filter before disk writes. */
class Test_Register_Logging extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture {
		tearDown as private cleanup_closures;
	}

	/**
	 * Captured level and formatted message pairs.
	 *
	 * @var array
	 */
	private $logs = array();

	/**
	 * Previous logger level.
	 *
	 * @var mixed
	 */
	private $previous_level;

	/**
	 * Log counts observed at each ROLLBACK, so a warning's position proves it was
	 * emitted after the rollback that would otherwise erase it under database logging.
	 *
	 * @var int[]
	 */
	private $rollbacks = array();

	/** Capture only this test's audit events. */
	public function setUp(): void {
		parent::setUp();
		$this->previous_level = Logger::$log_level;
		Logger::set_log_level( 'info' );
		Logger::reset_dedup_state();
		add_filter( 'woocommerce_pos_logging', array( $this, 'capture_log' ), 10, 2 );
		add_filter( 'query', array( $this, 'record_rollback' ) );
	}

	/** Restore logging and remove committed fixtures. */
	public function tearDown(): void {
		remove_filter( 'query', array( $this, 'record_rollback' ) );
		remove_filter( 'woocommerce_pos_logging', array( $this, 'capture_log' ), 10 );
		Logger::reset_dedup_state();
		Logger::$log_level = $this->previous_level;
		$this->cleanup_closures();
	}

	/** Capture the actual level and serialized context without writing to disk.
	 *
	 * @param bool   $enabled Whether logging is enabled.
	 * @param string $message Formatted message.
	 */
	public function capture_log( $enabled, $message ): bool {
		$this->logs[] = array( Logger::$log_level, $message );
		return false;
	}

	/** Note how many events had been captured when a transaction rolled back.
	 *
	 * @param string $sql Query about to run.
	 */
	public function record_rollback( $sql ) {
		if ( 'ROLLBACK' === $sql ) {
			$this->rollbacks[] = count( $this->logs );
		}
		return $sql;
	}

	/** Assert an event was emitted only after a ROLLBACK had run.
	 *
	 * WooCommerce's database log handler writes through the same connection as the
	 * store, so a warning logged before the rollback is erased with it.
	 *
	 * @param string $message Event message.
	 */
	private function assert_logged_after_rollback( string $message ): void {
		$index = null;
		foreach ( $this->logs as $position => $entry ) {
			if ( 0 === strpos( $entry[1], $message . ' | Context: ' ) ) {
				$index = $position;
			}
		}
		$this->assertNotNull( $index, $message );
		$this->assertNotEmpty( $this->rollbacks, 'No ROLLBACK ran before: ' . $message );
		$this->assertLessThanOrEqual( $index, min( $this->rollbacks ), $message . ' was logged before the rollback' );
	}

	/** Decode the single-line JSON context of a captured event.
	 *
	 * @param string $formatted Captured formatted message.
	 */
	private function context_of( string $formatted ): array {
		$this->assertStringNotContainsString( "\n", $formatted, 'an event must occupy one log line' );
		$decoded = json_decode( substr( $formatted, strpos( $formatted, ' | Context: ' ) + 12 ), true );
		$this->assertIsArray( $decoded, $formatted );
		return $decoded;
	}

	/** Assert one event's level and allowlisted context values.
	 *
	 * @param string $level Expected level.
	 * @param string $message Event message.
	 * @param array  $context Expected context subset.
	 */
	private function assert_event( string $level, string $message, array $context ): void {
		$matches = array_values(
			array_filter(
				$this->logs,
				static function ( $entry ) use ( $message ) {
					return 0 === strpos( $entry[1], $message . ' | Context: ' );
				}
			)
		);
		$this->assertNotEmpty( $matches, $message );
		$entry = end( $matches );
		$this->assertSame( $level, $entry[0] );
		$decoded = $this->context_of( $entry[1] );
		foreach ( $context as $key => $value ) {
			$this->assertArrayHasKey( $key, $decoded, $message . ' lacks ' . $key );
			$this->assertEquals( $value, $decoded[ $key ], $message . ' ' . $key );
		}
		foreach ( $this->logs as $logged ) {
			$this->assertContains( $logged[0], array( 'info', 'warning' ) );
		}
	}

	/** Register edits retain the actor and actual before/after values, not arbitrary input. */
	public function test_register_update_logs_changed_fields_and_actor(): void {
		$session = $this->closure_session( null, 'open' );
		$store = new Register_Store();
		$this->assert_event(
			'info',
			'Register created',
			array(
				'register_id' => $session['register_id'],
				'name' => 'Closure fixture',
				'default_float' => '',
			)
		);
		$store->update(
			$session['register_id'],
			array(
				'default_float' => '125',
				'name' => 'Front till',
				'password' => 'never-log-this',
			)
		);
		$this->assert_event(
			'info',
			'Register changed',
			array(
				'register_id' => $session['register_id'],
				'user_id' => get_current_user_id(),
			)
		);
		$entry = end( $this->logs )[1];
		$changed = $this->context_of( $entry );
		$this->assertEqualsCanonicalizing( array( 'default_float', 'name' ), $changed['fields'] );
		$this->assertSame( 'Closure fixture', $changed['before']['name'] );
		$this->assertSame( 'Front till', $changed['after']['name'] );
		$this->assertSame( '125.0000', $changed['after']['default_float'] );
		$this->assertStringNotContainsString( 'never-log-this', $entry );
		$this->logs = array();
		$store->update( $session['register_id'], array( 'default_float' => '125.0000' ) );
		$this->assertSame( array(), $this->logs );
	}

	/** Session opening, contention, approval and transitions emit safe context. */
	public function test_session_events_log_success_and_contention(): void {
		$session = $this->closure_session( null, 'open' );
		$store = new Register_Session_Store();
		$this->assert_event(
			'info',
			'Register session opened',
			array(
				'session_id' => $session['id'],
				'register_id' => $session['register_id'],
				'counted_float' => '100.0000',
				'user_id' => get_current_user_id(),
			)
		);
		$this->assertSame( $session, $store->create( array( 'id' => $session['id'] ) ) );
		// An idempotent replay returns the existing row: the success path, not a fault.
		$this->assert_event(
			'info',
			'Register session already recorded; returning the existing row',
			array( 'session_id' => $session['id'] )
		);
		$this->assertWPError(
			$store->create(
				array(
					'id' => wp_generate_uuid4(),
					'register_id' => $session['register_id'],
				)
			)
		);
		$this->assert_event(
			'warning',
			'Register session opening refused: register already has an open session',
			array(
				'register_id' => $session['register_id'],
				'session_id' => $session['id'],
			)
		);
		$manager = self::factory()->user->create();
		$this->assertWPError( $store->approve( $session, $manager ) );
		$this->assert_event(
			'warning',
			'Register session approval refused: status changed or approval already recorded',
			array(
				'session_id' => $session['id'],
				'user_id' => $manager,
				'status' => 'open',
			)
		);
		$counting = $store->transition( $session, array( 'status' => 'counting' ) );
		$this->assert_event(
			'info',
			'Register session state changed',
			array(
				'from' => 'open',
				'to' => 'counting',
			)
		);
		$approved = $store->approve( $counting, $manager );
		$this->assertSame( $manager, $approved['approved_by'] );
		$this->assert_event(
			'info',
			'Register session approved',
			array(
				'session_id' => $session['id'],
				'user_id' => $manager,
			)
		);
		$store->transition(
			$approved,
			array(
				'status' => 'closed',
				'counted' => array( 'cash' => '100' ),
			)
		);
		$this->assert_event(
			'info',
			'Register session state changed',
			array(
				'from' => 'counting',
				'to' => 'closed',
			)
		);
		$this->assertWPError( $store->transition( $session, array( 'status' => 'counting' ) ) );
		$this->assert_event(
			'warning',
			'Register session transition refused: status changed',
			array(
				'session_id' => $session['id'],
				'status' => 'closed',
			)
		);
	}

	/** Variance refusal is local and identifies the approval rule. */
	public function test_session_variance_refusal_logs_rule(): void {
		$session = $this->closure_session();
		$threshold = static function () {
			return '0';
		};
		add_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		try {
			$this->assertWPError(
				( new Register_Session_Store() )->transition(
					$session,
					array(
						'status' => 'closed',
						'counted' => array( 'cash' => '105' ),
					)
				)
			);
			$this->assert_event(
				'warning',
				'Register session closing refused: variance requires manager approval',
				array(
					'session_id' => $session['id'],
					'variance' => '5.0000',
					'threshold' => '0',
				)
			);
		} finally {
			remove_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		}
	}

	/** Movement types, reasons, replay, cutoff and void contention are recorded. */
	public function test_movement_events_log_success_and_refusals(): void {
		$session = $this->closure_session( null, 'open' );
		$store = new Cash_Movement_Store();
		$fields = array(
			'session_id' => $session['id'],
			'actor' => get_current_user_id(),
			'created_at_gmt' => '2026-09-11 10:00:00',
			'reason' => 'Change for drawer',
		);
		foreach ( array( 'paid_in', 'paid_out', 'no_sale', 'void' ) as $type ) {
			$fields['id'] = wp_generate_uuid4();
			$fields['type'] = $type;
			$fields['amount'] = in_array( $type, array( 'paid_in', 'paid_out' ), true ) ? '7' : '0';
			if ( 'void' === $type ) {
				$fields['voids'] = $row['id'];
				$fields['reason'] = '';
			}
			$row = $store->create( $fields );
			$this->assertIsArray( $row );
			$this->assert_event(
				'info',
				'Cash movement accepted',
				array(
					'movement_id' => $row['id'],
					'session_id' => $session['id'],
					'type' => $type,
					'amount' => $row['amount'],
					'reason_given' => 'void' === $type ? '' : '1',
					'reason' => $fields['reason'],
					'user_id' => get_current_user_id(),
				)
			);
		}
		$this->assertSame( $row, $store->create( array( 'id' => $row['id'] ) ) );
		// An idempotent replay returns the existing row: the success path, not a fault.
		$this->assert_event(
			'info',
			'Cash movement already recorded; returning the existing row',
			array( 'movement_id' => $row['id'] )
		);
		$fields['id'] = wp_generate_uuid4();
		$this->assertWPError( $store->create( $fields ) );
		$this->assert_event(
			'warning',
			'Cash movement refused: voids target already voided or unavailable',
			array(
				'movement_id' => $fields['id'],
				'voids' => $fields['voids'],
			)
		);
		$this->assert_logged_after_rollback( 'Cash movement refused: voids target already voided or unavailable' );
		foreach ( array( 'counting', 'closed' ) as $status ) {
			$session['status'] = $status;
			$session['counting_started_at_gmt'] = '2026-09-11 11:00:00';
			$this->logs = array();
			$this->assertTrue( $store->accepts( $session, '2026-09-11 10:59:59' ) );
			$this->assertSame( array(), $this->logs );
			$this->assertFalse( $store->accepts( $session, '2026-09-11 11:00:00' ) );
			$this->assert_event(
				'warning',
				'Cash movement refused: session status or counting cutoff rejects created_at_gmt',
				array(
					'session_id' => $session['id'],
					'status' => $status,
				)
			);
		}
	}

	/** Closure creation, duplicate, correction and resulting print counts. */
	public function test_closure_events_log_written_values_and_recount_replay(): void {
		$session = $this->closure_session();
		$store = new Closure_Store();
		$closure = $store->create( $this->closure_fields( $session ) );
		$this->assertIsArray( $closure );
		$this->assert_event(
			'info',
			'Register closure written',
			array(
				'closure_id' => $closure['id'],
				'number' => 1,
				'register_id' => $session['register_id'],
				'session_id' => $session['id'],
			)
		);
		$this->assertSame( '1.0000', $this->context_of( end( $this->logs )[1] )['variance']['cash'] );
		$this->assertSame( $closure, $store->create( array( 'id' => $closure['id'] ) ) );
		// An idempotent replay returns the existing row: the success path, not a fault.
		$this->assert_event(
			'info',
			'Register closure already recorded; returning the existing row',
			array( 'closure_id' => $closure['id'] )
		);
		$id = wp_generate_uuid4();
		$store->recount( $closure, $id, array( 'cash' => '102' ), 'Coin recount' );
		$this->assert_event(
			'warning',
			'Register closure recount recorded',
			array(
				'closure_id' => $closure['id'],
				'reason' => 'Coin recount',
			)
		);
		$recount = $this->context_of( end( $this->logs )[1] );
		$this->assertSame( '1.0000', $recount['old_variance']['cash'] );
		$this->assertSame( '2.0000', $recount['new_variance']['cash'] );
		Logger::reset_dedup_state();
		$store->recount( $closure, $id, array( 'cash' => '999' ), 'Must not claim new values' );
		$this->assertStringNotContainsString( 'Must not claim new values', end( $this->logs )[1] );
		$counter = new Closure_Print_Counter();
		$document = array(
			'closure' => array( 'id' => $closure['id'] ),
			'fiscal' => array(),
		);
		$render = static function () {
			return 'document';
		};
		foreach ( array(
			1 => 'Register closure printed',
			2 => 'Register closure reprinted',
		) as $count => $message ) {
			$this->assertSame( 'document', $counter->count_after( $document, $render ) );
			$this->assert_event(
				'info',
				$message,
				array(
					'closure_id' => $closure['id'],
					'print_count' => $count,
				)
			);
		}
		// A print whose rendering produces nothing is rolled back and must leave no record.
		$this->logs = array();
		$this->assertSame(
			'',
			$counter->count_after(
				$document,
				static function () {
					return '';
				}
			)
		);
		$this->assertSame( array(), $this->logs );
		$this->assertSame( 2, $store->get( $closure['id'] )['print_count'] );
		$this->assertWPError( $store->create( $this->closure_fields( $session ) ) );
		$this->assert_event(
			'warning',
			'Register closure refused: session already has a closure',
			array(
				'closure_id' => $closure['id'],
				'session_id' => $session['id'],
			)
		);
		$this->assert_logged_after_rollback( 'Register closure refused: session already has a closure' );
	}

	/** Dispatch a v2 write as the current user.
	 *
	 * @param string $route Resource path.
	 * @param array  $fields Request fields.
	 */
	private function post( string $route, array $fields ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/' . $route );
		$request->set_body_params( $fields );
		return $this->server->dispatch( $request );
	}

	/** REST replays answer before the stores run, and void targets are refused at validation. */
	public function test_rest_replays_and_void_refusals_are_logged_by_the_controllers(): void {
		wp_get_current_user()->add_cap( 'access_woocommerce_pos' );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_cash' );
		$session = $this->closure_session( null, 'open' );
		$this->logs = array();
		$this->assertSame( 200, $this->post( 'sessions', array( 'id' => $session['id'] ) )->get_status() );
		$this->assert_event(
			'info',
			'Register session already recorded; returning the existing row',
			array( 'session_id' => $session['id'] )
		);
		$movement = ( new Cash_Movement_Store() )->create(
			array(
				'id' => wp_generate_uuid4(),
				'session_id' => $session['id'],
				'type' => 'paid_in',
				'amount' => '5.0000',
				'reason' => 'Float top-up',
				'actor' => get_current_user_id(),
				'voids' => null,
				'created_at_gmt' => '2026-09-11 10:05:00',
			)
		);
		$this->logs = array();
		$this->assertSame( 200, $this->post( 'movements', array( 'id' => $movement['id'] ) )->get_status() );
		$this->assert_event(
			'info',
			'Cash movement already recorded; returning the existing row',
			array( 'movement_id' => $movement['id'] )
		);
		$void = array(
			'id' => wp_generate_uuid4(),
			'session_id' => $session['id'],
			'type' => 'void',
			'amount' => '0',
			'reason' => '',
			'voids' => wp_generate_uuid4(),
			'created_at' => '2026-09-11T10:06:00Z',
		);
		$this->logs = array();
		$refused = $this->post( 'movements', $void );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'wcpos_movement_void_refused', $refused->get_data()['code'] );
		$this->assert_event(
			'warning',
			'Cash movement refused: voids target already voided or unavailable',
			array(
				'movement_id' => $void['id'],
				'voids' => $void['voids'],
			)
		);
		$session = ( new Register_Session_Store() )->transition( $session, array( 'status' => 'counting' ) );
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$this->logs = array();
		$this->assertSame( 200, $this->post( 'closures', array( 'id' => $closure['id'] ) )->get_status() );
		$this->assert_event(
			'info',
			'Register closure already recorded; returning the existing row',
			array( 'closure_id' => $closure['id'] )
		);
	}

	/** Failures inside the print counter's and the closure's transactions are logged after their rollback. */
	public function test_nested_write_failures_are_logged_after_the_rollback(): void {
		global $wpdb;
		$session = $this->closure_session();
		$store = new Closure_Store();
		$closure = $store->create( $this->closure_fields( $session ) );
		$previous = $wpdb->suppress_errors();
		$fail = static function ( $sql ) use ( $store ) {
			return 0 === strpos( $sql, 'UPDATE ' . $store->table_name() . ' SET print_count' ) ? 'INVALID PRINT STAMP' : $sql;
		};
		add_filter( 'query', $fail );
		$this->logs = array();
		$this->rollbacks = array();
		try {
			( new Closure_Print_Counter() )->count_after(
				array(
					'closure' => array( 'id' => $closure['id'] ),
					'fiscal' => array(),
				),
				static function () {
					return 'document';
				}
			);
			$this->fail( 'Expected print stamp failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Closure print stamp failed.', $error->getMessage() );
			$this->assert_event( 'warning', 'Closure print stamp failed.', array( 'closure_id' => $closure['id'] ) );
			$this->assert_logged_after_rollback( 'Closure print stamp failed.' );
		} finally {
			remove_filter( 'query', $fail );
		}
		$next = $this->closure_session( $session['register_id'] );
		$sessions = new Register_Session_Store();
		$fail = static function ( $sql ) use ( $sessions ) {
			return 0 === strpos( $sql, 'UPDATE `' . $sessions->table_name() . '`' ) ? 'INVALID SESSION WRITE' : $sql;
		};
		add_filter( 'query', $fail );
		$this->logs = array();
		$this->rollbacks = array();
		try {
			$store->create( $this->closure_fields( $next, 2 ) );
			$this->fail( 'Expected session write failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Session write failed.', $error->getMessage() );
			$this->assert_event( 'warning', 'Closure session write failed.', array( 'session_id' => $next['id'] ) );
			$this->assert_logged_after_rollback( 'Closure session write failed.' );
			foreach ( $this->logs as $entry ) {
				$this->assertStringStartsNotWith( 'Register session write refused', $entry[1] );
			}
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}

	/** An operator-entered value cannot break an event across log lines or forge one. */
	public function test_context_stays_on_one_line_and_cannot_forge_an_event(): void {
		$session = $this->closure_session( null, 'open' );
		$this->logs = array();
		$forged = "Window cleaner\n2026-09-16T00:00:00+00:00 ERROR forged event";
		( new Cash_Movement_Store() )->create(
			array(
				'id' => wp_generate_uuid4(),
				'session_id' => $session['id'],
				'type' => 'paid_out',
				'amount' => '5.0000',
				'reason' => $forged,
				'actor' => get_current_user_id(),
				'voids' => null,
				'created_at_gmt' => '2026-09-11 10:05:00',
			)
		);
		$entry = end( $this->logs )[1];
		$this->assertStringNotContainsString( "\n", $entry );
		$this->assertSame( $forged, $this->context_of( $entry )['reason'] );
		$this->assertSame( 1, preg_match_all( '/\bERROR\b/', $entry ), 'the forged severity reaches the file only inside the JSON value' );
	}

	/** A print whose transaction cannot even start is still recorded. */
	public function test_print_transaction_start_failure_is_logged(): void {
		$session = $this->closure_session();
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$fail = static function ( $sql ) {
			return 'START TRANSACTION' === $sql ? 'INVALID START' : $sql;
		};
		global $wpdb;
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		$this->logs = array();
		try {
			( new Closure_Print_Counter() )->count_after(
				array(
					'closure' => array( 'id' => $closure['id'] ),
					'fiscal' => array(),
				),
				static function () {
					return 'document';
				}
			);
			$this->fail( 'Expected transaction failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assert_event( 'warning', 'Closure print transaction failed.', array( 'closure_id' => $closure['id'] ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}

	/** A calculation that throws inside the closure transaction is logged after the rollback. */
	public function test_closure_calculation_failure_is_logged_after_the_rollback(): void {
		global $wpdb;
		$session = $this->closure_session();
		$fields = $this->closure_fields( $session );
		$fail = static function ( $sql ) {
			return 0 === strpos( $sql, 'SELECT' ) && false !== strpos( $sql, 'DECIMAL(65,4)' ) ? 'INVALID CALC' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		$this->logs = array();
		$this->rollbacks = array();
		try {
			( new Closure_Store() )->create( $fields );
			$this->fail( 'Expected calculation failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assert_event( 'warning', $error->getMessage(), array( 'closure_id' => $fields['id'] ) );
			$this->assert_logged_after_rollback( $error->getMessage() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Context JSON cannot carry is still written, on one line, rather than dropped. */
	public function test_context_json_cannot_encode_falls_back_to_a_readable_line(): void {
		$this->logs = array();
		// INF has no JSON form; wp_json_encode() returns false rather than a document.
		Logger::warning(
			'Undecodable context',
			array(
				'raw' => INF,
				'id' => 'keep-me',
			)
		);
		$entry = end( $this->logs )[1];
		$this->assertStringContainsString( ' | Context: ', $entry );
		$this->assertStringContainsString( 'keep-me', $entry );
		$this->assertStringNotContainsString( "\n", $entry );
	}

	/** Rolled-back closure transitions must not be reported as successful. */
	public function test_failed_closure_write_does_not_log_a_committed_state_change(): void {
		global $wpdb;
		$session = $this->closure_session();
		$store = new Closure_Store();
		$fields = $this->closure_fields( $session );
		$this->logs = array();
		$fail = static function ( $sql ) use ( $store ) {
			return 0 === strpos( $sql, 'INSERT INTO `' . $store->table_name() . '`' ) ? 'INVALID CLOSURE WRITE' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			try {
				$store->create( $fields );
				$this->fail( 'Expected closure write failure.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure write failed.', $error->getMessage() );
				$this->assert_event( 'warning', 'Closure write failed.', array( 'closure_id' => $fields['id'] ) );
				$this->assert_logged_after_rollback( 'Closure write failed.' );
				foreach ( $this->logs as $entry ) {
					$this->assertSame( 'warning', $entry[0] );
				}
				$this->assertSame( 'counting', ( new Register_Session_Store() )->get( $session['id'] )['status'] );
			}
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Invalid sessions and sequence regression refusals name the responsible rule. */
	public function test_closure_refusals_log_session_and_number_rules(): void {
		$session = $this->closure_session( null, 'open' );
		$store = new Closure_Store();
		$this->assertWPError( $store->create( $this->closure_fields( $session ) ) );
		$this->assert_event(
			'warning',
			'Register closure refused: session or register missing, or status is not counting or closed',
			array(
				'session_id' => $session['id'],
				'status' => 'open',
			)
		);
		$session = ( new Register_Session_Store() )->transition( $session, array( 'status' => 'counting' ) );
		$store->create( $this->closure_fields( $session, 5 ) );
		$next = $this->closure_session( $session['register_id'] );
		$this->assertWPError( $store->create( $this->closure_fields( $next, 3 ) ) );
		$this->assert_event(
			'warning',
			'Register closure refused: number precedes register sequence',
			array(
				'number' => 3,
				'next_number' => 6,
			)
		);
		$this->assert_logged_after_rollback( 'Register closure refused: number precedes register sequence' );
		$store->create( $this->closure_fields( $next, 5 ) );
		$this->assert_event(
			'warning',
			'Register closure number already exists: assigned next number',
			array(
				'number' => 5,
				'next_number' => 6,
			)
		);
	}
}
