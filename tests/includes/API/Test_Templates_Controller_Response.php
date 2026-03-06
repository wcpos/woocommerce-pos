<?php
/**
 * Tests for Templates_Controller response formatting.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Templates_Controller;
use WP_REST_Request;

/**
 * Test_Templates_Controller_Response class.
 */
class Test_Templates_Controller_Response extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Test logicless template includes content in view context.
	 */
	public function test_logicless_template_includes_content_in_view_context(): void {
		$template = array(
			'id'      => 'test',
			'title'   => 'Test',
			'content' => '<p>{{store.name}}</p>',
			'engine'  => 'logicless',
			'type'    => 'receipt',
		);

		$controller = new Templates_Controller();
		$request    = new WP_REST_Request( 'GET', '/wcpos/v1/templates' );
		$request->set_param( 'context', 'view' );

		$result = $controller->prepare_item_for_response( $template, $request );

		$this->assertTrue( $result['offline_capable'] );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertEquals( '<p>{{store.name}}</p>', $result['content'] );
	}

	/**
	 * Test PHP template excludes content in view context.
	 */
	public function test_php_template_excludes_content_in_view_context(): void {
		$template = array(
			'id'      => 'test',
			'title'   => 'Test',
			'content' => '<?php echo "hello"; ?>',
			'engine'  => 'legacy-php',
			'type'    => 'receipt',
		);

		$controller = new Templates_Controller();
		$request    = new WP_REST_Request( 'GET', '/wcpos/v1/templates' );
		$request->set_param( 'context', 'view' );

		$result = $controller->prepare_item_for_response( $template, $request );

		$this->assertFalse( $result['offline_capable'] );
		$this->assertArrayNotHasKey( 'content', $result );
	}

	/**
	 * Test PHP template includes content in edit context.
	 */
	public function test_php_template_includes_content_in_edit_context(): void {
		$template = array(
			'id'      => 'test',
			'title'   => 'Test',
			'content' => '<?php echo "hello"; ?>',
			'engine'  => 'legacy-php',
			'type'    => 'receipt',
		);

		$controller = new Templates_Controller();
		$request    = new WP_REST_Request( 'GET', '/wcpos/v1/templates' );
		$request->set_param( 'context', 'edit' );

		$result = $controller->prepare_item_for_response( $template, $request );

		$this->assertArrayHasKey( 'content', $result );
	}

	/**
	 * Test menu_order defaults to zero.
	 */
	public function test_menu_order_defaults_to_zero(): void {
		$template = array(
			'id'     => 'test',
			'title'  => 'Test',
			'engine' => 'legacy-php',
			'type'   => 'receipt',
		);

		$controller = new Templates_Controller();
		$request    = new WP_REST_Request( 'GET', '/wcpos/v1/templates' );

		$result = $controller->prepare_item_for_response( $template, $request );

		$this->assertEquals( 0, $result['menu_order'] );
	}

	/**
	 * Test database templates include date_modified_gmt in REST response.
	 */
	public function test_get_items_returns_date_modified_gmt(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'wcpos_template',
				'post_title'  => 'Test Template',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$db_template = null;
		foreach ( $data as $template ) {
			if ( isset( $template['id'] ) && $template['id'] === $post_id ) {
				$db_template = $template;
				break;
			}
		}

		$this->assertNotNull( $db_template, 'Database template should be in response' );
		$this->assertArrayHasKey( 'date_modified_gmt', $db_template );
		$this->assertNotEmpty( $db_template['date_modified_gmt'] );
	}

	/**
	 * Test virtual (filesystem) templates include date_modified_gmt in REST response.
	 */
	public function test_virtual_templates_have_date_modified_gmt(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/templates' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$virtual = null;
		foreach ( $data as $template ) {
			if ( isset( $template['is_virtual'] ) && $template['is_virtual'] ) {
				$virtual = $template;
				break;
			}
		}

		$this->assertNotNull( $virtual, 'Virtual template should be in response' );
		$this->assertArrayHasKey( 'date_modified_gmt', $virtual );
		$this->assertNotEmpty( $virtual['date_modified_gmt'] );
	}
}
