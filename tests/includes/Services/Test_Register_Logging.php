<?php
/**
 * Local register audit logging tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
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

	/** Capture only this test's audit events. */
	public function setUp(): void {
		parent::setUp();
		$this->previous_level = Logger::$log_level;
		Logger::set_log_level( 'info' );
		Logger::reset_dedup_state();
		add_filter( 'woocommerce_pos_logging', array( $this, 'capture_log' ), 10, 2 );
	}

	/** Restore logging and remove committed fixtures. */
	public function tearDown(): void {
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
		foreach ( $context as $key => $value ) {
			$this->assertStringContainsString( '[' . $key . '] => ' . $value, $entry[1] );
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
				'before' => 'Array',
				'after' => 'Array',
				'default_float' => '125.0000',
				'name' => 'Front till',
			)
		);
		$entry = end( $this->logs )[1];
		$this->assertStringContainsString( 'Closure fixture', $entry );
		$this->assertStringContainsString( '[fields] => Array', $entry );
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
		$this->assert_event( 'warning', 'Register session already exists', array( 'session_id' => $session['id'] ) );
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
		$this->assert_event( 'warning', 'Cash movement already exists', array( 'movement_id' => $row['id'] ) );
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
		foreach ( array( 'counting', 'closed' ) as $status ) {
			$session['status'] = $status;
			$session['counting_started_at_gmt'] = '2026-09-11 11:00:00';
			$this->logs = array();
			$this->assertTrue( $store->accepts( $session, '2026-09-11 10:59:59' ) );
			$this->assertSame( array(), $this->logs );
			$this->assertFalse( $store->accepts( $session, '2026-09-11 11:00:00' ) );
			$this->assert_event(
				'warning',
				'Cash movement refused: created_at_gmt is past the counting cutoff',
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
				'cash' => '1.0000',
			)
		);
		$this->assertSame( $closure, $store->create( array( 'id' => $closure['id'] ) ) );
		$this->assert_event( 'warning', 'Register closure already exists', array( 'closure_id' => $closure['id'] ) );
		$id = wp_generate_uuid4();
		$store->recount( $closure, $id, array( 'cash' => '102' ), 'Coin recount' );
		$this->assert_event(
			'warning',
			'Register closure recount recorded',
			array(
				'closure_id' => $closure['id'],
				'old_variance' => 'Array',
				'new_variance' => 'Array',
				'reason' => 'Coin recount',
			)
		);
		$this->assertStringContainsString( '[cash] => 2.0000', end( $this->logs )[1] );
		$this->assertStringContainsString( '[cash] => 1.0000', end( $this->logs )[1] );
		Logger::reset_dedup_state();
		$store->recount( $closure, $id, array( 'cash' => '999' ), 'Must not claim new values' );
		$this->assertStringNotContainsString( 'Must not claim new values', end( $this->logs )[1] );
		for ( $count = 1; $count <= 2; ++$count ) {
			$this->assertSame( $count, $store->record_print( $closure['id'] )['print_count'] );
			$this->assert_event(
				'info',
				'Register closure reprinted',
				array(
					'closure_id' => $closure['id'],
					'print_count' => $count,
				)
			);
		}
		$this->assertWPError( $store->create( $this->closure_fields( $session ) ) );
		$this->assert_event(
			'warning',
			'Register closure refused: session already has a closure',
			array(
				'closure_id' => $closure['id'],
				'session_id' => $session['id'],
			)
		);
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
