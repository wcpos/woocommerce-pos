<?php
/**
 * Barcode / QR code image helper for the PDF render path.
 *
 * Dompdf cannot render inline `<svg>` barcodes (nor SVG embedded via an `<img>`
 * data URI) — both come out blank, leaving only the order number as text. PNG
 * raster images embedded as `<img>` data URIs render reliably, so this helper
 * rasterizes 1D barcodes (picqer) and QR codes (chillerlan) to PNG and returns
 * a self-contained `<img>` tag. It also rewrites `<barcode>` / `<qrcode>` markup
 * (the logicless gallery template syntax) into those `<img>` tags, mirroring the
 * client-side preview renderer so the PDF matches the on-screen receipt.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS\Templates
 */

namespace WCPOS\WooCommercePOS\Templates;

use Throwable;
use WCPOS\Vendor\chillerlan\QRCode\Output\QROutputInterface;
use WCPOS\Vendor\chillerlan\QRCode\QRCode;
use WCPOS\Vendor\chillerlan\QRCode\QROptions;
use WCPOS\Vendor\Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Barcode_Image class.
 */
class Barcode_Image {

	/**
	 * Build an `<img>` tag for a 1D barcode, or an empty string on failure.
	 *
	 * @param string $type   The barcode symbology (e.g. code128, ean13).
	 * @param string $value  The barcode value.
	 * @param int    $height The barcode height in pixels.
	 *
	 * @return string The `<img>` tag, or '' when the value is empty or generation fails.
	 */
	public static function barcode_img( string $type, string $value, int $height = 40 ): string {
		$text = trim( $value );
		if ( '' === $text ) {
			return '';
		}

		try {
			$png = self::without_vendor_deprecations(
				static function () use ( $text, $type, $height ): string {
					$generator = new BarcodeGeneratorPNG();
					// picqer defaults to Imagick when the extension is loaded, but a
					// PNG-less Imagick build (seen on some hosts) throws. Prefer GD,
					// which is near-universal and the recommended WordPress image lib.
					if ( \function_exists( 'imagecreate' ) ) {
						$generator->useGd();
					}

					// Clamp the height so a malformed template dimension cannot
					// allocate a huge raster.
					return $generator->getBarcode( $text, self::barcode_constant( $type ), 2, max( 8, min( 600, $height ) ) );
				}
			);

			// bwip-js renders the human-readable value under the bars in the
			// client-side previews; mirror it so the PDF matches on-screen.
			return self::img_from_png( $png ) . self::barcode_text( $text );
		} catch ( Throwable $error ) {
			return '';
		}
	}

	/**
	 * Build the human-readable text line shown beneath a 1D barcode.
	 *
	 * @param string $value The barcode value.
	 *
	 * @return string The HTML fragment.
	 */
	private static function barcode_text( string $value ): string {
		return '<div style="text-align: center; font-family: \'DejaVu Sans Mono\', Menlo, Consolas, monospace; '
			. 'font-size: 1em; letter-spacing: 2px; line-height: 1.3; margin-top: 1px">'
			. esc_html( $value ) . '</div>';
	}

	/**
	 * Build an `<img>` tag for a QR code, or an empty string on failure.
	 *
	 * @param string $value The QR code value.
	 * @param int    $size  The QR module scale (pixels per module).
	 *
	 * @return string The `<img>` tag, or '' when the value is empty or generation fails.
	 */
	public static function qrcode_img( string $value, int $size = 4 ): string {
		$text = trim( $value );
		if ( '' === $text ) {
			return '';
		}

		try {
			$png = self::without_vendor_deprecations(
				static function () use ( $text, $size ): string {
					$options = new QROptions(
						array(
							'outputType'  => QROutputInterface::GDIMAGE_PNG,
							'imageBase64' => false,
							// Clamp the scale so a malformed template dimension cannot
							// allocate a huge raster.
							'scale'       => max( 2, min( 40, $size ) ),
						)
					);

					return ( new QRCode( $options ) )->render( $text );
				}
			);

			return self::img_from_png( $png );
		} catch ( Throwable $error ) {
			return '';
		}
	}

