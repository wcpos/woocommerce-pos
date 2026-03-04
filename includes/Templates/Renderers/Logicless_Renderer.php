<?php
/**
 * Logicless receipt renderer.
 *
 * Supports Mustache-style section blocks for iteration and conditionals:
 *   {{#key}}...{{/key}}  — iterate arrays or show block for truthy values
 *   {{^key}}...{{/key}}  — show block when value is empty/falsy
 *   {{.}}                — current value (for arrays of scalars)
 *   {{key.path}}         — dot-path placeholder substitution
 *
 * Money fields are auto-formatted as currency using wc_price().
 * Section nesting is capped at depth 2.
 *
 * @package WCPOS\WooCommercePOS\Templates\Renderers
 */

namespace WCPOS\WooCommercePOS\Templates\Renderers;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Renderer_Interface;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WC_Abstract_Order;

/**
 * Logicless_Renderer class.
 */
class Logicless_Renderer implements Receipt_Renderer_Interface {

	/**
	 * Maximum section nesting depth.
	 */
	const MAX_SECTION_DEPTH = 2;

	/**
	 * Currency code for formatting.
	 *
	 * @var string
	 */
	private $currency = 'USD';

	/**
	 * Money field names (flipped for O(1) lookup).
	 *
	 * @var array
	 */
	private $money_fields = array();

	/**
	 * Render logicless template output.
	 *
	 * @param array             $template     Template metadata/content.
	 * @param WC_Abstract_Order $order        Order object.
	 * @param array             $receipt_data Canonical receipt payload.
	 */
	public function render( array $template, WC_Abstract_Order $order, array $receipt_data ): void {
		$content = isset( $template['content'] ) && \is_string( $template['content'] ) ? $template['content'] : '';

		if ( '' === $content ) {
			echo '<!-- Empty logicless receipt template -->';
			return;
		}

		$this->currency     = $receipt_data['meta']['currency'] ?? 'USD';
		$this->money_fields = array_flip( Receipt_Data_Schema::MONEY_FIELDS );

		$output = $this->process_content( $content, array( $receipt_data ), 0 );

		echo wp_kses_post( $output );
	}

