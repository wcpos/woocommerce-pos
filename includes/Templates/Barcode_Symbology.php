<?php
/**
 * Barcode symbology owner — one map, five render lanes.
 *
 * A receipt template names its barcode symbology once (`<barcode type="ean13">`),
 * but that name has to be translated for every render lane we ship: raw ESC/POS
 * bytes, raw StarPRNT bytes, Star Document Markup, ePOS-Print XML, and the
 * picqer rasterizer used by the HTML/PDF path. Each lane spells the same nine
 * symbologies differently, and two of them disagree about the *order* of the UPC
 * pair, so keeping the maps next to the emitters that use them guarantees they
 * drift. They live here instead, with a matrix test pinning every cell.
 *
 * Vendor references:
 * - ESC/POS: Epson ESC/POS Command Reference, `GS k` (barcode, function B).
 * - StarPRNT: StarPRNT Command Specifications Ver 1.3E, section 10 (barcode).
 * - ePOS-Print XML: Epson ePOS-Print XML reference, `<barcode>` element.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS\Templates
 */

namespace WCPOS\WooCommercePOS\Templates;

use WCPOS\Vendor\Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Barcode_Symbology class.
 *
 * All-static and stateless: a pure translation table.
 *
 * Shape, and when to change it. The per-lane accessors come in pairs named for
 * the vendor (escpos_*, starprnt_*) rather than hanging off a lane-descriptor
 * object, because the lanes are not uniform: two want an integer selector, two
 * want a string name, and one wants a picqer constant. A descriptor would make
 * that return type a union and hand the XML and markup lanes members they never
 * use. Revisit when either becomes true: a third vendor needs its own payload
 * encoder, or the matrix test's provider grows past roughly seven columns.
 *
 * Known duplication this class does NOT address: emit_centered_text() is
 * identical in Escpos_Thermal_Emitter and Starprnt_Thermal_Emitter, alongside
 * eleven text-metric helpers those two files already duplicated before this
 * class existed. Extracting one of the twelve into a trait would fragment the
 * set rather than fix it; they belong together in a shared text-layout module,
 * which is scoped separately. Do not treat this class as the pattern for that
 * work — a static lookup owner suits a translation table keyed by lane, not
 * shared behaviour that needs emitter state.
 */
class Barcode_Symbology {
	/**
	 * The 1D symbologies WCPOS supports, in canonical (parser) spelling.
	 *
	 * Every lane accessor below is total over this list.
	 *
	 * @var array
	 */
	public const SYMBOLOGIES = array(
		'code128',
		'code39',
		'code93',
		'ean13',
		'ean8',
		'upca',
		'upce',
		'codabar',
		'itf',
	);

	/**
	 * Canonical name for the 2D QR symbology, which is not a 1D barcode.
	 *
	 * @var string
	 */
	public const QRCODE = 'qrcode';

	/**
	 * Symbology used when a template names one we do not support.
	 *
	 * Code 128 encodes any printable ASCII, so it is the only symbology that can
	 * carry an arbitrary value without a data constraint of its own.
	 *
	 * @var string
	 */
	public const DEFAULT_SYMBOLOGY = 'code128';

	/**
	 * Lane discriminator for the ESC/POS byte emitter.
	 *
	 * @var string
	 */
	public const LANE_ESCPOS = 'escpos';

	/**
	 * Lane discriminator for the StarPRNT byte emitter.
	 *
	 * @var string
	 */
	public const LANE_STARPRNT = 'starprnt';

	/**
	 * Maximum barcode data bytes either byte lane will transmit.
	 *
	 * ESC/POS function B carries the length in a single byte (`GS k m n`), and
	 * the StarPRNT barcode data field is capped at the same 255 for every
	 * symbology, so one clamp covers both.
	 *
	 * @var int
	 */
	public const MAX_DATA_BYTES = 255;

