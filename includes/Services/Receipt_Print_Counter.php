<?php
/**
 * Server-derived receipt print counting.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Order;
use WCPOS\WooCommercePOS\Abstracts\Store;

/** Marks render copies without changing fiscal snapshots. */
final class Receipt_Print_Counter {
	/** Same bounded wait as the receipt sequence lock. */
	private const LOCK_WAIT_SECONDS = 5;

	/**
	 * Count an explicit print.
	 *
	 * @param WC_Order $order Order being printed.
	 * @return int New print count.
	 */
	public function count( WC_Order $order ): int {
		global $wpdb;
		// Two prints of one order in the same instant must not read the same value:
		// the increment runs under the per-order named lock the snapshot store uses,
		// and re-reads the meta inside it so the second caller sees the first's save.
		$lock   = 'wcpos_receipt_print_count_' . $order->get_id();
		$locked = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::LOCK_WAIT_SECONDS ) );
		try {
			if ( $locked ) {
				$order->read_meta_data( true );
			}
			$count = (int) $order->get_meta( '_wcpos_receipt_print_count' ) + 1;
			$order->update_meta_data( '_wcpos_receipt_print_count', (string) $count );
			$order->save();
		} finally {
			if ( $locked ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
		}

		return $count;
	}

	/**
	 * Mark only the returned render payload.
	 *
	 * @param array $data  Receipt payload.
	 * @param int   $count Assigned print count.
	 * @return array Marked payload.
	 */
	/**
	 * The count the next print would take, without saving it (for a marking that is
	 * committed only once the document actually rendered).
	 *
	 * @param WC_Order $order Order.
	 */
	public function peek( WC_Order $order ): int {
		return (int) $order->get_meta( '_wcpos_receipt_print_count' ) + 1;
	}

	/**
	 * Mark the copy being returned; the stored snapshot is never touched.
	 *
	 * `order.printed` is render-time data (the builder stamps it at build), so a
	 * copy served from a frozen snapshot gets the time of THIS print, not the sale's.
	 *
	 * @param array $data  Receipt data (a copy).
	 * @param int   $count This print's count.
	 */
	public function mark( array $data, int $count ): array {
		$data['fiscal']['is_reprint']    = $count > 1;
		$data['fiscal']['reprint_count'] = max( 0, $count - 1 );
		if ( isset( $data['order'] ) && is_array( $data['order'] ) ) {
			$pos_store = wcpos_get_store();
			$resolver  = new Receipt_Store_Resolver( \is_object( $pos_store ) ? $pos_store : new Store() );
			$data['order']['printed'] = Receipt_Date_Formatter::from_timestamp( time(), $resolver->resolve_store_timezone(), $resolver->resolve_locale() );
		}

		return $data;
	}
}
