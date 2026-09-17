<?php
/**
 * Regression coverage for registry-driven digest dispatch.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WP_UnitTestCase;

/**
 * Pins fail-closed dispatch beneath the v2 integrity lane.
 *
 * @see Integrity_Controller
 */
class Test_Digest_Registry extends WP_UnitTestCase {

	/**
	 * Unsupported collections must never execute product reads.
	 *
	 * @dataProvider unsupported_collections
	 * @param string $collection Unknown name or collection without digest ownership.
	 */
	public function test_bucket_reads_unsupported_collection_return_empty_without_queries( string $collection ): void {
		global $wpdb;

		// Arrange.
		$index   = new Digest_Index();
		$range   = array( 'bucket_size' => 100, 'start' => 0, 'end' => 100 );
		$queries = $wpdb->num_queries;

		// Act.
		$aggregates = $index->bucket_aggregates( $range, $collection );
		$listing    = $index->bucket_listing( $collection, $range );

		// Assert.
		$this->assertSame( array( 'buckets' => array(), 'max_id' => 0 ), $aggregates );
		$this->assertSame( array(), $listing );
		$this->assertSame( $queries, $wpdb->num_queries );
	}

	/** Unsupported id-space owners. */
	public static function unsupported_collections(): array {
		return array(
			'unknown' => array( 'nonsense' ),
			'no digest' => array( 'categories' ),
			'child collection' => array( 'variations' ),
		);
	}

	/** The SQL fragment must also fail closed if called independently. */
	public function test_live_max_unknown_collection_returns_no_rows(): void {
		global $wpdb;

		// Arrange.
		$method = new \ReflectionMethod( Digest_Index::class, 'live_max_id_sql' );
		$method->setAccessible( true );

		// Act.
		$query = $method->invoke( new Digest_Index(), 'nonsense', false );
		$rows  = $wpdb->get_col( $query['sql'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal SQL builder under test.

		// Assert.
		$this->assertSame( array(), $query['args'] );
		$this->assertSame( array(), $rows );
	}

	/** An unrecognized queue discriminator must be visible, not silently lost. */
	public function test_pending_upsert_unknown_type_logs_one_warning(): void {
		// Arrange.
		$messages = array();
		$capture  = static function ( $enabled, $message ) use ( &$messages ) {
			$messages[] = array( Logger::$log_level, $message );
			return false;
		};
		$method = new \ReflectionMethod( Integrity_Digest::class, 'upsert_pending' );
		$method->setAccessible( true );
		Logger::reset_dedup_state();
		add_filter( 'woocommerce_pos_logging', $capture, 10, 2 );

		// Act.
		try {
			$method->invoke( new Integrity_Digest(), 'unknown-queued-type', 42 );
		} finally {
			remove_filter( 'woocommerce_pos_logging', $capture, 10 );
			Logger::reset_dedup_state();
		}

		// Assert.
		$this->assertCount( 1, $messages );
		$this->assertSame( 'warning', $messages[0][0] );
		$this->assertStringContainsString( 'unknown-queued-type', $messages[0][1] );
	}
}