	/**
	 * ESC/POS `GS k` function-B symbology selector (the `m` parameter).
	 *
	 * @var array
	 */
	private const ESCPOS_IDS = array(
		'upca'    => 65,
		'upce'    => 66,
		'ean13'   => 67,
		'ean8'    => 68,
		'code39'  => 69,
		'itf'     => 70,
		'codabar' => 71,
		'code93'  => 72,
		'code128' => 73,
	);

	/**
	 * StarPRNT `ESC b n1` symbology selector.
	 *
	 * NOTE: the UPC pair is the inverse of the ESC/POS table above — Star numbers
	 * UPC-E before UPC-A. Do not transcribe one table from the other.
	 *
	 * @var array
	 */
	private const STARPRNT_IDS = array(
		'upce'    => 0,
		'upca'    => 1,
		'ean8'    => 2,
		'ean13'   => 3,
		'code39'  => 4,
		'itf'     => 5,
		'code128' => 6,
		'code93'  => 7,
		'codabar' => 8,
	);

	/**
	 * Attribute values for the ePOS-Print XML `<barcode type>` element.
	 *
	 * Only the UPC pair differs from the canonical spelling: ePOS-Print
	 * underscores them, and rejects the unseparated form outright.
	 *
	 * @var array
	 */
	private const EPOS_XML_NAMES = array(
		'upca' => 'upc_a',
		'upce' => 'upc_e',
	);

	/**
	 * Symbologies ePOS-Print accepts that WCPOS does not model itself.
	 *
	 * Source: Epson ePOS-Print XML reference, `<barcode>` element. These are
	 * accepted by the printer but absent from self::SYMBOLOGIES, so they are
	 * passed through untranslated instead of being folded to Code 128.
	 *
	 * @var array
	 */
	private const EPOS_XML_ONLY_TYPES = array(
		'jan13',
		'jan8',
		'code128_auto',
		'gs1_128',
		'gs1_databar_omnidirectional',
		'gs1_databar_truncated',
		'gs1_databar_limited',
		'gs1_databar_expanded',
	);

	/**
	 * Star Document Markup `[barcode: type ...]` names.
	 *
	 * Star markup is alone in calling Codabar "NW-7".
	 *
	 * @var array
	 */
	private const STAR_MARKUP_NAMES = array(
		'codabar' => 'nw7',
	);

	/**
	 * ESC/POS Code 128 code-set selector prefixed to the data.
	 *
	 * Function B requires the data to open with a code-set selector; without one
	 * the printer prints nothing and reports no error. Set B carries the full
	 * printable ASCII range, which is what receipt values use.
	 *
	 * @var string
	 */
	private const ESCPOS_CODE128_SELECTOR = '{B';

	/**
	 * Reduce a template's barcode type to a canonical name.
	 *
	 * @param string $type The barcode type attribute value.
	 *
	 * @return string A member of self::SYMBOLOGIES, or self::QRCODE.
	 */
	public static function normalize( string $type ): string {
		$normalized = strtolower( trim( $type ) );

		if ( 'qr' === $normalized ) {
			$normalized = self::QRCODE;
		}

		// Star markup's spelling of Codabar leaks back in through templates
		// written against a Star printer; accept it as an alias.
		if ( 'nw7' === $normalized ) {
			$normalized = 'codabar';
		}

		if ( self::QRCODE === $normalized ) {
			return self::QRCODE;
		}

		return \in_array( $normalized, self::SYMBOLOGIES, true ) ? $normalized : self::DEFAULT_SYMBOLOGY;
	}

	/**
	 * Determine whether a barcode type should be rendered as a QR code.
	 *
	 * @param string $type The barcode type attribute value.
	 *
	 * @return bool True when the type is a QR variant.
	 */
	public static function is_qr( string $type ): bool {
		return self::QRCODE === self::normalize( $type );
	}

