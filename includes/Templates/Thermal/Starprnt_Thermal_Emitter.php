<?php
/**
 * StarPRNT Thermal Emitter Class.
 *
 * Emits native StarPRNT command bytes from a thermal AST (produced by
 * Thermal_Markup_Parser) for Star CloudPRNT printers served as
 * `application/vnd.star.starprnt`. StarPRNT-native printers (the whole TSP100
 * line, mC-Print in StarPRNT mode) cannot decode ESC/POS, so Star jobs must be
 * emitted in this command set.
 *
 * The command bytes follow Star's own MIT-licensed reference implementation
 * (star-cloudprnt-for-woocommerce, `printer_star_prnt.inc.php`) and were
 * cross-checked against the StarPRNT language module of
 * NielsLeenheer/ReceiptPrinterEncoder. Text layout (alignment padding, rows,
 * rules, width handling) mirrors Escpos_Thermal_Emitter so the printed layout
 * matches across providers.
 *
 * Deliberate deviations / notes:
 *  - No initialize command is emitted. CloudPRNT jobs must not reset the
 *    device mid-session; jobs open with the UTF-8 encoding select sequence
 *    instead so non-ASCII text decodes correctly.
 *  - Cut uses the feed-then-cut variants (ESC d 2/3) like Star's reference
 *    plugin, so the last lines clear the cutter before cutting.
 *  - Star printers adjust the line feed pitch for magnified text themselves,
 *    so there is no scaled line-spacing handling.
 *  - Images (`<image>`) are thresholded to 1-bit dots by Thermal_Bitmap and sent
 *    as `ESC X` column graphics, Star having no row-major raster command to
 *    match ESC/POS `GS v 0`.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Barcode_Symbology;

/**
 * Starprnt_Thermal_Emitter class.
 */
class Starprnt_Thermal_Emitter {

	/**
	 * Render options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Accumulated output bytes.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * The paper width in character columns.
	 *
	 * @var int
	 */
	private $columns = 48;

	/**
	 * The current alignment mode (left|center|right).
	 *
	 * @var string
	 */
	private $align = 'left';

	/**
	 * Whether bold is currently active.
	 *
	 * @var bool
	 */
	private $bold = false;

	/**
	 * Whether underline is currently active.
	 *
	 * @var bool
	 */
	private $underline = false;

	/**
	 * Whether invert is currently active.
	 *
	 * @var bool
	 */
	private $invert = false;

	/**
	 * The current text width multiplier.
	 *
	 * @var int
	 */
	private $width = 1;

	/**
	 * The current text height multiplier.
	 *
	 * @var int
	 */
	private $height = 1;

	/**
	 * Whether unterminated text is sitting in the printer's line buffer.
	 *
	 * Star's graphics and barcode commands, like their ESC/POS counterparts, are
	 * line-oriented: `ESC X` starts a raster band and `ESC b` a barcode, and both
	 * expect to begin at the start of a line. Bare text in a template
	 * (`<receipt>Total<image/></receipt>`) parses to a `raw-text` node, which
	 * prints without a terminator, so the emitter tracks whether a line is open.
	 *
	 * @var bool
	 */
	private $line_open = false;

	/**
	 * Constructor.
	 *
	 * @param array $options Render options.
	 */
	public function __construct( array $options = array() ) {
		$this->options = $options;
	}

	/**
	 * Emit native StarPRNT bytes from a thermal AST.
	 *
	 * @param array $ast The thermal AST root (a receipt node).
	 *
	 * @return string The raw StarPRNT bytes.
	 */
	public function emit( array $ast ): string {
		$this->buffer    = '';
		$this->align     = 'left';
		$this->bold      = false;
		$this->underline = false;
		$this->invert    = false;
		$this->width     = 1;
		$this->height    = 1;
		$this->line_open = false;

		$this->columns = isset( $ast['paper_width'] ) ? (int) $ast['paper_width'] : 48;

		// ESC GS ) U — select UTF-8 encoding, then the companion font/width
		// setting, per Star's reference implementation. No initialize command:
		// CloudPRNT jobs must not reset the printer.
		$this->raw( array( 0x1b, 0x1d, 0x29, 0x55, 0x02, 0x00, 0x30, 0x01 ) );
		$this->raw( array( 0x1b, 0x1d, 0x29, 0x55, 0x02, 0x00, 0x40, 0x00 ) );

		$children = isset( $ast['children'] ) && \is_array( $ast['children'] ) ? $ast['children'] : array();
		$this->walk_nodes( $this->nodes_with_auto_drawer( $children ) );

		return $this->buffer;
	}

