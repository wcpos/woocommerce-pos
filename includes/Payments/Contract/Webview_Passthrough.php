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
	/**
	 * Order whose order-pay form WooCommerce is currently running a gateway against.
	 *
	 * @var int
	 */
	private static $paying_order_id = 0;

	/** Register payment completion hooks. */
	public static function register_hooks(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 10, 1 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'on_before_pay_action' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * Remember the order WooCommerce is about to hand to a gateway.
	 *
	 * WC_Form_Handler::pay_action() fires this after verifying the pay nonce and the
	 * order key and immediately before calling the gateway's process_payment(). It is
	 * the only payment signal the offline gateways (BACS, cheque, COD) give us — they
	 * land a status instead of calling payment_complete() — and it is what separates a
	 * real tender from an ordinary status edit made during a POS request.
	 *
	 * @param mixed $order Order being paid.
	 */
	public static function on_before_pay_action( $order ): void {
		self::$paying_order_id = $order instanceof WC_Order ? $order->get_id() : 0;
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
	 * Mint when an offline gateway's process_payment() lands a paid status.
	 *
	 * A status change on its own is never evidence of payment — an ordinary workflow
	 * edit during a POS request must not fabricate a captured tender — so this only
	 * runs for the order the order-pay form is paying right now.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous order status.
	 * @param string $to       New order status.
	 */
	public static function on_status_changed( $order_id, $from, $to ): void {
		if ( ! wcpos_request() || self::$paying_order_id !== (int) $order_id ) {
			return;
		}
		// Only a paid status is money. BACS and cheque land on-hold where the merchant
		// configures it that way, and on-hold means "awaiting payment", not a captured
		// row. wc_get_is_paid_statuses() carries anything a site filters in; WCPOS's own
		// pos-open and pos-partial are deliberately not among them.
		if ( ! in_array( (string) $to, wc_get_is_paid_statuses(), true ) ) {
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
		// The order writer records who rang up the sale in `_pos_user` (Orders_Controller);
		// the order-pay POST runs as the order's customer, so the current user is only a
		// fallback for an order that never went through the POS writer.
		$cashier_id = (int) $order->get_meta( '_pos_user' );
		$now        = gmdate( 'c' );
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
			'cashier_id'       => $cashier_id > 0 ? $cashier_id : get_current_user_id(),
			'store_id'         => '' === (string) $order->get_meta( '_pos_store' ) ? null : (int) $order->get_meta( '_pos_store' ),
			'created_at_gmt'   => $now,
			'captured_at_gmt'  => $now,
			'updated_at_gmt'   => $now,
		);
		$ledger->save( $order, $rows );
	}
}
