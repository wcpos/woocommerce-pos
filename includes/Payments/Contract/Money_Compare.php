<?php
/**
 * Decimal-string comparisons shared by settlement intent and money ownership.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

// phpcs:disable Squiz.Commenting -- Compact decimal comparison helpers.
namespace WCPOS\WooCommercePOS\Payments\Contract;

final class Money_Compare {
	public static function equal( $left, $right ): bool {
		$left = self::micro( is_int( $left ) || is_float( $left ) ? (string) $left : $left );
		$right = self::micro( is_int( $right ) || is_float( $right ) ? (string) $right : $right );
		return null !== $left && null !== $right && $left === $right;
	}

	/** Integer micro-units as a string: no float conversion, including rounding and large values. */
	public static function micro( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^([+-]?)(\d*)(?:\.(\d*))?$/D', $value, $m ) || '' === ( $m[2] . ( $m[3] ?? '' ) ) ) {
			return null;
		}
		$digits = ltrim( $m[2] . substr( str_pad( $m[3] ?? '', 7, '0' ), 0, 6 ), '0' );
		$digits = '' === $digits ? '0' : $digits;
		if ( (int) ( $m[3][6] ?? '0' ) >= 5 ) {
			$digits = self::increment( $digits );
		}
		return '-' === $m[1] && '0' !== $digits ? '-' . $digits : $digits;
	}

	private static function increment( string $digits ): string {
		for ( $i = strlen( $digits ) - 1; $i >= 0; --$i ) {
			if ( '9' !== $digits[ $i ] ) {
				$digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );
				return $digits;
			}
			$digits[ $i ] = '0';
		}
		return '1' . $digits;
	}

	public static function near( string $left, string $right ): bool {
		if ( $left === $right ) {
			return true;
		}
		if ( ( '-' === $left[0] ) !== ( '-' === $right[0] ) ) {
			return ( '0' === $left && '-1' === $right ) || ( '-1' === $left && '0' === $right );
		}
		$left = ltrim( $left, '-' );
		$right = ltrim( $right, '-' );
		return self::increment( $left ) === $right || self::increment( $right ) === $left;
	}
}
