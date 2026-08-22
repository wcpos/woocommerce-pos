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
	 * ePOS-Print XML `<barcode type>` attribute values.
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
	 * instead of handing the printer a symbol it will silently drop.
	 *
	 * @param string $type  The barcode type attribute value.
	 * @param string $value The barcode value.
	 * @param string $lane  Optional lane discriminator; see the UPC-E note below.
	 *
	 * @return bool True when the value can be encoded.
	 */
	public static function is_valid_value( string $type, string $value, string $lane = '' ): bool {
		$symbology = self::normalize_linear( $type );
		$length    = \strlen( $value );

		switch ( $symbology ) {
			case 'upca':
				return self::is_digits( $value ) && ( 11 === $length || 12 === $length );
			case 'upce':
				// ESC/POS accepts the 6-8 digit short form as well as the full
				// 11-12 digit form; StarPRNT accepts only the full form.
				if ( ! self::is_digits( $value ) ) {
					return false;
				}
				if ( self::LANE_STARPRNT === $lane ) {
					return 11 === $length || 12 === $length;
				}

				return ( $length >= 6 && $length <= 8 ) || 11 === $length || 12 === $length;
			case 'ean13':
				return self::is_digits( $value ) && ( 12 === $length || 13 === $length );
			case 'ean8':
				return self::is_digits( $value ) && ( 7 === $length || 8 === $length );
			case 'code39':
				return $length >= 1 && $length <= 255 && 1 === preg_match( '/\A[0-9A-Z \$%\*\+\-\.\/]+\z/', $value );
			case 'itf':
				// Interleaved 2 of 5 encodes digits in pairs, so an odd-length
				// value cannot be encoded at all.
				return self::is_digits( $value ) && $length >= 2 && $length <= 254 && 0 === $length % 2;
			case 'codabar':
				// The start and stop characters travel in the data itself.
				return $length >= 2 && $length <= 255 && 1 === preg_match( '/\A[A-Da-d][0-9\$\+\-\.\/:]*[A-Da-d]\z/', $value );
			case 'code93':
				return $length >= 1 && $length <= 255 && self::is_ascii( $value );
			case 'code128':
			default:
				return $length >= 2 && $length <= 255 && self::is_ascii( $value );
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

		$payload = self::ESCPOS_CODE128_SELECTOR . str_replace( '{', '{{', $value );
		$payload = substr( $payload, 0, self::MAX_DATA_BYTES );

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

		return substr( str_replace( '%', '%0', $value ), 0, self::MAX_DATA_BYTES );
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
