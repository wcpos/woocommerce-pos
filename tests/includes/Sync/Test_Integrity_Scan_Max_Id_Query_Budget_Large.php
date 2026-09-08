<?php
/**
 * Rows-examined budget for the scan's completion query, at 4x the fixture size.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

/**
 * The same budgets, against four times as many rows per collection.
 *
 * The half of the pair that makes the bounds mean "one index pass, no meta join,
 * no temp table": the absolute customer ceiling and the per-live-row ratio are
 * both met at 256 AND at 1,024 rows, which a derived-digest shape — several reads
 * per live row — cannot do at either size.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Digest_Index::bucket_aggregates
 */
class Test_Integrity_Scan_Max_Id_Query_Budget_Large extends Test_Integrity_Scan_Max_Id_Query_Budget {
	/**
	 * 4x the base fixture, deliberately NOT paired with looser bounds.
	 */
	protected const FIXTURE_SIZE = 1024;
}
