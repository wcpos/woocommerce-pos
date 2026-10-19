<?php
/**
 * Register session storage tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Session persistence and ledger-derived balances. */
class Test_Register_Session_Store extends WCPOS_REST_Unit_Test_Case {
	use Session_Expected_Tests;
	use Closure_Test_Fixture;

	/** The session columns a read returned before closures folded into the session row. */
	public const SESSION_KEYS = array( 'id', 'register_id', 'store_id', 'status', 'opened_at_gmt', 'business_day', 'opened_by', 'expected_float', 'counted_float', 'opening_variance', 'counting_started_at_gmt', 'closed_at_gmt', 'closed_by', 'approved_by', 'approved_counted', 'approved_expected', 'counted', 'closure_id', 'created_at_gmt' );

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
				)
			);
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
			)
		);
		$this->assertNull( $row['counting_started_at_gmt'] );
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
}
