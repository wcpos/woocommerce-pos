<?php
/**
 * Read Lane parity for order Collection Rules on legacy storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;
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
	 * Enable the sync routes so the proxy lane is registered.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
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
