<?php
/**
 * Clock seam for the payment contract: a test can make every "now" a second later than the last.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

/**
 * Format the next tick of the ticking test clock when one is set, else the real clock.
 *
 * Each call without a timestamp reads one second later than the last, so two normalizations
 * of one row always straddle a second (D14), never only when the real clock happens to tick.
 *
 * @param string   $format    Date format.
 * @param int|null $timestamp Unix timestamp; a given one is formatted as is.
 *
 * @return string
 */
function gmdate( $format, $timestamp = null ) {
	if ( null === $timestamp && isset( $GLOBALS['woocommerce_pos_ledger_clock'] ) ) {
		$timestamp = $GLOBALS['woocommerce_pos_ledger_clock']++;
	}

	return null === $timestamp ? \gmdate( $format ) : \gmdate( $format, $timestamp );
}
