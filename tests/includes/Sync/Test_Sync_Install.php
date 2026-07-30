<?php
/**
 * Tests for sync store installation.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Health;

/**
 * Sync schema installer tests.
 *
 * @covers \WCPOS\WooCommercePOS\Activator::install_sync_schema
 */
class Test_Sync_Install extends Sync_Store_Test_Case {
	/**
	 * These tests exercise INSTALL mechanics: they need REAL DDL (see setUp),
	 * and real DDL implicitly commits the wp-phpunit transaction — so cleanup
	 * must run AFTER parent::tearDown()'s rollback, or the rollback resurrects
	 * committed rows and later classes inherit them.
	 */
	public function setUp(): void {
		parent::setUp();
		// wp-phpunit rewrites per-test CREATE/DROP TABLE into TEMPORARY variants,
		// which SHOW TABLES (the health probe) cannot see — install mechanics
		// need REAL DDL, so drop the rewrite filters for these tests.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->drop_sync_tables();
	}

	/**
	 * Post-rollback hygiene — see the class comment.
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Post-rollback hygiene: restore a sane committed world for later classes.
		( new \WCPOS\WooCommercePOS\Activator() )->install_sync_schema();
		delete_option( Api::SCHEMA_OPTION );
		delete_option( 'woocommerce_pos_db_version' );
		delete_option( 'woocommerce_pos_db_upgrade_lock.lock' );
		remove_all_actions( 'woocommerce_init' );
	}

	/**
	 * The public activation path creates and latches the complete store.
	 */
	public function test_install_sync_schema_creates_all_tables_and_latches_version(): void {
		( new Activator() )->install_sync_schema();

		$this->assertTrue( Health::is_healthy() );
		$this->assertSame( array(), Health::missing_tables() );
		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION, null ) );
	}

	/**
	 * A missing table is not healthy and a later install repairs and re-latches it.
	 */
	public function test_missing_table_does_not_latch_and_next_install_repairs_schema(): void {
		$activator = new Activator();
		$skip_mutation_table = static function ( array $queries ): array {
			return array_filter(
				$queries,
				static function ( string $query ): bool {
					return false === strpos( $query, Health::MUTATIONS_TABLE );
				}
			);
		};
		add_filter( 'dbdelta_create_queries', $skip_mutation_table );
		$activator->install_sync_schema();
		remove_filter( 'dbdelta_create_queries', $skip_mutation_table );

		$this->assertFalse( Health::is_healthy() );
		$this->assertNull( get_option( Api::SCHEMA_OPTION, null ) );

		$activator->install_sync_schema();

		$this->assertTrue( Health::is_healthy() );
		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION, null ) );
	}

	/**
	 * Upgrading from a pre-3 sync schema appends a compensating customer 'update' for
	 * every live user (past the old stream head), superseding role-departure tombstones
	 * from the customer-role-only era — and a fresh latch at 3 appends nothing more.
	 */
	public function test_customer_scope_upgrade_appends_updates_for_all_live_users(): void {
		global $wpdb;

		$this->install_sync_tables_directly();
		$user_id    = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$change_log = new Change_Log();
		$change_log->record( 'customer', $user_id, 'delete', 'legacy-role-removal' );
		$old_head = (int) $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $change_log->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		$live_ids = array_map( 'intval', get_users( array( 'fields' => 'ids' ) ) );
		sort( $live_ids );
		update_option( Api::SCHEMA_OPTION, '2', false );

		( new Activator() )->install_sync_schema();

		$appended = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_id, change_type FROM ' . $change_log->table_name() . ' WHERE sequence > %d ORDER BY object_id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name with prepared sequence.
				$old_head
			),
			ARRAY_A
		);

		$this->assertSame( $live_ids, array_map( 'intval', array_column( $appended, 'object_id' ) ) );
		$this->assertSame( array_fill( 0, count( $live_ids ), 'update' ), array_column( $appended, 'change_type' ) );
		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION, null ) );

		// Re-latching at the current schema is not an upgrade — no second compensation pass.
		$new_head = (int) $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $change_log->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
		( new Activator() )->install_sync_schema();
		$this->assertSame(
			$new_head,
			(int) $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $change_log->table_name() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
			'a same-version re-latch must not append compensating rows'
		);
	}

	/**
	 * An unlatched sync schema queues db_upgrade even when the plugin version matches.
	 */
	public function test_version_check_queues_upgrade_when_only_sync_schema_is_unlatched(): void {
		update_option( 'woocommerce_pos_db_version', \WCPOS\WooCommercePOS\VERSION );
		delete_option( Api::SCHEMA_OPTION );
		delete_option( 'woocommerce_pos_db_upgrade_lock.lock' );
		remove_all_actions( 'woocommerce_init' );

		$activator     = new Activator();
		$reflection    = new \ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$version_check->invoke( $activator );

		$this->assertNotFalse(
			has_action( 'woocommerce_init' ),
			'db_upgrade should be queued when the sync schema is unlatched'
		);

		do_action( 'woocommerce_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce lifecycle hook under test.

		$this->assertTrue( Health::is_healthy() );
		$this->assertSame( Api::SCHEMA_VERSION, get_option( Api::SCHEMA_OPTION, null ) );
	}
}
