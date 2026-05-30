<?php
/**
 * Epson ePOS-Print XML Thermal Emitter Class.
 *
 * Maps a thermal AST (produced by Thermal_Markup_Parser) to Epson ePOS-Print
 * XML for use with Server Direct Print. The emitted document uses the same
 * namespace and escaping conventions as Epos_Xml_Output_Adapter.
 *
 * This is the template-driven counterpart to
 * `WCPOS\WooCommercePOS\Templates\Adapters\Epos_Xml_Output_Adapter`, which emits a
 * fixed, non-template layout from canonical receipt data.
 *
 * Deliberate deviations / limitations:
 *  - Double rules (`<line style="double"/>`) are emitted as ASCII `=` repeated
 *    across the paper width (consistent with the ESC/POS emitter) rather than a
 *    box-drawing glyph, so output is codepage-independent.
 *  - Paper cuts (`<cut>`), both full and partial, map to `<cut type="feed"/>`.
 *  - Images (`<image>`) are skipped entirely; server-side rasterization is out
 *    of scope, so the emitter writes nothing for image nodes.
 *  - Text is emitted as plain UTF-8 (Epson handles UTF-8); no ASCII
 *    normalization is applied.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

/**
 * Epos_Xml_Thermal_Emitter class.
 */
class Epos_Xml_Thermal_Emitter {

	/**
	 * Accumulated output XML.
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
	 * Whether bold (emphasis) is currently active.
	 *
	 * @var bool
	 */
	private $em = false;

	/**
	 * Whether underline is currently active.
	 *
	 * @var bool
	 */
	private $ul = false;

	/**
	 * Whether reverse (invert) is currently active.
	 *
	 * @var bool
	 */
	private $reverse = false;

	/**
	 * Whether double-width is currently active.
	 *
	 * @var bool
	 */
	private $dw = false;

	/**
	 * Whether double-height is currently active.
	 *
	 * @var bool
	 */
	private $dh = false;

