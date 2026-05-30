<?php
/**
 * HTML Thermal Emitter Class.
 *
 * Renders an HTML receipt string from a thermal AST (produced by
 * Thermal_Markup_Parser). This is a PHP port of the JS receipt-renderer
 * `render-html.ts`, used for the thermal -> PDF path (Dompdf). The output mirrors
 * the JS renderer's STRUCTURE; bwip-js is swapped for the vendor-prefixed picqer
 * barcode generator (1D barcodes) and chillerlan QR code generator (QR codes).
 *
 * Deliberate deviations from the JS renderer:
 *  - Images (`<image>`) are skipped entirely; server-side rasterization is out of
 *    scope, so the emitter writes nothing for image nodes.
 *  - Barcode/QR rendering uses PHP libraries that emit standalone SVG, which is
 *    constrained to fit the receipt width. On any failure the value is rendered as
 *    escaped monospace text instead of throwing.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

use Throwable;
use WCPOS\Vendor\chillerlan\QRCode\Output\QROutputInterface;
use WCPOS\Vendor\chillerlan\QRCode\QRCode;
use WCPOS\Vendor\chillerlan\QRCode\QROptions;
use WCPOS\Vendor\Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Html_Thermal_Emitter class.
 */
class Html_Thermal_Emitter {

	/**
	 * Render an HTML receipt string from a thermal AST.
	 *
	 * @param array $ast The thermal AST root (a receipt node).
	 *
	 * @return string The receipt HTML.
	 */
	public function emit( array $ast ): string {
		$width_chars = $this->safe_integer( isset( $ast['paper_width'] ) ? $ast['paper_width'] : null, 48, 16, 120 );

		$children = isset( $ast['children'] ) && \is_array( $ast['children'] ) ? $ast['children'] : array();
		$inner    = $this->render_nodes( $children, $width_chars );

		return '<div style="width: ' . $width_chars . 'ch; font-family: \'Courier New\', Courier, monospace; '
			. 'font-size: 13px; line-height: 1.4; background: #fff; color: #000; padding: 16px 12px; '
			. 'overflow: hidden; white-space: pre-wrap; word-break: break-all;">' . $inner . '</div>';
	}

	/**
	 * Render a list of AST nodes.
	 *
	 * @param array $nodes       The AST nodes.
	 * @param int   $width_chars The receipt character width.
	 *
	 * @return string The concatenated HTML.
	 */
	private function render_nodes( array $nodes, int $width_chars ): string {
		$html = '';
		foreach ( $nodes as $node ) {
			if ( \is_array( $node ) ) {
				$html .= $this->render_node( $node, $width_chars );
			}
		}

		return $html;
	}

