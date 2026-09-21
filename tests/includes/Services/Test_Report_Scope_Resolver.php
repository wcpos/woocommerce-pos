<?php
/**
 * Resolved report scope tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Report_Scope_Resolver;
use WCPOS\WooCommercePOS\Services\Report_Scope_Gate;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Calendar bounds and persisted identity, not request guesses. */
class Test_Report_Scope_Resolver extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** UTC-day arithmetic would produce 24 hours and the wrong boundaries here. */
	public function test_scope_dst_days_have_half_open_local_midnight_bounds(): void {
		update_option( 'timezone_string', 'America/New_York' );
		foreach ( array(
			array( '2026-03-08', '2026-03-08 05:00:00', '2026-03-09 04:00:00' ),
			array( '2026-11-01', '2026-11-01 04:00:00', '2026-11-02 05:00:00' ),
		) as list( $day, $from, $to ) ) {
			$context = Report_Scope_Resolver::context(
				array(
					'mode' => 'range',
					'from' => $day,
					'to' => $day,
				)
			);
			$scope = Report_Scope_Resolver::resolve( $context );
			$this->assertSame( $from, $scope['from_utc'] );
			$this->assertSame( $to, $scope['to_utc'] );
			$this->assertSame( 'America/New_York', $scope['timezone'] );
			$this->assertNull( $scope['register_id'] );
			$this->assertIsInt( $scope['store_id'] );
		}
	}

	/** The session stamp and its linked closure number win over today's date or a guess. */
	public function test_scope_closed_session_uses_linked_closure_number_and_stamp(): void {
		global $wpdb;
		$session = $this->closure_session();
		$closure = ( new Closure_Store() )->create( $this->closure_fields( $session, 42 ) );
		$this->assertIsArray( $closure );
		$wpdb->update( ( new Register_Session_Store() )->table_name(), array( 'business_day' => '2026-09-10' ), array( 'id' => $session['id'] ) );
		$scope = Report_Scope_Resolver::resolve(
			Report_Scope_Resolver::context(
				array(
					'mode' => 'session',
					'session_id' => $session['id'],
				)
			)
		);
		$this->assertSame( 42, $scope['session_number'] );
		$this->assertSame( '2026-09-10', $scope['business_day'] );
		$this->assertSame( '2026-09-11 08:00:00', $scope['opened_at'] );
		$this->assertSame( '2026-09-11 12:00:00', $scope['closed_at'] );
		$this->assertSame( $session['register_id'], $scope['register_id'] );
		$this->assertSame( 456, $scope['store_id'] );
	}

	/** Open sessions have no fabricated number; mismatched identities cannot widen scope. */
	public function test_scope_open_or_mismatched_session_is_rejected(): void {
		$session = $this->closure_session();
		$args = array(
			'mode' => 'session',
			'session_id' => $session['id'],
		);
		$error = Report_Scope_Resolver::context( $args );
		$this->assertSame( 'wcpos_report_session_not_closed', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		( new Closure_Store() )->create( $this->closure_fields( $session ) );
		foreach ( array( array( 'register_id' => wp_generate_uuid4() ), array( 'store_id' => 999 ) ) as $mismatch ) {
			$error = Report_Scope_Resolver::context( $args + $mismatch );
			$this->assertWPError( $error );
			$this->assertSame( 400, $error->get_error_data()['status'] );
		}
	}

	/** The exact reach boundary is allowed in Pro, but neither edition can go one day older. */
	public function test_gate_reach_and_free_register_day_rules_are_enforced(): void {
		$today = new \DateTimeImmutable( 'today', new \DateTimeZone( 'Pacific/Kiritimati' ) );
		$scope = array(
			'mode' => 'range',
			'timezone' => 'Pacific/Kiritimati',
			'register_id' => wp_generate_uuid4(),
			'from' => $today->format( 'Y-m-d' ),
			'to' => $today->format( 'Y-m-d' ),
		);
		$this->assertTrue( Report_Scope_Gate::check( $scope, false ) );
		$this->assertTrue( Report_Scope_Gate::check( array_replace( $scope, array( 'register_id' => null ) ), true ) );
		$this->assertWPError( Report_Scope_Gate::check( array_replace( $scope, array( 'register_id' => null ) ), false ) );
		$scope['from'] = $today->modify( '-92 days' )->format( 'Y-m-d' );
		$this->assertTrue( Report_Scope_Gate::check( $scope, true ) );
		$this->assertWPError( Report_Scope_Gate::check( $scope, false ) );
		$scope['from'] = $today->modify( '-93 days' )->format( 'Y-m-d' );
		foreach ( array( true, false ) as $pro ) {
			$error = Report_Scope_Gate::check( $scope, $pro );
			$this->assertSame( 'wcpos_report_scope_locked', $error->get_error_code() );
		}
		$scope['from'] = $today->modify( '+1 day' )->format( 'Y-m-d' );
		$this->assertSame( 400, Report_Scope_Gate::check( $scope, true )->get_error_data()['status'] );
	}
}
