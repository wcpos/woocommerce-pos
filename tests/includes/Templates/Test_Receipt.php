<?php
/**
 * Tests for receipt template behavior.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Templates as TemplatesManager;
use WCPOS\WooCommercePOS\Templates\Receipt;
use WC_REST_Unit_Test_Case;

/**
 * Test_Receipt class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipt extends WC_REST_Unit_Test_Case {
	/**
	 * Test fiscal mode falls back to live data when snapshot is unavailable.
	 */
	public function test_get_receipt_data_fiscal_without_snapshot_returns_live_mode_payload(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$method = new \ReflectionMethod( Receipt::class, 'get_receipt_data' );
		$method->setAccessible( true );

		$data = $method->invoke( $receipt, $order, 'fiscal' );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'order', $data );
		$this->assertArrayNotHasKey( 'meta', $data );
		$this->assertArrayNotHasKey( 'receipt', $data );
	}

	/**
	 * Helper to invoke the private render_custom_template method and capture output.
	 *
	 * @param Receipt $receipt      Receipt instance.
	 * @param array   $template     Template metadata/content.
	 * @param mixed   $order        Order object.
	 * @param array   $receipt_data Canonical receipt payload.
	 *
	 * @return string Rendered output.
	 */
	private function invoke_render_custom_template( Receipt $receipt, array $template, $order, array $receipt_data ): string {
		$method = new \ReflectionMethod( Receipt::class, 'render_custom_template' );
		$method->setAccessible( true );

		ob_start();
		try {
			$method->invoke( $receipt, $template, $order, $receipt_data );
		} finally {
			$output = ob_get_clean();
		}

		return (string) $output;
	}

	/**
	 * Logicless output is the browser print surface: it keeps its colour on
	 * screen (and in PDFs, which render via Template_Pdf_Service and never pass
	 * here) but must print B&W, so the route wraps it in a print-only grayscale
	 * filter.
	 */
	public function test_render_custom_template_logicless_wraps_output_in_print_grayscale_root(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$template = array(
			'engine'  => 'logicless',
			'content' => '<div style="color: #15803d;">Order {{order.number}}</div>',
		);

		$receipt_data = array(
			'order' => array(
				'currency' => 'USD',
				'number'   => '123',
			),
		);

		$output = $this->invoke_render_custom_template( $receipt, $template, $order, $receipt_data );

		$this->assertStringContainsString( '@media print { .wcpos-receipt-print-root', $output );
		$this->assertStringContainsString( 'grayscale(1)', $output );
		$this->assertStringContainsString( '<div class="wcpos-receipt-print-root">', $output );
		$this->assertStringContainsString( 'Order 123', $output );
		// Template colour survives sanitization: only the print media query grayscales it.
		$this->assertStringContainsString( '#15803d', $output );
	}

	/**
	 * Legacy PHP templates emit a full HTML document and own their print styling,
	 * so the route must not inject the grayscale wrapper around them.
	 */
	public function test_render_custom_template_legacy_engine_renders_without_print_wrapper(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$template = array(
			'engine'  => 'legacy-php',
			'content' => '',
		);

		$output = $this->invoke_render_custom_template( $receipt, $template, $order, array() );

		$this->assertStringNotContainsString( 'wcpos-receipt-print-root', $output );
	}

	/**
	 * Thermal templates use their XML-to-HTML pipeline on the browser print surface.
	 */
	public function test_render_custom_template_thermal_uses_html_thermal_pipeline(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$template = array(
			'engine'  => 'thermal',
			'content' => '<receipt paper-width="48"><text>Order {{order.number}}</text></receipt>',
		);

		$receipt_data = array(
			'order' => array(
				'currency' => 'USD',
				'number'   => 'BROWSER-123',
			),
		);

		$output = $this->invoke_render_custom_template( $receipt, $template, $order, $receipt_data );

		$this->assertStringContainsString( 'font-family: \'Courier New\'', $output );
		$this->assertStringContainsString( 'Order BROWSER-123', $output );
		$this->assertStringNotContainsString( '<receipt', $output );
	}

	/**
	 * Helper to invoke the private get_custom_template method.
	 *
	 * @param Receipt $receipt Receipt instance.
	 *
	 * @return array|null
	 */
	private function invoke_get_custom_template( Receipt $receipt ): ?array {
		$method = new \ReflectionMethod( Receipt::class, 'get_custom_template' );
		$method->setAccessible( true );

		return $method->invoke( $receipt );
	}

	/**
	 * Test that a numeric template query parameter selects a published database template.
	 */
	public function test_template_query_param_selects_published_database_template(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'Switchable Receipt',
				'post_content' => '<p>Switchable</p>',
			)
		);
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = (string) $post_id;
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		$this->assertIsArray( $template );
		$this->assertEquals( $post_id, $template['id'] );
		$this->assertEquals( 'receipt', $template['type'] );
	}

	/**
	 * Test that a draft database template is not returned via the query parameter.
	 */
	public function test_template_query_param_rejects_draft_template(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'draft',
				'post_title'   => 'Draft Receipt',
				'post_content' => '<p>Draft</p>',
			)
		);
		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = (string) $post_id;
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		// Should fall back to the active/default template, not the draft.
		$this->assertIsArray( $template );
		$this->assertNotEquals( $post_id, $template['id'] );
	}

	/**
	 * Test that a virtual template ID string selects the correct virtual template.
	 */
	public function test_template_query_param_selects_virtual_template(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = TemplatesManager::TEMPLATE_PLUGIN_CORE;
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		$this->assertIsArray( $template );
		$this->assertEquals( TemplatesManager::TEMPLATE_PLUGIN_CORE, $template['id'] );
		$this->assertEquals( 'receipt', $template['type'] );
		$this->assertTrue( $template['is_virtual'] );
	}

	/**
	 * Test that a gallery template key selects the matching receipt template.
	 */
	public function test_template_query_param_selects_gallery_template(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = 'standard-receipt';
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		$this->assertIsArray( $template );
		$this->assertEquals( 'standard-receipt', $template['key'] );
		$this->assertEquals( 'receipt', $template['type'] );
		$this->assertTrue( $template['is_virtual'] );
	}

	/**
	 * Test that an invalid template ID falls back to the active template.
	 */
	public function test_template_query_param_falls_back_on_invalid_id(): void {
		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = '999999';
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		// Should return the active/default template (not null, not the invalid ID).
		$this->assertIsArray( $template );
		$this->assertNotEquals( 999999, $template['id'] );
	}

	/**
	 * Test that a non-receipt type template is rejected via the query parameter.
	 */
	public function test_template_query_param_rejects_non_receipt_type(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'Report Template',
				'post_content' => '<p>Report</p>',
			)
		);
		wp_set_object_terms( $post_id, 'report', 'wcpos_template_type' );

		$order   = OrderHelper::create_order();
		$receipt = new Receipt( $order->get_id() );

		$_GET['template'] = (string) $post_id;
		try {
			$template = $this->invoke_get_custom_template( $receipt );
		} finally {
			unset( $_GET['template'] );
		}

		// Should fall back since the template is a report, not a receipt.
		$this->assertIsArray( $template );
		$this->assertNotEquals( $post_id, $template['id'] );
	}
}
