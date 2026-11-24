<?php
/**
 * Sync table installation logging tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Health;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/** Tests sync store installation diagnostics. */
class Test_Sync_Table_Install_Log extends \WP_UnitTestCase {
	/**
	 * A failed CREATE logs the table and the database's reason.
	 *
	 * @dataProvider sync_store_provider
	 * @param string $class       Sync store class.
	 * @param string $table_suffix Sync store table suffix.
	 */
	public function test_sync_install_failed_create_logs_the_table_and_db_error( string $class, string $table_suffix ): void {
		global $wpdb;

		// Arrange: a second site whose CREATE TABLE is rewritten into a failing statement.
		$real_prefix = $wpdb->prefix;
		$alt_table   = $real_prefix . 'site3' . $table_suffix;
		// The test bootstrap's own query filter rewrites CREATE TABLE to CREATE TEMPORARY TABLE first.
		$filter = static function ( $query ) use ( $alt_table ) {
			if ( preg_match( '/^\s*CREATE\s+(TEMPORARY\s+)?TABLE/i', $query ) && false !== strpos( $query, $alt_table ) ) {
				return 'CREATE TABLE ' . $alt_table . ' (id NOT A COLUMN)';
			}
			return $query;
		};
		$logs    = array();
		$capture = static function ( $enabled, $message ) use ( &$logs ) {
			if ( 'error' === Logger::$log_level && false !== strpos( $message, 'Sync table could not be created' ) ) {
				$logs[] = $message;
			}
			return false;
		};
		Logger::reset_dedup_state();
		add_filter( 'query', $filter );
		add_filter( 'wcpos_logging', $capture, 10, 2 );
		$suppress = $wpdb->suppress_errors();
		try {
			$wpdb->prefix = $real_prefix . 'site3';

			// Act.
			( new $class() )->install();
		} finally {
			$wpdb->prefix = $real_prefix;
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'wcpos_logging', $capture, 10 );
			remove_filter( 'query', $filter );
			$leaked = Health::table_exists( $alt_table );
			if ( $leaked ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $alt_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test-owned table name.
			}
		}

		// Assert: the table is still missing and one error line names it with the database's reason.
		$this->assertFalse( $leaked );
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( 'Sync table could not be created', $logs[0] );
		$this->assertStringContainsString( $alt_table, $logs[0] );
		$this->assertStringContainsString( 'db_error', $logs[0] );
		$this->assertStringNotContainsString( '"db_error":""', $logs[0] );
	}

	/**
	 * Sync stores whose installs must log a failed CREATE.
	 *
	 * @return array
	 */
	public static function sync_store_provider(): array {
		return array(
			'journal'   => array( Sync_Journal::class, Health::SYNC_JOURNAL_TABLE ),
			'digest'    => array( Integrity_Digest::class, Health::STORED_DIGEST_TABLE ),
			'mutations' => array( Mutation_Store::class, Health::MUTATIONS_TABLE ),
		);
	}

	/** A successful CREATE does not log a missing sync table. */
	public function test_sync_install_successful_create_logs_nothing(): void {
		global $wpdb;

		// Arrange: a fresh site with no CREATE rewrite.
		$real_prefix = $wpdb->prefix;
		$alt_table   = $real_prefix . 'site4' . Health::STORED_DIGEST_TABLE;
		$logs        = array();
		$capture     = static function ( $enabled, $message ) use ( &$logs ) {
			if ( 'error' === Logger::$log_level && false !== strpos( $message, 'Sync table could not be created' ) ) {
				$logs[] = $message;
			}
			return false;
		};
		Logger::reset_dedup_state();
		add_filter( 'wcpos_logging', $capture, 10, 2 );
		try {
			$wpdb->prefix = $real_prefix . 'site4';

			// Act.
			( new Integrity_Digest() )->install();

			// Assert: the table exists without a missing-table error.
			$this->assertTrue( Health::table_exists( $alt_table ) );
			$this->assertCount( 0, $logs );
		} finally {
			$wpdb->prefix = $real_prefix;
			remove_filter( 'wcpos_logging', $capture, 10 );
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $alt_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test-owned table name.
		}
	}
}
