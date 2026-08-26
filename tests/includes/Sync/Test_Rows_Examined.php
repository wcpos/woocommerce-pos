<?php
/**
 * Regression tests for the rows-examined instrument.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Tests\Helpers\Rows_Examined;
use WP_UnitTestCase;

/**
 * An instrument nobody can make fail gates nothing.
 *
 * These tests drive the counter against a purpose-built table with a KNOWN full
 * scan and a KNOWN index seek, so the instrument itself is pinned rather than
 * trusted. The table is created and dropped around the class, never inside a test:
 * DDL implicitly COMMITs and would end the per-test transaction that isolates every
 * other fixture in the run.
 *
 * @covers \WCPOS\WooCommercePOS\Tests\Helpers\Rows_Examined
 */
class Test_Rows_Examined extends WP_UnitTestCase {
	/**
	 * Rows seeded into the probe table.
	 */
	private const PROBE_ROWS = 2000;

	/**
	 * Probe table name (unprefixed).
	 */
	private const PROBE_TABLE = 'wcpos_rows_examined_probe';

	/**
	 * Build the probe table before any per-test transaction exists.
	 *
	 * @param mixed $factory WP unit-test factory (unused).
	 */
	public static function wpSetUpBeforeClass( $factory ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test-framework hook name.
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-only probe table; the name is a class constant.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				indexed_col bigint(20) unsigned NOT NULL DEFAULT 0,
				unindexed_col varchar(64) NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				KEY indexed_col (indexed_col)
			) ENGINE=InnoDB"
		);

		$values = array();
		for ( $i = 1; $i <= self::PROBE_ROWS; $i++ ) {
			$values[] = $wpdb->prepare( '(%d,%s)', $i, 'row-' . $i );
		}
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (indexed_col, unindexed_col) VALUES " . implode( ',', $chunk ) );
		}

		// Without realistic statistics the optimizer guesses, and a plan-sensitive
		// instrument measured against a guess proves nothing.
		$wpdb->query( "ANALYZE TABLE {$table}" );
		// phpcs:enable
	}

	/**
	 * Drop the probe table after the class, outside any test transaction.
	 */
	public static function wpTearDownAfterClass() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test-framework hook name.
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-only probe table teardown.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Prefixed probe table name.
	 */
	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::PROBE_TABLE;
	}

	/**
	 * A predicate on an unindexed column: every row must be looked at.
	 */
	private function full_scan_sql(): string {
		return 'SELECT id FROM ' . self::table() . " WHERE unindexed_col = 'no-such-value'";
	}

	/**
	 * A predicate on the indexed column, matching exactly one row.
	 */
	private function index_seek_sql(): string {
		return 'SELECT id FROM ' . self::table() . ' WHERE indexed_col = 1234';
	}

	/**
	 * Reading the counter must not move it — every measurement is a difference of
	 * two reads, so a self-polluting instrument would report noise as work.
	 */
	public function test_reading_the_counter_does_not_move_it(): void {
		$first  = Rows_Examined::total();
		$second = Rows_Examined::total();
		$third  = Rows_Examined::total();

		$this->assertSame( 0, $second - $first, 'Reading the Handler_read counters must not advance them.' );
		$this->assertSame( 0, $third - $second, 'Reading the Handler_read counters must not advance them.' );
	}

	/**
	 * A known full scan is reported as roughly one row examined per table row.
	 */
	public function test_reports_a_known_full_scan(): void {
		global $wpdb;

		$sql = $this->full_scan_sql();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only probe query.
		$examined = Rows_Examined::measure( fn() => $wpdb->get_col( $sql ), $result );

		$this->assertSame( array(), $result, 'The scan predicate must match nothing, so the engine cannot stop early.' );
		$this->assertGreaterThanOrEqual(
			self::PROBE_ROWS,
			$examined,
			"A scan of a {$this->row_count()}-row table reported only {$examined} rows examined.\n" . Rows_Examined::explain( $sql )
		);
	}

	/**
	 * A known index seek is reported as a handful of rows examined.
	 */
	public function test_reports_a_known_index_seek(): void {
		global $wpdb;

		$sql = $this->index_seek_sql();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only probe query.
		$examined = Rows_Examined::measure( fn() => $wpdb->get_col( $sql ), $result );

		$this->assertSame( array( '1234' ), $result, 'The seek predicate must match exactly one row.' );
		$this->assertLessThanOrEqual(
			10,
			$examined,
			"An indexed single-row lookup reported {$examined} rows examined.\n" . Rows_Examined::explain( $sql )
		);
	}

	/**
	 * The two are separated by orders of magnitude — that separation is the whole
	 * signal a budget assertion reads.
	 */
	public function test_separates_a_full_scan_from_an_index_seek(): void {
		global $wpdb;

		$scan_sql = $this->full_scan_sql();
		$seek_sql = $this->index_seek_sql();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only probe queries.
		$scan = Rows_Examined::measure( fn() => $wpdb->get_col( $scan_sql ) );
		$seek = Rows_Examined::measure( fn() => $wpdb->get_col( $seek_sql ) );
		// phpcs:enable

		$this->assertGreaterThan(
			$seek * 100,
			$scan,
			"Scan examined {$scan} rows, seek examined {$seek} — not enough separation to gate on.\n"
			. Rows_Examined::explain( $scan_sql ) . "\n" . Rows_Examined::explain( $seek_sql )
		);
	}

	/**
	 * The premise of the whole instrument: query COUNT cannot tell these apart.
	 *
	 * This is the property that made #1725 invisible. If this ever stops holding,
	 * the cheaper instrument would do and this one is redundant — so pin it.
	 */
	public function test_query_count_is_blind_to_the_difference(): void {
		global $wpdb;

		$scan_sql = $this->full_scan_sql();
		$seek_sql = $this->index_seek_sql();

		$before = get_num_queries();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only probe query.
		$wpdb->get_col( $scan_sql );
		$scan_queries = get_num_queries() - $before;

		$before = get_num_queries();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only probe query.
		$wpdb->get_col( $seek_sql );
		$seek_queries = get_num_queries() - $before;

		$this->assertSame( 1, $scan_queries );
		$this->assertSame( $scan_queries, $seek_queries, 'Query count reports a full scan and an index seek identically — which is why rows examined exists.' );
	}

	/**
	 * `measure()` hands back the operation's return value alongside the count.
	 */
	public function test_measure_returns_the_operation_result(): void {
		$examined = Rows_Examined::measure(
			function () {
				return 'the-result';
			},
			$result
		);

		$this->assertSame( 'the-result', $result );
		$this->assertIsInt( $examined );
	}

	/**
	 * `explain()` produces a diagnostic string naming the chosen key.
	 */
	public function test_explain_names_the_chosen_key(): void {
		$explained = Rows_Examined::explain( $this->index_seek_sql() );

		$this->assertStringContainsString( 'EXPLAIN:', $explained );
		$this->assertStringContainsString( 'key=indexed_col', $explained );
	}

	/**
	 * Row count, for failure messages.
	 */
	private function row_count(): int {
		return self::PROBE_ROWS;
	}
}
