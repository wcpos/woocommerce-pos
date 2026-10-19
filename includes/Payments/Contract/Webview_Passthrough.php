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
	const PENDING_META_KEY = '_wcpos_mint_pending';

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
		// Priority 1: the window must close before Orders::apply_unpaid_gateway_order_status()
		// runs on this same filter at PHP_INT_MAX and lands the merchant's configured status.
		add_filter( 'woocommerce_payment_successful_result', array( __CLASS__, 'on_payment_successful_result' ), 1, 2 );
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
	 * Forget the paying order once the gateway has answered.
	 *
	 * WC_Form_Handler::pay_action() applies this filter after process_payment()
	 * returns success, so a status the gateway landed is already recorded by now
	 * and anything that moves the order afterwards is not the gateway paying it.
	 * Orders::apply_unpaid_gateway_order_status() runs on this very filter for a
	 * gateway that took no payment, applying the merchant's configured status and
	 * withholding date_paid on purpose; with the window still open that transition
	 * read as an offline tender and minted a captured row for the full total.
	 *
	 * @param mixed $result   Gateway result, passed through untouched.
	 * @param mixed $order_id Order being paid.
	 *
	 * @return mixed
	 */
	public static function on_payment_successful_result( $result, $order_id ) {
		if ( self::$paying_order_id === (int) $order_id ) {
			self::$paying_order_id = 0;
		}

		return $result;
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
				if ( (int) ( $row['epoch'] ?? 0 ) === Money_Epoch::current( $order ) && in_array( $row['status'] ?? '', Ledger::LIVE_STATUSES, true ) ) {
					$live_methods[] = $row['method_id'];
				}
			}
			if ( $live_methods && ! in_array( $order->get_payment_method(), $live_methods, true ) ) {
				Logger::log( sprintf( 'Skipped WCPOS webview payment for order #%d: live ledger rows use a different payment method.', $order->get_id() ) );
			}
		}

		try {
			self::mint( $order, array(), true );
		} catch ( Ledger_Persistence_Exception $error ) {
			// The pending fact lets the sweeper reconcile without interrupting WooCommerce.
			Logger::error( sprintf( 'WCPOS webview payment ledger could not be saved on order #%d: %s', $order->get_id(), $error->getMessage() ) );
		}
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

		try {
			self::mint( $order, array(), true );
		} catch ( Ledger_Persistence_Exception $error ) {
			// Only hook callers swallow this; direct submissions must still receive their 409.
			Logger::error( sprintf( 'WCPOS webview payment ledger could not be saved on order #%d: %s', $order->get_id(), $error->getMessage() ) );
		}
	}

	/**
	 * Append a captured webview row when payment is not already represented.
	 *
	 * @param WC_Order $order     Order object.
	 * @param array    $overrides Submission-specific row values.
	 * @param bool     $defer     Persist failed hook mints for reconciliation.
	 * @throws Ledger_Persistence_Exception When the ledger cannot be saved.
	 */
	public static function mint( WC_Order $order, array $overrides = array(), bool $defer = false ): ?array {
		if ( ! wcpos_is_pos_order( $order ) ) {
			return null;
		}
		$ledger = Ledger::instance();
		$rows   = $ledger->read( $order );
		foreach ( $rows as $row ) {
			if ( (int) ( $row['epoch'] ?? 0 ) === Money_Epoch::current( $order ) && in_array( $row['status'] ?? '', Ledger::LIVE_STATUSES, true ) ) {
				return null;
			}
		}
		if ( Ledger::is_deriving() || Gateway_Submission::is_submitting( $order->get_id() ) ) {
			return null;
		}

		$method_id = $overrides['method_id'] ?? $order->get_payment_method();
		if ( (float) $order->get_total() <= 0 || '' === $method_id ) {
			return null;
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
		$row = array_merge(
			array(
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
			),
			$overrides
		);
		$epoch = Money_Epoch::current( $order );
		try {
			return self::adopt( $order, $row );
		} catch ( Ledger_Persistence_Exception $error ) {
			if ( $defer ) {
				// Only flush this fact: a full save would re-enter the contended ledger keeper.
				$fresh = wc_get_order( $order->get_id() );
				if ( $fresh instanceof WC_Order ) {
					Money_Meta_Guard::authorised(
						$fresh->get_id(),
						static function () use ( $fresh, $row, $epoch, $error ): void {
							$fresh->update_meta_data(
								self::PENDING_META_KEY,
								array(
									'row' => $row,
									'epoch' => $epoch,
									'reason' => $error->getMessage(),
									'created_at_gmt' => gmdate( 'c' ),
								)
							);
							$fresh->save_meta_data();
						}
					);
				}
			}
			throw $error;
		}
	}

	/**
	 * Retry a deferred hook mint. The sweeper holds the order lock and reads fresh state.
	 *
	 * @param WC_Order $order Locked order.
	 */
	public static function reconcile( WC_Order $order ): void {
		$pending = $order->get_meta( self::PENDING_META_KEY );
		if ( ! is_array( $pending ) ) {
			return;
		}
		// Never relabel a previous sale's payment as money taken in a reopened epoch.
		if ( Money_Epoch::current( $order ) !== $pending['epoch'] ) {
			Logger::error( sprintf( 'WCPOS deferred webview payment on order #%d needs reconciliation in epoch %d.', $order->get_id(), $pending['epoch'] ) );
			return;
		}
		try {
			if ( null === self::adopt( $order, $pending['row'] ) ) {
				return;
			}
			Money_Meta_Guard::authorised(
				$order->get_id(),
				static function () use ( $order ): void {
					$order->read_meta_data( true );
					$order->delete_meta_data( self::PENDING_META_KEY );
					$order->save_meta_data();
				}
			);
		} catch ( Ledger_Persistence_Exception $error ) {
			Logger::error( sprintf( 'WCPOS deferred webview payment on order #%d could not be saved: %s', $order->get_id(), $error->getMessage() ) );
		}
	}

	/**
	 * Adopt a captured snapshot with the same locked identity check on first write and retry.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $row   Captured payment snapshot.
	 */
	private static function adopt( WC_Order $order, array $row ): ?array {
		$adopted = Ledger::instance()->adopt(
			$order,
			$row,
			true,
			static function ( $stored, int $epoch ) use ( $row ): bool {
				if ( (int) ( $stored['epoch'] ?? 0 ) === $epoch && ( $stored['id'] ?? null ) === $row['id'] ) {
					return true;
				}
				$refs = array_filter( $row['provider_refs'] );
				if ( $refs ) {
					return (int) ( $stored['epoch'] ?? 0 ) === $epoch && ( $stored['method_id'] ?? null ) === $row['method_id'] && (bool) array_intersect_assoc( $refs, $stored['provider_refs'] ?? array() );
				}
				// A reference-less payment is represented only by a row in the locked epoch.
				return (int) ( $stored['epoch'] ?? 0 ) === $epoch && 'webview' === ( $stored['source'] ?? null ) && in_array( $stored['status'] ?? '', Ledger::LIVE_STATUSES, true );
			}
		);
		return is_wp_error( $adopted ) ? null : $adopted;
	}
}
