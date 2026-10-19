<?php
/**
 * Register session storage tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Session persistence and ledger-derived balances. */
class Test_Register_Session_Store extends WCPOS_REST_Unit_Test_Case {
	use Session_Expected_Tests;

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

	/** Register locking can use the public server capability check. */
	public function test_multiple_locks_test_server_returns_true(): void {
		// Arrange: use the configured test database server.
		// Act.
		$supported = Order_Lock::supports_multiple_locks();
		// Assert.
		$this->assertTrue( $supported );
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
