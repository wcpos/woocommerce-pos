<?php
/**
 * Tests for the barcode symbology owner.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use WCPOS\WooCommercePOS\Templates\Barcode_Symbology;
use WP_UnitTestCase;

/**
 * Barcode_Symbology_Test class.
 */
class Barcode_Symbology_Test extends WP_UnitTestCase {

	/**
	 * Every symbology, spelled out for every render lane.
	 *
	 * This is the artefact that makes five maps one map: a lane that regresses
	 * its own table fails here, not silently on a printer. The values are taken
	 * from the vendor references cited in Barcode_Symbology.
	 *
	 * @return array Provider rows.
	 */
	public function symbology_matrix_provider(): array {
		return array(
			// Symbology  => escpos id, starprnt id, epos-xml name, star markup name, picqer type.
			'code128' => array( 'code128', 73, 6, 'code128', 'code128', 'C128' ),
			'code39'  => array( 'code39', 69, 4, 'code39', 'code39', 'C39' ),
			'code93'  => array( 'code93', 72, 7, 'code93', 'code93', 'C93' ),
			'ean13'   => array( 'ean13', 67, 3, 'ean13', 'ean13', 'EAN13' ),
			'ean8'    => array( 'ean8', 68, 2, 'ean8', 'ean8', 'EAN8' ),
			'upca'    => array( 'upca', 65, 1, 'upc_a', 'upca', 'UPCA' ),
			'upce'    => array( 'upce', 66, 0, 'upc_e', 'upce', 'UPCE' ),
			'codabar' => array( 'codabar', 71, 8, 'codabar', 'nw7', 'CODABAR' ),
			'itf'     => array( 'itf', 70, 5, 'itf', 'itf', 'I25' ),
		);
	}

	/**
	 * Each symbology resolves to the documented value on every lane.
	 *
	 * @dataProvider symbology_matrix_provider
	 *
	 * @param string $type             The canonical symbology name.
	 * @param int    $escpos_id        The ESC/POS GS k function-B selector.
	 * @param int    $starprnt_id      The StarPRNT ESC b n1 selector.
	 * @param string $epos_xml_name    The ePOS-Print XML type attribute value.
	 * @param string $star_markup_name The Star Document Markup type name.
	 * @param string $picqer_type      The picqer generator constant value.
	 *
	 * @return void
	 */
	public function test_symbology_matrix_resolves_every_lane(
		string $type,
		int $escpos_id,
		int $starprnt_id,
		string $epos_xml_name,
		string $star_markup_name,
		string $picqer_type
	): void {
		// Arrange / Act / Assert.
		$this->assertSame( $type, Barcode_Symbology::normalize( $type ) );
		$this->assertSame( $escpos_id, Barcode_Symbology::escpos_id( $type ) );
		$this->assertSame( $starprnt_id, Barcode_Symbology::starprnt_id( $type ) );
		$this->assertSame( $epos_xml_name, Barcode_Symbology::epos_xml_name( $type ) );
		$this->assertSame( $star_markup_name, Barcode_Symbology::star_markup_name( $type ) );
		$this->assertSame( $picqer_type, Barcode_Symbology::picqer_type( $type ) );
	}

	/**
	 * The provider covers exactly the declared symbology list.
	 *
	 * @return void
	 */
	public function test_symbology_matrix_covers_every_declared_symbology(): void {
		// Arrange.
		$covered = array_keys( $this->symbology_matrix_provider() );

		// Act.
		sort( $covered );
		$declared = Barcode_Symbology::SYMBOLOGIES;
		sort( $declared );

		// Assert.
		$this->assertSame( $declared, $covered );
	}

	/**
	 * The UPC pair is numbered in opposite order by the two byte lanes.
	 *
	 * StarPRNT numbers UPC-E before UPC-A; ESC/POS is the other way round. A
	 * table transcribed from the wrong vendor inverts one of these.
	 *
	 * @return void
	 */
	public function test_upc_pair_is_ordered_oppositely_on_the_two_byte_lanes(): void {
		// Arrange / Act / Assert.
		$this->assertTrue( Barcode_Symbology::escpos_id( 'upca' ) < Barcode_Symbology::escpos_id( 'upce' ) );
		$this->assertTrue( Barcode_Symbology::starprnt_id( 'upce' ) < Barcode_Symbology::starprnt_id( 'upca' ) );
	}

