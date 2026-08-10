<?php
/**
 * Clock seam for the coupon modified-date regression test.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * Return the frozen test timestamp when configured.
 *
 * @return int Unix timestamp.
 */
function time() {
	if ( isset( $GLOBALS['woocommerce_pos_coupon_modified_date_now_gmt'] ) ) {
		return strtotime( $GLOBALS['woocommerce_pos_coupon_modified_date_now_gmt'] . ' UTC' );
	}

	return \time();
}

/**
 * Return the frozen test time when configured.
 *
 * @param string $type Type of time to retrieve.
 * @param bool   $gmt  Whether to use GMT.
 *
 * @return int|string
 */
function current_time( $type, $gmt = false ) {
	if ( $gmt && isset( $GLOBALS['woocommerce_pos_coupon_modified_date_now_gmt'] ) ) {
		$frozen_gmt = $GLOBALS['woocommerce_pos_coupon_modified_date_now_gmt'];

		return 'timestamp' === $type ? strtotime( $frozen_gmt . ' UTC' ) : $frozen_gmt;
	}

	return \current_time( $type, $gmt );
}
