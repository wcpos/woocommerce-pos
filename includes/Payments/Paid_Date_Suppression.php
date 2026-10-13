<?php
/**
 * Shared paid-date suppression for WCPOS status writes.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Payments;

\defined( 'ABSPATH' ) || die;

use WC_Abstract_Order;

/**
 * Distinguish an unpaid status write from a real payment by its paid date.
 *
 * WC_Order::maybe_set_date_paid() asks from inside set_status(), after the
 * status prop has moved, and only while date_paid is empty. It stamps when
 * has_status( <answer> ) is true. payment_complete() stamps date_paid itself
 * before asking, while the order still reads its previous status (or already
 * reads the target status on a re-submission).
 *
 * Withhold exactly when the order already reads the target status and date_paid
 * is empty. Answering '' to a real payment_complete() makes set_status( '' )
 * fall back to pending. Scope the filter to one order id: transition handlers
 * can settle other orders mid-write (subscriptions, bundles and gift cards).
 */
final class Paid_Date_Suppression {
	/**
	 * Whether WooCommerce asking now would stamp date_paid on a sale nobody paid.
	 *
	 * @param mixed  $order  The order WooCommerce passed (third filter argument).
	 * @param string $status Target status, without the `wc-` prefix.
	 *
	 * @return bool
	 */
	public static function withholds( $order, string $status ): bool {
		return $order instanceof WC_Abstract_Order
			&& '' !== $status
			&& $order->get_status() === $status
			&& ! $order->get_date_paid( 'edit' );
	}

	/**
	 * A `woocommerce_payment_complete_order_status` callback that answers '' for one
	 * order while withholds() holds, and passes every other answer through.
	 * Register it with three accepted arguments.
	 *
	 * @param int    $order_id Order the suppression is for.
	 * @param string $status   Target status, without the `wc-` prefix.
	 *
	 * @return \Closure
	 */
	public static function filter_for( int $order_id, string $status ): \Closure {
		return static function ( $payment_status, $filtered_order_id = 0, $filtered_order = null ) use ( $order_id, $status ) {
			return (int) $filtered_order_id === $order_id && self::withholds( $filtered_order, $status ) ? '' : $payment_status;
		};
	}
}
