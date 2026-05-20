<?php
/**
 * Unit tests for Templates class.
 *
 * Tests virtual template detection, priority order, and active template management.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WCPOS\WooCommercePOS\Services\Receipt_I18n_Labels;
use WCPOS\WooCommercePOS\Templates;
use WCPOS\WooCommercePOS\Templates\Renderers\Legacy_Php_Renderer;
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
		delete_option( 'wcpos_template_order_receipt' );
		delete_option( 'wcpos_template_order_report' );
		delete_option( 'wcpos_disabled_virtual_templates_receipt' );
		delete_option( 'wcpos_disabled_virtual_templates_report' );
		remove_all_filters( 'woocommerce_pos_wp_overnight_pdf_templates_enabled' );
		remove_all_filters( 'woocommerce_pos_wp_overnight_pdf_document' );
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
	 * Test gallery templates do not reference fields absent from canonical receipt data.
	 */
	public function test_gallery_templates_do_not_reference_orphaned_receipt_fields(): void {
		$gallery_dir    = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery';
		$html_templates = glob( $gallery_dir . '/*.html' );
		$xml_templates  = glob( $gallery_dir . '/*.xml' );
		$templates      = array_values(
			array_filter(
				array_merge(
					false === $html_templates ? array() : $html_templates,
					false === $xml_templates ? array() : $xml_templates
				),
				static function ( $template ): bool {
					return is_string( $template ) && is_readable( $template );
				}
			)
		);

		$this->assertNotEmpty( $templates, 'No readable gallery templates found in ' . $gallery_dir );

		foreach ( $templates as $template ) {
			$content = file_get_contents( $template );
			$label   = basename( $template );

			$this->assertNotFalse( $content, 'Unable to read gallery template: ' . $template );
			$this->assertStringNotContainsString( 'tax_summary.0', $content, $label );
			$this->assertStringNotContainsString( 'i18n.order_date', $content, $label );
			$this->assertDoesNotMatchRegularExpression( '/store\\.tax_id(?!s)/', $content, $label );
		}
	}

	/**
	 * Test invoice template hides paid-payment summary while payment is still due.
	 */
	public function test_invoice_template_hides_payments_when_order_needs_payment(): void {
		$rendered = $this->render_invoice_template(
			array(
				'needs_payment' => true,
				'payment_url'   => 'https://example.test/pay',
			)
		);

		$this->assertStringContainsString( 'Bank transfer', $rendered );
		$this->assertStringNotContainsString( 'Paid via Cash', $rendered );
	}

	/**
	 * Test invoice template renders localized paid-payment summary for paid orders.
	 */
	public function test_invoice_template_renders_paid_via_label_for_paid_orders(): void {
		$rendered = $this->render_invoice_template(
			array(
				'needs_payment' => false,
				'payment_url'   => '',
			)
		);

		$this->assertStringContainsString( 'Paid via Cash', $rendered );
		$this->assertStringNotContainsString( 'Bank transfer', $rendered );
	}

	/**
	 * Render the invoice gallery template with minimal receipt data.
	 *
	 * @param array $order_overrides Nested data overrides for the order section.
	 */
	private function render_invoice_template( array $order_overrides = array() ): string {
		$template = file_get_contents( \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/invoice.html' );

		if ( false === $template ) {
			$this->fail( 'Invoice template must be readable.' );
		}

		$mustache = new \Mustache\Engine();
		$data     = array(
			'store'    => array( 'name' => 'Test Store' ),
			'customer' => array( 'name' => 'Ada Lovelace' ),
			'order'    => array_replace(
				array(
					'number'        => '1001',
					'needs_payment' => false,
					'payment_url'   => '',
					'created'       => array( 'datetime' => '2026-05-11 10:00' ),
					'status_label'  => 'Completed',
				),
				$order_overrides
			),
			'totals'   => array(
				'subtotal_incl_display' => '$10.00',
				'total_incl_display'    => '$10.00',
			),
			'payments' => array(
				array(
					'method_title'   => 'Cash',
					'transaction_id' => '',
					'amount_display' => '$10.00',
				),
			),
			'i18n'     => Receipt_I18n_Labels::get_labels(),
		);

		return $mustache->render( $template, $data );
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
	 * Test WP Overnight templates are not listed when the integration is unavailable.
	 */
	public function test_wp_overnight_templates_not_detected_when_integration_unavailable(): void {
		add_filter( 'woocommerce_pos_wp_overnight_pdf_templates_enabled', '__return_false' );

		$templates = Templates::detect_filesystem_templates( 'receipt' );
		$ids       = array_column( $templates, 'id' );

		$this->assertNotContains( 'wp-overnight-invoice', $ids );
		$this->assertNotContains( 'wp-overnight-packing-slip', $ids );
	}

	/**
	 * Test WP Overnight invoice and packing slip templates are listed when the integration is available.
	 */
	public function test_wp_overnight_templates_detected_when_integration_available(): void {
		add_filter( 'woocommerce_pos_wp_overnight_pdf_templates_enabled', '__return_true' );

		$templates = Templates::detect_filesystem_templates( 'receipt' );
		$by_id     = array_column( $templates, null, 'id' );

		$this->assertArrayHasKey( 'wp-overnight-invoice', $by_id );
		$this->assertEquals( 'Invoice (WP Overnight)', $by_id['wp-overnight-invoice']['title'] );
		$this->assertEquals( 'invoice', $by_id['wp-overnight-invoice']['category'] );
		$this->assertEquals( 'legacy-php', $by_id['wp-overnight-invoice']['engine'] );
		$this->assertEquals( 'html', $by_id['wp-overnight-invoice']['output_type'] );
		$this->assertStringEndsWith( 'templates/wp-overnight-invoice.php', $by_id['wp-overnight-invoice']['file_path'] );

		$this->assertArrayHasKey( 'wp-overnight-packing-slip', $by_id );
		$this->assertEquals( 'Packing Slip (WP Overnight)', $by_id['wp-overnight-packing-slip']['title'] );
		$this->assertEquals( 'receipt', $by_id['wp-overnight-packing-slip']['category'] );
		$this->assertEquals( 'legacy-php', $by_id['wp-overnight-packing-slip']['engine'] );
		$this->assertEquals( 'html', $by_id['wp-overnight-packing-slip']['output_type'] );
		$this->assertStringEndsWith( 'templates/wp-overnight-packing-slip.php', $by_id['wp-overnight-packing-slip']['file_path'] );
	}

	/**
	 * Test the WP Overnight invoice template delegates rendering to the document API.
	 */
	public function test_wp_overnight_invoice_template_renders_document_html(): void {
		add_filter( 'woocommerce_pos_wp_overnight_pdf_templates_enabled', '__return_true' );
		add_filter(
			'woocommerce_pos_wp_overnight_pdf_document',
			static function ( $document, string $document_type, $order ) {
				$GLOBALS['wcpos_wcpdf_get_document_calls'][] = array(
					'document_type' => $document_type,
					'order'         => $order,
				);

				return new class() {
					public function get_html() {
						return '<main>WP Overnight invoice HTML</main>';
					}
				};
			},
			10,
			3
		);

		$GLOBALS['wcpos_wcpdf_get_document_calls'] = array();
		$template                                  = Templates::get_virtual_template( 'wp-overnight-invoice', 'receipt' );
		$order                                     = wc_create_order();
		$renderer                                  = new Legacy_Php_Renderer();

		ob_start();
		$renderer->render( $template, $order, array() );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'WP Overnight invoice HTML', $html );
		$this->assertCount( 1, $GLOBALS['wcpos_wcpdf_get_document_calls'] );
		$this->assertEquals( 'invoice', $GLOBALS['wcpos_wcpdf_get_document_calls'][0]['document_type'] );
		$this->assertSame( $order, $GLOBALS['wcpos_wcpdf_get_document_calls'][0]['order'] );
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
	 * Test legacy PHP starter templates are no longer registered.
	 */
	public function test_legacy_php_starter_templates_are_not_registered(): void {
		$starters = Templates::get_starter_templates();
		$keys     = array_keys( $starters );

		$this->assertNotContains( 'gift-receipt', $keys );
		$this->assertNotContains( 'minimal-receipt', $keys );
		$this->assertNotContains( 'thermal-receipt', $keys );
		$this->assertNotContains( 'simple-receipt', $keys );
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

		$purchase_order = term_exists( 'purchase-order', 'wcpos_template_category' );
		$this->assertNotNull( $purchase_order );

		$bar_ticket = term_exists( 'bar-ticket', 'wcpos_template_category' );
		$this->assertNotNull( $bar_ticket );
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
		$this->assertNotEmpty( $templates, 'Expected at least one gallery template.' );
		$template  = $templates[0];

		$this->assertArrayHasKey( 'key', $template );
		$this->assertArrayHasKey( 'title', $template );
		$this->assertArrayHasKey( 'description', $template );
		$this->assertArrayHasKey( 'type', $template );
		$this->assertArrayHasKey( 'category', $template );
		$this->assertArrayHasKey( 'engine', $template );
		$this->assertArrayHasKey( 'content', $template );
		$this->assertArrayHasKey( 'version', $template );
		$this->assertArrayHasKey( 'preview_data', $template );
	}

	/**
	 * Test every gallery preview_data profile has a matching JSON fixture.
	 */
	public function test_gallery_template_preview_data_profiles_have_fixture_files(): void {
		$templates = Templates::get_gallery_templates();
		$this->assertNotEmpty( $templates, 'Expected at least one gallery template.' );

		foreach ( $templates as $template ) {
			$profile = $template['preview_data'] ?? '';
			$this->assertNotEmpty( $profile, $template['key'] . ' should declare preview_data.' );
			$this->assertFileExists(
				\WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/gallery/preview-data/' . $profile . '.json',
				$template['key'] . ' preview_data profile should exist.'
			);
		}
	}

	/**
	 * Test that get_template includes description field.
	 */
	public function test_get_template_includes_description(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'Test Template',
			)
		);
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
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'Test Template',
			)
		);
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
	 * Test installed gallery templates expose their preview data profile.
	 */
	public function test_get_template_includes_gallery_preview_data_profile(): void {
		$post_id = \WCPOS\WooCommercePOS\Templates::install_gallery_template( 'invoice' );

		$template = \WCPOS\WooCommercePOS\Templates::get_template( $post_id );

		$this->assertEquals( 'invoice', $template['gallery_key'] );
		$this->assertEquals( 'invoice', $template['preview_data'] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test that get_template includes tax_display field.
	 */
	public function test_get_template_includes_tax_display(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'Test Template',
			)
		);
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

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test install_gallery_template sets metadata.
	 */
	public function test_install_gallery_template_sets_metadata(): void {
		$post_id = \WCPOS\WooCommercePOS\Templates::install_gallery_template( 'standard-receipt' );

		$this->assertEquals( 'standard-receipt', get_post_meta( $post_id, '_template_gallery_key', true ) );
		$this->assertEquals( 1, (int) get_post_meta( $post_id, '_template_gallery_version', true ) );
		$this->assertEquals( 'logicless', get_post_meta( $post_id, '_template_engine', true ) );

		wp_delete_post( $post_id, true );
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

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test thermal gallery templates are discovered.
	 */
	public function test_get_gallery_templates_finds_thermal_templates(): void {
		$templates = Templates::get_gallery_templates();
		$keys      = array_column( $templates, 'key' );
		$this->assertContains( 'thermal-simple-80mm', $keys );
		$this->assertContains( 'thermal-simple-58mm', $keys );
		$this->assertContains( 'thermal-detailed-80mm', $keys );
		$this->assertContains( 'thermal-detailed-58mm', $keys );
		$this->assertContains( 'thermal-kitchen-ticket', $keys );
	}

	/**
	 * Test thermal gallery templates are marked offline_capable.
	 */
	public function test_thermal_gallery_template_is_offline_capable(): void {
		$templates = Templates::get_gallery_templates();
		$thermal   = null;
		foreach ( $templates as $t ) {
			if ( 'thermal-simple-80mm' === $t['key'] ) {
				$thermal = $t;
				break;
			}
		}
		$this->assertNotNull( $thermal, 'thermal-simple-80mm should be in gallery' );
		$this->assertEquals( 'thermal', $thermal['engine'] );
		$this->assertTrue( $thermal['offline_capable'] );
		$this->assertNotEmpty( $thermal['content'] );
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

	/**
	 * Test get_gallery_template_by_key returns a template for a valid key.
	 */
	public function test_get_gallery_template_by_key_returns_template(): void {
		$template = Templates::get_gallery_template_by_key( 'standard-receipt' );
		$this->assertNotNull( $template );
		$this->assertEquals( 'standard-receipt', $template['key'] );
		$this->assertArrayHasKey( 'content', $template );
		$this->assertNotEmpty( $template['content'] );
	}

	/**
	 * Test get_gallery_template_by_key returns null for an invalid key.
	 */
	public function test_get_gallery_template_by_key_returns_null_for_invalid(): void {
		$template = Templates::get_gallery_template_by_key( 'nonexistent-template' );
		$this->assertNull( $template );
	}

	/**
	 * Test get_template_order returns empty array by default.
	 */
	public function test_get_template_order_returns_empty_by_default(): void {
		$order = Templates::get_template_order( 'receipt' );
		$this->assertIsArray( $order );
		$this->assertEmpty( $order );
	}

	/**
	 * Test save and retrieve template order.
	 */
	public function test_save_and_get_template_order(): void {
		$order = array( 'plugin-pro', 42, 'plugin-core', 15 );
		Templates::save_template_order( $order, 'receipt' );

		$retrieved = Templates::get_template_order( 'receipt' );
		$this->assertEquals( $order, $retrieved );
	}

	/**
	 * Test virtual template is not disabled by default.
	 */
	public function test_virtual_template_not_disabled_by_default(): void {
		$this->assertFalse( Templates::is_virtual_template_disabled( 'plugin-core' ) );
	}

	/**
	 * Test disable and re-enable virtual template.
	 */
	public function test_disable_and_enable_virtual_template(): void {
		Templates::set_virtual_template_disabled( 'plugin-core', true );
		$this->assertTrue( Templates::is_virtual_template_disabled( 'plugin-core' ) );

		Templates::set_virtual_template_disabled( 'plugin-core', false );
		$this->assertFalse( Templates::is_virtual_template_disabled( 'plugin-core' ) );
	}

	/**
	 * Test disabling one virtual template does not affect others.
	 */
	public function test_disable_virtual_template_isolation(): void {
		Templates::set_virtual_template_disabled( 'plugin-core', true );
		$this->assertFalse( Templates::is_virtual_template_disabled( 'plugin-pro' ) );
	}

	/**
	 * Test get_disabled_virtual_templates returns array.
	 */
	public function test_get_disabled_virtual_templates_returns_array(): void {
		Templates::set_virtual_template_disabled( 'plugin-core', true );
		Templates::set_virtual_template_disabled( 'theme', true );

		$disabled = Templates::get_disabled_virtual_templates();
		$this->assertIsArray( $disabled );
		$this->assertContains( 'plugin-core', $disabled );
		$this->assertContains( 'theme', $disabled );
		$this->assertNotContains( 'plugin-pro', $disabled );
	}

	/**
	 * Test save_template_order sanitizes values.
	 */
	public function test_save_template_order_sanitizes_values(): void {
		$order = array( 'plugin-core', 42, '<script>alert(1)</script>', 99 );
		Templates::save_template_order( $order, 'receipt' );

		$retrieved = Templates::get_template_order( 'receipt' );
		// Script tag should be sanitized away.
		$this->assertNotContains( '<script>alert(1)</script>', $retrieved );
		$this->assertContains( 'plugin-core', $retrieved );
		$this->assertContains( 42, $retrieved );
		$this->assertContains( 99, $retrieved );
	}

	/**
	 * Test save_raw_post_content refuses to write to non-template post types.
	 *
	 * The bypass MUST NOT be usable on plain posts or pages — raw HTML stored
	 * there would surface unfiltered on the front-end and become a stored-XSS
	 * vector.
	 */
	public function test_save_raw_post_content_refuses_non_template_post_type(): void {
		// Arrange: a plain post (not a wcpos_template).
		$post_id = self::factory()->post->create( array( 'post_content' => 'original' ) );

		// Act: attempt the bypass.
		$result = Templates::save_raw_post_content( $post_id, '<script>alert(1)</script>' );

		// Assert: rejected, content untouched.
		$this->assertFalse( $result );
		$this->assertEquals( 'original', get_post( $post_id )->post_content );
	}

	/**
	 * Test save_raw_post_content refuses templates whose engine is legacy-php.
	 *
	 * Legacy-php template content is executed via include() by Legacy_Php_Renderer.
	 * Bypassing kses there would let stored code execute server-side — RCE.
	 */
	public function test_save_raw_post_content_refuses_legacy_php_engine(): void {
		// Arrange: a wcpos_template marked as legacy-php.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_content' => 'original',
			)
		);
		update_post_meta( $post_id, '_template_engine', 'legacy-php' );

		// Act: attempt the bypass with PHP-looking content.
		$result = Templates::save_raw_post_content( $post_id, '<?php phpinfo(); ?>' );

		// Assert: rejected, content untouched.
		$this->assertFalse( $result );
		$this->assertEquals( 'original', get_post( $post_id )->post_content );
	}

	/**
	 * Test save_raw_post_content refuses templates with no engine meta.
	 *
	 * A template without `_template_engine` is unclassified — the safe default
	 * is to refuse the bypass rather than guess.
	 */
	public function test_save_raw_post_content_refuses_missing_engine_meta(): void {
		// Arrange: a wcpos_template with NO engine meta set.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_content' => 'original',
			)
		);

		// Act.
		$result = Templates::save_raw_post_content( $post_id, '<div>raw</div>' );

		// Assert: rejected, content untouched.
		$this->assertFalse( $result );
		$this->assertEquals( 'original', get_post( $post_id )->post_content );
	}

	/**
	 * Test save_raw_post_content accepts logicless templates and stores raw content.
	 *
	 * Regression: the happy path must still work after the new guards are added.
	 */
	public function test_save_raw_post_content_accepts_logicless_engine(): void {
		// Arrange.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_content' => 'original',
			)
		);
		update_post_meta( $post_id, '_template_engine', 'logicless' );

		// Content that wp_kses_post would strip (the print-color-adjust CSS property
		// is not in core's safe_style_css allowlist).
		$raw = '<div style="print-color-adjust: exact; background: #f3f4f6;">total</div>';

		// Act.
		$result = Templates::save_raw_post_content( $post_id, $raw );

		// Assert.
		$this->assertTrue( $result );
		$this->assertEquals( $raw, get_post( $post_id )->post_content );
	}

	/**
	 * Test save_raw_post_content accepts thermal templates and stores raw XML.
	 *
	 * Regression: thermal-engine output is ESC/POS XML that wp_kses would mangle.
	 */
	public function test_save_raw_post_content_accepts_thermal_engine(): void {
		// Arrange.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_content' => 'original',
			)
		);
		update_post_meta( $post_id, '_template_engine', 'thermal' );

		$raw = '<receipt><align mode="center"><text>STORE</text></align></receipt>';

		// Act.
		$result = Templates::save_raw_post_content( $post_id, $raw );

		// Assert.
		$this->assertTrue( $result );
		$this->assertEquals( $raw, get_post( $post_id )->post_content );
	}
}
