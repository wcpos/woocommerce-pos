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
}
