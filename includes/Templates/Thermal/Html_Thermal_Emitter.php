<?php
/**
 * HTML Thermal Emitter Class.
 *
 * Renders an HTML receipt string from a thermal AST (produced by
 * Thermal_Markup_Parser). This is a PHP port of the JS receipt-renderer
 * `render-html.ts`, used for the thermal -> PDF path (Dompdf). The output mirrors
 * the JS renderer's CONTENT; bwip-js is swapped for the vendor-prefixed picqer
 * barcode generator (1D barcodes) and chillerlan QR code generator (QR codes).
 *
 * Deliberate deviations from the JS renderer (Dompdf has no flexbox engine and
 * no `ch` unit, so the JS renderer's flex rows would collapse):
 *  - Rows are emitted as real single-row tables with em-based column widths
 *    (1 monospace character ≈ 0.6em), Dompdf's most reliable layout primitive.
 *  - The base font size is scaled so the template's character grid exactly
 *    fills the paper width passed by the caller, matching what the printer
 *    does on a physical roll.
 *  - Images (`<image>`) are emitted as plain `<img>` tags; Pdf_Renderer embeds
 *    local WordPress images (store logos) as data URIs before Dompdf renders.
 *    Remote image URLs stay blank because Dompdf remote access is disabled.
 *  - Barcode/QR rendering uses PHP libraries that emit PNG `<img>` tags. On any
 *    failure the value is rendered as escaped monospace text instead of throwing.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Barcode_Image;

/**
 * Html_Thermal_Emitter class.
 */
class Html_Thermal_Emitter {

	/**
	 * Advance width of a monospace character relative to the font size.
	 */
	private const CHAR_WIDTH_EM = 0.6;

	/**
	 * Printer dot budgets for image sizing: wide (80mm, ≥40 columns) printers
	 * are 576 dots across, narrow (58mm) 384. Mirrors the JS preview renderer —
	 * keep in sync with packages/receipt-renderer/src/render-html.ts in the
	 * wcpos monorepo, or PDF/preview parity silently drifts.
	 */
	private const DOT_BUDGET_WIDE = 576;

	/**
	 * Narrow-roll printer dot budget (see DOT_BUDGET_WIDE sync note).
	 */
	private const DOT_BUDGET_NARROW = 384;

	/**
	 * Column count at/above which the wide dot budget applies.
	 */
	private const NARROW_PAPER_THRESHOLD_CHARS = 40;

	/**
	 * Wrapper side padding in px. Mirrors the JS preview renderer's receipt
	 * frame (render-html.ts, see DOT_BUDGET_WIDE sync note); in the PDF path
	 * Pdf_Layout_Preprocessor lifts this padding into the @page margins.
	 */
	private const PADDING_X_PX = 12.0;

	/**
	 * Wrapper top/bottom padding in px (see PADDING_X_PX).
	 */
	private const PADDING_Y_PX = 16.0;

