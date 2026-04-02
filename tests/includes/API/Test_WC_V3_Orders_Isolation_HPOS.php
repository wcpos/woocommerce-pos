<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WC_Order;
use WP_REST_Request;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_WC_V3_Orders_Isolation_HPOS extends WCPOS_REST_HPOS_Unit_Test_Case {
	use HPOSToggleTrait;

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

	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_all_filters( 'wc_allow_changing_orders_storage_while_sync_is_pending' );

		parent::tearDown();
	}

	public function test_wc_v3_orders_list_status_any_is_unmodified_hpos(): void {
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
	}

	public function test_wc_v3_orders_list_created_via_filter_is_not_rewritten_hpos(): void {
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

	public function test_wc_v3_orders_list_does_not_include_wcpos_payment_or_receipt_links_hpos(): void {
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
			$this->assertArrayNotHasKey( 'payment', $links );
			$this->assertArrayNotHasKey( 'receipt', $links );
		}
	}
}
