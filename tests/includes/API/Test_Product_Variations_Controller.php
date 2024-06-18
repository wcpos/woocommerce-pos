<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\Product_Variations_Controller;
use WCPOS\WooCommercePOS\Products;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Product_Variations_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Product_Variations_Controller();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );

		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	public function test_rest_base(): void {
		$rest_base = $this->get_reflected_property_value( 'rest_base' );

		$this->assertEquals( 'products/(?P<product_id>[\d]+)/variations', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<product_id>[\d]+)/variations', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<product_id>[\d]+)/variations/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<product_id>[\d]+)/variations/batch', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/(?P<product_id>[\d]+)/variations/generate', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/products/variations', $routes );
	}

	/**
	 * Get all expected fields.
	 */
	public function get_expected_response_fields() {
		return array(
			'id',
			'date_created',
			'date_created_gmt',
			'date_modified',
			'date_modified_gmt',
			'description',
			'permalink',
			'sku',
			'price',
			'regular_price',
			'sale_price',
			'date_on_sale_from',
			'date_on_sale_from_gmt',
			'date_on_sale_to',
			'date_on_sale_to_gmt',
			'on_sale',
			'status',
			'purchasable',
			'virtual',
			'downloadable',
			'downloads',
			'download_limit',
			'download_expiry',
			'tax_status',
			'tax_class',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
			'backorders_allowed',
			'backordered',
			'low_stock_amount',
			'weight',
			'dimensions',
			'shipping_class',
			'shipping_class_id',
			'image',
			'attributes',
			'menu_order',
			'meta_data',
			'_links',
			// Added by WCPOS.
			'barcode',
			// Added in WooCommerce 8.3.0 :/
			'name',
			'parent_id',
		);
	}

	public function test_variation_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$product     = ProductHelper::create_variation_product();
		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$response    = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$variations = $response->get_data();
		$this->assertEquals( 2, \count( $variations ) );
		$response_fields = array_keys( $variations[0] );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );
		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	/**
	 * Test getting all variation IDs.
	 */
	public function test_variation_api_get_all_ids(): void {
		$product1       = ProductHelper::create_variation_product();
		$product2       = ProductHelper::create_variation_product();
		$variation1_ids = $product1->get_children();
		$variation2_ids = $product2->get_children();
		$this->assertEquals( 2, \count( $variation1_ids ) );
		$this->assertEquals( 2, \count( $variation2_ids ) );

		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/variations' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );
		$this->assertEquals( 4, \count( $ids ) );

		$this->assertEqualsCanonicalizing( array( $variation1_ids[0], $variation1_ids[1], $variation2_ids[0], $variation2_ids[1] ), $ids );
	}

	/**
	 * Test getting all variation IDs with modified date.
	 */
	public function test_variation_api_get_all_ids_with_date_modified_gmt(): void {
		$product1       = ProductHelper::create_variation_product();
		$product2       = ProductHelper::create_variation_product();
		$variation1_ids = $product1->get_children();
		$variation2_ids = $product2->get_children();
		$this->assertEquals( 2, \count( $variation1_ids ) );
		$this->assertEquals( 2, \count( $variation2_ids ) );

		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/variations' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id', 'date_modified_gmt' ) );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );
		$this->assertEquals( 4, \count( $ids ) );

		$this->assertEqualsCanonicalizing( array( $variation1_ids[0], $variation1_ids[1], $variation2_ids[0], $variation2_ids[1] ), $ids );

		// Verify that date_modified_gmt is present for all variations and correctly formatted.
		foreach ( $data as $d ) {
			$this->assertArrayHasKey( 'date_modified_gmt', $d, "The 'date_modified_gmt' field is missing for variation ID {$d['id']}." );
			$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|(\+\d{2}:\d{2}))?/', $d['date_modified_gmt'], "The 'date_modified_gmt' field for variation ID {$d['id']} is not correctly formatted." );
		}
	}

	/**
	 * Test getting all variation IDs.
	 */
	public function test_variation_api_get_variable_ids(): void {
		$product       = ProductHelper::create_variation_product();
		$product2       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$this->assertEquals( 2, \count( $variation_ids ) );

		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );
		$this->assertEquals( 2, \count( $ids ) );

		$this->assertEqualsCanonicalizing( array( $variation_ids[1], $variation_ids[0] ), $ids );
	}

	/**
	 * Test getting all variation IDs with modified date.
	 */
	public function test_variation_api_get_variable_ids_with_date_modified_gmt(): void {
		$product       = ProductHelper::create_variation_product();
		$product2       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$this->assertEquals( 2, \count( $variation_ids ) );

		$request     = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id', 'date_modified_gmt' ) );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );
		$this->assertEquals( 2, \count( $ids ) );

		$this->assertEqualsCanonicalizing( array( $variation_ids[1], $variation_ids[0] ), $ids );

		// Verify that date_modified_gmt is present for all variations and correctly formatted.
		foreach ( $data as $d ) {
			$this->assertArrayHasKey( 'date_modified_gmt', $d, "The 'date_modified_gmt' field is missing for variation ID {$d['id']}." );
			$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|(\+\d{2}:\d{2}))?/', $d['date_modified_gmt'], "The 'date_modified_gmt' field for variation ID {$d['id']} is not correctly formatted." );
		}
	}

	/**
	 * Each variation needs a UUID.
	 */
	public function test_variation_response_contains_uuid_meta_data(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$request       = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0] );
		$response      = $this->server->dispatch( $request );

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
	 * Barcode.
	 */
	public function test_variation_get_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => 'foo',
				);
			}
		);

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$variation     = wc_get_product( $variation_ids[0] );
		$variation->update_meta_data( 'foo', 'bar' );
		$this->assertEquals( 'bar', $this->endpoint->wcpos_get_barcode( $variation ) );
	}

	public function test_variation_response_contains_sku_barcode(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();

		$request       = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0] );
		$response      = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertStringStartsWith( 'DUMMY SKU VARIABLE SMALL', $data['barcode'] );
	}

	public function test_variation_response_contains_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_some_field',
				);
			}
		);

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		update_post_meta( $variation_ids[0], '_some_field', 'some_string' );

		$request       = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0] );
		$response      = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( 'some_string', $data['barcode'] );
	}

	public function test_variation_update_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => 'barcode',
				);
			}
		);

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		update_post_meta( $variation_ids[0], '_sku', 'sku-12345' );

		$request       = $this->wp_rest_patch_request( '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0] );
		$request->set_body_params(
			array(
				'barcode' => 'foo-12345',
			)
		);
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( 'foo-12345', $data['barcode'] );
	}

	/**
	 * Orderby.
	 */
	public function test_variation_orderby_sku(): void {
		$product       = ProductHelper::create_variation_product();
		$request       = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_query_params(
			array(
				'orderby' => 'sku',
				'order' => 'asc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'sku' );

		$this->assertStringStartsWith( 'DUMMY SKU VARIABLE LARGE', $skus[0] );
		$this->assertStringStartsWith( 'DUMMY SKU VARIABLE SMALL', $skus[1] );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'sku',
				'order' => 'desc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'sku' );

		$this->assertStringStartsWith( 'DUMMY SKU VARIABLE SMALL', $skus[0] );
		$this->assertStringStartsWith( 'DUMMY SKU VARIABLE LARGE', $skus[1] );
	}

	public function test_variation_orderby_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
				);
			}
		);

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		update_post_meta( $variation_ids[0], '_barcode', 'alpha' );
		update_post_meta( $variation_ids[1], '_barcode', 'zeta' );

		$request       = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_query_params(
			array(
				'orderby' => 'barcode',
				'order' => 'asc',
			)
		);
		$response         = $this->server->dispatch( $request );
		$data             = $response->get_data();
		$barcodes         = wp_list_pluck( $data, 'barcode' );

		$this->assertEquals( $barcodes, array( 'alpha', 'zeta' ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'barcode',
				'order' => 'desc',
			)
		);
		$response         = $this->server->dispatch( $request );
		$data             = $response->get_data();
		$barcodes         = wp_list_pluck( $data, 'barcode' );

		$this->assertEquals( $barcodes, array( 'zeta', 'alpha' ) );
	}

	public function test_variation_orderby_stock_status(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		update_post_meta( $variation_ids[0], '_stock_status', 'instock' );
		update_post_meta( $variation_ids[1], '_stock_status', 'outofstock' );

		$request       = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_query_params(
			array(
				'orderby' => 'stock_status',
				'order' => 'asc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'stock_status' );

		$this->assertEquals( $skus, array( 'instock', 'outofstock' ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'stock_status',
				'order' => 'desc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'stock_status' );

		$this->assertEquals( $skus, array( 'outofstock', 'instock' ) );
	}

	public function test_variation_orderby_stock_quantity(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		update_post_meta( $variation_ids[0], '_stock', 1 );
		update_post_meta( $variation_ids[0], '_manage_stock', 'yes' );
		update_post_meta( $variation_ids[1], '_stock', 2 );
		update_post_meta( $variation_ids[1], '_manage_stock', 'yes' );

		$request       = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order' => 'asc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'stock_quantity' );

		$this->assertEquals( $skus, array( 1, 2 ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order' => 'desc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'stock_quantity' );

		$this->assertEquals( $skus, array( 2, 1 ) );
	}

	/**
	 * Decimal quantities.
	 */
	public function test_variation_decimal_stock_quantity_schema(): void {
		$schema = $this->endpoint->get_item_schema();
		$this->assertEquals( 'integer', $schema['properties']['stock_quantity']['type'] );

		$this->setup_decimal_quantity_tests();
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );

		$schema = $this->endpoint->get_item_schema();
		$this->assertEquals( 'string', $schema['properties']['stock_quantity']['type'] );
	}

	public function test_variation_response_with_decimal_quantities(): void {
		$this->setup_decimal_quantity_tests();
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$variation     = wc_get_product( $variation_ids[0] );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 1.5 );
		$variation->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0] );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( 1.5, $data['stock_quantity'] );
	}

	// @TODO - this works in the POS, but not in the tests, I have no idea why
	public function test_variation_update_decimal_quantities(): void {
		$this->setup_decimal_quantity_tests();
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$variation     = wc_get_product( $variation_ids[0] );
		$variation->set_manage_stock( true );
		$variation->save();

		$request  = $this->wp_rest_patch_request( '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0] );
		$request->set_body_params(
			array(
				'stock_quantity' => '3.85',
			)
		);
		$response = $this->server->dispatch( $request );
		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3.85, $data['stock_quantity'] );
	}

	public function test_variation_orderby_decimal_stock_quantity(): void {
		$this->setup_decimal_quantity_tests();
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		update_post_meta( $variation_ids[0], '_stock', '11.2' );
		update_post_meta( $variation_ids[0], '_manage_stock', 'yes' );
		update_post_meta( $variation_ids[1], '_stock', '3.5' );
		update_post_meta( $variation_ids[1], '_manage_stock', 'yes' );
		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order' => 'asc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'stock_quantity' );

		$this->assertEquals( $skus, array( 3.5, 11.2 ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order' => 'desc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'stock_quantity' );

		$this->assertEquals( $skus, array( 11.2, 3.5 ) );
	}

	/**
	 * Online Only products.
	 */
	public function test_online_only_product_variations(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'pos_only_products' => true,
				);
			}
		);

		// create a variable product with 4 variations
		$product       = ProductHelper::create_variation_product(); // has two variations
		$variation_3 = new \WC_Product_Variation();
		$variation_3->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => 'DUMMY SKU VARIABLE MEDIUM',
				'regular_price' => 10,
			)
		);
		$variation_3->set_attributes( array( 'pa_size' => 'medium' ) );
		$variation_3->save_meta_data();
		$variation_3->save();

		$variation_4 = new \WC_Product_Variation();
		$variation_4->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => 'DUMMY SKU VARIABLE EXTRA LARGE',
				'regular_price' => 15,
			)
		);
		$variation_4->set_attributes( array( 'pa_size' => 'x-large' ) );
		$variation_4->save_meta_data();
		$variation_4->save();

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $variation_4->get_id() ),
						),
						'online_only' => array(
							'ids' => array( $variation_3->get_id() ),
						),
					),
				),
			)
		);

		// test get all ids
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3, \count( $data ) );
		$ids         = wp_list_pluck( $data, 'id' );

		$this->assertNotContains( $variation_3->get_id(), $ids );
		$this->assertContains( $variation_4->get_id(), $ids );

		// test products response
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3, \count( $data ) );
		$ids         = wp_list_pluck( $data, 'id' );

		$this->assertNotContains( $variation_3->get_id(), $ids );
		$this->assertContains( $variation_4->get_id(), $ids );

		delete_option( 'woocommerce_pos_settings_visibility' );
	}

	// /**
	// * Variations should order by menu_order by default.
	// */
	// public function test_variation_orderby_menu_order(): void {
	// }

	/**
	 *
	 */
	public function test_variation_get_with_includes() {
		$product = ProductHelper::create_variation_product(); // has two variations
		$variation_ids = $product->get_children();

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_param( 'include', array( $variation_ids[0] ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $variation_ids[0], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_variation_get_with_excludes() {
		$product = ProductHelper::create_variation_product(); // has two variations
		$variation_ids = $product->get_children();

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_param( 'exclude', array( $variation_ids[0] ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $variation_ids[1], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_variation_on_sale_with_includes() {
		$product = ProductHelper::create_variation_product(); // has two variations
		$variation_ids = $product->get_children();

		// put variations on_sale
		$variation1 = new \WC_Product_Variation( $variation_ids[0] );
		$variation1->set_sale_price( 1 );
		$variation1->save();

		$variation2 = new \WC_Product_Variation( $variation_ids[1] );
		$variation2->set_sale_price( 1 );
		$variation2->save();

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$variation_ids = $product->get_children();
		$request->set_param( 'on_sale', true );
		$request->set_param( 'include', array( $variation_ids[0] ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $variation_ids[0], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_variation_on_sale_with_excludes() {
		$product = ProductHelper::create_variation_product(); // has two variations
		$variation_ids = $product->get_children();

		// put variations on_sale
		$variation1 = new \WC_Product_Variation( $variation_ids[0] );
		$variation1->set_sale_price( 1 );
		$variation1->save();

		$variation2 = new \WC_Product_Variation( $variation_ids[1] );
		$variation2->set_sale_price( 1 );
		$variation2->save();

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$variation_ids = $product->get_children();
		$request->set_param( 'on_sale', true );
		$request->set_param( 'exclude', array( $variation_ids[0] ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $variation_ids[1], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_all_variation_response_contains_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_some_field',
				);
			}
		);

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		update_post_meta( $variation_ids[0], '_some_field', 'some_string' );

		$request       = $this->wp_rest_get_request( '/wcpos/v1/products/variations' );
		$response      = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );

		$this->assertEquals( 'some_string', $data[1]['barcode'] );
	}

	/**
	 * Test search using the products/variations endpoint.
	 */
	public function test_all_variation_search(): void {
		// enable barcode and pos_only_products
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
					'pos_only_products' => true,
				);
			}
		);

		$random_sku1         = wp_generate_password( 8, false );
		$random_sku2         = wp_generate_password( 8, false );
		$random_barcode1     = wp_generate_password( 10, false );
		$random_barcode2     = wp_generate_password( 10, false );

		// create two variable products
		$product1 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids1 = $product1->get_children();
		update_post_meta( $variation_ids1[0], '_sku', $random_sku1 );
		update_post_meta( $variation_ids1[0], '_barcode', $random_barcode1 );
		update_post_meta( $variation_ids1[1], '_sku', $random_sku2 );
		update_post_meta( $variation_ids1[1], '_barcode', $random_barcode2 );

		$product2 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids2 = $product2->get_children();
		update_post_meta( $variation_ids2[0], '_sku', $random_sku1 );
		update_post_meta( $variation_ids2[0], '_barcode', $random_barcode1 );
		update_post_meta( $variation_ids2[1], '_sku', $random_sku2 );
		update_post_meta( $variation_ids2[1], '_barcode', $random_barcode2 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/variations' );

		// empty search
		$request->set_query_params( array( 'search' => '' ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 4, \count( $data ) );

		// search for sku1
		$request->set_query_params( array( 'search' => $random_sku1 ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );
		$this->assertEqualsCanonicalizing( array( $variation_ids1[0], $variation_ids2[0] ), wp_list_pluck( $data, 'id' ) );

		// search for sku2
		$request->set_query_params( array( 'search' => $random_sku2 ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );
		$this->assertEqualsCanonicalizing( array( $variation_ids1[1], $variation_ids2[1] ), wp_list_pluck( $data, 'id' ) );

		// search for barcode1
		$request->set_query_params( array( 'search' => $random_barcode1 ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );
		$this->assertEqualsCanonicalizing( array( $variation_ids1[0], $variation_ids2[0] ), wp_list_pluck( $data, 'id' ) );

		// search for barcode2
		$request->set_query_params( array( 'search' => $random_barcode2 ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );
		$this->assertEqualsCanonicalizing( array( $variation_ids1[1], $variation_ids2[1] ), wp_list_pluck( $data, 'id' ) );

		// make sure online_only variations are not returned
		update_post_meta( $variation_ids1[1], '_pos_visibility', 'online_only' );
		$request->set_query_params( array( 'search' => $random_barcode2 ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( array( $variation_ids2[1] ), wp_list_pluck( $data, 'id' ) );
	}

	/**
	 *
	 */
	public function test_all_variation_with_includes() {
		$product1 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids1 = $product1->get_children();
		$product2 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids2 = $product2->get_children();
		$product3 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids3 = $product3->get_children();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/variations' );
		$request->set_param( 'include', array( $variation_ids1[0], $variation_ids1[1] ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$ids         = wp_list_pluck( $data, 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );
		$this->assertEqualsCanonicalizing( array( $variation_ids1[0], $variation_ids1[1] ), $ids );
	}

	/**
	 *
	 */
	public function test_all_variation_with_excludes() {
		$product1 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids1 = $product1->get_children();
		$product2 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids2 = $product2->get_children();
		$product3 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids3 = $product3->get_children();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/variations' );
		$request->set_param( 'exclude', array( $variation_ids1[0], $variation_ids1[1] ) );
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$ids         = wp_list_pluck( $data, 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 4, \count( $data ) );
		$this->assertEqualsCanonicalizing( array( $variation_ids2[0], $variation_ids2[1], $variation_ids3[0], $variation_ids3[1] ), $ids );
	}

	/**
	 *
	 */
	public function test_all_variation_search_with_includes() {
		$product1 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids1 = $product1->get_children();
		$product2 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids2 = $product2->get_children();
		$product3 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids3 = $product3->get_children();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/variations' );
		$request->set_query_params(
			array(
				'include' => array( $variation_ids1[0], $variation_ids1[1] ),
				'search' => 'SMALL',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $variation_ids1[0], $data[0]['id'] );
	}

	/**
	 *
	 */
	public function test_all_variation_search_with_excludes() {
		$product1 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids1 = $product1->get_children();
		$product2 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids2 = $product2->get_children();
		$product3 = ProductHelper::create_variation_product(); // has two variations
		$variation_ids3 = $product3->get_children();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/variations' );
		$request->set_query_params(
			array(
				'exclude' => array( $variation_ids1[0], $variation_ids1[1] ),
				'search' => 'SMALL',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$ids         = wp_list_pluck( $data, 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );
		$this->assertEqualsCanonicalizing( array( $variation_ids2[0], $variation_ids3[0] ), $ids );
	}
}
