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
	 * Count a print now.
	 *
	 * @param WC_Order $order Order.
	 */
	public function count( WC_Order $order ): int {
		return (int) $this->locked(
			$order,
			function ( int $count ) use ( $order ): int {
				$this->save( $order, $count );
				return $count;
			}
		);
	}

	/**
	 * Reserve the next count, render under it, and commit it only if the render
	 * produced something. The per-order lock is held across the render so two
	 * concurrent prints cannot both be rendered under the same number.
	 *
	 * @param WC_Order $order  Order.
	 * @param callable $render function ( int $count ): string — the document bytes.
	 *
	 * @return string The rendered document ('' when nothing rendered).
	 */
	public function count_after( WC_Order $order, callable $render ): string {
		return (string) $this->locked(
			$order,
			function ( int $count ) use ( $order, $render ): string {
				$result = (string) $render( $count );
				if ( '' !== $result ) {
					$this->save( $order, $count );
				}
				return $result;
			}
		);
	}

	/**
	 * Mark the copy being returned; the stored snapshot is never touched.
	 *
	 * `order.printed` is render-time data (the builder stamps it at build), so a
	 * copy served from a frozen snapshot gets the time of THIS print, in the order's
	 * store timezone and locale like the builder.
	 *
	 * @param array         $data  Receipt data (a copy).
	 * @param int           $count This print's count.
	 * @param WC_Order|null $order Order, to resolve its store for the printed time.
	 */
	public function mark( array $data, int $count, ?WC_Order $order = null ): array {
		$data['fiscal']['is_reprint']    = $count > 1;
		$data['fiscal']['reprint_count'] = max( 0, $count - 1 );
		if ( isset( $data['order'] ) && is_array( $data['order'] ) ) {
			$pos_store                = null !== $order ? Receipt_Data_Builder::resolve_pos_store( $order ) : wcpos_get_store();
			$resolver                 = new Receipt_Store_Resolver( \is_object( $pos_store ) ? $pos_store : new Store() );
			$data['order']['printed'] = Receipt_Date_Formatter::from_timestamp( time(), $resolver->resolve_store_timezone(), $resolver->resolve_locale() );
		}

		return $data;
	}

	/**
	 * Run work under the per-order named lock with the next count, fresh from the
	 * database, so two prints of one order in the same instant never share a number.
	 *
	 * @param WC_Order $order Order.
	 * @param callable $work  function ( int $next_count ): mixed.
	 *
	 * @return mixed
	 */
	private function locked( WC_Order $order, callable $work ) {
		global $wpdb;
		$lock   = 'wcpos_receipt_print_count_' . $order->get_id();
		$locked = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::LOCK_WAIT_SECONDS ) );
		try {
			if ( $locked ) {
				$order->read_meta_data( true );
			}
			return $work( (int) $order->get_meta( '_wcpos_receipt_print_count' ) + 1 );
		} finally {
			if ( $locked ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
		}
	}

	/**
	 * Persist a count.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $count Count.
	 */
	private function save( WC_Order $order, int $count ): void {
		$order->update_meta_data( '_wcpos_receipt_print_count', (string) $count );
		$order->save();
	}
}
