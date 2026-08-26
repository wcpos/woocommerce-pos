<?php
/**
 * Rows-examined budget for saving a `pos-open` order under HPOS, at 4x the fixture.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

/**
 * The HPOS half of the two-size pair — same absolute budget, four times the orders.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid::get_order_ids_by_uuid
 */
class Test_Order_Save_Query_Budget_HPOS_Large extends Test_Order_Save_Query_Budget_HPOS {
	/**
	 * 4x the base fixture, against the same absolute ceiling.
	 */
	protected const ORDER_FIXTURE_SIZE = 512;
}