	/**
	 * Type names are trimmed, lowercased, and aliased before lookup.
	 *
	 * @return void
	 */
	public function test_normalize_trims_lowercases_and_resolves_aliases(): void {
		// Arrange / Act / Assert.
		$this->assertSame( 'ean13', Barcode_Symbology::normalize( '  EAN13 ' ) );
		$this->assertSame( 'qrcode', Barcode_Symbology::normalize( 'QR' ) );
		$this->assertSame( 'codabar', Barcode_Symbology::normalize( 'NW7' ) );
	}

	/**
	 * An unsupported type name falls back to Code 128 on every lane.
	 *
	 * @return void
	 */
	public function test_unknown_type_falls_back_to_code128_on_every_lane(): void {
		// Arrange.
		$type = 'not-a-symbology';

		// Act / Assert.
		$this->assertSame( 'code128', Barcode_Symbology::normalize( $type ) );
		$this->assertSame( 73, Barcode_Symbology::escpos_id( $type ) );
		$this->assertSame( 6, Barcode_Symbology::starprnt_id( $type ) );
		$this->assertSame( 'code128', Barcode_Symbology::epos_xml_name( $type ) );
		$this->assertSame( 'code128', Barcode_Symbology::star_markup_name( $type ) );
		$this->assertSame( 'C128', Barcode_Symbology::picqer_type( $type ) );
	}

	/**
	 * QR variants are recognised, and nothing else is.
	 *
	 * @return void
	 */
	public function test_is_qr_recognises_both_spellings_and_no_others(): void {
		// Arrange / Act / Assert.
		$this->assertTrue( Barcode_Symbology::is_qr( 'qr' ) );
		$this->assertTrue( Barcode_Symbology::is_qr( 'qrcode' ) );
		$this->assertTrue( Barcode_Symbology::is_qr( ' QRCode ' ) );
		$this->assertFalse( Barcode_Symbology::is_qr( 'code128' ) );
		$this->assertFalse( Barcode_Symbology::is_qr( 'not-a-symbology' ) );

		foreach ( Barcode_Symbology::SYMBOLOGIES as $symbology ) {
			$this->assertFalse( Barcode_Symbology::is_qr( $symbology ), $symbology );
		}
	}

	/**
	 * ITF rejects an odd-length value, which cannot be encoded in digit pairs.
	 *
	 * @return void
	 */
	public function test_is_valid_value_rejects_odd_length_itf(): void {
		// Arrange / Act / Assert.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'itf', '1234', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'itf', '12345', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'itf', '12A4', Barcode_Symbology::LANE_ESCPOS ) );
	}

	/**
	 * Fixed-length symbologies reject values of the wrong length or alphabet.
	 *
	 * @return void
	 */
	public function test_is_valid_value_enforces_the_fixed_length_symbologies(): void {
		// Arrange / Act / Assert.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'ean13', '4006381333931', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'ean13', '40063813339311', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'ean13', 'NOT-A-NUMBER', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'ean8', '96385074', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'ean8', '963850', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'upca', '12345678901', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'upca', '1234567890', Barcode_Symbology::LANE_ESCPOS ) );
	}

	/**
	 * StarPRNT rejects the short UPC-E form that ESC/POS accepts.
	 *
	 * @return void
	 */
	public function test_is_valid_value_rejects_short_upce_on_the_starprnt_lane_only(): void {
		// Arrange.
		$short = '123456';

		// Act / Assert.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'upce', $short, Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'upce', $short, Barcode_Symbology::LANE_STARPRNT ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'upce', '01234500006', Barcode_Symbology::LANE_STARPRNT ) );
	}

	/**
	 * Code 39 and Codabar accept only their own alphabets.
	 *
	 * @return void
	 */
	public function test_is_valid_value_enforces_the_restricted_alphabets(): void {
		// Arrange / Act / Assert.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'code39', 'ABC-123 $/+%', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code39', 'abc', Barcode_Symbology::LANE_ESCPOS ) );
		// Codabar carries its start/stop characters in the data itself.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'codabar', 'A12345B', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'codabar', '12345', Barcode_Symbology::LANE_ESCPOS ) );
	}

