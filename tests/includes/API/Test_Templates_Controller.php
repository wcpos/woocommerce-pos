<?php
/**
 * Tests for the WCPOS Templates API Controller.
 *
 * Tests the templates REST API endpoints including:
 * - Route registration
 * - Permission checks
 * - Template listing (virtual and database)
 * - Single template retrieval
 * - Active template retrieval
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Templates_Controller;

/**
 * Test_Templates_Controller class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Templates_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The Templates controller instance.
	 *
	 * @var Templates_Controller
	 */
	protected $endpoint;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Templates_Controller();
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

		$this->assertEquals( 'templates', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wcpos/v1/templates', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/templates/(?P<id>[\w-]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/templates/active', $routes );
	}

	/**
	 * Test get_items requires authentication.
	 */
	public function test_get_items_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );

		// Baseline gate returns 403 for unauthenticated users (no access_woocommerce_pos).
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test get_items requires manage_woocommerce_pos capability.
	 */
	public function test_get_items_requires_capability(): void {
		// Create a user without manage_woocommerce_pos capability
		$subscriber = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $subscriber );
	}

	/**
	 * Test get_items returns templates for authorized user.
	 */
	public function test_get_items_returns_templates(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		if ( ! empty( $data ) ) {
			$this->assertArrayHasKey( 'engine', $data[0] );
			$this->assertArrayHasKey( 'output_type', $data[0] );
		}

		// Check headers
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
	}

	/**
	 * Test virtual template metadata defaults.
	 */
	public function test_virtual_template_includes_engine_and_output_type(): void {
		$template = \WCPOS\WooCommercePOS\Templates::get_virtual_template( 'plugin-core', 'receipt' );
		if ( ! $template ) {
			$this->markTestSkipped( 'No core virtual receipt template found.' );
		}

		$this->assertEquals( 'legacy-php', $template['engine'] );
		$this->assertEquals( 'html', $template['output_type'] );
	}

	/**
	 * Test get_items can filter by type.
	 */
	public function test_get_items_filter_by_type(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'type', 'receipt' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test get_items with invalid type filter returns error.
	 */
	public function test_get_items_with_invalid_type(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'type', 'invalid-type-xyz' );
		$response = $this->server->dispatch( $request );

		// Invalid type should return 400 (bad request)
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test get_items with per_page of 0.
	 */
	public function test_get_items_with_per_page_zero(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'per_page', 0 );
		$response = $this->server->dispatch( $request );

		// per_page=0 should be treated as invalid - either returns 400 or uses default
		$this->assertContains( $response->get_status(), array( 200, 400 ) );
	}

	/**
	 * Test get_items with invalid context.
	 */
	public function test_get_items_with_invalid_context(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'context', 'invalid-context' );
		$response = $this->server->dispatch( $request );

		// Invalid context should fail validation
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test get_item requires authentication.
	 */
	public function test_get_item_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/default-receipt' );
		$response = $this->server->dispatch( $request );

		// Baseline gate returns 403 for unauthenticated users (no access_woocommerce_pos).
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test get_item returns 404 for non-existent template.
	 */
	public function test_get_item_returns_404_for_non_existent(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/non-existent-template-id' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test get_active requires authentication.
	 */
	public function test_get_active_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/active' );
		$response = $this->server->dispatch( $request );

		// Baseline gate returns 403 for unauthenticated users (no access_woocommerce_pos).
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test get_active returns active template.
	 */
	public function test_get_active_returns_template(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/active' );
		$response = $this->server->dispatch( $request );

		// Either returns a template (200) or no active template (404)
		$this->assertContains( $response->get_status(), array( 200, 404 ) );

		if ( 200 === $response->get_status() ) {
			$data = $response->get_data();
			$this->assertArrayHasKey( 'id', $data );
			$this->assertTrue( $data['is_active'] );
		}
	}

	/**
	 * Test get_active can filter by type.
	 */
	public function test_get_active_filter_by_type(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates/active' );
		$request->set_param( 'type', 'receipt' );
		$response = $this->server->dispatch( $request );

		// Either returns a template (200) or no active template (404)
		$this->assertContains( $response->get_status(), array( 200, 404 ) );
	}

	/**
	 * Test collection params include expected parameters.
	 */
	public function test_collection_params(): void {
		$params = $this->endpoint->get_collection_params();

		$this->assertArrayHasKey( 'page', $params );
		$this->assertArrayHasKey( 'per_page', $params );
		$this->assertArrayHasKey( 'type', $params );
		$this->assertArrayHasKey( 'context', $params );
	}

	/**
	 * Test type parameter has correct enum values.
	 */
	public function test_type_enum_values(): void {
		$params = $this->endpoint->get_collection_params();

		$this->assertEquals( array( 'receipt', 'report' ), $params['type']['enum'] );
	}

	/**
	 * Test context parameter has correct enum values.
	 */
	public function test_context_enum_values(): void {
		$params = $this->endpoint->get_collection_params();

		$this->assertEquals( array( 'view', 'edit' ), $params['context']['enum'] );
	}

	/**
	 * Test default type is receipt.
	 */
	public function test_default_type_is_receipt(): void {
		$params = $this->endpoint->get_collection_params();

		$this->assertEquals( 'receipt', $params['type']['default'] );
	}

	/**
	 * Test shop_manager can access templates.
	 */
	public function test_shop_manager_can_access_templates(): void {
		// shop_manager should have manage_woocommerce_pos through custom caps
		$manager_id = $this->factory->user->create(
			array(
				'role' => 'shop_manager',
			)
		);
		$manager = get_user_by( 'id', $manager_id );
		$manager->add_cap( 'manage_woocommerce_pos' );
		wp_set_current_user( $manager_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		wp_delete_user( $manager_id );
	}

	/**
	 * Test template response excludes content in view context.
	 */
	public function test_template_excludes_content_in_view_context(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'context', 'view' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		if ( ! empty( $data ) ) {
			$first_template = $data[0];
			$this->assertArrayNotHasKey( 'content', $first_template );
		}
	}
}
