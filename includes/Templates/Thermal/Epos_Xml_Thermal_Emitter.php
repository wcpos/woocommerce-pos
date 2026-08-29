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
 *  - ePOS `<text>` attributes are persistent printer state, so the emitter
 *    tracks that state and emits only changes after a reset preamble.
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

use WCPOS\WooCommercePOS\Templates\Barcode_Symbology;

/**
 * Epos_Xml_Thermal_Emitter class.
 */
class Epos_Xml_Thermal_Emitter {

	/**
	 * Render options.
	 *
	 * @var array
	 */
	private $options = array();

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
	 * The text style state currently held by the printer.
	 *
	 * A null value means the state is unknown and must be re-emitted.
	 *
	 * @var array
	 */
	private $printer = array(
		'align'   => null,
		'em'      => null,
		'ul'      => null,
		'reverse' => null,
		'dw'      => null,
		'dh'      => null,
	);

	/**
	 * Constructor.
	 *
	 * @param array $options Render options.
	 */
	public function __construct( array $options = array() ) {
		$this->options = $options;
	}

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

		// The printer still holds whatever the previous job left, so treat every
		// attribute as unknown and let the transition write the full reset preamble.
		$this->printer = array_fill_keys( array( 'align', 'em', 'ul', 'reverse', 'dw', 'dh' ), null );
		$this->buffer .= '<text' . $this->style_transition() . '/>';

		$children = isset( $ast['children'] ) && \is_array( $ast['children'] ) ? $ast['children'] : array();
		$this->walk_nodes( $this->nodes_with_auto_drawer( $children ) );

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
	 * Emit an Epson ePOS-Print drawer pulse.
	 *
	 * @param string $connector Drawer connector.
	 */
	private function emit_pulse( string $connector ): void {
		$connector = \WCPOS\WooCommercePOS\Services\Print_Job_Service::normalize_drawer_connector( $connector );
		$drawer    = 'pin5' === $connector ? 'drawer_2' : 'drawer_1';
		$this->buffer .= '<pulse drawer="' . $drawer . '" time="pulse_100"/>';
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
				$this->emit_pulse( isset( $node['connector'] ) ? (string) $node['connector'] : 'pin2' );
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

		$content = Thermal_Text_Layout::extract_text( $children );
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
	 * Emit a single <text> line in the current style state.
	 *
	 * @param string      $content The plain text content (will be XML-escaped).
	 * @param string|null $align   Alignment override for this line (rows are
	 *                             pre-padded and always print left-aligned).
	 *
	 * @return void
	 */
	private function emit_text_element( string $content, ?string $align = null ): void {
		$this->buffer .= '<text' . $this->style_transition( $align ) . '>' . $this->escape( $content ) . "\n" . '</text>';
	}

	/**
	 * Build the <text> attributes that move the printer to the current style,
	 * and record the printer as now holding that style.
	 *
	 * The attributes persist on the printer until changed, so only the values
	 * that differ from what it holds are written; a null entry in $printer
	 * (unknown) is always written.
	 *
	 * @param string|null $align Alignment override; null uses the wrapper state.
	 *
	 * @return string The attribute string (with a leading space when non-empty).
	 */
	private function style_transition( ?string $align = null ): string {
		$desired = array(
			'align'   => null === $align ? $this->align : $align,
			'em'      => $this->em,
			'ul'      => $this->ul,
			'reverse' => $this->reverse,
			'dw'      => $this->dw,
			'dh'      => $this->dh,
		);
		$attrs = '';
		foreach ( $desired as $name => $value ) {
			if ( $this->printer[ $name ] !== $value ) {
				$attrs .= ' ' . $name . '="' . ( \is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value ) . '"';
			}
		}
		$this->printer = $desired;

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
		$widths = Thermal_Text_Layout::resolve_row_widths( $cols, $this->columns );

		$line = '';
		foreach ( $cols as $index => $col ) {
			$width = isset( $widths[ $index ] ) ? $widths[ $index ] : 1;
			$text  = Thermal_Text_Layout::extract_text( isset( $col['children'] ) ? $col['children'] : array() );
			$text  = Thermal_Text_Layout::truncate_display( $text, $width );
			$pad   = max( 0, $width - Thermal_Text_Layout::display_width( $text ) );
			$align = isset( $col['align'] ) ? $col['align'] : 'left';
			if ( 'right' === $align ) {
				$line .= str_repeat( ' ', $pad ) . $text;
			} else {
				$line .= $text . str_repeat( ' ', $pad );
			}
		}

		$this->emit_text_element( $line, 'left' );
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

		$this->emit_text_element( $text );
	}

	/**
	 * Emit a native barcode element.
	 *
	 * The `type` attribute is an ePOS-Print enum, not the template's spelling —
	 * the UPC pair is underscored there (`upc_a` / `upc_e`) and the unseparated
	 * form is rejected — so the name comes from Barcode_Symbology.
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

		$type   = isset( $node['barcode_type'] ) ? (string) $node['barcode_type'] : 'code128';
		$height = isset( $node['height'] ) ? (int) $node['height'] : 40;
		$height = max( 1, min( 255, $height ) );

		// Same rescue as the ESC/POS lane: a value the symbology cannot encode
		// would be dropped by the printer "with no error returned" (ePOS-Print
		// manual), so print it as a centered line instead of nothing.
		if ( ! Barcode_Symbology::is_valid_value( $type, $value, Barcode_Symbology::LANE_ESCPOS ) ) {
			$this->emit_text_element( (string) preg_replace( '/[\x00-\x1f\x7f]/', ' ', $value ), 'center' );

			return;
		}

		$payload = Barcode_Symbology::epos_xml_payload( $type, $value );

		$this->buffer .= '<barcode type="' . $this->escape( Barcode_Symbology::epos_xml_name( $type ) ) . '" hri="none" height="' . $height . '" align="' . $this->escape( $this->align ) . '">' . $this->escape( $payload ) . '</barcode>';
		$this->printer['align'] = null;
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
		if ( '' === trim( $value ) ) {
			return;
		}
		$size = isset( $node['size'] ) ? (int) $node['size'] : 4;

		// <symbol> data shares the barcode escape layer (`\xnn`, `\\`).
		$payload = Barcode_Symbology::epos_xml_escape_data( $value );

		$this->buffer .= '<symbol type="qrcode_model_2" level="default" width="' . $size . '" align="' . $this->escape( $this->align ) . '">' . $this->escape( $payload ) . '</symbol>';
		$this->printer['align'] = null;
	}

	/**
	 * Emit a paper feed of N lines.
	 *
	 * @param array $node The feed AST node.
	 *
	 * @return void
	 */
	private function emit_feed( array $node ): void {
		$lines         = Thermal_Bounds::clamp_int(
			isset( $node['lines'] ) ? $node['lines'] : null,
			Thermal_Bounds::FEED_LINES_MIN,
			Thermal_Bounds::FEED_LINES_MIN,
			Thermal_Bounds::FEED_LINES_MAX
		);
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
}
