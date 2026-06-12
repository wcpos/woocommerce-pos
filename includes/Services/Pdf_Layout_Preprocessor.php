<?php
/**
 * Rewrites receipt HTML into Dompdf-friendly markup before PDF rendering.
 *
 * Dompdf has no CSS Flexbox or Grid layout engine — it silently maps
 * `display:flex` / `display:grid` to `block`, which collapses receipt rows
 * built as columns. The previous approach shimmed this with CSS attribute
 * selectors keyed on inline-style substrings, but that only covered the exact
 * pixel values used by the bundled templates and leaned on Dompdf floats,
 * whose placement is buggy (consecutive floated values stack leftward).
 *
 * This preprocessor instead parses the HTML and rewrites every inline-styled
 * flex/grid container into a real `<table>` — Dompdf's most reliable layout
 * primitive — computing cell widths from the actual `flex` /
 * `grid-template-columns` / `gap` values, so customized templates work as well
 * as the bundled ones. It also lifts the root element's padding into `@page`
 * margins so the PDF page box matches the on-screen preview (Dompdf's default
 * 1.2cm page margin would otherwise be added on top of the template padding).
 *
 * Supported CSS subset (template authors take note):
 *  - Inline `style=""` declarations only; class/stylesheet-based flex is not
 *    rewritten (the bundled legacy template's classes are shimmed separately
 *    in Pdf_Renderer::LEGACY_FLEX_SHIM).
 *  - Lengths in `px`/`pt` only — other units in `gap`/`flex-basis`/`padding`
 *    fall back to shrink-to-content / unlifted padding.
 *  - `grid-template-columns`: `fr`, `px`, `auto`, and `repeat(N, …)`;
 *    `minmax()`/`%` degrade to an even flexible column. Children are chunked
 *    into rows of N columns (row auto-flow only).
 *  - `justify-content: space-between|space-around|space-evenly` rows become
 *    label/value tables with the last cell right-aligned; `center|flex-end`
 *    runs become text-aligned inline-blocks when no child has a fixed basis.
 *  - The padding lift requires a single root element.
 *
 * Only the PDF render path uses this class; gallery templates and live
 * previews are never modified.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DOMDocument;
use DOMElement;

/**
 * Pdf_Layout_Preprocessor class.
 */
class Pdf_Layout_Preprocessor {

	/**
	 * Points per CSS pixel (72dpi PDF space vs 96dpi CSS space).
	 */
	private const PT_PER_PX = 0.75;

	/**
	 * Root padding lifted off the receipt wrapper, as @page margins in pt
	 * (top, right, bottom, left). Zero margins when no padding was lifted —
	 * the preview shows none around an unpadded root either.
	 *
	 * @var float[]
	 */
	private $page_margins_pt = array( 0.0, 0.0, 0.0, 0.0 );

	/**
	 * Whether the last process() input was a full HTML document.
	 *
	 * @var bool
	 */
	private $full_document = false;

	/**
	 * Rewrite flex/grid receipt markup into Dompdf-friendly tables.
	 *
	 * Fragments (logicless/thermal output) additionally get their root padding
	 * lifted into @page margins. Full documents (the legacy-php template) keep
	 * their <head> stylesheet and page box untouched: only the in-body flex
	 * containers and known legacy classes are rewritten.
	 *
	 * @param string $html Receipt HTML (fragment or full document).
	 *
	 * @return string The rewritten HTML.
	 */
	public function process( string $html ): string {
		$this->page_margins_pt = array( 0.0, 0.0, 0.0, 0.0 );
		$this->full_document   = false;

		if ( '' === trim( $html ) ) {
			return $html;
		}

		$full_document       = false !== stripos( $html, '<html' );
		$this->full_document = $full_document;

		$dom      = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		// The processing instruction pins the parser to UTF-8 for fragments;
		// loadHTML otherwise assumes ISO-8859-1 and mangles multibyte text.
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $html;
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof DOMElement ) {
			return $html;
		}

		if ( ! $full_document ) {
			$this->lift_root_padding( $body );
		}
		$this->transform_children( $body );

		if ( $full_document ) {
			// Serialize the whole document so <head> styles survive, dropping
			// the synthetic XML prolog the UTF-8 pinning added.
			foreach ( $dom->childNodes as $node ) {
				if ( XML_PI_NODE === $node->nodeType ) {
					$dom->removeChild( $node );
					break;
				}
			}

			return (string) $dom->saveHTML();
		}

