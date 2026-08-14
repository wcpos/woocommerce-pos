<?php
/**
 * Tests for V2 catalog proxy order searches.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * V2 order-search probes using posts storage.
 */
class Test_Catalog_Proxy_Orders extends WCPOS_REST_Unit_Test_Case {
	use Catalog_Proxy_Order_Search_Tests;

	/**
	 * Enable sync routes and create posts-backed fixtures.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();

		$this->create_order_search_fixtures();
	}

	/**
	 * The sync flag is written before the parent transaction starts, so the
	 * rollback restores true — delete it explicitly for later suites.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}
}
