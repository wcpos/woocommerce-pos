<?php
/**
 * Tests for POS API endpoint permissions.
 *
 * Verifies that admin, shop_manager, and cashier roles have correct
 * access to every POS REST API endpoint, and that removing individual
 * capabilities from the cashier role correctly denies access.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * Test_Permissions class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Permissions extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Shop manager user ID.
	 *
	 * @var int
	 */
	protected $shop_manager;

	/**
	 * Cashier user ID.
	 *
	 * @var int
	 */
	protected $cashier;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber;

	/**
	 * Capabilities that should be present on the cashier role.
	 *
	 * @var array
	 */
	private $cashier_caps = array(
		'read',
		'read_private_products',
		'read_private_shop_orders',
		'publish_shop_orders',
		'edit_shop_orders',
		'edit_others_shop_orders',
		'list_users',
		'create_customers',
		'edit_users',
		'read_private_shop_coupons',
		'manage_product_terms',
		'access_woocommerce_pos',
	);

	/**
	 * Create a user with all cashier capabilities except the excluded ones.
	 *
	 * WordPress caches role capabilities in memory, so remove_cap() on a
	 * user object cannot override capabilities granted by the role. This
	 * helper creates a subscriber (no POS caps) and grants individual caps.
	 *
	 * @param array $exclude Capabilities to exclude.
	 *
	 * @return int User ID.
	 */
	private function create_cashier_without( array $exclude ): int {
		$user_id  = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user_obj = get_user_by( 'id', $user_id );

		foreach ( $this->cashier_caps as $cap ) {
			if ( ! in_array( $cap, $exclude, true ) ) {
				$user_obj->add_cap( $cap );
			}
		}

		return $user_id;
	}

	/**
	 * Set up test fixtures.
	 *
	 * Creates admin (via parent), shop_manager, cashier, and subscriber
	 * users. Ensures the cashier role has all required capabilities.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the cashier role has every capability we need for testing.
		$role = get_role( 'cashier' );
		if ( $role ) {
			foreach ( $this->cashier_caps as $cap ) {
				$role->add_cap( $cap );
			}
		}

		$this->shop_manager = $this->factory->user->create(
			array( 'role' => 'shop_manager' )
		);
		// Grant POS caps to shop_manager (mirroring plugin activation).
		$sm_user = get_user_by( 'id', $this->shop_manager );
		$sm_user->add_cap( 'manage_woocommerce_pos' );
		$sm_user->add_cap( 'access_woocommerce_pos' );

		$this->cashier = $this->factory->user->create(
			array( 'role' => 'cashier' )
		);

		$this->subscriber = $this->factory->user->create(
			array( 'role' => 'subscriber' )
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wp_delete_user( $this->shop_manager );
		wp_delete_user( $this->cashier );
		wp_delete_user( $this->subscriber );
		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// Products - role-based tests
	// ──────────────────────────────────────────────

	/**
	 * Test that an admin can read products.
	 */
	public function test_admin_can_read_products(): void {
		wp_set_current_user( $this->user );

		$product  = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product(
			array( 'regular_price' => 18, 'price' => 18 )
		);
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a shop_manager can read products.
	 */
	public function test_shop_manager_can_read_products(): void {
		wp_set_current_user( $this->shop_manager );

		$product  = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product(
			array( 'regular_price' => 18, 'price' => 18 )
		);
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a cashier can read products.
	 */
	public function test_cashier_can_read_products(): void {
		wp_set_current_user( $this->cashier );

		$product  = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product(
			array( 'regular_price' => 18, 'price' => 18 )
		);
		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a cashier cannot create a product.
	 */
	public function test_cashier_cannot_create_product(): void {
		wp_set_current_user( $this->cashier );

		$request = $this->wp_rest_post_request( '/wcpos/v1/products' );
		$request->set_body_params(
			array(
				'name' => 'Test',
				'type' => 'simple',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	// ──────────────────────────────────────────────
	// Orders - role-based tests
	// ──────────────────────────────────────────────

	/**
	 * Test that an admin can create an order.
	 */
	public function test_admin_can_create_order(): void {
		wp_set_current_user( $this->user );

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params( array( 'status' => 'pending' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );
	}

	/**
	 * Test that a shop_manager can create an order.
	 */
	public function test_shop_manager_can_create_order(): void {
		wp_set_current_user( $this->shop_manager );

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params( array( 'status' => 'pending' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );
	}

	/**
	 * Test that a cashier can create an order.
	 */
	public function test_cashier_can_create_order(): void {
		wp_set_current_user( $this->cashier );

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params( array( 'status' => 'pending' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );
	}

	/**
	 * Test that a cashier can update an order.
	 */
	public function test_cashier_can_update_order(): void {
		wp_set_current_user( $this->user );
		$order = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();

		wp_set_current_user( $this->cashier );

		$request = $this->wp_rest_patch_request( '/wcpos/v1/orders/' . $order->get_id() );
		$request->set_body_params( array( 'status' => 'completed' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a cashier cannot delete an order.
	 */
	public function test_cashier_cannot_delete_order(): void {
		wp_set_current_user( $this->user );
		$order = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();

		wp_set_current_user( $this->cashier );

		$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/orders/' . $order->get_id() );
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_param( 'force', true );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	// ──────────────────────────────────────────────
	// Customers - role-based tests
	// ──────────────────────────────────────────────

	/**
	 * Test that an admin can read customers.
	 */
	public function test_admin_can_read_customers(): void {
		wp_set_current_user( $this->user );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a shop_manager can read customers.
	 */
	public function test_shop_manager_can_read_customers(): void {
		wp_set_current_user( $this->shop_manager );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a cashier can read customers.
	 */
	public function test_cashier_can_read_customers(): void {
		wp_set_current_user( $this->cashier );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a cashier can create a customer.
	 */
	public function test_cashier_can_create_customer(): void {
		wp_set_current_user( $this->cashier );

		$request = $this->wp_rest_post_request( '/wcpos/v1/customers' );
		$request->set_body_params(
			array(
				'email'      => 'cashier-create-test@example.com',
				'first_name' => 'Test',
				'last_name'  => 'Customer',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );
	}

	/**
	 * Test that a cashier can update a customer.
	 */
	public function test_cashier_can_update_customer(): void {
		wp_set_current_user( $this->user );
		$customer = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper::create_customer();

		wp_set_current_user( $this->cashier );

		$request = $this->wp_rest_patch_request( '/wcpos/v1/customers/' . $customer->get_id() );
		$request->set_body_params( array( 'first_name' => 'Updated' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a cashier cannot delete a customer.
	 */
	public function test_cashier_cannot_delete_customer(): void {
		wp_set_current_user( $this->user );
		$customer = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper::create_customer();

		wp_set_current_user( $this->cashier );

		$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/customers/' . $customer->get_id() );
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_param( 'force', true );
		$request->set_param( 'reassign', 0 );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	// ──────────────────────────────────────────────
	// Taxes - role-based tests
	// ──────────────────────────────────────────────

	/**
	 * Test that a cashier can read taxes.
	 */
	public function test_cashier_can_read_taxes(): void {
		wp_set_current_user( $this->cashier );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/taxes' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	// ──────────────────────────────────────────────
	// Tax Classes - role-based tests
	// ──────────────────────────────────────────────

	/**
	 * Test that a cashier can read tax classes.
	 */
	public function test_cashier_can_read_tax_classes(): void {
		wp_set_current_user( $this->cashier );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/taxes/classes' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	// ──────────────────────────────────────────────
	// Shipping Methods - role-based tests
	// ──────────────────────────────────────────────

	/**
	 * Test that a cashier can read shipping methods.
	 */
	public function test_cashier_can_read_shipping_methods(): void {
		wp_set_current_user( $this->cashier );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/shipping_methods' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	// ──────────────────────────────────────────────
	// Settings - role-based tests
	// ──────────────────────────────────────────────

	/**
	 * Test that an admin can read settings.
	 */
	public function test_admin_can_read_settings(): void {
		wp_set_current_user( $this->user );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/settings/general' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a shop_manager can read settings.
	 */
	public function test_shop_manager_can_read_settings(): void {
		wp_set_current_user( $this->shop_manager );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/settings/general' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that a cashier cannot read settings.
	 */
	public function test_cashier_cannot_read_settings(): void {
		wp_set_current_user( $this->cashier );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/settings/general' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	// ──────────────────────────────────────────────
	// Baseline gate tests
	// ──────────────────────────────────────────────

	/**
	 * Test that a subscriber (no POS caps) cannot access products.
	 */
	public function test_subscriber_cannot_access_products(): void {
		wp_set_current_user( $this->subscriber );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test that a subscriber cannot access orders.
	 */
	public function test_subscriber_cannot_access_orders(): void {
		wp_set_current_user( $this->subscriber );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test that the auth/test endpoint is public (not 403).
	 */
	public function test_auth_test_endpoint_is_public(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/auth/test' );
		$response = $this->server->dispatch( $request );

		$this->assertNotEquals( 403, $response->get_status() );
	}

	/**
	 * Test that the auth/refresh endpoint is public (not 403).
	 */
	public function test_auth_refresh_endpoint_is_public(): void {
		wp_set_current_user( 0 );

		$request = $this->wp_rest_post_request( '/wcpos/v1/auth/refresh' );
		$request->set_body_params( array( 'refresh_token' => 'dummy' ) );
		$response = $this->server->dispatch( $request );

		$this->assertNotEquals( 403, $response->get_status() );
	}

	// ──────────────────────────────────────────────
	// Capability subtraction tests
	//
	// WordPress caches role capabilities in memory, so remove_cap() on a
	// user object cannot override capabilities granted by the role. Each
	// test creates a fresh subscriber and grants only the caps we need.
	// ──────────────────────────────────────────────

	/**
	 * Test that a cashier without access_woocommerce_pos is blocked on products.
	 */
	public function test_cashier_without_access_pos_blocked_on_products(): void {
		$user_id = $this->create_cashier_without( array( 'access_woocommerce_pos' ) );
		wp_set_current_user( $user_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that a cashier without read_private_products cannot read products.
	 */
	public function test_cashier_without_read_private_products_cannot_read_products(): void {
		$user_id = $this->create_cashier_without( array( 'read_private_products' ) );
		wp_set_current_user( $user_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/products' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that a cashier without publish_shop_orders cannot create orders.
	 */
	public function test_cashier_without_publish_shop_orders_cannot_create_orders(): void {
		$user_id = $this->create_cashier_without( array( 'publish_shop_orders' ) );
		wp_set_current_user( $user_id );

		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params( array( 'status' => 'pending' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that a cashier without read_private_shop_orders cannot read orders.
	 */
	public function test_cashier_without_read_private_shop_orders_cannot_read_orders(): void {
		$user_id = $this->create_cashier_without( array( 'read_private_shop_orders' ) );
		wp_set_current_user( $user_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/orders' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that a cashier without order edit capabilities cannot update orders.
	 *
	 * WordPress only requires edit_others_shop_orders (not edit_shop_orders)
	 * to edit another user's order. Both must be removed to fully block edits.
	 */
	public function test_cashier_without_edit_order_caps_cannot_update_orders(): void {
		wp_set_current_user( $this->user );
		$order = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();

		$user_id = $this->create_cashier_without( array( 'edit_shop_orders', 'edit_others_shop_orders' ) );
		wp_set_current_user( $user_id );

		$request = $this->wp_rest_patch_request( '/wcpos/v1/orders/' . $order->get_id() );
		$request->set_body_params( array( 'status' => 'completed' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that a cashier without list_users cannot read customers.
	 */
	public function test_cashier_without_list_users_cannot_read_customers(): void {
		$user_id = $this->create_cashier_without( array( 'list_users' ) );
		wp_set_current_user( $user_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/customers' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that a cashier without create_customers cannot create customers.
	 */
	public function test_cashier_without_create_customers_cannot_create_customers(): void {
		$user_id = $this->create_cashier_without( array( 'create_customers' ) );
		wp_set_current_user( $user_id );

		$request = $this->wp_rest_post_request( '/wcpos/v1/customers' );
		$request->set_body_params(
			array(
				'email'      => 'no-create-test@example.com',
				'first_name' => 'Test',
				'last_name'  => 'Customer',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that a cashier without edit_users cannot update customers.
	 */
	public function test_cashier_without_edit_users_cannot_update_customers(): void {
		wp_set_current_user( $this->user );
		$customer = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\CustomerHelper::create_customer();

		$user_id = $this->create_cashier_without( array( 'edit_users' ) );
		wp_set_current_user( $user_id );

		$request = $this->wp_rest_patch_request( '/wcpos/v1/customers/' . $customer->get_id() );
		$request->set_body_params( array( 'first_name' => 'Blocked' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that a cashier without access_woocommerce_pos cannot read taxes.
	 */
	public function test_cashier_without_access_pos_cannot_read_taxes(): void {
		$user_id = $this->create_cashier_without( array( 'access_woocommerce_pos' ) );
		wp_set_current_user( $user_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/taxes' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $user_id );
	}
}
