<?php
/**
 * Monochrome Bitmap Class.
 *
 * Turns a template `<image>` (in practice, the store logo) into the one thing
 * every native printer command set wants: a grid of black-or-white dots at the
 * paper's own resolution. The three native lanes then pack those dots in
 * whatever order their command takes — row-major for ESC/POS `GS v 0` and
 * ePOS-Print `<image>`, column-major 24-dot bands for StarPRNT `ESC X`.
 *
 * Before this existed the native lanes dropped `<image>` nodes on the floor and
 * said server-side rasterization was out of scope. It was never out of scope:
 * Raster_Thermal_Emitter already decoded, scaled and thresholded logos the same
 * way, and Local_Image_Resolver already read them off disk without an outbound
 * request. This is that work, lifted to where all four lanes can share it.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

use WCPOS\WooCommercePOS\Services\Local_Image_Resolver;

/**
 * Thermal_Bitmap class.
 */
final class Thermal_Bitmap {

	/**
	 * Luma below which a pixel is printed as a black dot.
	 *
	 * Mid-grey, matching Raster_Thermal_Emitter::composite_thresholded() so a
	 * logo lands identically whichever lane renders it.
	 */
	private const BLACK_THRESHOLD = 128;

	/**
	 * Hard ceiling on the source height, in dots.
	 *
	 * A tall image scaled to full paper width would allocate its own height in
	 * dots; 4000 is about half a metre of paper, far past any real logo, and
	 * keeps a hostile template from turning one `<image>` into a huge job.
	 */
	private const MAX_HEIGHT = 4000;

	/**
	 * Width in dots. Always a multiple of 8.
	 *
	 * @var int
	 */
	private $width;

	/**
	 * Height in dots.
	 *
	 * @var int
	 */
	private $height;

	/**
	 * Row-major packed dots: ceil(width / 8) bytes per row, MSB first, set = black.
	 *
	 * @var string
	 */
	private $raster;

	/**
	 * Construct from packed geometry.
	 *
	 * @param int    $width  Width in dots (a multiple of 8).
	 * @param int    $height Height in dots.
	 * @param string $raster Row-major packed dots.
	 */
	private function __construct( int $width, int $height, string $raster ) {
		$this->width  = $width;
		$this->height = $height;
		$this->raster = $raster;
	}

	/**
	 * Build a bitmap from an image AST node.
	 *
	 * Resolution goes through Local_Image_Resolver, so a data URI is decoded in
	 * place, a local WordPress URL is read off disk, and a remote URL resolves to
	 * nothing rather than being fetched — this runs inside the printer's job
	 * fetch, where an outbound request would stall the print.
	 *
	 * @param array $node     The image AST node (`src`, `width`).
	 * @param int   $max_dots Printable width of the paper, in dots.
	 *
	 * @return self|null The bitmap, or null when the image is unusable.
	 */
	public static function from_node( array $node, int $max_dots ): ?self {
		$bytes = ( new Local_Image_Resolver() )->bytes( isset( $node['src'] ) ? (string) $node['src'] : '' );

		return self::from_bytes(
			$bytes,
			isset( $node['width'] ) ? (int) $node['width'] : 0,
			$max_dots
		);
	}