		$out = '';
		foreach ( $body->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Page margins (pt) lifted from the receipt root padding by process().
	 *
	 * Only meaningful after process() has run; zeros otherwise.
	 *
	 * @return float[] [top, right, bottom, left] in pt.
	 */
	public function get_page_margins_pt(): array {
		return $this->page_margins_pt;
	}

	/**
	 * Whether the last process() input was a full HTML document.
	 *
	 * Callers branch on this instead of sniffing the markup themselves, so the
	 * renderer and the preprocessor can never disagree about which treatment
	 * (fragment @page margins vs. document-owned page box) an input received.
	 *
	 * @return bool
	 */
	public function is_full_document(): bool {
		return $this->full_document;
	}

	/**
	 * Move the root element's padding into @page margins.
	 *
	 * The browser preview shows the template's own root padding as the only
	 * whitespace around the receipt. Replacing Dompdf's default 1.2cm page
	 * margin with that padding keeps page one identical to the preview and
	 * gives later pages the same consistent margins.
	 *
	 * @param DOMElement $body The document body.
	 */
	private function lift_root_padding( DOMElement $body ): void {
		$root = null;
		foreach ( $body->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( null !== $root ) {
					return; // Multiple roots — ambiguous, leave padding alone.
				}
				$root = $child;
			} elseif ( XML_TEXT_NODE === $child->nodeType && '' !== trim( (string) $child->nodeValue ) ) {
				return; // Loose text next to the root element.
			}
		}

		if ( null === $root ) {
			return;
		}

		$styles  = self::parse_styles( $root->getAttribute( 'style' ) );
		$padding = self::resolve_padding_px( $styles );
		if ( null === $padding ) {
			return;
		}

		unset( $styles['padding'], $styles['padding-top'], $styles['padding-right'], $styles['padding-bottom'], $styles['padding-left'] );
		self::set_styles( $root, $styles );

		$this->page_margins_pt = array_map(
			static function ( float $px ): float {
				return round( $px * self::PT_PER_PX, 2 );
			},
			$padding
		);
	}

