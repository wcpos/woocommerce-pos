<?php
/**
 * Tests for shared ESC/POS QR command bytes.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Tests\Templates\Thermal;

use WCPOS\WooCommercePOS\Templates\Thermal\Escpos_Qr;
use WCPOS\WooCommercePOS\Templates\Thermal\Thermal_Bounds;
use WP_UnitTestCase;

/**
 * Test_Escpos_Qr class.
 */
class Test_Escpos_Qr extends WP_UnitTestCase {

	/**
	 * The complete model-2 command sequence matches the fixed wire contract.
	 */
	public function test_bytes_fixed_payload_matches_literal_sequence(): void {
		$expected = "\x1d\x28\x6b\x04\x00\x31\x41\x32\x00"
			. "\x1d\x28\x6b\x03\x00\x31\x43\x04"
			. "\x1d\x28\x6b\x03\x00\x31\x45\x31"
			. "\x1d\x28\x6b\x06\x00\x31\x50\x30ABC"
			. "\x1d\x28\x6b\x03\x00\x31\x51\x30";

		$this->assertSame( $expected, Escpos_Qr::bytes( 'ABC', 4 ) );
	}

	/**
	 * Module sizes outside the supported range clamp at both bounds.
	 */
	public function test_bytes_out_of_range_sizes_are_clamped(): void {
		$below = Escpos_Qr::bytes( 'ABC', Thermal_Bounds::QRCODE_SIZE_MIN - 1 );
		$above = Escpos_Qr::bytes( 'ABC', Thermal_Bounds::QRCODE_SIZE_MAX + 1 );

		$this->assertSame( Thermal_Bounds::QRCODE_SIZE_MIN, ord( $below[16] ) );
		$this->assertSame( Thermal_Bounds::QRCODE_SIZE_MAX, ord( $above[16] ) );
	}

	/**
	 * Oversized data is capped before calculating the two-byte store length.
	 */
	public function test_bytes_oversized_payload_caps_store_length(): void {
		$bytes = Escpos_Qr::bytes( str_repeat( 'A', 70000 ), 4 );

		$this->assertSame( "\x1d\x28\x6b\xff\xff\x31\x50\x30", substr( $bytes, 25, 8 ) );
		$this->assertSame( str_repeat( 'A', 65532 ), substr( $bytes, 33, -8 ) );
		$this->assertSame( 65573, strlen( $bytes ) );
		$this->assertStringEndsWith( "\x1d\x28\x6b\x03\x00\x31\x51\x30", $bytes );
	}
}