	/**
	 * Determine whether a value satisfies a symbology's data constraints.
	 *
	 * A printer given data it cannot encode prints nothing and reports no error,
	 * so callers on the byte lanes check this first and print the value as text
	 * instead of handing the printer a symbol it will silently drop. The same
	 * rule governs the constraints below: where a value would print as a symbol
	 * that scans back as something other than the value asked for, it is
	 * rejected here, because a silently wrong barcode is worse than no barcode.
	 *
	 * The lane is required, not optional. Two symbologies differ between lanes:
	 * UPC-E (ESC/POS accepts the 6-8 digit short form, StarPRNT requires the
	 * full 11-12 digit payload) and Code 128 (the lanes escape and select code
	 * sets differently, so both the alphabet and the encoded length differ).
	 * Defaulting either to one vendor would dress that printer's rules up as a
	 * neutral answer.
	 *
	 * @param string $type  The barcode type attribute value.
	 * @param string $value The barcode value.
	 * @param string $lane  Lane discriminator: self::LANE_ESCPOS or self::LANE_STARPRNT.
	 *
	 * @return bool True when the value can be encoded.
	 */
	public static function is_valid_value( string $type, string $value, string $lane ): bool {
		$symbology = self::normalize_linear( $type );
		$length    = \strlen( $value );

		switch ( $symbology ) {
			case 'upca':
				if ( ! self::is_digits( $value ) ) {
					return false;
				}
				// 11 digits: the printer computes the check digit. 12: the last
				// digit is the check digit and must already be right.
				return 11 === $length || ( 12 === $length && self::has_valid_gtin_check_digit( $value ) );
			case 'upce':
				// ESC/POS accepts the 6-8 digit short form as well as the full
				// 11-12 digit form; StarPRNT accepts only the full form. The full
				// form is a UPC-A payload the printer compresses, so its check
				// digit is the UPC-A one. The short form's check digit is derived
				// from an expansion only the printer performs, so it is left to
				// the printer rather than half-checked here.
				if ( ! self::is_digits( $value ) ) {
					return false;
				}
				if ( 12 === $length ) {
					return self::has_valid_gtin_check_digit( $value );
				}
				if ( 11 === $length ) {
					return true;
				}

				return self::LANE_ESCPOS === $lane && $length >= 6 && $length <= 8;
			case 'ean13':
				if ( ! self::is_digits( $value ) ) {
					return false;
				}

				return 12 === $length || ( 13 === $length && self::has_valid_gtin_check_digit( $value ) );
			case 'ean8':
				if ( ! self::is_digits( $value ) ) {
					return false;
				}

				return 7 === $length || ( 8 === $length && self::has_valid_gtin_check_digit( $value ) );
			case 'code39':
				return self::is_valid_code39( $value );
			case 'itf':
				// Interleaved 2 of 5 encodes digits in pairs, so an odd-length
				// value cannot be encoded at all.
				return self::is_digits( $value ) && $length >= 2 && $length <= 254 && 0 === $length % 2;
			case 'codabar':
				// The start and stop characters travel in the data itself.
				return $length >= 2 && $length <= 255 && 1 === preg_match( '/\A[A-Da-d][0-9\$\+\-\.\/:]*[A-Da-d]\z/', $value );
			case 'code93':
				return $length >= 1 && $length <= 255 && self::is_ascii( $value )
					&& ( self::LANE_STARPRNT !== $lane || 0 === preg_match( '/[\x00-\x1f\x7f]/', $value ) );
			case 'code128':
			default:
				return self::is_valid_code128( $value, $lane );
		}
	}

	/**
	 * Map a symbology to its ESC/POS `GS k` function-B selector.
	 *
	 * @param string $type The barcode type attribute value.
	 *
	 * @return int The `m` parameter byte.
	 */
	public static function escpos_id( string $type ): int {
		return self::ESCPOS_IDS[ self::normalize_linear( $type ) ] ?? self::ESCPOS_IDS[ self::DEFAULT_SYMBOLOGY ];
	}

