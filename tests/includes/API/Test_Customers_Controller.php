<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\API;
use WCPOS\WooCommercePOS\API\Customers_Controller;
use WP_REST_Request;
use WP_User;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Customers_Controller extends WC_REST_Unit_Test_Case {
	/**
	 * @var Customers_Controller
	 */
	protected $endpoint;

	/**
	 * @var WP_User
	 */
	protected $user;

	
	public function setup(): void {
		parent::setUp();

		$this->endpoint = new Customers_Controller();
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

	public function get_wp_rest_request( $method = 'GET', $path = '/wcpos/v1/customers' ) {
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
		
		$this->assertEquals('customers', $rest_base_property->getValue($this->endpoint));
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/customers', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/customers/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/customers/batch', $routes );
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
			'email',
			'first_name',
			'last_name',
			'role',
			'username',
			'billing',
			'shipping',
			'is_paying_customer',
			'avatar_url',
			'meta_data',
		);
	}

	public function test_customer_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$customer    = CustomerHelper::create_customer();
		$response    = $this->server->dispatch( $this->get_wp_rest_request( 'GET', '/wcpos/v1/customers/' . $customer->get_id() ) );

		$this->assertEquals( 200, $response->get_status() );

		$response_fields = array_keys( $response->get_data() );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	/**
	 * Test getting all customer IDs.
	 */
	public function test_customer_api_get_all_ids(): void {
		$customer    = CustomerHelper::create_customer();
		$request     = $this->get_wp_rest_request( 'GET', '/wcpos/v1/customers' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array('id') );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$this->assertEquals(
			array(
				(object) array( 'id' => 1 ),
				(object) array( 'id' => $customer->get_id() ),
				(object) array( 'id' => 7 ), // from above test?
			), $response->get_data()
		);
	}

	/**
	 * Each custoemr needs a UUID.
	 */
	public function test_customer_response_contains_uuid_meta_data(): void {
		$customer = CustomerHelper::create_customer();
		$request  = $this->get_wp_rest_request( 'GET', '/wcpos/v1/customers/' . $customer->get_id() );
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
	 * Test first_name orderby.
	 */
	public function test_orderby_first_name_fields(): void {
		// Create some customers
		$customer1 = CustomerHelper::create_customer(array('first_name' => 'Alice'));
		$customer2 = CustomerHelper::create_customer(array('first_name' => 'Zara'));
		$customer3 = CustomerHelper::create_customer(array('first_name' => 'Bob'));

		// Order by 'first_name' ascending
		$request = $this->get_wp_rest_request('GET', '/wcpos/v1/customers');
		$request->set_param('orderby', 'first_name');
		$request->set_param('order', 'asc');
		$response = $this->server->dispatch($request);
		$data     = $response->get_data();

		$this->assertEquals(200, $response->get_status());
		$this->assertEquals('Alice', $data[0]['first_name']);
		$this->assertEquals('Bob', $data[1]['first_name']);
		$this->assertEquals('Zara', $data[2]['first_name']);
	}
}
