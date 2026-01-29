<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\Products_Controller;
use WCPOS\WooCommercePOS\API\Product_Variations_Controller;
use WCPOS\WooCommercePOS\API\Orders_Controller;

/**
 * Tests for decimal quantity functionality.
 *
 * These tests require the decimal_qty setting to be enabled BEFORE the API routes
 * are registered, which is why they use WCPOS_REST_Decimal_Quantity_Unit_Test_Case.
 *
 * @internal
 * @coversNothing
 */
class Test_Decimal_Quantities extends WCPOS_REST_Decimal_Quantity_Unit_Test_Case {
	/**
	 * @var Products_Controller
	 */
	protected $products_endpoint;

	/**
	 * @var Product_Variations_Controller
	 */
	protected $variations_endpoint;

	/**
	 * @var Orders_Controller
	 */
	protected $orders_endpoint;

	public function setUp(): void {
		parent::setUp();

		$this->products_endpoint   = new Products_Controller();
		$this->variations_endpoint = new Product_Variations_Controller();
		$this->orders_endpoint     = new Orders_Controller();
	}

	/**
	 * Test that decimal_qty setting is enabled.
	 */
	public function test_decimal_qty_setting_enabled(): void {
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );
	}

	/**
	 * Test that product schema allows decimal stock quantity.
	 */
	public function test_product_schema_allows_decimal_stock_quantity(): void {
		$schema = $this->products_endpoint->get_item_schema();
		$this->assertEquals( 'number', $schema['properties']['stock_quantity']['type'] );
	}

	/**
	 * Test that variation schema allows decimal stock quantity.
	 */
	public function test_variation_schema_allows_decimal_stock_quantity(): void {
		$schema = $this->variations_endpoint->get_item_schema();
		$this->assertEquals( 'number', $schema['properties']['stock_quantity']['type'] );
	}

	/**
	 * Test that order line item schema allows decimal quantity.
	 */
	public function test_order_line_item_schema_allows_decimal_quantity(): void {
		$schema = $this->orders_endpoint->get_item_schema();
		$this->assertEquals( array( 'number' ), $schema['properties']['line_items']['items']['properties']['quantity']['type'] );
	}

	/**
	 * Test updating product with decimal stock quantity.
	 */
	public function test_product_update_with_decimal_stock_quantity(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_manage_stock( true );
		$product->save();

		$request = $this->wp_rest_patch_request( '/wcpos/v1/products/' . $product->get_id() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'stock_quantity' => '3.85',
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . print_r( $data, true ) );
		$this->assertEquals( 3.85, $data['stock_quantity'] );
	}

	/**
	 * Test updating variation with decimal stock quantity.
	 */
	public function test_variation_update_with_decimal_stock_quantity(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$variation     = wc_get_product( $variation_ids[0] );
		$variation->set_manage_stock( true );
		$variation->save();

		$request = $this->wp_rest_patch_request( '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0] );
		$request->set_body_params(
			array(
				'stock_quantity' => '3.85',
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . print_r( $data, true ) );
		$this->assertEquals( 3.85, $data['stock_quantity'] );
	}

	/**
	 * Test creating order with decimal quantity.
	 */
	public function test_create_order_with_decimal_quantity(): void {
		$product = ProductHelper::create_simple_product();

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'payment_method' => 'pos_cash',
				'line_items'     => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => '1.5',
					),
				),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status(), 'Response: ' . print_r( $data, true ) );
		$this->assertEquals( 'woocommerce-pos', $data['created_via'] );
	}

	/**
	 * Test product response includes decimal stock quantity.
	 */
	public function test_product_response_with_decimal_stock_quantity(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 1.5 );
		$product->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1.5, $data['stock_quantity'] );
	}

	/**
	 * Test variation response includes decimal stock quantity.
	 */
	public function test_variation_response_with_decimal_stock_quantity(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$variation     = wc_get_product( $variation_ids[0] );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 1.5 );
		$variation->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0] );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1.5, $data['stock_quantity'] );
	}

	/**
	 * Test product orderby decimal stock quantity.
	 */
	public function test_product_orderby_decimal_stock_quantity(): void {
		$product1 = ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => '20.7',
			)
		);
		$product2 = ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => '3.5',
			)
		);
		$product3 = ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => '11.2',
			)
		);

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order'   => 'asc',
			)
		);

		$response   = $this->server->dispatch( $request );
		$data       = $response->get_data();
		$quantities = wp_list_pluck( $data, 'stock_quantity' );

		$this->assertEquals( array( 3.5, 11.2, 20.7 ), $quantities );
	}
}