	/**
	 * Render a single AST node.
	 *
	 * @param array $node        The AST node.
	 * @param int   $width_chars The receipt character width.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_node( array $node, int $width_chars ): string {
		$type     = isset( $node['type'] ) ? $node['type'] : '';
		$children = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();

		switch ( $type ) {
			case 'raw-text':
				return $this->escape_html( isset( $node['value'] ) ? (string) $node['value'] : '' );
			case 'text':
				return '<div>' . $this->render_nodes( $children, $width_chars ) . '</div>';
			case 'bold':
				return '<strong>' . $this->render_nodes( $children, $width_chars ) . '</strong>';
			case 'underline':
				return '<span style="text-decoration: underline">' . $this->render_nodes( $children, $width_chars ) . '</span>';
			case 'invert':
				return '<span style="background: #000; color: #fff; padding: 0 4px">' . $this->render_nodes( $children, $width_chars ) . '</span>';
			case 'size':
				$em = $this->safe_float( isset( $node['width'] ) ? $node['width'] : null, 1, 0.5, 8 );
				return '<span style="font-size: ' . $this->format_float( $em ) . 'em; line-height: 1.2">' . $this->render_nodes( $children, $width_chars ) . '</span>';
			case 'align':
				$mode = $this->safe_align( isset( $node['mode'] ) ? $node['mode'] : null );
				return '<div style="text-align: ' . $mode . '">' . $this->render_nodes( $children, $width_chars ) . '</div>';
			case 'row':
				return $this->render_row( $node, $width_chars );
			case 'col':
				return $this->render_col( $node, $width_chars );
			case 'line':
				return $this->render_line( $node );
			case 'barcode':
				$barcode_type = isset( $node['barcode_type'] ) ? (string) $node['barcode_type'] : 'code128';
				$value        = isset( $node['value'] ) ? (string) $node['value'] : '';
				if ( $this->is_qr_barcode_type( $barcode_type ) ) {
					return $this->render_qrcode( $value, $this->height_to_qr_size( isset( $node['height'] ) ? (int) $node['height'] : 40 ) );
				}
				return $this->render_barcode( $barcode_type, $value );
			case 'qrcode':
				$value = isset( $node['value'] ) ? (string) $node['value'] : '';
				$size  = isset( $node['size'] ) ? (int) $node['size'] : 4;
				return $this->render_qrcode( $value, $size );
			case 'feed':
				$lines = $this->safe_integer( isset( $node['lines'] ) ? $node['lines'] : null, 1, 1, 50 );
				return '<div style="height: ' . $this->format_float( $lines * 1.4 ) . 'em"></div>';
			case 'cut':
				return '<div style="border-top: 1px dashed #ccc; margin: 12px 0; position: relative">'
					. '<span style="position: absolute; top: -8px; left: -4px; font-size: 14px">&#9986;</span></div>';
			case 'receipt':
				return $this->render_nodes( $children, $width_chars );
			case 'image':
				// Skipped: server-side rasterization is out of scope.
			case 'drawer':
			default:
				return '';
		}
	}

	/**
	 * Render a row as a flex container of columns.
	 *
	 * @param array $node        The row AST node.
	 * @param int   $width_chars The receipt character width.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_row( array $node, int $width_chars ): string {
		$cols = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();
		$html = '';
		foreach ( $cols as $col ) {
			if ( \is_array( $col ) ) {
				$html .= $this->render_col( $col, $width_chars );
			}
		}

		return '<div style="display: flex">' . $html . '</div>';
	}

	/**
	 * Render a single column.
	 *
	 * @param array $node        The col AST node.
	 * @param int   $width_chars The receipt character width.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_col( array $node, int $width_chars ): string {
		$width = isset( $node['width'] ) ? $node['width'] : 12;
		if ( '*' === $width ) {
			$flex = 'flex: 1';
		} else {
			$flex = 'flex: 0 0 ' . $this->safe_integer( $width, 12, 1, 120 ) . 'ch';
		}

		$align    = $this->safe_align( isset( $node['align'] ) ? $node['align'] : null );
		$children = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();

		return '<span style="' . $flex . '; text-align: ' . $align . '; overflow: hidden">'
			. $this->render_nodes( $children, $width_chars ) . '</span>';
	}

	/**
	 * Render a horizontal rule line.
	 *
	 * @param array $node The line AST node.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_line( array $node ): string {
		$style = isset( $node['style'] ) ? $node['style'] : 'single';

		if ( 'double' === $style ) {
			return '<hr style="border: none; border-top: 3px double #000; margin: 4px 0" />';
		}
		if ( 'dashed' === $style ) {
			return '<hr style="border: none; border-top: 1px dashed #000; margin: 4px 0" />';
		}
		if ( 'dotted' === $style ) {
			return '<hr style="border: none; border-top: 1px dotted #000; margin: 4px 0" />';
		}

		return '<hr style="border: none; border-top: 1px solid #000; margin: 4px 0" />';
	}

	/**
	 * Render a 1D barcode as a centered SVG, falling back to text on failure.
	 *
	 * @param string $barcode_type The barcode symbology string.
	 * @param string $value        The barcode value.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_barcode( string $barcode_type, string $value ): string {
		$text = trim( $value );
		if ( '' === $text ) {
			return '';
		}

		try {
			$generator = new BarcodeGeneratorSVG();
			$svg       = $generator->getBarcode( $text, $this->barcode_constant( $barcode_type ) );

			return '<div style="text-align: center; padding: 8px 0">' . $this->constrain_svg( $svg ) . '</div>';
		} catch ( Throwable $error ) {
			return $this->render_barcode_fallback( $text );
		}
	}

	/**
	 * Render a QR code as a centered SVG, falling back to text on failure.
	 *
	 * @param string $value The QR code value.
	 * @param int    $size  The QR code module size hint (unused by chillerlan SVG).
	 *
	 * @return string The HTML fragment.
	 */
	private function render_qrcode( string $value, int $size ): string {
		$text = trim( $value );
		if ( '' === $text ) {
			return '';
		}

		try {
			// $size is intentionally NOT mapped to QROptions::scale: scale only
			// affects raster (PNG/GIF) output — the MARKUP_SVG renderer emits a
			// vector with a module-unit viewBox and no width/height, so scale is a
			// no-op there. Sizing is handled responsively by constrain_svg (capped
			// to the receipt width), which is the intended look on narrow paper.
			$options = new QROptions(
				array(
					'outputType'  => QROutputInterface::MARKUP_SVG,
					'imageBase64' => false,
				)
			);
			$svg = ( new QRCode( $options ) )->render( $text );

			return '<div style="text-align: center; padding: 8px 0">' . $this->constrain_svg( $svg ) . '</div>';
		} catch ( Throwable $error ) {
			return $this->render_barcode_fallback( $text );
		}
	}

