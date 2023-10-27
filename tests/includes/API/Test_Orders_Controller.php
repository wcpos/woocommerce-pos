<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\API\Orders_Controller;
use WP_REST_Request;
use WP_User;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Orders_Controller extends WC_REST_Unit_Test_Case {
	/**
	 * @var Orders_Controller
	 */
	protected $endpoint;

	/**
	 * @var WP_User
	 */
	protected $user;

	
	public function setup(): void {
		parent::setUp();

		$this->endpoint = new Orders_Controller();
		$this->user     = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		new Api();
		wp_set_current_user( $this->user );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function get_wp_rest_request( $method = 'GET', $path = '/wcpos/v1/orders' ) {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_method( $method );
		$request->set_route( $path );

		return $request;
	}

	public function test_namespace_property(): void {
		$reflection         = new ReflectionClass($this->endpoint);
		$namespace_property = $reflection->getProperty('namespace');
		$namespace_property->setAccessible(true);
		
		$this->assertEquals('wcpos/v1', $namespace_property->getValue($this->endpoint));
	}

	public function test_rest_base(): void {
		$reflection         = new ReflectionClass($this->endpoint);
		$rest_base_property = $reflection->getProperty('rest_base');
		$rest_base_property->setAccessible(true);
		
		$this->assertEquals('orders', $rest_base_property->getValue($this->endpoint));
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/products', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/batch', $routes );
	}

	/**
	 * Get all expected fields.
	 */
	/**
	 * Get all expected fields.
	 */
	public function get_expected_response_fields() {
		return array(
			'id',
			'parent_id',
			'number',
			'order_key',
			'created_via',
			'version',
			'status',
			'currency',
			'date_created',
			'date_created_gmt',
			'date_modified',
			'date_modified_gmt',
			'discount_total',
			'discount_tax',
			'shipping_total',
			'shipping_tax',
			'cart_tax',
			'total',
			'total_tax',
			'prices_include_tax',
			'customer_id',
			'customer_ip_address',
			'customer_user_agent',
			'customer_note',
			'billing',
			'shipping',
			'payment_method',
			'payment_method_title',
			'transaction_id',
			'date_paid',
			'date_paid_gmt',
			'date_completed',
			'date_completed_gmt',
			'cart_hash',
			'meta_data',
			'line_items',
			'tax_lines',
			'shipping_lines',
			'fee_lines',
			'coupon_lines',
			'currency_symbol',
			'refunds',
			'payment_url',
			'is_editable',
			'needs_payment',
			'needs_processing',
		);
	}

	public function test_order_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$product  = OrderHelper::create_order();
		$response = $this->server->dispatch( $this->get_wp_rest_request( 'GET', '/wcpos/v1/orders/' . $product->get_id() ) );

		$this->assertEquals( 200, $response->get_status() );

		$response_fields = array_keys( $response->get_data() );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	public function test_order_api_get_all_ids(): void {
		$order    = OrderHelper::create_order();
		$request  = $this->get_wp_rest_request( 'GET', '/wcpos/v1/orders' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array('id') );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( array( (object) array( 'id' => $order->get_id() ) ), $response->get_data() );
	}

	/**
	 * Each order needs a UUID.
	 */
	public function test_order_response_contains_uuid_meta_data(): void {
		$order     = OrderHelper::create_order();
		$request   = $this->get_wp_rest_request( 'GET', '/wcpos/v1/orders/' . $order->get_id() );
		$response  = $this->server->dispatch($request);

		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		$found      = false;
		$uuid_value = '';
		$count      = 0;

		// Look for the _woocommerce_pos_uuid key in meta_data
		foreach ($data['meta_data'] as $meta) {
			if ('_woocommerce_pos_uuid' === $meta['key']) {
				$count++;
				$uuid_value = $meta['value'];
			}
		}

		$this->assertEquals(1, $count, 'There should only be one _woocommerce_pos_uuid.');
		$this->assertTrue(Uuid::isValid($uuid_value), 'The UUID value is not valid.');
	}

	public function test_orderby_status(): void {
		$order1    = OrderHelper::create_order( array( 'status' => 'pending' ) );
		$order2    = OrderHelper::create_order( array( 'status' => 'completed' ) );
		$request   = $this->get_wp_rest_request( 'GET', '/wcpos/v1/orders' );
		$request->set_query_params( array( 'orderby' => 'status', 'order' => 'asc' ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$statuses = wp_list_pluck( $data, 'status' );

		$this->assertEquals( $statuses, array( 'completed', 'pending' ) );

		// reverse order
		$request->set_query_params( array( 'orderby' => 'status', 'order' => 'desc' ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$statuses = wp_list_pluck( $data, 'status' );

		$this->assertEquals( $statuses, array( 'pending', 'completed' ) );
	}

	public function test_orderby_customer(): void {
		$customer  = CustomerHelper::create_customer();
		$order1    = OrderHelper::create_order( array( 'customer_id' => $customer->get_id() ) );
		$order2    = OrderHelper::create_order();
		$request   = $this->get_wp_rest_request( 'GET', '/wcpos/v1/orders' );
		$request->set_query_params( array( 'orderby' => 'customer_id', 'order' => 'asc' ) );
		$response     = rest_get_server()->dispatch( $request );
		$data         = $response->get_data();
		$customer_ids = wp_list_pluck( $data, 'customer_id' );

		$this->assertEquals( $customer_ids, array( 1, $customer->get_id() ) );

		// reverse order
		$request->set_query_params( array( 'orderby' => 'customer_id', 'order' => 'desc' ) );
		$response     = rest_get_server()->dispatch( $request );
		$data         = $response->get_data();
		$customer_ids = wp_list_pluck( $data, 'customer_id' );

		$this->assertEquals( $customer_ids, array( $customer->get_id(), 1 ) );
	}

	public function test_orderby_payment_method(): void {
		$order1    = OrderHelper::create_order( array( 'payment_method' => 'pos_cash' ) );
		$order2    = OrderHelper::create_order( array( 'payment_method' => 'pos_card' ) );
		$request   = $this->get_wp_rest_request( 'GET', '/wcpos/v1/orders' );
		$request->set_query_params( array( 'orderby' => 'payment_method', 'order' => 'asc' ) );
		$response        = rest_get_server()->dispatch( $request );
		$data            = $response->get_data();
		$payment_methods = wp_list_pluck( $data, 'payment_method_title' );

		$this->assertEquals( $payment_methods, array( 'Card', 'Cash' ) );

		// reverse order
		$request->set_query_params( array( 'orderby' => 'payment_method', 'order' => 'desc' ) );
		$response        = rest_get_server()->dispatch( $request );
		$data            = $response->get_data();
		$payment_methods = wp_list_pluck( $data, 'payment_method_title' );

		$this->assertEquals( $payment_methods, array( 'Cash', 'Card' ) );
	}
}
