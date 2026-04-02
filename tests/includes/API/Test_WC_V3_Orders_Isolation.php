<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WP_REST_Request;
use WC_Order;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_WC_V3_Orders_Isolation extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Helper to create a plain wc/v3 GET request.
	 */
	private function wc_rest_get_request( string $path ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_method( 'GET' );
		$request->set_route( $path );

		return $request;
	}

	/**
	 * Helper to create an order and set created_via before saving.
	 */
	private function create_order_with_created_via( string $created_via, array $args = array() ): WC_Order {
		$order = OrderHelper::create_order( $args );

		if ( method_exists( $order, 'set_created_via' ) ) {
			$order->set_created_via( $created_via );
			$order->save();
		}

		return $order;
	}

	public function test_wc_v3_orders_list_status_any_is_unmodified(): void {
		$pending_order   = OrderHelper::create_order( array( 'status' => 'pending' ) );
		$completed_order = OrderHelper::create_order( array( 'status' => 'completed' ) );

		$request = $this->wc_rest_get_request( '/wc/v3/orders' );
		$request->set_param( 'status', 'any' );

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

	public function test_wc_v3_orders_list_fields_projection_is_unmodified(): void {
		$order = OrderHelper::create_order();

		$request = $this->wc_rest_get_request( '/wc/v3/orders' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id', 'date_modified_gmt' ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $data );

		$order_data = $data[0];
		$this->assertEquals( $order->get_id(), $order_data['id'] );
		$this->assertArrayHasKey( 'billing', $order_data );
		$this->assertArrayHasKey( 'shipping', $order_data );
		$this->assertArrayHasKey( 'payment_method', $order_data );
		$this->assertArrayHasKey( 'payment_method_title', $order_data );
		$this->assertArrayHasKey( 'meta_data', $order_data );
		$this->assertArrayHasKey( 'created_via', $order_data );
		$this->assertNotSame( 'woocommerce-pos', $order_data['created_via'] );
	}

	public function test_wc_v3_orders_list_created_via_filter_is_not_rewritten(): void {
		$checkout_order = $this->create_order_with_created_via( 'checkout' );
		$this->create_order_with_created_via( 'rest-api' );

		$request = $this->wc_rest_get_request( '/wc/v3/orders' );
		$request->set_param( 'created_via', 'checkout' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertEquals( $checkout_order->get_id(), $data[0]['id'] );
		$this->assertSame( 'checkout', $data[0]['created_via'] );
	}

	public function test_wc_v3_single_order_with_fields_projection_is_unmodified(): void {
		$order = OrderHelper::create_order();

		$request = $this->wc_rest_get_request( '/wc/v3/orders/' . $order->get_id() );
		$request->set_param( 'fields', array( 'id', 'date_modified_gmt' ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $order->get_id(), $data['id'] );
		$this->assertArrayHasKey( 'billing', $data );
		$this->assertArrayHasKey( 'shipping', $data );
		$this->assertArrayHasKey( 'payment_method', $data );
		$this->assertArrayHasKey( 'payment_method_title', $data );
		$this->assertArrayHasKey( 'meta_data', $data );
		$this->assertArrayHasKey( 'created_via', $data );
		$this->assertNotSame( 'woocommerce-pos', $data['created_via'] );
	}

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
		$request->set_param( 'status', 'any' );
		$request->set_param( 'search', 'Filter' );
		$request->set_param( 'fields', array( 'id', 'date_modified_gmt' ) );
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
