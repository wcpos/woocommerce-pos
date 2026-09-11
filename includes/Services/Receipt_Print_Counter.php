<?php
/**
 * Server-derived receipt print counting.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Order;

/** Marks render copies without changing fiscal snapshots. */
final class Receipt_Print_Counter {
	/**
	 * Count an explicit print.
	 *
	 * @param WC_Order $order Order being printed.
	 * @return int New print count.
	 */
	public function count( WC_Order $order ): int {
		$count = (int) $order->get_meta( '_wcpos_receipt_print_count' ) + 1;
		$order->update_meta_data( '_wcpos_receipt_print_count', (string) $count );
		$order->save();

		return $count;
	}

	/**
	 * Mark only the returned render payload.
	 *
	 * @param array $data  Receipt payload.
	 * @param int   $count Assigned print count.
	 * @return array Marked payload.
	 */
	public function mark( array $data, int $count ): array {
		$data['fiscal']['is_reprint'] = $count > 1;
		$data['fiscal']['reprint_count'] = max( 0, $count - 1 );

		return $data;
	}
}
