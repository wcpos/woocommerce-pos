<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\Customers_Controller;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Customers_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Customers_Controller();
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

		$this->assertEquals( 'customers', $rest_base );
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
		$request     = $this->wp_rest_get_request( '/wcpos/v1/customers/' . $customer->get_id() );
		$response    = $this->server->dispatch( $request );

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
		$request     = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( 1, $this->user, $customer->get_id() ), $ids );
	}

	/**
	 * Each customer needs a UUID.
	 */
	public function test_customer_response_contains_uuid_meta_data(): void {
		$customer = CustomerHelper::create_customer();
		$request  = $this->wp_rest_get_request( '/wcpos/v1/customers/' . $customer->get_id() );
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
	 * Orderby.
	 */
	public function test_orderby_first_name(): void {
		// Create some customers
		$customer1 = CustomerHelper::create_customer( array( 'first_name' => 'Alice' ) );
		$customer2 = CustomerHelper::create_customer( array( 'first_name' => 'Zara' ) );
		$customer3 = CustomerHelper::create_customer( array( 'first_name' => 'Bob' ) );

		// Order by 'first_name' ascending
		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'orderby' => 'first_name',
				'order'   => 'asc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$first_names = wp_list_pluck( $data, 'first_name' );

		$this->assertEquals( $first_names, array( 'Alice', 'Bob', 'Zara' ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'first_name',
				'order'   => 'desc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$first_names = wp_list_pluck( $data, 'first_name' );

		$this->assertEquals( $first_names, array( 'Zara', 'Bob', 'Alice' ) );
	}

	public function test_orderby_last_name(): void {
		// Create some customers
		$customer1 = CustomerHelper::create_customer( array( 'last_name' => 'Anderson' ) );
		$customer2 = CustomerHelper::create_customer( array( 'last_name' => 'Thompson' ) );
		$customer3 = CustomerHelper::create_customer( array( 'last_name' => 'Martinez' ) );

		// Order by 'last_name' ascending
		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'orderby' => 'last_name',
				'order'   => 'asc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$last_names  = wp_list_pluck( $data, 'last_name' );

		$this->assertEquals( $last_names, array( 'Anderson', 'Martinez', 'Thompson' ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'last_name',
				'order'   => 'desc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$last_names  = wp_list_pluck( $data, 'last_name' );

		$this->assertEquals( $last_names, array( 'Thompson', 'Martinez', 'Anderson' ) );
	}

	public function test_orderby_email(): void {
		// Create some customers
		$customer1 = CustomerHelper::create_customer( array( 'email' => 'john.doe@example.com' ) );
		$customer2 = CustomerHelper::create_customer( array( 'email' => 'sarah.smith@sample.com' ) );
		$customer3 = CustomerHelper::create_customer( array( 'email' => 'alex.miller@demo.net' ) );

		// Order by 'email' ascending
		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'orderby' => 'email',
				'order'   => 'asc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$emails      = wp_list_pluck( $data, 'email' );

		$this->assertEquals( $emails, array( 'alex.miller@demo.net', 'john.doe@example.com', 'sarah.smith@sample.com' ) );

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'email',
				'order'   => 'desc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$emails      = wp_list_pluck( $data, 'email' );

		$this->assertEquals( $emails, array( 'sarah.smith@sample.com', 'john.doe@example.com', 'alex.miller@demo.net' ) );
	}

	/**
	 * @TODO Role sorting is a known limitation.
	 *
	 * The wp_capabilities meta field is a serialized array like:
	 * a:1:{s:13:"administrator";b:1;} or a:1:{s:10:"subscriber";b:1;}
	 *
	 * Sorting by meta_value on this field compares serialized strings, not actual role names.
	 * To fix this properly, we'd need to use a pre_user_query filter to extract and sort
	 * by the actual role name, which is complex and may have performance implications.
	 *
	 * @see https://github.com/wcpos/woocommerce-pos/issues/XXX
	 */
	public function test_orderby_role(): void {
		$this->markTestSkipped( 'Role sorting is a known limitation - wp_capabilities is a serialized array' );
	}

	public function test_orderby_username(): void {
		// Create some customers
		$customer1 = CustomerHelper::create_customer( array( 'username' => 'alpha' ) );
		$customer2 = CustomerHelper::create_customer( array( 'username' => 'zeta' ) );
		$customer3 = CustomerHelper::create_customer( array( 'username' => 'beta' ) );

		// Order by 'username' ascending
		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'orderby' => 'username',
				'order'   => 'asc',
			)
		);
		$response        = $this->server->dispatch( $request );
		$data            = $response->get_data();
		$usernames       = wp_list_pluck( $data, 'username' );

		$this->assertEquals(
			$usernames,
			array( 'alpha', 'beta', 'zeta' )
		);

		// reverse order
		$request->set_query_params(
			array(
				'orderby' => 'username',
				'order'   => 'desc',
			)
		);
		$response        = $this->server->dispatch( $request );
		$data            = $response->get_data();
		$usernames       = wp_list_pluck( $data, 'username' );

		$this->assertEquals(
			$usernames,
			array( 'zeta', 'beta', 'alpha' )
		);
	}

	/**
	 * Search.
	 */
	public function test_customer_search(): void {
		$random_first_name              = wp_generate_password( 8, false );
		$random_last_name               = wp_generate_password( 8, false );
		$random_email                   = wp_generate_password( 6, false ) . '@example.com';
		$random_username                = wp_generate_password( 6, false );
		$random_billing_first_name      = wp_generate_password( 8, false );
		$random_billing_last_name       = wp_generate_password( 8, false );
		$random_billing_email           = wp_generate_password( 8, false ) . '@test.com';
		$random_billing_company         = wp_generate_password( 8, false );
		$random_billing_phone           = wp_generate_password( 8, false );

		$customer1 = CustomerHelper::create_customer( array( 'first_name' => $random_first_name ) );
		$customer2 = CustomerHelper::create_customer( array( 'last_name' => $random_last_name ) );
		$customer3 = CustomerHelper::create_customer( array( 'email' => $random_email ) );
		$customer4 = CustomerHelper::create_customer( array( 'username' => $random_username ) );
		$customer5 = CustomerHelper::create_customer( array( 'billing_first_name' => $random_billing_first_name ) );
		$customer6 = CustomerHelper::create_customer( array( 'billing_last_name' => $random_billing_last_name ) );
		$customer7 = CustomerHelper::create_customer( array( 'billing_email' => $random_billing_email ) );
		$customer8 = CustomerHelper::create_customer( array( 'billing_company' => $random_billing_company ) );
		$customer9 = CustomerHelper::create_customer( array( 'billing_phone' => $random_billing_phone ) );

		$request   = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params( array( 'role' => 'all' ) );

		// empty search
		$request->set_query_params( array( 'search' => '' ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 9, \count( $data ) );

		// search for first_name
		$request->set_query_params( array( 'search' => $random_first_name ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer1->get_id(), $data[0]['id'] );

		// search for last_name
		$request->set_query_params( array( 'search' => $random_last_name ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer2->get_id(), $data[0]['id'] );

		// search for email
		$request->set_query_params( array( 'search' => $random_email ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer3->get_id(), $data[0]['id'] );

		// search for username
		$request->set_query_params( array( 'search' => $random_username ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer4->get_id(), $data[0]['id'] );

		// search for billing_first_name
		$request->set_query_params( array( 'search' => $random_billing_first_name ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer5->get_id(), $data[0]['id'] );

		// search for billing_last_name
		$request->set_query_params( array( 'search' => $random_billing_last_name ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer6->get_id(), $data[0]['id'] );

		// search for billing_last_name
		$request->set_query_params( array( 'search' => $random_billing_email ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer7->get_id(), $data[0]['id'] );

		// search for billing_company
		$request->set_query_params( array( 'search' => $random_billing_company ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer8->get_id(), $data[0]['id'] );

		// search for billing_last_name
		$request->set_query_params( array( 'search' => $random_billing_phone ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer9->get_id(), $data[0]['id'] );
	}

	/**
	 * Test customer creation.
	 */
	public function test_create_customer(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/customers' );
		$request->set_body_params(
			array(
				'first_name' => 'John',
				'last_name'  => 'Doe',
				'email'      => 'email@example.com',
				'password'   => '',
			)
		);
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		// print_r($data);

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'John', $data['first_name'] );
		$this->assertEquals( 'Doe', $data['last_name'] );
	}

	public function test_update_customer(): void {
		$customer = CustomerHelper::create_customer(
			array(
				'first_name' => 'Sarah',
				'last_name'  => 'Dobbs',
				'email'      => 'dobbs@example.com',
			),
		);

		$request  = $this->wp_rest_get_request( '/wcpos/v1/customers/' . $customer->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'Sarah', $data['first_name'] );

		$request            = $this->wp_rest_post_request( '/wcpos/v1/customers/' . $customer->get_id() );
		$data['first_name'] = 'Jane';
		$request->set_body_params( $data );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'Jane', $data['first_name'] );
	}

	/**
	 * Test customer search with includes.
	 */
	public function test_customer_search_with_includes(): void {
		$customer1 = CustomerHelper::create_customer( array( 'first_name' => 'John' ) );
		$customer2 = CustomerHelper::create_customer( array( 'first_name' => 'John' ) );

		$request   = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'role'    => 'all',
				'search'  => 'John',
				'include' => $customer2->get_id(),
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer2->get_id(), $data[0]['id'] );
	}

	/**
	 * Test customer search with includes.
	 */
	public function test_customer_search_with_excludes(): void {
		$customer1 = CustomerHelper::create_customer( array( 'first_name' => 'John' ) );
		$customer2 = CustomerHelper::create_customer( array( 'first_name' => 'John' ) );

		$request   = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'role'    => 'all',
				'search'  => 'John',
				'exclude' => $customer2->get_id(),
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer1->get_id(), $data[0]['id'] );
	}

	public function test_customer_uuid_is_unique(): void {
		$uuid       = Uuid::uuid4()->toString();
		$customer1  = CustomerHelper::create_customer();
		$customer1->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$customer1->save_meta_data();
		$customer2  = CustomerHelper::create_customer();
		$customer2->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$customer2->save_meta_data();

		$request   = $this->wp_rest_get_request( '/wcpos/v1/customers' );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );

		// pluck uuids from meta_data
		$uuids = array();
		foreach ( $data as $customer ) {
			foreach ( $customer['meta_data'] as $meta ) {
				if ( '_woocommerce_pos_uuid' === $meta['key'] ) {
					$uuids[] = $meta['value'];
				}
			}
		}

		$this->assertEquals( 2, \count( $uuids ) );
		$this->assertContains( $uuid, $uuids );
		$this->assertEquals( 2, \count( array_unique( $uuids ) ) );
	}
}