	/**
	 * Build the ESC/POS data bytes for a barcode value.
	 *
	 * Code 128 alone needs a code-set selector prefixed to the data, and needs a
	 * literal `{` doubled so it is not read as one. The prefix counts toward the
	 * transmitted length, so the clamp is applied after it is added.
	 *
	 * @param string $type  The barcode type attribute value.
	 * @param string $value The barcode value.
	 *
	 * @return string The data bytes to transmit.
	 */
	public static function escpos_payload( string $type, string $value ): string {
		if ( self::DEFAULT_SYMBOLOGY !== self::normalize_linear( $type ) ) {
			return substr( $value, 0, self::MAX_DATA_BYTES );
		}

		$payload = substr( self::escpos_code128_data( $value ), 0, self::MAX_DATA_BYTES );

		// Clamping can split an escaped `{{` pair; a lone trailing `{` would be
		// read as the start of a code-set selector, so drop it.
		$trailing_braces = \strlen( $payload ) - \strlen( rtrim( $payload, '{' ) );
		if ( 1 === $trailing_braces % 2 ) {
			$payload = substr( $payload, 0, -1 );
		}

		return $payload;
	}

	/**
	 * Map a symbology to its StarPRNT `ESC b n1` selector.
	 *
	 * @param string $type The barcode type attribute value.
	 *
	 * @return int The `n1` parameter byte.
	 */
	public static function starprnt_id( string $type ): int {
		return self::STARPRNT_IDS[ self::normalize_linear( $type ) ] ?? self::STARPRNT_IDS[ self::DEFAULT_SYMBOLOGY ];
	}

	/**
	 * Build the StarPRNT data bytes for a barcode value.
	 *
	 * StarPRNT escapes Code 128 with `%`, not `{`: `%0` is a literal `%`, and
	 * `%6`/`%7`/`%8` select code sets A/B/C. Omitting the start code is legal —
	 * the printer auto-selects — so, unlike ESC/POS, no selector is prefixed.
	 *
	 * @param string $type  The barcode type attribute value.
	 * @param string $value The barcode value.
	 *
	 * @return string The data bytes to transmit.
	 */
	public static function starprnt_payload( string $type, string $value ): string {
		if ( self::DEFAULT_SYMBOLOGY !== self::normalize_linear( $type ) ) {
			return substr( $value, 0, self::MAX_DATA_BYTES );
		}

		$payload = substr( self::starprnt_code128_data( $value ), 0, self::MAX_DATA_BYTES );

		// Clamping can split an escaped `%0` pair. Star reads `%` plus the byte
		// after it as an escape, and the next byte on the wire is the RS that
		// terminates the barcode — so a lone trailing `%` eats the terminator and
		// the printer keeps consuming the rest of the receipt as barcode data.
		// Unlike an unprintable symbol, that failure is not self-limiting.
		$trailing_percents = \strlen( $payload ) - \strlen( rtrim( $payload, '%' ) );
		if ( 1 === $trailing_percents % 2 ) {
			$payload = substr( $payload, 0, -1 );
		}

		return $payload;
	}

	/**
	 * Map a symbology to its Star Document Markup name.
	 *
	 * @param string $type The barcode type attribute value.
	 *
	 * @return string The `[barcode: type ...]` name.
	 */
	public static function star_markup_name( string $type ): string {
		$symbology = self::normalize_linear( $type );

		return self::STAR_MARKUP_NAMES[ $symbology ] ?? $symbology;
	}

	/**
	 * Map a symbology to its ePOS-Print XML `<barcode type>` value.
	 *
	 * @param string $type The barcode type attribute value.
	 *
	 * @return string The attribute value.
	 */
	public static function epos_xml_name( string $type ): string {
		$requested = strtolower( trim( $type ) );

		// ePOS-Print accepts symbologies WCPOS does not model, and the `type`
		// attribute is free-form, so a template may legitimately ask for one.
		// Folding those to Code 128 would silently downgrade a working GS1-128
		// to a scannable-but-wrong symbol, so anything ePOS itself accepts is
		// passed straight through. A name neither we nor ePOS recognise still
		// falls back to Code 128 rather than being handed to the printer, which
		// would reject the element and print nothing at all.
		if ( \in_array( $requested, self::EPOS_XML_ONLY_TYPES, true ) ) {
			return $requested;
		}

		$symbology = self::normalize_linear( $type );

		return self::EPOS_XML_NAMES[ $symbology ] ?? $symbology;
	}

