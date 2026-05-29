<?php
/**
 * Thermal Markup Parser Class.
 *
 * Parses a thermal XML template string into a nested AST array. This is a PHP
 * port of the JS receipt-renderer `parse-xml.ts` and mirrors its defaults and
 * behavior so server-rendered output matches the client renderer.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

/**
 * Thermal_Markup_Parser class.
 */
class Thermal_Markup_Parser {

	/**
	 * Parse a thermal XML template string into an AST.
	 *
	 * @param string $xml The thermal XML markup.
	 *
	 * @throws RuntimeException When the markup cannot be parsed or the root is not <receipt>.
	 *
	 * @return array The root receipt AST node as a nested array.
	 */
	public function parse( string $xml ): array {
		$doc = $this->load_document( $xml );

		$root = $doc->documentElement;
		if ( null === $root || 'receipt' !== strtolower( $root->tagName ) ) {
			throw new RuntimeException( 'XML parse error' );
		}

		return array(
			'type'        => 'receipt',
			'paper_width' => $this->int_attr( $root, 'paper-width', 48 ),
			'children'    => $this->parse_children( $root ),
		);
	}

	/**
	 * Load an XML string into a DOMDocument, suppressing libxml warnings.
	 *
	 * @param string $xml The thermal XML markup.
	 *
	 * @throws RuntimeException When DOMDocument cannot load the markup.
	 *
	 * @return DOMDocument The loaded document.
	 */
	private function load_document( string $xml ): DOMDocument {
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$doc    = new DOMDocument();
		$loaded = $doc->loadXML( $xml, LIBXML_NONET );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $loaded || null === $doc->documentElement ) {
			throw new RuntimeException( 'XML parse error' );
		}