	/**
	 * Render the escaped value as monospace fallback text.
	 *
	 * @param string $text The value to render.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_barcode_fallback( string $text ): string {
		return '<div style="text-align: center; padding: 8px 0"><code>' . $this->escape_html( $text ) . '</code></div>';
	}

	/**
	 * Constrain a standalone SVG so it embeds and fits inside the receipt HTML.
	 *
	 * Strips a leading XML declaration that the generators may emit and injects a
	 * max-width/height style so Dompdf renders it within the receipt width.
	 *
	 * @param string $svg The SVG markup.
	 *
	 * @return string The constrained SVG markup.
	 */
	private function constrain_svg( string $svg ): string {
		$svg = preg_replace( '/^\s*<\?xml[^>]*\?>\s*/', '', $svg );
		$svg = null === $svg ? '' : $svg;

		$pos = strpos( $svg, '<svg ' );
		if ( false === $pos ) {
			return $svg;
		}

		return substr_replace( $svg, '<svg style="max-width: 100%; height: auto" ', $pos, \strlen( '<svg ' ) );
	}

	/**
	 * Map an AST barcode type string to a picqer generator constant.
	 *
	 * @param string $barcode_type The barcode type string.
	 *
	 * @return string The picqer TYPE_* constant value.
	 */
	private function barcode_constant( string $barcode_type ): string {
		$normalized = strtolower( trim( $barcode_type ) );

		$map = array(
			'code128' => BarcodeGeneratorSVG::TYPE_CODE_128,
			'code39'  => BarcodeGeneratorSVG::TYPE_CODE_39,
			'code93'  => BarcodeGeneratorSVG::TYPE_CODE_93,
			'ean13'   => BarcodeGeneratorSVG::TYPE_EAN_13,
			'ean8'    => BarcodeGeneratorSVG::TYPE_EAN_8,
			'upca'    => BarcodeGeneratorSVG::TYPE_UPC_A,
			'upce'    => BarcodeGeneratorSVG::TYPE_UPC_E,
			'codabar' => BarcodeGeneratorSVG::TYPE_CODABAR,
			'itf'     => BarcodeGeneratorSVG::TYPE_INTERLEAVED_2_5,
		);

		return isset( $map[ $normalized ] ) ? $map[ $normalized ] : BarcodeGeneratorSVG::TYPE_CODE_128;
	}

	/**
	 * Determine whether a barcode type should be rendered as a QR code.
	 *
	 * @param string $type The barcode type string.
	 *
	 * @return bool True when the type is a QR variant.
	 */
	private function is_qr_barcode_type( string $type ): bool {
		$normalized = strtolower( trim( $type ) );

		return 'qrcode' === $normalized || 'qr' === $normalized;
	}

	/**
	 * Convert a barcode height into a QR code size, mirroring heightToQrSize.
	 *
	 * @param int $height The barcode height.
	 *
	 * @return int The QR code size clamped between 2 and 10, or 4 by default.
	 */
	private function height_to_qr_size( int $height ): int {
		if ( $height <= 0 ) {
			return 4;
		}

		$size = (int) round( $height / 10 );

		return max( 2, min( 10, $size ) );
	}

	/**
	 * Resolve a bounded integer with a fallback, mirroring the JS safeInteger.
	 *
	 * @param mixed $value    The candidate value.
	 * @param int   $fallback The fallback value.
	 * @param int   $min      The minimum allowed value.
	 * @param int   $max      The maximum allowed value.
	 *
	 * @return int The resolved integer.
	 */
	private function safe_integer( $value, int $fallback, int $min, int $max ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		$number = (int) $value;

		return ( $number >= $min && $number <= $max ) ? $number : $fallback;
	}

	/**
	 * Resolve a bounded float with a fallback, mirroring the JS safeFloat.
	 *
	 * @param mixed $value    The candidate value.
	 * @param float $fallback The fallback value.
	 * @param float $min      The minimum allowed value.
	 * @param float $max      The maximum allowed value.
	 *
	 * @return float The resolved float.
	 */
	private function safe_float( $value, float $fallback, float $min, float $max ): float {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		$number = (float) $value;

		return ( $number >= $min && $number <= $max ) ? $number : $fallback;
	}

	/**
	 * Format a float for CSS output, trimming a trailing ".0".
	 *
	 * @param float $value The value to format.
	 *
	 * @return string The formatted value.
	 */
	private function format_float( float $value ): string {
		$formatted = rtrim( rtrim( sprintf( '%.2f', $value ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	/**
	 * Resolve an alignment value to a valid CSS text-align keyword.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return string One of left, center, or right.
	 */
	private function safe_align( $value ): string {
		if ( 'center' === $value || 'right' === $value || 'left' === $value ) {
			return $value;
		}

		return 'left';
	}

	/**
	 * HTML-escape a string for safe embedding.
	 *
	 * @param string $value The input text.
	 *
	 * @return string The escaped text.
	 */
	private function escape_html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}