	/**
	 * Map a symbology to its picqer generator constant.
	 *
	 * @param string $type The barcode type attribute value.
	 *
	 * @return string The picqer TYPE_* constant value.
	 */
	public static function picqer_type( string $type ): string {
		$map = array(
			'code128' => BarcodeGeneratorPNG::TYPE_CODE_128,
			'code39'  => BarcodeGeneratorPNG::TYPE_CODE_39,
			'code93'  => BarcodeGeneratorPNG::TYPE_CODE_93,
			'ean13'   => BarcodeGeneratorPNG::TYPE_EAN_13,
			'ean8'    => BarcodeGeneratorPNG::TYPE_EAN_8,
			'upca'    => BarcodeGeneratorPNG::TYPE_UPC_A,
			'upce'    => BarcodeGeneratorPNG::TYPE_UPC_E,
			'codabar' => BarcodeGeneratorPNG::TYPE_CODABAR,
			'itf'     => BarcodeGeneratorPNG::TYPE_INTERLEAVED_2_5,
		);

		return $map[ self::normalize_linear( $type ) ] ?? $map[ self::DEFAULT_SYMBOLOGY ];
	}

	/**
	 * Build the unclamped ESC/POS Code 128 data bytes for a value.
	 *
	 * Split out from escpos_payload() so is_valid_value() can measure the
	 * *encoded* length. The selector and the doubled braces both count toward
	 * the 255-byte limit, so a value that fits before escaping can overflow
	 * after it — and the clamp would then print a shortened barcode that scans
	 * cleanly as the wrong value.
	 *
	 * @param string $value The barcode value.
	 *
	 * @return string The encoded data, before any length clamp.
	 */
	private static function escpos_code128_data( string $value ): string {
		return self::ESCPOS_CODE128_SELECTOR . str_replace( '{', '{{', $value );
	}

	/**
	 * Build the unclamped StarPRNT Code 128 data bytes for a value.
	 *
	 * @param string $value The barcode value.
	 *
	 * @return string The encoded data, before any length clamp.
	 */
	private static function starprnt_code128_data( string $value ): string {
		return str_replace( '%', '%0', $value );
	}

