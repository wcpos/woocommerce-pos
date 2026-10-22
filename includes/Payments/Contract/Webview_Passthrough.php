<?php
/**
 * Legacy order-pay payment passthrough.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WC_Payment_Gateway;
use WCPOS\WooCommercePOS\Logger;

/** Mints the server-owned ledger row for legacy webview payments. */
class Webview_Passthrough {
	/**
	 * Order whose order-pay form WooCommerce is currently running a gateway against.
	 *
	 * @var int
	 */
	private static $paying_order_id = 0;

	/**
	 * Whether WooCommerce's order-pay form is running a gateway against this order right now.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function is_paying( int $order_id ): bool {
		return 0 !== $order_id && self::$paying_order_id === $order_id;
	}

	/** Register payment completion hooks. */
	public static function register_hooks(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 10, 1 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'on_before_pay_action' ), 10, 1 );
		// Priority 1: the window must close before Orders::apply_unpaid_gateway_order_status()
		// runs on this same filter at PHP_INT_MAX and lands the merchant's configured status.
		add_filter( 'woocommerce_payment_successful_result', array( __CLASS__, 'on_payment_successful_result' ), 1, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		// Priority 100: after Gateways::available_payment_gateways() (99) rebuilds the POS list.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'drop_full_total_gateways' ), 100 );
		add_filter( 'woocommerce_no_available_payment_methods_message', array( __CLASS__, 'no_gateways_message' ) );
	}

	/**
	 * Whether order-pay refuses the gateway (ADR 0040 D3): webview and manual gateways settle the order total,
	 * so they cannot pay a POS sale holding a captured tender. Pro's server and device modes charge the balance.
	 *
	 * @param WC_Order                $order   Order being paid.
	 * @param WC_Payment_Gateway|null $gateway Chosen gateway; null when unknown.
	 */
	private static function refuses( WC_Order $order, ?WC_Payment_Gateway $gateway ): bool {
		if ( ( $gateway && in_array( Descriptor_Builder::resolve_mode( $gateway ), array( 'server', 'device' ), true ) ) || ! wcpos_is_pos_order( $order ) ) {
			return false;
		}
		return (bool) array_filter( Ledger::instance()->read( $order ), static fn( $row ) => in_array( $row['status'] ?? '', Ledger::COUNTING_STATUSES, true ) );
	}

	/** The order whose pay page this request renders, or null off the order-pay endpoint. */
	private static function order_pay_order(): ?WC_Order {
		$order = isset( $GLOBALS['wp'] ) && is_wc_endpoint_url( 'order-pay' ) ? wc_get_order( absint( $GLOBALS['wp']->query_vars['order-pay'] ) ) : null;
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Drop the gateways order-pay refuses for the order on the pay page.
	 *
	 * @param mixed $gateways Available gateways; other plugins call this filter with anything.
	 * @return mixed
	 */
	public static function drop_full_total_gateways( $gateways ) {
		$order = is_array( $gateways ) ? self::order_pay_order() : null;
		return $order ? array_filter( $gateways, static fn( $gateway ) => $gateway instanceof WC_Payment_Gateway && ! self::refuses( $order, $gateway ) ) : $gateways;
	}

	/**
	 * Say why the pay page lists no gateway when order-pay refused them all.
	 *
	 * @param mixed $message WooCommerce's message.
	 * @return mixed
	 */
	public static function no_gateways_message( $message ) {
		$order = self::order_pay_order();
		return $order && self::refuses( $order, null ) ? self::refusal() : $message;
	}

	/** The refusal shown on the pay page. */
	private static function refusal(): string {
		return __( 'This sale has a payment recorded at the till. Finish it at the till.', 'woocommerce-pos' );
	}

	/**
	 * Remember the order WooCommerce is about to hand to a gateway, or refuse a full-total one (D3).
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
		$method_id = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the pay nonce before this action.
		$refused   = $order instanceof WC_Order && self::refuses( $order, Descriptor_Builder::instance()->gateway( (string) $method_id ) );
		if ( $refused ) {
			// An error notice is WooCommerce's own stop: pay_action() never calls process_payment() with one queued.
			wc_add_notice( self::refusal(), 'error' );
		}
		self::$paying_order_id = $order instanceof WC_Order && ! $refused ? $order->get_id() : 0;
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
				if ( in_array( $row['status'] ?? '', Ledger::LIVE_STATUSES, true ) ) {
					$live_methods[] = $row['method_id'];
				}
			}
			if ( $live_methods && ! in_array( $order->get_payment_method(), $live_methods, true ) ) {
				Logger::log( sprintf( 'Skipped WCPOS webview payment for order #%d: live ledger rows use a different payment method.', $order->get_id() ) );
			}
		}

		// A sent attempt this payment answers is the row's id, so Ledger::save() marks the attempt paid.
		$attempt = Completion_Stamp::attempt( $order->get_id() );
		try {
			self::mint( $order, $attempt ? array( 'id' => $attempt['attempt_id'] ) : array() );
		} catch ( Ledger_Persistence_Exception $error ) {
			return; // mint() already logged and left a recovery note; never interrupt the gateway hook.
		} catch ( \Throwable $error ) {
			// Anything else is unexpected: record it once, and still never interrupt the gateway hook.
			Logger::error( sprintf( 'WCPOS webview payment hook failed on order #%d: %s', $order->get_id(), $error->getMessage() ), array( 'order_id' => $order->get_id() ) );
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
			self::mint( $order );
		} catch ( Ledger_Persistence_Exception $error ) {
			return; // mint() already logged and left a recovery note; never interrupt the gateway hook.
		} catch ( \Throwable $error ) {
			// Anything else is unexpected: record it once, and still never interrupt the gateway hook.
			Logger::error( sprintf( 'WCPOS webview payment hook failed on order #%d: %s', $order->get_id(), $error->getMessage() ), array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Append a captured webview row when payment is not already represented.
	 *
	 * @param WC_Order $order     Order object.
	 * @param array    $overrides Submission-specific row values.
	 * @throws Ledger_Persistence_Exception When the ledger cannot be saved.
	 */
	public static function mint( WC_Order $order, array $overrides = array() ): ?array {
		if ( ! wcpos_is_pos_order( $order ) ) {
			return null;
		}
		$ledger = Ledger::instance();
		$rows   = $ledger->read( $order );
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'] ?? '', Ledger::LIVE_STATUSES, true ) ) {
				return null;
			}
		}
		if ( Ledger::is_deriving( $order->get_id() ) || Gateway_Submission::is_submitting( $order->get_id() ) ) {
			return null;
		}

		$method_id = $overrides['method_id'] ?? $order->get_payment_method();
		if ( (float) $order->get_total() <= 0 || '' === $method_id ) {
			return null;
		}
		// From here WooCommerce has taken money: any failure, building the row included, is recorded loudly.
		$transaction_id = '';
		$row            = array();
		try {
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
			$adopted = $ledger->adopt(
				$order,
				$row,
				true,
				static function ( $stored ) use ( $row ): bool {
					// Concurrent callbacks for one payment mint one row: same id, or the same
					// method with an intersecting provider reference, or a reference-less live webview row.
					if ( ( $stored['id'] ?? null ) === $row['id'] ) {
						return true;
					}
					$refs = array_filter( $row['provider_refs'] );
					if ( $refs ) {
						return ( $stored['method_id'] ?? null ) === $row['method_id'] && (bool) array_intersect_assoc( $refs, $stored['provider_refs'] ?? array() );
					}
					return 'webview' === ( $stored['source'] ?? null ) && in_array( $stored['status'] ?? '', Ledger::LIVE_STATUSES, true );
				}
			);
		} catch ( \Throwable $error ) {
			// WooCommerce already took this money: say so loudly with what is known; a human reconciles it.
			$reference = '' !== (string) $transaction_id ? $transaction_id : ( $row['id'] ?? $overrides['id'] ?? $method_id );
			$amount    = $row['amount'] ?? $overrides['amount'] ?? '?';
			$currency  = $row['currency'] ?? $overrides['currency'] ?? '?';
			Logger::error( sprintf( 'WCPOS payment %s (%s %s) captured but not recorded in the ledger on order #%d; reconcile manually: %s', $reference, $amount, $currency, $order->get_id(), $error->getMessage() ), array( 'order_id' => $order->get_id() ) );
			try {
				$order->add_order_note( sprintf( 'WCPOS: payment %s (%s %s) captured but not recorded in the ledger; reconcile manually.', $reference, $amount, $currency ) );
			} catch ( \Throwable $note_error ) {
				Logger::error( sprintf( 'WCPOS could not add the unrecorded-payment note on order #%d: %s', $order->get_id(), $note_error->getMessage() ) );
			}
			throw $error instanceof Ledger_Persistence_Exception ? $error : new Ledger_Persistence_Exception( esc_html( $error->getMessage() ), 0, $error );
		}
		return is_wp_error( $adopted ) ? null : $adopted;
	}
}
