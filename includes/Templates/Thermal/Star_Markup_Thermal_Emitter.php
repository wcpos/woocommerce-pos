<?php
/**
 * Star Document Markup Thermal Emitter.
 *
 * Emits Star Document Markup (text/vnd.star.markup) from a thermal AST produced
 * by Thermal_Markup_Parser — the same AST consumed by the ESC/POS and ePOS-XML
 * emitters. Used for the Star Online (stario.online) push provider, whose job
 * API accepts Star markup but not ESC/POS or PDF.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

/**
 * Star_Markup_Thermal_Emitter class.
 */
class Star_Markup_Thermal_Emitter {
	/**
	 * Accumulated markup output.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Paper width in character columns (for fixed-width row packing).
	 *
	 * @var int
	 */
	private int $columns = 48;

	/**
	 * Current alignment (left|center|right).
	 *
	 * @var string
	 */
	private string $align = 'left';

	/**
	 * Emit Star Document Markup from a thermal AST.
	 *
	 * @param array $ast The thermal AST root (a receipt node).
	 *
	 * @return string Star Document Markup.
	 */
	public function emit( array $ast ): string {
		$this->buffer  = '';
		$this->align   = 'left';
		$this->columns = isset( $ast['paper_width'] ) ? (int) $ast['paper_width'] : 48;

		$children = isset( $ast['children'] ) && \is_array( $ast['children'] ) ? $ast['children'] : array();
		$this->walk_nodes( $children );

		return $this->buffer;
	}

	/**
	 * Walk a list of AST nodes.
	 *
	 * @param array $nodes AST nodes.
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
	 * @param array $node AST node.
	 */
	private function walk_node( array $node ): void {
		$type = isset( $node['type'] ) ? $node['type'] : '';

		switch ( $type ) {
			case 'raw-text':
				$this->buffer .= $this->escape( isset( $node['value'] ) ? (string) $node['value'] : '' );
				break;
			case 'text':
				$this->walk_nodes( isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array() );
				break;
			case 'bold':
				$this->wrap( '[bold: on]', '[bold: off]', $node );
				break;
			case 'underline':
				$this->wrap( '[underline: on]', '[underline: off]', $node );
				break;
			case 'invert':
				$this->wrap( '[negative: on]', '[negative: off]', $node );
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
				$this->buffer .= '[cut]';
				break;
			case 'feed':
				$lines = isset( $node['lines'] ) ? max( 1, (int) $node['lines'] ) : 1;
				$this->buffer .= str_repeat( '[feed]', $lines );
				break;
			case 'drawer':
				$this->buffer .= '[drawer: on]';
				break;
			case 'receipt':
				$this->walk_nodes( isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array() );
				break;
		}
	}

	/**
	 * Emit a stateful wrapping tag pair around a node's children.
	 *
	 * @param string $open  Opening tag.
	 * @param string $close Closing tag.
	 * @param array  $node  AST node.
	 */
	private function wrap( string $open, string $close, array $node ): void {
		$this->buffer .= $open;
		$this->walk_nodes( isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array() );
		$this->buffer .= $close;
	}

	/**
	 * Emit a magnify-wrapped block, restoring 1x afterward.
	 *
	 * @param array $node Size AST node.
	 */
	private function emit_size( array $node ): void {
		$width  = isset( $node['width'] ) ? max( 1, (int) $node['width'] ) : 1;
		$height = isset( $node['height'] ) ? max( 1, (int) $node['height'] ) : 1;
		$this->buffer .= sprintf( '[magnify: width %d; height %d]', $width, $height );
		$this->walk_nodes( isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array() );
		$this->buffer .= '[magnify]';
	}

	/**
	 * Emit an alignment-wrapped block, restoring the previous alignment.
	 *
	 * @param array $node Align AST node.
	 */
	private function emit_align( array $node ): void {
		$previous    = $this->align;
		$mode        = isset( $node['mode'] ) ? (string) $node['mode'] : 'left';
		$this->align = $mode;
		$this->buffer .= '[align: ' . $this->align_value( $mode ) . ']';
		$this->walk_nodes( isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array() );
		$this->align = $previous;
		$this->buffer .= '[align: ' . $this->align_value( $previous ) . ']';
	}

	/**
	 * Map an alignment mode to its Star markup value ('center' → 'middle').
	 *
	 * @param string $mode Alignment mode.
	 *
	 * @return string
	 */
	private function align_value( string $mode ): string {
		if ( 'center' === $mode ) {
			return 'middle';
		}
		if ( 'right' === $mode ) {
			return 'right';
		}

		return 'left';
	}

