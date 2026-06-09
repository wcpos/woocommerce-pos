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
	 * Dompdf has no flex/grid engine, so the compatibility shim rewrites those
	 * containers as tables/floats. An earlier all-tables approach nested anonymous
	 * tables and triggered a "Frame not found in cellmap" fatal on this exact
	 * structure (a grid cell containing space-between rows plus a flex-end
	 * wrapper, as in the detailed receipt). This guards that regression.
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
		$pdf = $this->renderer->render_html( $html, array( 'flex_grid_shim' => true ) );

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
	 * The PDF flex/grid shim preserves fixed receipt columns.
	 *
	 * Invoice/packing-slip templates use grid/flex wrappers with fixed trailing
	 * columns for totals, order details, and barcodes. The Dompdf table fallback
	 * must carry those widths across so the right-hand blocks do not stretch or
	 * overlap the left-hand content.
	 */
	public function test_flex_grid_shim_preserves_fixed_receipt_columns(): void {
		// Act.
		$html = $this->invoke_private_method(
			'inject_flex_grid_shim',
			array( '<div style="display: grid; grid-template-columns: 1fr 320px; gap: 28px;"></div>' )
		);

		// Assert.
		$this->assertStringContainsString( '[style*="grid-template-columns: 1fr 320px"]>*:last-child', $html );
		$this->assertStringContainsString( 'width:320px !important', $html );
		$this->assertStringContainsString( '[style*="flex: 0 0 280px"],[style*="flex:0 0 280px"]', $html );
		$this->assertStringContainsString( '[style*="gap: 28px"],[style*="gap:28px"]', $html );
		$this->assertStringContainsString( 'border-spacing:28px 0 !important', $html );
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
