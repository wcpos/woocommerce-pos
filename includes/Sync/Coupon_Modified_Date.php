<?php
/**
 * Coupon post-date touch shared by the v1 and v2 write lanes.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * WC's coupon CPT data store only calls wp_update_post() when a POST field
 * (code, description, status, dates) actually changes. A meta-backed edit —
 * `amount`, `discount_type`, the usage limits — is written straight to postmeta,
 * so `post_modified(_gmt)` is left stale.
 *
 * That is not cosmetic. The client's catalogue replication is INCREMENTAL and
 * date-based: it polls `?modified_after=<cursor>&fields=id,date_modified_gmt`
 * (packages/query/src/{collection-replication-state,data-fetcher}.ts), and
 * `Catalog_Proxy_Controller` forwards that parameter untouched to wc/v3, where
 * WooCommerce filters on `post_modified_gmt`. A coupon whose amount changed but
 * whose post date did not move is therefore INVISIBLE to every other till until
 * something else happens to touch the post.
 *
 * v1 covered this by installing a `woocommerce_update_coupon` listener for the
 * duration of a v1 REST dispatch (see API/V1/Coupons_Controller). The v2 push
 * lane forwards to stock wc/v3 and installs no such listener, so the writer has
 * to apply the touch itself — hence this shared helper rather than a second
 * copy of the SQL.
 *
 * @see https://github.com/wcpos/woocommerce-pos-pro/issues/86
 */
class Coupon_Modified_Date {
	/**
	 * Advance a coupon's post_modified(_gmt) to now.
	 *
	 * Unconditional by design, exactly like the v1 listener: the caller only
	 * reaches this after a write it already knows succeeded, so "now" is the
	 * truthful modification time whether or not WC moved the date itself.
	 *
	 * @param int $coupon_id The coupon post ID.
	 */
	public static function touch( int $coupon_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => $coupon_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		clean_post_cache( $coupon_id );
	}
}