	/**
	 * Replace `<barcode>` / `<qrcode>` markup with opaque placeholder tokens.
	 *
	 * Each element that rasterizes successfully is swapped for a plain-text token,
	 * and its `<img>` tag is stored in `$images` keyed by that token. The caller is
	 * expected to sanitize the returned HTML and then splice the images back in
	 * (e.g. `strtr( wp_kses_post( $html ), $images )`). This keeps the `data:` image
	 * URI out of the sanitizer entirely, so no `data:` protocol allowance — which
	 * would widen the protocol allow-list for every URI attribute — is needed.
	 * Elements whose value is empty, or which fail to rasterize, are left untouched
	 * so the sanitizer can drop them.
	 *
	 * @param string $html   The rendered template HTML.
	 * @param array  $images Filled with token => `<img>` HTML for the caller to splice in.
	 *
	 * @return string The HTML with barcode markup replaced by placeholder tokens.
	 */
	public static function replace_markup( string $html, array &$images ): string {
		if ( false === stripos( $html, '<barcode' ) && false === stripos( $html, '<qrcode' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<(barcode|qrcode)\b([^>]*)>(.*?)</\1>#is',
			static function ( array $match ) use ( &$images ): string {
				$img = self::element_to_img( strtolower( $match[1] ), $match[2], $match[3] );
				if ( '' === $img ) {
					return $match[0];
				}

				$token            = 'WCPOSBARCODEPLACEHOLDER' . \count( $images ) . 'X';
				$images[ $token ] = $img;

				return $token;
			},
			$html
		);
	}

	/**
	 * Rasterize a single `<barcode>` / `<qrcode>` element to an `<img>` tag.
	 *
	 * Mirrors the client-side logicless preview renderer: the value is the
	 * `data-value` attribute or the text content; the symbology is the
	 * `data-barcode` or `type` attribute (a `<qrcode>` tag, a qr/qrcode type, or a
	 * typeless element all render a QR code, matching the preview).
	 *
	 * @param string $tag   The lowercased tag name (barcode|qrcode).
	 * @param string $attrs The raw attribute string.
	 * @param string $inner The element's inner content.
	 *
	 * @return string The `<img>` tag, or '' when empty or rasterization fails.
	 */
	private static function element_to_img( string $tag, string $attrs, string $inner ): string {
		$value = self::attr( $attrs, 'data-value' );
		$value = '' !== $value ? $value : trim( wp_strip_all_tags( $inner ) );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === trim( $value ) ) {
			return '';
		}

		if ( 'qrcode' === $tag ) {
			$raw_type = 'qrcode';
		} else {
			$raw_type = self::attr( $attrs, 'data-barcode' );
			$raw_type = '' !== $raw_type ? $raw_type : self::attr( $attrs, 'type' );
			$raw_type = '' !== $raw_type ? $raw_type : 'qr';
		}
		$type = strtolower( trim( $raw_type ) );

		if ( 'qr' === $type || 'qrcode' === $type ) {
			$size = (int) self::attr( $attrs, 'size' );

			return self::qrcode_img( $value, $size > 0 ? $size : 4 );
		}

		$height = (int) self::attr( $attrs, 'height' );

		return self::barcode_img( $type, $value, $height > 0 ? $height : 40 );
	}

	/**
	 * Wrap raw PNG bytes in an `<img>` data-URI tag constrained to the receipt width.
	 *
	 * @param string $png The raw PNG image bytes.
	 *
	 * @return string The `<img>` tag.
	 */
	private static function img_from_png( string $png ): string {
		return '<img src="data:image/png;base64,' . base64_encode( self::flatten_png( $png ) ) . '" '
			. 'style="max-width: 100%; height: auto" alt="" />';
	}

