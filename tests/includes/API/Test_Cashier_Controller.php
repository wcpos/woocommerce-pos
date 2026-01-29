<?php
/**
 * Tests for the WCPOS Cashier API Controller.
 *
 * Tests the cashier REST API endpoints including:
 * - Route registration
 * - Permission checks (authentication, own data access)
 * - Cashier data retrieval
 * - Store access endpoints
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Cashier;

/**
 * Test_Cashier_Controller class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Cashier_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The Cashier controller instance.
	 *
	 * @var Cashier
	 */
	protected $endpoint;

	/**
	 * A second user for permission tests.
	 *
	 * @var int
	 */
	protected $other_user;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Cashier();

		// Create another user for permission tests
		$this->other_user = $this->factory->user->create(
			array(
				'role' => 'shop_manager',
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->other_user ) {
			wp_delete_user( $this->other_user );
		}
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

		$this->assertEquals( 'cashier', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wcpos/v1/cashier/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/cashier/(?P<id>[\d]+)/stores', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/cashier/(?P<id>[\d]+)/stores/(?P<store_id>[\d]+)', $routes );
	}

	/**
	 * Test get_cashier endpoint returns data for own user.
	 */
	public function test_get_cashier_own_user(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'uuid', $data );
		$this->assertArrayHasKey( 'username', $data );
		$this->assertArrayHasKey( 'email', $data );
		$this->assertArrayHasKey( 'first_name', $data );
		$this->assertArrayHasKey( 'last_name', $data );
		$this->assertArrayHasKey( 'display_name', $data );
		$this->assertArrayHasKey( 'avatar_url', $data );
		$this->assertArrayHasKey( 'stores', $data );

		$this->assertEquals( $this->user, $data['id'] );
	}

	/**
	 * Test get_cashier endpoint requires authentication.
	 */
	public function test_get_cashier_requires_authentication(): void {
		// Log out
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test get_cashier endpoint returns 404 for non-existent user.
	 */
	public function test_get_cashier_returns_404_for_non_existent_user(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test get_cashier endpoint prevents access to other user's data for non-managers.
	 *
	 * Note: Users with 'manage_woocommerce' capability (like shop_manager) CAN access
	 * other users' cashier data. Only users without this capability are restricted.
	 */
	public function test_get_cashier_prevents_access_to_other_user(): void {
		// Create a user without manage_woocommerce capability
		$editor = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		// Grant publish_shop_orders to make them a "cashier" without manage_woocommerce
		$user = get_user_by( 'id', $editor );
		$user->add_cap( 'publish_shop_orders' );
		wp_set_current_user( $editor );

		// Try to access another user's data
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->other_user );
		$response = $this->server->dispatch( $request );

		// Should be 403 Forbidden (editor can't access other user's data)
		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $editor );
	}

	/**
	 * Test admin can access other user's cashier data.
	 */
	public function test_admin_can_access_other_user_data(): void {
		// Current user is admin (set up in parent)
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->other_user );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $this->other_user, $data['id'] );
	}

	/**
	 * Test get_cashier_stores endpoint returns stores.
	 */
	public function test_get_cashier_stores(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user . '/stores' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// Check headers
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
	}

	/**
	 * Test get_cashier_stores requires authentication.
	 */
	public function test_get_cashier_stores_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user . '/stores' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test get_cashier_store endpoint for specific store.
	 */
	public function test_get_cashier_store(): void {
		// Get available stores first
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user . '/stores' );
		$response = $this->server->dispatch( $request );
		$stores   = $response->get_data();

		if ( empty( $stores ) ) {
			$this->markTestSkipped( 'No stores available for testing' );
		}

		$store_id = $stores[0]['id'];

		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user . '/stores/' . $store_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $store_id, $data['id'] );
	}

	/**
	 * Test get_cashier_store returns 404 for non-existent store.
	 */
	public function test_get_cashier_store_returns_404_for_non_existent_store(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user . '/stores/999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test cashier data includes UUID.
	 */
	public function test_cashier_data_includes_uuid(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'uuid', $data );
		$this->assertNotEmpty( $data['uuid'] );
		// UUID should be in valid format
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$data['uuid']
		);
	}

	/**
	 * Test cashier data UUID is consistent across requests.
	 */
	public function test_cashier_uuid_is_consistent(): void {
		// First request
		$request1  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user );
		$response1 = $this->server->dispatch( $request1 );
		$data1     = $response1->get_data();

		// Second request
		$request2  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user );
		$response2 = $this->server->dispatch( $request2 );
		$data2     = $response2->get_data();

		$this->assertEquals( $data1['uuid'], $data2['uuid'] );
	}

	/**
	 * Test user without POS permissions gets 403.
	 */
	public function test_user_without_pos_permissions_gets_403(): void {
		// Create a subscriber (no POS permissions)
		$subscriber = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );

		// Try to access their own cashier data
		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $subscriber );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $subscriber );
	}

	/**
	 * Test cashier data filter is applied.
	 */
	public function test_cashier_data_filter_applied(): void {
		add_filter(
			'woocommerce_pos_rest_prepare_cashier',
			function ( $data, $user, $request ) {
				$data['custom_field'] = 'test_value';

				return $data;
			},
			10,
			3
		);

		$request  = $this->wp_rest_get_request( '/wcpos/v1/cashier/' . $this->user );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'custom_field', $data );
		$this->assertEquals( 'test_value', $data['custom_field'] );

		remove_all_filters( 'woocommerce_pos_rest_prepare_cashier' );
	}
}
