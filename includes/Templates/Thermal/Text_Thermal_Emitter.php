<?php
/**
 * Plain Text Thermal Emitter Class.
 *
 * Emits `text/plain` from a thermal AST: the receipt's character-cell layout with
 * every command byte removed. Star CloudPRNT lists `text/plain` as decodable on
 * every model, including the Line Mode-only ones (TSP650II/TSP700II/TSP800II)
 * that cannot decode StarPRNT at all, so this is the format that keeps a printer
 * working when native emission is not on its menu.
 *
 * Because the format carries no commands, the peripherals move to the transport:
 * cut and cash-drawer are requested with the `X-Star-Cut` / `X-Star-CashDrawer`
 * response headers on the CloudPRNT job fetch. This emitter therefore swallows
 * `<cut>` and `<drawer>` nodes and reports them through cut_type() and drawer()
 * so the caller can set those headers.
 *
 * Two things the command formats can express are simply not expressible here,
 * and both are limits of the protocol rather than of this code:
 *
 * - **Drawer connector.** `X-Star-CashDrawer` takes only `none`/`start`/`end`,
 *   so a job configured for the second connector (`pin5`) fires the printer's
 *   default drawer output instead. StarPRNT emits a connector-specific pulse;
 *   plain text has no way to ask for one.
 * - **Character encoding.** `Starprnt_Thermal_Emitter` opens every job with the
 *   `ESC GS ) U` UTF-8 select sequence; a command-free format cannot send it, so
 *   non-ASCII text is decoded with whatever code page the printer is set to.
 *   normalize_text() folds the typographic characters receipts actually produce
 *   (smart quotes, dashes, no-break spaces) down to ASCII, which covers the
 *   common case; full glyph coverage is what the `image/png` fallback is for.
 *
 * Styling (`<bold>`, `<underline>`, `<invert>`, `<size>`) has no plain-text
 * expression and is walked through transparently. `<barcode>` and `<qrcode>`
 * cannot be rendered, so their value is printed as a centred text line — a
 * receipt whose QR carries an order URL stays useful, where dropping the node
 * would lose the information entirely. `<image>` is dropped, matching
 * `Escpos_Thermal_Emitter`.
 *
 * @package WCPOS\WooCommercePOS\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

/**
 * Text_Thermal_Emitter class.
 */
class Text_Thermal_Emitter {

	/**
	 * Render options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Accumulated output text.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * The text buffered for the line currently being built.
	 *
	 * @var string
	 */
	private $line = '';

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
	 * The cut requested by the AST, or null when it asked for none.
	 *
	 * @var string|null
	 */
	private $cut_type = null;

	/**
	 * The drawer kick requested by the AST/options, or null when none.
	 *
	 * @var string|null
	 */
	private $drawer = null;

	/**
	 * Constructor.
	 *
	 * @param array $options Render options.
	 */
	public function __construct( array $options = array() ) {
		$this->options = $options;
	}

	/**
	 * Emit plain text from a thermal AST.
	 *
	 * @param array $ast The thermal AST root (a receipt node).
	 *
	 * @return string The receipt as plain text.
	 */
	public function emit( array $ast ): string {
		$this->buffer   = '';
		$this->line     = '';
		$this->align    = 'left';
		$this->cut_type = null;
		$this->drawer   = null;

		$this->columns = isset( $ast['paper_width'] ) ? max( 1, (int) $ast['paper_width'] ) : 48;

		$children = isset( $ast['children'] ) && \is_array( $ast['children'] ) ? $ast['children'] : array();
		$this->walk_nodes( $children );
		$this->flush_line();

		// The AST carries no drawer node, but the job asked for one: the transport
		// header is the only place a text/plain job can request it.
		if ( null === $this->drawer && ! empty( $this->options['auto_open_drawer'] ) ) {
			$this->drawer = 'end';
		}

		return $this->buffer;
	}

	/**
	 * The cut the rendered AST asked for, for the `X-Star-Cut` header.
	 *
	 * @return string|null 'full', 'partial', or null when the receipt cuts nothing.
	 */
	public function cut_type(): ?string {
		return $this->cut_type;
	}