	/**
	 * Render an HTML receipt string from a thermal AST.
	 *
	 * @param array $ast  The thermal AST root (a receipt node).
	 * @param array $opts Optional: 'paper_width_px' — the PDF paper width in CSS
	 *                    px; the base font is scaled so the template's character
	 *                    grid fills the printable width like a real roll printer.
	 *
	 * @return string The receipt HTML.
	 */
	public function emit( array $ast, array $opts = array() ): string {
		$width_chars = $this->safe_integer( isset( $ast['paper_width'] ) ? $ast['paper_width'] : null, 48, 16, 120 );

		// 13px matches the JS preview renderer's base font; with a known paper
		// width the font scales so the grid fills the printable width instead.
		$font_px = 13.0;
		if ( isset( $opts['paper_width_px'] ) && is_numeric( $opts['paper_width_px'] ) ) {
			$inner_px = (float) $opts['paper_width_px'] - 2 * self::PADDING_X_PX;
			if ( $inner_px > 50 ) {
				// Clamp to a legible range so a malformed paper/column combination
				// cannot produce microscopic or oversized receipt text.
				$font_px = max( 6.0, min( 14.0, $inner_px / ( $width_chars * self::CHAR_WIDTH_EM ) ) );
			}
		}

		$children = isset( $ast['children'] ) && \is_array( $ast['children'] ) ? $ast['children'] : array();
		$inner    = $this->render_nodes( $children, $width_chars );

		return '<div style="font-family: \'Courier New\', Courier, monospace; '
			. 'font-size: ' . $this->format_float( $font_px ) . 'px; line-height: 1.4; background: #fff; color: #000; '
			. 'padding: ' . $this->format_float( self::PADDING_Y_PX ) . 'px ' . $this->format_float( self::PADDING_X_PX ) . 'px; '
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
				// Standalone column outside a row: plain aligned block.
				return '<div style="text-align: ' . $this->safe_align( isset( $node['align'] ) ? $node['align'] : null ) . '">'
					. $this->render_nodes( $children, $width_chars ) . '</div>';
			case 'line':
				return $this->render_line( $node );
			case 'barcode':
				$barcode_type = isset( $node['barcode_type'] ) ? (string) $node['barcode_type'] : 'code128';
				$value        = isset( $node['value'] ) ? (string) $node['value'] : '';
				if ( $this->is_qr_barcode_type( $barcode_type ) ) {
					return $this->render_qrcode( $value, $this->height_to_qr_size( isset( $node['height'] ) ? (int) $node['height'] : 40 ) );
				}
				return $this->render_barcode( $barcode_type, $value, isset( $node['height'] ) ? (int) $node['height'] : 40 );
			case 'qrcode':
				$value = isset( $node['value'] ) ? (string) $node['value'] : '';
				$size  = isset( $node['size'] ) ? (int) $node['size'] : 4;
				return $this->render_qrcode( $value, $size );
			case 'feed':
				$lines = $this->safe_integer( isset( $node['lines'] ) ? $node['lines'] : null, 1, 1, 50 );
				return '<div style="height: ' . $this->format_float( $lines * 1.4 ) . 'em"></div>';
			case 'cut':
				// The scissors glyph is missing from the monospace core fonts, so
				// it gets the bundled DejaVu face (present in every Dompdf install).
				return '<div style="border-top: 1px dashed #ccc; margin: 12px 0; position: relative">'
					. '<span style="position: absolute; top: -8px; left: -4px; font-size: 14px; font-family: \'DejaVu Sans\', sans-serif">&#9986;</span></div>';
			case 'receipt':
				return $this->render_nodes( $children, $width_chars );
			case 'image':
				return $this->render_image( $node, $width_chars );
			case 'drawer':
			default:
				return '';
		}
	}

	/**
	 * Render a row as a single-row table of columns.
	 *
	 * Dompdf has no flexbox engine and no `ch` unit, so the JS renderer's flex
	 * rows are expressed as a fixed-layout table: fixed columns get em widths
	 * (chars × 0.6em) and `*` columns share the remaining width.
	 *
	 * @param array $node        The row AST node.
	 * @param int   $width_chars The receipt character width.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_row( array $node, int $width_chars ): string {
		$cols  = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();
		$cells = '';
		foreach ( $cols as $col ) {
			if ( \is_array( $col ) ) {
				$cells .= $this->render_row_cell( $col, $width_chars );
			}
		}

		return '<table style="width: 100%; table-layout: fixed; border-collapse: collapse"><tr>' . $cells . '</tr></table>';
	}

	/**
	 * Render a single row column as a table cell.
	 *
	 * @param array $node        The col AST node.
	 * @param int   $width_chars The receipt character width.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_row_cell( array $node, int $width_chars ): string {
		$width       = isset( $node['width'] ) ? $node['width'] : 12;
		$width_style = '';
		if ( '*' !== $width ) {
			$chars       = $this->safe_integer( $width, 12, 1, 120 );
			$width_style = 'width: ' . $this->format_float( $chars * self::CHAR_WIDTH_EM ) . 'em; ';
		}

		$align    = $this->safe_align( isset( $node['align'] ) ? $node['align'] : null );
		$children = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();

		return '<td style="' . $width_style . 'text-align: ' . $align . '; vertical-align: top; padding: 0; overflow: hidden">'
			. $this->render_nodes( $children, $width_chars ) . '</td>';
	}

	/**
	 * Render an image node as a centered <img>.
	 *
	 * Image widths are authored in printer dots; mirroring the JS renderer they
	 * scale by the paper's dot budget so the image keeps the same fraction of
	 * the receipt width (em-based because Dompdf has no `ch` unit). Local
	 * WordPress URLs (store logos, uploads) are embedded as data URIs by
	 * Pdf_Renderer before Dompdf sees the HTML; remote URLs render blank because
	 * remote access stays disabled.
	 *
	 * @param array $node        The image AST node.
	 * @param int   $width_chars The receipt character width.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_image( array $node, int $width_chars ): string {
		$src = trim( isset( $node['src'] ) ? (string) $node['src'] : '' );
		if ( '' === $src ) {
			return '';
		}

		$width_dots = $this->safe_integer( isset( $node['width'] ) ? $node['width'] : null, 200, 1, 2000 );
		$dot_budget = $width_chars >= self::NARROW_PAPER_THRESHOLD_CHARS ? self::DOT_BUDGET_WIDE : self::DOT_BUDGET_NARROW;
		$width_em   = $width_dots * $width_chars / $dot_budget * self::CHAR_WIDTH_EM;

		return '<div style="text-align: center; padding: 8px 0">'
			. '<img src="' . $this->escape_html( $src ) . '" alt="" style="width: ' . $this->format_float( $width_em ) . 'em; max-width: 100%; height: auto" />'
			. '</div>';
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
	 * Render a 1D barcode as a centered PNG image, falling back to text on failure.
	 *
	 * @param string $barcode_type The barcode symbology string.
	 * @param string $value        The barcode value.
	 * @param int    $height       The barcode height in pixels.
	 *
	 * @return string The HTML fragment.
	 */
	private function render_barcode( string $barcode_type, string $value, int $height = 40 ): string {
		$text = trim( $value );
		if ( '' === $text ) {
			return '';
		}

		$img = Barcode_Image::barcode_img( $barcode_type, $text, $height );

		return '' !== $img
			? '<div style="text-align: center; padding: 8px 0">' . $img . '</div>'
			: $this->render_barcode_fallback( $text );
	}

	/**
	 * Render a QR code as a centered PNG image, falling back to text on failure.
	 *
	 * @param string $value The QR code value.
	 * @param int    $size  The QR code module scale (pixels per module).
	 *
	 * @return string The HTML fragment.
	 */
	private function render_qrcode( string $value, int $size ): string {
		$text = trim( $value );
		if ( '' === $text ) {
			return '';
		}

		$img = Barcode_Image::qrcode_img( $text, $size );

		return '' !== $img
			? '<div style="text-align: center; padding: 8px 0">' . $img . '</div>'
			: $this->render_barcode_fallback( $text );
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
