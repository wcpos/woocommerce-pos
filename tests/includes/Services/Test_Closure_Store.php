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
		$this->assertSame( '10.0000', $row['period_refunds_total'] );
		$this->assertSame( '80.0000', $row['perpetual_sales_total'] );
		$this->assertSame( '10.0000', $row['perpetual_refunds_total'] );
		$this->assertSame(
			array(
				'cash' => '140.0000',
				'card' => '30.0000',
			),
			$row['expected']
		);
		$this->assertSame( array( 'cash' => '-39.0000' ), $row['variance'] );
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
		$this->assertSame( '10.0000', $register['counters']['perpetual_refunds_total'] );
		$second = $store->create( $this->closure_fields( $this->closure_session( $session['register_id'] ), 2 ) );
		$this->assertSame( '0.0000', $second['period_sales_total'] );
		$this->assertSame( '80.0000', $second['perpetual_sales_total'] );
		$this->assertSame( '10.0000', $second['perpetual_refunds_total'] );
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
			( new Register_Session_Store() )->approve( $session, $manager );
			$this->assertSame( $manager, $store->create( $fields )['approved_by'] );
		} finally {
			remove_filter( 'woocommerce_pos_session_variance_threshold', $threshold );
		}
	}

	/** A previously closed status row receives only the closure link. */
	public function test_closed_session_status_fields_are_preserved(): void {
		$session = $this->closure_session( null, 'closed' );
		$fields = $this->closure_fields( $session );
		$fields['closed_at_gmt'] = '2026-09-11 13:00:00';
		$row = ( new Closure_Store() )->create( $fields );
		$this->assertIsArray( $row );
		$session['closure_id'] = $row['id'];
		$this->assertSame( $session, ( new Register_Session_Store() )->get( $session['id'] ) );
		$this->assertSame( array( 'cash' => '1.0000' ), $row['variance'] );
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
}
