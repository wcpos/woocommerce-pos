<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\API\Product_Variations_Controller;
use WP_REST_Request;
use WP_User;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Product_Variations_Controller extends WC_REST_Unit_Test_Case {
	/**
	 * @var Product_Variations_Controller
	 */
	protected $endpoint;

	/**
	 * @var WP_User
	 */
	protected $user;

	
	public function setup(): void {
		parent::setUp();

		$this->endpoint = new Product_Variations_Controller();
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

	public function get_wp_rest_request( $method, $path ) {
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
		
		$this->assertEquals('products/(?P<product_id>[\d]+)/variations', $rest_base_property->getValue($this->endpoint));
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
		);
	}

	public function test_variation_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$product     = ProductHelper::create_variation_product();
		$response    = $this->server->dispatch( $this->get_wp_rest_request( 'GET', '/wcpos/v1/products/' . $product->get_id() . '/variations' ) );

		$this->assertEquals( 200, $response->get_status() );
		$variations = $response->get_data();
		$this->assertEquals( 2, \count( $variations ) );
		$response_fields = array_keys( $variations[0] );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	/**
	 * Test getting all customer IDs.
	 */
	public function test_variation_api_get_all_ids(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$this->assertEquals( 2, \count( $variation_ids ) );
		

		$request     = $this->get_wp_rest_request( 'GET', '/wcpos/v1/products/' . $product->get_id() . '/variations' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array('id') );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals(
			array(
				(object) array( 'id' => $variation_ids[1] ),
				(object) array( 'id' => $variation_ids[0] ),
			),
			$response->get_data()
		);
	}

	/**
	 * Each variation needs a UUID.
	 */
	public function test_variation_response_contains_uuid_meta_data(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$request       = new WP_REST_Request('GET', '/wcpos/v1/products/' . $product->get_id() . '/variations/' . $variation_ids[0]);
		$response      = $this->server->dispatch($request);

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
}
