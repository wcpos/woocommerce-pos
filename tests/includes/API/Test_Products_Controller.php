<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\API\Products_Controller;
use WP_REST_Request;
use WP_User;

function woocommerce_pos_get_settings( $group, $field ) {
	if ( 'general' === $group && 'barcode' === $field) {
	}

	return array('option' => 'mock_value');
}

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Products_Controller extends WC_REST_Unit_Test_Case {
	/**
	 * @var Products_Controller
	 */
	protected $endpoint;

	/**
	 * @var WP_User
	 */
	protected $user;

	public function setup(): void {
		parent::setUp();

		$this->endpoint = new Products_Controller();
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

	// public function test_something(): void {
	// 	// Your test logic here
	// 	$result = woocommerce_pos_get_settings('group', 'key');
	// 	$this->assertEquals('mocked_value', $result);
	// }

	public function get_wp_rest_request( $method = 'GET', $path = '/wcpos/v1/products' ) {
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
		
		$this->assertEquals('products', $rest_base_property->getValue($this->endpoint));
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
	public function get_expected_response_fields() {
		return array(
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
		);
	}

	public function test_product_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$product  = ProductHelper::create_simple_product();
		$response = $this->server->dispatch( $this->get_wp_rest_request( 'GET', '/wcpos/v1/products/' . $product->get_id() ) );

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
		$product  = ProductHelper::create_simple_product();
		$request  = $this->get_wp_rest_request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array('id') );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals( array( (object) array( 'id' => $product->get_id() ) ), $response->get_data() );
	}

	/**
	 * Each product needs a UUID.
	 */
	public function test_product_response_contains_uuid_meta_data(): void {
		$product  = ProductHelper::create_simple_product();
		$request  = new WP_REST_Request('GET', '/wcpos/v1/products/' . $product->get_id());
		$response = $this->server->dispatch($request);

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

	/**
	 * Barcode.
	 */
	public function test_get_barcode(): void {
		add_filter( 'woocommerce_pos_general_settings', function() {
			return array(
				'barcode_field' => 'foo',
			);
		});

		$product  = ProductHelper::create_simple_product();
		$product->update_meta_data( 'foo', 'bar' );
		$this->assertEquals( 'bar', $this->endpoint->wcpos_get_barcode( $product ) );
	}


	public function test_product_response_contains_barcode(): void {
		add_filter( 'woocommerce_pos_general_settings', function() {
			return array(
				'barcode_field' => '_some_field',
			);
		});

		$product  = ProductHelper::create_simple_product();
		$product->update_meta_data( '_some_field', 'some_string' );
		$product->save_meta_data();
		$request  = new WP_REST_Request('GET', '/wcpos/v1/products/' . $product->get_id());
		$response = $this->server->dispatch($request);
	
		$data = $response->get_data();
	
		$this->assertEquals(200, $response->get_status());
			
		$this->assertEquals( 'some_string', $data['barcode'] );
	}

	/**
	 * Orerby.
	 */
	public function test_orderby_sku(): void {
		$product1  = ProductHelper::create_simple_product( array( 'sku' => '987654321' ) );
		$product2  = ProductHelper::create_simple_product( array( 'sku' => 'zeta' ) );
		$product3  = ProductHelper::create_simple_product( array( 'sku' => '123456789' ) );
		$product4  = ProductHelper::create_simple_product( array( 'sku' => 'alpha' ) );
		$request   = $this->get_wp_rest_request( 'GET', '/wcpos/v1/products' );
		$request->set_query_params( array( 'orderby' => 'sku', 'order' => 'asc' ) );
		$response     = rest_get_server()->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'sku' );

		$this->assertEquals( $skus, array( '123456789', '987654321', 'alpha', 'zeta' ) );

		// reverse order
		$request->set_query_params( array( 'orderby' => 'sku', 'order' => 'desc' ) );
		$response     = rest_get_server()->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'sku' );

		$this->assertEquals( $skus, array( 'zeta', 'alpha', '987654321', '123456789' ) );
	}

	public function test_orderby_stock_status(): void {
		$product1  = ProductHelper::create_simple_product( array( 'stock_status' => 'instock' ) );
		$product2  = ProductHelper::create_simple_product( array( 'stock_status' => 'outofstock' ) );
		$request   = $this->get_wp_rest_request( 'GET', '/wcpos/v1/products' );
		$request->set_query_params( array( 'orderby' => 'stock_status', 'order' => 'asc' ) );
		$response     = rest_get_server()->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'stock_status' );

		$this->assertEquals( $skus, array( 'instock', 'outofstock' ) );

		// reverse order
		$request->set_query_params( array( 'orderby' => 'stock_status', 'order' => 'desc' ) );
		$response     = rest_get_server()->dispatch( $request );
		$data         = $response->get_data();
		$skus         = wp_list_pluck( $data, 'stock_status' );

		$this->assertEquals( $skus, array( 'outofstock', 'instock' ) );
	}
}
