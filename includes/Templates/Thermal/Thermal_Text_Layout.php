<?php
/**
 * Shared character-cell layout for thermal emitters.
 *
 * Thermal printers lay text out in fixed character cells, so every emitter that
 * targets one needs the same primitives: display-width measurement that counts
 * CJK glyphs as two cells, truncation and padding against that width, star-column
 * distribution for `<row>`, plain-text extraction, and typographic normalization
 * for characters the printer's codepage cannot render.
 *
 * None of this has anything to do with ESC/POS, StarPRNT, ePOS-XML or Star
 * Document Markup — a column is a column on all of them, and a receipt that lays
 * out differently per protocol is a bug. These primitives lived as private copies
 * in each emitter and drifted apart: the Star Markup copy measured U+FFE5
 * FULLWIDTH YEN as one cell while the others measured two, and two copies of the
 * no-mbstring fallback called mb_convert_encoding() — itself an mbstring
 * function — so a host without the extension took a fatal.
 *
 * Deliberately stateless and static rather than a trait. Four private copies that
 * *looked* locally defined are how the drift happened in the first place;
 * `display_width()` called on `$this` is indistinguishable from a method the
 * emitter owns, whereas `Thermal_Text_Layout::display_width()` names its source
 * at every call.
 * Being static also keeps `columns` an argument instead of an implicit property
 * contract, and lets the layout be exercised on its own.
 *
 * Text emission itself stays with each emitter: only the measuring moved.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

/**
 * Thermal_Text_Layout class.
 */
final class Thermal_Text_Layout {

	/**
	 * Not instantiable: every member is a pure static function.
	 */
	private function __construct() {}

	/**
	 * Normalize text by replacing non-ASCII typographic characters.
	 *
	 * @param string $value The input text.
	 *
	 * @return string The normalized text.
	 */
	public static function normalize_text( string $value ): string {
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
	 * Compute the display width of a string (full-width chars count as 2 cells).
	 *
	 * @param string $value The input text.
	 *
	 * @return int The display width in character cells.
	 */
	public static function display_width( string $value ): int {
		$width = 0;
		foreach ( self::split_chars( $value ) as $char ) {
			$width += self::is_full_width( $char ) ? 2 : 1;
		}

		return $width;
	}

	/**
	 * Truncate a string to a maximum display width.
	 *
	 * A full-width character that would straddle the limit is dropped whole
	 * rather than half-printed.
	 *
	 * @param string $value The input text.
	 * @param int    $width The maximum display width in character cells.
	 *
	 * @return string The truncated text.
	 */
	public static function truncate_display( string $value, int $width ): string {
		$result = '';
		$used   = 0;
		foreach ( self::split_chars( $value ) as $char ) {
			$next = self::is_full_width( $char ) ? 2 : 1;
			if ( $used + $next > $width ) {
				break;
			}
			$result .= $char;
			$used   += $next;
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
	public static function split_chars( string $value ): array {
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
	 * Whether a single character occupies two character cells (full-width / CJK).
	 *
	 * @param string $char The single UTF-8 character.
	 *
	 * @return bool True when the character is full-width.
	 */
	public static function is_full_width( string $char ): bool {
		$code = self::code_point( $char );
		if ( $code < 0 ) {
			return false;
		}

		return ( $code >= 0x1100 && $code <= 0x115f )
			// Angle brackets: East Asian Wide by UAX #11, unlike the ASCII pair.
			|| 0x2329 === $code
			|| 0x232a === $code
			|| ( $code >= 0x2e80 && $code <= 0xa4cf )
			|| ( $code >= 0xac00 && $code <= 0xd7a3 )
			|| ( $code >= 0xf900 && $code <= 0xfaff )
			// Vertical forms and CJK compatibility forms.
			|| ( $code >= 0xfe10 && $code <= 0xfe19 )
			|| ( $code >= 0xfe30 && $code <= 0xfe6f )
			|| ( $code >= 0xff00 && $code <= 0xff60 )
			// Fullwidth currency signs, U+FFE5 FULLWIDTH YEN among them.
			|| ( $code >= 0xffe0 && $code <= 0xffe6 )
			// CJK Extension B and beyond: rare, but a single one of these
			// mis-measured throws a whole row's column padding out.
			|| ( $code >= 0x20000 && $code <= 0x2fffd )
			|| ( $code >= 0x30000 && $code <= 0x3fffd );
	}

	/**
	 * Resolve the Unicode code point of a single character.
	 *
	 * The plugin does not require ext-mbstring, so the fallback decodes the UTF-8
	 * byte sequence by hand rather than reaching for another mb_ function: a host
	 * missing mb_ord() is missing the whole extension, so mb_convert_encoding()
	 * is not available to fall back onto either.
	 *
	 * @param string $char The single UTF-8 character.
	 *
	 * @return int The code point, or -1 when undetermined.
	 */
	public static function code_point( string $char ): int {
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

	/**
	 * Extract the concatenated raw text of a node subtree.
	 *
	 * @param array $nodes The AST nodes.
	 *
	 * @return string The concatenated text.
	 */
	public static function extract_text( array $nodes ): string {
		$text = '';
		foreach ( $nodes as $node ) {
			if ( ! \is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['type'] ) && 'raw-text' === $node['type'] ) {
				$text .= isset( $node['value'] ) ? (string) $node['value'] : '';
			} elseif ( isset( $node['children'] ) && \is_array( $node['children'] ) ) {
				$text .= self::extract_text( $node['children'] );
			}
		}

		return $text;
	}

	/**
	 * Resolve concrete column widths for a row, splitting star columns.
	 *
	 * Fixed widths are honoured first; whatever cells are left over are shared
	 * evenly between the star columns, with any remainder going to the last one.
	 *
	 * @param array $cols    The column AST nodes.
	 * @param int   $columns The paper width in character cells.
	 *
	 * @return array The resolved integer widths, indexed by column.
	 */
	public static function resolve_row_widths( array $cols, int $columns ): array {
		$fixed_total = 0;
		$star_count  = 0;
		foreach ( $cols as $col ) {
			if ( isset( $col['width'] ) && '*' === $col['width'] ) {
				++$star_count;
			} else {
				$fixed_total += self::fixed_col_width( $col );
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
				$widths[ $index ] = self::fixed_col_width( $col );
			}
		}

		return $widths;
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
	public static function alignment_padding( string $align, int $text_width, int $columns ): int {
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
	 * A fixed column's width, bounded to what a printer can actually lay out.
	 *
	 * Both the parser and the preview bound `<col width>`; without the same bound
	 * here a hand-built AST -- or a column wider than the paper -- would pad past
	 * the row and wrap onto another physical line, which is the preview/print
	 * divergence this bound exists to close. Star columns are resolved from the
	 * remaining space and never come through here.
	 *
	 * @param array $col The column AST node.
	 *
	 * @return int The bounded width, or 0 when the column declares none.
	 */
	private static function fixed_col_width( array $col ): int {
		if ( ! isset( $col['width'] ) ) {
			return 0;
		}

		return Thermal_Bounds::clamp_int(
			$col['width'],
			0,
			Thermal_Bounds::COL_WIDTH_MIN,
			Thermal_Bounds::COL_WIDTH_MAX
		);
	}
}
