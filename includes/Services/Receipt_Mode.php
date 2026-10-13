<?php
/**
 * Receipt mode selection.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Resolve a request override or the configured receipt mode. */
final class Receipt_Mode {
	/**
	 * Prefer a valid requested mode, then the setting, then fiscal.
	 *
	 * @param string|null $requested Requested mode.
	 */
	public static function resolve( ?string $requested = null ): string {
		if ( in_array( $requested, array( 'fiscal', 'live' ), true ) ) {
			return $requested;
		}
		$mode = Settings::instance()->receipt_default_mode();
		return in_array( $mode, array( 'fiscal', 'live' ), true ) ? $mode : 'fiscal';
	}
}