	/**
	 * ESC/POS Code 128 data opens with the code-set selector and doubles braces.
	 *
	 * @return void
	 */
	public function test_escpos_payload_prefixes_the_selector_for_code128_only(): void {
		// Arrange / Act / Assert.
		$this->assertSame( '{BABC-123', Barcode_Symbology::escpos_payload( 'code128', 'ABC-123' ) );
		$this->assertSame( '{BA{{B', Barcode_Symbology::escpos_payload( 'code128', 'A{B' ) );
		$this->assertSame( '4006381333931', Barcode_Symbology::escpos_payload( 'ean13', '4006381333931' ) );
	}

	/**
	 * StarPRNT Code 128 data escapes percent and carries no start code.
	 *
	 * Star's escape character is "%", not ESC/POS's "{", and omitting the start
	 * code is legal — the printer auto-selects. Prefixing "{B" here would print
	 * the selector as data.
	 *
	 * @return void
	 */
	public function test_starprnt_payload_escapes_percent_and_adds_no_start_code(): void {
		// Arrange / Act / Assert.
		$this->assertSame( 'ABC-123', Barcode_Symbology::starprnt_payload( 'code128', 'ABC-123' ) );
		$this->assertSame( 'A%0B', Barcode_Symbology::starprnt_payload( 'code128', 'A%B' ) );
		$this->assertSame( 'A{B', Barcode_Symbology::starprnt_payload( 'code128', 'A{B' ) );
		$this->assertSame( '4006381333931', Barcode_Symbology::starprnt_payload( 'ean13', '4006381333931' ) );
	}

	/**
	 * Both byte lanes clamp the data to the transmittable length.
	 *
	 * @return void
	 */
	public function test_payloads_are_clamped_to_the_maximum_data_length(): void {
		// Arrange.
		$long = str_repeat( '1', 300 );

		// Act / Assert.
		$this->assertSame( Barcode_Symbology::MAX_DATA_BYTES, \strlen( Barcode_Symbology::escpos_payload( 'code128', $long ) ) );
		$this->assertSame( Barcode_Symbology::MAX_DATA_BYTES, \strlen( Barcode_Symbology::starprnt_payload( 'code128', $long ) ) );
	}

	/**
	 * Clamping a Code 128 payload can split an escaped `%0` pair. Star reads a
	 * `%` plus the following byte as an escape, and the byte that follows on the
	 * wire is the RS terminator — so a lone trailing `%` swallows the terminator
	 * and the printer consumes the rest of the receipt as barcode data.
	 *
	 * @return void
	 */
	public function test_starprnt_payload_never_ends_in_a_lone_escape_character(): void {
		// Arrange.
		$value = str_repeat( 'A', 254 ) . '%';

		// Act.
		$payload = Barcode_Symbology::starprnt_payload( 'code128', $value );

		// Assert.
		$trailing = \strlen( $payload ) - \strlen( rtrim( $payload, '%' ) );
		$this->assertSame( 0, $trailing % 2 );
	}

	/**
	 * Epson's minimum of two bytes for Code 128 counts the `{B` selector that
	 * escpos_payload() always prepends, so a one-character value is legal.
	 *
	 * @return void
	 */
	public function test_is_valid_value_accepts_a_single_character_code128_value(): void {
		// Arrange / Act / Assert.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'code128', '7', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code128', '', Barcode_Symbology::LANE_ESCPOS ) );
	}

	/**
	 * Code 128 is measured on the encoded bytes, not the merchant's value.
	 *
	 * ESC/POS spends two bytes on the `{B` selector before the value starts, so
	 * 254 characters no longer fit even though the value itself is under the
	 * 255-byte limit. Accepting it would clamp the payload and print a shorter
	 * barcode that still scans — as a different value.
	 *
	 * @return void
	 */
	public function test_is_valid_value_rejects_a_code128_value_that_overflows_the_escpos_selector(): void {
		// Arrange.
		$fits      = str_repeat( 'A', 253 );
		$overflows = str_repeat( 'A', 254 );

		// Act / Assert.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'code128', $fits, Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertSame( Barcode_Symbology::MAX_DATA_BYTES, \strlen( Barcode_Symbology::escpos_payload( 'code128', $fits ) ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code128', $overflows, Barcode_Symbology::LANE_ESCPOS ) );
	}

