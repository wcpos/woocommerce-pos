<?php
/**
 * Tests for the shared monochrome bitmap used by the native thermal lanes.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Bitmap;
use WP_UnitTestCase;

/**
 * Thermal_Bitmap_Test class.
 */
class Thermal_Bitmap_Test extends WP_UnitTestCase {

	/**
	 * Encode a solid-colour PNG.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @param int $red    Red channel.
	 * @param int $green  Green channel.
	 * @param int $blue   Blue channel.
	 *
	 * @return string The PNG bytes.
	 */
	private function solid_png( int $width, int $height, int $red, int $green, int $blue ): string {
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, $red, $green, $blue ) );
		ob_start();
		imagepng( $image );

		return (string) ob_get_clean();
	}

	/**
	 * A solid black image packs to all-set bits at its natural size.
	 *
	 * @return void
	 */
	public function test_from_bytes_black_image_packs_every_bit_set(): void {
		// Arrange.
		$png = $this->solid_png( 16, 3, 0, 0, 0 );

		// Act.
		$bitmap = Thermal_Bitmap::from_bytes( $png, 16, 576 );

		// Assert.
		$this->assertNotNull( $bitmap );
		$this->assertSame( 16, $bitmap->width() );
		$this->assertSame( 3, $bitmap->height() );
		$this->assertSame( 2, $bitmap->bytes_per_row() );
		$this->assertSame( str_repeat( "\xff", 6 ), $bitmap->raster() );
	}

	/**
	 * A solid white image packs to all-clear bits.
	 *
	 * @return void
	 */
	public function test_from_bytes_white_image_packs_every_bit_clear(): void {
		// Arrange.
		$png = $this->solid_png( 8, 2, 255, 255, 255 );

		// Act.
		$bitmap = Thermal_Bitmap::from_bytes( $png, 8, 576 );

		// Assert.
		$this->assertNotNull( $bitmap );
		$this->assertSame( "\x00\x00", $bitmap->raster() );
	}

	/**
	 * Mid-grey thresholds to black and light grey to white.
	 *
	 * The same Rec. 601 threshold Raster_Thermal_Emitter uses, so a logo lands
	 * the same way whichever lane renders it.
	 *
	 * @return void
	 */
	public function test_from_bytes_thresholds_grey_at_mid_luma(): void {
		// Arrange / Act.
		$dark  = Thermal_Bitmap::from_bytes( $this->solid_png( 8, 1, 90, 90, 90 ), 8, 576 );
		$light = Thermal_Bitmap::from_bytes( $this->solid_png( 8, 1, 200, 200, 200 ), 8, 576 );

		// Assert.
		$this->assertNotNull( $dark );
		$this->assertNotNull( $light );
		$this->assertSame( "\xff", $dark->raster() );
		$this->assertSame( "\x00", $light->raster() );
	}

	/**
	 * A width that is not a whole number of bytes is padded, and the pad is blank.
	 *
	 * ePOS-Print, ESC/POS `GS v 0` and StarPRNT `ESC X` all address the raster in
	 * whole bytes, so a 5-dot logo has to become an 8-dot one with three blank
	 * columns rather than a row that ends mid-byte.
	 *
	 * @return void
	 */
	public function test_from_bytes_pads_width_to_a_whole_byte_with_blank_dots(): void {
		// Arrange.
		$png = $this->solid_png( 5, 1, 0, 0, 0 );

		// Act.
		$bitmap = Thermal_Bitmap::from_bytes( $png, 5, 576 );

		// Assert.
		$this->assertNotNull( $bitmap );
		$this->assertSame( 8, $bitmap->width() );
		$this->assertSame( 1, $bitmap->bytes_per_row() );
		// The five image columns are black; the three pad columns are not.
		$this->assertSame( "\xf8", $bitmap->raster() );
	}

	/**
	 * A requested width wider than the paper is clamped to the paper.
	 *
	 * @return void
	 */
	public function test_from_bytes_clamps_the_requested_width_to_the_paper(): void {
		// Arrange.
		$png = $this->solid_png( 100, 50, 0, 0, 0 );

		// Act.
		$bitmap = Thermal_Bitmap::from_bytes( $png, 2000, 384 );

		// Assert.
		$this->assertNotNull( $bitmap );
		$this->assertSame( 384, $bitmap->width() );
		// Aspect ratio is preserved: 50/100 of 384.
		$this->assertSame( 192, $bitmap->height() );
	}

	/**
	 * A zero requested width falls back to the image's natural size.
	 *
	 * @return void
	 */
	public function test_from_bytes_uses_the_natural_width_when_none_is_requested(): void {
		// Arrange.
		$png = $this->solid_png( 24, 6, 0, 0, 0 );

		// Act.
		$bitmap = Thermal_Bitmap::from_bytes( $png, 0, 576 );

		// Assert.
		$this->assertNotNull( $bitmap );
		$this->assertSame( 24, $bitmap->width() );
		$this->assertSame( 6, $bitmap->height() );
	}

	/**
	 * Bytes that are not a decodable image yield no bitmap.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_undecodable_bytes(): void {
		// Arrange / Act / Assert.
		$this->assertNull( Thermal_Bitmap::from_bytes( 'not an image', 40, 576 ) );
		$this->assertNull( Thermal_Bitmap::from_bytes( '', 40, 576 ) );
	}

	/**
	 * A remote src is not fetched, so it produces no bitmap.
	 *
	 * This runs inside the printer's job fetch; an outbound request here would
	 * stall the print.
	 *
	 * @return void
	 */
	public function test_from_node_does_not_fetch_a_remote_src(): void {
		// Arrange.
		$node = array(
			'type'  => 'image',
			'src'   => 'https://x.test/logo.png',
			'width' => 200,
		);

		// Act / Assert.
		$this->assertNull( Thermal_Bitmap::from_node( $node, 576 ) );
	}

	/**
	 * A data-URI src is decoded in place.
	 *
	 * @return void
	 */
	public function test_from_node_decodes_a_data_uri_src(): void {
		// Arrange.
		$node = array(
			'type'  => 'image',
			'src'   => 'data:image/png;base64,' . base64_encode( $this->solid_png( 32, 8, 0, 0, 0 ) ),
			'width' => 32,
		);

		// Act.
		$bitmap = Thermal_Bitmap::from_node( $node, 576 );

		// Assert.
		$this->assertNotNull( $bitmap );
		$this->assertSame( 32, $bitmap->width() );
		$this->assertSame( 8, $bitmap->height() );
	}

	/**
	 * Reading a dot outside the bitmap returns blank paper.
	 *
	 * The StarPRNT lane packs fixed 24-dot bands, so the last band of a logo
	 * whose height is not a multiple of 24 reads past the bottom edge by design.
	 *
	 * @return void
	 */
	public function test_pixel_reads_outside_the_bitmap_as_blank(): void {
		// Arrange.
		$bitmap = Thermal_Bitmap::from_bytes( $this->solid_png( 8, 1, 0, 0, 0 ), 8, 576 );

		// Act / Assert.
		$this->assertNotNull( $bitmap );
		$this->assertSame( 1, $bitmap->pixel( 0, 0 ) );
		$this->assertSame( 0, $bitmap->pixel( 0, 1 ) );
		$this->assertSame( 0, $bitmap->pixel( 8, 0 ) );
		$this->assertSame( 0, $bitmap->pixel( -1, 0 ) );
	}
}
