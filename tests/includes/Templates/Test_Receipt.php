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
	/** Live rendering must carry the persisted fiscal identity through the real builder. */
	public function test_live_render_uses_frozen_identity(): void {
		$order = OrderHelper::create_order();
		$snapshot = ( new \WCPOS\WooCommercePOS\Services\Receipt_Data_Builder() )->build( $order, 'fiscal' );
		$snapshot['fiscal']['qr_payload'] = 'FROZEN-QR-243';
		$store = \WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store::instance();
		$store->persist_snapshot( $order->get_id(), $snapshot );
		$number = $store->get_snapshot( $order->get_id() )['fiscal']['receipt_number'];
		$receipt = new Receipt( $order->get_id() );
		$method = new \ReflectionMethod( Receipt::class, 'get_receipt_data' );
		$method->setAccessible( true );
		$data = $method->invoke( $receipt, $order, 'live' );
		$output = $this->invoke_render_custom_template(
			$receipt,
			array(
				'engine' => 'logicless',
				'content' => '<p>ID:{{fiscal.receipt_number}} {{fiscal.qr_payload}}</p>',
			),
			$order,
			$data
		);
		$this->assertStringContainsString( 'FROZEN-QR-243', $output );
		$this->assertStringContainsString( 'ID:' . $number, $output );
	}

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
	/**
	 * Each default template renders fiscal additions only when their values exist.
	 *
	 * @dataProvider fiscal_gallery_templates
	 * @param string $key Gallery key.
	 */
	public function test_gallery_fiscal_blocks_are_value_guarded( string $key ): void {
		$data = ( new \WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader() )->build();
		$this->assertFalse( $data['fiscal']['is_reprint'] );
		$template = \WCPOS\WooCommercePOS\Services\Print_Job_Service::load_template( $key );
		$this->assertNotNull( $template );
		$order = OrderHelper::create_order();
		$qr = base64_encode( \WCPOS\WooCommercePOS\Templates\Barcode_Image::qrcode_png( $data['fiscal']['qr_payload'], 4 ) );
		$this->assertNotSame( '', $qr );
		$identity_parts = array(
			$data['i18n']['register'] . ': ' . $data['register']['name'],
			'#' . $data['fiscal']['receipt_number'],
			$data['i18n']['sale_time'] . ': ' . $data['fiscal']['sale_time']['datetime'],
			$data['software']['name'] . ' ' . $data['software']['plugin_version'] . ' · ' . $data['software']['app_version'],
		);
		$html = $this->render_fiscal_gallery( $template, $order, $data );
		$this->assertStringContainsString( $qr, $html, $key );
		$dom = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$lines = array();
		foreach ( ( new \DOMXPath( $dom ) )->query( '//div[not(*)]' ) as $node ) {
			$lines[] = trim( $node->textContent );
		}
		foreach ( $identity_parts as $part ) {
			$this->assertContains( $part, $lines, $key );
		}
		$data['fiscal']['is_reprint'] = true;
		$data['fiscal']['reprint_count'] = 2;
		$data['order']['printed']['datetime'] = 'Sep 11, 2026 12:00';
		$copy = $data['i18n']['copy'] . ' 2 · Sep 11, 2026 12:00';
		$html = $this->render_fiscal_gallery( $template, $order, $data );
		$this->assertStringContainsString( $copy, $this->fiscal_gallery_text( $html ), $key );

		// A pre-1.4 payload has none of the new identities, even if fiscal exists.
		$data['fiscal']['qr_payload'] = '';
		$data['fiscal']['is_reprint'] = false;
		unset( $data['register'], $data['software'], $data['fiscal']['sale_time'], $data['fiscal']['receipt_number'] );
		$html = $this->render_fiscal_gallery( $template, $order, $data );
		$text = $this->fiscal_gallery_text( $html );
		$this->assertStringNotContainsString( $qr, $html, $key );
		$this->assertStringNotContainsString( $copy, $text, $key );
		$this->assertStringNotContainsString( $data['i18n']['register'] . ':', $text, $key );
		$this->assertStringNotContainsString( $data['i18n']['sale_time'] . ':', $text, $key );
		foreach ( $identity_parts as $part ) {
			$this->assertStringNotContainsString( $part, $text, $key );
		}

		// A single present part renders independently of the missing parts.
		$data['register'] = array( 'name' => 'SINGLE-REGISTER' );
		$html = $this->render_fiscal_gallery( $template, $order, $data );
		$this->assertStringContainsString( $data['i18n']['register'] . ': SINGLE-REGISTER', $this->fiscal_gallery_text( $html ) );
		$dom = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$nodes = ( new \DOMXPath( $dom ) )->query( '//*[contains(text(), "SINGLE-REGISTER")]' );
		$this->assertSame( 1, $nodes->length );
		$this->assertSame( $data['i18n']['register'] . ': SINGLE-REGISTER', trim( $nodes->item( 0 )->textContent ) );
	}

	/** @return array Gallery cases. */
	public static function fiscal_gallery_templates(): array {
		return array_map( static fn( $key ) => array( $key ), array(
			'standard-receipt', 'standard-receipt-rtl', 'detailed-receipt',
			'thermal-simple-80mm', 'thermal-simple-58mm', 'thermal-simple-80mm-rtl',
			'thermal-detailed-80mm', 'thermal-detailed-58mm',
		) );
	}

	/** Render through the real HTML or thermal AST pipeline, without a printer. */
	private function render_fiscal_gallery( array $template, $order, array $data ): string {
		if ( 'thermal' === $template['engine'] ) {
			$ast = ( new \WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Renderer() )->build_ast( $template, $order, $data );
			if ( ! empty( $data['register']['name'] ) ) {
				foreach ( $ast['children'] as $node ) {
					$raw = $node['children'][0]['children'][0]['value'] ?? '';
					if ( false !== strpos( $raw, $data['register']['name'] ) ) {
						$this->assertStringNotContainsString( "\n", $raw, 'Each identity part is a single text line.' );
					}
				}
			}
			return ( new \WCPOS\WooCommercePOS\Templates\Thermal\Html_Thermal_Emitter() )->emit( $ast );
		}
		ob_start();
		try {
			( new \WCPOS\WooCommercePOS\Templates\Renderers\Logicless_Renderer() )->render( $template, $order, $data );
		} finally {
			$html = ob_get_clean();
		}
		return (string) $html;
	}

	/** Normalize layout whitespace while retaining rendered separators and labels. */
	private function fiscal_gallery_text( string $html ): string {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

}