	/**
	 * Emit a row as one fixed-width line so columns align predictably.
	 *
	 * @param array $node Row AST node.
	 */
	private function emit_row( array $node ): void {
		$cols   = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();
		$widths = $this->resolve_row_widths( $cols );

		$line = '';
		foreach ( $cols as $index => $col ) {
			$width    = isset( $widths[ $index ] ) ? $widths[ $index ] : 1;
			$children = isset( $col['children'] ) && \is_array( $col['children'] ) ? $col['children'] : array();
			$text     = $this->truncate( $this->extract_text( $children ), $width );
			$markup   = $this->render_inline( $children, $width );
			$pad      = max( 0, $width - $this->display_width( $text ) );
			$align    = isset( $col['align'] ) ? $col['align'] : 'left';
			$line .= ( 'right' === $align )
				? str_repeat( ' ', $pad ) . $markup
				: $markup . str_repeat( ' ', $pad );
		}

		$this->buffer .= '[fixedWidth: on]' . $line . '[fixedWidth: off]' . "\n";
	}

	/**
	 * Resolve concrete column widths for a row, splitting star columns evenly.
	 *
	 * @param array $cols Column AST nodes.
	 *
	 * @return array Integer widths indexed by column.
	 */
	private function resolve_row_widths( array $cols ): array {
		$fixed_total = 0;
		$star_count  = 0;
		foreach ( $cols as $col ) {
			if ( isset( $col['width'] ) && '*' === $col['width'] ) {
				++$star_count;
			} else {
				$fixed_total += isset( $col['width'] ) ? (int) $col['width'] : 0;
			}
		}

		$remaining  = max( 0, $this->columns - $fixed_total );
		$star_width = $star_count > 0 ? (int) floor( $remaining / $star_count ) : 0;
		$remainder  = $star_count > 0 ? $remaining - ( $star_width * $star_count ) : 0;

		$widths     = array();
		$star_index = 0;
		foreach ( $cols as $index => $col ) {
			if ( isset( $col['width'] ) && '*' === $col['width'] ) {
				++$star_index;
				$extra            = ( $star_index === $star_count ) ? $remainder : 0;
				$widths[ $index ] = max( 1, $star_width + $extra );
			} else {
				$widths[ $index ] = isset( $col['width'] ) ? (int) $col['width'] : 0;
			}
		}

		return $widths;
	}

	/**
	 * Emit a horizontal rule across the paper width.
	 *
	 * @param array $node Line AST node.
	 */
	private function emit_line( array $node ): void {
		$style = isset( $node['style'] ) ? (string) $node['style'] : 'single';
		$char  = ( 'double' === $style ) ? '=' : '-';
		$this->buffer .= '[fixedWidth: on]' . str_repeat( $char, $this->columns ) . '[fixedWidth: off]' . "\n";
	}

	/**
	 * Emit a 1D barcode.
	 *
	 * @param array $node Barcode AST node.
	 */
	private function emit_barcode( array $node ): void {
		$value = isset( $node['value'] ) ? (string) $node['value'] : '';
		$type  = isset( $node['barcode_type'] ) ? strtolower( (string) $node['barcode_type'] ) : 'code128';
		$this->buffer .= sprintf( '[barcode: type %s; data "%s"; hri]', $this->barcode_type( $type ), $this->data_escape( $value ) );
	}

	/**
	 * Emit a QR code.
	 *
	 * @param array $node QR AST node.
	 */
	private function emit_qrcode( array $node ): void {
		$value = isset( $node['value'] ) ? (string) $node['value'] : '';
		$cell  = isset( $node['size'] ) ? max( 1, min( 8, (int) $node['size'] ) ) : 3;
		$this->buffer .= sprintf( '[barcode: type qr; data "%s"; cell %d; ec medium]', $this->data_escape( $value ), $cell );
	}

	/**
	 * Map a parser barcode type to a Star markup barcode type.
	 *
	 * @param string $type Parser barcode type.
	 *
	 * @return string
	 */
	private function barcode_type( string $type ): string {
		$allowed = array( 'code128', 'code39', 'code93', 'ean13', 'ean8', 'upca', 'upce', 'itf', 'nw7' );

		return \in_array( $type, $allowed, true ) ? $type : 'code128';
	}

	/**
	 * Emit a logo image, only for a public http(s) URL.
	 *
	 * @param array $node Image AST node.
	 */
	private function emit_image( array $node ): void {
		$src = isset( $node['src'] ) ? trim( (string) $node['src'] ) : '';
		if ( '' === $src ) {
			return;
		}

		if ( 0 === strpos( $src, '/' ) && 0 !== strpos( $src, '//' ) ) {
			$src = home_url( $src );
		}

		if ( ! preg_match( '#^https?://#i', $src ) ) {
			return;
		}

		$this->buffer .= sprintf( '[image: url %s; width 100%%]', $src );
	}

