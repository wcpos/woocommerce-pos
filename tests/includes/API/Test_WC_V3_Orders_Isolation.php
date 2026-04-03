<?php
/**
 * Tests for WC REST API v3 orders endpoint isolation.
 *
 * Verifies that plain wc/v3 order responses remain unmodified
 * when the WCPOS plugin is active.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Tests\API\Traits\WC_REST_Order_Helpers;
use WP_REST_Request;

/**
 * Class Test_WC_V3_Orders_Isolation
 *
 * @internal
 *
 * @coversNothing
 */
class Test_WC_V3_Orders_Isolation extends WCPOS_REST_Unit_Test_Case {
	use WC_REST_Order_Helpers;

	/**
	 * Verify that listing orders with status=any returns all orders unmodified.
	 */
	public function test_wc_v3_orders_list_status_any_is_unmodified(): void {
		$pending_order   = OrderHelper::create_order( array( 'status' => 'pending' ) );
		$completed_order = OrderHelper::create_order( array( 'status' => 'completed' ) );

		$request = $this->wc_rest_get_request( '/wc/v3/orders' );
		$request->set_param( 'status', array( 'any' ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 2, $data );
		$this->assertEqualsCanonicalizing(
			array( $pending_order->get_id(), $completed_order->get_id() ),
			wp_list_pluck( $data, 'id' )
		);
		$this->assertEqualsCanonicalizing(
			array( 'pending', 'completed' ),
			wp_list_pluck( $data, 'status' )
		);
	}

	/**
	 * Verify that searching orders by billing name returns matching results unmodified.
	 */
	public function test_wc_v3_orders_list_search_is_unmodified(): void {
		$matching_order = OrderHelper::create_order();
		$matching_order->set_billing_first_name( 'John' );
		$matching_order->save();

		OrderHelper::create_order();

		$request = $this->wc_rest_get_request( '/wc/v3/orders' );
		$request->set_param( 'search', 'John' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertEquals( $matching_order->get_id(), $data[0]['id'] );
		$this->assertSame( 'John', $data[0]['billing']['first_name'] );
	}

	/**
	 * Verify that _fields projection returns only the requested fields.
	 */
	public function test_wc_v3_orders_list_fields_projection_is_unmodified(): void {
		$order = OrderHelper::create_order();

		$request = $this->wc_rest_get_request( '/wc/v3/orders' );
		$request->set_param( 'per_page', 100 );
		$request->set_param( '_fields', array( 'id', 'date_modified_gmt' ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $data );

		$order_data = $data[0];
		$this->assertSame( array( 'id', 'date_modified_gmt' ), array_keys( $order_data ) );
		$this->assertEquals( $order->get_id(), $order_data['id'] );
	}

	/**
	 * Verify that the created_via collection parameter filters orders correctly.
	 *
	 * The created_via collection parameter was added in WC 9.6.
	 */
	public function test_wc_v3_orders_list_created_via_filter_is_not_rewritten(): void {
		if ( version_compare( WC_VERSION, '9.6', '<' ) ) {
			$this->markTestSkipped( 'created_via collection parameter requires WC 9.6+.' );
		}

		$checkout_order = $this->create_order_with_created_via( 'checkout' );
		$this->create_order_with_created_via( 'rest-api' );

		$request = $this->wc_rest_get_request( '/wc/v3/orders' );
		$request->set_param( 'created_via', array( 'checkout' ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertEquals( $checkout_order->get_id(), $data[0]['id'] );
		$this->assertSame( 'checkout', $data[0]['created_via'] );
	}

	/**
	 * Verify that _fields projection works for a single order endpoint.
	 */
	public function test_wc_v3_single_order_with_fields_projection_is_unmodified(): void {
		$order = OrderHelper::create_order();

		$request = $this->wc_rest_get_request( '/wc/v3/orders/' . $order->get_id() );
		$request->set_param( '_fields', array( 'id', 'date_modified_gmt' ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( array( 'id', 'date_modified_gmt' ), array_keys( $data ) );
		$this->assertEquals( $order->get_id(), $data['id'] );
	}

	/**
	 * Verify that WCPOS payment and receipt links are not included in wc/v3 responses.
	 */
	public function test_wc_v3_orders_list_does_not_include_wcpos_payment_or_receipt_links(): void {
		OrderHelper::create_order();
		OrderHelper::create_order();

		$request  = $this->wc_rest_get_request( '/wc/v3/orders' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $data );

		foreach ( $data as $order ) {
			$this->assertArrayHasKey( '_links', $order );
			$links = $order['_links'] ?? array();
			$this->assertArrayNotHasKey( 'payment', $links, 'wc/v3 order list item should not contain wcpos payment link' );
			$this->assertArrayNotHasKey( 'receipt', $links, 'wc/v3 order list item should not contain wcpos receipt link' );
		}
	}

	/**
	 * Verify that WCPOS-specific query params do not trigger meta_query rewrites on wc/v3.
	 */
	public function test_wc_v3_orders_list_request_does_not_trigger_wcpos_query_rewrites(): void {
		$matching_order = OrderHelper::create_order();
		$matching_order->set_billing_first_name( 'Filter' );
		$matching_order->save();
		OrderHelper::create_order();

		$captured_args = null;
		$collector     = function ( array $args, WP_REST_Request $request ) use ( &$captured_args ) {
			$captured_args = $args;
			return $args;
		};

		add_filter( 'woocommerce_rest_shop_order_object_query', $collector, 999, 2 );

		$request = $this->wc_rest_get_request( '/wc/v3/orders' );
		$request->set_param( 'status', array( 'any' ) );
		$request->set_param( 'search', 'Filter' );
		$request->set_param( '_fields', array( 'id', 'date_modified_gmt' ) );
		$request->set_param( 'pos_cashier', 123 );
		$request->set_param( 'pos_store', 'front-register' );

		try {
			$response = $this->server->dispatch( $request );
			$data     = $response->get_data();
		} finally {
			remove_filter( 'woocommerce_rest_shop_order_object_query', $collector, 999 );
		}

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $data );
		$this->assertIsArray( $captured_args );

		$meta_query = $captured_args['meta_query'] ?? array();
		foreach ( $meta_query as $clause ) {
			$this->assertNotSame( '_pos_user', $clause['key'] ?? null, 'wc/v3 request should not be rewritten with WCPOS cashier query args' );
			$this->assertNotSame( '_pos_store', $clause['key'] ?? null, 'wc/v3 request should not be rewritten with WCPOS store query args' );
		}
	}
}
