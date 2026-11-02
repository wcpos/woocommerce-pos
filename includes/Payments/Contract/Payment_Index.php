<?php
/**
 * Order lookups through the ledger's index meta.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

use WCPOS\WooCommercePOS\Services\Order_Meta_Store;

\defined( 'ABSPATH' ) || die;

/**
 * Order lookups through the ledger's index meta, read from the order meta table by
 * {@see Order_Meta_Store}.
 *
 * `meta_key` compares under the table's `_ci` PAD SPACE collation, where `_WCPOS_PAYMENT_ID` and
 * `_wcpos_payment_id ` match the index key; the holder lookup asks for the exact key too. The
 * live-leg scan is a candidate list: the sweeper re-reads each order's ledger by its exact key.
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
		$ids = Order_Meta_Store::order_ids(
			Ledger::PAYMENT_ID_META_KEY,
			array(
				'value'     => $payment_id,
				'exact_key' => true,
				'type'      => 'shop_order',
				'exclude'   => array( 'auto-draft' ),
				'limit'     => 1,
			)
		);
		return $ids[0] ?? 0;
	}

	/**
	 * Orders holding at least one live leg, oldest-modified first: a row the ledger still has work to do on
	 * (pending, authorized, or owing its sale's paid transition).
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
		return Order_Meta_Store::order_ids(
			Ledger::LIVE_LEG_META_KEY,
			array(
				'type'    => 'shop_order',
				'exclude' => array( 'trash', 'auto-draft' ),
				'order'   => 'modified',
				'limit'   => $limit,
			)
		) ?? array();
	}
}
