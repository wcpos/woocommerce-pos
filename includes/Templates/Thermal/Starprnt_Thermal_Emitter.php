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
 *  - Images (`<image>`) are skipped entirely, as in Escpos_Thermal_Emitter.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

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
				// Skipped: server-side rasterization is out of scope.
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
		$this->raw_string( $this->normalize_text( $value ) );
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
			$plain = $this->normalize_text( $this->extract_text( $children ) );
			$pad   = $this->alignment_padding( $this->display_width( $plain ) );
			if ( $pad > 0 ) {
				$this->raw_string( str_repeat( ' ', $pad ) );
			}
		}
		$this->walk_nodes( $children );
		$this->newline();
	}

	/**
	 * Compute the leading-space padding for the current non-left alignment.
	 *
	 * @param int $text_width The display width of the line's plain text.
	 *
	 * @return int The number of leading spaces (clamped at 0).
	 */
	private function alignment_padding( int $text_width ): int {
		$remaining = $this->columns - $text_width;
		if ( $remaining <= 0 ) {
			return 0;
		}
		if ( 'center' === $this->align ) {
			return (int) floor( $remaining / 2 );
		}

		return $remaining;
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
		$width           = isset( $node['width'] ) ? max( 1, (int) $node['width'] ) : 1;
		$height          = isset( $node['height'] ) ? max( 1, (int) $node['height'] ) : 1;

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
		$widths = $this->resolve_row_widths( $cols );

		$line = '';
		foreach ( $cols as $index => $col ) {
			$width = isset( $widths[ $index ] ) ? $widths[ $index ] : 1;
			$text  = $this->normalize_text( $this->extract_text( isset( $col['children'] ) ? $col['children'] : array() ) );
			$text  = $this->truncate_display( $text, $width );
			$pad   = max( 0, $width - $this->display_width( $text ) );
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
	 * Resolve concrete column widths for a row, splitting star columns.
	 *
	 * @param array $cols The column AST nodes.
	 *
	 * @return array The resolved integer widths, indexed by column.
	 */
	private function resolve_row_widths( array $cols ): array {
		$fixed_total = 0;
		$star_count  = 0;
		foreach ( $cols as $col ) {
			if ( isset( $col['width'] ) && '*' === $col['width'] ) {
				$star_count++;
			} else {
				$fixed_total += isset( $col['width'] ) ? (int) $col['width'] : 0;
			}
		}

		$remaining      = max( 0, $this->columns - $fixed_total );
		$star_width     = $star_count > 0 ? (int) floor( $remaining / $star_count ) : 0;
		$star_remainder = $star_count > 0 ? $remaining - ( $star_width * $star_count ) : 0;

		$widths     = array();
		$star_index = 0;
		foreach ( $cols as $index => $col ) {
			if ( isset( $col['width'] ) && '*' === $col['width'] ) {
				$star_index++;
				$extra            = ( $star_index === $star_count ) ? $star_remainder : 0;
				$widths[ $index ] = max( 1, $star_width + $extra );
			} else {
				$widths[ $index ] = isset( $col['width'] ) ? (int) $col['width'] : 0;
			}
		}

		return $widths;
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
	 * Emit a CODE128 barcode using ESC b, terminated by RS.
	 *
	 * Parameters follow Star's reference plugin: type 6 (Code128), no HRI,
	 * module width 2, height clamped to the printable 8-255 dot range.
	 *
	 * @param array $node The barcode AST node.
	 *
	 * @return void
	 */
	private function emit_barcode( array $node ): void {
		$value  = isset( $node['value'] ) ? (string) $node['value'] : '';
		$height = isset( $node['height'] ) ? (int) $node['height'] : 40;
		$height = max( 8, min( 255, $height ) );

		$data = substr( $value, 0, 255 );
		$this->raw( array( 0x1b, 0x62, 0x06, 0x01, 0x02, $height ) );
		$this->raw_string( $data );
		$this->raw( array( 0x1e ) );
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
		$size  = max( 1, min( 8, $size ) );

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
		$lines = isset( $node['lines'] ) ? max( 1, (int) $node['lines'] ) : 1;
		for ( $index = 0; $index < $lines; $index++ ) {
			$this->raw( array( 0x0a ) );
		}
	}

	/**
	 * Emit a single newline.
	 *
	 * @return void
	 */
	private function newline(): void {
		$this->raw( array( 0x0a ) );
	}

	/**
	 * Normalize text by replacing non-ASCII typographic characters.
	 *
	 * @param string $value The input text.
	 *
	 * @return string The normalized text.
	 */
	private function normalize_text( string $value ): string {
		$search  = array( "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}" );
		$value   = str_replace( $search, '-', $value );
		$value   = str_replace( array( "\u{2018}", "\u{2019}" ), "'", $value );
		$value   = str_replace( array( "\u{201C}", "\u{201D}" ), '"', $value );
		$value   = str_replace( "\u{00A0}", ' ', $value );

		return $value;
	}

	/**
	 * Compute the display width of a string (full-width chars count as 2).
	 *
	 * @param string $value The input text.
	 *
	 * @return int The display width.
	 */
	private function display_width( string $value ): int {
		$width = 0;
		$chars = $this->split_chars( $value );
		foreach ( $chars as $char ) {
			$width += $this->is_full_width( $char ) ? 2 : 1;
		}

		return $width;
	}

	/**
	 * Truncate a string to a maximum display width.
	 *
	 * @param string $value The input text.
	 * @param int    $width The maximum display width.
	 *
	 * @return string The truncated text.
	 */
	private function truncate_display( string $value, int $width ): string {
		$result = '';
		$used   = 0;
		$chars  = $this->split_chars( $value );
		foreach ( $chars as $char ) {
			$next = $this->is_full_width( $char ) ? 2 : 1;
			if ( $used + $next > $width ) {
				break;
			}
			$result .= $char;
			$used    += $next;
		}

		return $result;
	}

	/**
	 * Split a UTF-8 string into an array of characters.
	 *
	 * @param string $value The input text.
	 *
	 * @return array The characters.
	 */
	private function split_chars( string $value ): array {
		if ( '' === $value ) {
			return array();
		}
		if ( function_exists( 'mb_str_split' ) ) {
			return mb_str_split( $value, 1, 'UTF-8' );
		}
		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );

		return false === $chars ? array() : $chars;
	}

	/**
	 * Whether a single character is full-width / CJK.
	 *
	 * @param string $char The single UTF-8 character.
	 *
	 * @return bool True when the character is full-width.
	 */
	private function is_full_width( string $char ): bool {
		$code = $this->code_point( $char );
		if ( $code < 0 ) {
			return false;
		}

		return ( $code >= 0x1100 && $code <= 0x115f )
			|| 0x2329 === $code
			|| 0x232a === $code
			|| ( $code >= 0x2e80 && $code <= 0xa4cf )
			|| ( $code >= 0xac00 && $code <= 0xd7a3 )
			|| ( $code >= 0xf900 && $code <= 0xfaff )
			|| ( $code >= 0xfe10 && $code <= 0xfe19 )
			|| ( $code >= 0xfe30 && $code <= 0xfe6f )
			|| ( $code >= 0xff00 && $code <= 0xff60 )
			|| ( $code >= 0xffe0 && $code <= 0xffe6 );
	}

	/**
	 * Resolve the Unicode code point of a single character.
	 *
	 * @param string $char The single UTF-8 character.
	 *
	 * @return int The code point, or -1 when undetermined.
	 */
	private function code_point( string $char ): int {
		if ( function_exists( 'mb_ord' ) ) {
			// mb_ord() is typed int by stubs; cast guards a theoretical false (invalid
			// char) to 0, which is_full_width() treats as not full-width.
			return (int) mb_ord( $char, 'UTF-8' );
		}
		$length = strlen( $char );
		if ( 1 === $length ) {
			return ord( $char );
		}
		if ( 2 === $length ) {
			return ( ( ord( $char[0] ) & 0x1f ) << 6 ) | ( ord( $char[1] ) & 0x3f );
		}
		if ( 3 === $length ) {
			return ( ( ord( $char[0] ) & 0x0f ) << 12 ) | ( ( ord( $char[1] ) & 0x3f ) << 6 ) | ( ord( $char[2] ) & 0x3f );
		}
		if ( 4 === $length ) {
			return ( ( ord( $char[0] ) & 0x07 ) << 18 ) | ( ( ord( $char[1] ) & 0x3f ) << 12 ) | ( ( ord( $char[2] ) & 0x3f ) << 6 ) | ( ord( $char[3] ) & 0x3f );
		}

		return -1;
	}

	/**
	 * Extract the concatenated raw text of a node subtree.
	 *
	 * @param array $nodes The AST nodes.
	 *
	 * @return string The concatenated text.
	 */
	private function extract_text( array $nodes ): string {
		$text = '';
		foreach ( $nodes as $node ) {
			if ( ! \is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['type'] ) && 'raw-text' === $node['type'] ) {
				$text .= isset( $node['value'] ) ? (string) $node['value'] : '';
			} elseif ( isset( $node['children'] ) && \is_array( $node['children'] ) ) {
				$text .= $this->extract_text( $node['children'] );
			}
		}

		return $text;
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
