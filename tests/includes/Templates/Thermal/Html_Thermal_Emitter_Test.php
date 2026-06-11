<?php
/**
 * Tests for the HTML thermal emitter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Html_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;
use WP_UnitTestCase;

/**
 * Html_Thermal_Emitter_Test class.
 */
class Html_Thermal_Emitter_Test extends WP_UnitTestCase {

	/**
	 * Emitter under test.
	 *
	 * @var Html_Thermal_Emitter
	 */
	private $emitter;

	/**
	 * Set up the emitter instance.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->emitter = new Html_Thermal_Emitter();
	}

	/**
	 * Build a receipt AST node wrapping the supplied children.
	 *
	 * @param array $children    The child nodes.
	 * @param int   $paper_width The paper width in characters.
	 *
	 * @return array The receipt AST node.
	 */
	private function receipt( array $children, int $paper_width = 48 ): array {
		return array(
			'type'        => 'receipt',
			'paper_width' => $paper_width,
			'children'    => $children,
		);
	}

	/**
	 * The receipt wrapper uses a monospace font without ch units.
	 *
	 * Dompdf has no `ch` unit support — a ch-based wrapper width is silently
	 * dropped and the receipt collapses to the page width.
	 *
	 * @return void
	 */
	public function test_receipt_wrapper_is_monospace_without_ch_units(): void {
		// Arrange.
		$ast = $this->receipt( array(), 42 );

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( 'monospace', $html );
		$this->assertStringNotContainsString( 'ch;', $html );
		$this->assertStringStartsWith( '<div', $html );
	}

	/**
	 * The base font scales so the character grid fills the given paper width.
	 *
	 * 80mm paper is 302.36 CSS px; minus 2 × 12px side padding the printable
	 * width is 278.36px, so 48 columns at 0.6em advance need a 9.66px font.
	 *
	 * @return void
	 */
	public function test_font_size_scales_to_paper_width(): void {
		// Arrange.
		$ast = $this->receipt( array(), 48 );

		// Act.
		$html = $this->emitter->emit( $ast, array( 'paper_width_px' => 302.36 ) );

		// Assert.
		$this->assertStringContainsString( 'font-size: 9.67px', $html );
	}

	/**
	 * Without a paper width the wrapper keeps the preview's 13px base font.
	 *
	 * @return void
	 */
	public function test_receipt_wrapper_invalid_width_uses_default(): void {
		// Arrange.
		$ast = $this->receipt( array(), 9999 );

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( 'font-size: 13px', $html );
	}

	/**
	 * Raw text is HTML-escaped.
	 *
	 * @return void
	 */
	public function test_raw_text_is_html_escaped(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'  => 'raw-text',
					'value' => '<script>alert("x")</script>',
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * Bold renders as a strong element.
	 *
	 * @return void
	 */
	public function test_bold_renders_strong_element(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'     => 'bold',
					'children' => array(
						array(
							'type' => 'raw-text',
							'value' => 'Hi',
						),
					),
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '<strong>Hi</strong>', $html );
	}

	/**
	 * Underline renders an underline span.
	 *
	 * @return void
	 */
	public function test_underline_renders_underline_span(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'     => 'underline',
					'children' => array(
						array(
							'type' => 'raw-text',
							'value' => 'U',
						),
					),
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( 'text-decoration: underline', $html );
		$this->assertStringContainsString( 'U', $html );
	}

	/**
	 * Invert renders an inverted span.
	 *
	 * @return void
	 */
	public function test_invert_renders_inverted_span(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'     => 'invert',
					'children' => array(
						array(
							'type' => 'raw-text',
							'value' => 'I',
						),
					),
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( 'background: #000', $html );
		$this->assertStringContainsString( 'color: #fff', $html );
	}

	/**
	 * Size renders an em font-size span.
	 *
	 * @return void
	 */
	public function test_size_renders_em_font_size(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'     => 'size',
					'width'    => 2,
					'height'   => 2,
					'children' => array(
						array(
							'type' => 'raw-text',
							'value' => 'S',
						),
					),
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( 'font-size: 2em', $html );
	}

