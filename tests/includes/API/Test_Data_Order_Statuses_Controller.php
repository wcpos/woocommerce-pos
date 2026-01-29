<?php
/**
 * Tests for the WCPOS Data Order Statuses API Controller.
 *
 * Tests the order statuses data REST API endpoints including:
 * - Route registration
 * - Permission checks
 * - Order status listing
 * - Status format (wc- prefix removal)
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Data_Order_Statuses_Controller;

/**
 * Test_Data_Order_Statuses_Controller class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Data_Order_Statuses_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The controller instance.
	 *
	 * @var Data_Order_Statuses_Controller
	 */
	protected $endpoint;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Data_Order_Statuses_Controller();
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

		$this->assertEquals( 'data/order_statuses', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wcpos/v1/data/order_statuses', $routes );
	}

	/**
	 * Test get_items requires authentication.
	 */
	public function test_get_items_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		// Should require authentication
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test get_items returns order statuses for authenticated user.
	 */
	public function test_get_items_returns_statuses(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );
	}

	/**
	 * Test returned statuses have correct structure.
	 */
	public function test_status_structure(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		// Check first status has expected keys
		$first_status = $data[0];
		$this->assertArrayHasKey( 'status', $first_status );
		$this->assertArrayHasKey( 'label', $first_status );
	}

	/**
	 * Test wc- prefix is removed from statuses.
	 */
	public function test_wc_prefix_removed(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		foreach ( $data as $status_data ) {
			$this->assertStringNotContainsString(
				'wc-',
				$status_data['status'],
				'Status should not contain wc- prefix'
			);
		}
	}

	/**
	 * Test includes WooCommerce default statuses.
	 */
	public function test_includes_default_statuses(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		$data     = $response->get_data();
		$statuses = wp_list_pluck( $data, 'status' );

		$this->assertContains( 'pending', $statuses );
		$this->assertContains( 'processing', $statuses );
		$this->assertContains( 'completed', $statuses );
		$this->assertContains( 'cancelled', $statuses );
	}

	/**
	 * Test includes POS custom statuses.
	 */
	public function test_includes_pos_statuses(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		$data     = $response->get_data();
		$statuses = wp_list_pluck( $data, 'status' );

		$this->assertContains( 'pos-open', $statuses, 'POS Open status should be included' );
		$this->assertContains( 'pos-partial', $statuses, 'POS Partial status should be included' );
	}

	/**
	 * Test status labels are not empty.
	 */
	public function test_status_labels_not_empty(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		foreach ( $data as $status_data ) {
			$this->assertNotEmpty( $status_data['label'], 'Status label should not be empty' );
		}
	}

	/**
	 * Test response includes links.
	 */
	public function test_response_includes_links(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		if ( ! empty( $data ) ) {
			$first_status = $data[0];
			$this->assertArrayHasKey( '_links', $first_status );
			$this->assertArrayHasKey( 'collection', $first_status['_links'] );
		}
	}

	/**
	 * Test schema has correct properties.
	 */
	public function test_schema_properties(): void {
		$schema = $this->endpoint->get_item_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'status', $schema['properties'] );
		$this->assertArrayHasKey( 'label', $schema['properties'] );
	}

	/**
	 * Test any logged-in user can view order statuses.
	 */
	public function test_any_logged_in_user_can_view(): void {
		// Create a subscriber (minimal role)
		$subscriber = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/data/order_statuses' );
		$response = $this->server->dispatch( $request );

		// Logged-in users should be able to view
		$this->assertEquals( 200, $response->get_status() );

		wp_delete_user( $subscriber );
	}
}
