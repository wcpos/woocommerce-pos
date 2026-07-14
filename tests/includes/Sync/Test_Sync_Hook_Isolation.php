<?php
/**
 * Tests that the sync REST surface does not bleed into wc/v3 requests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Sync hook isolation tests.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Api
 * @covers \WCPOS\WooCommercePOS\Sync\Status_Controller
 */
class Test_Sync_Hook_Isolation extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable the sync feature flag before routes are registered.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );

		parent::setUp();
	}

	/**
	 * Remove the sync feature flag after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		// setUp committed the flag before the transaction started; delete it
		// after the rollback or the rollback restores the committed row.
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * The enabled sync surface does not alter wc/v3 products or routes.
	 */
	public function test_enabled_sync_surface_does_not_modify_wc_v3_products_or_routes(): void {
		$product = ProductHelper::create_simple_product();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product->get_id() );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$routes   = $this->server->get_routes();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'healthy', $data );
		$this->assertArrayNotHasKey( 'missing_tables', $data );
		$this->assertArrayNotHasKey( 'schema_version', $data );
		$this->assertArrayHasKey( '/wcpos/v1/sync/status', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/sync', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/sync/status', $routes );
	}
}
