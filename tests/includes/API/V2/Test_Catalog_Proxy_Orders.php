<?php
/**
 * Tests for V2 catalog proxy order searches.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * V2 order-search probes using posts storage.
 */
class Test_Catalog_Proxy_Orders extends WCPOS_REST_Unit_Test_Case {
	use Catalog_Proxy_Order_Payload_Tests;
	use Catalog_Proxy_Order_Search_Tests;

	/**
	 * Create posts-backed fixtures after REST initialization.
	 *
	 * The order payload pins read through the production sync read lane — see
	 * {@see WCPOS_REST_Unit_Test_Case::install_sync_read_lane()}.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->install_sync_read_lane();
		$this->create_order_search_fixtures();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		$this->uninstall_sync_read_lane();
	}
}
