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
	 * Orders in the given statuses that carry at least one ledger row, oldest-modified first.
	 *
	 * @param string[] $statuses Bare order statuses (`pending`, not `wc-pending`).
	 * @param int      $limit    Batch cap.
	 *
	 * @return int[] Order ids.
	 */
	public static function order_ids_with_rows( array $statuses, int $limit ): array {
		global $wpdb;
		$statuses = array_values(
			array_map(
				static function ( string $status ): string {
					return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
				},
				$statuses
			)
		);
		if ( empty( $statuses ) || $limit < 1 ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		if ( self::hpos() ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Placeholders built from a counted list; datastore-aware index lookup.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT o.id FROM {$wpdb->prefix}wc_orders o"
					. " WHERE o.type = 'shop_order' AND o.status IN ( {$placeholders} )"
					. " AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}wc_orders_meta m WHERE m.order_id = o.id AND m.meta_key = %s )"
					. ' ORDER BY o.date_updated_gmt ASC LIMIT %d',
					array_merge( $statuses, array( Ledger::PAYMENT_ID_META_KEY, $limit ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Placeholders built from a counted list; datastore-aware index lookup.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p"
					. " WHERE p.post_type = 'shop_order' AND p.post_status IN ( {$placeholders} )"
					. " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s )"
					. ' ORDER BY p.post_modified_gmt ASC LIMIT %d',
					array_merge( $statuses, array( Ledger::PAYMENT_ID_META_KEY, $limit ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
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
