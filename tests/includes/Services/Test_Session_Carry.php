<?php
/**
 * Carrying a drawer between registers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Session_Carry;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Real stores pin the close-before-open sequence and replay. */
class Test_Session_Carry extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture {
		tearDown as fixture_teardown;
	}

	/** The sale sequence option before the test; the fixture's COMMIT makes the ledger's bump durable.
	 *
	 * @var string|null
	 */
	private $sale_sequence;

	/** Remember the sale sequence the ledger will bump. */
	public function setUp(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit lifecycle method.
		parent::setUp();
		$this->sale_sequence = get_option( 'wcpos_fiscal_sequence_sale', null );
	}

	/** Remove the committed rows, then restore the sale sequence for the fiscal store's own tests. */
	public function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit lifecycle method.
		$this->fixture_teardown();
		if ( null === $this->sale_sequence ) {
			delete_option( 'wcpos_fiscal_sequence_sale' );
		} else {
			update_option( 'wcpos_fiscal_sequence_sale', $this->sale_sequence, false );
		}
	}

	/** Client-owned identities for one act. */
	private function ids(): array {
		return array(
			'closure_id' => wp_generate_uuid4(),
			'session_id' => wp_generate_uuid4(),
		);
	}

	/** An empty target with cleanup even when the carry commits its opening. */
	private function target(): array {
		$this->use_multi_register_repository();
		$target = $this->create_register( array( 'store_id' => 789 ) );
		$this->closure_registers[] = $target['id'];
		return $target;
	}

	/** Cash from the ledger, not a device count, becomes the target float. */
	public function test_carry_closes_at_expected_and_opens_with_carried_cash(): void {
		global $wpdb;
		$sessions = new Register_Session_Store();
		$source = $this->closure_session( null, 'open' );
		$wpdb->update(
			$sessions->table_name(),
			array(
				'counted_float' => '0',
				'business_day' => '2026-09-11',
			),
			array( 'id' => $source['id'] )
		);
		$source = $sessions->get( $source['id'] );
		$target = $this->target();
		$ids = $this->ids();
		$order = new \WC_Order();
		$order->set_total( '100' );
		// The register on the order keys its fiscal row for the fixture's committed-row cleanup.
		$order->update_meta_data( '_wcpos_register', $source['register_id'] );
		$order->save();
		Ledger::instance()->record(
			$order,
			array(
				'id' => wp_generate_uuid4(),
				'method_id' => 'pos_cash',
				'amount' => '100',
				'session_id' => $source['id'],
			)
		);
		try {
			$result = ( new Session_Carry() )->carry( $source, $target, $ids, '2026-09-11 12:00:00' );
			$this->assertNotWPError( $result );
			$closure = $result['closure'];
			$this->assertSame( array( 'cash' => '100.0000' ), $closure['counted'] );
			$this->assertSame( $closure['counted'], $closure['expected'] );
			$this->assertSame( array( 'cash' => '0.0000' ), $closure['variance'] );
			$this->assertSame( 'store_switch', $closure['reason'] );
			$this->assertSame( $target['id'], $closure['carried_to_register_id'] );
			$this->assertSame( 1, $closure['number'] );
			$this->assertSame( '100.0000', $closure['period_sales_total'] );
			$this->assertNull( $closure['findings'] );
			$this->assertSame( 'carried', $result['session']['float_source'] );
			$this->assertSame( $ids['closure_id'], $result['session']['carried_from_closure_id'] );
			$this->assertSame( '100.0000', $result['session']['counted_float'] );
			$this->assertSame( '100.0000', $result['session']['expected_float'] );
			$this->assertSame( '0.0000', $result['session']['opening_variance'] );
			$this->assertSame( 'open', $result['session']['status'] );
			$this->assertSame( 789, $result['session']['store_id'] );
			$this->assertSame( '2026-09-11', $result['session']['business_day'] );
			$this->assertSame( '2026-09-11 12:00:00', $result['session']['opened_at_gmt'] );
			$closed = $sessions->get( $source['id'] );
			$this->assertSame( 'closed', $closed['status'] );
			$this->assertSame( $ids['closure_id'], $closed['closure_id'] );
			$this->assertSame( get_current_user_id(), $closed['counting_by'] );
		} finally {
			$order->delete( true );
		}
	}

	/** A replay returns both original rows and performs no SQL writes. */
	public function test_carry_replay_returns_existing_rows_without_writes(): void {
		$sessions = new Register_Session_Store();
		$source = $this->closure_session( null, 'open' );
		$target = $this->target();
		$ids = $this->ids();
		$carry = new Session_Carry();
		$first = $carry->carry( $source, $target, $ids, '2026-09-11 12:00:00' );
		$before = $sessions->list( array() );
		$closures = ( new Closure_Store() )->list( array() );
		$writes = array();
		$observe = static function ( $sql ) use ( &$writes, $sessions ) {
			if ( preg_match( '/^(INSERT|UPDATE|DELETE)/i', $sql ) && false !== strpos( $sql, $sessions->table_name() ) ) {
				$writes[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$replay = $carry->carry( $sessions->get( $source['id'] ), $target, $ids, '2026-09-11 13:00:00' );
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame( $first, $replay );
		$this->assertSame( array(), $writes );
		$this->assertSame( $before, $sessions->list( array() ) );
		$this->assertSame( $closures, ( new Closure_Store() )->list( array() ) );
	}

	/** Counting must finish or be abandoned before carrying. */
	public function test_carry_refuses_a_counting_source(): void {
		$source = $this->closure_session();
		$result = ( new Session_Carry() )->carry( $source, $this->target(), $this->ids(), '2026-09-11 12:00:00' );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_session_transition_refused', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $source, ( new Register_Session_Store() )->get( $source['id'] ) );
	}

	/** An occupied target leaves the source untouched. */
	public function test_carry_refuses_an_open_target(): void {
		$source = $this->closure_session( null, 'open' );
		$target = $this->target();
		$occupied = $this->closure_session( $target['id'], 'open' );
		$result = ( new Session_Carry() )->carry( $source, $target, $this->ids(), '2026-09-11 12:00:00' );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_session_already_open', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $occupied['id'], $result->get_error_data()['session_id'] );
		$this->assertSame( $source, ( new Register_Session_Store() )->get( $source['id'] ) );
	}

	/** A closure id that belongs to another session is refused and the source resumes selling. */
	public function test_carry_refuses_a_foreign_closure_id_and_resumes_selling(): void {
		$other = $this->closure_session();
		$foreign = ( new Closure_Store() )->create( $this->closure_fields( $other ) );
		$source = $this->closure_session( null, 'open' );
		$ids = array_merge( $this->ids(), array( 'closure_id' => $foreign['id'] ) );
		$result = ( new Session_Carry() )->carry( $source, $this->target(), $ids, '2026-09-11 12:00:00' );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_closure_session_invalid', $result->get_error_code() );
		$after = ( new Register_Session_Store() )->get( $source['id'] );
		$this->assertSame( 'open', $after['status'] );
		$this->assertNull( $after['counting_by'] );
		$this->assertNull( $after['closure_id'] );
		$this->assertNull( ( new Register_Session_Store() )->get( $ids['session_id'] ) );
	}

	/** A session id that already names another session is refused before the source changes. */
	public function test_carry_refuses_a_session_id_that_names_another_session(): void {
		$source = $this->closure_session( null, 'open' );
		$ids = array_merge( $this->ids(), array( 'session_id' => $source['id'] ) );
		$result = ( new Session_Carry() )->carry( $source, $this->target(), $ids, '2026-09-11 12:00:00' );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_session_transition_refused', $result->get_error_code() );
		$this->assertSame( $source, ( new Register_Session_Store() )->get( $source['id'] ) );
		$this->assertNull( ( new Closure_Store() )->get( $ids['closure_id'] ) );
	}

	/** A sale landing between the request and the closure is in the carried cash. */
	public function test_carry_counts_the_snapshot_expected_not_an_earlier_read(): void {
		global $wpdb;
		$sessions = new Register_Session_Store();
		$source = $this->closure_session( null, 'open' );
		$wpdb->update( $sessions->table_name(), array( 'counted_float' => '0' ), array( 'id' => $source['id'] ) );
		$source = $sessions->get( $source['id'] );
		$target = $this->target();
		$ids = $this->ids();
		$landed = false;
		$late = function ( $sql ) use ( $source, &$landed ) {
			if ( ! $landed && false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $source['register_id'] ) ) {
				$landed = true;
				$order = new \WC_Order();
				$order->set_total( '20' );
				$order->update_meta_data( '_wcpos_register', $source['register_id'] );
				$order->save();
				Ledger::instance()->record(
					$order,
					array(
						'id' => wp_generate_uuid4(),
						'method_id' => 'pos_cash',
						'amount' => '20',
						'session_id' => $source['id'],
					)
				);
			}
			return $sql;
		};
		add_filter( 'query', $late );
		try {
			$result = ( new Session_Carry() )->carry( $source, $target, $ids, '2026-09-11 12:00:00' );
		} finally {
			remove_filter( 'query', $late );
		}
		$this->assertTrue( $landed );
		$this->assertIsArray( $result );
		$this->assertSame( '20.0000', $result['closure']['counted']['cash'] );
		$this->assertSame( '20.0000', $result['closure']['expected']['cash'] );
		$this->assertSame( '20.0000', $result['session']['counted_float'] );
	}

	/** A count another device starts under the source lock is left alone. */
	public function test_carry_refuses_a_source_that_starts_counting_under_the_lock(): void {
		$source = $this->closure_session( null, 'open' );
		$target = $this->target();
		$ids = $this->ids();
		$counted = false;
		$count = function ( $sql ) use ( $source, &$counted ) {
			if ( ! $counted && false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $source['register_id'] ) ) {
				$counted = true;
				( new Register_Session_Store() )->transition(
					$source,
					array(
						'status' => 'counting',
						'counting_started_at_gmt' => '2026-09-11 11:59:00',
						'counting_by' => 77,
					)
				);
			}
			return $sql;
		};
		add_filter( 'query', $count );
		try {
			$result = ( new Session_Carry() )->carry( $source, $target, $ids, '2026-09-11 12:00:00' );
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertTrue( $counted );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_session_transition_refused', $result->get_error_code() );
		$after = ( new Register_Session_Store() )->get( $source['id'] );
		$this->assertSame( 'counting', $after['status'] );
		$this->assertSame( 77, (int) $after['counting_by'] );
		$this->assertNull( ( new Closure_Store() )->get( $ids['closure_id'] ) );
		$this->assertNull( ( new Register_Session_Store() )->get( $ids['session_id'] ) );
	}

	/** The same closure id against a second target funds nothing twice. */
	public function test_carry_refuses_a_replayed_closure_against_another_target(): void {
		$source = $this->closure_session( null, 'open' );
		$ids = $this->ids();
		$first = ( new Session_Carry() )->carry( $source, $this->target(), $ids, '2026-09-11 12:00:00' );
		$this->assertIsArray( $first );
		$other = $this->target();
		$again = array_merge( $ids, array( 'session_id' => wp_generate_uuid4() ) );
		$closed = ( new Register_Session_Store() )->get( $source['id'] );
		$result = ( new Session_Carry() )->carry( $closed, $other, $again, '2026-09-11 12:00:00' );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_closure_session_invalid', $result->get_error_code() );
		$this->assertNull( ( new Register_Session_Store() )->get( $again['session_id'] ) );
		$this->assertSame( array(), ( new Register_Session_Store() )->list( array( 'register_id' => $other['id'] ) ) );
	}

	/** A target retired between validation and the opening takes no session. */
	public function test_carry_refuses_a_target_retired_under_the_lock(): void {
		$source = $this->closure_session( null, 'open' );
		$target = $this->target();
		$ids = $this->ids();
		$retired = false;
		$retire = function ( $sql ) use ( $target, &$retired ) {
			if ( ! $retired && false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $target['id'] ) ) {
				$retired = true;
				( new \WCPOS\WooCommercePOS\Services\Register_Store() )->retire( $target['id'] );
			}
			return $sql;
		};
		add_filter( 'query', $retire );
		try {
			$result = ( new Session_Carry() )->carry( $source, $target, $ids, '2026-09-11 12:00:00' );
		} finally {
			remove_filter( 'query', $retire );
		}
		$this->assertTrue( $retired );
		$this->assertWPError( $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertSame( 'to_register_id', $result->get_error_data()['param'] );
		$this->assertNull( ( new Register_Session_Store() )->get( $ids['session_id'] ) );
		$this->assertSame( $ids['closure_id'], $result->get_error_data()['closure']['id'] );
	}

	/** Losing the target after the close returns the committed closure, never reopens. */
	public function test_target_open_race_returns_error_with_the_committed_closure(): void {
		$source = $this->closure_session( null, 'open' );
		$target = $this->target();
		$ids = $this->ids();
		$occupied = null;
		$race = function ( $sql ) use ( $target, &$occupied ) {
			if ( null === $occupied && false !== strpos( $sql, "GET_LOCK( 'wcpos_register_" . $target['id'] ) ) {
				$occupied = true;
				$occupied = $this->closure_session( $target['id'], 'open' );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		try {
			$result = ( new Session_Carry() )->carry( $source, $target, $ids, '2026-09-11 12:00:00' );
		} finally {
			remove_filter( 'query', $race );
		}
		$this->assertWPError( $result );
		$this->assertSame( 'wcpos_session_already_open', $result->get_error_code() );
		$this->assertSame( $occupied['id'], $result->get_error_data()['session_id'] );
		$this->assertSame( ( new Closure_Store() )->get( $ids['closure_id'] ), $result->get_error_data()['closure'] );
		$this->assertSame( 'closed', ( new Register_Session_Store() )->get( $source['id'] )['status'] );
		$this->assertNull( ( new Register_Session_Store() )->get( $ids['session_id'] ) );
	}
}
