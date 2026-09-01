<?php
/**
 * Order post-date touch for meta-only CPT saves.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Advance stale CPT order post dates after meta-only saves.
 * HPOS already advances its own date_updated_gmt on persistence.
 */
class Order_Modified_Date {
	/**
	 * Advance an order's post_modified(_gmt) beyond its previous value.
	 *
	 * @param int $order_id The order post ID.
	 */
	public static function touch( int $order_id ): void {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return;
		}
		global $wpdb;

		$previous_gmt = (string) get_post_field( 'post_modified_gmt', $order_id );
		$modified_gmt = gmdate(
			'Y-m-d H:i:s',
			max( time(), strtotime( $previous_gmt . ' UTC' ) + 1 )
		);

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => get_date_from_gmt( $modified_gmt ),
				'post_modified_gmt' => $modified_gmt,
			),
			array( 'ID' => $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		clean_post_cache( $order_id );
	}
}
