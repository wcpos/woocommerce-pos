<?php
/**
 * Tests for V2 catalog proxy order searches.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Shared V1 order-search parity probes.
 */
trait Catalog_Proxy_Order_Search_Tests {
	/**
	 * Order targeted by the search probes.
	 *
	 * @var \WC_Order
	 */
	private $target_order;

	/**
	 * Create orders with distinct billing search fields.
	 */
	private function create_order_search_fixtures(): void {
		$this->target_order = OrderHelper::create_order();
		$this->target_order->set_billing_first_name( 'AureliaProbe' );
		$this->target_order->set_billing_last_name( 'QuillonProbe' );
		$this->target_order->set_billing_email( 'aurelia.order.probe@example.invalid' );
		$this->target_order->save();

		$other_order = OrderHelper::create_order();
		$other_order->set_billing_first_name( 'BenedictProbe' );
		$other_order->set_billing_last_name( 'RenshawProbe' );
		$other_order->set_billing_email( 'benedict.order.probe@example.invalid' );
		$other_order->save();
	}

	/**
	 * Numeric order IDs retain V1 search semantics.
	 */
	public function test_order_search_by_id_matches_v1(): void {
		$this->assert_order_search_finds_target( (string) $this->target_order->get_id() );
	}

	/**
	 * Billing first names retain V1 search semantics.
	 */
	public function test_order_search_by_billing_first_name_matches_v1(): void {
		$this->assert_order_search_finds_target( 'AureliaProbe' );
	}

	/**
	 * Billing last names retain V1 search semantics.
	 */
	public function test_order_search_by_billing_last_name_matches_v1(): void {
		$this->assert_order_search_finds_target( 'QuillonProbe' );
	}

	/**
	 * Partial billing emails retain V1 search semantics.
	 */
	public function test_order_search_by_billing_email_matches_v1(): void {
		$this->assert_order_search_finds_target( 'aurelia.order.probe' );
	}

	/**
	 * Dispatch a real V2 request and assert that it finds the target order.
	 *
	 * @param string $search Search value.
	 */
	private function assert_order_search_finds_target( string $search ): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( array( 'search' => $search ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals(
			array( $this->target_order->get_id() ),
			wp_list_pluck( $data, 'id' )
		);
	}
}

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
}
