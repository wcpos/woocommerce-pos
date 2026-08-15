<?php
/**
 * Read Lane parity for order Collection Rules on legacy storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * The v1 <-> v2 parity matrix, posts storage.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules
 * @covers \WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
 */
class Test_Collection_Rules_Parity extends WCPOS_REST_Unit_Test_Case {
	use Collection_Rules_Parity_Tests;

	/**
	 * Initialize REST routes before parity checks.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
