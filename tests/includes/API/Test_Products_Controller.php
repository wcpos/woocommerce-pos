<?php

namespace WCPOS\WooCommercePOS\API\V1;

/**
 * Simulate the legacy WooCommerce empty-decimal coercion for one compatibility test.
 *
 * @param mixed $number     Value to format.
 * @param mixed $dp         Decimal precision.
 * @param bool  $trim_zeros Whether to trim trailing zeros.
 *
 * @return string
 */
function wc_format_decimal( $number, $dp = false, $trim_zeros = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- test shim must shadow the WC function in the controller namespace.
	if ( ! empty( $GLOBALS['wcpos_test_legacy_empty_decimal'] ) && '' === $number && false !== $dp ) {
		return number_format( 0, (int) $dp, '.', '' );
	}

	return \wc_format_decimal( $number, $dp, $trim_zeros );
}

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\V1\Products_Controller;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Products_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Products_Controller();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Skip brand fallback assertions when the Woo REST parent owns brand handling.
	 */
	private function skip_if_native_brand_filter_supported(): void {
		$method = new \ReflectionMethod( Products_Controller::class, 'wcpos_parent_collection_supports_param' );
		$method->setAccessible( true );

		if ( $method->invoke( $this->endpoint, 'brand' ) ) {
			$this->markTestSkipped( 'WooCommerce REST supports brand natively; WCPOS brand fallback is not active.' );
		}
	}

	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );

		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	public function test_rest_base(): void {
		$rest_base = $this->get_reflected_property_value( 'rest_base' );

		$this->assertEquals( 'products', $rest_base );
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
	 *
	 * Note: Some fields are version-dependent:
	 * - 'brands' was added to the WC product REST API schema in WC 9.9.0 (PR #55945).
	 * - 'global_unique_id' was added in WC 9.4.0.
	 */
	public function get_expected_response_fields() {
		$fields = array(
			'id',
			'name',
			'slug',
			'permalink',
			'date_created',
			'date_created_gmt',
			'date_modified',
			'date_modified_gmt',
			'type',
			'status',
			'featured',
			'catalog_visibility',
			'description',
			'short_description',
			'sku',
			'price',
			'regular_price',
			'sale_price',
			'date_on_sale_from',
			'date_on_sale_from_gmt',
			'date_on_sale_to',
			'date_on_sale_to_gmt',
			'price_html',
			'on_sale',
			'purchasable',
			'total_sales',
			'virtual',
			'downloadable',
			'downloads',
			'download_limit',
			'download_expiry',
			'external_url',
			'button_text',
			'tax_status',
			'tax_class',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
			'backorders_allowed',
			'backordered',
			'low_stock_amount',
			'sold_individually',
			'weight',
			'dimensions',
			'shipping_required',
			'shipping_taxable',
			'shipping_class',
			'shipping_class_id',
			'reviews_allowed',
			'average_rating',
			'rating_count',
			'related_ids',
			'upsell_ids',
			'cross_sell_ids',
			'parent_id',
			'purchase_note',
			'categories',
			'tags',
			'images',
			'has_options',
			'attributes',
			'default_attributes',
			'variations',
			'grouped_products',
			'menu_order',
			'meta_data',
			'post_password',
			// Added by WCPOS.
			'barcode',
			// Added in WooCommerce 9.4.0
			'global_unique_id',
		);

		// 'brands' was added to the product REST API schema in WC 9.9.0.
		// See: https://github.com/woocommerce/woocommerce/pull/55945
		if ( version_compare( WC_VERSION, '9.9.0', '>=' ) ) {
			$fields[] = 'brands';
		}

		return $fields;
	}

	public function test_product_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$product  = ProductHelper::create_simple_product();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$response_fields = array_keys( $response->get_data() );
		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );
		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	public function test_product_api_schema(): void {
		$schema = $this->endpoint->get_item_schema();

		$this->assertArrayHasKey( 'barcode', $schema['properties'] );
	}

	public function test_product_api_get_all_ids(): void {
		$product1  = ProductHelper::create_simple_product();
		$product2  = ProductHelper::create_simple_product();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $product1->get_id(), $product2->get_id() ), $ids );
	}

	public function test_product_api_get_all_ids_accepts_scalar_fields_id(): void {
		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', 'id' );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $product1->get_id(), $product2->get_id() ), $ids );
		$this->assertEquals( array( 'id' ), array_keys( $data[0] ) );
	}

	public function test_product_api_get_all_id_with_date_modified_gmt(): void {
		$product1  = ProductHelper::create_simple_product();
		$product2  = ProductHelper::create_simple_product();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id', 'date_modified_gmt' ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $product1->get_id(), $product2->get_id() ), $ids );

		// Verify that date_modified_gmt is present for all products and correctly formatted.
		foreach ( $data as $d ) {
			$this->assertArrayHasKey( 'date_modified_gmt', $d, "The 'date_modified_gmt' field is missing for product ID {$d['id']}." );
			$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|(\+\d{2}:\d{2}))?/', $d['date_modified_gmt'], "The 'date_modified_gmt' field for product ID {$d['id']} is not correctly formatted." );
		}
	}

	/**
	 * Each product needs a UUID.
	 */
	public function test_product_response_contains_uuid_meta_data(): void {
		$product  = ProductHelper::create_simple_product();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

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
	public function test_product_get_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => 'foo',
				);
			}
		);

		$product  = ProductHelper::create_simple_product();
		$product->update_meta_data( 'foo', 'bar' );
		$this->assertEquals( 'bar', $this->endpoint->wcpos_get_barcode( $product ) );
	}

	public function test_product_response_contains_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_some_field',
				);
			}
		);

		$product  = ProductHelper::create_simple_product();
		$product->update_meta_data( '_some_field', 'some_string' );
		$product->save_meta_data();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( 'some_string', $data['barcode'] );
	}

	public function test_product_update_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => 'barcode',
				);
			}
		);

		$product  = ProductHelper::create_simple_product( array( 'sku' => 'sku-12345' ) );
		$request  = $this->wp_rest_patch_request( '/wcpos/v1/products/' . $product->get_id() );
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

	public function test_product_get_global_unique_id_as_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_global_unique_id',
				);
			}
		);

		$product  = ProductHelper::create_simple_product();
		$product->set_global_unique_id( '1234567890123' );
		$this->assertEquals( '1234567890123', $this->endpoint->wcpos_get_barcode( $product ) );
	}

	public function test_product_response_contains_global_unique_id_as_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_global_unique_id',
				);
			}
		);

		$product  = ProductHelper::create_simple_product();
		$product->set_global_unique_id( '1234567890' );
		$product->save();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( '1234567890', $data['barcode'] );
	}

	/**
	 * The default barcode field (no settings saved) is the WooCommerce GTIN field.
	 */
	public function test_product_response_default_barcode_field_is_global_unique_id(): void {
		$product  = ProductHelper::create_simple_product();
		$product->set_global_unique_id( '4006381333931' );
		$product->save();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( '4006381333931', $data['barcode'] );
	}

	public function test_product_update_global_unique_id_as_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_global_unique_id',
				);
			}
		);

		$product  = ProductHelper::create_simple_product( array( 'sku' => 'sku-12345' ) );
		$request  = $this->wp_rest_patch_request( '/wcpos/v1/products/' . $product->get_id() );
		$request->set_body_params(
			array(
				'barcode' => '12345',
			)
		);
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( '12345', $data['barcode'] );
	}

	/**
	 * Orderby.
	 *
	 * The sequence used to stop at `zeta`, because every product in the fixture had a
	 * SKU — which is why the sort-as-filter bug survived: with no meta-less row present,
	 * an INNER JOIN and a LEFT JOIN return the same list (#1779 follow-up).
	 */
	public function test_product_orderby_sku(): void {
		ProductHelper::create_simple_product( array( 'sku' => '987654321' ) );
		ProductHelper::create_simple_product( array( 'sku' => 'zeta' ) );
		ProductHelper::create_simple_product( array( 'sku' => '123456789' ) );
		ProductHelper::create_simple_product( array( 'sku' => 'alpha' ) );
		$this->wcpos_create_product_without_meta( '_sku' );

		$this->assertSame(
			array( '123456789', '987654321', 'alpha', 'zeta', '' ),
			$this->wcpos_orderby_values( 'sku', 'asc', 'sku' )
		);
		$this->assertSame(
			array( 'zeta', 'alpha', '987654321', '123456789', '' ),
			$this->wcpos_orderby_values( 'sku', 'desc', 'sku' )
		);
	}

	/**
	 * The barcode sort must serve products that carry NO barcode meta row at all.
	 *
	 * That is the shape a default store is in — the barcode field defaults to
	 * `_global_unique_id`, which most catalogues never populate — and it made this sort
	 * answer with an EMPTY page.
	 */
	public function test_product_orderby_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
				);
			}
		);

		$product1 = ProductHelper::create_simple_product();
		$product1->update_meta_data( '_barcode', 'alpha' );
		$product1->save_meta_data();

		$product2 = ProductHelper::create_simple_product();
		$product2->update_meta_data( '_barcode', 'zeta' );
		$product2->save_meta_data();

		// No `_barcode` meta row whatsoever — the row an INNER JOIN drops.
		ProductHelper::create_simple_product();

		$this->assertSame( array( 'alpha', 'zeta', '' ), $this->wcpos_orderby_values( 'barcode', 'asc', 'barcode' ) );
		$this->assertSame( array( 'zeta', 'alpha', '' ), $this->wcpos_orderby_values( 'barcode', 'desc', 'barcode' ) );
	}

	/**
	 * A sort must never change WHICH products come back — only their order.
	 *
	 * Asserted on BOTH lanes in one case, with literal routes. The claim is a parity claim
	 * — the two lanes must answer a category-filtered barcode sort with the same set — and
	 * a v1-only version of it would be a legacy pin that proves nothing about the lane the
	 * app actually calls (tests/lane-coverage/README.md).
	 */
	public function test_product_orderby_barcode_preserves_category_membership(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
				);
			}
		);

		$category = wp_insert_term( 'Gear', 'product_cat' );
		$this->assertIsArray( $category );
		$members = array();
		foreach ( array( 'b-alpha', null, 'b-mike' ) as $barcode ) {
			$product = ProductHelper::create_simple_product();
			$product->set_category_ids( array( (int) $category['term_id'] ) );
			$product->save();
			if ( null !== $barcode ) {
				$product->update_meta_data( '_barcode', $barcode );
				$product->save_meta_data();
			}
			$members[] = $product->get_id();
		}
		// A product OUTSIDE the category, so a filter that stopped filtering also fails.
		ProductHelper::create_simple_product();
		sort( $members );

		$unsorted = $this->wcpos_orderby_ids( array( 'category' => (string) $category['term_id'] ) );
		$sorted   = $this->wcpos_orderby_ids(
			array(
				'category' => (string) $category['term_id'],
				'orderby'  => 'barcode',
				'order'    => 'asc',
			)
		);
		// A SET comparison: which products come back is the claim, not their order.
		sort( $unsorted );
		sort( $sorted );

		$this->assertSame( $members, $unsorted );
		$this->assertSame( $members, $sorted );

		// The same claim on the lane the app actually calls.
		$v2_request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$v2_request->set_query_params(
			array(
				'category' => (string) $category['term_id'],
				'orderby'  => 'barcode',
				'order'    => 'asc',
				'per_page' => 100,
			)
		);
		$v2_response = $this->server->dispatch( $v2_request );

		$this->assertEquals( 200, $v2_response->get_status() );

		$v2_ids = wp_list_pluck( $v2_response->get_data(), 'id' );
		sort( $v2_ids );

		$this->assertSame( $members, $v2_ids );
	}

	/**
	 * Asserted on IDS, not the reported status: WooCommerce defaults a product with no
	 * `_stock_status` row to "instock" in the payload, so a value sequence could not tell
	 * the meta-less row from a real in-stock one. What this pins is that it is still
	 * SERVED, and served last.
	 */
	public function test_product_orderby_stock_status(): void {
		$instock    = ProductHelper::create_simple_product( array( 'stock_status' => 'instock' ) );
		$outofstock = ProductHelper::create_simple_product( array( 'stock_status' => 'outofstock' ) );
		$metaless   = $this->wcpos_create_product_without_meta( '_stock_status' );

		$this->assertSame(
			array( $instock->get_id(), $outofstock->get_id(), $metaless ),
			$this->wcpos_orderby_ids( array( 'orderby' => 'stock_status', 'order' => 'asc' ) )
		);
		$this->assertSame(
			array( $outofstock->get_id(), $instock->get_id(), $metaless ),
			$this->wcpos_orderby_ids( array( 'orderby' => 'stock_status', 'order' => 'desc' ) )
		);
	}

	public function test_product_orderby_stock_quantity(): void {
		$product1  = ProductHelper::create_simple_product(
			array(
				'stock_quantity' => 1,
				'manage_stock'   => true,
			)
		);
		$product2  = ProductHelper::create_simple_product(
			array(
				'stock_quantity' => 2,
				'manage_stock'   => true,
			)
		);
		$product3  = ProductHelper::create_simple_product(
			array(
				'stock_quantity' => null,
				'manage_stock'   => true,
			)
		);
		$product4  = ProductHelper::create_simple_product(
			array(
				'stock_quantity' => 0,
				'manage_stock'   => true,
			)
		);
		$product5  = ProductHelper::create_simple_product(
			array(
				'stock_quantity' => -1,
				'manage_stock'   => true,
			)
		);
		$product6  = ProductHelper::create_simple_product();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order'   => 'asc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$stock_qtys   = wp_list_pluck( $data, 'stock_quantity' );

		// Products without stock management (null) should come last when sorting
		$this->assertEquals( array( -1, 0, 1, 2, null, null ), $stock_qtys );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order'   => 'desc',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$stock_qtys   = wp_list_pluck( $data, 'stock_quantity' );

		// Products without stock management (null) should come last when sorting DESC
		$this->assertEquals( array( 2, 1, 0, -1, null, null ), $stock_qtys );
	}

	/**
	 * Filter products by a single Store API-style brand parameter.
	 */
	public function test_product_filter_by_brand(): void {
		$brand_a = wp_insert_term( 'Brand A', 'product_brand' );
		$brand_b = wp_insert_term( 'Brand B', 'product_brand' );
		$this->assertIsArray( $brand_a );
		$this->assertIsArray( $brand_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$product3 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $brand_a['term_id'] ), 'product_brand' );
		wp_set_object_terms( $product2->get_id(), array( (int) $brand_b['term_id'] ), 'product_brand' );
		wp_set_object_terms( $product3->get_id(), array( (int) $brand_a['term_id'], (int) $brand_b['term_id'] ), 'product_brand' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'brand' => (string) $brand_a['term_id'],
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$ids      = wp_list_pluck( $data, 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( array( $product1->get_id(), $product3->get_id() ), $ids );
	}

	/**
	 * Filter products by multiple custom WCPOS brand IDs.
	 */
	public function test_product_filter_by_multiple_brands(): void {
		$brand_a = wp_insert_term( 'Brand C', 'product_brand' );
		$brand_b = wp_insert_term( 'Brand D', 'product_brand' );
		$this->assertIsArray( $brand_a );
		$this->assertIsArray( $brand_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$product3 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $brand_a['term_id'] ), 'product_brand' );
		wp_set_object_terms( $product2->get_id(), array( (int) $brand_b['term_id'] ), 'product_brand' );
		wp_set_object_terms( $product3->get_id(), array(), 'product_brand' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'brand' => $brand_a['term_id'] . ',' . $brand_b['term_id'],
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$ids      = wp_list_pluck( $data, 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( array( $product1->get_id(), $product2->get_id() ), $ids );
	}

	/**
	 * Filter products that match all requested brand IDs with Store API-style operators.
	 */
	public function test_product_filter_by_brand_and_operator(): void {
		$this->skip_if_native_brand_filter_supported();

		$brand_a = wp_insert_term( 'Brand G', 'product_brand' );
		$brand_b = wp_insert_term( 'Brand H', 'product_brand' );
		$this->assertIsArray( $brand_a );
		$this->assertIsArray( $brand_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$product3 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $brand_a['term_id'] ), 'product_brand' );
		wp_set_object_terms( $product2->get_id(), array( (int) $brand_b['term_id'] ), 'product_brand' );
		wp_set_object_terms( $product3->get_id(), array( (int) $brand_a['term_id'], (int) $brand_b['term_id'] ), 'product_brand' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'brand'          => $brand_a['term_id'] . ',' . $brand_b['term_id'],
				'brand_operator' => 'and',
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$ids      = wp_list_pluck( $data, 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( $product3->get_id() ), $ids );
	}

	/**
	 * Filter products by excluding brand IDs with Store API-style operators.
	 */
	public function test_product_filter_by_brand_not_in_operator(): void {
		$this->skip_if_native_brand_filter_supported();

		$brand_a = wp_insert_term( 'Brand E', 'product_brand' );
		$brand_b = wp_insert_term( 'Brand F', 'product_brand' );
		$this->assertIsArray( $brand_a );
		$this->assertIsArray( $brand_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $brand_a['term_id'] ), 'product_brand' );
		wp_set_object_terms( $product2->get_id(), array( (int) $brand_b['term_id'] ), 'product_brand' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'brand'          => (string) $brand_a['term_id'],
				'brand_operator' => 'not_in',
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$ids      = wp_list_pluck( $data, 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertContains( $product2->get_id(), $ids );
		$this->assertNotContains( $product1->get_id(), $ids );
	}

	/**
	 * Plain category filters without operator params keep Woo REST IN semantics.
	 */
	public function test_product_filter_by_category_plain_and_explicit_in_return_same_products(): void {
		$category_a = wp_insert_term( 'Category A', 'product_cat' );
		$category_b = wp_insert_term( 'Category B', 'product_cat' );
		$this->assertIsArray( $category_a );
		$this->assertIsArray( $category_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$product3 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $category_a['term_id'] ), 'product_cat' );
		wp_set_object_terms( $product2->get_id(), array( (int) $category_b['term_id'] ), 'product_cat' );
		wp_set_object_terms( $product3->get_id(), array( (int) $category_a['term_id'], (int) $category_b['term_id'] ), 'product_cat' );

		$category_ids = $category_a['term_id'] . ',' . $category_b['term_id'];
		$expected_ids = array( $product1->get_id(), $product2->get_id(), $product3->get_id() );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params( array( 'category' => $category_ids ) );
		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( $expected_ids, $ids );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'category'          => $category_ids,
				'category_operator' => 'in',
			)
		);
		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( $expected_ids, $ids );
	}

	/**
	 * Store API-style AND category operator requires all requested categories.
	 */
	public function test_product_filter_by_category_and_operator_returns_products_in_all_categories(): void {
		$category_a = wp_insert_term( 'Category C', 'product_cat' );
		$category_b = wp_insert_term( 'Category D', 'product_cat' );
		$this->assertIsArray( $category_a );
		$this->assertIsArray( $category_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$product3 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $category_a['term_id'] ), 'product_cat' );
		wp_set_object_terms( $product2->get_id(), array( (int) $category_b['term_id'] ), 'product_cat' );
		wp_set_object_terms( $product3->get_id(), array( (int) $category_a['term_id'], (int) $category_b['term_id'] ), 'product_cat' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'category'          => $category_a['term_id'] . ',' . $category_b['term_id'],
				'category_operator' => 'and',
			)
		);

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( $product3->get_id() ), $ids );
	}

	/**
	 * Store API-style NOT IN category operator excludes products assigned to the requested category.
	 */
	public function test_product_filter_by_category_not_in_operator_excludes_matching_products(): void {
		$category_a = wp_insert_term( 'Category E', 'product_cat' );
		$category_b = wp_insert_term( 'Category F', 'product_cat' );
		$this->assertIsArray( $category_a );
		$this->assertIsArray( $category_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $category_a['term_id'] ), 'product_cat' );
		wp_set_object_terms( $product2->get_id(), array( (int) $category_b['term_id'] ), 'product_cat' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'category'          => (string) $category_a['term_id'],
				'category_operator' => 'not_in',
			)
		);

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertContains( $product2->get_id(), $ids );
		$this->assertNotContains( $product1->get_id(), $ids );
	}

	/**
	 * Plain tag filters without operator params keep Woo REST IN semantics.
	 */
	public function test_product_filter_by_tag_plain_and_explicit_in_return_same_products(): void {
		$tag_a = wp_insert_term( 'Tag A', 'product_tag' );
		$tag_b = wp_insert_term( 'Tag B', 'product_tag' );
		$this->assertIsArray( $tag_a );
		$this->assertIsArray( $tag_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$product3 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $tag_a['term_id'] ), 'product_tag' );
		wp_set_object_terms( $product2->get_id(), array( (int) $tag_b['term_id'] ), 'product_tag' );
		wp_set_object_terms( $product3->get_id(), array( (int) $tag_a['term_id'], (int) $tag_b['term_id'] ), 'product_tag' );

		$tag_ids      = $tag_a['term_id'] . ',' . $tag_b['term_id'];
		$expected_ids = array( $product1->get_id(), $product2->get_id(), $product3->get_id() );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params( array( 'tag' => $tag_ids ) );
		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( $expected_ids, $ids );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'tag'          => $tag_ids,
				'tag_operator' => 'in',
			)
		);
		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( $expected_ids, $ids );
	}

	/**
	 * Store API-style AND tag operator requires all requested tags.
	 */
	public function test_product_filter_by_tag_and_operator_returns_products_with_all_tags(): void {
		$tag_a = wp_insert_term( 'Tag C', 'product_tag' );
		$tag_b = wp_insert_term( 'Tag D', 'product_tag' );
		$this->assertIsArray( $tag_a );
		$this->assertIsArray( $tag_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$product3 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $tag_a['term_id'] ), 'product_tag' );
		wp_set_object_terms( $product2->get_id(), array( (int) $tag_b['term_id'] ), 'product_tag' );
		wp_set_object_terms( $product3->get_id(), array( (int) $tag_a['term_id'], (int) $tag_b['term_id'] ), 'product_tag' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'tag'          => $tag_a['term_id'] . ',' . $tag_b['term_id'],
				'tag_operator' => 'and',
			)
		);

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( $product3->get_id() ), $ids );
	}

	/**
	 * Store API-style NOT IN tag operator excludes products assigned to the requested tag.
	 */
	public function test_product_filter_by_tag_not_in_operator_excludes_matching_products(): void {
		$tag_a = wp_insert_term( 'Tag E', 'product_tag' );
		$tag_b = wp_insert_term( 'Tag F', 'product_tag' );
		$this->assertIsArray( $tag_a );
		$this->assertIsArray( $tag_b );

		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();

		wp_set_object_terms( $product1->get_id(), array( (int) $tag_a['term_id'] ), 'product_tag' );
		wp_set_object_terms( $product2->get_id(), array( (int) $tag_b['term_id'] ), 'product_tag' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'tag'          => (string) $tag_a['term_id'],
				'tag_operator' => 'not_in',
			)
		);

		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertContains( $product2->get_id(), $ids );
		$this->assertNotContains( $product1->get_id(), $ids );
	}

	/**
	 * Operator params without matching terms are no-ops.
	 */
	public function test_product_filter_by_tax_operator_without_terms_does_not_add_tax_query(): void {
		$product = ProductHelper::create_simple_product();

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params( array( 'category_operator' => 'and' ) );
		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertContains( $product->get_id(), $ids );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params( array( 'tag_operator' => 'not_in' ) );
		$response = $this->server->dispatch( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertContains( $product->get_id(), $ids );
	}

	/**
	 * Store API taxonomy operator helper maps supported and unknown values.
	 */
	public function test_product_store_api_tax_operator_mapping(): void {
		$method = new \ReflectionMethod( Products_Controller::class, 'wcpos_get_store_api_tax_operator' );
		$method->setAccessible( true );

		$this->assertEquals( 'IN', $method->invoke( $this->endpoint, 'in' ) );
		$this->assertEquals( 'AND', $method->invoke( $this->endpoint, 'and' ) );
		$this->assertEquals( 'NOT IN', $method->invoke( $this->endpoint, 'not_in' ) );
		$this->assertEquals( 'IN', $method->invoke( $this->endpoint, 'unknown' ) );
		$this->assertEquals( 'IN', $method->invoke( $this->endpoint, null ) );
	}

	/**
	 * Native parent support disables WCPOS taxonomy operator fallback mutations per param.
	 */
	public function test_product_tax_operator_fallback_skips_when_parent_supports_param(): void {
		$endpoint = new class() extends Products_Controller {
			protected function wcpos_parent_collection_supports_param( string $param ): bool {
				return 'category_operator' === $param;
			}
		};
		$method = new \ReflectionMethod( $endpoint, 'wcpos_apply_store_api_tax_operator_fallbacks' );
		$method->setAccessible( true );

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'category'          => '1',
				'category_operator' => 'not_in',
			)
		);
		$args = array(
			'tax_query' => array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array( 1 ),
					'operator' => 'IN',
				),
			),
		);

		$result = $method->invoke( $endpoint, $args, $request );

		$this->assertEquals( 'IN', $result['tax_query'][0]['operator'] );
	}

	/**
	 * Decimal quantities.
	 */
	public function test_product_decimal_stock_quantity_schema(): void {
		$schema = $this->endpoint->get_item_schema();
		$this->assertEquals( 'integer', $schema['properties']['stock_quantity']['type'] );

		$this->setup_decimal_quantity_tests();
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );

		$schema = $this->endpoint->get_item_schema();
		$this->assertEquals( 'number', $schema['properties']['stock_quantity']['type'] );
	}

	public function test_product_response_with_decimal_quantities(): void {
		$this->setup_decimal_quantity_tests();
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );

		$product  = ProductHelper::create_simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 1.5 );
		$product->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( 1.5, $data['stock_quantity'] );
	}

	public function test_product_orderby_decimal_stock_quantity(): void {
		$this->setup_decimal_quantity_tests();
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );

		$product1  = ProductHelper::create_simple_product(
			array(
				'stock_quantity' => '11.2',
				'manage_stock'   => true,
			)
		);
		$product2  = ProductHelper::create_simple_product(
			array(
				'stock_quantity' => '3.5',
				'manage_stock'   => true,
			)
		);
		$product3  = ProductHelper::create_simple_product(
			array(
				'stock_quantity' => '20.7',
				'manage_stock'   => true,
			)
		);
		// Not stock-managed: `_stock` is NULL, and it must still be served, last.
		ProductHelper::create_simple_product();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order'   => 'asc',
			)
		);
		$response   = $this->server->dispatch( $request );
		$data       = $response->get_data();
		$quantities = wp_list_pluck( $data, 'stock_quantity' );

		$this->assertEquals( array( 3.5, 11.2, 20.7, null ), $quantities );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'stock_quantity',
				'order'   => 'desc',
			)
		);
		$response   = $this->server->dispatch( $request );
		$data       = $response->get_data();
		$quantities = wp_list_pluck( $data, 'stock_quantity' );

		$this->assertEquals( array( 20.7, 11.2, 3.5, null ), $quantities );
	}

	/**
	 * A product whose postmeta row for `$meta_key` is absent entirely.
	 *
	 * WooCommerce always writes these keys, so the row has to be removed after the fact
	 * to reproduce the catalogue shape an importer leaves behind.
	 *
	 * @param string $meta_key The meta key to strip.
	 *
	 * @return int The product id.
	 */
	private function wcpos_create_product_without_meta( string $meta_key ): int {
		$product = ProductHelper::create_simple_product();
		delete_post_meta( $product->get_id(), $meta_key );
		wp_cache_flush();

		return $product->get_id();
	}

	/**
	 * Dispatch a wcpos/v1 product collection read and return the rows.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return array
	 */
	private function wcpos_read_products( array $params ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_query_params( $params );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * One field of every row of a sorted read, in served order.
	 *
	 * @param string $orderby Sort key.
	 * @param string $order   Sort direction.
	 * @param string $field   Payload field to pluck.
	 *
	 * @return array
	 */
	private function wcpos_orderby_values( string $orderby, string $order, string $field ): array {
		return wp_list_pluck(
			$this->wcpos_read_products(
				array(
					'orderby' => $orderby,
					'order'   => $order,
				)
			),
			$field
		);
	}

	/**
	 * The ids a read serves, in served order.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return array<int, int>
	 */
	private function wcpos_orderby_ids( array $params ): array {
		return wp_list_pluck( $this->wcpos_read_products( $params ), 'id' );
	}

	public function test_product_search(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
				);
			}
		);

		$random_title       = wp_generate_password( 12, false );
		$random_sku         = wp_generate_password( 8, false );
		$random_barcode     = wp_generate_password( 10, false );
		$random_description = 'A string containing ' . $random_title . ' and ' . $random_sku . ' and ' . $random_barcode;

		$product1  = ProductHelper::create_simple_product( array( 'description' => $random_description ) );
		$product2  = ProductHelper::create_simple_product(
			array(
				'description' => $random_description,
				'name'        => 'Foo ' . $random_title . ' bar',
			)
		);
		$product3  = ProductHelper::create_simple_product(
			array(
				'description' => $random_description,
				'sku'         => 'foo-' . $random_sku . '-bar',
			)
		);
		$product4  = ProductHelper::create_simple_product( array( 'description' => $random_description ) );
		$product4->update_meta_data( '_barcode', 'foo-' . $random_barcode . '-bar' );
		$product4->save_meta_data();

		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );

		// empty search
		$request->set_query_params( array( 'search' => '' ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 4, \count( $data ) );

		// search for title
		$request->set_query_params( array( 'search' => $random_title ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $product2->get_id(), $data[0]['id'] );

		// search for sku
		$request->set_query_params( array( 'search' => $random_sku ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $product3->get_id(), $data[0]['id'] );

		// search for barcode
		$request->set_query_params( array( 'search' => $random_barcode ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $product4->get_id(), $data[0]['id'] );
	}

	/**
	 * Online Only products.
	 */
	public function test_online_only_products(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'pos_only_products' => true,
				);
			}
		);

		$product1    = ProductHelper::create_simple_product();
		$product2    = ProductHelper::create_simple_product();
		$product3    = ProductHelper::create_simple_product();
		$product4    = ProductHelper::create_simple_product();

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $product3->get_id() ),
						),
						'online_only' => array(
							'ids' => array( $product1->get_id() ),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
			)
		);

		// test get all ids
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3, \count( $data ) );
		$ids         = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $product2->get_id(), $product3->get_id(), $product4->get_id() ), $ids );

		// test products response
		$request      = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 3, \count( $data ) );
		$ids         = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $product2->get_id(), $product3->get_id(), $product4->get_id() ), $ids );

		delete_option( 'woocommerce_pos_settings_visibility' );
	}

	public function test_search_title_with_includes(): void {
		$product1  = ProductHelper::create_simple_product();
		$product2  = ProductHelper::create_simple_product();

		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'search', 'dummy' );
		$request->set_param( 'include', array( $product1->get_id() ) );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids         = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $product1->get_id() ), $ids );
	}

	public function test_search_sku_with_includes(): void {
		$product1  = ProductHelper::create_simple_product();
		$product2  = ProductHelper::create_simple_product();

		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'search', 'DUMMY SKU' );
		$request->set_param( 'include', array( $product2->get_id() ) );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids         = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $product2->get_id() ), $ids );
	}

	public function test_filter_on_sale_with_includes(): void {
		$product1  = ProductHelper::create_simple_product(
			array(
				'sale_price' => 8,
				'on_sale'    => true,
			)
		);
		$product2  = ProductHelper::create_simple_product();
		$product3  = ProductHelper::create_simple_product(
			array(
				'sale_price' => 6,
				'on_sale'    => true,
			)
		);

		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'on_sale', true );
		$request->set_param( 'include', array( $product1->get_id() ) );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids         = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $product1->get_id() ), $ids );
	}

	public function test_search_title_with_excludes(): void {
		$product1  = ProductHelper::create_simple_product();
		$product2  = ProductHelper::create_simple_product();

		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'search', 'dummy' );
		$request->set_param( 'exclude', array( $product1->get_id() ) );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids         = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $product2->get_id() ), $ids );
	}

	public function test_search_sku_with_excludes(): void {
		$product1  = ProductHelper::create_simple_product();
		$product2  = ProductHelper::create_simple_product();

		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'search', 'DUMMY SKU' );
		$request->set_param( 'exclude', array( $product2->get_id() ) );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids         = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $product1->get_id() ), $ids );
	}

	public function test_filter_on_sale_with_excludes(): void {
		$product1  = ProductHelper::create_simple_product(
			array(
				'sale_price' => 8,
				'on_sale'    => true,
			)
		);
		$product2  = ProductHelper::create_simple_product();
		$product3  = ProductHelper::create_simple_product(
			array(
				'sale_price' => 6,
				'on_sale'    => true,
			)
		);

		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'on_sale', true );
		$request->set_param( 'exclude', array( $product1->get_id() ) );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );

		$ids         = wp_list_pluck( $data, 'id' );
		$this->assertEquals( array( $product3->get_id() ), $ids );
	}

	/**
	 * Variable product sale_price metadata should exclude variations without a sale price.
	 *
	 * When a variation has no sale_price, WC's get_variation_sale_price() returns '' which
	 * WC internally treats as 0, producing a $0.00 min sale price. The metadata should only
	 * include sale prices from variations that are actually on sale.
	 */
	public function test_variable_product_sale_price_excludes_empty(): void {
		$product = new \WC_Product_Variable();
		$product->set_props(
			array(
				'name' => 'Variable Sale Price Test',
				'sku'  => uniqid( 'VARIABLE SALE TEST' ),
			)
		);

		$attribute_data = ProductHelper::create_attribute( 'size', array( 'small', 'medium', 'large' ) );
		$attribute      = new \WC_Product_Attribute();
		$attribute->set_id( $attribute_data['attribute_id'] );
		$attribute->set_name( $attribute_data['attribute_taxonomy'] );
		$attribute->set_options( $attribute_data['term_ids'] );
		$attribute->set_position( 1 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		// Variation 1: on sale (regular=20, sale=15, effective price=15).
		$variation_1 = new \WC_Product_Variation();
		$variation_1->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => uniqid( 'VAR SMALL' ),
				'regular_price' => '20',
				'sale_price'    => '15',
			)
		);
		$variation_1->set_attributes( array( 'pa_size' => 'small' ) );
		$variation_1->save();

		// Variation 2: NOT on sale (regular=25, no sale_price, effective price=25).
		$variation_2 = new \WC_Product_Variation();
		$variation_2->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => uniqid( 'VAR MEDIUM' ),
				'regular_price' => '25',
			)
		);
		$variation_2->set_attributes( array( 'pa_size' => 'medium' ) );
		$variation_2->save();

		// Variation 3: on sale (regular=30, sale=22, effective price=22).
		$variation_3 = new \WC_Product_Variation();
		$variation_3->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => uniqid( 'VAR LARGE' ),
				'regular_price' => '30',
				'sale_price'    => '22',
			)
		);
		$variation_3->set_attributes( array( 'pa_size' => 'large' ) );
		$variation_3->save();

		// Clear WC's cached price transients.
		wc_delete_product_transients( $product->get_id() );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		// Find the _woocommerce_pos_variable_prices meta.
		$variable_prices = null;
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_variable_prices' === $meta['key'] ) {
				$variable_prices = json_decode( $meta['value'], true );
				break;
			}
		}

		$this->assertNotNull( $variable_prices, 'Variable prices metadata should be present.' );

		// price range: min=15 (variation 1 sale), max=25 (variation 2 regular, not on sale).
		$this->assertEquals( '15.00', $variable_prices['price']['min'] );
		$this->assertEquals( '25.00', $variable_prices['price']['max'] );

		// regular_price range: min=20, max=30.
		$this->assertEquals( '20.00', $variable_prices['regular_price']['min'] );
		$this->assertEquals( '30.00', $variable_prices['regular_price']['max'] );

		// sale_price range: only variations WITH a sale price (15 and 22), NOT $0.00.
		$this->assertEquals( '15.00', $variable_prices['sale_price']['min'] );
		$this->assertEquals( '22.00', $variable_prices['sale_price']['max'] );
	}

	/**
	 * Variable product price metadata should be recalculated from current variation meta.
	 *
	 * Regression test for wcpos/monorepo#515. If WooCommerce's variable-product price
	 * transient is stale after a variation price update, the POS response must not keep
	 * returning the old parent/listing price.
	 */
	public function test_variable_product_price_metadata_ignores_stale_parent_price_cache(): void {
		$product = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();

		foreach ( $variation_ids as $variation_id ) {
			update_post_meta( $variation_id, '_regular_price', '79' );
			update_post_meta( $variation_id, '_sale_price', '' );
			update_post_meta( $variation_id, '_price', '79' );
		}
		wc_delete_product_transients( $product->get_id() );

		// Prime WooCommerce's variable price cache with the old parent/listing price.
		$product->get_variation_prices();

		// Simulate variation price edits that update child price meta, while the
		// parent variable-product price cache remains stale.
		foreach ( $variation_ids as $variation_id ) {
			update_post_meta( $variation_id, '_regular_price', '69.95' );
			update_post_meta( $variation_id, '_sale_price', '65' );
			update_post_meta( $variation_id, '_price', '65' );
		}

		$request = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$variable_prices = null;
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_variable_prices' === $meta['key'] ) {
				$variable_prices = json_decode( $meta['value'], true );
				break;
			}
		}

		$this->assertNotNull( $variable_prices, 'Variable prices metadata should be present.' );
		$this->assertEquals( '65.00', $variable_prices['price']['min'] );
		$this->assertEquals( '65.00', $variable_prices['price']['max'] );
		$this->assertEquals( '69.95', $variable_prices['regular_price']['min'] );
		$this->assertEquals( '69.95', $variable_prices['regular_price']['max'] );
		$this->assertEquals( '65.00', $variable_prices['sale_price']['min'] );
		$this->assertEquals( '65.00', $variable_prices['sale_price']['max'] );
	}

	/**
	 * A stale persisted _woocommerce_pos_variable_prices meta row must not be served —
	 * the response must reflect the range recomputed from current variation prices.
	 */
	public function test_variable_product_price_metadata_uses_recomputed_in_memory_value(): void {
		$product = ProductHelper::create_variation_product();
		$product->update_meta_data(
			'_woocommerce_pos_variable_prices',
			wp_json_encode(
				array(
					'price'         => array(
						'min' => '99',
						'max' => '99',
					),
					'regular_price' => array(
						'min' => '99',
						'max' => '99',
					),
					'sale_price'    => array(
						'min' => '99',
						'max' => '99',
					),
				)
			)
		);
		$product->save();

		$variation = new \WC_Product_Variation( $product->get_children()[0] );
		$variation->set_regular_price( '12' );
		$variation->set_price( '12' );
		$variation->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$variable_prices = null;
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_variable_prices' === $meta['key'] ) {
				$variable_prices = json_decode( $meta['value'], true );
				break;
			}
		}

		$this->assertNotNull( $variable_prices, 'Variable prices metadata should be present.' );
		$this->assertSame( '12.00', $variable_prices['price']['min'] );
		$this->assertSame( '15.00', $variable_prices['price']['max'] );
	}

	/**
	 * A variable product converted from a simple product must not expose its old simple price.
	 */
	public function test_variable_product_converted_from_simple_uses_current_variation_price(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => '102',
				'price'         => '102',
			)
		);

		$attribute_data = ProductHelper::create_attribute( 'size', array( 'large' ) );
		$attribute      = new \WC_Product_Attribute();
		$attribute->set_id( $attribute_data['attribute_id'] );
		$attribute->set_name( $attribute_data['attribute_taxonomy'] );
		$attribute->set_options( $attribute_data['term_ids'] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$variable_product = new \WC_Product_Variable( $product->get_id() );
		$variable_product->set_attributes( array( $attribute ) );
		$variable_product->save();

		$variation = new \WC_Product_Variation();
		$variation->set_props(
			array(
				'parent_id'     => $variable_product->get_id(),
				'regular_price' => '114.234',
			)
		);
		$variation->set_attributes( array( 'pa_size' => 'large' ) );
		$variation->save();

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'include', array( $variable_product->get_id() ) );
		$request->set_param( 'dp', 3 );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data()[0];

		$this->assertEquals( 200, $response->get_status() );

		$variable_prices = null;
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_variable_prices' === $meta['key'] ) {
				$variable_prices = json_decode( $meta['value'], true );
				break;
			}
		}

		$this->assertNotNull( $variable_prices, 'Variable prices metadata should be present.' );
		$this->assertEquals( '114.23', $variable_prices['price']['min'] );
		$this->assertEquals( '114.234', $data['price'] );
		$this->assertSame( '', $data['regular_price'] );
		$this->assertSame( '', $data['sale_price'] );
	}

	/**
	 * Parent fields must not combine prices from different variations into a false sale.
	 */
	public function test_variable_product_parent_fields_do_not_pair_independent_price_minima(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();

		$regular_variation = new \WC_Product_Variation( $variation_ids[0] );
		$regular_variation->set_regular_price( '10' );
		$regular_variation->set_sale_price( '' );
		$regular_variation->save();

		$sale_variation = new \WC_Product_Variation( $variation_ids[1] );
		$sale_variation->set_regular_price( '20' );
		$sale_variation->set_sale_price( '15' );
		$sale_variation->save();

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'include', array( $product->get_id() ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data()[0];

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( '10.00', $data['price'] );
		$this->assertSame( '', $data['regular_price'] );
		$this->assertSame( '', $data['sale_price'] );
	}

	/**
	 * Unpriced variable products stay empty on WooCommerce versions that coerce empty decimals to zero.
	 */
	public function test_variable_product_without_prices_does_not_expose_zero_parent_price(): void {
		$product = ProductHelper::create_variation_product();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = new \WC_Product_Variation( $variation_id );
			$variation->set_regular_price( '' );
			$variation->set_sale_price( '' );
			$variation->set_price( '' );
			$variation->save();
		}

		$GLOBALS['wcpos_test_legacy_empty_decimal'] = true;
		try {
			$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
			$request->set_param( 'include', array( $product->get_id() ) );
			$response = $this->server->dispatch( $request );
		} finally {
			unset( $GLOBALS['wcpos_test_legacy_empty_decimal'] );
		}
		$data = $response->get_data()[0];

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( '', $data['price'] );
	}

	/**
	 * Online-only variations must not contribute to the parent price served to the POS.
	 */
	public function test_variable_product_parent_price_excludes_online_only_variations(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			static function () {
				return array( 'pos_only_products' => true );
			}
		);

		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$hidden        = new \WC_Product_Variation( $variation_ids[0] );
		$visible       = new \WC_Product_Variation( $variation_ids[1] );
		$hidden->set_regular_price( '9.50' );
		$hidden->save();
		$visible->set_regular_price( '25.00' );
		$visible->save();

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'variations' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $hidden->get_id() ) ),
					),
				),
			)
		);

		$request = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'include', array( $product->get_id() ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data()[0];

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( '25.00', $data['price'] );
	}

	/**
	 * When no variations have sale prices, the sale_price min/max should be empty strings, not 0.
	 */
	public function test_variable_product_no_sale_prices(): void {
		$product = ProductHelper::create_variation_product();

		// Clear WC's cached price transients.
		wc_delete_product_transients( $product->get_id() );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		// Find the _woocommerce_pos_variable_prices meta.
		$variable_prices = null;
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_variable_prices' === $meta['key'] ) {
				$variable_prices = json_decode( $meta['value'], true );
				break;
			}
		}

		$this->assertNotNull( $variable_prices, 'Variable prices metadata should be present.' );

		// sale_price min and max should be empty strings (not 0) when no variations are on sale.
		$this->assertSame( '', $variable_prices['sale_price']['min'], 'sale_price min should be empty string when no variations are on sale.' );
		$this->assertSame( '', $variable_prices['sale_price']['max'], 'sale_price max should be empty string when no variations are on sale.' );
	}

	/**
	 * CHARACTERIZATION: the persisted variable-price metadata is byte-identical.
	 *
	 * The V1 lane stores a JSON string in postmeta, so key ORDER and decimal
	 * formatting are part of the contract, not just the values. This pins the
	 * exact bytes so the shared range service cannot quietly reshape them.
	 */
	public function test_variable_product_price_metadata_json_shape_is_stable(): void {
		// Arrange.
		$product = new \WC_Product_Variable();
		$product->set_props(
			array(
				'name' => 'Variable Shape Test',
				'sku'  => uniqid( 'VARIABLE SHAPE' ),
			)
		);
		$attribute_data = ProductHelper::create_attribute( 'shape', array( 'small', 'large' ) );
		$attribute      = new \WC_Product_Attribute();
		$attribute->set_id( $attribute_data['attribute_id'] );
		$attribute->set_name( $attribute_data['attribute_taxonomy'] );
		$attribute->set_options( $attribute_data['term_ids'] );
		$attribute->set_position( 1 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$variation_1 = new \WC_Product_Variation();
		$variation_1->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => uniqid( 'SHAPE SMALL' ),
				'regular_price' => '20',
				'sale_price'    => '15',
			)
		);
		$variation_1->set_attributes( array( 'pa_shape' => 'small' ) );
		$variation_1->save();

		$variation_2 = new \WC_Product_Variation();
		$variation_2->set_props(
			array(
				'parent_id'     => $product->get_id(),
				'sku'           => uniqid( 'SHAPE LARGE' ),
				'regular_price' => '30',
			)
		);
		$variation_2->set_attributes( array( 'pa_shape' => 'large' ) );
		$variation_2->save();
		wc_delete_product_transients( $product->get_id() );

		// Act.
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products/' . $product->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$encoded  = null;
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_variable_prices' === $meta['key'] ) {
				$encoded = $meta['value'];
				break;
			}
		}

		// Assert.
		$this->assertEquals(
			'{"price":{"min":"15.00","max":"30.00"},"regular_price":{"min":"20.00","max":"30.00"},"sale_price":{"min":"15.00","max":"15.00"}}',
			$encoded
		);
	}

	public function test_uuid_is_unique(): void {
		$uuid      = Uuid::uuid4()->toString();
		$product1  = ProductHelper::create_simple_product();
		$product1->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$product1->save_meta_data();
		$product2  = ProductHelper::create_simple_product();
		$product2->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$product2->save_meta_data();

		$request   = $this->wp_rest_get_request( '/wcpos/v1/products' );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );

		// pluck uuids from meta_data
		$uuids = array();
		foreach ( $data as $product ) {
			foreach ( $product['meta_data'] as $meta ) {
				if ( '_woocommerce_pos_uuid' === $meta['key'] ) {
					$uuids[] = $meta['value'];
				}
			}
		}

		$this->assertEquals( 2, \count( $uuids ) );
		$this->assertContains( $uuid, $uuids );
		$this->assertEquals( 2, \count( array_unique( $uuids ) ) );
	}

	public function test_product_api_get_all_ids_with_include_filter(): void {
		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$request->set_param( 'include', array( $product1->get_id() ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( array( $product1->get_id() ), $ids );
		$this->assertNotContains( $product2->get_id(), $ids );
	}

	public function test_product_api_get_all_ids_with_exclude_filter(): void {
		$product1 = ProductHelper::create_simple_product();
		$product2 = ProductHelper::create_simple_product();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$request->set_param( 'exclude', array( $product1->get_id() ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertNotContains( $product1->get_id(), $ids );
		$this->assertContains( $product2->get_id(), $ids );
	}

	/**
	 * WC's batch_items() calls create_item() directly, bypassing per-item
	 * schema validation, so malformed meta_data entries must be dropped before
	 * WC core's unguarded $meta['key'] access fatals mid-batch on PHP 8.
	 *
	 * LEGACY v1 PIN (lane audit 2026-08-10): this tolerance is v1-batch-only and is
	 * deliberately NOT ported to the v2 push lane, which forwards one mutation per
	 * request and lets wc/v3 reject a malformed payload. See
	 * WCPOS_REST_API::wcpos_sanitize_meta_data_param() for the ruling.
	 */
	public function test_batch_create_product_with_string_meta_data_entry_creates_product(): void {
		// Arrange.
		$request = $this->wp_rest_post_request( '/wcpos/v1/products/batch' );
		$request->set_body_params(
			array(
				'create' => array(
					array(
						'name'          => 'Batch Meta Product',
						'type'          => 'simple',
						'regular_price' => '10',
						'meta_data'     => array( 'not-an-object' ),
					),
				),
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'create', $data );
		$this->assertArrayNotHasKey( 'error', $data['create'][0] );
		$this->assertGreaterThan( 0, $data['create'][0]['id'] );
		$this->assertEquals( 'Batch Meta Product', $data['create'][0]['name'] );
	}
}
