<?php
/**
 * Unit tests for Templates class.
 *
 * Tests virtual template detection, priority order, and active template management.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\Templates;
use WP_UnitTestCase;

/**
 * Class Test_Templates
 */
class Test_Templates extends WP_UnitTestCase {
	/**
	 * Test data directory for mock templates.
	 *
	 * @var string
	 */
	private $test_templates_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test templates directory.
		$this->test_templates_dir = sys_get_temp_dir() . '/wcpos-test-templates';
		if ( ! file_exists( $this->test_templates_dir ) ) {
			mkdir( $this->test_templates_dir, 0755, true );
		}

		// Clean up any active template options.
		delete_option( 'wcpos_active_template_receipt' );
		delete_option( 'wcpos_active_template_report' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up test templates directory.
		if ( file_exists( $this->test_templates_dir ) ) {
			$this->recursive_rmdir( $this->test_templates_dir );
		}

		// Clean up options.
		delete_option( 'wcpos_active_template_receipt' );
		delete_option( 'wcpos_active_template_report' );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_rmdir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			$objects = scandir( $dir );
			foreach ( $objects as $object ) {
				if ( '.' !== $object && '..' !== $object ) {
					$path = $dir . '/' . $object;
					if ( is_dir( $path ) ) {
						$this->recursive_rmdir( $path );
					} else {
						unlink( $path );
					}
				}
			}
			rmdir( $dir );
		}
	}

	/**
	 * Test that core template is detected when only core plugin exists.
	 */
	public function test_detect_core_template_when_only_core_exists(): void {
		// Core plugin should always have a receipt template.
		$templates = Templates::detect_filesystem_templates( 'receipt' );

		$this->assertNotEmpty( $templates, 'Should detect at least one template' );

		// Find the core template.
		$core_template = null;
		foreach ( $templates as $template ) {
			if ( Templates::TEMPLATE_PLUGIN_CORE === $template['id'] ) {
				$core_template = $template;
				break;
			}
		}

		$this->assertNotNull( $core_template, 'Core template should be detected' );
		$this->assertEquals( 'plugin', $core_template['source'] );
		$this->assertTrue( $core_template['is_virtual'] );
		$this->assertEquals( 'receipt', $core_template['type'] );
	}

	/**
	 * Test that virtual template path returns correct path for core template.
	 */
	public function test_get_virtual_template_path_for_core(): void {
		$path = Templates::get_virtual_template_path( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' );

		$this->assertNotNull( $path );
		$this->assertStringEndsWith( 'templates/receipt.php', $path );
		$this->assertTrue( file_exists( $path ) );
	}

	/**
	 * Test that theme template returns null when no theme template exists.
	 */
	public function test_theme_template_returns_null_when_not_exists(): void {
		$template = Templates::get_virtual_template( Templates::TEMPLATE_THEME, 'receipt' );

		// This should be null unless there's a theme template.
		// In test environment, there shouldn't be one.
		$this->assertNull( $template );
	}

	/**
	 * Test active template option storage.
	 */
	public function test_active_template_option_storage(): void {
		// Set active template to core plugin.
		$result = Templates::set_active_template_id( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' );

		$this->assertTrue( $result );

		// Verify it's stored in option.
		$stored = get_option( 'wcpos_active_template_receipt' );
		$this->assertEquals( Templates::TEMPLATE_PLUGIN_CORE, $stored );

		// Verify we can retrieve it.
		$active_id = Templates::get_active_template_id( 'receipt' );
		$this->assertEquals( Templates::TEMPLATE_PLUGIN_CORE, $active_id );
	}

	/**
	 * Test set active template by string ID.
	 */
	public function test_set_active_template_by_string_id(): void {
		// Set core template as active.
		$result = Templates::set_active_template_id( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' );

		$this->assertTrue( $result );
		$this->assertTrue( Templates::is_active_template( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' ) );
	}

	/**
	 * Test set active template by post ID.
	 */
	public function test_set_active_template_by_post_id(): void {
		// Create a custom template.
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'Test Template',
				'post_content' => '<?php echo "Test"; ?>',
			)
		);

		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		// Set it as active.
		$result = Templates::set_active_template_id( $post_id, 'receipt' );

		$this->assertTrue( $result );
		$this->assertTrue( Templates::is_active_template( $post_id, 'receipt' ) );

		// Verify string ID is not active.
		$this->assertFalse( Templates::is_active_template( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' ) );
	}

	/**
	 * Test fallback when active template is deleted.
	 */
	public function test_fallback_when_active_template_deleted(): void {
		// Create and set a custom template as active.
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'Test Template',
				'post_content' => '<?php echo "Test"; ?>',
			)
		);

		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );
		Templates::set_active_template_id( $post_id, 'receipt' );

		// Now delete the template.
		wp_delete_post( $post_id, true );

		// Get active template - should fallback to default.
		$active_id = Templates::get_active_template_id( 'receipt' );

		// Should get a virtual template ID since custom was deleted.
		$this->assertIsString( $active_id );
		$this->assertContains(
			$active_id,
			array( Templates::TEMPLATE_THEME, Templates::TEMPLATE_PLUGIN_PRO, Templates::TEMPLATE_PLUGIN_CORE )
		);

		// The option should have been cleaned up.
		$stored = get_option( 'wcpos_active_template_receipt', null );
		$this->assertNull( $stored );
	}

	/**
	 * Test that get_template returns correct data for database template.
	 */
	public function test_get_template_returns_correct_data(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'My Custom Template',
				'post_content' => '<?php echo "Custom"; ?>',
			)
		);

		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );
		update_post_meta( $post_id, '_template_language', 'php' );

		$template = Templates::get_template( $post_id );

		$this->assertIsArray( $template );
		$this->assertEquals( $post_id, $template['id'] );
		$this->assertEquals( 'My Custom Template', $template['title'] );
		$this->assertEquals( 'receipt', $template['type'] );
		$this->assertEquals( 'php', $template['language'] );
		$this->assertEquals( 'legacy-php', $template['engine'] );
		$this->assertEquals( 'html', $template['output_type'] );
		$this->assertFalse( $template['is_virtual'] );
		$this->assertEquals( 'custom', $template['source'] );
	}

	/**
	 * Test that get_virtual_template returns correct data.
	 */
	public function test_get_virtual_template_returns_correct_data(): void {
		$template = Templates::get_virtual_template( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' );

		$this->assertIsArray( $template );
		$this->assertEquals( Templates::TEMPLATE_PLUGIN_CORE, $template['id'] );
		$this->assertEquals( 'receipt', $template['type'] );
		$this->assertEquals( 'php', $template['language'] );
		$this->assertEquals( 'legacy-php', $template['engine'] );
		$this->assertEquals( 'html', $template['output_type'] );
		$this->assertTrue( $template['is_virtual'] );
		$this->assertEquals( 'plugin', $template['source'] );
		$this->assertNotEmpty( $template['content'] );
		$this->assertNotEmpty( $template['file_path'] );
	}

	/**
	 * Test get_active_template returns full template data.
	 */
	public function test_get_active_template_returns_full_data(): void {
		Templates::set_active_template_id( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' );

		$template = Templates::get_active_template( 'receipt' );

		$this->assertIsArray( $template );
		$this->assertEquals( Templates::TEMPLATE_PLUGIN_CORE, $template['id'] );
		$this->assertNotEmpty( $template['file_path'] );
	}

	/**
	 * Test that invalid template ID returns null.
	 */
	public function test_get_template_returns_null_for_invalid_id(): void {
		$template = Templates::get_template( 999999 );
		$this->assertNull( $template );
	}

	/**
	 * Test that invalid virtual ID returns null.
	 */
	public function test_get_virtual_template_returns_null_for_invalid_id(): void {
		$template = Templates::get_virtual_template( 'invalid-id', 'receipt' );
		$this->assertNull( $template );
	}

	/**
	 * Test set_active_template_id fails for invalid template.
	 */
	public function test_set_active_template_fails_for_invalid(): void {
		$result = Templates::set_active_template_id( 'invalid-id', 'receipt' );
		$this->assertFalse( $result );

		$result = Templates::set_active_template_id( 999999, 'receipt' );
		$this->assertFalse( $result );
	}

	/**
	 * Test default template priority order.
	 */
	public function test_default_template_follows_priority(): void {
		// Without Pro or theme templates, core should be default.
		$default = Templates::get_default_template( 'receipt' );

		$this->assertIsArray( $default );

		// If no theme exists (typically in test), first should be Pro (if exists) or Core.
		$valid_defaults = array(
			Templates::TEMPLATE_THEME,
			Templates::TEMPLATE_PLUGIN_PRO,
			Templates::TEMPLATE_PLUGIN_CORE,
		);

		$this->assertContains( $default['id'], $valid_defaults );
	}

	/**
	 * Test is_active_template comparison works for both types.
	 */
	public function test_is_active_template_comparison(): void {
		// Create a custom template.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'Test',
			)
		);
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		// Set custom as active.
		Templates::set_active_template_id( $post_id, 'receipt' );

		$this->assertTrue( Templates::is_active_template( $post_id, 'receipt' ) );
		$this->assertTrue( Templates::is_active_template( (string) $post_id, 'receipt' ) );
		$this->assertFalse( Templates::is_active_template( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' ) );

		// Now set virtual as active.
		Templates::set_active_template_id( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' );

		$this->assertTrue( Templates::is_active_template( Templates::TEMPLATE_PLUGIN_CORE, 'receipt' ) );
		$this->assertFalse( Templates::is_active_template( $post_id, 'receipt' ) );
	}

	/**
	 * Test that template category taxonomy exists.
	 */
	public function test_template_category_taxonomy_exists(): void {
		$this->assertTrue( taxonomy_exists( 'wcpos_template_category' ) );
	}

	/**
	 * Test that default template categories are registered.
	 */
	public function test_default_categories_registered(): void {
		// Re-trigger registration to insert terms within this test's transaction.
		new Templates();

		$receipt = term_exists( 'receipt', 'wcpos_template_category' );
		$this->assertNotNull( $receipt );

		$invoice = term_exists( 'invoice', 'wcpos_template_category' );
		$this->assertNotNull( $invoice );

		$gift_receipt = term_exists( 'gift-receipt', 'wcpos_template_category' );
		$this->assertNotNull( $gift_receipt );

		$credit_note = term_exists( 'credit-note', 'wcpos_template_category' );
		$this->assertNotNull( $credit_note );

		$kitchen_ticket = term_exists( 'kitchen-ticket', 'wcpos_template_category' );
		$this->assertNotNull( $kitchen_ticket );
	}

	/**
	 * Test get_gallery_templates returns an array.
	 */
	public function test_get_gallery_templates_returns_array(): void {
		$templates = Templates::get_gallery_templates();
		$this->assertIsArray( $templates );
	}

	/**
	 * Test get_gallery_templates finds standard-receipt.
	 */
	public function test_get_gallery_templates_finds_standard_receipt(): void {
		$templates = Templates::get_gallery_templates();
		$keys      = array_column( $templates, 'key' );
		$this->assertContains( 'standard-receipt', $keys );
	}

	/**
	 * Test gallery template has all required fields.
	 */
	public function test_gallery_template_has_required_fields(): void {
		$templates = Templates::get_gallery_templates();
		$template  = $templates[0];

		$this->assertArrayHasKey( 'key', $template );
		$this->assertArrayHasKey( 'title', $template );
		$this->assertArrayHasKey( 'description', $template );
		$this->assertArrayHasKey( 'type', $template );
		$this->assertArrayHasKey( 'category', $template );
		$this->assertArrayHasKey( 'engine', $template );
		$this->assertArrayHasKey( 'content', $template );
		$this->assertArrayHasKey( 'version', $template );
	}

	/**
	 * Test that get_template includes description field.
	 */
	public function test_get_template_includes_description(): void {
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'wcpos_template',
			'post_status' => 'publish',
			'post_title'  => 'Test Template',
		) );
		update_post_meta( $post_id, '_template_description', 'A test description.' );
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		$template = \WCPOS\WooCommercePOS\Templates::get_template( $post_id );

		$this->assertArrayHasKey( 'description', $template );
		$this->assertEquals( 'A test description.', $template['description'] );
	}

	/**
	 * Test that get_template includes gallery source fields.
	 */
	public function test_get_template_includes_gallery_source_fields(): void {
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'wcpos_template',
			'post_status' => 'publish',
			'post_title'  => 'Test Template',
		) );
		update_post_meta( $post_id, '_template_is_premade', '1' );
		update_post_meta( $post_id, '_template_gallery_version', '2' );
		update_post_meta( $post_id, '_template_gallery_key', 'standard-receipt' );
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		$template = \WCPOS\WooCommercePOS\Templates::get_template( $post_id );

		$this->assertTrue( $template['is_premade'] );
		$this->assertEquals( 2, $template['gallery_version'] );
		$this->assertEquals( 'standard-receipt', $template['gallery_key'] );
	}

	/**
	 * Test that get_template includes tax_display field.
	 */
	public function test_get_template_includes_tax_display(): void {
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'wcpos_template',
			'post_status' => 'publish',
			'post_title'  => 'Test Template',
		) );
		update_post_meta( $post_id, '_template_tax_display', 'incl' );
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		$template = \WCPOS\WooCommercePOS\Templates::get_template( $post_id );

		$this->assertEquals( 'incl', $template['tax_display'] );
	}

	/**
	 * Test install_gallery_template creates a post.
	 */
	public function test_install_gallery_template_creates_post(): void {
		$post_id = \WCPOS\WooCommercePOS\Templates::install_gallery_template( 'standard-receipt' );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertEquals( 'wcpos_template', $post->post_type );
		$this->assertEquals( 'Standard Receipt', $post->post_title );
		$this->assertNotEmpty( $post->post_content );
	}

	/**
	 * Test install_gallery_template sets metadata.
	 */
	public function test_install_gallery_template_sets_metadata(): void {
		$post_id = \WCPOS\WooCommercePOS\Templates::install_gallery_template( 'standard-receipt' );

		$this->assertTrue( (bool) get_post_meta( $post_id, '_template_is_premade', true ) );
		$this->assertEquals( 'standard-receipt', get_post_meta( $post_id, '_template_gallery_key', true ) );
		$this->assertEquals( 1, (int) get_post_meta( $post_id, '_template_gallery_version', true ) );
		$this->assertEquals( 'logicless', get_post_meta( $post_id, '_template_engine', true ) );
	}

	/**
	 * Test install_gallery_template sets taxonomies.
	 */
	public function test_install_gallery_template_sets_taxonomies(): void {
		$post_id = \WCPOS\WooCommercePOS\Templates::install_gallery_template( 'standard-receipt' );

		$type_terms = wp_get_post_terms( $post_id, 'wcpos_template_type' );
		$this->assertEquals( 'receipt', $type_terms[0]->slug );

		$cat_terms = wp_get_post_terms( $post_id, 'wcpos_template_category' );
		$this->assertEquals( 'receipt', $cat_terms[0]->slug );
	}

	/**
	 * Test install_gallery_template returns error for invalid key.
	 */
	public function test_install_gallery_template_invalid_key_returns_error(): void {
		$result = \WCPOS\WooCommercePOS\Templates::install_gallery_template( 'nonexistent' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test legacy set_active_template method.
	 */
	public function test_legacy_set_active_template_method(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'Test',
			)
		);
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		// Use legacy method.
		$result = Templates::set_active_template( $post_id );

		$this->assertTrue( $result );
		$this->assertTrue( Templates::is_active_template( $post_id, 'receipt' ) );
	}
}
