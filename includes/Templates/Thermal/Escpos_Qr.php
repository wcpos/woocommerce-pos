<?php
/**
 * Shared ESC/POS QR command builder.
 *
 * @package WCPOS\WooCommercePOS\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

/**
 * Build native model-2 QR commands without modifying emitter state.
 */
final class Escpos_Qr {

	/**
	 * Return the complete model-2 QR command sequence.
	 *
	 * @param string $value The QR payload.
	 * @param int    $size  The module size in dots.
	 *
	 * @return string Raw ESC/POS bytes.
	 */
	public static function bytes( string $value, int $size ): string {
		$size    = max( Thermal_Bounds::QRCODE_SIZE_MIN, min( Thermal_Bounds::QRCODE_SIZE_MAX, $size ) );
		$data    = substr( $value, 0, 0xffff - 3 );
		$payload = \strlen( $data ) + 3;
		$p_l     = $payload & 0xff;
		$p_h     = ( $payload >> 8 ) & 0xff;

		// Select model 2, module size, and error correction level M.
		$bytes = "\x1d\x28\x6b\x04\x00\x31\x41\x32\x00"
			. "\x1d\x28\x6b\x03\x00\x31\x43" . \chr( $size )
			. "\x1d\x28\x6b\x03\x00\x31\x45\x31";

		// Store data, then print the stored symbol.
		return $bytes
			. "\x1d\x28\x6b" . \chr( $p_l ) . \chr( $p_h ) . "\x31\x50\x30" . $data
			. "\x1d\x28\x6b\x03\x00\x31\x51\x30";
	}
}