	/**
	 * The drawer kick the rendered job asked for, for `X-Star-CashDrawer`.
	 *
	 * @return string|null 'start', 'end', or null when no drawer should fire.
	 */
	public function drawer(): ?string {
		return $this->drawer;
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
		$type     = isset( $node['type'] ) ? $node['type'] : '';
		$children = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();

		switch ( $type ) {
			case 'raw-text':
				$this->line .= Thermal_Text_Layout::normalize_text( isset( $node['value'] ) ? (string) $node['value'] : '' );
				break;
			case 'text':
				$this->emit_text_line( $children );
				break;
			case 'bold':
			case 'underline':
			case 'invert':
			case 'size':
				// No plain-text expression; the children still print.
				$this->walk_nodes( $children );
				break;
			case 'align':
				$this->emit_align( $node );
				break;
			case 'row':
				$this->emit_row( $node );
				break;
			case 'line':
				$this->emit_rule( $node );
				break;
			case 'barcode':
			case 'qrcode':
				$this->emit_symbol_fallback( $node );
				break;
			case 'image':
				// Dropped: a text/plain job cannot carry raster data.
				break;
			case 'cut':
				$this->cut_type = isset( $node['cut_type'] ) ? (string) $node['cut_type'] : 'partial';
				break;
			case 'feed':
				$this->emit_feed( $node );
				break;
			case 'drawer':
				$this->drawer = 'end';
				break;
			case 'receipt':
				$this->walk_nodes( $children );
				break;
		}
	}

	/**
	 * Emit a single printed text line, aligned within the paper width.
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
				$this->line .= str_repeat( ' ', $pad );
			}
		}
		$this->walk_nodes( $children );
		$this->newline();
	}

	/**
	 * Walk an alignment-wrapped block with the alignment applied.
	 *
	 * @param array $node The align AST node.
	 *
	 * @return void
	 */
	private function emit_align( array $node ): void {
		$previous    = $this->align;
		$this->align = isset( $node['mode'] ) ? (string) $node['mode'] : 'left';
		$this->walk_nodes( isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array() );
		$this->align = $previous;
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

		$row = '';
		foreach ( $cols as $index => $col ) {
			$width = isset( $widths[ $index ] ) ? $widths[ $index ] : 1;
			$text  = Thermal_Text_Layout::normalize_text( Thermal_Text_Layout::extract_text( isset( $col['children'] ) ? $col['children'] : array() ) );
			$text  = Thermal_Text_Layout::truncate_display( $text, $width );
			$pad   = max( 0, $width - Thermal_Text_Layout::display_width( $text ) );
			$align = isset( $col['align'] ) ? (string) $col['align'] : 'left';
			if ( 'right' === $align ) {
				$row .= str_repeat( ' ', $pad ) . $text;
			} else {
				$row .= $text . str_repeat( ' ', $pad );
			}
		}

		$this->line .= $row;
		$this->newline();
	}

	/**
	 * Emit a horizontal rule line.
	 *
	 * @param array $node The line AST node.
	 *
	 * @return void
	 */
	private function emit_rule( array $node ): void {
		$style = isset( $node['style'] ) ? (string) $node['style'] : 'single';

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

		$this->line .= $text;
		$this->newline();
	}

	/**
	 * Print a barcode/QR value as text, centred within the paper width.
	 *
	 * @param array $node The barcode or qrcode AST node.
	 *
	 * @return void
	 */
	private function emit_symbol_fallback( array $node ): void {
		$value = Thermal_Text_Layout::normalize_text( isset( $node['value'] ) ? (string) $node['value'] : '' );
		if ( '' === $value ) {
			return;
		}

		$pad = Thermal_Text_Layout::alignment_padding( 'center', Thermal_Text_Layout::display_width( $value ), $this->columns );
		if ( $pad > 0 ) {
			$this->line .= str_repeat( ' ', $pad );
		}
		$this->line .= $value;
		$this->newline();
	}

	/**
	 * Emit a paper feed of N blank lines.
	 *
	 * @param array $node The feed AST node.
	 *
	 * @return void
	 */
	private function emit_feed( array $node ): void {
		$lines = isset( $node['lines'] ) ? max( 1, (int) $node['lines'] ) : 1;
		for ( $index = 0; $index < $lines; $index++ ) {
			$this->newline();
		}
	}

	/**
	 * Close the current line and start a new one.
	 *
	 * @return void
	 */
	private function newline(): void {
		$this->buffer .= rtrim( $this->line, " \t" ) . "\n";
		$this->line    = '';
	}

	/**
	 * Flush any text buffered outside a line-terminating node.
	 *
	 * @return void
	 */
	private function flush_line(): void {
		if ( '' !== $this->line ) {
			$this->newline();
		}
	}
}
