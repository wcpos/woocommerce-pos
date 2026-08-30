<?php
/**
 * Rows-examined budget for the product-save identity check, at 4x the fixture size.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

/**
 * The same absolute budget, against four times as many products.
 *
 * The half of the pair that makes the budget mean "does not scale": one ceiling
 * met at 256 AND at 1,024 products cannot be explained away by a generous bound.
 * The ownership scan grows 4x between the two sizes; the skip does not move.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid::stamp_on_save
 */
class Test_Product_Save_Query_Budget_Large extends Test_Product_Save_Query_Budget {
	/**
	 * 4x the base fixture, deliberately NOT paired with a larger budget.
	 */
	protected const PRODUCT_FIXTURE_SIZE = 1024;
}
