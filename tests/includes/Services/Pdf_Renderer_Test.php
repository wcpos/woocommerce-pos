<?php
/**
 * Pdf_Renderer tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Pdf_Renderer;

/**
 * Pdf_Renderer_Test class.
 */
class Pdf_Renderer_Test extends \WP_UnitTestCase {
	/**
	 * Renderer under test.
	 *
	 * @var Pdf_Renderer
	 */
	private $renderer;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->renderer = new Pdf_Renderer();
	}

	/**
	 * The prefixed Dompdf class is available under the WCPOS\Vendor namespace.
	 */
	public function test_prefixed_dompdf_class_is_loadable(): void {
		// Assert.
		$this->assertTrue(
			class_exists( '\\WCPOS\\Vendor\\Dompdf\\Dompdf' ),
			'Prefixed Dompdf class should be autoloadable from vendor_prefixed.'
		);
	}

	/**
	 * Render_html returns a non-trivial PDF document.
	 */
	public function test_render_html_returns_pdf_bytes(): void {
		// Act.
		$pdf = $this->renderer->render_html( '<html><body><h1>Hello PDF</h1></body></html>' );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertGreaterThan( 1000, \strlen( $pdf ) );
	}

	/**
	 * Render_html renders UTF-8 content (accents/currency) without fataling.
	 */
	public function test_render_html_renders_utf8_content(): void {
		// Act.
		$pdf = $this->renderer->render_html(
			'<html><body style="font-family: DejaVu Sans Mono, monospace">'
			. '<p>Order #1234 — caf&eacute; &euro;5.00 — <strong>TOTAL</strong></p>'
			. '</body></html>'
		);

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
	}

	/**
	 * Render_html without fit_height preserves the requested custom paper box.
	 */
	public function test_render_html_without_fit_height_uses_requested_paper(): void {
		// Arrange.
		$paper = array( 0, 0, 226.77, 1000.0 );

		// Act.
		$pdf = $this->renderer->render_html(
			'<html><body><p>Short receipt</p></body></html>',
			array( 'paper' => $paper )
		);
		$box = $this->get_media_box( $pdf );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertEquals( 226.77, $box['width'], '', 0.01 );
		$this->assertEquals( 1000.0, $box['height'], '', 0.01 );
	}

	/**
	 * Render_html without options keeps the default A4 page size.
	 */
	public function test_render_html_without_fit_height_uses_default_a4_paper(): void {
		// Act.
		$pdf = $this->renderer->render_html( '<html><body><p>Default page</p></body></html>' );
		$box = $this->get_media_box( $pdf );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertEquals( 595.28, $box['width'], '', 0.05 );
		$this->assertEquals( 841.89, $box['height'], '', 0.05 );
	}

	/**
	 * Render_html with fit_height shrinks a tall custom receipt page to content.
	 */
	public function test_render_html_with_fit_height_fits_custom_page_to_content(): void {
		// Act.
		$pdf = $this->renderer->render_html(
			'<html><body><p>Short receipt</p></body></html>',
			array(
				'paper'      => array( 0, 0, 226.77, 1000.0 ),
				'fit_height' => true,
			)
		);
		$box = $this->get_media_box( $pdf );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertEquals( 226.77, $box['width'], '', 0.01 );
		$this->assertLessThan( 1000.0, $box['height'] );
	}

	/**
	 * Render_html survives nested flex-inside-grid layout.
	 *
	 * Dompdf has no flex/grid engine, so Pdf_Layout_Preprocessor rewrites those
	 * containers as real tables. An earlier CSS-only display:table approach
	 * nested anonymous tables and triggered a "Frame not found in cellmap" fatal
	 * on this exact structure (a grid cell containing space-between rows plus a
	 * flex-end wrapper, as in the detailed receipt). This guards that regression.
	 */
	public function test_render_html_handles_nested_flex_and_grid(): void {
		// Arrange.
		$html = '<div style="display: grid; grid-template-columns: 1fr 320px;">'
			. '<div></div>'
			. '<div>'
			. '<div style="display: flex; justify-content: space-between;"><span>Subtotal</span><span>55,00</span></div>'
			. '<div style="display: flex; justify-content: space-between;"><span>Total</span><span>55,00</span></div>'
			. '</div></div>'
			. '<div style="display: grid; grid-template-columns: 1fr 320px;">'
			. '<div><div style="display: flex; justify-content: space-between;"><span>Cash</span><span>55,00</span></div></div>'
			. '<div style="display: flex; justify-content: flex-end;"><div style="width: 220px;">X</div></div>'
			. '</div>';

		// Act.
		$pdf = $this->renderer->render_html( $html, array( 'receipt_layout' => true ) );

		// Assert.
		$this->assertEquals( '%PDF-', substr( $pdf, 0, 5 ) );
		$this->assertGreaterThan( 1000, \strlen( $pdf ) );
	}

	/**
	 * Render_html embeds local image URLs before Dompdf sees the document.
	 *
	 * Dompdf runs with remote access and local file access locked down, so receipt
	 * logos that are stored in WordPress uploads must be converted to data URIs.
	 */
	public function test_render_html_embeds_local_image_urls_as_data_uris(): void {
		// Arrange.
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . 'wcpos-pdf-logo.png';
		$url     = trailingslashit( $uploads['baseurl'] ) . 'wcpos-pdf-logo.png';
		$png     = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=' );

		file_put_contents( $path, $png );

		try {
			// Act.
			$html = $this->invoke_private_method(
				'prepare_html_for_render',
				array(
					'<html><body><img src="' . esc_url( $url ) . '" alt="US Store"></body></html>',
					array(),
				)
			);

			// Assert.
			$this->assertStringContainsString( 'src="data:image/png;base64,', $html );
			$this->assertStringNotContainsString( $url, $html );
		} finally {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Render_html embeds local image URLs even when they include cache busters.
	 */
	public function test_render_html_embeds_local_image_urls_with_query_and_fragment_as_data_uris(): void {
		// Arrange.
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . 'wcpos-pdf-logo-versioned.png';
		$url     = trailingslashit( $uploads['baseurl'] ) . 'wcpos-pdf-logo-versioned.png?ver=123#logo';
		$png     = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=' );

		file_put_contents( $path, $png );

		try {
			// Act.
			$html = $this->invoke_private_method(
				'prepare_html_for_render',
				array(
					'<html><body><img src="' . esc_url( $url ) . '" alt="US Store"></body></html>',
					array(),
				)
			);

			// Assert.
			$this->assertStringContainsString( 'src="data:image/png;base64,', $html );
			$this->assertStringNotContainsString( $url, $html );
		} finally {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Receipt preparation rewrites grids into tables with carried widths.
	 *
	 * Invoice/packing-slip templates use grid/flex wrappers with fixed trailing
	 * columns for totals, order details, and barcodes. The Dompdf table rewrite
	 * must carry those widths across so the right-hand blocks do not stretch or
	 * overlap the left-hand content.
	 */
	public function test_receipt_layout_rewrites_grid_with_fixed_columns(): void {
		// Act.
		$html = $this->invoke_private_method(
			'prepare_html_for_render',
			array(
				'<div style="display: grid; grid-template-columns: 1fr 320px; gap: 28px;"><div>left</div><div>right</div></div>',
				array( 'receipt_layout' => true ),
			)
		);

		// Assert.
		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( 'width: 320px', $html );
		$this->assertStringContainsString( 'padding-left: 28px', $html );
		$this->assertStringNotContainsString( 'display: grid', $html );
	}

	/**
	 * Receipt preparation lifts the root padding into @page margins.
	 *
	 * The browser preview shows the template's root padding as the only
	 * whitespace around the receipt; Dompdf's default 1.2cm page margin must be
	 * replaced by it so PDF pages match the preview.
	 */
	public function test_receipt_layout_lifts_root_padding_into_page_margins(): void {
		// Act.
		$html = $this->invoke_private_method(
			'prepare_html_for_render',
			array(
				'<div style="font-size: 13px; padding: 32px 36px;"><p>Receipt</p></div>',
				array( 'receipt_layout' => true ),
			)
		);

		// Assert.
		$this->assertStringContainsString( '@page { margin: 24pt 27pt 24pt 27pt; }', $html );
		$this->assertStringContainsString( 'body { margin: 0; padding: 0; }', $html );
		$this->assertStringNotContainsString( 'padding: 32px 36px', $html );
	}

	/**
	 * Full-document receipts (legacy-php template) keep their head stylesheet.
	 *
	 * Full documents run through the DOM preprocessor in full-document mode:
	 * their <head> stylesheet survives and Dompdf's default page margins stay
	 * (no @page override is injected).
	 */
	public function test_receipt_layout_preserves_full_document_head(): void {
		// Arrange.
		$html = '<html><head><style>.receipt{padding:24px;font-family:serif}</style></head>'
			. '<body><div class="receipt">Legacy receipt</div></body></html>';

		// Act.
		$out = $this->invoke_private_method(
			'prepare_html_for_render',
			array( $html, array( 'receipt_layout' => true ) )
		);

		// Assert.
		$this->assertStringContainsString( '.receipt{padding:24px;font-family:serif}', $out );
		$this->assertStringContainsString( 'Legacy receipt', $out );
		$this->assertStringNotContainsString( '@page', $out );
	}

	/**
	 * Inline flex/grid inside full documents is rewritten to real tables.
	 *
	 * Custom legacy and Template Studio receipts can be full HTML documents
	 * that include inline flex/grid layout. The DOM preprocessor rewrites
	 * those containers in place without dropping the document head.
	 */
	public function test_receipt_layout_rewrites_inline_flex_grid_in_full_documents(): void {
		// Arrange.
		$html = '<html><head><style>.receipt{padding:24px}</style></head>'
			. '<body><div style="display:flex;justify-content:space-between"><span>Cash</span><span>10.00</span></div>'
			. '<div style="display:grid;grid-template-columns:1fr 220px"><span>Left</span><span>Right</span></div></body></html>';

		// Act.
		$out = $this->invoke_private_method(
			'prepare_html_for_render',
			array( $html, array( 'receipt_layout' => true ) )
		);

		// Assert.
		$this->assertStringContainsString( '.receipt{padding:24px}', $out );
		$this->assertStringContainsString( '<table', $out );
		$this->assertStringContainsString( 'width: 220px', $out );
		$this->assertStringNotContainsString( 'display:flex', $out );
		$this->assertStringNotContainsString( 'display:grid', $out );
		$this->assertStringNotContainsString( '@page', $out );
	}

	/**
	 * Legacy class-based rows become tables with right-aligned values.
	 *
	 * The bundled legacy receipt.php declares its flex in a <head> stylesheet;
	 * the old class-keyed float shim drifted consecutive values (tendered /
	 * change) leftward, and the hint-less header cells let Dompdf's auto table
	 * layout inflate the logo cell, pushing the store name toward the center.
	 */
	public function test_receipt_layout_rewrites_legacy_classes_in_full_documents(): void {
		// Arrange.
		$html = '<html><head><style>.payment-sub{display:flex;justify-content:space-between}</style></head>'
			. '<body><header class="receipt-header">'
			. '<div class="logo"><img src="x.png" alt=""></div>'
			. '<div class="store"><div class="store-name">Store</div></div>'
			. '<div class="meta"><div class="status-pill"><span class="dot"></span>Completed</div></div>'
			. '</header>'
			. '<div class="payments">'
			. '<div class="payment-sub"><span>Change</span><span class="amount">7,16</span></div>'
			. '</div></body></html>';

		// Act.
		$out = $this->invoke_private_method(
			'prepare_html_for_render',
			array( $html, array( 'receipt_layout' => true ) )
		);

		// Assert: header cells carry shrink hints, rows get right-aligned
		// value cells, and the pill becomes an unbreakable inline-block chip.
		$this->assertStringContainsString( '<table', $out );
		$this->assertStringContainsString( 'width: 1%', $out );
		$this->assertStringContainsString( 'text-align: right', $out );
		$this->assertStringContainsString( 'display: inline-block', $out );
		$this->assertStringNotContainsString( 'float', $out );
	}

	/**
	 * Headless full documents get the full-document treatment too.
	 *
	 * The renderer previously sniffed `</head>` while the preprocessor sniffed
	 * `<html`, so an `<html><body>` document without a head fell down the
	 * fragment path: zero-margin @page markup prepended before `<html>` while
	 * the preprocessor had already skipped the padding lift.
	 */
	public function test_receipt_layout_full_document_without_head_keeps_default_margins(): void {
		// Arrange.
		$html = '<html><body><div style="padding: 32px;">Custom receipt</div></body></html>';

		// Act.
		$out = $this->invoke_private_method(
			'prepare_html_for_render',
			array( $html, array( 'receipt_layout' => true ) )
		);

		// Assert: no fragment-path injections, root padding not lifted.
		$this->assertStringNotContainsString( '@page', $out );
		$this->assertStringContainsString( 'padding: 32px', $out );
	}

	/**
	 * Receipt preparation declares UTF-8 so Dompdf cannot mis-sniff the charset.
	 *
	 * Mostly-ASCII receipts with a single multibyte character (an em dash in an
	 * empty SKU cell) were mis-decoded by Dompdf's encoding detection.
	 */
	public function test_receipt_layout_declares_utf8_charset(): void {
		// Act.
		$html = $this->invoke_private_method(
			'prepare_html_for_render',
			array(
				'<div><p>Order #1 — total</p></div>',
				array( 'receipt_layout' => true ),
			)
		);

		// Assert.
		$this->assertStringContainsString( 'charset=utf-8', $html );
	}

	/**
	 * Read the first page MediaBox dimensions from PDF bytes.
	 *
	 * @param string $pdf PDF bytes.
	 *
	 * @return array{width: float, height: float}
	 */
	private function get_media_box( string $pdf ): array {
		$this->assertMatchesRegularExpression(
			'/\\/MediaBox\\s*\\[\\s*([\\d.\\-]+)\\s+([\\d.\\-]+)\\s+([\\d.\\-]+)\\s+([\\d.\\-]+)\\s*\\]/',
			$pdf,
			'PDF should contain a MediaBox.'
		);
		preg_match(
			'/\\/MediaBox\\s*\\[\\s*([\\d.\\-]+)\\s+([\\d.\\-]+)\\s+([\\d.\\-]+)\\s+([\\d.\\-]+)\\s*\\]/',
			$pdf,
			$matches
		);

		return array(
			'width'  => (float) $matches[3] - (float) $matches[1],
			'height' => (float) $matches[4] - (float) $matches[2],
		);
	}

	/**
	 * Invoke a private renderer helper for focused HTML-preparation assertions.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 *
	 * @return mixed
	 */
	private function invoke_private_method( string $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $this->renderer, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $this->renderer, $args );
	}
}