	/**
	 * Process section blocks, then substitute remaining placeholders.
	 *
	 * @param string $content       Template content.
	 * @param array  $context_stack Stack of data contexts (innermost last).
	 * @param int    $depth         Current section nesting depth.
	 *
	 * @return string
	 */
	private function process_content( string $content, array $context_stack, int $depth ): string {
		if ( $depth >= self::MAX_SECTION_DEPTH ) {
			$stripped = preg_replace( '/\\{\\{[#^\\/][\\w.]+\\}\\}/', '', $content );
			return $this->substitute_placeholders( $stripped, $context_stack );
		}

		$result = '';
		$offset = 0;

		while ( preg_match( '/\\{\\{([#^])([\\w.]+)\\}\\}/', $content, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$tag_start = $match[0][1];
			$type      = $match[1][0];
			$key       = $match[2][0];

			$before = substr( $content, $offset, $tag_start - $offset );
			$result .= $this->substitute_placeholders( $before, $context_stack );

			$inner_start = $tag_start + \strlen( $match[0][0] );
			$close_pos   = $this->find_closing_tag( $content, $key, $inner_start );

			if ( false === $close_pos ) {
				$result .= $match[0][0];
				$offset  = $inner_start;
				continue;
			}

			$inner       = substr( $content, $inner_start, $close_pos - $inner_start );
			$closing_tag = '{{/' . $key . '}}';
			$offset      = $close_pos + \strlen( $closing_tag );

			// Standalone tag handling: if the opening tag sits alone on a line
			// (only whitespace between the preceding newline and the tag),
			// strip the leading newline from inner content.
			$open_is_standalone = $this->is_standalone_tag( $content, $tag_start, $inner_start );
			if ( $open_is_standalone ) {
				$inner = $this->strip_leading_newline( $inner );
			}

			// If the closing tag sits alone on a line, consume its trailing newline.
			$close_end           = $close_pos + \strlen( $closing_tag );
			$close_is_standalone = $this->is_standalone_tag( $content, $close_pos, $close_end );
			if ( $close_is_standalone && isset( $content[ $offset ] ) && "\n" === $content[ $offset ] ) {
				++$offset;
			}

			$value = $this->resolve_value( $key, $context_stack );

			if ( '#' === $type ) {
				$result .= $this->process_truthy_section( $inner, $value, $context_stack, $depth );
			} elseif ( '^' === $type ) {
				$result .= $this->process_inverted_section( $inner, $value, $context_stack, $depth );
			}
		}

		$result .= $this->substitute_placeholders(
			substr( $content, $offset ),
			$context_stack
		);

		return $result;
	}

	/**
	 * Process a truthy (#) section block.
	 *
	 * @param string $inner         Inner template content.
	 * @param mixed  $value         Resolved section value.
	 * @param array  $context_stack Current context stack.
	 * @param int    $depth         Current nesting depth.
	 *
	 * @return string
	 */
	private function process_truthy_section( string $inner, $value, array $context_stack, int $depth ): string {
		$result = '';

		if ( \is_array( $value ) && ! $this->is_associative( $value ) ) {
			foreach ( $value as $item ) {
				$child_ctx = \is_array( $item ) ? $item : array( '.' => $item );
				$result   .= $this->process_content( $inner, array_merge( $context_stack, array( $child_ctx ) ), $depth + 1 );
			}
		} elseif ( \is_array( $value ) && $this->is_associative( $value ) ) {
			$result .= $this->process_content( $inner, array_merge( $context_stack, array( $value ) ), $depth );
		} elseif ( ! empty( $value ) ) {
			$result .= $this->process_content( $inner, $context_stack, $depth );
		}

		return $result;
	}

	/**
	 * Process an inverted (^) section block.
	 *
	 * @param string $inner         Inner template content.
	 * @param mixed  $value         Resolved section value.
	 * @param array  $context_stack Current context stack.
	 * @param int    $depth         Current nesting depth.
	 *
	 * @return string
	 */
	private function process_inverted_section( string $inner, $value, array $context_stack, int $depth ): string {
		$is_empty = ( null === $value )
			|| ( '' === $value )
			|| ( false === $value )
			|| ( \is_array( $value ) && 0 === \count( $value ) );

		if ( $is_empty ) {
			return $this->process_content( $inner, $context_stack, $depth );
		}

		return '';
	}

	/**
	 * Find the matching closing tag for a section, accounting for nesting.
	 *
	 * @param string $content Template content.
	 * @param string $key     Section key name.
	 * @param int    $offset  Position to start searching from.
	 *
	 * @return int|false Position of the closing tag, or false if not found.
	 */
	private function find_closing_tag( string $content, string $key, int $offset ) {
		$open_tag  = '{{#' . $key . '}}';
		$close_tag = '{{/' . $key . '}}';
		$nesting   = 1;
		$pos       = $offset;

		while ( $nesting > 0 ) {
			$next_open  = strpos( $content, $open_tag, $pos );
			$next_close = strpos( $content, $close_tag, $pos );

			if ( false === $next_close ) {
				return false;
			}

			if ( false !== $next_open && $next_open < $next_close ) {
				++$nesting;
				$pos = $next_open + \strlen( $open_tag );
			} else {
				--$nesting;
				if ( 0 === $nesting ) {
					return $next_close;
				}
				$pos = $next_close + \strlen( $close_tag );
			}
		}

		return false;
	}

	/**
	 * Resolve a dot-path key against the context stack.
	 *
	 * Tries each context from innermost to outermost.
	 *
	 * @param string $key           Dot-separated key path.
	 * @param array  $context_stack Stack of data contexts.
	 *
	 * @return mixed Resolved value, or null if not found.
	 */
	private function resolve_value( string $key, array $context_stack ) {
		if ( '.' === $key ) {
			$ctx = end( $context_stack );
			return isset( $ctx['.'] ) ? $ctx['.'] : $ctx;
		}

		for ( $i = \count( $context_stack ) - 1; $i >= 0; $i-- ) {
			$value = $this->get_nested_value( $context_stack[ $i ], $key );
			if ( null !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Traverse a nested array using a dot-separated key path.
	 *
	 * @param array  $data Nested array.
	 * @param string $key  Dot-separated key path.
	 *
	 * @return mixed Value at the path, or null if not found.
	 */
	private function get_nested_value( array $data, string $key ) {
		$segments = explode( '.', $key );
		$current  = $data;

		foreach ( $segments as $segment ) {
			if ( ! \is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * Replace {{placeholder}} tokens with values from the context stack.
	 *
	 * @param string $content       Template content with placeholders.
	 * @param array  $context_stack Stack of data contexts.
	 *
	 * @return string
	 */
	private function substitute_placeholders( string $content, array $context_stack ): string {
		return preg_replace_callback(
			'/\\{\\{\\s*([\\w.]+)\\s*\\}\\}/',
			function ( $matches ) use ( $context_stack ) {
				$key   = $matches[1];
				$value = $this->resolve_value( $key, $context_stack );

				if ( null === $value || \is_array( $value ) ) {
					return '';
				}

				return $this->format_value( $value, $key );
			},
			$content
		);
	}

	/**
	 * Format a value for output, auto-formatting money fields.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $key   Dot-path key (used to detect money fields).
	 *
	 * @return string
	 */
	private function format_value( $value, string $key ): string {
		if ( ! is_numeric( $value ) ) {
			return (string) $value;
		}

		$terminal = $key;
		$dot_pos  = strrpos( $key, '.' );
		if ( false !== $dot_pos ) {
			$terminal = substr( $key, $dot_pos + 1 );
		}

		if ( isset( $this->money_fields[ $terminal ] ) ) {
			return wp_strip_all_tags(
				wc_price( (float) $value, array( 'currency' => $this->currency ) )
			);
		}

		return (string) $value;
	}

	/**
	 * Determine whether an array is associative.
	 *
	 * @param array $array Array value.
	 *
	 * @return bool
	 */
	private function is_associative( array $array ): bool {
		if ( array() === $array ) {
			return false;
		}

		return array_keys( $array ) !== range( 0, \count( $array ) - 1 );
	}

	/**
	 * Check if a tag occupies a line by itself (only whitespace before it on the line).
	 *
	 * @param string $content  Full template content.
	 * @param int    $tag_pos  Start position of the tag.
	 * @param int    $tag_end  End position of the tag.
	 *
	 * @return bool
	 */
	private function is_standalone_tag( string $content, int $tag_pos, int $tag_end ): bool {
		// Find the start of the line containing this tag.
		$line_start = strrpos( substr( $content, 0, $tag_pos ), "\n" );
		$line_start = ( false === $line_start ) ? 0 : $line_start + 1;

		// Check that only whitespace exists between line start and tag.
		$before_tag = substr( $content, $line_start, $tag_pos - $line_start );
		if ( '' !== trim( $before_tag ) ) {
			return false;
		}

		// Check that nothing follows the tag on this line (or it's the end).
		if ( $tag_end >= \strlen( $content ) ) {
			return true;
		}

		return "\n" === $content[ $tag_end ] || ! isset( $content[ $tag_end ] );
	}

	/**
	 * Strip a leading newline from content.
	 *
	 * @param string $content Template content.
	 *
	 * @return string
	 */
	private function strip_leading_newline( string $content ): string {
		if ( \strlen( $content ) > 0 && "\n" === $content[0] ) {
			return substr( $content, 1 );
		}

		return $content;
	}

}
