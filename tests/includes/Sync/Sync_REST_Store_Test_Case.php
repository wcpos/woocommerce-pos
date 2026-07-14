<?php
/**
 * Shared sync REST store test setup.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Health;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Sync_Index;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Installs sync tables once while providing the WCPOS REST test server.
 */
abstract class Sync_REST_Store_Test_Case extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Install tables before any per-test transaction begins.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		( new Change_Log() )->install();
		( new Integrity_Digest() )->install();
		( new Sync_Index() )->install();
		( new Mutation_Store() )->install();
	}

	/**
	 * Empty tables with transaction-safe DML.
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;

		foreach ( Health::required_tables() as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table names.
		}
	}
}
