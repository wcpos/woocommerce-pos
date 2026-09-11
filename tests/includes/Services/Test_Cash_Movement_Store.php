<?php
/**
 * Cash movement storage tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cash_Movement_Store;
use WP_UnitTestCase;

/** Tests write-once movements and atomic void refusal. */
class Test_Cash_Movement_Store extends WP_UnitTestCase {
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
}
