<?php
/**
 * Shared sync store test setup.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Health;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Sync_Index;
use WP_UnitTestCase;

/**
 * Installs and removes the sync store around integration tests.
 */
abstract class Sync_Store_Test_Case extends WP_UnitTestCase {
	/**
	 * Create the sync tables once per class, BEFORE any per-test transaction
	 * starts. DDL inside a test implicitly commits the wp-phpunit transaction,
	 * which leaks every prior write past the rollback — so table creation must
	 * never happen per-test. Install-mechanics tests (Test_Sync_Install) are
	 * the deliberate exception and carry their own post-rollback hygiene.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		( new Change_Log() )->install();
		( new Integrity_Digest() )->install();
		( new Sync_Index() )->install();
		( new Mutation_Store() )->install();
	}

	/**
	 * Empty the sync tables and reset options before a test — DML only, so the
	 * per-test transaction stays alive and the rollback cleans everything.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->empty_sync_tables();
		delete_option( Api::SCHEMA_OPTION );
		delete_option( Sync_Index::EPOCH_OPTION );
		delete_option( Sync_Index::BACKFILL_OPTION );
	}

	/**
	 * Delete all rows from the sync tables (transaction-safe, unlike DROP).
	 */
	protected function empty_sync_tables(): void {
		global $wpdb;

		foreach ( Health::required_tables() as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table names.
		}
	}

	/**
	 * Drop all sync tables.
	 */
	protected function drop_sync_tables(): void {
		global $wpdb;

		foreach ( Health::required_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table names.
		}
	}

	/**
	 * Install each table through its public schema method.
	 */
	protected function install_sync_tables_directly(): void {
		( new Change_Log() )->install();
		( new Integrity_Digest() )->install();
		( new Sync_Index() )->install();
		( new Mutation_Store() )->install();
	}
}