	/**
	 * Emit ePOS-Print XML from a thermal AST.
	 *
	 * @param array $ast The thermal AST root (a receipt node).
	 *
	 * @return string The ePOS-Print XML document.
	 */
	public function emit( array $ast ): string {
		$this->buffer  = '';
		$this->align   = 'left';
		$this->em      = false;
		$this->ul      = false;
		$this->reverse = false;
		$this->dw      = false;
		$this->dh      = false;

		$this->columns = isset( $ast['paper_width'] ) ? (int) $ast['paper_width'] : 48;

		$this->buffer .= '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">';

		$children = isset( $ast['children'] ) && \is_array( $ast['children'] ) ? $ast['children'] : array();
		$this->walk_nodes( $children );

		$this->buffer .= '</epos-print>';

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
				$this->emit_text_element( isset( $node['value'] ) ? (string) $node['value'] : '' );
				break;
			case 'text':
				$this->emit_text_node( $node );
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
				$this->buffer .= '<cut type="feed"/>';
				break;
			case 'feed':
				$this->emit_feed( $node );
				break;
			case 'drawer':
				$this->buffer .= '<pulse/>';
				break;
			case 'receipt':
				$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
				break;
		}
	}

	/**
	 * Emit a single <text> element from a text node, unioning the current style
	 * state with any style wrappers found within the node's own subtree.
	 *
	 * @param array $node The text AST node.
	 *
	 * @return void
	 */
	private function emit_text_node( array $node ): void {
		$children = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();

		$previous_em      = $this->em;
		$previous_ul      = $this->ul;
		$previous_reverse = $this->reverse;
		$previous_dw      = $this->dw;
		$previous_dh      = $this->dh;

		$this->collect_subtree_styles( $children );

		$content = $this->extract_text( $children );
		$this->emit_text_element( $content );

		$this->em      = $previous_em;
		$this->ul      = $previous_ul;
		$this->reverse = $previous_reverse;
		$this->dw      = $previous_dw;
		$this->dh      = $previous_dh;
	}

	/**
	 * Union the style flags implied by wrappers within a node subtree.
	 *
	 * @param array $nodes The AST nodes to scan.
	 *
	 * @return void
	 */
	private function collect_subtree_styles( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( ! \is_array( $node ) ) {
				continue;
			}
			$type = isset( $node['type'] ) ? $node['type'] : '';
			if ( 'bold' === $type ) {
				$this->em = true;
			} elseif ( 'underline' === $type ) {
				$this->ul = true;
			} elseif ( 'invert' === $type ) {
				$this->reverse = true;
			} elseif ( 'size' === $type ) {
				$width  = isset( $node['width'] ) ? (int) $node['width'] : 1;
				$height = isset( $node['height'] ) ? (int) $node['height'] : 1;
				if ( $width > 1 ) {
					$this->dw = true;
				}
				if ( $height > 1 ) {
					$this->dh = true;
				}
			}
			if ( isset( $node['children'] ) && \is_array( $node['children'] ) ) {
				$this->collect_subtree_styles( $node['children'] );
			}
		}
	}

	/**
	 * Emit a single <text> element using the current style state.
	 *
	 * @param string $content The plain text content (will be XML-escaped).
	 *
	 * @return void
	 */
	private function emit_text_element( string $content ): void {
		$this->buffer .= '<text' . $this->style_attributes() . '>' . $this->escape( $content ) . "\n" . '</text>';
	}

	/**
	 * Build the style attribute string for the current state.
	 *
	 * @return string The attribute string (with a leading space when non-empty).
	 */
	private function style_attributes(): string {
		$attrs = '';
		if ( 'center' === $this->align || 'right' === $this->align ) {
			$attrs .= ' align="' . $this->align . '"';
		}
		if ( $this->em ) {
			$attrs .= ' em="true"';
		}
		if ( $this->ul ) {
			$attrs .= ' ul="true"';
		}
		if ( $this->reverse ) {
			$attrs .= ' reverse="true"';
		}
		if ( $this->dw ) {
			$attrs .= ' dw="true"';
		}
		if ( $this->dh ) {
			$attrs .= ' dh="true"';
		}

		return $attrs;
	}

	/**
	 * Emit a bold-wrapped block.
	 *
	 * @param array $node The bold AST node.
	 *
	 * @return void
	 */
	private function emit_bold( array $node ): void {
		$previous = $this->em;
		$this->em = true;
		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
		$this->em = $previous;
	}

	/**
	 * Emit an underline-wrapped block.
	 *
	 * @param array $node The underline AST node.
	 *
	 * @return void
	 */
	private function emit_underline( array $node ): void {
		$previous = $this->ul;
		$this->ul = true;
		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
		$this->ul = $previous;
	}

	/**
	 * Emit an invert-wrapped block.
	 *
	 * @param array $node The invert AST node.
	 *
	 * @return void
	 */
	private function emit_invert( array $node ): void {
		$previous      = $this->reverse;
		$this->reverse = true;
		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
		$this->reverse = $previous;
	}

	/**
	 * Emit a size-wrapped block.
	 *
	 * @param array $node The size AST node.
	 *
	 * @return void
	 */
	private function emit_size( array $node ): void {
		$previous_dw = $this->dw;
		$previous_dh = $this->dh;
		$width       = isset( $node['width'] ) ? (int) $node['width'] : 1;
		$height      = isset( $node['height'] ) ? (int) $node['height'] : 1;

		if ( $width > 1 ) {
			$this->dw = true;
		}
		if ( $height > 1 ) {
			$this->dh = true;
		}

		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );

		$this->dw = $previous_dw;
		$this->dh = $previous_dh;
	}

	/**
	 * Emit an alignment-wrapped block.
	 *
	 * @param array $node The align AST node.
	 *
	 * @return void
	 */
	private function emit_align( array $node ): void {
		$previous    = $this->align;
		$this->align = isset( $node['mode'] ) ? (string) $node['mode'] : 'left';
		$this->walk_nodes( isset( $node['children'] ) ? $node['children'] : array() );
		$this->align = $previous;
	}

	/**
	 * Emit a row as one left-aligned <text> line.
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
			$text  = $this->extract_text( isset( $col['children'] ) ? $col['children'] : array() );
			$text  = $this->truncate_display( $text, $width );
			$pad   = max( 0, $width - $this->display_width( $text ) );
			$align = isset( $col['align'] ) ? $col['align'] : 'left';
			if ( 'right' === $align ) {
				$line .= str_repeat( ' ', $pad ) . $text;
			} else {
				$line .= $text . str_repeat( ' ', $pad );
			}
		}

		$this->buffer .= '<text align="left">' . $this->escape( $line ) . "\n" . '</text>';
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
	 * Emit a horizontal rule as a <text> line.
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

		$this->buffer .= '<text>' . $this->escape( $text ) . "\n" . '</text>';
	}

	/**
	 * Emit a native barcode element.
	 *
	 * @param array $node The barcode AST node.
	 *
	 * @return void
	 */
	private function emit_barcode( array $node ): void {
		$value  = isset( $node['value'] ) ? (string) $node['value'] : '';
		$type   = isset( $node['barcode_type'] ) ? (string) $node['barcode_type'] : 'code128';
		$height = isset( $node['height'] ) ? (int) $node['height'] : 40;
		$height = max( 1, min( 255, $height ) );

		$this->buffer .= '<barcode type="' . $this->escape( $type ) . '" hri="none" height="' . $height . '">' . $this->escape( $value ) . '</barcode>';
	}

	/**
	 * Emit a native QR code (symbol) element.
	 *
	 * @param array $node The qrcode AST node.
	 *
	 * @return void
	 */
	private function emit_qrcode( array $node ): void {
		$value = isset( $node['value'] ) ? (string) $node['value'] : '';
		$size  = isset( $node['size'] ) ? (int) $node['size'] : 4;

		$this->buffer .= '<symbol type="qrcode_model_2" level="default" width="' . $size . '">' . $this->escape( $value ) . '</symbol>';
	}

	/**
	 * Emit a paper feed of N lines.
	 *
	 * @param array $node The feed AST node.
	 *
	 * @return void
	 */
	private function emit_feed( array $node ): void {
		$lines         = isset( $node['lines'] ) ? max( 1, (int) $node['lines'] ) : 1;
		$this->buffer .= '<feed line="' . $lines . '"/>';
	}

	/**
	 * Escape XML text content and attribute values.
	 *
	 * @param string $value Raw text.
	 *
	 * @return string The escaped text.
	 */
	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
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
		$values = unpack( 'N', mb_convert_encoding( $char, 'UCS-4BE', 'UTF-8' ) );

		return false === $values ? -1 : (int) $values[1];
	}
}
