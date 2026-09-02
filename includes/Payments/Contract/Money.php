<?php
/** Integer minor-unit money helpers. */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/** Keeps ledger arithmetic out of floating-point comparisons. */
final class Money {
	/** Parse a major-unit value into integer minor units. */
	public static function minor( $value ): int {
		$factor = 10 ** wc_get_price_decimals();
		return (int) round( (float) $value * $factor );
	}

	/** Format integer minor units for the wire. */
	public static function format( int $minor ): string {
		$decimals = wc_get_price_decimals();
		$factor   = 10 ** $decimals;
		return number_format( $minor / $factor, $decimals, '.', '' );
	}

	/** Normalize a major-unit value for the wire. */
	public static function normalize( $value ): string {
		$value = wc_format_decimal( $value, wc_get_price_decimals() );
		return self::format( self::minor( $value ) );
	}
}
