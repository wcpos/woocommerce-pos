<?php
/**
 * Bounded reconciliation of stale provider payment legs.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;

/** Free owns scheduling; registered handlers own provider status and expiry operations. */
final class Payments_Sweeper {
	const HOOK = 'wcpos_payments_sweep';

	/** Register the callback and schedule one recurring reconciliation event. */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'wcpos_ten_minutes', self::HOOK );
		}
	}

	/**
	 * Add the shared ten-minute interval.
	 *
	 * @param array $schedules Existing cron schedules.
	 */
	public function schedules( array $schedules ): array {
		$schedules['wcpos_ten_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display' => __( 'Every ten minutes', 'woocommerce-pos' ),
		);
		return $schedules;
	}

	/** Poll oldest-modified in-progress orders within this run's batch budget. */
	public function run(): void {
		$threshold = max( 0, (int) apply_filters( 'wcpos_payments_sweep_threshold', 5 * MINUTE_IN_SECONDS ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public payments contract filter.
		// Selected by the live-leg index, not by order status: an authorization that covers
		// the balance has already completed the order (ledger.md §3.3, authorized counts),
		// and that leg still has to be voided if it expires uncaptured.
		$order_ids = Payment_Index::order_ids_with_live_legs(
			max( 1, (int) apply_filters( 'wcpos_payments_sweep_batch_size', 50 ) ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public payments contract filter.
		);
		foreach ( $order_ids as $order_id ) {
			// A busy order is left for the next run; the existing lock owns contention policy.
			Order_Lock::instance()->with_lock(
				$order_id,
				function () use ( $order_id, $threshold ) {
					$order = wc_get_order( $order_id );
					if ( ! $order instanceof WC_Order ) {
						return;
					}
					$ledger = Ledger::instance();
					foreach ( $ledger->read( $order ) as $row ) {
						$updated = strtotime( $row['updated_at_gmt'] ?? $row['created_at_gmt'] ?? '' );
						if ( ! in_array( $row['status'], array( 'pending', 'authorized' ), true ) || false === $updated || $updated >= time() - $threshold ) {
							continue;
						}
						$handler = Capture_Mode_Registry::instance()->resolve( $row['capture_mode'], $row['provider'] ?? null );
						if ( ! $handler ) {
							continue;
						}
						$result = $handler->status( $row );
						if ( is_wp_error( $result ) ) {
							Logger::log( sprintf( 'WCPOS payment sweep %s: %s', $row['id'], $result->get_error_message() ) );
							continue;
						}
						// Decide expiry before an authorization can complete the order.
						$result = $ledger->apply_result( $order, $row['id'], $result, false );
						$expires = ! is_wp_error( $result ) && ! empty( $result['expires_at'] ) ? strtotime( $result['expires_at'] ) : false;
						if ( ! is_wp_error( $result ) && in_array( $result['status'], array( 'pending', 'authorized' ), true ) && false !== $expires && $expires < time() ) {
							$result = $handler->void( $result, 'expired' );
							if ( ! is_wp_error( $result ) ) {
								$result = $ledger->apply_result( $order, $row['id'], $result, false );
							}
							// The projection never unwinds a paid order (§3.3), so an authorization
							// that completed it and then lapsed leaves the order paid with no money
							// captured. That is a needs-attention fact, recorded where staff look.
							if ( ! is_wp_error( $result ) && ! in_array( $order->get_status(), Ledger::IN_PROGRESS_STATUSES, true ) ) {
								/* translators: 1: payment row uuid, 2: leg amount with currency. */
								$order->add_order_note( sprintf( __( 'WCPOS payment %1$s: the authorization for %2$s expired after the order was marked paid and was never captured — needs attention.', 'woocommerce-pos' ), $row['id'], $row['amount'] . ' ' . $row['currency'] ) );
							}
						}
						if ( is_wp_error( $result ) ) {
							Logger::log( sprintf( 'WCPOS payment sweep %s: %s', $row['id'], $result->get_error_message() ) );
						}
					}
					// Reconcile every leg before counting authorizations that may expire this run.
					$ledger->derive( $order, $ledger->read( $order ) );
					if ( $order->get_changes() ) {
						$order->save();
					}
				}
			);
		}
	}
}