		return $doc;
	}

	/**
	 * Parse the child nodes of an element into AST nodes.
	 *
	 * @param DOMElement $parent The parent element.
	 *
	 * @return array List of AST nodes.
	 */
	private function parse_children( DOMElement $parent ): array {
		$nodes = array();

		foreach ( $parent->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text = null === $child->textContent ? '' : $child->textContent;
				// Skip whitespace-only nodes (indentation), but preserve
				// non-empty text as-is so spaces around inline elements survive.
				if ( preg_match( '/\S/', $text ) ) {
					$nodes[] = array(
						'type'  => 'raw-text',
						'value' => $text,
					);
				}
				continue;
			}

			if ( XML_ELEMENT_NODE !== $child->nodeType || ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );

			switch ( $tag ) {
				case 'text':
				case 'bold':
				case 'underline':
				case 'invert':
					$nodes[] = array(
						'type'     => $tag,
						'children' => $this->parse_children( $child ),
					);
					break;
				case 'size':
					$width   = $this->int_attr( $child, 'width', 1 );
					$nodes[] = array(
						'type'     => 'size',
						'width'    => $width,
						'height'   => $this->int_attr( $child, 'height', $width ),
						'children' => $this->parse_children( $child ),
					);
					break;
				case 'align':
					$nodes[] = array(
						'type'     => 'align',
						'mode'     => $this->enum_attr( $child, 'mode', array( 'left', 'center', 'right' ), 'left' ),
						'children' => $this->parse_children( $child ),
					);
					break;
				case 'row':
					$nodes[] = array(
						'type'     => 'row',
						'children' => $this->parse_row_children( $child ),
					);
					break;
				case 'col':
					break;
				case 'line':
					$nodes[] = array(
						'type'  => 'line',
						'style' => $this->enum_attr( $child, 'style', array( 'single', 'double', 'dashed', 'dotted' ), 'single' ),
					);
					break;
				case 'barcode':
					$type = $child->hasAttribute( 'type' ) ? $child->getAttribute( 'type' ) : 'code128';
					if ( $this->is_qr_barcode_type( $type ) ) {
						$nodes[] = array(
							'type'  => 'qrcode',
							'size'  => $this->height_to_qr_size( $this->int_attr( $child, 'height', 40 ) ),
							'value' => trim( $child->textContent ),
						);
					} else {
						$nodes[] = array(
							'type'         => 'barcode',
							'barcode_type' => $type,
							'height'       => $this->int_attr( $child, 'height', 40 ),
							'value'        => trim( $child->textContent ),
						);
					}
					break;
				case 'qrcode':
					$nodes[] = array(
						'type'  => 'qrcode',
						'size'  => $this->int_attr( $child, 'size', 4 ),
						'value' => trim( $child->textContent ),
					);
					break;
				case 'image':
					$nodes[] = array(
						'type'  => 'image',
						'src'   => $child->hasAttribute( 'src' ) ? $child->getAttribute( 'src' ) : '',
						'width' => $this->int_attr( $child, 'width', 200 ),
					);
					break;
				case 'cut':
					$nodes[] = array(
						'type'     => 'cut',
						'cut_type' => $this->enum_attr( $child, 'type', array( 'full', 'partial' ), 'partial' ),
					);
					break;
				case 'feed':
					$nodes[] = array(
						'type'  => 'feed',
						'lines' => $this->int_attr( $child, 'lines', 1 ),
					);
					break;
				case 'drawer':
					$nodes[] = array( 'type' => 'drawer' );
					break;
				default:
					foreach ( $this->parse_children( $child ) as $node ) {
						$nodes[] = $node;
					}
			}
		}

		return $nodes;
	}

	/**
	 * Parse the children of a row element, keeping only <col> elements.
	 *
	 * @param DOMElement $row The row element.
	 *
	 * @return array List of col AST nodes.
	 */
	private function parse_row_children( DOMElement $row ): array {
		$cols = array();

		foreach ( $row->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || ! $child instanceof DOMElement ) {
				continue;
			}
			if ( 'col' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$raw_width = $child->hasAttribute( 'width' ) ? $child->getAttribute( 'width' ) : null;
			$width     = ( '*' === $raw_width ) ? '*' : $this->int_attr( $child, 'width', 12 );

			$cols[] = array(
				'type'     => 'col',
				'width'    => $width,
				'align'    => $this->enum_attr( $child, 'align', array( 'left', 'right' ), 'left' ),
				'children' => $this->parse_children( $child ),
			);
		}

		return $cols;
	}

	/**
	 * Resolve an attribute against a fixed set of valid values.
	 *
	 * @param DOMElement $el       The element to read from.
	 * @param string     $name     The attribute name.
	 * @param array      $valid    The allowed values.
	 * @param string     $fallback The fallback value when missing or invalid.
	 *
	 * @return string The resolved value.
	 */
	private function enum_attr( DOMElement $el, string $name, array $valid, string $fallback ): string {
		$value = $el->hasAttribute( $name ) ? $el->getAttribute( $name ) : null;

		return ( null !== $value && in_array( $value, $valid, true ) ) ? $value : $fallback;
	}

	/**
	 * Resolve a positive-integer attribute, mirroring the JS intAttr helper.
	 *
	 * An attribute is only valid when it matches /^[1-9]\d*$/ (trimmed) and is a
	 * safe integer; otherwise the fallback is returned.
	 *
	 * @param DOMElement $el       The element to read from.
	 * @param string     $name     The attribute name.
	 * @param int        $fallback The fallback value.
	 *
	 * @return int The resolved integer.
	 */
	private function int_attr( DOMElement $el, string $name, int $fallback ): int {
		if ( ! $el->hasAttribute( $name ) ) {
			return $fallback;
		}

		$raw = trim( $el->getAttribute( $name ) );
		if ( ! preg_match( '/^[1-9]\d*$/', $raw ) ) {
			return $fallback;
		}

		$value = (int) $raw;

		return ( (string) $value === $raw && $value <= PHP_INT_MAX ) ? $value : $fallback;
	}

	/**
	 * Determine whether a barcode type should be rendered as a QR code.
	 *
	 * @param string $type The barcode type attribute value.
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
}
