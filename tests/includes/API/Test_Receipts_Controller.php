<?php
/**
 * Tests for receipts REST controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\API\Receipts_Controller;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;

/**
 * Test_Receipts_Controller class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipts_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Endpoint instance.
	 *
	 * @var Receipts_Controller
	 */
	protected $endpoint;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Receipts_Controller();
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wcpos/v1/receipts/(?P<order_id>[\d]+)', $routes );
	}

	/**
	 * Test fiscal mode returns snapshot payload.
	 */
	public function test_get_item_fiscal_mode_returns_snapshot(): void {
		$order = OrderHelper::create_order();
		Receipt_Snapshot_Store::instance()->handle_payment_complete( $order->get_id() );

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'fiscal' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertEquals( 'fiscal', $data['mode'] );
		$this->assertTrue( $data['has_snapshot'] );
		$this->assertEquals( 'fiscal', $data['data']['meta']['mode'] );
	}

	/**
	 * Test live mode returns recalculated payload.
	 */
	public function test_get_item_live_mode_returns_payload(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'live' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'live', $data['mode'] );
		$this->assertArrayHasKey( 'totals', $data['data'] );
	}

	/**
	 * Test mode validation rejects invalid values.
	 */
	public function test_get_item_rejects_invalid_mode(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'broken' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test endpoint requires auth.
	 */
	public function test_get_item_requires_authentication(): void {
		$order = OrderHelper::create_order();
		wp_set_current_user( 0 );

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );
	}
}