	/**
	 * Build a bitmap from raw image bytes.
	 *
	 * @param string $bytes          Encoded image bytes (PNG, JPEG, GIF, ...).
	 * @param int    $requested_dots Preferred width in dots, or 0 for natural size.
	 * @param int    $max_dots       Printable width of the paper, in dots.
	 *
	 * @return self|null The bitmap, or null when the bytes are not a usable image.
	 */
	public static function from_bytes( string $bytes, int $requested_dots, int $max_dots ): ?self {
		if ( '' === $bytes || ! \function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image data returns false rather than warning.
		$source = @imagecreatefromstring( $bytes );
		if ( false === $source ) {
			return null;
		}

		// A decoded image always has at least one pixel in each axis, so the
		// division below needs no zero guard.
		$natural_width  = imagesx( $source );
		$natural_height = imagesy( $source );

		$max_dots = max( 8, $max_dots );
		$target   = $requested_dots > 0 ? min( $requested_dots, $max_dots ) : min( $natural_width, $max_dots );
		$target   = max( 1, $target );
		$height   = max( 1, (int) round( $natural_height * ( $target / $natural_width ) ) );

		if ( $height > self::MAX_HEIGHT ) {
			// unset() rather than imagedestroy(): the call has been a no-op since
			// PHP 8.0 and is deprecated as of 8.5, while dropping the only
			// reference frees the handle on 8.x and on 7.4 alike. See the same
			// note in Raster_Thermal_Emitter.
			unset( $source );

			return null;
		}

		// ePOS-Print wants the width in whole bytes ("set the image width to a
		// multiple of 8, or set the missing bits to 0" — ePOS-Print XML User's
		// Manual). Padding here rather than in each lane keeps every lane's
		// packing loop free of a partial trailing byte.
		$padded = (int) ( ceil( $target / 8 ) * 8 );

		$scaled = imagecreatetruecolor( $padded, $height );
		if ( false === $scaled ) {
			unset( $source );

			return null;
		}

		// White ground, so the pad columns read as blank paper and a logo with an
		// alpha channel composites onto white rather than onto black.
		imagefilledrectangle( $scaled, 0, 0, $padded - 1, $height - 1, imagecolorallocate( $scaled, 255, 255, 255 ) );
		imagecopyresampled( $scaled, $source, 0, 0, 0, 0, $target, $height, $natural_width, $natural_height );
		unset( $source );

		$raster = self::pack( $scaled, $padded, $height );
		unset( $scaled );

		return new self( $padded, $height, $raster );
	}

	/**
	 * The width in dots. Always a multiple of 8.
	 *
	 * @return int
	 */
	public function width(): int {
		return $this->width;
	}

	/**
	 * The height in dots.
	 *
	 * @return int
	 */
	public function height(): int {
		return $this->height;
	}

	/**
	 * The dots row by row: width/8 bytes per row, MSB first, set bit = black dot.
	 *
	 * This is the layout ESC/POS `GS v 0` and ePOS-Print `<image>` both take.
	 *
	 * @return string
	 */
	public function raster(): string {
		return $this->raster;
	}

	/**
	 * The number of bytes in one raster row.
	 *
	 * @return int
	 */
	public function bytes_per_row(): int {
		return (int) ( $this->width / 8 );
	}

	/**
	 * Read one dot.
	 *
	 * Out-of-range coordinates read as white, so a caller packing fixed-height
	 * bands does not have to special-case the last, short band.
	 *
	 * @param int $x Column, from the left.
	 * @param int $y Row, from the top.
	 *
	 * @return int 1 for a black dot, 0 for blank paper.
	 */
	public function pixel( int $x, int $y ): int {
		if ( $x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height ) {
			return 0;
		}

		$byte = \ord( $this->raster[ ( $y * $this->bytes_per_row() ) + ( $x >> 3 ) ] );

		return ( $byte >> ( 7 - ( $x % 8 ) ) ) & 1;
	}

	/**
	 * Threshold a GD image into row-major packed dots.
	 *
	 * @param resource|object $image  The scaled image (GD resource on PHP 7.4, GdImage on 8+).
	 * @param int             $width  Width in dots (a multiple of 8).
	 * @param int             $height Height in dots.
	 *
	 * @return string The packed raster.
	 */
	private static function pack( $image, int $width, int $height ): string {
		$raster = '';

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x += 8 ) {
				$byte = 0;
				for ( $bit = 0; $bit < 8; $bit++ ) {
					$rgb = imagecolorat( $image, $x + $bit, $y );
					// Rec. 601 luma, the standard grey weighting, thresholded at
					// mid-grey — the same conversion Raster_Thermal_Emitter uses.
					$luma = ( 0.299 * ( ( $rgb >> 16 ) & 0xFF ) )
						+ ( 0.587 * ( ( $rgb >> 8 ) & 0xFF ) )
						+ ( 0.114 * ( $rgb & 0xFF ) );

					if ( $luma < self::BLACK_THRESHOLD ) {
						$byte |= 1 << ( 7 - $bit );
					}
				}
				$raster .= \chr( $byte );
			}
		}

		return $raster;
	}
}