	/**
	 * Recursively transform flex/grid containers, deepest first.
	 *
	 * @param DOMElement $element The element whose children to transform.
	 */
	private function transform_children( DOMElement $element ): void {
		// Snapshot: transformation replaces nodes in place.
		$children = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$children[] = $child;
			}
		}

		foreach ( $children as $child ) {
			$this->transform_children( $child );
			$this->transform_element( $child );
		}
	}

	/**
	 * Transform a single flex/grid container into Dompdf-friendly markup.
	 *
	 * @param DOMElement $element The candidate container.
	 */
	private function transform_element( DOMElement $element ): void {
		$styles  = self::parse_styles( $element->getAttribute( 'style' ) );
		$display = isset( $styles['display'] ) ? strtolower( $styles['display'] ) : '';

		if ( 'inline-flex' === $display || 'inline-grid' === $display ) {
			$this->convert_inline_flex( $element, $styles );
			return;
		}

		if ( 'flex' !== $display && 'grid' !== $display ) {
			// The bundled legacy-php template declares its flex in a <head>
			// stylesheet rather than inline styles; its class names are stable,
			// so they get the same table treatment, keyed by class.
			$this->convert_legacy_classes( $element );
			return;
		}

		$children = self::element_children( $element );
		if ( 0 === \count( $children ) ) {
			// Spacer divs (e.g. divider lines) just need flex dropped.
			unset( $styles['display'] );
			self::set_styles( $element, $styles );
			return;
		}

		$justify = isset( $styles['justify-content'] ) ? strtolower( $styles['justify-content'] ) : '';

		// Column flex stacks children like normal block flow; a row table
		// would rotate the content sideways.
		$direction = isset( $styles['flex-direction'] ) ? strtolower( trim( $styles['flex-direction'] ) ) : '';
		if ( 'flex' === $display && 0 === strpos( $direction, 'column' ) ) {
			$this->convert_column_stack( $element, $styles, $children );
			return;
		}

		// Simple aligned runs (a right-pushed barcode, a centered badge row)
		// read better as text-aligned inline-blocks than as a table.
		if ( \in_array( $justify, array( 'flex-end', 'end', 'right', 'center' ), true ) && ! self::has_sized_child( $children ) ) {
			$this->convert_aligned_run( $element, $styles, $children, $justify );
			return;
		}

		if ( 'grid' === $display ) {
			$this->convert_grid( $element, $styles, $children );
			return;
		}

		$this->convert_flex_row( $element, $styles, $children );
	}

	/**
	 * Convert a flex row into a single-row table.
	 *
	 * @param DOMElement   $element  The flex container.
	 * @param array        $styles   Parsed container styles.
	 * @param DOMElement[] $children The container's element children.
	 */
	private function convert_flex_row( DOMElement $element, array $styles, array $children ): void {
		$justify = isset( $styles['justify-content'] ) ? strtolower( $styles['justify-content'] ) : '';
		$gap     = self::parse_gap_px( $styles );

		$specs      = array();
		$grow_count = 0;
		$has_fixed  = false;
		foreach ( $children as $child ) {
			$spec = self::flex_child_spec( $child );
			if ( 'grow' === $spec['kind'] ) {
				++$grow_count;
			}
			if ( 'fixed' === $spec['kind'] ) {
				$has_fixed = true;
			}
			$specs[] = $spec;
		}

		// space-between label/value rows: let the table spread the cells and
		// right-align the last one, mirroring how the browser pushes it flush.
		$space_between = \in_array( $justify, array( 'space-between', 'space-around', 'space-evenly' ), true );

		// All children grow equally (e.g. three flex:1 sign-off columns):
		// fixed layout splits the width evenly like flexbox would.
		$equal_split = ! $space_between && ! $has_fixed && \count( $children ) === $grow_count && $grow_count > 1;

		$table = $this->build_table( $element, $styles );
		if ( $equal_split ) {
			self::append_style( $table, 'table-layout', 'fixed' );
		}

		$row = $element->ownerDocument->createElement( 'tr' );
		$table->appendChild( $row );

		$valign = self::vertical_align( $styles );
		$last   = \count( $children ) - 1;

		foreach ( $children as $i => $child ) {
			$cell_styles = array( 'vertical-align' => $valign );

			if ( $i > 0 && $gap[1] > 0 ) {
				$cell_styles['padding-left'] = self::css_number( $gap[1] ) . 'px';
			}

			$spec = $specs[ $i ];
			if ( 'fixed' === $spec['kind'] ) {
				$cell_styles['width'] = $spec['width'];
			} elseif ( 'shrink' === $spec['kind'] && ! $space_between ) {
				// width:1% + nowrap shrinks the cell to its content like
				// flex-basis:auto would; remaining width flows to grow cells.
				$cell_styles['width']       = '1%';
				$cell_styles['white-space'] = 'nowrap';
			}

			if ( $space_between && $i === $last && $i > 0 ) {
				$cell_styles['text-align'] = 'right';
			} elseif ( $space_between && $i > 0 && $i < $last ) {
				$cell_styles['text-align'] = 'center';
			}

			$this->append_cell( $row, $child, $cell_styles );
		}

		$element->parentNode->replaceChild( $table, $element );
	}

	/**
	 * Convert a grid into a table, chunking children into rows of N columns.
	 *
	 * @param DOMElement   $element  The grid container.
	 * @param array        $styles   Parsed container styles.
	 * @param DOMElement[] $children The container's element children.
	 */
	private function convert_grid( DOMElement $element, array $styles, array $children ): void {
		$columns = self::parse_grid_columns( isset( $styles['grid-template-columns'] ) ? $styles['grid-template-columns'] : '' );
		if ( 0 === \count( $columns ) ) {
			$columns = array(
				array(
					'kind' => 'fr',
					'value' => 1.0,
				),
			);
		}

		$gap      = self::parse_gap_px( $styles );
		$col_n    = \count( $columns );
		$fr_total = 0.0;
		$only_fr  = true;
		foreach ( $columns as $column ) {
			if ( 'fr' === $column['kind'] ) {
				$fr_total += $column['value'];
			} else {
				$only_fr = false;
			}
		}

		$table = $this->build_table( $element, $styles );
		if ( $only_fr && $col_n > 1 ) {
			self::append_style( $table, 'table-layout', 'fixed' );
		}

		$valign = self::vertical_align( $styles );
		$rows   = array_chunk( $children, $col_n );

		foreach ( $rows as $row_index => $row_children ) {
			$row = $element->ownerDocument->createElement( 'tr' );
			$table->appendChild( $row );

			foreach ( $row_children as $i => $child ) {
				$cell_styles = array( 'vertical-align' => $valign );

				if ( $i > 0 && $gap[1] > 0 ) {
					$cell_styles['padding-left'] = self::css_number( $gap[1] ) . 'px';
				}
				if ( $row_index > 0 && $gap[0] > 0 ) {
					$cell_styles['padding-top'] = self::css_number( $gap[0] ) . 'px';
				}

				$column = $columns[ $i ];
				if ( 'px' === $column['kind'] ) {
					$cell_styles['width'] = self::css_number( $column['value'] ) . 'px';
				} elseif ( 'auto' === $column['kind'] ) {
					$cell_styles['width']       = '1%';
					$cell_styles['white-space'] = 'nowrap';
				} elseif ( $only_fr && $fr_total > 0 ) {
					$cell_styles['width'] = self::css_number( $column['value'] / $fr_total * 100 ) . '%';
				}

				$this->append_cell( $row, $child, $cell_styles );
			}
		}

		$element->parentNode->replaceChild( $table, $element );
	}

	/**
	 * Convert a column flex container into a plain block stack.
	 *
	 * @param DOMElement   $element  The flex container.
	 * @param array        $styles   Parsed container styles.
	 * @param DOMElement[] $children The container's element children.
	 */
	private function convert_column_stack( DOMElement $element, array $styles, array $children ): void {
		$gap = self::parse_gap_px( $styles );

		self::strip_layout_styles( $styles );
		self::set_styles( $element, $styles );

		foreach ( $children as $i => $child ) {
			$child_styles = self::parse_styles( $child->getAttribute( 'style' ) );
			self::strip_flex_child_styles( $child_styles );
			if ( $i > 0 && $gap[0] > 0 ) {
				$child_styles['margin-top'] = self::css_number( $gap[0] ) . 'px';
			}
			self::set_styles( $child, $child_styles );
		}
	}

	/**
	 * Convert a centered/right-aligned flex run into text-aligned inline-blocks.
	 *
	 * @param DOMElement   $element  The flex container.
	 * @param array        $styles   Parsed container styles.
	 * @param DOMElement[] $children The container's element children.
	 * @param string       $justify  The normalized justify-content value.
	 */
	private function convert_aligned_run( DOMElement $element, array $styles, array $children, string $justify ): void {
		$gap = self::parse_gap_px( $styles );

		self::strip_layout_styles( $styles );
		$styles['text-align'] = 'center' === $justify ? 'center' : 'right';
		self::set_styles( $element, $styles );

		foreach ( $children as $i => $child ) {
			$child_styles = self::parse_styles( $child->getAttribute( 'style' ) );
			self::strip_flex_child_styles( $child_styles );
			$child_styles['display'] = 'inline-block';
			if ( $i > 0 && $gap[1] > 0 ) {
				$child_styles['margin-left'] = self::css_number( $gap[1] ) . 'px';
			}
			self::set_styles( $child, $child_styles );
		}
	}

	/**
	 * Convert an inline-flex container (status pills) into inline-blocks.
	 *
	 * The pill's dot is a fixed-size span; inline-block lets its width/height
	 * apply, which plain inline display would collapse. Whitespace between the
	 * chip's parts becomes non-breaking: a flex row never wraps its items, and
	 * Dompdf's word-based minimum-width sizing would otherwise wrap the chip
	 * inside shrink-to-content table cells.
	 *
	 * @param DOMElement $element The inline-flex container.
	 * @param array      $styles  Parsed container styles.
	 */
	private function convert_inline_flex( DOMElement $element, array $styles ): void {
		$gap = self::parse_gap_px( $styles );

		self::strip_layout_styles( $styles );
		$styles['display'] = 'inline-block';
		self::set_styles( $element, $styles );

		foreach ( $element->childNodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType && null !== $node->nodeValue ) {
				$node->nodeValue = (string) preg_replace( '/\s+/u', "\u{00A0}", $node->nodeValue );
			}
		}
		// Multibyte-safe trim: ltrim/rtrim would strip the NBSP's individual
		// bytes and corrupt adjacent UTF-8 characters (e.g. £ shares 0xC2).
		$first = $element->firstChild;
		if ( null !== $first && XML_TEXT_NODE === $first->nodeType ) {
			$first->nodeValue = (string) preg_replace( '/^\x{00A0}+/u', '', (string) $first->nodeValue );
		}
		$last = $element->lastChild;
		if ( null !== $last && XML_TEXT_NODE === $last->nodeType ) {
			$last->nodeValue = (string) preg_replace( '/\x{00A0}+$/u', '', (string) $last->nodeValue );
		}

		$children = self::element_children( $element );
		foreach ( $children as $child ) {
			$child_styles = self::parse_styles( $child->getAttribute( 'style' ) );
			self::strip_flex_child_styles( $child_styles );
			// Natural baseline alignment, not vertical-align:middle — Dompdf
			// raises "middle" inline-blocks to cap height, floating the chip's
			// dot above the label. On the baseline a fixed-size dot's optical
			// center lands at the uppercase midline, matching the browser's
			// flex centering (Dompdf ignores length values for vertical-align,
			// so a fine-tuned offset is not an option).
			$child_styles['display'] = 'inline-block';
			// Mirror the flex gap after every child that has following content
			// — a chip's label may be a bare text node, which margin-left on
			// the next element child could never reach.
			if ( $gap[1] > 0 && self::has_following_content( $child ) ) {
				$child_styles['margin-right'] = self::css_number( $gap[1] ) . 'px';
			}
			self::set_styles( $child, $child_styles );
		}
	}

	/**
	 * Rewrite the bundled legacy template's class-based flex containers.
	 *
	 * The legacy receipt.php keeps its layout in a <head> stylesheet, which the
	 * inline-style transforms cannot see. Its class names are stable, so the
	 * known containers are wrapped IN PLACE: the element keeps its class (the
	 * stylesheet's colors/spacing still apply; its display:flex degrades to
	 * block under Dompdf) and the children move into a real table inside it.
	 * Without width hints Dompdf's auto table layout distributes leftover width
	 * across all cells, inflating the logo cell and drifting floated values.
	 *
	 * @param DOMElement $element The candidate element.
	 */
	private function convert_legacy_classes( DOMElement $element ): void {
		if ( self::has_class( $element, 'receipt-header' ) ) {
			$this->wrap_legacy_header( $element );
			return;
		}

		if ( self::has_class( $element, 'status-pill' ) ) {
			$this->convert_legacy_status_pill( $element );
			return;
		}

		$is_label_value_row = self::has_class( $element, 'totals-row' )
			|| self::has_class( $element, 'payment-row' )
			|| self::has_class( $element, 'payment-sub' )
			|| ( self::has_class( $element, 'row' ) && self::has_ancestor_class( $element, 'card' ) );

		if ( $is_label_value_row ) {
			$this->wrap_legacy_label_value_row( $element );
		}
	}

	/**
	 * Convert the legacy status pill into an unbreakable inline-block chip.
	 *
	 * The stylesheet's inline-flex/gap are invisible here, so the chip gets
	 * inline display:inline-block (winning over the stylesheet), the dot keeps
	 * natural baseline alignment (Dompdf raises vertical-align:middle to cap
	 * height), and the flex gap is mirrored as a margin on element children
	 * that have following content (the label is a bare text node).
	 *
	 * @param DOMElement $element The .status-pill element.
	 */
	private function convert_legacy_status_pill( DOMElement $element ): void {
		self::append_style( $element, 'display', 'inline-block' );

		foreach ( self::element_children( $element ) as $child ) {
			$child_styles            = self::parse_styles( $child->getAttribute( 'style' ) );
			$child_styles['display'] = 'inline-block';
			if ( self::has_following_content( $child ) ) {
				// The stylesheet's flex gap (6px), mirrored from receipt.php.
				$child_styles['margin-right'] = '6px';
			}
			self::set_styles( $child, $child_styles );
		}

		// Non-breaking whitespace: a flex chip never wraps, and Dompdf's
		// word-based minimum width would otherwise wrap it inside
		// shrink-to-content table cells.
		foreach ( $element->childNodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType && null !== $node->nodeValue ) {
				$node->nodeValue = (string) preg_replace( '/\s+/u', "\u{00A0}", trim( (string) $node->nodeValue ) );
			}
		}
	}

	/**
	 * Wrap the legacy header's logo/store/meta children in a hinted table.
	 *
	 * @param DOMElement $element The .receipt-header element.
	 */
	private function wrap_legacy_header( DOMElement $element ): void {
		$children = self::element_children( $element );
		if ( 0 === \count( $children ) ) {
			return;
		}

		$cells = array();
		foreach ( $children as $i => $child ) {
			$cell_styles = array( 'vertical-align' => 'top' );
			if ( $i > 0 ) {
				// The stylesheet's flex gap (22px) — gaps are unreachable from
				// class-based CSS here, so the bundled value is mirrored.
				$cell_styles['padding-left'] = '22px';
			}

			// .logo / .meta shrink to content like flex 0 0 auto; the .store
			// column stays width-less and absorbs the leftover width.
			if ( ! self::has_class( $child, 'store' ) ) {
				$cell_styles['width']       = '1%';
				$cell_styles['white-space'] = 'nowrap';
			}

			$cells[] = $cell_styles;
		}

		$this->wrap_children_in_row_table( $element, $children, $cells );
	}

	/**
	 * Wrap a legacy label/value row in a table with a right-aligned last cell.
	 *
	 * Replaces the old float-right shim for .totals-row/.payment-row/
	 * .payment-sub/.card .row: Dompdf stacks consecutive floats leftward,
	 * drifting the lower values (tendered/change) off the edge.
	 *
	 * @param DOMElement $element The row element.
	 */
	private function wrap_legacy_label_value_row( DOMElement $element ): void {
		$children = self::element_children( $element );
		if ( \count( $children ) < 2 ) {
			return;
		}

		$last  = \count( $children ) - 1;
		$cells = array();
		foreach ( $children as $i => $child ) {
			$cell_styles = array( 'vertical-align' => 'top' );
			if ( $i === $last ) {
				$cell_styles['text-align'] = 'right';
			}
			$cells[] = $cell_styles;
		}

		$this->wrap_children_in_row_table( $element, $children, $cells );
	}

	/**
	 * Move an element's children into a single-row table inside the element.
	 *
	 * The container element itself is preserved so its class-based styling
	 * (padding, borders, typography) keeps applying.
	 *
	 * @param DOMElement   $element  The container element.
	 * @param DOMElement[] $children The container's element children.
	 * @param array[]      $cells    Style maps for each cell, by child index.
	 */
	private function wrap_children_in_row_table( DOMElement $element, array $children, array $cells ): void {
		$document = $element->ownerDocument;

		$table = $document->createElement( 'table' );
		$table->setAttribute( 'style', 'width: 100%; border-spacing: 0' );
		$row = $document->createElement( 'tr' );
		$table->appendChild( $row );

		foreach ( $children as $i => $child ) {
			$cell       = $document->createElement( 'td' );
			$style_text = self::build_styles( isset( $cells[ $i ] ) ? $cells[ $i ] : array() );
			if ( '' !== $style_text ) {
				$cell->setAttribute( 'style', $style_text );
			}
			$row->appendChild( $cell );
			$cell->appendChild( $child );
		}

		// Drop leftover inter-child whitespace so it cannot form an extra line
		// box above the table.
		foreach ( iterator_to_array( $element->childNodes ) as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType && '' === trim( (string) $node->nodeValue ) ) {
				$element->removeChild( $node );
			}
		}

		$element->appendChild( $table );
	}

	/**
	 * Whether a node is followed by rendered content (element or real text).
	 *
	 * Whitespace-only and NBSP-only text nodes do not count: chip edge
	 * whitespace is trimmed to empty nodes that must not attract gap margins.
	 *
	 * @param \DOMNode $node The node.
	 *
	 * @return bool
	 */
	private static function has_following_content( \DOMNode $node ): bool {
		for ( $next = $node->nextSibling; null !== $next; $next = $next->nextSibling ) {
			if ( $next instanceof DOMElement ) {
				return true;
			}
			if ( XML_TEXT_NODE === $next->nodeType && 1 === preg_match( '/[^\s\x{00A0}]/u', (string) $next->nodeValue ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an element carries a class name.
	 *
	 * @param DOMElement $element The element.
	 * @param string     $name    The class name.
	 *
	 * @return bool
	 */
	private static function has_class( DOMElement $element, string $name ): bool {
		return \in_array( $name, preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) ), true );
	}

	/**
	 * Whether any ancestor element carries a class name.
	 *
	 * @param DOMElement $element The element.
	 * @param string     $name    The class name.
	 *
	 * @return bool
	 */
	private static function has_ancestor_class( DOMElement $element, string $name ): bool {
		for ( $parent = $element->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
			if ( self::has_class( $parent, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create the replacement table carrying the container's non-layout styles.
	 *
	 * @param DOMElement $element The flex/grid container being replaced.
	 * @param array      $styles  Parsed container styles.
	 *
	 * @return DOMElement The new (detached) table element.
	 */
	private function build_table( DOMElement $element, array $styles ): DOMElement {
		$table = $element->ownerDocument->createElement( 'table' );

		self::strip_layout_styles( $styles );
		$styles['width']          = '100%';
		$styles['border-spacing'] = '0';
		// border-collapse:separate keeps cell padding-based gaps intact.

		$style_text = self::build_styles( $styles );
		if ( '' !== $style_text ) {
			$table->setAttribute( 'style', $style_text );
		}

		foreach ( array( 'class', 'id', 'dir' ) as $attribute ) {
			if ( $element->hasAttribute( $attribute ) ) {
				$table->setAttribute( $attribute, $element->getAttribute( $attribute ) );
			}
		}

		return $table;
	}

	/**
	 * Append a cell wrapping an original flex/grid child.
	 *
	 * The child element is moved into the cell unchanged (minus its flex
	 * sizing properties), so its own borders, padding and backgrounds keep
	 * rendering exactly as authored.
	 *
	 * @param DOMElement $row         The table row.
	 * @param DOMElement $child       The original container child.
	 * @param array      $cell_styles Styles for the new cell.
	 */
	private function append_cell( DOMElement $row, DOMElement $child, array $cell_styles ): void {
		$cell = $row->ownerDocument->createElement( 'td' );
		$row->appendChild( $cell );

		$style_text = self::build_styles( $cell_styles );
		if ( '' !== $style_text ) {
			$cell->setAttribute( 'style', $style_text );
		}

		$child_styles = self::parse_styles( $child->getAttribute( 'style' ) );
		self::strip_flex_child_styles( $child_styles );
		self::set_styles( $child, $child_styles );

		$cell->appendChild( $child );
	}

	/**
	 * Whether any child carries an explicit flex basis width.
	 *
	 * @param DOMElement[] $children The container's element children.
	 *
	 * @return bool
	 */
	private static function has_sized_child( array $children ): bool {
		foreach ( $children as $child ) {
			if ( 'fixed' === self::flex_child_spec( $child )['kind'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify a flex child's sizing from its `flex` shorthand.
	 *
	 * @param DOMElement $child The flex child.
	 *
	 * @return array{kind:string,width:string} kind: grow|shrink|fixed|auto.
	 */
	private static function flex_child_spec( DOMElement $child ): array {
		$styles = self::parse_styles( $child->getAttribute( 'style' ) );
		$flex   = isset( $styles['flex'] ) ? strtolower( trim( $styles['flex'] ) ) : '';

		if ( '' === $flex ) {
			if ( isset( $styles['flex-grow'] ) && (float) $styles['flex-grow'] > 0 ) {
				return array(
					'kind' => 'grow',
					'width' => '',
				);
			}

			return array(
				'kind' => 'auto',
				'width' => '',
			);
		}

		// flex: auto is the 1 1 auto shorthand — a growing column.
		if ( 'auto' === $flex ) {
			return array(
				'kind' => 'grow',
				'width' => '',
			);
		}

		$parts = preg_split( '/\s+/', $flex );
		$grow  = is_numeric( $parts[0] ) ? (float) $parts[0] : 0.0;
		$basis = \count( $parts ) >= 3 ? $parts[2] : ( isset( $parts[1] ) && ! is_numeric( $parts[1] ) ? $parts[1] : 'auto' );
		if ( 1 === \count( $parts ) && ! is_numeric( $parts[0] ) ) {
			$basis = $parts[0];
		}

		if ( $grow > 0 ) {
			return array(
				'kind' => 'grow',
				'width' => '',
			);
		}

		$basis_px = self::length_to_px( $basis );
		if ( null !== $basis_px && $basis_px > 0 ) {
			return array(
				'kind' => 'fixed',
				'width' => self::css_number( $basis_px ) . 'px',
			);
		}

		return array(
			'kind' => 'shrink',
			'width' => '',
		);
	}

	/**
	 * Parse grid-template-columns into px/fr/auto column specs.
	 *
	 * @param string $value The grid-template-columns value.
	 *
	 * @return array<int,array{kind:string,value:float}>
	 */
	private static function parse_grid_columns( string $value ): array {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return array();
		}

		// Expand simple repeat(N, token) constructs.
		$value = (string) preg_replace_callback(
			'/repeat\(\s*(\d+)\s*,\s*([^()]+)\)/',
			static function ( array $matches ): string {
				return trim( implode( ' ', array_fill( 0, max( 1, (int) $matches[1] ), trim( $matches[2] ) ) ) );
			},
			$value
		);

		$columns = array();
		foreach ( preg_split( '/\s+/', $value ) as $token ) {
			if ( '' === $token ) {
				continue;
			}

			if ( preg_match( '/^([0-9.]+)fr$/', $token, $m ) ) {
				$columns[] = array(
					'kind' => 'fr',
					'value' => (float) $m[1],
				);
				continue;
			}

			if ( 'auto' === $token || 'min-content' === $token || 'max-content' === $token ) {
				$columns[] = array(
					'kind' => 'auto',
					'value' => 0.0,
				);
				continue;
			}

			$px = self::length_to_px( $token );
			if ( null !== $px ) {
				$columns[] = array(
					'kind' => 'px',
					'value' => $px,
				);
				continue;
			}

			// Unknown token (minmax(), %) — treat as an even flexible column.
			$columns[] = array(
				'kind' => 'fr',
				'value' => 1.0,
			);
		}

		return $columns;
	}

	/**
	 * Parse the container gap into [row, column] pixels.
	 *
	 * @param array $styles Parsed container styles.
	 *
	 * @return array{0:float,1:float} [row gap, column gap] in px.
	 */
	private static function parse_gap_px( array $styles ): array {
		$row = 0.0;
		$col = 0.0;

		if ( isset( $styles['gap'] ) ) {
			$parts = preg_split( '/\s+/', trim( $styles['gap'] ) );
			$row   = (float) ( self::length_to_px( $parts[0] ) ?? 0.0 );
			$col   = isset( $parts[1] ) ? (float) ( self::length_to_px( $parts[1] ) ?? 0.0 ) : $row;
		}

		if ( isset( $styles['row-gap'] ) ) {
			$row = (float) ( self::length_to_px( $styles['row-gap'] ) ?? $row );
		}
		if ( isset( $styles['column-gap'] ) ) {
			$col = (float) ( self::length_to_px( $styles['column-gap'] ) ?? $col );
		}

		return array( $row, $col );
	}

	/**
	 * Map align-items to a table-cell vertical-align.
	 *
	 * @param array $styles Parsed container styles.
	 *
	 * @return string The vertical-align value.
	 */
	private static function vertical_align( array $styles ): string {
		$align = isset( $styles['align-items'] ) ? strtolower( trim( $styles['align-items'] ) ) : '';

		if ( 'flex-end' === $align || 'end' === $align ) {
			return 'bottom';
		}
		if ( 'center' === $align ) {
			return 'middle';
		}
		if ( 'baseline' === $align ) {
			return 'baseline';
		}

		return 'top';
	}

	/**
	 * Format a float for CSS output, immune to LC_NUMERIC comma locales.
	 *
	 * @param float $value The value to format.
	 *
	 * @return string The formatted number.
	 */
	private static function css_number( float $value ): string {
		$formatted = rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	/**
	 * Convert a CSS length to px (px and pt only — template inline styles).
	 *
	 * @param string $value The CSS length.
	 *
	 * @return float|null Pixels, or null when not convertible.
	 */
	private static function length_to_px( string $value ): ?float {
		$value = strtolower( trim( $value ) );

		if ( preg_match( '/^(-?[0-9.]+)px$/', $value, $m ) ) {
			return (float) $m[1];
		}
		if ( preg_match( '/^(-?[0-9.]+)pt$/', $value, $m ) ) {
			return (float) $m[1] / self::PT_PER_PX;
		}
		if ( '0' === $value ) {
			return 0.0;
		}

		return null;
	}

	/**
	 * Resolve the root element's padding to [top, right, bottom, left] px.
	 *
	 * @param array $styles Parsed root styles.
	 *
	 * @return float[]|null Padding box, or null when absent/unparseable.
	 */
	private static function resolve_padding_px( array $styles ): ?array {
		$padding = array( 0.0, 0.0, 0.0, 0.0 );
		$found   = false;

		if ( isset( $styles['padding'] ) ) {
			$parts = preg_split( '/\s+/', trim( $styles['padding'] ) );
			$px    = array();
			foreach ( $parts as $part ) {
				$len = self::length_to_px( $part );
				if ( null === $len ) {
					return null; // Unsupported unit — leave the template alone.
				}
				$px[] = $len;
			}

			switch ( \count( $px ) ) {
				case 1:
					$padding = array( $px[0], $px[0], $px[0], $px[0] );
					break;
				case 2:
					$padding = array( $px[0], $px[1], $px[0], $px[1] );
					break;
				case 3:
					$padding = array( $px[0], $px[1], $px[2], $px[1] );
					break;
				case 4:
					$padding = array( $px[0], $px[1], $px[2], $px[3] );
					break;
				default:
					return null;
			}
			$found = true;
		}

		$sides = array(
			'padding-top'    => 0,
			'padding-right'  => 1,
			'padding-bottom' => 2,
			'padding-left'   => 3,
		);
		foreach ( $sides as $property => $index ) {
			if ( ! isset( $styles[ $property ] ) ) {
				continue;
			}
			$len = self::length_to_px( $styles[ $property ] );
			if ( null === $len ) {
				return null;
			}
			$padding[ $index ] = $len;
			$found             = true;
		}

		return $found ? $padding : null;
	}

	/**
	 * Element children of a node (skipping text/comment nodes).
	 *
	 * @param DOMElement $element The parent element.
	 *
	 * @return DOMElement[]
	 */
	private static function element_children( DOMElement $element ): array {
		$children = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Remove container-level layout properties before reuse on a table/block.
	 *
	 * @param array $styles Parsed styles, modified in place.
	 */
	private static function strip_layout_styles( array &$styles ): void {
		unset(
			$styles['display'],
			$styles['flex-direction'],
			$styles['flex-wrap'],
			$styles['justify-content'],
			$styles['align-items'],
			$styles['align-content'],
			$styles['gap'],
			$styles['row-gap'],
			$styles['column-gap'],
			$styles['grid-template-columns'],
			$styles['grid-template-rows'],
			$styles['grid-auto-flow']
		);
	}

	/**
	 * Remove child-level flex sizing properties.
	 *
	 * @param array $styles Parsed styles, modified in place.
	 */
	private static function strip_flex_child_styles( array &$styles ): void {
		unset(
			$styles['flex'],
			$styles['flex-grow'],
			$styles['flex-shrink'],
			$styles['flex-basis'],
			$styles['align-self'],
			$styles['justify-self'],
			$styles['min-width']
		);
	}

	/**
	 * Parse an inline style attribute into an ordered property map.
	 *
	 * @param string $style The style attribute value.
	 *
	 * @return array<string,string>
	 */
	private static function parse_styles( string $style ): array {
		$styles       = array();
		$declarations = array();
		$buffer       = '';
		$depth        = 0;
		$quote        = '';
		$length       = \strlen( $style );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $style[ $i ];

			if ( '' !== $quote ) {
				$buffer .= $char;
				if ( $char === $quote && ( 0 === $i || '\\' !== $style[ $i - 1 ] ) ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote   = $char;
				$buffer .= $char;
				continue;
			}

			if ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char && $depth > 0 ) {
				$depth--;
			}

			if ( ';' === $char && 0 === $depth ) {
				$declarations[] = $buffer;
				$buffer         = '';
				continue;
			}

			$buffer .= $char;
		}

		$declarations[] = $buffer;

		foreach ( $declarations as $declaration ) {
			$colon = strpos( $declaration, ':' );
			if ( false === $colon ) {
				continue;
			}

			$property = strtolower( trim( substr( $declaration, 0, $colon ) ) );
			$value    = trim( substr( $declaration, $colon + 1 ) );
			if ( '' !== $property && '' !== $value ) {
				$styles[ $property ] = $value;
			}
		}

		return $styles;
	}

	/**
	 * Serialize a property map back to a style string.
	 *
	 * @param array $styles The property map.
	 *
	 * @return string
	 */
	private static function build_styles( array $styles ): string {
		$declarations = array();
		foreach ( $styles as $property => $value ) {
			$declarations[] = $property . ': ' . $value;
		}

		return implode( '; ', $declarations );
	}

	/**
	 * Write a property map to an element's style attribute.
	 *
	 * @param DOMElement $element The element.
	 * @param array      $styles  The property map.
	 */
	private static function set_styles( DOMElement $element, array $styles ): void {
		$style_text = self::build_styles( $styles );
		if ( '' === $style_text ) {
			$element->removeAttribute( 'style' );
		} else {
			$element->setAttribute( 'style', $style_text );
		}
	}

	/**
	 * Append one declaration to an element's existing style attribute.
	 *
	 * @param DOMElement $element  The element.
	 * @param string     $property The CSS property.
	 * @param string     $value    The CSS value.
	 */
	private static function append_style( DOMElement $element, string $property, string $value ): void {
		$styles              = self::parse_styles( $element->getAttribute( 'style' ) );
		$styles[ $property ] = $value;
		self::set_styles( $element, $styles );
	}
}
