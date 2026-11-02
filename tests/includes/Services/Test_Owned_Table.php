<?php
/**
 * Owned table installation tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Sync\Health;

/** Tests request-local installation checks across sites. */
class Test_Owned_Table extends \WP_UnitTestCase {
	/** The first site's memo must not skip the second site's table check. */
	public function test_ensure_installed_checks_each_site_table_once(): void {
		global $wpdb;

		// Arrange: memoise the real table, then intercept the second site's probe.
		$store = new Register_Session_Store();
		$store->ensure_installed();
		$real_prefix = $wpdb->prefix;
		$alt_table   = $real_prefix . 'site2' . Register_Session_Store::TABLE;
		$seen        = array();
		$filter      = static function ( $query ) use ( &$seen, $alt_table ) {
			if ( false !== strpos( $query, 'site2' ) ) {
				$seen[] = $query;
				return "SELECT '" . $alt_table . "'";
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$wpdb->prefix = $real_prefix . 'site2';

			// Act.
			$store->ensure_installed();
			$store->ensure_installed();
		} finally {
			$wpdb->prefix = $real_prefix;
			remove_filter( 'query', $filter );
		}

		// Assert: checked once, neither skipped nor repeated.
		$this->assertCount( 1, $seen );
	}

	/** A CREATE that fails is logged with the table and the database's reason, not swallowed. */
	public function test_create_table_if_missing_failed_create_logs_an_error(): void {
		global $wpdb;

		// Arrange: a second site whose CREATE TABLE is rewritten into a failing statement.
		$store       = new Register_Session_Store();
		$real_prefix = $wpdb->prefix;
		$alt_table   = $real_prefix . 'site3' . Register_Session_Store::TABLE;
		// The test bootstrap's own query filter rewrites CREATE TABLE to CREATE TEMPORARY TABLE first.
		$filter      = static function ( $query ) use ( $alt_table ) {
			if ( preg_match( '/^\s*CREATE\s+(TEMPORARY\s+)?TABLE/i', $query ) && false !== strpos( $query, $alt_table ) ) {
				return 'CREATE TABLE ' . $alt_table . ' (id NOT A COLUMN)';
			}
			return $query;
		};
		$logs    = array();
		$capture = static function ( $enabled, $message ) use ( &$logs ) {
			if ( 'error' === \WCPOS\WooCommercePOS\Logger::$log_level ) {
				$logs[] = $message;
			}
			return false;
		};
		\WCPOS\WooCommercePOS\Logger::reset_dedup_state();
		add_filter( 'query', $filter );
		add_filter( 'wcpos_logging', $capture, 10, 2 );
		$suppress = $wpdb->suppress_errors();
		try {
			$wpdb->prefix = $real_prefix . 'site3';

			// Act.
			$store->ensure_installed();
		} finally {
			$wpdb->prefix = $real_prefix;
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'wcpos_logging', $capture, 10 );
			remove_filter( 'query', $filter );
		}
		$leaked = Health::table_exists( $alt_table );
		if ( $leaked ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $alt_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test-owned table name.
		}

		// Assert: the table is still missing and one error line names it with the database's reason.
		$this->assertFalse( $leaked );
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( 'Table could not be created', $logs[0] );
		$this->assertStringContainsString( $alt_table, $logs[0] );
		$this->assertStringContainsString( 'db_error', $logs[0] );
		$this->assertStringNotContainsString( '"db_error":""', $logs[0] );
	}
}