	/**
	 * Whether a value can be encoded as Code 128 on a given lane.
	 *
	 * Two lane-specific constraints, both of which fail silently on the printer:
	 *
	 * - Alphabet. escpos_payload() always selects code set B, which encodes
	 *   ASCII 32-126 only; a tab, LF or CR needs set A, so an ESC/POS printer
	 *   drops the symbol. StarPRNT auto-selects its code set, but its barcode
	 *   data is RS-terminated, so control bytes cannot safely travel in it.
	 * - Length. The limit applies to the encoded bytes, not the merchant's
	 *   value: ESC/POS adds a two-byte selector and doubles every `{`, StarPRNT
	 *   doubles every `%`. A value that only overflows once escaped would be
	 *   clamped into a shorter barcode that still scans — as the wrong value —
	 *   so it is rejected here and printed as text instead.
	 *
	 * Epson's minimum of n >= 2 counts the `{B` selector, so a one-character
	 * value is legal on the wire (n = 3).
	 *
	 * @param string $value The barcode value.
	 * @param string $lane  Lane discriminator: self::LANE_ESCPOS or self::LANE_STARPRNT.
	 *
	 * @return bool True when the value can be encoded.
	 */
	private static function is_valid_code128( string $value, string $lane ): bool {
		if ( self::LANE_ESCPOS === $lane ) {
			if ( 1 !== preg_match( '/\A[\x20-\x7e]+\z/', $value ) ) {
				return false;
			}

			return \strlen( self::escpos_code128_data( $value ) ) <= self::MAX_DATA_BYTES;
		}

		if ( '' === $value || ! self::is_ascii( $value ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			return false;
		}

		return \strlen( self::starprnt_code128_data( $value ) ) <= self::MAX_DATA_BYTES;
	}

	/**
	 * Whether a value can be encoded as Code 39.
	 *
	 * `*` is the start/stop sentinel, not data. A printer that finds one in the
	 * middle of the value ends the symbol there, so `AB*CD` scans back as `AB`.
	 * A matching leading and trailing pair is accepted because that is how a
	 * value copied off another system's barcode is usually written; anything
	 * else is rejected and printed as text.
	 *
	 * @param string $value The barcode value.
	 *
	 * @return bool True when the value can be encoded.
	 */
	private static function is_valid_code39( string $value ): bool {
		$length = \strlen( $value );
		if ( $length < 1 || $length > 255 ) {
			return false;
		}

		$body = $value;
		if ( $length >= 2 && '*' === $value[0] && '*' === $value[ $length - 1 ] ) {
			$body = substr( $value, 1, -1 );
		}

		return 1 === preg_match( '/\A[0-9A-Z \$%\+\-\.\/]+\z/', $body );
	}

	/**
	 * Whether a GTIN's trailing digit is the correct mod-10 check digit.
	 *
	 * EAN-13, EAN-8 and UPC-A share one rule: weight the digits 3 and 1
	 * alternately from the one immediately left of the check digit, then the
	 * check digit is whatever brings the total to a multiple of ten. UPC-E's
	 * full 12-digit form is a UPC-A payload, so it uses the same rule.
	 *
	 * A wrong check digit is not a harmless typo. The printer either drops the
	 * symbol or prints one encoding a different number from the human-readable
	 * value beside it, and both outcomes are worse than the text fallback.
	 *
	 * @param string $digits An all-digit value whose last character is the check digit.
	 *
	 * @return bool True when the check digit is correct.
	 */
	private static function has_valid_gtin_check_digit( string $digits ): bool {
		$length = \strlen( $digits );
		if ( $length < 2 ) {
			return false;
		}

		$sum = 0;
		for ( $index = $length - 2; $index >= 0; $index-- ) {
			$weight = 0 === ( $length - 2 - $index ) % 2 ? 3 : 1;
			$sum   += (int) $digits[ $index ] * $weight;
		}

		return ( ( 10 - ( $sum % 10 ) ) % 10 ) === (int) $digits[ $length - 1 ];
	}

	/**
	 * Normalize to a 1D symbology, folding QR onto the default.
	 *
	 * The lane accessors are only ever reached for `barcode` AST nodes; a QR type
	 * arriving here means a caller routed a node wrongly, and Code 128 keeps the
	 * value printable rather than emitting an unencodable symbology id.
	 *
	 * @param string $type The barcode type attribute value.
	 *
	 * @return string A member of self::SYMBOLOGIES.
	 */
	private static function normalize_linear( string $type ): string {
		$symbology = self::normalize( $type );

		return self::QRCODE === $symbology ? self::DEFAULT_SYMBOLOGY : $symbology;
	}

	/**
	 * Whether a value is a non-empty run of ASCII digits.
	 *
	 * @param string $value The value to test.
	 *
	 * @return bool True when the value is all digits.
	 */
	private static function is_digits( string $value ): bool {
		return 1 === preg_match( '/\A[0-9]+\z/', $value );
	}

	/**
	 * Whether every byte of a value is in the 0-127 range printers accept.
	 *
	 * @param string $value The value to test.
	 *
	 * @return bool True when the value is 7-bit clean.
	 */
	private static function is_ascii( string $value ): bool {
		return 1 !== preg_match( '/[\x80-\xff]/', $value );
	}
}
