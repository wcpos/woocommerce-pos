<?php
/**
 * Order lookups through the ledger's index meta.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/**
 * Datastore-aware direct meta queries over `_wcpos_payment_id`.
 *
 * `wc_get_orders()` with a `meta_query` is NOT supported on the CPT order datastore —
 * it fires a `doing_it_wrong` and returns unfiltered results — so, like
 * `Sync\Pos_Uuid::get_order_ids_by_uuid()`, the two lookups here read the meta table
 * directly: `wc_orders_meta` under HPOS, `wp_postmeta` otherwise.
 */
final class Payment_Index {
	/**
	 * Find the order holding a payment row, by the row's UUID.
	 *
	 * Trashed orders are still found: the money behind a settling row moved whether or
	 * not somebody binned the order since, and the row is where that fact is recorded.
	 *
	 * @param string $payment_id Payment UUID (lowercase).
	 *
	 * @return int Order id, or 0 when no order holds the row.
	 */
	public static function order_id_for_payment( string $payment_id ): int {
		global $wpdb;
		if ( self::hpos() ) {
			$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Datastore-aware index lookup; see the class comment.
				$wpdb->prepare(
					"SELECT m.order_id FROM {$wpdb->prefix}wc_orders_meta m"
					. " JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id AND o.type = 'shop_order'"
					. ' WHERE m.meta_key = %s AND m.meta_value = %s'
					. " AND o.status <> 'auto-draft'"
					. ' LIMIT 1',
					Ledger::PAYMENT_ID_META_KEY,
					$payment_id
				)
			);
		} else {
			$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Datastore-aware index lookup; see the class comment.
				$wpdb->prepare(
					"SELECT m.post_id FROM {$wpdb->postmeta} m"
					. " JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'shop_order'"
					. ' WHERE m.meta_key = %s AND m.meta_value = %s'
					. " AND p.post_status <> 'auto-draft'"
					. ' LIMIT 1',
					Ledger::PAYMENT_ID_META_KEY,
					$payment_id
				)
			);
		}

		return (int) $id;
	}

	/**
	 * Orders holding at least one pending or authorized leg, oldest-modified first.
	 *
	 * No status filter on purpose: an authorization that covers the balance completes
	 * the order through payment_complete(), and that is exactly the leg a sweep must
	 * still reach when it expires uncaptured. The live-leg index drops off the order
	 * the moment every leg settles, so the candidate set is only ever orders with
	 * something to reconcile — never the abandoned-cart mass.
	 *
	 * @param int $limit Batch cap.
	 *
	 * @return int[] Order ids.
	 */
	public static function order_ids_with_live_legs( int $limit ): array {
		global $wpdb;
		if ( $limit < 1 ) {
			return array();
		}
		if ( self::hpos() ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Datastore-aware index lookup; see the class comment.
				$wpdb->prepare(
					"SELECT o.id FROM {$wpdb->prefix}wc_orders o"
					. " WHERE o.type = 'shop_order' AND o.status NOT IN ( 'trash', 'auto-draft' )"
					. " AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}wc_orders_meta m WHERE m.order_id = o.id AND m.meta_key = %s )"
					. ' ORDER BY o.date_updated_gmt ASC LIMIT %d',
					Ledger::LIVE_LEG_META_KEY,
					$limit
				)
			);
		} else {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Datastore-aware index lookup; see the class comment.
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p"
					. " WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ( 'trash', 'auto-draft' )"
					. " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s )"
					. ' ORDER BY p.post_modified_gmt ASC LIMIT %d',
					Ledger::LIVE_LEG_META_KEY,
					$limit
				)
			);
		}

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/** Whether orders live in the HPOS tables. */
	private static function hpos(): bool {
		$order_util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		return class_exists( $order_util )
			&& method_exists( $order_util, 'custom_orders_table_usage_is_enabled' )
			&& call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) );
	}
}