	/**
	 * Align renders a text-align div.
	 *
	 * @return void
	 */
	public function test_align_renders_text_align_div(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'     => 'align',
					'mode'     => 'center',
					'children' => array(
						array(
							'type' => 'raw-text',
							'value' => 'C',
						),
					),
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( 'text-align: center', $html );
	}

	/**
	 * Row renders a fixed-layout table and its columns carry em widths.
	 *
	 * Dompdf has no flexbox engine and no `ch` unit, so rows are real tables:
	 * fixed columns get chars × 0.6em widths and `*` columns share the rest.
	 *
	 * @return void
	 */
	public function test_row_renders_table_with_em_col_widths(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'     => 'row',
					'children' => array(
						array(
							'type'     => 'col',
							'width'    => '*',
							'align'    => 'left',
							'children' => array(
								array(
									'type' => 'raw-text',
									'value' => 'L',
								),
							),
						),
						array(
							'type'     => 'col',
							'width'    => 8,
							'align'    => 'right',
							'children' => array(
								array(
									'type' => 'raw-text',
									'value' => 'R',
								),
							),
						),
					),
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '<table style="width: 100%; table-layout: fixed', $html );
		$this->assertStringContainsString( 'width: 4.8em', $html );
		$this->assertStringContainsString( 'text-align: right', $html );
		$this->assertStringNotContainsString( 'display: flex', $html );
		$this->assertEquals( 2, substr_count( $html, '<td' ) );
	}

	/**
	 * Each line style renders its corresponding hr variant.
	 *
	 * @return void
	 */
	public function test_line_styles_render_matching_hr_variants(): void {
		// Arrange / Act.
		$single = $this->emitter->emit(
			$this->receipt(
				array(
					array(
						'type' => 'line',
						'style' => 'single',
					),
				)
			)
		);
		$double = $this->emitter->emit(
			$this->receipt(
				array(
					array(
						'type' => 'line',
						'style' => 'double',
					),
				)
			)
		);
		$dashed = $this->emitter->emit(
			$this->receipt(
				array(
					array(
						'type' => 'line',
						'style' => 'dashed',
					),
				)
			)
		);
		$dotted = $this->emitter->emit(
			$this->receipt(
				array(
					array(
						'type' => 'line',
						'style' => 'dotted',
					),
				)
			)
		);

		// Assert.
		$this->assertStringContainsString( 'border-top: 1px solid #000', $single );
		$this->assertStringContainsString( 'border-top: 3px double #000', $double );
		$this->assertStringContainsString( 'border-top: 1px dashed #000', $dashed );
		$this->assertStringContainsString( 'border-top: 1px dotted #000', $dotted );
	}

	/**
	 * Feed renders a spacer of the correct height.
	 *
	 * @return void
	 */
	public function test_feed_renders_spacer_div(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type' => 'feed',
					'lines' => 2,
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( 'height: 2.8em', $html );
	}

	/**
	 * Cut renders a scissors glyph.
	 *
	 * @return void
	 */
	public function test_cut_renders_scissors_glyph(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type' => 'cut',
					'cut_type' => 'partial',
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '&#9986;', $html );
	}

	/**
	 * Drawer nodes emit nothing.
	 *
	 * @return void
	 */
	public function test_drawer_emits_nothing(): void {
		// Arrange.
		$with    = $this->receipt( array( array( 'type' => 'drawer' ) ) );
		$without = $this->receipt( array() );

		// Act.
		$html_with    = $this->emitter->emit( $with );
		$html_without = $this->emitter->emit( $without );

		// Assert.
		$this->assertEquals( $html_without, $html_with );
	}

	/**
	 * Image nodes emit a centered <img> scaled by the paper's dot budget.
	 *
	 * Widths are authored in printer dots; 200 dots on a 576-dot (48-column)
	 * roll is 200/576 of the receipt = 16.67ch = 10em. Pdf_Renderer embeds
	 * local URLs (store logos) as data URIs before Dompdf renders.
	 *
	 * @return void
	 */
	public function test_image_emits_centered_img_scaled_by_dot_budget(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type' => 'image',
					'src' => 'https://x/y.png',
					'width' => 200,
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '<img src="https://x/y.png"', $html );
		$this->assertStringContainsString( 'width: 10em', $html );
		$this->assertStringContainsString( 'max-width: 100%', $html );
		$this->assertStringContainsString( 'text-align: center', $html );
	}

	/**
	 * Image nodes without a src emit nothing.
	 *
	 * @return void
	 */
	public function test_image_without_src_emits_nothing(): void {
		// Arrange.
		$with    = $this->receipt(
			array(
				array(
					'type' => 'image',
					'src' => '   ',
					'width' => 200,
				),
			)
		);
		$without = $this->receipt( array() );

		// Act.
		$html_with    = $this->emitter->emit( $with );
		$html_without = $this->emitter->emit( $without );

		// Assert.
		$this->assertEquals( $html_without, $html_with );
	}

	/**
	 * A valid CODE128 barcode embeds a PNG image.
	 *
	 * Dompdf cannot render inline SVG, so barcodes are rasterized to a PNG data
	 * URI that Dompdf renders reliably.
	 *
	 * @return void
	 */
	public function test_valid_code128_barcode_embeds_png_image(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'         => 'barcode',
					'barcode_type' => 'code128',
					'height'       => 40,
					'value'        => '12345678',
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '<img src="data:image/png;base64,', $html );
		$this->assertStringNotContainsString( '<svg', $html );
	}

	/**
	 * A QR code node embeds a PNG image.
	 *
	 * @return void
	 */
	public function test_qrcode_embeds_png_image(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'  => 'qrcode',
					'size'  => 4,
					'value' => 'https://wcpos.com',
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '<img src="data:image/png;base64,', $html );
		$this->assertStringNotContainsString( '<svg', $html );
	}

	/**
	 * An invalid barcode value falls back to escaped monospace text without throwing.
	 *
	 * @return void
	 */
	public function test_invalid_barcode_value_falls_back_to_text(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'         => 'barcode',
					'barcode_type' => 'ean13',
					'height'       => 40,
					'value'        => 'not-numeric',
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertStringContainsString( 'not-numeric', $html );
	}

	/**
	 * An empty qrcode value emits nothing.
	 *
	 * @return void
	 */
	public function test_empty_qrcode_value_emits_nothing(): void {
		// Arrange.
		$with    = $this->receipt(
			array(
				array(
					'type' => 'qrcode',
					'size' => 4,
					'value' => '   ',
				),
			)
		);
		$without = $this->receipt( array() );

		// Act.
		$html_with    = $this->emitter->emit( $with );
		$html_without = $this->emitter->emit( $without );

		// Assert.
		$this->assertEquals( $html_without, $html_with );
	}

	/**
	 * Parsed XML markup renders through the emitter end to end.
	 *
	 * @return void
	 */
	public function test_parsed_markup_renders_end_to_end(): void {
		// Arrange.
		$parser = new Thermal_Markup_Parser();
		$ast    = $parser->parse( '<receipt paper-width="32"><text><bold>Store</bold></text><line style="double"/></receipt>' );

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '<strong>Store</strong>', $html );
		$this->assertStringContainsString( 'border-top: 3px double #000', $html );
	}

	/**
	 * 1D barcodes carry the human-readable value beneath the bars.
	 *
	 * bwip-js renders the value under the bars in the client-side previews, so
	 * the PDF mirrors it.
	 *
	 * @return void
	 */
	public function test_barcode_includes_human_readable_value(): void {
		// Arrange.
		$ast = $this->receipt(
			array(
				array(
					'type'         => 'barcode',
					'barcode_type' => 'code128',
					'height'       => 40,
					'value'        => '12345678',
				),
			)
		);

		// Act.
		$html = $this->emitter->emit( $ast );

		// Assert.
		$this->assertStringContainsString( '<img src="data:image/png;base64,', $html );
		$this->assertStringContainsString( '>12345678</div>', $html );
	}
}
