<?php
/**
 * Shared character-cell layout helpers for thermal emitters.
 *
 * Thermal printers lay text out in fixed character cells, so every emitter that
 * targets one needs the same primitives: display-width measurement that counts
 * CJK glyphs as two cells, truncation and padding against that width, star-column
 * distribution for `<row>`, and typographic normalization for characters the
 * printer's codepage cannot render.
 *
 * The methods are pure — they take the paper width and alignment as arguments
 * rather than reading emitter state — so a trait user needs no particular
 * property names.
 *
 * `Escpos_Thermal_Emitter` and `Text_Thermal_Emitter` share this copy;
 * `Starprnt_Thermal_Emitter` and `Epos_Xml_Thermal_Emitter` still carry their own
 * (identical) private copies, which are theirs to drop when they are next touched.
 *
 * @package WCPOS\WooCommercePOS\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

/**
 * Thermal_Text_Layout trait.
 */
trait Thermal_Text_Layout {

	/**
	 * Normalize text by replacing non-ASCII typographic characters.
	 *
	 * @param string $value The input text.
	 *
	 * @return string The normalized text.
	 */
	private function normalize_text( string $value ): string {
		$search = array( "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}" );
		$value  = str_replace( $search, '-', $value );
		$value  = str_replace( array( "\u{2018}", "\u{2019}" ), "'", $value );
		$value  = str_replace( array( "\u{201C}", "\u{201D}" ), '"', $value );
		// CLDR time patterns separate the hour from the day period with a narrow
		// or thin no-break space; neither survives a printer character table.
		$value  = str_replace( array( "\u{00A0}", "\u{202F}", "\u{2009}" ), ' ', $value );

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
			$used   += $next;
		}

		return $result;
	}

	/**
	 * Compute the leading-space padding that aligns a line of the given width.
	 *
	 * @param string $align      The alignment mode (left|center|right).
	 * @param int    $text_width The display width of the line's plain text.
	 * @param int    $columns    The paper width in character cells.
	 *
	 * @return int The number of leading spaces (clamped at 0).
	 */
	private function alignment_padding( string $align, int $text_width, int $columns ): int {
		$remaining = $columns - $text_width;
		if ( $remaining <= 0 ) {
			return 0;
		}
		if ( 'center' === $align ) {
			return (int) floor( $remaining / 2 );
		}
		if ( 'right' === $align ) {
			return $remaining;
		}

		return 0;
	}

	/**
	 * Resolve concrete column widths for a row, splitting star columns.
	 *
	 * @param array $cols    The column AST nodes.
	 * @param int   $columns The paper width in character cells.
	 *
	 * @return array The resolved integer widths, indexed by column.
	 */
	private function resolve_row_widths( array $cols, int $columns ): array {
		$fixed_total = 0;
		$star_count  = 0;
		foreach ( $cols as $col ) {
			if ( isset( $col['width'] ) && '*' === $col['width'] ) {
				++$star_count;
			} else {
				$fixed_total += isset( $col['width'] ) ? (int) $col['width'] : 0;
			}
		}

		$remaining      = max( 0, $columns - $fixed_total );
		$star_width     = $star_count > 0 ? (int) floor( $remaining / $star_count ) : 0;
		$star_remainder = $star_count > 0 ? $remaining - ( $star_width * $star_count ) : 0;

		$widths     = array();
		$star_index = 0;
		foreach ( $cols as $index => $col ) {
			if ( isset( $col['width'] ) && '*' === $col['width'] ) {
				++$star_index;
				$extra            = ( $star_index === $star_count ) ? $star_remainder : 0;
				$widths[ $index ] = max( 1, $star_width + $extra );
			} else {
				$widths[ $index ] = isset( $col['width'] ) ? (int) $col['width'] : 0;
			}
		}

		return $widths;
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
		if ( \function_exists( 'mb_str_split' ) ) {
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
	 * The plugin does not require ext-mbstring, so the fallback decodes the
	 * UTF-8 byte sequence by hand rather than reaching for another mb_ function.
	 *
	 * @param string $char The single UTF-8 character.
	 *
	 * @return int The code point, or -1 when undetermined.
	 */
	private function code_point( string $char ): int {
		if ( \function_exists( 'mb_ord' ) ) {
			// mb_ord() is typed int by stubs; cast guards a theoretical false (invalid
			// char) to 0, which is_full_width() treats as not full-width.
			return (int) mb_ord( $char, 'UTF-8' );
		}

		$length = \strlen( $char );
		if ( 1 === $length ) {
			return \ord( $char );
		}
		if ( 2 === $length ) {
			return ( ( \ord( $char[0] ) & 0x1f ) << 6 ) | ( \ord( $char[1] ) & 0x3f );
		}
		if ( 3 === $length ) {
			return ( ( \ord( $char[0] ) & 0x0f ) << 12 ) | ( ( \ord( $char[1] ) & 0x3f ) << 6 ) | ( \ord( $char[2] ) & 0x3f );
		}
		if ( 4 === $length ) {
			return ( ( \ord( $char[0] ) & 0x07 ) << 18 ) | ( ( \ord( $char[1] ) & 0x3f ) << 12 ) | ( ( \ord( $char[2] ) & 0x3f ) << 6 ) | ( \ord( $char[3] ) & 0x3f );
		}

		return -1;
	}
}
