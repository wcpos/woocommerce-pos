<?php
/**
 * Read Lane parity for the customer collection.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * The v1 <-> v2 customer parity matrix.
 *
 * One class, no storage variant: customers live in `wp_users`/`wp_usermeta` and nowhere
 * else, so unlike orders there is no HPOS sibling of this file.
 *
 * @covers \WCPOS\WooCommercePOS\API\V1\Customers_Controller
 * @covers \WCPOS\WooCommercePOS\API\V2\Proxy\Customers_Proxy_Behavior
 */
class Test_Customers_Lane_Parity extends WCPOS_REST_Unit_Test_Case {
	use Customers_Lane_Parity_Tests;

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
