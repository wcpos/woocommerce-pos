<?php
/**
 * Tests for the Customers Controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\V1\Customers_Controller;
use WCPOS\WooCommercePOS\Services\Tax_Id_Types;
use WCPOS\WooCommercePOS\Services\Tax_Id_Writer;

/**
 * Test_Customers_Controller class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Customers_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Set up test fixtures.
	 */
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Customers_Controller();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test namespace property.
	 */
	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );

		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	/**
	 * Test rest_base property.
	 */
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
	 *
	 * @return array
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
			'tax_ids',
		);
	}

	/**
	 * Test getting all customer fields.
	 */
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
	 * The bulk id path honours an include filter.
	 */
	public function test_customer_api_get_all_ids_with_include_filter(): void {
		$customer1 = CustomerHelper::create_customer();
		$customer2 = CustomerHelper::create_customer();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$request->set_param( 'include', array( $customer1->get_id() ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( array( $customer1->get_id() ), $ids );
		$this->assertNotContains( $customer2->get_id(), $ids );
	}

	/**
	 * Exclude wins over include on the bulk id path.
	 */
	public function test_customer_api_get_all_ids_with_include_and_exclude_filter(): void {
		$customer1 = CustomerHelper::create_customer();
		$customer2 = CustomerHelper::create_customer();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$request->set_param( 'include', array( $customer1->get_id(), $customer2->get_id() ) );
		$request->set_param( 'exclude', array( $customer2->get_id() ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( array( $customer1->get_id() ), $ids );
		$this->assertNotContains( $customer2->get_id(), $ids );
	}

	/**
	 * The bulk id path honours an exclude filter.
	 */
	public function test_customer_api_get_all_ids_with_exclude_filter(): void {
		$customer1 = CustomerHelper::create_customer();
		$customer2 = CustomerHelper::create_customer();
		$request   = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$request->set_param( 'exclude', array( $customer1->get_id() ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertNotContains( $customer1->get_id(), $ids );
		$this->assertContains( $customer2->get_id(), $ids );
	}

	/**
	 * The bulk id path honours modified_after without serving the date field.
	 */
	public function test_customer_api_get_all_ids_with_modified_after_returns_updated_ids_without_date_field(): void {
		$customer = CustomerHelper::create_customer();
		update_user_meta( $customer->get_id(), 'last_update', time() );

		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$request->set_param( 'modified_after', gmdate( 'Y-m-d\TH:i:s', time() - 60 ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertContains( $customer->get_id(), $ids );
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

		// Look for the _woocommerce_pos_uuid key in meta_data.
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
	 * Updating customer tax_ids preserves the submitted type and country even
	 * when the mapped legacy meta key is generic and billing country differs.
	 */
	public function test_update_customer_tax_ids_preserves_type_and_country(): void {
		$customer = CustomerHelper::create_customer(
			array(
				'billing_country' => 'US',
				'email'           => 'tax-id-customer@example.com',
			)
		);

		$request = $this->wp_rest_post_request( '/wcpos/v1/customers/' . $customer->get_id() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'tax_ids' => array(
						array(
							'type'    => Tax_Id_Types::TYPE_AU_ABN,
							'value'   => '86792035060',
							'country' => 'AU',
							'label'   => null,
						),
					),
				)
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'type'     => Tax_Id_Types::TYPE_AU_ABN,
					'value'    => '86792035060',
					'country'  => 'AU',
					'label'    => null,
					'verified' => null,
				),
			),
			$data['tax_ids']
		);
		$this->assertSame( '86792035060', get_user_meta( $customer->get_id(), 'billing_vat_number', true ) );
		$this->assertNotEmpty( get_user_meta( $customer->get_id(), Tax_Id_Writer::OWNED_KEYS_META_KEY, true ) );
	}

	/**
	 * Orderby first_name.
	 */
	public function test_orderby_first_name(): void {
		// Create some customers.
		CustomerHelper::create_customer( array( 'first_name' => 'Alice' ) );
		CustomerHelper::create_customer( array( 'first_name' => 'Zara' ) );
		CustomerHelper::create_customer( array( 'first_name' => 'Bob' ) );

		// Order by 'first_name' ascending.
		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				// The POS customer space now defaults to every user (see
				// Customers_Lane_Parity_Tests); pin the role so this sort assertion
				// stays about the sort and not about which users exist.
				'role'    => 'customer',
				'orderby' => 'first_name',
				'order'   => 'asc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$first_names = wp_list_pluck( $data, 'first_name' );

		$this->assertEquals( $first_names, array( 'Alice', 'Bob', 'Zara' ) );

		// Reverse order.
		$request->set_query_params(
			array(
				'role'    => 'customer',
				'orderby' => 'first_name',
				'order'   => 'desc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$first_names = wp_list_pluck( $data, 'first_name' );

		$this->assertEquals( $first_names, array( 'Zara', 'Bob', 'Alice' ) );
	}

	/**
	 * Orderby last_name.
	 */
	public function test_orderby_last_name(): void {
		// Create some customers.
		CustomerHelper::create_customer( array( 'last_name' => 'Anderson' ) );
		CustomerHelper::create_customer( array( 'last_name' => 'Thompson' ) );
		CustomerHelper::create_customer( array( 'last_name' => 'Martinez' ) );

		// Order by 'last_name' ascending.
		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				// The POS customer space now defaults to every user (see
				// Customers_Lane_Parity_Tests); pin the role so this sort assertion
				// stays about the sort and not about which users exist.
				'role'    => 'customer',
				'orderby' => 'last_name',
				'order'   => 'asc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$last_names  = wp_list_pluck( $data, 'last_name' );

		$this->assertEquals( $last_names, array( 'Anderson', 'Martinez', 'Thompson' ) );

		// Reverse order.
		$request->set_query_params(
			array(
				'role'    => 'customer',
				'orderby' => 'last_name',
				'order'   => 'desc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$last_names  = wp_list_pluck( $data, 'last_name' );

		$this->assertEquals( $last_names, array( 'Thompson', 'Martinez', 'Anderson' ) );
	}

	/**
	 * Orderby email.
	 */
	public function test_orderby_email(): void {
		// Create some customers.
		CustomerHelper::create_customer( array( 'email' => 'john.doe@example.com' ) );
		CustomerHelper::create_customer( array( 'email' => 'sarah.smith@sample.com' ) );
		CustomerHelper::create_customer( array( 'email' => 'alex.miller@demo.net' ) );

		// Order by 'email' ascending.
		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				// The POS customer space now defaults to every user (see
				// Customers_Lane_Parity_Tests); pin the role so this sort assertion
				// stays about the sort and not about which users exist.
				'role'    => 'customer',
				'orderby' => 'email',
				'order'   => 'asc',
			)
		);
		$response    = $this->server->dispatch( $request );
		$data        = $response->get_data();
		$emails      = wp_list_pluck( $data, 'email' );

		$this->assertEquals( $emails, array( 'alex.miller@demo.net', 'john.doe@example.com', 'sarah.smith@sample.com' ) );

		// Reverse order.
		$request->set_query_params(
			array(
				'role'    => 'customer',
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
	 * Orderby role.
	 *
	 * Roles live in the serialized `wp_capabilities` usermeta, so a naive
	 * meta_value sort is noise. `wcpos_customer_query` + `wcpos_orderby_role`
	 * rank users by role hierarchy (administrator → subscriber, unknown last),
	 * with multi-role users taking their highest-privilege position. This
	 * asserts the full ladder in both directions and the multi-role rule.
	 *
	 * Users are created with distinct hierarchy ranks and the response is
	 * filtered to just those IDs, so interleaved fixtures (eg. the admin the
	 * base test class authenticates as) do not affect the assertion.
	 */
	public function test_orderby_role(): void {
		$admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$cashier_id    = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$editor_id     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$customer_id   = $this->factory->user->create( array( 'role' => 'customer' ) );
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Multi-role user: primary customer, but also shop_manager. Must sort by
		// the highest-privilege role (shop_manager, rank 2), not customer.
		$multi_id   = $this->factory->user->create( array( 'role' => 'customer' ) );
		$multi_user = new \WP_User( $multi_id );
		$multi_user->add_role( 'shop_manager' );

		// Expected order by hierarchy rank (asc): admin(1) <
		// shop_manager/multi(2) < cashier(3) < editor(4) < customer(7) <
		// subscriber(8).
		$expected_asc  = array( $admin_id, $multi_id, $cashier_id, $editor_id, $customer_id, $subscriber_id );
		$expected_desc = array_reverse( $expected_asc );
		$my_ids        = $expected_asc;

		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'role'     => 'all',
				'orderby'  => 'role',
				'order'    => 'asc',
				'per_page' => 100,
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$ids_in_order = wp_list_pluck( $response->get_data(), 'id' );
		$filtered_asc = array_values( array_intersect( $ids_in_order, $my_ids ) );
		$this->assertEquals( $expected_asc, $filtered_asc );

		// Reverse order.
		$request->set_query_params(
			array(
				'role'     => 'all',
				'orderby'  => 'role',
				'order'    => 'desc',
				'per_page' => 100,
			)
		);
		$response      = $this->server->dispatch( $request );
		$ids_in_order  = wp_list_pluck( $response->get_data(), 'id' );
		$filtered_desc = array_values( array_intersect( $ids_in_order, $my_ids ) );
		$this->assertEquals( $expected_desc, $filtered_desc );
	}

	/**
	 * Orderby username.
	 */
	public function test_orderby_username(): void {
		// Create some customers.
		CustomerHelper::create_customer( array( 'username' => 'alpha' ) );
		CustomerHelper::create_customer( array( 'username' => 'zeta' ) );
		CustomerHelper::create_customer( array( 'username' => 'beta' ) );

		// Order by 'username' ascending.
		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				// The POS customer space now defaults to every user (see
				// Customers_Lane_Parity_Tests); pin the role so this sort assertion
				// stays about the sort and not about which users exist.
				'role'    => 'customer',
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

		// Reverse order.
		$request->set_query_params(
			array(
				'role'    => 'customer',
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

		// Empty search. Pinned to the `customer` role: this block counts the nine
		// fixtures, and the default role is now `all` (every user on the site).
		$request->set_query_params(
			array(
				'role'   => 'customer',
				'search' => '',
			)
		);
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 9, \count( $data ) );

		// Search for first_name.
		$request->set_query_params( array( 'search' => $random_first_name ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer1->get_id(), $data[0]['id'] );

		// Search for last_name.
		$request->set_query_params( array( 'search' => $random_last_name ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer2->get_id(), $data[0]['id'] );

		// Search for email.
		$request->set_query_params( array( 'search' => $random_email ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer3->get_id(), $data[0]['id'] );

		// Search for username.
		$request->set_query_params( array( 'search' => $random_username ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer4->get_id(), $data[0]['id'] );

		// Search for billing_first_name.
		$request->set_query_params( array( 'search' => $random_billing_first_name ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer5->get_id(), $data[0]['id'] );

		// Search for billing_last_name.
		$request->set_query_params( array( 'search' => $random_billing_last_name ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer6->get_id(), $data[0]['id'] );

		// Search for billing_email.
		$request->set_query_params( array( 'search' => $random_billing_email ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer7->get_id(), $data[0]['id'] );

		// Search for billing_company.
		$request->set_query_params( array( 'search' => $random_billing_company ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer8->get_id(), $data[0]['id'] );

		// Search for billing_phone.
		$request->set_query_params( array( 'search' => $random_billing_phone ) );
		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer9->get_id(), $data[0]['id'] );
	}

	/**
	 * Customer search requires every whitespace-separated term to match a searchable field.
	 *
	 * @dataProvider customer_search_matching_terms_provider
	 *
	 * @param string $search Search string.
	 */
	public function test_customer_search_matches_every_term( string $search ): void {
		$customer = CustomerHelper::create_customer(
			array(
				'first_name'      => 'Jane',
				'last_name'       => 'Smith',
				'email'           => 'multiword.customer@example.com',
				'billing_company' => 'Acme Consulting',
			)
		);
		wp_update_user(
			array(
				'ID'           => $customer->get_id(),
				'display_name' => 'WCPOS Customer',
			)
		);

		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'role'   => 'all',
				'search' => $search,
			)
		);
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer->get_id(), $data[0]['id'] );
	}

	/**
	 * A customer matching the same term in several fields is returned once.
	 *
	 * The previous implementation matched meta via a JOIN, which could return the same user
	 * once per matching meta row and inflate the reported total.
	 */
	public function test_customer_search_does_not_duplicate_multi_field_matches(): void {
		$customer = CustomerHelper::create_customer(
			array(
				'first_name'      => 'Acme',
				'last_name'       => 'Acme',
				'email'           => 'acme@example.com',
				'billing_company' => 'Acme Industries',
			)
		);

		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'role'   => 'all',
				'search' => 'Acme',
			)
		);
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $customer->get_id(), $data[0]['id'] );
		$this->assertEquals( 1, (int) $headers['X-WP-Total'] );
	}

	/**
	 * A whitespace-only search has no terms and behaves like no search at all.
	 */
	public function test_customer_search_ignores_whitespace_only_search(): void {
		CustomerHelper::create_customer(
			array(
				'first_name' => 'Jane',
				'last_name'  => 'Smith',
			)
		);

		$no_search_request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$no_search_request->set_query_params( array( 'role' => 'all' ) );
		$no_search_response = $this->server->dispatch( $no_search_request );

		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'role'   => 'all',
				'search' => "\u{00A0}",
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals(
			wp_list_pluck( $no_search_response->get_data(), 'id' ),
			wp_list_pluck( $response->get_data(), 'id' )
		);
	}

	/**
	 * The search hook is a no-op on a query it did not prepare.
	 *
	 * WordPress fires pre_user_query for every WP_User_Query, and this callback can outlive our
	 * own query if that query is short-circuited before running. Firing on an unrelated query
	 * (no _wcpos_search marker) must not touch its WHERE clause.
	 */
	public function test_search_hook_leaves_unrelated_query_untouched(): void {
		$controller = new Customers_Controller();
		$query      = new \WP_User_Query();

		$query->query_vars  = array();
		$query->query_where = 'WHERE 1=1';

		$controller->wcpos_search_user_table( $query );

		$this->assertEquals( 'WHERE 1=1', $query->query_where );
	}

	/**
	 * Customer search excludes customers when any term does not match.
	 */
	public function test_customer_search_returns_empty_when_any_term_does_not_match(): void {
		CustomerHelper::create_customer(
			array(
				'first_name' => 'Jane',
				'last_name'  => 'Smith',
			)
		);

		$request = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$request->set_query_params(
			array(
				'role'   => 'all',
				'search' => 'Jane Nonexistent',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEmpty( $response->get_data() );
	}

	/**
	 * Customer search terms that should match.
	 *
	 * @return array<string, array{string}>
	 */
	public function customer_search_matching_terms_provider(): array {
		return array(
			'full name'                 => array( 'Jane Smith' ),
			'reversed name'             => array( 'Smith Jane' ),
			'single term'               => array( 'Jane' ),
			'email'                     => array( 'multiword.customer@example.com' ),
			'billing company'           => array( 'Acme Consulting' ),
			'display name'              => array( 'WCPOS Customer' ),
			'extra internal whitespace' => array( "Jane \t  Smith" ),
			'term limit'                => array( 'Jane Jane Jane Jane Jane Jane Jane Jane Jane Jane Nonexistent' ),
		);
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

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'John', $data['first_name'] );
		$this->assertEquals( 'Doe', $data['last_name'] );
	}

	/**
	 * Test that generate_username = true auto-generates a username from the email address,
	 * overriding the WooCommerce store setting when it is set to the opposite value.
	 */
	public function test_create_customer_generates_username_from_email(): void {
		$prev_pos     = get_option( 'woocommerce_pos_settings_general', array() );
		$prev_wc      = get_option( 'woocommerce_registration_generate_username' );
		try {
			update_option( 'woocommerce_pos_settings_general', array( 'generate_username' => true ) );
			// Set the WC store option to 'no' so the POS override is the only reason generation works.
			update_option( 'woocommerce_registration_generate_username', 'no' );

			$request = $this->wp_rest_post_request( '/wcpos/v1/customers' );
			$request->set_body_params(
				array(
					'email'    => 'jane.smith@example.com',
					'password' => '',
				)
			);
			$response = $this->server->dispatch( $request );
			$data     = $response->get_data();

			$this->assertEquals( 201, $response->get_status() );
			// Username should be derived from the email local-part.
			$this->assertStringStartsWith( 'jane', $data['username'] );
		} finally {
			update_option( 'woocommerce_pos_settings_general', $prev_pos );
			update_option( 'woocommerce_registration_generate_username', $prev_wc );
		}
	}

	/**
	 * Test that generate_username = false allows an explicit username to be used,
	 * even when the WooCommerce store setting would auto-generate one.
	 */
	public function test_create_customer_uses_explicit_username_when_generate_disabled(): void {
		$prev_pos = get_option( 'woocommerce_pos_settings_general', array() );
		$prev_wc  = get_option( 'woocommerce_registration_generate_username' );
		try {
			update_option( 'woocommerce_pos_settings_general', array( 'generate_username' => false ) );
			// Set the WC store option to 'yes' to confirm the POS does not force it.
			update_option( 'woocommerce_registration_generate_username', 'yes' );

			$request = $this->wp_rest_post_request( '/wcpos/v1/customers' );
			$request->set_body_params(
				array(
					'email'    => 'bob.jones@example.com',
					'username' => 'bob.jones',
					'password' => '',
				)
			);
			$response = $this->server->dispatch( $request );
			$data     = $response->get_data();

			$this->assertEquals( 201, $response->get_status() );
			$this->assertEquals( 'bob.jones', $data['username'] );
		} finally {
			update_option( 'woocommerce_pos_settings_general', $prev_pos );
			update_option( 'woocommerce_registration_generate_username', $prev_wc );
		}
	}

	/**
	 * Test customer update.
	 */
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
	 * Test customer search with excludes.
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

	/**
	 * Test that each customer UUID is unique.
	 */
	public function test_customer_uuid_is_unique(): void {
		$uuid       = Uuid::uuid4()->toString();
		$customer1  = CustomerHelper::create_customer();
		$customer1->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$customer1->save_meta_data();
		$customer2  = CustomerHelper::create_customer();
		$customer2->update_meta_data( '_woocommerce_pos_uuid', $uuid );
		$customer2->save_meta_data();

		$request   = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		// The POS customer space now defaults to every user (see
		// Customers_Lane_Parity_Tests); pin the role so this stays a test about
		// uuid rekeying rather than about which users exist on the site.
		$request->set_query_params( array( 'role' => 'customer' ) );

		$response     = $this->server->dispatch( $request );
		$data         = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 2, \count( $data ) );

		// Pluck uuids from meta_data.
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
	public function test_batch_create_customer_with_string_meta_data_entry_creates_customer(): void {
		// Arrange.
		$request = $this->wp_rest_post_request( '/wcpos/v1/customers/batch' );
		$request->set_body_params(
			array(
				'create' => array(
					array(
						'email'     => 'batch-meta@example.com',
						'meta_data' => array( 'not-an-object' ),
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
		$this->assertEquals( 'batch-meta@example.com', $data['create'][0]['email'] );
	}
}
