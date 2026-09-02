<?php
/**
 * Legacy order-pay payment passthrough.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;

/** Mints the server-owned ledger row for legacy webview payments. */
class Webview_Passthrough {
	/** Register payment completion hooks. */
	public static function register_hooks(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * Mint after WooCommerce completes a payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_payment_complete( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( wcpos_is_pos_order( $order ) ) {
			$live_methods = array();
			foreach ( Ledger::instance()->read( $order ) as $row ) {
				if ( in_array( $row['status'] ?? '', Ledger::LIVE_STATUSES, true ) ) {
					$live_methods[] = $row['method_id'];
				}
			}
			if ( $live_methods && ! in_array( $order->get_payment_method(), $live_methods, true ) ) {
				Logger::log( sprintf( 'Skipped WCPOS webview payment for order #%d: live ledger rows use a different payment method.', $order->get_id() ) );
			}
		}

		self::maybe_mint( $order );
	}

	/**
	 * Mint when a POS webview offline gateway reaches a paid status.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous order status.
	 * @param string $to       New order status.
	 */
	public static function on_status_changed( $order_id, $from, $to ): void {
		if ( ! wcpos_request() || in_array( $to, array( 'pos-open', 'pos-partial', 'pending', 'cancelled', 'failed', 'refunded', 'checkout-draft', 'trash' ), true ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || '' === $order->get_payment_method() ) {
			return;
		}

		self::maybe_mint( $order );
	}

	/**
	 * Append a captured webview row when payment is not already represented.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function maybe_mint( WC_Order $order ): void {
		if ( ! wcpos_is_pos_order( $order ) ) {
			return;
		}
		$ledger = Ledger::instance();
		$rows   = $ledger->read( $order );
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'] ?? '', Ledger::LIVE_STATUSES, true ) ) {
				return;
			}
		}
		if ( Ledger::is_deriving() ) {
			return;
		}

		$method_id = $order->get_payment_method();
		if ( (float) $order->get_total() <= 0 || '' === $method_id ) {
			return;
		}
		$descriptor = Descriptor_Builder::instance()->get( $method_id );
		$tendered   = null;
		$change     = null;
		if ( 'pos_cash' === $method_id ) {
			$cash_tendered = $order->get_meta( '_pos_cash_amount_tendered' );
			$cash_change    = $order->get_meta( '_pos_cash_change' );
			$tendered       = '' === $cash_tendered ? null : $cash_tendered;
			$change         = '' === $cash_change ? null : $cash_change;
		}
		$transaction_id = $order->get_transaction_id();
		$now            = gmdate( 'c' );
		$rows[] = array(
			'id'               => wp_generate_uuid4(),
			'source'           => 'webview',
			'method_id'        => $method_id,
			'kind'             => $descriptor ? $descriptor['kind'] : 'other',
			'provider'         => $descriptor ? $descriptor['capture']['provider'] : null,
			'capture_mode'     => 'webview',
			'transport'        => null,
			'recorded_offline' => false,
			'amount'           => $order->get_total(),
			'currency'         => $order->get_currency(),
			'tendered'         => $tendered,
			'change'           => $change,
			'tip'              => null,
			'status'           => 'captured',
			'provider_refs'    => array( 'transaction_id' => '' !== $transaction_id ? $transaction_id : null ),
			'receipt'          => array(),
			'cashier_id'       => get_current_user_id(),
			'store_id'         => '' === (string) $order->get_meta( '_pos_store' ) ? null : (int) $order->get_meta( '_pos_store' ),
			'created_at_gmt'   => $now,
			'captured_at_gmt'  => $now,
			'updated_at_gmt'   => $now,
		);
		$ledger->save( $order, $rows );
	}
}