	/**
	 * Flatten a PNG onto an opaque white background, dropping any alpha channel.
	 *
	 * The picqer barcode generator emits PNGs with a transparent background (some
	 * QR builds do too). Dompdf routes transparent PNGs through an Imagick
	 * alpha-extraction path that fails
	 * on hosts whose Imagick build lacks a PNG delegate (`Unable to set format`).
	 * An opaque PNG takes Dompdf's plain GD path instead, which is what receipts
	 * need anyway (black on white). Returns the input unchanged if GD is missing
	 * or the image cannot be decoded.
	 *
	 * @param string $png The raw PNG image bytes.
	 *
	 * @return string The opaque PNG bytes.
	 */
	private static function flatten_png( string $png ): string {
		if ( ! \function_exists( 'imagecreatefromstring' ) ) {
			return $png;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image data returns false rather than warning.
		$source = @imagecreatefromstring( $png );
		if ( false === $source ) {
			return $png;
		}

		$width  = imagesx( $source );
		$height = imagesy( $source );
		$canvas = imagecreatetruecolor( $width, $height );
		$white  = (int) imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $width, $height, $white );
		imagealphablending( $canvas, true );
		imagecopy( $canvas, $source, 0, 0, 0, 0, $width, $height );
		imagesavealpha( $canvas, false );

		ob_start();
		imagepng( $canvas );
		$flat = (string) ob_get_clean();

		return '' !== $flat ? $flat : $png;
	}

	/**
	 * Read a single HTML attribute value out of an attribute string.
	 *
	 * @param string $attrs The raw attribute string (e.g. ` type="code128" height="40"`).
	 * @param string $name  The attribute name.
	 *
	 * @return string The attribute value, or '' when absent.
	 */
	private static function attr( string $attrs, string $name ): string {
		if ( preg_match( '#\b' . preg_quote( $name, '#' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $attrs, $m ) ) {
			return ( $m[2] ?? '' ) . ( $m[3] ?? '' ) . ( $m[4] ?? '' );
		}

		return '';
	}

	/**
	 * Map a barcode type string to a picqer generator constant.
	 *
	 * @param string $type The barcode type string.
	 *
	 * @return string The picqer TYPE_* constant value.
	 */
	private static function barcode_constant( string $type ): string {
		$map = array(
			'code128' => BarcodeGeneratorPNG::TYPE_CODE_128,
			'code39'  => BarcodeGeneratorPNG::TYPE_CODE_39,
			'code93'  => BarcodeGeneratorPNG::TYPE_CODE_93,
			'ean13'   => BarcodeGeneratorPNG::TYPE_EAN_13,
			'ean8'    => BarcodeGeneratorPNG::TYPE_EAN_8,
			'upca'    => BarcodeGeneratorPNG::TYPE_UPC_A,
			'upce'    => BarcodeGeneratorPNG::TYPE_UPC_E,
			'codabar' => BarcodeGeneratorPNG::TYPE_CODABAR,
			'itf'     => BarcodeGeneratorPNG::TYPE_INTERLEAVED_2_5,
		);

		$normalized = strtolower( trim( $type ) );

		return isset( $map[ $normalized ] ) ? $map[ $normalized ] : BarcodeGeneratorPNG::TYPE_CODE_128;
	}

	/**
	 * Run a callable with E_DEPRECATED notices from the vendored libraries silenced.
	 *
	 * The prefixed picqer PNG generator calls imagedestroy(), deprecated on PHP
	 * 8.5; the notice is harmless but would otherwise spam logs on each receipt.
	 *
	 * @param callable $callback The callback to execute.
	 *
	 * @return string The callback's return value.
	 */
	private static function without_vendor_deprecations( callable $callback ): string {
		$previous_handler = null;
		$previous_handler = set_error_handler(
			static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$previous_handler ): bool {
				if (
					0 !== ( $errno & ( E_DEPRECATED | E_USER_DEPRECATED ) )
					&& false !== strpos( str_replace( '\\', '/', $errfile ), '/vendor_prefixed/' )
				) {
					return true;
				}

				if ( \is_callable( $previous_handler ) ) {
					return (bool) \call_user_func( $previous_handler, $errno, $errstr, $errfile, $errline );
				}

				return false;
			}
		);

		try {
			return (string) $callback();
		} finally {
			restore_error_handler();
		}
	}
}