	/**
	 * Escaping expansion counts toward the Code 128 limit on both lanes.
	 *
	 * Every `{` doubles on ESC/POS and every `%` doubles on StarPRNT, so a value
	 * that fits before escaping can overflow after it.
	 *
	 * @return void
	 */
	public function test_is_valid_value_rejects_a_code128_value_that_overflows_once_escaped(): void {
		// Arrange. Both are well inside 255 characters before escaping.
		$braces   = str_repeat( '{', 200 );
		$percents = str_repeat( '%', 200 );

		// Act / Assert.
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code128', $braces, Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code128', $percents, Barcode_Symbology::LANE_STARPRNT ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'code128', str_repeat( '%', 127 ), Barcode_Symbology::LANE_STARPRNT ) );
	}

	/**
	 * A wrong check digit is rejected on the full-length GTIN forms.
	 *
	 * The shorter forms carry no check digit — the printer computes one — so
	 * they stay valid.
	 *
	 * @return void
	 */
	public function test_is_valid_value_rejects_a_wrong_gtin_check_digit(): void {
		// Arrange / Act / Assert.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'ean13', '4006381333931', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'ean13', '4006381333932', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'ean13', '400638133393', Barcode_Symbology::LANE_ESCPOS ) );

		$this->assertTrue( Barcode_Symbology::is_valid_value( 'ean8', '96385074', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'ean8', '96385075', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'ean8', '9638507', Barcode_Symbology::LANE_ESCPOS ) );

		$this->assertTrue( Barcode_Symbology::is_valid_value( 'upca', '036000291452', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'upca', '036000291453', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'upca', '12345678901', Barcode_Symbology::LANE_ESCPOS ) );

		// UPC-E's full form is a UPC-A payload, so it uses the UPC-A check digit.
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'upce', '036000291452', Barcode_Symbology::LANE_STARPRNT ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'upce', '036000291453', Barcode_Symbology::LANE_STARPRNT ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'upce', '01234500006', Barcode_Symbology::LANE_STARPRNT ) );
	}

	/**
	 * ESC/POS Code 128 accepts only what code set B can encode.
	 *
	 * The ESC/POS payload builder always selects set B, which covers printable
	 * ASCII only. StarPRNT auto-selects its code set, so it keeps the wider
	 * 7-bit range.
	 *
	 * @return void
	 */
	public function test_is_valid_value_rejects_code_set_b_unencodable_bytes_on_the_escpos_lane(): void {
		// Arrange.
		$with_line_feed = "ABC\n123";

		// Act / Assert.
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code128', $with_line_feed, Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code128', "ABC\t123", Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code128', "ABC\r123", Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'code128', 'ABC-123', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'code128', $with_line_feed, Barcode_Symbology::LANE_STARPRNT ) );
	}

	/**
	 * Code 39 treats `*` as the start/stop sentinel, not as data.
	 *
	 * A printer ends the symbol at an interior `*`, so `AB*CD` scans back as
	 * `AB`. A matching leading and trailing pair is how a value copied off
	 * another system is often written, so that form is still accepted.
	 *
	 * @return void
	 */
	public function test_is_valid_value_rejects_a_code39_asterisk_outside_the_sentinel_pair(): void {
		// Arrange / Act / Assert.
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code39', 'AB*CD', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code39', '*ABC', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code39', 'ABC*', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code39', '*', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertFalse( Barcode_Symbology::is_valid_value( 'code39', '**', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'code39', '*ABC*', Barcode_Symbology::LANE_ESCPOS ) );
		$this->assertTrue( Barcode_Symbology::is_valid_value( 'code39', 'ABC-123', Barcode_Symbology::LANE_ESCPOS ) );
	}
}