	/**
	 * Escape text content for Star markup. Bracket characters are markup-significant.
	 *
	 * @param string $value Text.
	 *
	 * @return string
	 */
	private function escape( string $value ): string {
		return str_replace( array( '[', ']' ), array( '[[', ']]' ), $value );
	}

	/**
	 * Escape a value placed inside a quoted tag parameter (e.g. barcode data).
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	private function data_escape( string $value ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	}

	/**
	 * Concatenate the raw text of a node subtree.
	 *
	 * @param array $nodes AST nodes.
	 *
	 * @return string
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
	 * Render inline text nodes for fixed-width rows while preserving formatting.
	 *
	 * Star markup tags are not printed characters, so padding/truncation is based
	 * on extracted text while the emitted inline content keeps style wrappers.
	 *
	 * @param array $nodes AST nodes.
	 * @param int   $width Maximum display width.
	 *
	 * @return string Star markup inline content.
	 */
	private function render_inline( array $nodes, int $width ): string {
		$remaining = max( 0, $width );

		return $this->render_inline_nodes( $nodes, $remaining );
	}

	/**
	 * Render a list of inline nodes, consuming display width as raw text is emitted.
	 *
	 * @param array $nodes     AST nodes.
	 * @param int   $remaining Remaining display width.
	 *
	 * @return string Star markup inline content.
	 */
	private function render_inline_nodes( array $nodes, int &$remaining ): string {
		$out = '';
		foreach ( $nodes as $node ) {
			if ( $remaining <= 0 || ! \is_array( $node ) ) {
				continue;
			}
			$out .= $this->render_inline_node( $node, $remaining );
		}

		return $out;
	}

	/**
	 * Render a single inline node for use inside a fixed-width row.
	 *
	 * @param array $node      AST node.
	 * @param int   $remaining Remaining display width.
	 *
	 * @return string Star markup inline content.
	 */
	private function render_inline_node( array $node, int &$remaining ): string {
		$type = isset( $node['type'] ) ? (string) $node['type'] : '';

		if ( 'raw-text' === $type ) {
			$text       = $this->truncate( isset( $node['value'] ) ? (string) $node['value'] : '', $remaining );
			$remaining -= $this->display_width( $text );

			return $this->escape( $text );
		}

		$children = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();
		if ( 'text' === $type || 'column' === $type ) {
			return $this->render_inline_nodes( $children, $remaining );
		}

		$wrapped = $this->render_inline_nodes( $children, $remaining );
		if ( '' === $wrapped ) {
			return '';
		}

		if ( 'bold' === $type ) {
			return '[bold: on]' . $wrapped . '[bold: off]';
		}
		if ( 'underline' === $type ) {
			return '[underline: on]' . $wrapped . '[underline: off]';
		}
		if ( 'invert' === $type ) {
			return '[negative: on]' . $wrapped . '[negative: off]';
		}
		if ( 'size' === $type ) {
			$width  = isset( $node['width'] ) ? max( 1, (int) $node['width'] ) : 1;
			$height = isset( $node['height'] ) ? max( 1, (int) $node['height'] ) : 1;

			return sprintf( '[magnify: width %d; height %d]', $width, $height ) . $wrapped . '[magnify]';
		}

		return $wrapped;
	}

	/**
	 * Display width of a string (full-width chars count as 2).
	 *
	 * @param string $value Text.
	 *
	 * @return int
	 */
	private function display_width( string $value ): int {
		$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $value, 1, 'UTF-8' ) : preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			$chars = array();
		}
		$width = 0;
		foreach ( $chars as $char ) {
			$code   = function_exists( 'mb_ord' ) ? (int) mb_ord( $char, 'UTF-8' ) : 0;
			$width += ( $code >= 0x1100 && $code <= 0x115f ) || ( $code >= 0x2e80 && $code <= 0xa4cf )
				|| ( $code >= 0xac00 && $code <= 0xd7a3 ) || ( $code >= 0xf900 && $code <= 0xfaff )
				|| ( $code >= 0xff00 && $code <= 0xff60 ) ? 2 : 1;
		}

		return $width;
	}

	/**
	 * Truncate a string to a maximum display width.
	 *
	 * @param string $value Text.
	 * @param int    $width Max display width.
	 *
	 * @return string
	 */
	private function truncate( string $value, int $width ): string {
		$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $value, 1, 'UTF-8' ) : preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			$chars = array();
		}

		$result = '';
		$used   = 0;
		foreach ( $chars as $char ) {
			$next = $this->display_width( $char );
			if ( $used + $next > $width ) {
				break;
			}
			$result .= $char;
			$used   += $next;
		}

		return $result;
	}
}
