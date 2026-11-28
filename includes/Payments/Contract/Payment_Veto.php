<?php
/**
 * Payment pre-capture filter.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_Error;

/** The store's pre-capture veto on money commands, run by Command_Log::run() through the controller's gate. */
final class Payment_Veto {
	public const KINDS = array( 'record', 'intent', 'submit', 'capture', 'close' );

	/** Codes a refusal may never carry: the till's re-send list and core's own payment answers. Such a code becomes wcpos_payment_vetoed. */
	public const RESERVED_CODES = array(
		// The till re-sends these: monorepo packages/sync-core/src/woo/moneyCommandAnswer.ts RESENDABLE_MONEY_CODES (main a84fd1bf, 28 Nov 2026). Keep in step with that list.
		'wcpos_payment_locked',
		'wcpos_revision_stale',
		'woo_rxdb_sync_record_locked',
		// Codes core itself answers on the payments routes (includes/Payments, Payments_Controller).
		'wcpos_amount_exceeds_balance',
		'wcpos_amount_mismatch',
		'wcpos_balance_outstanding',
		'wcpos_capture_mode_conflict',
		'wcpos_capture_mode_not_allowed',
		'wcpos_capture_mode_unsupported',
		'wcpos_command_conflict',
		'wcpos_fields_invalid',
		'wcpos_idempotency_conflict',
		'wcpos_idempotency_in_progress',
		'wcpos_invalid_transition',
		'wcpos_method_not_recordable',
		'wcpos_order_already_paid',
		'wcpos_order_not_found',
		'wcpos_payment_conflict',
		'wcpos_payment_in_flight',
		'wcpos_payment_method_disabled',
		'wcpos_payment_method_not_found',
		'wcpos_payment_not_correctable',
		'wcpos_payment_not_found',
		'wcpos_provider_error',
		'wcpos_refund_not_allocatable',
		'wcpos_rest_cannot_edit_order',
		'wcpos_revision_required',
		'wcpos_settlement_not_saved',
		'wcpos_tender_correction_unavailable',
	);

	/**
	 * Ask the store whether to refuse this command before its handler runs.
	 *
	 * @param WC_Order $order   Order read under the lock.
	 * @param string   $kind    Command kind.
	 * @param array    $context Amount, method and cashier.
	 */
	public static function check( WC_Order $order, string $kind, array $context ): ?WP_Error {
		if ( ! in_array( $kind, self::KINDS, true ) || ! has_filter( 'wcpos_payment_pre_capture' ) ) {
			return null;
		}
		try {
			/**
			 * Last word before money is taken or the sale closes. Return a WP_Error to refuse.
			 * Runs under the order lock, so the callback has no need to take it.
			 * The callback may change and save the order (for example, recompute a coupon) only when it is about to refuse;
			 * a callback that allows must never change the order.
			 * A callback that receives a WP_Error returns it unchanged.
			 *
			 * Core answers a refusal with 409 in the shape of `wcpos_revision_stale` plus `vetoed: true`: the code is the
			 * callback's when it starts with `wcpos_` and is not one of RESERVED_CODES, else `wcpos_payment_vetoed`;
			 * the message is the callback's, translated
			 * and readable by a cashier; `data.detail` is passed through from the error's data. Nothing is stored. A throw
			 * or any other return value allows the command, and a throw is logged.
			 *
			 * @since 2.0.7
			 *
			 * @param null|WP_Error $veto    Null to allow.
			 * @param WC_Order      $order   The order, read under the lock.
			 * @param string        $kind    record|intent|submit|capture|close.
			 * @param array         $context ['amount' => minor units or null, 'method' => id or null, 'cashier_id' => int].
			 */
			$veto = apply_filters( 'wcpos_payment_pre_capture', null, $order, $kind, $context );
		} catch ( \Throwable $error ) {
			Logger::log(
				'WCPOS payment veto: a wcpos_payment_pre_capture callback threw, so the command is allowed.',
				array(
					'kind'     => $kind,
					'order_id' => $order->get_id(),
					'error'    => get_class( $error ) . ': ' . $error->getMessage(),
				)
			);
			return null;
		}
		if ( ! is_wp_error( $veto ) ) {
			return null;
		}
		$code    = $veto->get_error_code();
		$code    = 0 === strpos( (string) $code, 'wcpos_' ) && ! in_array( $code, self::RESERVED_CODES, true ) ? $code : 'wcpos_payment_vetoed';
		$message = $veto->get_error_message();
		if ( '' === $message ) {
			$message = __( 'The store refused this payment. Check the order and try again.', 'woocommerce-pos' );
		}
		$state = Order_Serializer::current_state( $order->get_id() );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$fresh = wc_get_order( $order->get_id() );
		$data  = array(
			'status'          => 409,
			'currentRevision' => $state['revision'],
			'current'         => $state['document'],
			'order'           => Ledger::instance()->summary( $fresh instanceof WC_Order ? $fresh : $order ),
			'vetoed'          => true,
		);
		$error_data = $veto->get_error_data();
		if ( is_array( $error_data ) && array_key_exists( 'detail', $error_data ) ) {
			$data['detail'] = $error_data['detail'];
		}
		return new WP_Error( $code, $message, $data );
	}
}
