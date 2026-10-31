<?php
/**
 * Site-local installation smoke test for the sync and till tables.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WP_UnitTestCase;

/**
 * @group multisite
 * @expectedDeprecated wpmu_new_blog
 */
class Test_Till_Tables_Multisite extends WP_UnitTestCase {
	/** @var int Site created by the smoke test. */
	private $site_id = 0;

	/** @var string[] Site-local custom tables to remove after rollback. */
	private $tables = array();

	/** Real DDL commits fixtures; clean them after WordPress rolls back the test. */
	public function tearDown(): void {
		parent::tearDown();
		global $wpdb;
		foreach ( $this->tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixture site table names.
		}
		if ( $this->site_id ) {
			wpmu_delete_blog( $this->site_id, true );
		}
	}

	/**
	 * Activation's schema installer must use the switched site's prefix throughout.
	 */
	public function test_second_site_installs_till_tables_and_rebuilds_digests(): void {
		global $wpdb;
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a network: run with .phpunit.multisite.xml.dist.' );
		}

		// SHOW TABLES and the installer's health checks cannot see WP test temporary tables.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->site_id = self::factory()->blog->create();
		$prefix        = $wpdb->get_blog_prefix( $this->site_id );
		// All tables installed by Activator::install_sync_schema(), including install_till_tables().
		$this->tables = array(
			$prefix . 'wcpos_sync_journal',
			$prefix . 'wcpos_sync_stored_digest',
			$prefix . 'wcpos_sync_mutations',
			$prefix . 'wcpos_register_sessions',
			$prefix . 'wcpos_fiscal_records',
		);
		switch_to_blog( $this->site_id );
		try {
			( new Activator() )->install_sync_schema();
			foreach ( $this->tables as $table ) {
				$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
			}

			$digest = new Integrity_Digest();
			$this->assertSame( $prefix . 'wcpos_sync_stored_digest', $digest->table_name() );
			$result = $digest->rebuild();
			$this->assertArrayHasKey( 'stored_total', $result );
		} finally {
			restore_current_blog();
		}
	}
}
