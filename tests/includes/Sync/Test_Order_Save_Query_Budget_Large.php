<?php
/**
 * Rows-examined budget for saving a `pos-open` order, at 4x the fixture size.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

/**
 * The same absolute budget, against four times as many orders.
 *
 * This class is the half of the pair that makes the budget mean "does not scale".
 * A single measurement can always be explained away by a generous ceiling; two
 * measurements at 128 and 512 orders sharing ONE absolute ceiling cannot. An
 * implementation that walks the meta table grows roughly 4x between them and
 * overshoots at both sizes; a correct one moves by a few hundred rows.
 *
 * Measured on this fixture: 15,994 rows examined before the #1725 fix, 597 after —
 * against the 1,500 the 128-order sibling also has to meet.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid::get_order_ids_by_uuid
 */
class Test_Order_Save_Query_Budget_Large extends Test_Order_Save_Query_Budget {
	/**
	 * 4x the base fixture. Deliberately NOT paired with a larger budget — the
	 * ceiling is absolute precisely so that growing the fixture cannot ratify a
	 * regression.
	 */
	protected const ORDER_FIXTURE_SIZE = 512;
}
