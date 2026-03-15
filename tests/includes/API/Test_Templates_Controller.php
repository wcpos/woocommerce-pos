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
 * - Search/category/modified_after filters
 * - PATCH updates
 * - Batch updates
 * - Copy
 * - Install from gallery
 * - Preview
 * - Gallery listing
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
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
		delete_option( 'wcpos_template_order_receipt' );
		delete_option( 'wcpos_template_order_report' );
		delete_option( 'wcpos_disabled_virtual_templates_receipt' );
		delete_option( 'wcpos_disabled_virtual_templates_report' );
		remove_role( 'pos_cashier_test' );
	}

	/**
	 * Create a template post for testing.
	 *
	 * @param string $title  Template title.
	 * @param string $type   Template type slug.
	 * @param string $status Post status.
	 *
	 * @return int Post ID.
	 */
	private function create_template( string $title, string $type = 'receipt', string $status = 'publish' ): int {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => $status,
				'post_title'  => $title,
			)
		);
		wp_set_object_terms( $post_id, $type, 'wcpos_template_type' );

		// Set required meta so get_template works.
		update_post_meta( $post_id, '_template_engine', 'logicless' );
		update_post_meta( $post_id, '_template_output_type', 'html' );
		update_post_meta( $post_id, '_template_language', 'html' );
		update_post_meta( $post_id, '_template_tax_display', 'default' );

		return $post_id;
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
		$this->assertArrayHasKey( '/wcpos/v1/templates/gallery', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/templates/batch', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/templates/install', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/templates/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/templates/(?P<id>[\d]+)/copy', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/templates/(?P<id>[\w-]+)/preview', $routes );
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
		// Create a user without manage_woocommerce_pos capability.
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

		// Check headers.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
	}

	/**
	 * Test get_items respects per_page when virtual templates are included.
	 */
	public function test_get_items_respects_per_page_with_virtual_templates(): void {
		$virtual_templates = \WCPOS\WooCommercePOS\Templates::detect_filesystem_templates( 'receipt' );
		if ( empty( $virtual_templates ) ) {
			$this->markTestSkipped( 'No virtual templates available.' );
		}

		$post_id = $this->create_template( 'Per Page Contract Template' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'per_page', 1 );
		$request->set_param( 'page', 1 );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );

		wp_delete_post( $post_id, true );
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

		// Invalid type should return 400 (bad request).
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test get_items with per_page of 0.
	 */
	public function test_get_items_with_per_page_zero(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'per_page', 0 );
		$response = $this->server->dispatch( $request );

		// per_page=0 should be treated as invalid - either returns 400 or uses default.
		$this->assertContains( $response->get_status(), array( 200, 400 ) );
	}

	/**
	 * Test get_items with invalid context.
	 */
	public function test_get_items_with_invalid_context(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'context', 'invalid-context' );
		$response = $this->server->dispatch( $request );

		// Invalid context should fail validation.
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

		// Either returns a template (200) or no active template (404).
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

		// Either returns a template (200) or no active template (404).
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
		$this->assertArrayHasKey( 'search', $params );
		$this->assertArrayHasKey( 'category', $params );
		$this->assertArrayHasKey( 'modified_after', $params );
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
		// shop_manager should have manage_woocommerce_pos through custom caps.
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

	// ---- Cashier permission matrix tests ----

	/**
	 * Create a user with cashier-equivalent capabilities (access but not manage).
	 *
	 * Uses a temporary role so capability caching works reliably in tests.
	 *
	 * @return int User ID.
	 */
	private function create_cashier_user(): int {
		// Ensure a disposable 'pos_cashier_test' role exists with only access cap.
		remove_role( 'pos_cashier_test' );
		add_role(
			'pos_cashier_test',
			'POS Cashier Test',
			array(
				'read'                    => true,
				'access_woocommerce_pos'  => true,
			)
		);

		return $this->factory->user->create( array( 'role' => 'pos_cashier_test' ) );
	}

	/**
	 * Test cashier can list templates with view context.
	 */
	public function test_cashier_can_list_templates_view_context(): void {
		$cashier_id = $this->create_cashier_user();
		wp_set_current_user( $cashier_id );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'context', 'view' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		wp_delete_user( $cashier_id );
	}

	/**
	 * Test cashier cannot list templates with edit context.
	 */
	public function test_cashier_cannot_list_templates_edit_context(): void {
		$cashier_id = $this->create_cashier_user();
		wp_set_current_user( $cashier_id );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'context', 'edit' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_user( $cashier_id );
	}

	/**
	 * Test cashier can read a single template with view context.
	 */
	public function test_cashier_can_read_template_view_context(): void {
		$cashier_id = $this->create_cashier_user();
		wp_set_current_user( $cashier_id );

		$post_id = $this->create_template( 'Cashier View Template' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $post_id );
		$request->set_param( 'context', 'view' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		wp_delete_post( $post_id, true );
		wp_delete_user( $cashier_id );
	}

	/**
	 * Test cashier cannot read a single template with edit context.
	 */
	public function test_cashier_cannot_read_template_edit_context(): void {
		$cashier_id = $this->create_cashier_user();
		wp_set_current_user( $cashier_id );

		$post_id = $this->create_template( 'Cashier Edit Template' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $post_id );
		$request->set_param( 'context', 'edit' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_post( $post_id, true );
		wp_delete_user( $cashier_id );
	}

	/**
	 * Test cashier cannot preview a template.
	 */
	public function test_cashier_cannot_preview_template(): void {
		$cashier_id = $this->create_cashier_user();
		wp_set_current_user( $cashier_id );

		$post_id = $this->create_template( 'Cashier Preview Template' );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $post_id . '/preview' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_delete_post( $post_id, true );
		wp_delete_user( $cashier_id );
	}

	/**
	 * Test admin can list templates with edit context.
	 */
	public function test_admin_can_list_templates_edit_context(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'context', 'edit' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test admin can read a single template with edit context.
	 */
	public function test_admin_can_read_template_edit_context(): void {
		$post_id = $this->create_template( 'Admin Edit Template' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $post_id );
		$request->set_param( 'context', 'edit' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test admin can preview a template.
	 */
	public function test_admin_can_preview_template(): void {
		$post_id = $this->create_template( 'Admin Preview Template' );
		$order   = \Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $post_id . '/preview' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		wp_delete_post( $post_id, true );
		wp_delete_post( $order->get_id(), true );
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

	// ---- Task 5: Search and filter tests ----

	/**
	 * Test search filters templates by title.
	 */
	public function test_search_filters_templates_by_title(): void {
		$id_alpha = $this->create_template( 'Alpha Receipt' );
		$id_beta  = $this->create_template( 'Beta Invoice' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'search', 'Alpha' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data   = $response->get_data();
		$titles = array_column( $data, 'title' );

		$this->assertContains( 'Alpha Receipt', $titles );
		$this->assertNotContains( 'Beta Invoice', $titles );

		wp_delete_post( $id_alpha, true );
		wp_delete_post( $id_beta, true );
	}

	/**
	 * Test search filters templates by description meta.
	 */
	public function test_search_filters_templates_by_description(): void {
		$id_match = $this->create_template( 'Description Match Template' );
		$id_other = $this->create_template( 'Description Other Template' );

		update_post_meta( $id_match, '_template_description', 'Contains zebra keyword' );
		update_post_meta( $id_other, '_template_description', 'Contains unrelated keyword' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'search', 'zebra' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data   = $response->get_data();
		$titles = array_column( $data, 'title' );

		$this->assertContains( 'Description Match Template', $titles );
		$this->assertNotContains( 'Description Other Template', $titles );

		wp_delete_post( $id_match, true );
		wp_delete_post( $id_other, true );
	}

	/**
	 * Test category filter returns matching templates.
	 */
	public function test_category_filter_returns_matching_templates(): void {
		$id_receipt = $this->create_template( 'Category Receipt A' );
		$id_gift    = $this->create_template( 'Category Gift B' );

		wp_set_object_terms( $id_receipt, 'receipt', 'wcpos_template_category' );
		wp_set_object_terms( $id_gift, 'gift-receipt', 'wcpos_template_category' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$request->set_param( 'category', 'gift-receipt' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data   = $response->get_data();
		$titles = array_column( $data, 'title' );

		$this->assertContains( 'Category Gift B', $titles );
		$this->assertNotContains( 'Category Receipt A', $titles );

		wp_delete_post( $id_receipt, true );
		wp_delete_post( $id_gift, true );
	}

	// ---- Task 6: PATCH tests ----

	/**
	 * Test PATCH template updates status.
	 */
	public function test_patch_template_updates_status(): void {
		$post_id = $this->create_template( 'Status Test Template' );

		$request = $this->wp_rest_patch_request( '/wcpos/v1/templates/' . $post_id );
		$request->set_body_params( array( 'status' => 'draft' ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$post = get_post( $post_id );
		$this->assertEquals( 'draft', $post->post_status );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test PATCH template updates menu_order.
	 */
	public function test_patch_template_updates_menu_order(): void {
		$post_id = $this->create_template( 'Order Test Template' );

		$request = $this->wp_rest_patch_request( '/wcpos/v1/templates/' . $post_id );
		$request->set_body_params( array( 'menu_order' => 5 ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$post = get_post( $post_id );
		$this->assertEquals( 5, $post->menu_order );

		wp_delete_post( $post_id, true );
	}

	// ---- Task 7: Batch tests ----

	/**
	 * Test batch update multiple templates.
	 */
	public function test_batch_update_multiple_templates(): void {
		$id1 = $this->create_template( 'Batch A' );
		$id2 = $this->create_template( 'Batch B' );
		$id3 = $this->create_template( 'Batch C' );

		$request = $this->wp_rest_post_request( '/wcpos/v1/templates/batch' );
		$request->set_body_params(
			array(
				'update' => array(
					array(
						'id' => $id1,
						'status' => 'draft',
						'menu_order' => 1,
					),
					array(
						'id' => $id2,
						'status' => 'publish',
						'menu_order' => 2,
					),
					array(
						'id' => $id3,
						'menu_order' => 3,
					),
				),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'update', $data );
		$this->assertCount( 3, $data['update'] );

		$this->assertEquals( 'draft', get_post( $id1 )->post_status );
		$this->assertEquals( 'publish', get_post( $id2 )->post_status );
		$this->assertEquals( 3, get_post( $id3 )->menu_order );

		wp_delete_post( $id1, true );
		wp_delete_post( $id2, true );
		wp_delete_post( $id3, true );
	}

	/**
	 * Test batch update item validation for missing ids.
	 */
	public function test_batch_update_with_missing_id_returns_item_error(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/templates/batch' );
		$request->set_body_params(
			array(
				'update' => array(
					array( 'menu_order' => 20 ),
				),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test batch reorder saves ordered template IDs including virtual.
	 */
	public function test_batch_reorder_saves_order_with_virtual_ids(): void {
		$id1 = $this->create_template( 'Reorder A' );
		$id2 = $this->create_template( 'Reorder B' );

		$request = $this->wp_rest_post_request( '/wcpos/v1/templates/batch' );
		$request->set_body_params(
			array(
				'order' => array( 'plugin-core', $id2, $id1 ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$stored = \WCPOS\WooCommercePOS\Templates::get_template_order( 'receipt' );
		$this->assertEquals( array( 'plugin-core', $id2, $id1 ), $stored );

		wp_delete_post( $id1, true );
		wp_delete_post( $id2, true );
	}

	/**
	 * Test batch toggle virtual template disabled state.
	 */
	public function test_batch_toggle_virtual_template(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/templates/batch' );
		$request->set_body_params(
			array(
				'disable_virtual' => array( 'plugin-core' ),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( \WCPOS\WooCommercePOS\Templates::is_virtual_template_disabled( 'plugin-core' ) );

		// Now re-enable.
		$request2 = $this->wp_rest_post_request( '/wcpos/v1/templates/batch' );
		$request2->set_body_params(
			array(
				'enable_virtual' => array( 'plugin-core' ),
			)
		);
		$response2 = $this->server->dispatch( $request2 );

		$this->assertEquals( 200, $response2->get_status() );
		$this->assertFalse( \WCPOS\WooCommercePOS\Templates::is_virtual_template_disabled( 'plugin-core' ) );
	}

	// ---- Task 8: Copy and Install tests ----

	/**
	 * Test copy template creates a new post.
	 */
	public function test_copy_template_creates_new_post(): void {
		$original_id = $this->create_template( 'Original Template' );
		wp_update_post(
			array(
				'ID'           => $original_id,
				'post_content' => '<p>Template content here</p>',
			)
		);

		$request  = $this->wp_rest_post_request( '/wcpos/v1/templates/' . $original_id . '/copy' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertStringContainsString( 'Copy of Original Template', $data['title'] );
		$this->assertNotEquals( $original_id, $data['id'] );

		// The copy should be a draft.
		$copy_post = get_post( $data['id'] );
		$this->assertEquals( 'draft', $copy_post->post_status );

		wp_delete_post( $original_id, true );
		wp_delete_post( $data['id'], true );
	}

	/**
	 * Test install gallery template via API.
	 */
	public function test_install_gallery_template_via_api(): void {
		// Check if gallery templates are available.
		$gallery = \WCPOS\WooCommercePOS\Templates::get_gallery_templates();
		if ( empty( $gallery ) ) {
			$this->markTestSkipped( 'No gallery templates available.' );
		}

		$first_key = $gallery[0]['key'];

		$request = $this->wp_rest_post_request( '/wcpos/v1/templates/install' );
		$request->set_body_params( array( 'gallery_key' => $first_key ) );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertTrue( is_numeric( $data['id'] ) );

		wp_delete_post( $data['id'], true );
	}

	// ---- Task 9: Preview tests ----

	/**
	 * Test preview returns a URL.
	 */
	public function test_preview_returns_url(): void {
		$post_id = $this->create_template( 'Preview Template' );
		$order   = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $post_id . '/preview' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'preview_url', $data );
		$this->assertArrayHasKey( 'order_id', $data );
		$this->assertArrayHasKey( 'template_id', $data );
		$this->assertStringContainsString( '/wcpos-checkout/wcpos-receipt/' . $order->get_id(), $data['preview_url'] );

		$query = wp_parse_url( $data['preview_url'], PHP_URL_QUERY );
		parse_str( (string) $query, $query_params );
		$this->assertEquals( $order->get_order_key(), $query_params['key'] ?? '' );
		$this->assertEquals( (string) $post_id, (string) ( $query_params['wcpos_preview_template'] ?? '' ) );
		$this->assertEquals( $order->get_id(), $data['order_id'] );

		wp_delete_post( $post_id, true );
		wp_delete_post( $order->get_id(), true );
	}

	/**
	 * Test preview returns a URL for a gallery template key.
	 */
	public function test_preview_returns_url_for_gallery_template(): void {
		$gallery = \WCPOS\WooCommercePOS\Templates::get_gallery_templates();
		if ( empty( $gallery ) ) {
			$this->markTestSkipped( 'No gallery templates available.' );
		}

		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		$gallery_key = $gallery[0]['key'];
		$request     = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $gallery_key . '/preview' );
		$response    = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'preview_url', $data );
		$this->assertStringContainsString( 'wcpos_preview_template=' . $gallery_key, $data['preview_url'] );
		$this->assertEquals( $gallery_key, $data['template_id'] );

		wp_delete_post( $order->get_id(), true );
	}

	/**
	 * Test preview returns thermal data for thermal gallery template.
	 */
	public function test_preview_returns_thermal_data_for_thermal_template(): void {
		$gallery = \WCPOS\WooCommercePOS\Templates::get_gallery_templates();
		$thermal = null;

		foreach ( $gallery as $t ) {
			if ( 'thermal' === ( $t['engine'] ?? '' ) ) {
				$thermal = $t;
				break;
			}
		}

		if ( ! $thermal ) {
			$this->markTestSkipped( 'No thermal gallery templates available.' );
		}

		$order = OrderHelper::create_order( array( 'total' => 50 ) );
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		try {
			$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $thermal['key'] . '/preview' );
			$response = $this->server->dispatch( $request );

			$this->assertEquals( 200, $response->get_status() );

			$data = $response->get_data();
			$this->assertEquals( 'thermal', $data['engine'] );
			$this->assertArrayHasKey( 'template_content', $data );
			$this->assertArrayHasKey( 'receipt_data', $data );
			$this->assertStringContainsString( '<receipt', $data['template_content'] );
			$this->assertArrayHasKey( 'meta', $data['receipt_data'] );
			$this->assertArrayHasKey( 'lines', $data['receipt_data'] );
			// Money fields should be pre-formatted strings.
			$this->assertIsString( $data['receipt_data']['totals']['grand_total_incl'] );
		} finally {
			wp_delete_post( $order->get_id(), true );
		}
	}

	/**
	 * Test preview returns thermal data with mock data when no orders exist.
	 */
	public function test_preview_thermal_with_no_orders_uses_mock_data(): void {
		$gallery = \WCPOS\WooCommercePOS\Templates::get_gallery_templates();
		$thermal = null;

		foreach ( $gallery as $t ) {
			if ( 'thermal' === ( $t['engine'] ?? '' ) ) {
				$thermal = $t;
				break;
			}
		}

		if ( ! $thermal ) {
			$this->markTestSkipped( 'No thermal gallery templates available.' );
		}

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/' . $thermal['key'] . '/preview' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'thermal', $data['engine'] );
		$this->assertArrayHasKey( 'template_content', $data );
		$this->assertArrayHasKey( 'receipt_data', $data );
		// Mock data has order_id 1234.
		$this->assertEquals( 1234, $data['receipt_data']['meta']['order_id'] );
	}

	// ---- DELETE tests ----

	/**
	 * Test deleting a custom template permanently removes it.
	 */
	public function test_delete_custom_template(): void {
		$post_id = $this->create_template( 'Deletable Template' );

		$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/templates/' . $post_id );
		$request->set_header( 'X-WCPOS', '1' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['deleted'] );
		$this->assertEquals( $post_id, $data['id'] );

		// Confirm the post is actually gone.
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Test deleting a premade (non-virtual) template succeeds.
	 */
	public function test_delete_premade_template_succeeds(): void {
		$post_id = $this->create_template( 'Premade Template' );
		update_post_meta( $post_id, '_template_is_premade', '1' );

		$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/templates/' . $post_id );
		$request->set_header( 'X-WCPOS', '1' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['deleted'] );
		$this->assertEquals( $post_id, $data['id'] );

		// Confirm the post is actually gone.
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Test deleting a nonexistent template returns 404.
	 */
	public function test_delete_nonexistent_template_returns_404(): void {
		$request = new \WP_REST_Request( 'DELETE', '/wcpos/v1/templates/999999' );
		$request->set_header( 'X-WCPOS', '1' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	// ---- Sorted order and disabled state tests ----

	/**
	 * Test get_items returns templates sorted by stored order.
	 */
	public function test_get_items_sorted_by_stored_order(): void {
		$id1 = $this->create_template( 'Template A' );
		$id2 = $this->create_template( 'Template B' );
		$id3 = $this->create_template( 'Template C' );

		// Store custom order: C, A, B.
		\WCPOS\WooCommercePOS\Templates::save_template_order(
			array( $id3, $id1, $id2 ),
			'receipt'
		);

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$db_templates = array_values(
			array_filter(
				$data,
				function ( $t ) use ( $id1, $id2, $id3 ) {
					return \in_array( $t['id'], array( $id1, $id2, $id3 ), true );
				}
			)
		);

		$this->assertCount( 3, $db_templates );
		$this->assertEquals( $id3, $db_templates[0]['id'] );
		$this->assertEquals( $id1, $db_templates[1]['id'] );
		$this->assertEquals( $id2, $db_templates[2]['id'] );

		wp_delete_post( $id1, true );
		wp_delete_post( $id2, true );
		wp_delete_post( $id3, true );
	}

	/**
	 * Test get_items includes is_disabled for virtual templates.
	 */
	public function test_get_items_includes_is_disabled_for_virtual(): void {
		\WCPOS\WooCommercePOS\Templates::set_virtual_template_disabled( 'plugin-core', true );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$core = null;
		foreach ( $data as $t ) {
			if ( 'plugin-core' === ( $t['id'] ?? '' ) ) {
				$core = $t;
				break;
			}
		}

		$this->assertNotNull( $core );
		$this->assertArrayHasKey( 'is_disabled', $core );
		$this->assertTrue( $core['is_disabled'] );
	}

	/**
	 * Test templates not in stored order are appended at end.
	 */
	public function test_templates_not_in_order_appended(): void {
		$id1 = $this->create_template( 'Ordered Template' );
		$id2 = $this->create_template( 'Unordered Template' );

		// Only id1 is in the stored order.
		\WCPOS\WooCommercePOS\Templates::save_template_order(
			array( 'plugin-core', $id1 ),
			'receipt'
		);

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$ids  = array_column( $data, 'id' );

		// id1 should appear before id2.
		$pos1 = array_search( $id1, $ids, true );
		$pos2 = array_search( $id2, $ids, true );
		$this->assertNotFalse( $pos1 );
		$this->assertNotFalse( $pos2 );
		$this->assertLessThan( $pos2, $pos1 );

		wp_delete_post( $id1, true );
		wp_delete_post( $id2, true );
	}

	// ---- Gallery listing tests ----

	/**
	 * Test gallery endpoint returns premade templates.
	 */
	public function test_gallery_endpoint_returns_premade_templates(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates/gallery' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		if ( ! empty( $data ) ) {
			$keys = array_column( $data, 'key' );
			$this->assertContains( 'standard-receipt', $keys );

			// Verify content_file is stripped from the response.
			foreach ( $data as $template ) {
				$this->assertArrayNotHasKey( 'content_file', $template );
			}
		}
	}
}
