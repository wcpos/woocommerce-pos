<?php
/**
 * Tests for the change log's query interface.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Change_Log;

/**
 * The change stream reads through page()/head_sequence(), not through its table.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Change_Log
 */
class Test_Change_Log_Query extends Sync_Store_Test_Case {
	/**
	 * The log under test.
	 *
	 * @var Change_Log
	 */
	private $log;

	/**
	 * Start each test against an empty stream.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->log = new Change_Log();
	}

	/**
	 * An empty stream has no head to jump to.
	 */
	public function test_head_sequence_empty_log_returns_zero(): void {
		$this->assertEquals( 0, $this->log->head_sequence() );
	}

	/**
	 * The head is the last recorded row, whatever collection wrote it.
	 */
	public function test_head_sequence_after_records_returns_last_sequence(): void {
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'tax_rate', 22, 'create', 'test', false );

		$page = $this->log->page( array(), 0, 10 );

		$this->assertEquals( 2, \count( $page['rows'] ) );
		$this->assertEquals( $page['rows'][1]['sequence'], $this->log->head_sequence() );
	}

	/**
	 * No object types = the unified stream: every collection, one sequence order.
	 */
	public function test_page_without_object_types_returns_every_collection_in_sequence_order(): void {
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'tax_rate', 22, 'create', 'test', false );
		$this->log->record( 'customer', 33, 'delete', 'test', false );

		$rows = $this->log->page( array(), 0, 10 )['rows'];

		$this->assertEquals(
			array( 'product', 'tax_rate', 'customer' ),
			array_column( $rows, 'object_type' )
		);
		$this->assertEquals( array( 11, 22, 33 ), array_column( $rows, 'object_id' ) );
	}

	/**
	 * Named object types narrow the stream — the products stream spans two of them.
	 */
	public function test_page_with_object_types_returns_only_those_types(): void {
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'variation', 12, 'create', 'test', false );
		$this->log->record( 'tax_rate', 22, 'create', 'test', false );

		$rows = $this->log->page( array( 'product', 'variation' ), 0, 10 )['rows'];

		$this->assertEquals( array( 11, 12 ), array_column( $rows, 'object_id' ) );
	}

	/**
	 * The cursor is exclusive and the limit bounds the page.
	 */
	public function test_page_since_cursor_and_limit_bound_the_window(): void {
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'product', 12, 'update', 'test', false );
		$this->log->record( 'product', 13, 'update', 'test', false );
		$first = $this->log->page( array( 'product' ), 0, 1 );

		$second = $this->log->page( array( 'product' ), $first['rows'][0]['sequence'], 1 );

		$this->assertEquals( array( 11 ), array_column( $first['rows'], 'object_id' ) );
		$this->assertEquals( array( 12 ), array_column( $second['rows'], 'object_id' ) );
	}

	/**
	 * The page's head is the GLOBAL head, not the narrowed types' own max: one
	 * cursor drains every collection, so a narrowed page must still report where
	 * the shared sequence space currently ends.
	 */
	public function test_page_head_reports_the_global_head_not_the_narrowed_max(): void {
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'tax_rate', 22, 'create', 'test', false );

		$page = $this->log->page( array( 'product' ), 0, 10 );

		$this->assertEquals( array( 11 ), array_column( $page['rows'], 'object_id' ) );
		$this->assertEquals( $this->log->head_sequence(), $page['head'] );
	}

	/**
	 * Rows come back typed, so no consumer re-casts the DB's string columns.
	 */
	public function test_page_returns_typed_rows(): void {
		$this->log->record( 'product', 11, 'update', 'test', false );

		$row = $this->log->page( array(), 0, 10 )['rows'][0];

		$this->assertIsInt( $row['sequence'] );
		$this->assertIsInt( $row['object_id'] );
		$this->assertSame( 'product', $row['object_type'] );
		$this->assertSame( 'update', $row['change_type'] );
		$this->assertIsString( $row['modified_gmt'] );
	}

	/**
	 * The deprecated single-type read keeps its legacy shape: no object_type key,
	 * and a max_sequence scoped to that type alone.
	 */
	public function test_changes_since_keeps_its_legacy_row_shape_and_scoped_max(): void {
		$this->log->record( 'product', 11, 'update', 'test', false );
		$this->log->record( 'tax_rate', 22, 'create', 'test', false );

		$result = $this->log->changes_since( 'product', 0, 10 );

		$this->assertEquals(
			array( 'sequence', 'object_id', 'change_type', 'modified_gmt' ),
			array_keys( $result['rows'][0] )
		);
		$this->assertEquals( $result['rows'][0]['sequence'], $result['max_sequence'] );
		$this->assertNotEquals( $this->log->head_sequence(), $result['max_sequence'] );
	}
}
