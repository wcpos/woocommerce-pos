<?php
/**
 * Tests for the raster (PNG) thermal emitter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Raster_Thermal_Emitter;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Markup_Parser;
use WP_UnitTestCase;

/**
 * Raster_Thermal_Emitter_Test class.
 */
class Raster_Thermal_Emitter_Test extends WP_UnitTestCase {

	/**
	 * Markup parser instance.
	 *
	 * @var Thermal_Markup_Parser
	 */
	private $parser;

	/**
	 * Emitter under test.
	 *
	 * @var Raster_Thermal_Emitter
	 */
	private $emitter;

	/**
	 * Set up the parser and emitter instances.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! Raster_Thermal_Emitter::is_supported() ) {
			$this->markTestSkipped( 'GD with FreeType is required to rasterize receipts.' );
		}
		$this->parser  = new Thermal_Markup_Parser();
		$this->emitter = new Raster_Thermal_Emitter();
	}

	/**
	 * Tear down filters.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_receipt_raster_font' );
		parent::tearDown();
	}

	/**
	 * Emit PNG bytes for a markup string.
	 *
	 * @param string $xml The thermal markup.
	 *
	 * @return string The PNG bytes.
	 */
	private function render( string $xml ): string {
		return $this->emitter->emit( $this->parser->parse( $xml ) );
	}

	/**
	 * Decode rendered markup into a GD image.
	 *
	 * @param string $xml The thermal markup.
	 *
	 * @return \GdImage|resource
	 */
	private function render_image( string $xml ) {
		$image = imagecreatefromstring( $this->render( $xml ) );
		$this->assertNotFalse( $image, 'The emitter did not produce a decodable image.' );

		return $image;
	}

