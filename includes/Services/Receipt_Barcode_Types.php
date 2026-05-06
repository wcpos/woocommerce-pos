<?php
/**
 * Receipt barcode type normalization.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Receipt_Barcode_Types class.
 */
final class Receipt_Barcode_Types {
	/**
	 * Default order barcode symbology.
	 */
	public const DEFAULT_ORDER_BARCODE_TYPE = 'code128';

	/**
	 * Supported order barcode symbologies.
	 *
	 * @var array<int,string>
	 */
	private const ORDER_BARCODE_TYPES = array( 'code128', 'qrcode', 'ean13', 'ean8', 'upca' );

	/**
	 * Normalize an order barcode type for receipt renderers.
	 *
	 * @param string $value Raw barcode type.
	 *
	 * @return string
	 */
	public static function normalize_order_barcode_type( string $value ): string {
		$value = strtolower( trim( $value ) );

		return \in_array( $value, self::ORDER_BARCODE_TYPES, true ) ? $value : self::DEFAULT_ORDER_BARCODE_TYPE;
	}
}
