<?php
/**
 * Tests for the unified sync journal query interfaces.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/**
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal
 */
class Test_Sync_Journal_Query extends Sync_Store_Test_Case {
	/** @var Sync_Journal */
	private $journal;

	public function setUp(): void {
		parent::setUp();
		$this->journal = new Sync_Journal();
		$this->journal->install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
	}

	public function test_head_sequence_empty_journal_returns_zero(): void {
		$this->assertSame( 0, $this->journal->head_sequence() );
	}

	public function test_epoch_survives_install_on_a_surviving_table_and_regenerates_on_recreate(): void {
		global $wpdb;
		$first = $this->journal->ensure_epoch();
		$this->assertSame( $first, $this->journal->ensure_epoch() );

		// dbDelta no-op on a surviving table: every row survives, so activation
		// re-runs must NOT change the generation (needless all-client resync).
		$this->journal->install();
		$this->assertSame( $first, $this->journal->ensure_epoch() );

		// A recreated table IS a new sequence generation. The WP test suite
		// rewrites DROP/CREATE TABLE to TEMPORARY variants, which never touch
		// the real table install() probes with SHOW TABLES — lift the filters
		// so the recreate is real, then restore everything.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( 'DROP TABLE ' . $this->journal->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Known internal table name.
			$this->journal->install();
			$this->assertNotSame( $first, $this->journal->ensure_epoch() );
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_page_returns_global_stream_and_head(): void {
		$this->journal->record( 'product', 11, false, '2026-08-13 10:00:00', 'test', false );
		$this->journal->record( 'tax_rate', 22, false, '', 'test', false );
		$this->journal->record( 'customer', 33, true, '2026-08-13 11:00:00', 'test', false );

		$page = $this->journal->page( array(), 0, 10 );

		$this->assertSame( array( 'product', 'tax_rate', 'customer' ), array_column( $page['rows'], 'object_type' ) );
		$this->assertSame( array( 11, 22, 33 ), array_column( $page['rows'], 'object_id' ) );
		$this->assertSame( $page['rows'][2]['sequence'], $page['head'] );
	}

	public function test_page_types_narrow_stream_and_head_is_stream_scoped(): void {
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'variation', 12, false, '', 'test', false );
		$this->journal->record( 'order', 22, false, 'sha256:order', 'test', false );

		$page = $this->journal->page( array( 'product', 'variation' ), 0, 10 );

		// The head is the STREAM's head: the foreign order row above it must not
		// move it, or a drained catalogue cursor could never reach head (304 idle).
		$this->assertSame( array( 11, 12 ), array_column( $page['rows'], 'object_id' ) );
		$this->assertSame( $page['rows'][1]['sequence'], $page['head'] );
		$this->assertGreaterThan( $page['head'], $this->journal->head_sequence() );
		$this->assertSame( $this->journal->head_sequence( array( 'order' ) ), $this->journal->head_sequence() );
	}

	public function test_page_since_and_limit_bound_the_window(): void {
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'product', 12, false, '', 'test', false );
		$this->journal->record( 'product', 13, false, '', 'test', false );
		$first = $this->journal->page( array( 'product' ), 0, 1 );

		$second = $this->journal->page( array( 'product' ), $first['rows'][0]['sequence'], 1 );

		$this->assertSame( array( 11 ), array_column( $first['rows'], 'object_id' ) );
		$this->assertSame( array( 12 ), array_column( $second['rows'], 'object_id' ) );
	}

	public function test_page_coerces_deleted_and_revision_with_the_other_row_fields(): void {
		$this->journal->record( 'product', 11, true, '2026-08-13 10:00:00', 'test', false );

		$row = $this->journal->page( array(), 0, 10 )['rows'][0];

		$this->assertSame(
			array( 'sequence', 'object_id', 'object_type', 'deleted', 'revision', 'modified_gmt' ),
			array_keys( $row )
		);
		$this->assertIsInt( $row['sequence'] );
		$this->assertIsInt( $row['object_id'] );
		$this->assertSame( 1, $row['deleted'] );
		$this->assertSame( '2026-08-13 10:00:00', $row['revision'] );
		$this->assertIsString( $row['modified_gmt'] );
	}

	public function test_customer_schema_upgrade_append_uses_schema_upgrade_origin_and_empty_revision(): void {
		global $wpdb;
		$user_id = $this->factory->user->create();

		$this->assertTrue( $this->journal->append_customer_updates_for_all_users() );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT object_type, object_id, deleted, revision, origin FROM ' . $this->journal->table_name() . ' WHERE object_id = %d',
				$user_id
			),
			ARRAY_A
		);

		$this->assertSame( array( 'customer', (string) $user_id, '0', '', 'schema-upgrade' ), array_values( $row ) );
	}

	public function test_rows_after_sequence_returns_only_the_requested_order_lane(): void {
		$this->journal->record( 'product', 11, false, '', 'test', false );
		$this->journal->record( 'order', 22, false, 'sha256:one', 'hook:update', false );
		$cursor = $this->journal->head_sequence();
		$this->journal->record( 'order', 33, true, 'deleted', 'hook:delete', false );

		$rows = $this->journal->rows_after_sequence( $cursor - 1, 10 );

		$this->assertSame( array( 22, 33 ), array_column( $rows, 'order_id' ) );
		$this->assertSame( array( 0, 1 ), array_column( $rows, 'deleted' ) );
		$this->assertSame( array( 'sha256:one', 'deleted' ), array_column( $rows, 'revision' ) );
	}

	public function test_rows_after_sequence_clamps_limit_to_one_and_251(): void {
		for ( $id = 1; $id <= 260; $id++ ) {
			$this->journal->record( 'order', $id, false, '', 'test', false );
		}

		$this->assertCount( 1, $this->journal->rows_after_sequence( 0, 0 ) );
		$this->assertCount( 251, $this->journal->rows_after_sequence( 0, 500 ) );
	}

	public function test_rows_after_sequence_returns_empty_when_table_is_missing(): void {
		global $wpdb;
		$table = $this->journal->table_name();
		$previous_suppress_errors = $wpdb->suppress_errors();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			$drop_result = $wpdb->query( 'DROP TABLE ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Deliberate missing-table test.
			$this->assertNotFalse( $drop_result, 'The fixture must remove the journal table.' );
			$wpdb->suppress_errors();

			try {
				$this->assertSame( array(), $this->journal->rows_after_sequence( 0, 10 ) );
			} finally {
				$wpdb->suppress_errors( $previous_suppress_errors );
			}
			$this->assertSame( $previous_suppress_errors, $wpdb->suppress_errors( $previous_suppress_errors ), 'Cleanup must restore wpdb error reporting before reinstalling the table.' );
		} finally {
			$wpdb->suppress_errors( $previous_suppress_errors );
			try {
				$this->journal->install();
			} finally {
				add_filter( 'query', array( $this, '_create_temporary_tables' ) );
				add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			}
		}
	}

	/**
	 * The catalogue stream's types are a registry projection (journal group
	 * minus orders) — a newly journalled collection cannot be invisible to it.
	 */
	public function test_catalogue_object_types_project_from_the_registry_without_orders(): void {
		$types = Sync_Journal::catalogue_object_types();

		$this->assertNotContains( 'order', $types );
		$this->assertSame( array( 'product', 'variation', 'customer', 'category', 'brand', 'tag', 'coupon', 'tax_rate' ), $types );
	}
}