	/**
	 * Count non-white pixels in an image.
	 *
	 * @param \GdImage|resource $image The image.
	 *
	 * @return int
	 */
	private function ink_pixels( $image ): int {
		$ink    = 0;
		$width  = imagesx( $image );
		$height = imagesy( $image );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				if ( $this->is_ink( $image, $x, $y ) ) {
					++$ink;
				}
			}
		}

		return $ink;
	}

	/**
	 * Whether a pixel is anything other than white.
	 *
	 * The emitter palettizes its output, and on a palette image imagecolorat()
	 * returns the colour *index*, not an RGB triple — so the index has to be
	 * resolved before it can be compared to white.
	 *
	 * @param \GdImage|resource $image The image.
	 * @param int               $x     Column.
	 * @param int               $y     Row.
	 *
	 * @return bool
	 */
	private function is_ink( $image, int $x, int $y ): bool {
		$at = imagecolorat( $image, $x, $y );
		if ( ! imageistruecolor( $image ) ) {
			$rgb = imagecolorsforindex( $image, $at );
			$at  = ( $rgb['red'] << 16 ) | ( $rgb['green'] << 8 ) | $rgb['blue'];
		}

		return 0xFFFFFF !== ( $at & 0xFFFFFF );
	}

	/**
	 * It emits a decodable PNG.
	 */
	public function test_emit_produces_a_png(): void {
		$png = $this->render( '<receipt paper-width="48"><text>WCPOS</text></receipt>' );

		$this->assertSame( "\x89PNG\r\n\x1a\n", substr( $png, 0, 8 ) );
	}

	/**
	 * It rasterizes 80 mm paper at 576 dots.
	 */
	public function test_emit_uses_576_dots_for_80mm_paper(): void {
		foreach ( array( 48, 42 ) as $columns ) {
			$image = $this->render_image( '<receipt paper-width="' . $columns . '"><text>Hi</text></receipt>' );
			$this->assertSame( 576, imagesx( $image ), "columns={$columns}" );
			imagedestroy( $image );
		}
	}

	/**
	 * It rasterizes 58 mm paper at 384 dots.
	 */
	public function test_emit_uses_384_dots_for_58mm_paper(): void {
		$image = $this->render_image( '<receipt paper-width="32"><text>Hi</text></receipt>' );

		$this->assertSame( 384, imagesx( $image ) );
		imagedestroy( $image );
	}

	/**
	 * It never lets the character grid run past the paper edge.
	 */
	public function test_emit_keeps_a_full_width_line_inside_the_paper(): void {
		foreach ( array( 48, 42, 32 ) as $columns ) {
			$image = $this->render_image(
				'<receipt paper-width="' . $columns . '"><text>' . str_repeat( 'M', $columns ) . '</text></receipt>'
			);

			$width = imagesx( $image );
			// The rightmost column must exist and the render must not have clipped
			// glyphs by overflowing: check the final cell still holds ink.
			$this->assertGreaterThan( 0, $this->ink_pixels( $image ), "columns={$columns}" );
			$this->assertSame( $columns >= 40 ? 576 : 384, $width, "columns={$columns}" );
			imagedestroy( $image );
		}
	}

	/**
	 * It puts ink on the page for text, and leaves a blank receipt blank.
	 */
	public function test_emit_draws_text_and_leaves_blank_lines_empty(): void {
		$with_text = $this->render_image( '<receipt paper-width="32"><text>WCPOS</text></receipt>' );
		$blank     = $this->render_image( '<receipt paper-width="32"><feed lines="1"/></receipt>' );

		$this->assertGreaterThan( 0, $this->ink_pixels( $with_text ) );
		$this->assertSame( 0, $this->ink_pixels( $blank ) );

		imagedestroy( $with_text );
		imagedestroy( $blank );
	}

	/**
	 * It grows the canvas as the receipt grows.
	 */
	public function test_emit_height_tracks_the_line_count(): void {
		$one  = $this->render_image( '<receipt paper-width="32"><text>A</text></receipt>' );
		$five = $this->render_image(
			'<receipt paper-width="32"><text>A</text><text>B</text><text>C</text><text>D</text><text>E</text></receipt>'
		);

		$this->assertSame( imagesy( $one ) * 5, imagesy( $five ) );

		imagedestroy( $one );
		imagedestroy( $five );
	}

	/**
	 * It gives a double-height line twice the line box.
	 */
	public function test_emit_size_multiplies_the_line_box(): void {
		$normal = $this->render_image( '<receipt paper-width="32"><text>A</text></receipt>' );
		$tall   = $this->render_image( '<receipt paper-width="32"><size height="2"><text>A</text></size></receipt>' );

		$this->assertSame( imagesy( $normal ) * 2, imagesy( $tall ) );

		imagedestroy( $normal );
		imagedestroy( $tall );
	}

	/**
	 * It centres text rather than drawing it flush left.
	 */
	public function test_emit_centres_aligned_text(): void {
		$left   = $this->render_image( '<receipt paper-width="32"><text>AB</text></receipt>' );
		$centre = $this->render_image( '<receipt paper-width="32"><align mode="center"><text>AB</text></align></receipt>' );

		$this->assertGreaterThan( $this->first_ink_column( $left ), $this->first_ink_column( $centre ) );

		imagedestroy( $left );
		imagedestroy( $centre );
	}

	/**
	 * The x of the leftmost inked pixel.
	 *
	 * @param \GdImage|resource $image The image.
	 *
	 * @return int
	 */
	private function first_ink_column( $image ): int {
		$width  = imagesx( $image );
		$height = imagesy( $image );
		for ( $x = 0; $x < $width; $x++ ) {
			for ( $y = 0; $y < $height; $y++ ) {
				if ( $this->is_ink( $image, $x, $y ) ) {
					return $x;
				}
			}
		}

		return $width;
	}

	/**
	 * It inverts the line box, so an inverted line is mostly ink.
	 */
	public function test_emit_invert_fills_the_line_box(): void {
		$plain    = $this->render_image( '<receipt paper-width="32"><text>TOTAL</text></receipt>' );
		$inverted = $this->render_image( '<receipt paper-width="32"><invert><text>TOTAL</text></invert></receipt>' );

		$this->assertGreaterThan( $this->ink_pixels( $plain ), $this->ink_pixels( $inverted ) );

		imagedestroy( $plain );
		imagedestroy( $inverted );
	}

	/**
	 * It rasterizes a QR code into the receipt rather than dropping it.
	 */
	public function test_emit_rasterizes_a_qrcode(): void {
		$without = $this->render_image( '<receipt paper-width="48"><text>Hi</text></receipt>' );
		$with    = $this->render_image( '<receipt paper-width="48"><text>Hi</text><qrcode>https://x.test</qrcode></receipt>' );

		$this->assertGreaterThan( imagesy( $without ), imagesy( $with ), 'The QR block should add height.' );
		$this->assertGreaterThan( $this->ink_pixels( $without ), $this->ink_pixels( $with ) );

		imagedestroy( $without );
		imagedestroy( $with );
	}

	/**
	 * It rasterizes a barcode and prints its human-readable value.
	 */
	public function test_emit_rasterizes_a_barcode(): void {
		$without = $this->render_image( '<receipt paper-width="48"><text>Hi</text></receipt>' );
		$with    = $this->render_image( '<receipt paper-width="48"><text>Hi</text><barcode type="code128">12345678</barcode></receipt>' );

		$this->assertGreaterThan( imagesy( $without ), imagesy( $with ) );

		imagedestroy( $without );
		imagedestroy( $with );
	}

	/**
	 * It composites an inline data-URI image.
	 */
	public function test_emit_composites_a_data_uri_image(): void {
		$logo = imagecreatetruecolor( 40, 20 );
		imagefilledrectangle( $logo, 0, 0, 39, 19, imagecolorallocate( $logo, 0, 0, 0 ) );
		ob_start();
		imagepng( $logo );
		$bytes = (string) ob_get_clean();
		imagedestroy( $logo );

		$src   = 'data:image/png;base64,' . base64_encode( $bytes );
		$image = $this->render_image( '<receipt paper-width="48"><image src="' . $src . '" width="40"/></receipt>' );

		$this->assertGreaterThan( 0, $this->ink_pixels( $image ) );
		imagedestroy( $image );
	}

	/**
	 * It ignores a remote image URL rather than fetching it at print time.
	 */
	public function test_emit_ignores_a_remote_image_url(): void {
		$image = $this->render_image( '<receipt paper-width="32"><image src="https://x.test/logo.png" width="40"/></receipt>' );

		$this->assertSame( 0, $this->ink_pixels( $image ) );
		imagedestroy( $image );
	}

	/**
	 * It emits exactly white and black, nothing in between.
	 *
	 * A thermal head prints one dot or none, so anything else is either the
	 * printer guessing or GD's palette quantizer having rewritten our colours —
	 * it turns pure white into (252,254,252) if you let it near the canvas.
	 */
	public function test_emit_uses_only_pure_black_and_white(): void {
		$logo = imagecreatetruecolor( 30, 12 );
		imagefilledrectangle( $logo, 0, 0, 29, 11, imagecolorallocate( $logo, 90, 90, 90 ) );
		ob_start();
		imagepng( $logo );
		$bytes = (string) ob_get_clean();
		imagedestroy( $logo );

		$image = $this->render_image(
			'<receipt paper-width="48">'
			. '<image src="data:image/png;base64,' . base64_encode( $bytes ) . '" width="60"/>'
			. '<text>WCPOS</text>'
			. '<invert><text>TOTAL</text></invert>'
			. '<qrcode>https://x.test</qrcode>'
			. '</receipt>'
		);

		$seen   = array();
		$width  = imagesx( $image );
		$height = imagesy( $image );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$rgb                                                       = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
				$seen[ $rgb['red'] . ',' . $rgb['green'] . ',' . $rgb['blue'] ] = true;
			}
		}

		ksort( $seen );
		$this->assertSame( array( '0,0,0', '255,255,255' ), array_keys( $seen ) );

		imagedestroy( $image );
	}

	/**
	 * It wraps text past the paper edge instead of silently clipping it.
	 *
	 * A thermal printer wraps an over-long line; nothing wraps a raster, so a
	 * long product name would otherwise lose its tail at the paper edge.
	 */
	public function test_emit_wraps_text_longer_than_the_paper(): void {
		$one  = $this->render_image( '<receipt paper-width="32"><text>A</text></receipt>' );
		$long = $this->render_image( '<receipt paper-width="32"><text>' . str_repeat( 'A', 70 ) . '</text></receipt>' );

		// 70 characters over 32 columns is three physical lines.
		$this->assertSame( imagesy( $one ) * 3, imagesy( $long ) );

		imagedestroy( $one );
		imagedestroy( $long );
	}

	/**
	 * It breaks a line on an embedded newline.
	 */
	public function test_emit_breaks_on_embedded_newlines(): void {
		$one = $this->render_image( '<receipt paper-width="32"><text>A</text></receipt>' );
		$two = $this->render_image( "<receipt paper-width=\"32\"><text>A\nB</text></receipt>" );

		$this->assertSame( imagesy( $one ) * 2, imagesy( $two ) );

		imagedestroy( $one );
		imagedestroy( $two );
	}

	/**
	 * It keeps a bold span bold without bolding the rest of the line.
	 */
	public function test_emit_preserves_inline_style_runs(): void {
		$plain     = $this->render_image( '<receipt paper-width="32"><text>aaaa bbbb</text></receipt>' );
		$all_bold  = $this->render_image( '<receipt paper-width="32"><bold><text>aaaa bbbb</text></bold></receipt>' );
		$half_bold = $this->render_image( '<receipt paper-width="32"><text>aaaa <bold>bbbb</bold></text></receipt>' );

		$plain_ink = $this->ink_pixels( $plain );
		$bold_ink  = $this->ink_pixels( $all_bold );
		$half_ink  = $this->ink_pixels( $half_bold );

		// Bold puts down more ink; a half-bold line must land strictly between the
		// two, which it cannot do if the whole line took one style.
		$this->assertGreaterThan( $plain_ink, $bold_ink );
		$this->assertGreaterThan( $plain_ink, $half_ink );
		$this->assertLessThan( $bold_ink, $half_ink );

		imagedestroy( $plain );
		imagedestroy( $all_bold );
		imagedestroy( $half_bold );
	}

	/**
	 * It scales height without also stretching glyphs across their neighbours.
	 *
	 * `<size height="2">` with width left at 1 is what the bundled narrow
	 * templates use for headings. Doubling the font size alone would double the
	 * glyph width too while the cell advance stayed put, overlapping every
	 * character with the next.
	 */
	public function test_emit_scales_height_without_widening_the_run(): void {
		$normal = $this->render_image( '<receipt paper-width="32"><text>MMMM</text></receipt>' );
		$tall   = $this->render_image( '<receipt paper-width="32"><size height="2"><text>MMMM</text></size></receipt>' );

		$this->assertSame( imagesy( $normal ) * 2, imagesy( $tall ) );
		$this->assertSame( $this->ink_span( $normal ), $this->ink_span( $tall ), 'A height-only scale must not widen the run.' );

		imagedestroy( $normal );
		imagedestroy( $tall );
	}

	/**
	 * It widens the run when the width multiplier asks for it.
	 *
	 * `height` has to be given explicitly: the parser defaults it to `width`, so
	 * a bare `<size width="2">` means double in both axes, not double-wide.
	 */
	public function test_emit_scales_width_independently(): void {
		$normal = $this->render_image( '<receipt paper-width="32"><text>MM</text></receipt>' );
		$wide   = $this->render_image( '<receipt paper-width="32"><size width="2" height="1"><text>MM</text></size></receipt>' );

		$this->assertSame( imagesy( $normal ), imagesy( $wide ) );
		$this->assertGreaterThan( $this->ink_span( $normal ), $this->ink_span( $wide ) );

		imagedestroy( $normal );
		imagedestroy( $wide );
	}

	/**
	 * The horizontal extent of the inked pixels.
	 *
	 * @param \GdImage|resource $image The image.
	 *
	 * @return int
	 */
	private function ink_span( $image ): int {
		$min    = imagesx( $image );
		$max    = -1;
		$width  = imagesx( $image );
		$height = imagesy( $image );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				if ( $this->is_ink( $image, $x, $y ) ) {
					$min = min( $min, $x );
					$max = max( $max, $x );
				}
			}
		}

		return $max < 0 ? 0 : ( $max - $min + 1 );
	}

	/**
	 * It reads a store logo from a local WordPress URL, not just a data URI.
	 */
	public function test_emit_composites_a_local_url_image(): void {
		$uploads = wp_upload_dir();
		$file    = trailingslashit( $uploads['path'] ) . 'wcpos-raster-logo.png';

		$logo = imagecreatetruecolor( 40, 20 );
		imagefilledrectangle( $logo, 0, 0, 39, 19, imagecolorallocate( $logo, 0, 0, 0 ) );
		imagepng( $logo, $file );
		imagedestroy( $logo );

		$url   = trailingslashit( $uploads['url'] ) . 'wcpos-raster-logo.png';
		$image = $this->render_image( '<receipt paper-width="48"><image src="' . esc_attr( $url ) . '" width="40"/></receipt>' );

		$this->assertGreaterThan( 0, $this->ink_pixels( $image ), 'A configured store logo must reach the raster.' );

		imagedestroy( $image );
		wp_delete_file( $file );
	}

	/**
	 * It still prints the barcode value when the symbol cannot be generated.
	 *
	 * An EAN-13 node carrying a non-numeric value fails to rasterize; suppressing
	 * the human-readable text along with it would leave a silent gap where a
	 * scannable code should be. The HTML and PDF paths fall back the same way.
	 */
	public function test_emit_keeps_barcode_text_when_generation_fails(): void {
		$blank = $this->render_image( '<receipt paper-width="48"><feed lines="1"/></receipt>' );
		$image = $this->render_image( '<receipt paper-width="48"><barcode type="ean13">NOT-NUMERIC</barcode></receipt>' );

		$this->assertGreaterThan( 0, $this->ink_pixels( $image ), 'The value must still be printed as text.' );
		$this->assertSame( 0, $this->ink_pixels( $blank ) );

		imagedestroy( $blank );
		imagedestroy( $image );
	}

	/**
	 * It reports the AST's cut instead of drawing it.
	 */
	public function test_emit_reports_cut_out_of_band(): void {
		$this->render( '<receipt paper-width="32"><text>Hi</text><cut type="full"/></receipt>' );

		$this->assertSame( 'full', $this->emitter->cut_type() );
	}

	/**
	 * It reports a drawer requested by the job options.
	 */
	public function test_emit_reports_drawer_from_render_options(): void {
		$emitter = new Raster_Thermal_Emitter( array( 'auto_open_drawer' => true ) );
		$emitter->emit( $this->parser->parse( '<receipt paper-width="32"><text>Hi</text></receipt>' ) );

		$this->assertSame( 'end', $emitter->drawer() );
	}

	/**
	 * It reports no cut or drawer when the receipt asked for neither.
	 */
	public function test_emit_reports_nothing_when_the_receipt_asks_for_nothing(): void {
		$this->render( '<receipt paper-width="32"><text>Hi</text></receipt>' );

		$this->assertNull( $this->emitter->cut_type() );
		$this->assertNull( $this->emitter->drawer() );
	}

	/**
	 * It resets state between emissions so a reused emitter does not leak.
	 */
	public function test_emit_resets_state_between_calls(): void {
		$this->render( '<receipt paper-width="32"><text>Hi</text><cut/></receipt>' );
		$this->render( '<receipt paper-width="32"><text>Bye</text></receipt>' );

		$this->assertNull( $this->emitter->cut_type() );
	}

	/**
	 * It falls back to the bundled font when the filter names an unreadable path,
	 * rather than rendering nothing.
	 */
	public function test_emit_falls_back_when_the_font_filter_is_broken(): void {
		add_filter(
			'woocommerce_pos_receipt_raster_font',
			static function (): string {
				return '/nonexistent/font.ttf';
			}
		);

		$image = $this->render_image( '<receipt paper-width="32"><text>WCPOS</text></receipt>' );

		$this->assertGreaterThan( 0, $this->ink_pixels( $image ) );
		imagedestroy( $image );
	}
}
