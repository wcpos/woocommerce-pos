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
	 * Listen for every coupon save, whatever wrote it.
	 *
	 * Registered UNCONDITIONALLY from Init — deliberately not behind the schema
	 * latch, because this touches `wp_posts` only and the client's replication is
	 * date-based on BOTH lanes. A cashier does not care which surface a discount
	 * was edited from: a merchant changing a coupon amount in wp-admin, over
	 * WP-CLI, or from another plugin has changed the coupon, and every till must
	 * see it on its next incremental poll. v1 only ever installed this for the
	 * duration of its own REST dispatch, so an admin-side edit was invisible to
	 * the POS — that was a gap, not a design.
	 *
	 * `touch()` writes with $wpdb->update() rather than wp_update_post(), so it
	 * cannot re-enter `woocommerce_update_coupon` — no recursion guard needed.
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_update_coupon', array( __CLASS__, 'touch' ), 10, 1 );
	}

	/**
	 * Advance a coupon's post_modified(_gmt) beyond its previous value.
	 *
	 * Unconditional by design, exactly like the v1 listener: the caller only
	 * reaches this after a write it already knows succeeded. The stored value
	 * must advance by at least one second because incremental queries use a
	 * strict `modified_after` comparison and WordPress timestamps have
	 * one-second precision.
	 *
	 * @param int $coupon_id The coupon post ID.
	 */
	public static function touch( int $coupon_id ): void {
		global $wpdb;

		$previous_gmt = (string) get_post_field( 'post_modified_gmt', $coupon_id );
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
			array( 'ID' => $coupon_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		clean_post_cache( $coupon_id );
	}
}
