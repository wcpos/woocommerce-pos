<?php
/**
 * Rows-examined instrument for query-plan regression budgets.
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

/**
 * Counts the rows the storage engine actually touched during an operation.
 *
 * WHY THIS EXISTS. The obvious perf instrument — `get_num_queries()` — is blind to
 * the entire class of bug this defends against. #1725 turned a 51 ms index lookup
 * into a ~1 s full scan of `wp_postmeta` by adding three words to an `ORDER BY`;
 * the query COUNT was identical either side (one query before, one query after) and
 * every correctness test stayed green. What changed was how many rows the engine
 * had to walk to answer it: 887,406 versus 28,150 for the same single query.
 *
 * MySQL/MariaDB expose that directly as the `Handler_read%` session counters, which
 * tick once per row handed to the SQL layer by a storage-engine handler. Summing
 * them and taking the delta across an operation gives a deterministic, free,
 * plan-sensitive measure of work done.
 *
 * WHY NOT ASSERT ON `EXPLAIN`. The chosen index is not portable. `@wordpress/env`
 * provisions the database from the floating `mariadb:lts` tag, so the optimizer's
 * plan can change under us on a Docker image bump with no code change at all — a
 * plan-pinning gate would go red on an image update rather than on a regression,
 * and a gate that cries wolf gets muted. Rows examined is behavioural: it measures
 * the outcome we care about (work), not the mechanism the optimizer picked to get
 * there. `EXPLAIN` still earns its place in the FAILURE MESSAGE, where it is what
 * makes a red build diagnosable — hence {@see self::explain()}.
 *
 * SCALE MATTERS. The bad plan for #1725 only appears once the table is big enough
 * for the optimizer to prefer an id-ordered walk — measured at ~64 orders. Below
 * that threshold both variants pick the same plan and a budget passes for the wrong
 * reason, gating nothing. Callers must seed past the threshold (>= 128 orders) and
 * `ANALYZE TABLE` so the statistics the optimizer reads are real. Seeding at that
 * size means DDL-free DML plus an `ANALYZE`, and `ANALYZE TABLE` implicitly COMMITs
 * — so it must run from `wpSetUpBeforeClass()`, never inside a test, or it ends the
 * per-test transaction and the fixture leaks past the rollback. See
 * {@see \Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait} for the
 * same scar written up at length.
 *
 * BUDGETS ARE PER-DATASTORE. The same operation measured 887,406 rows under CPT and
 * ~1 under HPOS, because `wc_orders_meta` carries a composite `(meta_key, meta_value)`
 * index and `wp_postmeta` does not. A single shared bound is either so loose that CPT
 * regressions pass or so tight that the HPOS assertion means nothing.
 */
final class Rows_Examined {
	/**
	 * Not instantiable.
	 */
	private function __construct() {}

	/**
	 * Total rows handed to the SQL layer by storage-engine handlers on this session.
	 *
	 * Reading it does not move it: `SHOW SESSION STATUS` is answered from the status
	 * variables themselves, so two back-to-back reads differ by zero. That property is
	 * load-bearing — every measurement here is a difference of two reads — and is
	 * pinned by Test_Rows_Examined.
	 */
	public static function total(): int {
		global $wpdb;

		$total = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading server status counters; there is no WP API for this and caching would defeat the purpose.
		foreach ( (array) $wpdb->get_results( "SHOW SESSION STATUS LIKE 'Handler_read%'" ) as $row ) {
			$total += (int) $row->Value; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column name.
		}

		return $total;
	}

	/**
	 * Rows examined while running $operation.
	 *
	 * @param callable   $operation Operation to measure.
	 * @param null|mixed $result    Receives whatever $operation returned.
	 *
	 * @return int Rows examined.
	 */
	public static function measure( callable $operation, &$result = null ): int {
		$before = self::total();
		$result = $operation();

		return self::total() - $before;
	}

	/**
	 * A human-readable `EXPLAIN` for a query, for use in assertion failure messages.
	 *
	 * Diagnostic only — never assert on this. Both the plan and the SQL text are
	 * moving targets (see the class docblock), and pinning the SQL literal would make
	 * a test fail on the FIX rather than on the bug.
	 *
	 * @param string $sql Query to explain.
	 *
	 * @return string
	 */
	public static function explain( string $sql ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic EXPLAIN of a caller-built query, test-only.
		$rows = (array) $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return 'EXPLAIN returned nothing (' . $wpdb->last_error . ')';
		}

		$lines = array();
		foreach ( $rows as $row ) {
			$parts = array();
			foreach ( array( 'table', 'type', 'possible_keys', 'key', 'rows', 'Extra' ) as $column ) {
				$parts[] = $column . '=' . ( ( null === ( $row[ $column ] ?? null ) || '' === $row[ $column ] ) ? 'NULL' : $row[ $column ] );
			}
			$lines[] = '  ' . implode( ' ', $parts );
		}

		return "EXPLAIN:\n" . implode( "\n", $lines );
	}
}