	/**
	 * Walk a list of AST nodes.
	 *
	 * @param array $nodes The AST nodes.
	 *
	 * @return void
	 */
	private function walk_nodes( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( \is_array( $node ) ) {
				$this->walk_node( $node );
			}
		}
	}

	/**
	 * Insert an auto drawer node before the first trailing cut when enabled.
	 *
	 * @param array $nodes AST nodes.
	 *
	 * @return array
	 */
	private function nodes_with_auto_drawer( array $nodes ): array {
		if ( empty( $this->options['auto_open_drawer'] ) || $this->nodes_contain_drawer( $nodes ) ) {
			return $nodes;
		}

		$drawer = array(
			'type'      => 'drawer',
			'connector' => \WCPOS\WooCommercePOS\Services\Print_Job_Service::normalize_drawer_connector( (string) ( $this->options['drawer_connector'] ?? 'pin2' ) ),
		);

		for ( $i = count( $nodes ) - 1; $i >= 0; $i-- ) {
			$type = isset( $nodes[ $i ]['type'] ) ? (string) $nodes[ $i ]['type'] : '';
			if ( 'cut' === $type ) {
				array_splice( $nodes, $i, 0, array( $drawer ) );
				return $nodes;
			}
			if ( in_array( $type, array( 'feed' ), true ) ) {
				continue;
			}
			break;
		}

		$nodes[] = $drawer;
		return $nodes;
	}

	/**
	 * Whether a node list contains an explicit drawer node.
	 *
	 * @param array $nodes AST nodes.
	 *
	 * @return bool
	 */
	private function nodes_contain_drawer( array $nodes ): bool {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( 'drawer' === ( $node['type'] ?? '' ) ) {
				return true;
			}
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) && $this->nodes_contain_drawer( $node['children'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Emit a StarPRNT drawer pulse.
	 *
	 * ESC BEL sets the pulse width (on/off in 10ms units), then the trigger
	 * byte fires the peripheral: 0x07 for device 1 (pin2), 0x1A for device 2
	 * (pin5).
	 *
	 * @param string $connector Drawer connector.
	 */
	private function emit_drawer_pulse( string $connector ): void {
		$connector = \WCPOS\WooCommercePOS\Services\Print_Job_Service::normalize_drawer_connector( $connector );
		$trigger   = 'pin5' === $connector ? 0x1a : 0x07;

		$this->raw( array( 0x1b, 0x07, 0x0a, 0x0a, $trigger ) );
	}

	/**
	 * Walk a single AST node.
	 *
	 * @param array $node The AST node.
	 *
	 * @return void
	 */
	private function walk_node( array $node ): void {
		$type = isset( $node['type'] ) ? $node['type'] : '';

		switch ( $type ) {
			case 'raw-text':
				$this->emit_inline_text( isset( $node['value'] ) ? (string) $node['value'] : '' );
				break;
			case 'text':
				$this->emit_text_line( isset( $node['children'] ) ? $node['children'] : array() );
				break;
			case 'bold':
				$this->emit_bold( $node );
				break;
			case 'underline':
				$this->emit_underline( $node );
				break;
			case 'invert':
				$this->emit_invert( $node );
				break;
			case 'size':
				$this->emit_size( $node );
				break;
			case 'align':
				$this->emit_align( $node );
				break;
			case 'row':
				$this->emit_row( $node );
				break;
			case 'line':
				$this->emit_line( $node );
				break;
			case 'barcode':
				$this->emit_barcode( $node );
				break;
			case 'qrcode':
				$this->emit_qrcode( $node );
				break;
			case 'image':
				$this->emit_image( $node );
				break;
			case 'cut':
				$this->emit_cut( $node );
				break;
			case 'feed':
				$this->emit_feed( $node );
				break;
			case 'drawer':
				$this->emit_drawer_pulse( isset( $node['connector'] ) ? (string) $node['connector'] : 'pin2' );
				break;
			case 'receipt':
				$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
				break;
		}
	}

	/**
	 * Emit inline (styled) text bytes for the current line.
	 *
	 * @param string $value The raw text value.
	 *
	 * @return void
	 */
	private function emit_inline_text( string $value ): void {
		$text = Thermal_Text_Layout::normalize_text( $value );
		if ( '' === $text ) {
			return;
		}

		$this->raw_string( $text );

		// The parser preserves a text node verbatim, newlines included, so this
		// may have ended the line itself — `<receipt>Total\n<image/></receipt>`
		// leaves the printer at column zero. Reading the state off the bytes just
		// written keeps close_open_line() from spending a second line feed there.
		$this->line_open = "\n" !== substr( $text, -1 );
	}

	/**
	 * Close an open line so a line-oriented command can start cleanly.
	 *
	 * @return void
	 */
	private function close_open_line(): void {
		if ( $this->line_open ) {
			$this->newline();
		}
	}

	/**
	 * Emit a single printed text line (the children, padding, then a newline).
	 *
	 * @param array $children The child nodes of the text node.
	 *
	 * @return void
	 */
	private function emit_text_line( array $children ): void {
		if ( 'left' !== $this->align ) {
			$plain = Thermal_Text_Layout::normalize_text( Thermal_Text_Layout::extract_text( $children ) );
			$pad   = Thermal_Text_Layout::alignment_padding( $this->align, Thermal_Text_Layout::display_width( $plain ), $this->columns );
			if ( $pad > 0 ) {
				$this->raw_string( str_repeat( ' ', $pad ) );
			}
		}
		$this->walk_nodes( $children );
		$this->newline();
	}

	/**
	 * Emit a bold-wrapped block using ESC E / ESC F.
	 *
	 * @param array $node The bold AST node.
	 *
	 * @return void
	 */
	private function emit_bold( array $node ): void {
		$previous = $this->bold;
		$this->raw( array( 0x1b, 0x45 ) );
		$this->bold = true;
		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
		$this->raw( $previous ? array( 0x1b, 0x45 ) : array( 0x1b, 0x46 ) );
		$this->bold = $previous;
	}

	/**
	 * Emit an underline-wrapped block using ESC - n.
	 *
	 * @param array $node The underline AST node.
	 *
	 * @return void
	 */
	private function emit_underline( array $node ): void {
		$previous = $this->underline;
		$this->raw( array( 0x1b, 0x2d, 0x01 ) );
		$this->underline = true;
		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
		$this->raw( array( 0x1b, 0x2d, $previous ? 0x01 : 0x00 ) );
		$this->underline = $previous;
	}

	/**
	 * Emit an invert-wrapped block using ESC 4 / ESC 5.
	 *
	 * @param array $node The invert AST node.
	 *
	 * @return void
	 */
	private function emit_invert( array $node ): void {
		$previous = $this->invert;
		$this->raw( array( 0x1b, 0x34 ) );
		$this->invert = true;
		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
		$this->raw( $previous ? array( 0x1b, 0x34 ) : array( 0x1b, 0x35 ) );
		$this->invert = $previous;
	}

	/**
	 * Emit a size-wrapped block using ESC i (height, width).
	 *
	 * @param array $node The size AST node.
	 *
	 * @return void
	 */
	private function emit_size( array $node ): void {
		$previous_width  = $this->width;
		$previous_height = $this->height;
		$width           = Thermal_Bounds::clamp_int( isset( $node['width'] ) ? $node['width'] : null, 1, Thermal_Bounds::SIZE_MULTIPLIER_MIN, Thermal_Bounds::SIZE_MULTIPLIER_MAX );
		$height          = Thermal_Bounds::clamp_int( isset( $node['height'] ) ? $node['height'] : null, 1, Thermal_Bounds::SIZE_MULTIPLIER_MIN, Thermal_Bounds::SIZE_MULTIPLIER_MAX );

		$this->raw( array( 0x1b, 0x69, $this->magnification_byte( $height ), $this->magnification_byte( $width ) ) );
		$this->width  = $width;
		$this->height = $height;

		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );

		$this->raw( array( 0x1b, 0x69, $this->magnification_byte( $previous_height ), $this->magnification_byte( $previous_width ) ) );
		$this->width  = $previous_width;
		$this->height = $previous_height;
	}

	/**
	 * Compute the ESC i magnification byte for a multiplier (0-based, max 6x).
	 *
	 * @param int $multiplier The width or height multiplier.
	 *
	 * @return int The ESC i parameter byte.
	 */
	private function magnification_byte( int $multiplier ): int {
		return max( 0, min( 5, $multiplier - 1 ) );
	}

	/**
	 * Emit an alignment-wrapped block using ESC GS a.
	 *
	 * @param array $node The align AST node.
	 *
	 * @return void
	 */
	private function emit_align( array $node ): void {
		$previous = $this->align;
		$mode     = isset( $node['mode'] ) ? $node['mode'] : 'left';
		$this->raw( array( 0x1b, 0x1d, 0x61, $this->align_byte( $mode ) ) );
		$this->align = $mode;

		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );

		$this->raw( array( 0x1b, 0x1d, 0x61, $this->align_byte( $previous ) ) );
		$this->align = $previous;
	}

	/**
	 * Map an alignment mode to its ESC GS a parameter byte.
	 *
	 * @param string $mode The alignment mode.
	 *
	 * @return int The ESC GS a parameter byte.
	 */
	private function align_byte( string $mode ): int {
		if ( 'center' === $mode ) {
			return 0x01;
		}
		if ( 'right' === $mode ) {
			return 0x02;
		}

		return 0x00;
	}

	/**
	 * Emit a row as one physical line followed by a newline.
	 *
	 * @param array $node The row AST node.
	 *
	 * @return void
	 */
	private function emit_row( array $node ): void {
		$cols   = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();
		$widths = Thermal_Text_Layout::resolve_row_widths( $cols, $this->columns );

		$line = '';
		foreach ( $cols as $index => $col ) {
			$width = isset( $widths[ $index ] ) ? $widths[ $index ] : 1;
			$text  = Thermal_Text_Layout::normalize_text( Thermal_Text_Layout::extract_text( isset( $col['children'] ) ? $col['children'] : array() ) );
			$text  = Thermal_Text_Layout::truncate_display( $text, $width );
			$pad   = max( 0, $width - Thermal_Text_Layout::display_width( $text ) );
			$align = isset( $col['align'] ) ? $col['align'] : 'left';
			if ( 'right' === $align ) {
				$line .= str_repeat( ' ', $pad ) . $text;
			} else {
				$line .= $text . str_repeat( ' ', $pad );
			}
		}

		$this->raw_string( $line );
		$this->newline();
	}

	/**
	 * Emit a horizontal rule line.
	 *
	 * @param array $node The line AST node.
	 *
	 * @return void
	 */
	private function emit_line( array $node ): void {
		$style = isset( $node['style'] ) ? $node['style'] : 'single';

		if ( 'dotted' === $style ) {
			$pattern = '. ';
			$repeat  = (int) ceil( $this->columns / \strlen( $pattern ) );
			$text    = substr( str_repeat( $pattern, $repeat ), 0, $this->columns );
		} elseif ( 'double' === $style ) {
			$text = str_repeat( '=', $this->columns );
		} else {
			// single and dashed both render as '-' across the width.
			$text = str_repeat( '-', $this->columns );
		}

		$this->raw_string( $text );
		$this->newline();
	}

	/**
	 * Emit a 1D barcode using ESC b, terminated by RS.
	 *
	 * `ESC b n1 n2 n3 n4 <data> RS` — StarPRNT Command Specifications Ver 1.3E,
	 * barcode section. n1 is the symbology (owned by Barcode_Symbology; note
	 * Star numbers the UPC pair the opposite way round to ESC/POS), n2 = 2 for
	 * "HRI under the bars, line feed after printing" — matching the preview, the
	 * PDF and the raster lane, and matching Star's own reference plugin, which
	 * sets n2 = 2 whenever HRI is asked for — n3 = 2 for the medium module width
	 * (valid for every symbology we emit), and n4 is the height in dots, clamped
	 * to the printable 8-255 range.
	 *
	 * A StarPRNT printer handed data its symbology cannot encode discards the
	 * command up to the RS terminator without reporting an error, so an
	 * unencodable value is printed as text instead.
	 *
	 * @param array $node The barcode AST node.
	 *
	 * @return void
	 */
	private function emit_barcode( array $node ): void {
		$value = isset( $node['value'] ) ? (string) $node['value'] : '';
		if ( '' === trim( $value ) ) {
			return;
		}

		// Before the validation branch, not after: ESC b starts a barcode block,
		// and the rescue below centres its text against the full paper width, so
		// both outcomes need the line closed first.
		$this->close_open_line();

		$type   = isset( $node['barcode_type'] ) ? (string) $node['barcode_type'] : 'code128';
		$height = isset( $node['height'] ) ? (int) $node['height'] : 40;
		// The 8-dot floor is Star's, not the markup's: Thermal_Bounds allows a
		// 1-dot barcode and ESC/POS prints one, but ESC b rejects anything
		// shorter than 8. A device-specific bound, so it stays here.
		$height = max( 8, min( Thermal_Bounds::BARCODE_HEIGHT_MAX, $height ) );

		if ( ! Barcode_Symbology::is_valid_value( $type, $value, Barcode_Symbology::LANE_STARPRNT ) ) {
			$this->emit_centered_text( $value );

			return;
		}

		$this->raw( array( 0x1b, 0x62, Barcode_Symbology::starprnt_id( $type ), 0x02, 0x02, $height ) );
		$this->raw_string( Barcode_Symbology::starprnt_payload( $type, $value ) );
		$this->raw( array( 0x1e ) );
	}

	/**
	 * Print a template `<image>` (in practice, the store logo).
	 *
	 * `ESC X nL nH d1..dk` — Star's column graphics, taken from the StarPRNT
	 * language module of NielsLeenheer/ReceiptPrinterEncoder, the same source the
	 * rest of this emitter was cross-checked against. Star has no row-major
	 * raster command to match ESC/POS `GS v 0`: the image goes out in 24-dot
	 * bands, three bytes per column, top bit first, so the dots are transposed
	 * out of the bitmap here. Line spacing is set to 24 dots (`ESC 0`) for the
	 * duration so consecutive bands butt together instead of leaving white
	 * stripes, and restored to the default (`ESC z 1`) afterwards.
	 *
	 * The image is centred unconditionally, ignoring any enclosing `<align>`.
	 * That is the contract the other three renderers already keep — the preview
	 * (thermal-renderer.ts), the PDF (Html_Thermal_Emitter::render_image()) and
	 * the raster lane (Raster_Thermal_Emitter::draw_image()) all hard-centre an
	 * `<image>` — and inheriting the wrapper's alignment instead would left-align
	 * the bare `<image>` the template editor inserts, which all three show
	 * centred.
	 *
	 * A src that resolves to nothing (a remote URL, a missing file) prints
	 * nothing, and in particular does not disturb the line spacing.
	 *
	 * @param array $node The image AST node.
	 *
	 * @return void
	 */
	private function emit_image( array $node ): void {
		$bitmap = Thermal_Bitmap::from_node( $node, Thermal_Bounds::paper_dots( $this->columns ) );
		if ( null === $bitmap ) {
			return;
		}

		// ESC X starts a raster band; close any open text line first.
		$this->close_open_line();

		$width  = $bitmap->width();
		$height = $bitmap->height();

		$this->raw( array( 0x1b, 0x1d, 0x61, $this->align_byte( 'center' ) ) );
		$this->raw( array( 0x1b, 0x30 ) ); // ESC 0 — 24-dot line spacing.

		for ( $top = 0; $top < $height; $top += 24 ) {
			$this->raw( array( 0x1b, 0x58, $width & 0xff, ( $width >> 8 ) & 0xff ) );

			$band = '';
			for ( $x = 0; $x < $width; $x++ ) {
				for ( $byte_index = 0; $byte_index < 3; $byte_index++ ) {
					$byte = 0;
					for ( $bit = 0; $bit < 8; $bit++ ) {
						// pixel() reads out of range as blank, which is what makes
						// the last band safe when the height is not a multiple of 24.
						$byte |= $bitmap->pixel( $x, $top + ( $byte_index * 8 ) + $bit ) << ( 7 - $bit );
					}
					$band .= \chr( $byte );
				}
			}

			$this->raw_string( $band );
			$this->raw( array( 0x0a, 0x0d ) );
		}

		$this->raw( array( 0x1b, 0x7a, 0x01 ) ); // ESC z 1 — default line spacing.
		$this->raw( array( 0x1b, 0x1d, 0x61, $this->align_byte( $this->align ) ) );
	}

	/**
	 * Print a value as a centered plain-text line.
	 *
	 * Mirrors the rescue in Html_Thermal_Emitter::render_barcode_fallback(): when
	 * the symbol cannot be produced, the value itself is still readable.
	 *
	 * Control bytes are folded to spaces first. This is the one path that routes
	 * a barcode value into the text stream, and a barcode value is exactly where
	 * a stray tab, LF or CR turns up — Code 128 validation rejects them on the
	 * ESC/POS lane precisely because code set B cannot encode them, which sends
	 * them here. Emitted raw they would break the line the rescue is centering.
	 *
	 * @param string $value The value to print.
	 *
	 * @return void
	 */
	private function emit_centered_text( string $value ): void {
		$text = Thermal_Text_Layout::normalize_text( $this->strip_control_bytes( $value ) );
		$pad  = (int) floor( max( 0, $this->columns - Thermal_Text_Layout::display_width( $text ) ) / 2 );
		if ( $pad > 0 ) {
			$this->raw_string( str_repeat( ' ', $pad ) );
		}
		$this->raw_string( $text );
		$this->newline();
	}

	/**
	 * Replace control bytes with spaces so they cannot reach the print stream.
	 *
	 * @param string $value The value to clean.
	 *
	 * @return string The value with control bytes folded to spaces.
	 */
	private function strip_control_bytes( string $value ): string {
		$cleaned = preg_replace( '/[\x00-\x1f\x7f]/', ' ', $value );

		return null === $cleaned ? $value : $cleaned;
	}

	/**
	 * Emit a model-2 QR code using the ESC GS y command family.
	 *
	 * @param array $node The qrcode AST node.
	 *
	 * @return void
	 */
	private function emit_qrcode( array $node ): void {
		$value = isset( $node['value'] ) ? (string) $node['value'] : '';
		$size  = isset( $node['size'] ) ? (int) $node['size'] : 4;
		// Star's QR module size tops out at 8, half the ESC/POS ceiling in
		// Thermal_Bounds::QRCODE_SIZE_MAX. Device-specific, so it stays here.
		$size  = max( Thermal_Bounds::QRCODE_SIZE_MIN, min( 8, $size ) );

		// ESC GS y prints a QR block; close any open text line first.
		$this->close_open_line();

		// Select model 2.
		$this->raw( array( 0x1b, 0x1d, 0x79, 0x53, 0x30, 0x02 ) );
		// Set error correction level (M).
		$this->raw( array( 0x1b, 0x1d, 0x79, 0x53, 0x31, 0x01 ) );
		// Set cell size.
		$this->raw( array( 0x1b, 0x1d, 0x79, 0x53, 0x32, $size ) );

		// Store data.
		$data = substr( $value, 0, 0xffff );
		$p_l  = \strlen( $data ) & 0xff;
		$p_h  = ( \strlen( $data ) >> 8 ) & 0xff;
		$this->raw( array( 0x1b, 0x1d, 0x79, 0x44, 0x31, 0x00, $p_l, $p_h ) );
		$this->raw_string( $data );

		// Print the stored symbol.
		$this->raw( array( 0x1b, 0x1d, 0x79, 0x50 ) );
	}

	/**
	 * Emit a paper cut command (feed-then-cut variants).
	 *
	 * @param array $node The cut AST node.
	 *
	 * @return void
	 */
	private function emit_cut( array $node ): void {
		$cut_type = isset( $node['cut_type'] ) ? $node['cut_type'] : 'partial';
		$this->raw( array( 0x1b, 0x64, 'full' === $cut_type ? 0x02 : 0x03 ) );
	}

	/**
	 * Emit a paper feed of N lines.
	 *
	 * @param array $node The feed AST node.
	 *
	 * @return void
	 */
	private function emit_feed( array $node ): void {
		$lines = Thermal_Bounds::clamp_int(
			isset( $node['lines'] ) ? $node['lines'] : null,
			Thermal_Bounds::FEED_LINES_MIN,
			Thermal_Bounds::FEED_LINES_MIN,
			Thermal_Bounds::FEED_LINES_MAX
		);
		for ( $index = 0; $index < $lines; $index++ ) {
			$this->raw( array( 0x0a ) );
		}
		$this->line_open = false;
	}

	/**
	 * Emit a single newline.
	 *
	 * @return void
	 */
	private function newline(): void {
		$this->raw( array( 0x0a ) );
		$this->line_open = false;
	}

	/**
	 * Append a list of ordinal bytes to the output buffer.
	 *
	 * @param array $bytes The ordinal bytes.
	 *
	 * @return void
	 */
	private function raw( array $bytes ): void {
		foreach ( $bytes as $byte ) {
			$this->buffer .= \chr( $byte & 0xff );
		}
	}

	/**
	 * Append a raw string to the output buffer.
	 *
	 * @param string $value The string to append.
	 *
	 * @return void
	 */
	private function raw_string( string $value ): void {
		$this->buffer .= $value;
	}
}
