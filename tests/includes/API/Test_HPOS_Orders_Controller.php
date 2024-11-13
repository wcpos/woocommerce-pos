<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Ramsey\Uuid\Uuid;
use WC_Order_Item_Fee;
use WCPOS\WooCommercePOS\API\Orders_Controller;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_HPOS_Orders_Controller extends WCPOS_REST_HPOS_Unit_Test_Case {
	use HPOSToggleTrait;

	/**
	 * @var OrdersTableDataStore
	 */
	private $sut;

	/**
	 * Whether COT was enabled before the test.
	 *
	 * @var bool
	 */
	private $cot_state;

	public function setup(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		$this->setup_cot();
		$this->cot_state = OrderUtil::custom_orders_table_usage_is_enabled();
		$this->toggle_cot_feature_and_usage( true );

		$this->endpoint = new Orders_Controller();
	}

	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();

		remove_all_filters( 'wc_allow_changing_orders_storage_while_sync_is_pending' );

		parent::tearDown();
	}

	/**
	 * Test HPOS is enabled.
	 */
	public function test_hpos_enabled(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
		$order    = OrderHelper::create_order();
		$this->assert_order_record_existence( $order->get_id(), true, true );
	}

	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );

		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	public function test_rest_base(): void {
		$rest_base = $this->get_reflected_property_value( 'rest_base' );

		$this->assertEquals( 'orders', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/orders', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/orders/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/orders/batch', $routes );

		// added by WooCommerce POS
		$this->assertArrayHasKey( '/wcpos/v1/orders/(?P<order_id>[\d]+)/email', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/orders/statuses', $routes );
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

		$order    = OrderHelper::create_order();
		$request     = $this->wp_rest_get_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$response_fields = array_keys( $response->get_data() );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	/**
	 *
	 */
	public function test_order_api_get_all_ids(): void {
		$order1    = OrderHelper::create_order();
		$order2    = OrderHelper::create_order();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEquals( array( $order1->get_id(), $order2->get_id() ), $ids );
	}

	/**
	 *
	 */
	public function test_order_api_get_all_ids_with_date_modified_gmt(): void {
		$order1    = OrderHelper::create_order();
		$order2    = OrderHelper::create_order();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id', 'date_modified_gmt' ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data    = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEquals( array( $order1->get_id(), $order2->get_id() ), $ids );

				// Verify that date_modified_gmt is present for all products and correctly formatted.
		foreach ( $data as $d ) {
			$this->assertArrayHasKey( 'date_modified_gmt', $d, "The 'date_modified_gmt' field is missing for product ID {$d['id']}." );
			$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|(\+\d{2}:\d{2}))?/', $d['date_modified_gmt'], "The 'date_modified_gmt' field for product ID {$d['id']} is not correctly formatted." );
		}
	}

	/**
	 * Each order needs a UUID.
	 */
	public function test_order_response_contains_uuid_meta_data(): void {
		$order     = OrderHelper::create_order();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response  = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$found      = false;
		$uuid_value = '';
		$count      = 0;

		// Look for the _woocommerce_pos_uuid key in meta_data
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_uuid' === $meta['key'] ) {
				$count++;
				$uuid_value = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one _woocommerce_pos_uuid.' );
		$this->assertTrue( Uuid::isValid( $uuid_value ), 'The UUID value is not valid.' );
	}

	/**
	 * This works on the app, but not in the test??
	 */
	public function test_orderby_status(): void {
		$order1    = OrderHelper::create_order( array( 'status' => 'pending' ) );
		$order2    = OrderHelper::create_order( array( 'status' => 'completed' ) );
		$order3    = OrderHelper::create_order( array( 'status' => 'on-hold' ) );
		$order4    = OrderHelper::create_order( array( 'status' => 'processing' ) );
		$request   = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_query_params(
			array(
				'orderby' => 'status',
				'order' => 'asc',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data     = $response->get_data();
		$statuses = wp_list_pluck( $data, 'status' );

		$this->assertEquals( $statuses, array( 'completed', 'on-hold', 'pending', 'processing' ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'status',
				'order' => 'desc',
			)
		);
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$statuses = wp_list_pluck( $data, 'status' );

		$this->assertEquals( $statuses, array( 'processing', 'pending', 'on-hold', 'completed' ) );
	}

	/**
	 *
	 */
	public function test_orderby_customer(): void {
		$customer  = CustomerHelper::create_customer();
		$order1    = OrderHelper::create_order( array( 'customer_id' => $customer->get_id() ) );
		$order2    = OrderHelper::create_order();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_query_params(
			array(
				'orderby' => 'customer_id',
				'order' => 'asc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data         = $response->get_data();
		$customer_ids = wp_list_pluck( $data, 'customer_id' );

		$this->assertEquals( $customer_ids, array( 1, $customer->get_id() ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'customer_id',
				'order' => 'desc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$customer_ids = wp_list_pluck( $data, 'customer_id' );

		$this->assertEquals( $customer_ids, array( $customer->get_id(), 1 ) );
	}

	public function test_orderby_payment_method(): void {
		$order1    = OrderHelper::create_order( array( 'payment_method' => 'pos_cash' ) );
		$order2    = OrderHelper::create_order( array( 'payment_method' => 'pos_card' ) );
		$request   = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_query_params(
			array(
				'orderby' => 'payment_method',
				'order' => 'asc',
			)
		);
		$response        = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data            = $response->get_data();
		$payment_methods = wp_list_pluck( $data, 'payment_method_title' );

		$this->assertEquals( $payment_methods, array( 'Card', 'Cash' ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'payment_method',
				'order' => 'desc',
			)
		);
		$response        = $this->server->dispatch( $request );
		$data            = $response->get_data();
		$payment_methods = wp_list_pluck( $data, 'payment_method_title' );

		$this->assertEquals( $payment_methods, array( 'Cash', 'Card' ) );
	}

	public function test_orderby_total(): void {
		$order1    = OrderHelper::create_order( array( 'total' => 100 ) );
		$order2    = OrderHelper::create_order( array( 'total' => 200 ) );
		$request   = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_query_params(
			array(
				'orderby' => 'total',
				'order' => 'asc',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data     = $response->get_data();
		$totals   = wp_list_pluck( $data, 'total' );

		$this->assertEquals( $totals, array( 100, 200 ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'total',
				'order' => 'desc',
			)
		);
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$totals   = wp_list_pluck( $data, 'total' );

		$this->assertEquals( $totals, array( 200, 100 ) );
	}

	/**
	 * Line items.
	 */
	public function test_line_items_contains_uuid_meta_data(): void {
		$order     = OrderHelper::create_order();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response  = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data['line_items'] ) );

		$found      = false;
		$uuid_value = '';
		$count      = 0;

		// Look for the _woocommerce_pos_uuid key in meta_data
		foreach ( $data['line_items'][0]['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_uuid' === $meta['key'] ) {
				$count++;
				$uuid_value = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one _woocommerce_pos_uuid.' );
		$this->assertTrue( Uuid::isValid( $uuid_value ), 'The UUID value is not valid.' );
	}

	/**
	 * Shipping lines.
	 */
	public function test_shipping_lines_contains_uuid_meta_data(): void {
		$order     = OrderHelper::create_order();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response  = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data['shipping_lines'] ) );

		$found      = false;
		$uuid_value = '';
		$count      = 0;

		// Look for the _woocommerce_pos_uuid key in meta_data
		foreach ( $data['shipping_lines'][0]['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_uuid' === $meta['key'] ) {
				$count++;
				$uuid_value = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one _woocommerce_pos_uuid.' );
		$this->assertTrue( Uuid::isValid( $uuid_value ), 'The UUID value is not valid.' );
	}

	/**
	 * Fee lines.
	 */
	public function test_fee_lines_contains_uuid_meta_data(): void {
		$order         = OrderHelper::create_order();
		$fee           = new WC_Order_Item_Fee();
		$order->add_item( $fee );
		$order->save();
		$request       = $this->wp_rest_get_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response      = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data['fee_lines'] ) );

		$found      = false;
		$uuid_value = '';
		$count      = 0;

		// Look for the _woocommerce_pos_uuid key in meta_data
		foreach ( $data['fee_lines'][0]['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_uuid' === $meta['key'] ) {
				$count++;
				$uuid_value = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one _woocommerce_pos_uuid.' );
		$this->assertTrue( Uuid::isValid( $uuid_value ), 'The UUID value is not valid.' );
	}

	/**
	 * Create a new order.
	 */
	public function test_create_guest_order(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'payment_method' => 'pos_cash',
				'line_items'     => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
					),
				),
				'billing' => array(
					'email'      => '',
					'first_name' => '',
					'last_name'  => '',
					'address_1'  => '',
					'address_2'  => '',
					'city'       => '',
					'state'      => '',
					'postcode'   => '',
					'country'    => '',
					'phone'      => '',
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status() );

		$this->assertEquals( 'pending', $data['status'] );
		$this->assertEquals( 'woocommerce-pos', $data['created_via'] );
		$this->assertEquals( 0, $data['customer_id'] );
	}

	/**
	 * GOTCHA: if there is billing info, we need to allow no email for guest orders.
	 */
	public function test_create_guest_order_with_billing_info(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'payment_method' => 'pos_cash',
				'line_items'     => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$cashier    = null;
		$count      = 0;

		// Look for the _pos_user key in meta_data
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_pos_user' === $meta['key'] ) {
				$count++;
				$cashier = $meta['value'];
			}
		}

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'pending', $data['status'] );
		$this->assertEquals( 'woocommerce-pos', $data['created_via'] );
		$this->assertEquals( 'pos_cash', $data['payment_method'] );
		$this->assertEquals( 0, $data['customer_id'] );
		$this->assertEquals( 1, \count( $data['line_items'] ) );
		$this->assertEquals( 1, $count, 'There should only be one _pos_user.' );
		$this->assertEquals( $this->user, $cashier, 'The cashier ID is not correct.' );
	}

	/**
	 * Send receipt to customer.
	 */
	public function test_send_receipt(): void {
		$email         = 'sendtest@example.com';
		$email_sent    = false;
		$note_added    = false;
		$expected_note = sprintf( 'Order details manually sent to %s from WooCommerce POS.', $email );

		$email_sent_callback = function () use ( &$email_sent ): void {
			$email_sent = true;
		};

		$order_note_filter_check = function ( $commentdata, $data ) use ( &$note_added, $expected_note ) {
			if ( $commentdata['comment_content'] === $expected_note ) {
				$note_added = true;
			}

			return $commentdata;
		};

		add_action( 'woocommerce_before_resend_order_emails', $email_sent_callback );
		add_filter( 'woocommerce_new_order_note_data', $order_note_filter_check, 10, 2 );

		$order     = OrderHelper::create_order();
		$request   = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/email' );
		$request->set_body_params(
			array(
				'email' => $email,
			)
		);
		$response  = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, $data['success'] );
		$this->assertTrue( $email_sent, 'Order receipt email was not sent.' );
		$this->assertTrue( $note_added, 'Specific order note was not added.' );

		// Remove the action hook after the test to clean up
		remove_action( 'woocommerce_before_resend_order_emails', $email_sent_callback );
		remove_filter( 'woocommerce_new_order_note_data', $order_note_filter_check );
	}

	/**
	 * Saving variation attributes.
	 *
	 * GOTCHA: saving a variation attributes will cause duplication, eg:
	 * retrieve order from REST API, send back, now you have duplicate attributes.
	 *
	 * @TODO - this is working in the app, but not in the test??
	 */
	public function test_order_save_line_item_attributes(): void {
		$product        = ProductHelper::create_variation_product();
		$variation_ids  = $product->get_children();
		$variation      = wc_get_product( $variation_ids[0] );
		$order          = OrderHelper::create_order( array( 'product' => $variation ) );

		// just retrieve order, no changes
		$request       = $this->wp_rest_get_request( '/wcpos/v1/orders/' . $order->get_id() );
		$response      = $this->server->dispatch( $request );
		$data          = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data['line_items'] ), 'There should be one line item.' );

		$attr       = '';
		$count      = 0;

		// Look for the pa_size key in meta_data
		foreach ( $data['line_items'][0]['meta_data'] as $meta ) {
			if ( 'pa_size' === $meta['key'] ) {
				$count++;
				$attr = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one pa_size.' );
		$this->assertEquals( 'small', $attr, 'The pa_size value is not valid.' );

		// now, save the order back to the API
		$request       = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() );
		$request->set_body_params( $data );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data['line_items'] ), 'There should be one line item.' );

		// Look for the pa_size key in meta_data
		foreach ( $data['line_items'][0]['meta_data'] as $meta ) {
			if ( 'pa_size' === $meta['key'] ) {
				$count++;
				$attr = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one pa_size.' );
		$this->assertEquals( 'small', $attr, 'The pa_size value is not valid.' );
	}

	/**
	 * Saving line item with parent_name = null.
	 *
	 * GOTCHA: WC REST API can return a line item with parent_name = null,
	 * but it won't pass validation when saving.
	 */
	public function test_order_save_line_item_with_null_parent_name(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'line_items'     => array(
					array(
						'product_id'  => 1,
						'quantity'    => 1,
						'parent_name' => null,
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'woocommerce-pos', $data['created_via'] );
	}

	/**
	 * Retrieve all order statuses.
	 */
	public function test_get_order_statuses(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders/statuses' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotEmpty( $data );

		// Ensure that the response is an array
		$this->assertIsArray( $data );

		// Check if each element in the array has the required structure
		foreach ( $data as $status ) {
			$this->assertIsArray( $status );
			$this->assertArrayHasKey( 'id', $status );
			$this->assertArrayHasKey( 'name', $status );

			// Check if the 'id' and 'name' fields are strings
			$this->assertIsString( $status['id'] );
			$this->assertIsString( $status['name'] );
		}
	}

	/**
	 *
	 */
	public function test_order_search_by_id() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'search', (string) $order1->get_id() );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids      = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $order1->get_id() ), $ids );
	}

	/**
	 *
	 */
	public function test_order_search_by_billing_first_name() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();
		$order2->set_billing_first_name( 'John' );
		$order2->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'search', 'John' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids      = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $order2->get_id() ), $ids );
	}

	/**
	 *
	 */
	public function test_order_search_by_billing_last_name() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();
		$order1->set_billing_last_name( 'Doe' );
		$order1->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'search', 'Doe' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids      = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $order1->get_id() ), $ids );
	}

	/**
	 *
	 */
	public function test_order_search_by_billing_email() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();
		$order1->set_billing_email( 'posuser@example.com' );
		$order1->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'search', 'posuser' );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids      = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $order1->get_id() ), $ids );
	}

	/**
	 *
	 */
	public function test_order_search_by_id_with_includes() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'search', (string) $order1->get_id() );
		$request->set_param( 'include', array( $order2->get_id() ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 0, \count( $data ) );
	}

	/**
	 *
	 */
	public function test_order_search_by_id_with_excludes() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'search', (string) $order1->get_id() );
		$request->set_param( 'exclude', array( $order1->get_id() ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 0, \count( $data ) );
	}

	/**
	 *
	 */
	public function test_order_search_by_billing_first_name_with_includes() {
		$order1 = OrderHelper::create_order();
		$order1->set_billing_first_name( 'John' );
		$order1->save();
		$order2 = OrderHelper::create_order();
		$order2->set_billing_first_name( 'John' );
		$order2->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'search', 'John' );
		$request->set_param( 'include', array( $order2->get_id() ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids      = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $order2->get_id() ), $ids );
	}

	/**
	 *
	 */
	public function test_order_search_by_billing_first_name_with_excludes() {
		$order1 = OrderHelper::create_order();
		$order1->set_billing_first_name( 'John' );
		$order1->save();
		$order2 = OrderHelper::create_order();
		$order2->set_billing_first_name( 'John' );
		$order2->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'search', 'John' );
		$request->set_param( 'exclude', array( $order1->get_id() ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids      = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $order2->get_id() ), $ids );
	}

	/**
	 *
	 */
	public function test_create_order_with_decimal_quantity() {
		$this->setup_decimal_quantity_tests();

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'payment_method' => 'pos_cash',
				'line_items'     => array(
					array(
						'product_id' => 1,
						'quantity'   => '1.5',
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status() );

		$this->assertEquals( 'woocommerce-pos', $data['created_via'] );
	}

	/**
	 * 
	 */
	public function test_filter_order_by_cashier() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();
		$order2->add_meta_data( '_pos_user', 4, true );
		$order2->save();

		$request = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'pos_cashier', 4 );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids      = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $order2->get_id() ), $ids );
	}

	/**
	 * 
	 */
	public function test_filter_order_by_store() {
		$order1 = OrderHelper::create_order();
		$order2 = OrderHelper::create_order();
		$order2->add_meta_data( '_pos_store', 64, true );
		$order2->save();

		$request = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$request->set_param( 'pos_store', 64 );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids      = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $order2->get_id() ), $ids );
	}
}
