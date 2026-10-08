<?php
/**
 * Full-order gateway submission and awaiting-customer state.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;

/** Reads what a Woo gateway did, within the route's existing order lock. */
class Gateway_Submission {
	public const STAMP_META_KEY = '_wcpos_awaiting_customer';
	/** Every sent or cancelled attempt for the order's life, so a delayed retry never sends again. */
	public const ATTEMPTS_META_KEY = '_wcpos_gateway_attempts';
	/** Attempt ids the history keeps; older ones fall off (a till does not retry a week-old send). */
	public const ATTEMPTS_CAP = 50;
	/**
	 * Order whose gateway outcome is currently being observed.
	 *
	 * @var int
	 */
	private static $submitting_order_id = 0;

	/**
	 * Whether the submit route owns this order's minting window.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function is_submitting( int $order_id ): bool {
		return self::$submitting_order_id === $order_id;
	}

	/**
	 * Submit declared values and return the observed outcome.
	 *
	 * @param WC_Order $order      Order object.
	 * @param array    $descriptor Method descriptor.
	 * @param string   $attempt_id Attempt UUID; becomes the row id when money is recorded.
	 * @param array    $values     Declared field values.
	 * @return array|WP_Error
	 */
	public static function submit( WC_Order $order, array $descriptor, string $attempt_id, array $values ) {
		if ( 'gateway' !== $descriptor['capture']['mode'] ) {
			return new WP_Error( 'wcpos_capture_mode_unsupported', __( 'Payment capture mode is unsupported.', 'woocommerce-pos' ), array( 'status' => 501 ) );
		}
		if ( ! wp_is_uuid( $attempt_id ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Attempt ID must be a UUID.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		if ( ! wcpos_is_pos_order( $order ) ) {
			return new WP_Error( 'wcpos_invalid_transition', __( 'Only POS orders take a gateway submission.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( empty( $descriptor['pos_enabled'] ) ) {
			return new WP_Error( 'wcpos_payment_method_disabled', __( 'Payment method is not enabled for the POS.', 'woocommerce-pos' ), array( 'status' => 403 ) );
		}
		$attempt_id = strtolower( $attempt_id );
		$ledger     = Ledger::instance();
		$rows       = $ledger->read( $order );
		// A recorded attempt is the row itself: the attempt id is the client-minted row id.
		$recorded = $ledger->find( $order, $attempt_id );
		if ( $recorded ) {
			return array(
				'outcome' => 'recorded',
				'payment' => $recorded,
				'order' => $ledger->summary( $order ),
			);
		}
		$stamp   = self::read_stamp( $order );
		$history = self::read_attempts( $order );
		if ( 'sent' === ( $history[ $attempt_id ] ?? null ) ) {
			return array(
				'outcome' => 'sent',
				'payment' => null,
				'order' => $ledger->summary( $order ),
			);
		}
		if ( 'cancelled' === ( $history[ $attempt_id ] ?? null ) ) {
			// The till undid this send; a late retry of it must not send again.
			return new WP_Error( 'wcpos_payment_conflict', __( 'This attempt was cancelled at the till.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( 0 === Money::minor( $ledger->balance( $order, $rows ) ) ) {
			return new WP_Error( 'wcpos_order_already_paid', __( 'Order is already paid.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'], Ledger::LIVE_STATUSES, true ) ) {
				return new WP_Error( 'wcpos_payment_conflict', __( 'Order already has a live payment.', 'woocommerce-pos' ), array( 'status' => 409 ) );
			}
		}
		$gateway = Descriptor_Builder::instance()->gateway( $descriptor['id'] );
		if ( ! $gateway ) {
			return new WP_Error( 'wcpos_payment_method_not_found', __( 'Payment method not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$components = $descriptor['fields']['components'] ?? array();
		$errors     = self::validate_values( $components, $values );
		if ( $errors ) {
			return self::invalid_fields( $errors );
		}
		$ids             = array();
		$destination     = null;
		$has_destination = false;
		foreach ( $components as $component ) {
			if ( 'note' === $component['component'] ) {
				continue;
			}
			$id    = $component['id'];
			$ids[] = $id;
			unset( $_POST[ $id ] );
			$value = $values[ $id ] ?? null;
			if ( 'checkbox' === $component['component'] ? true === $value : is_string( $value ) ) {
				// WordPress slashes request globals and gateways wp_unslash() them; match that.
				$_POST[ $id ] = 'checkbox' === $component['component'] ? '1' : wp_slash( $value );
			}
			if ( ! $has_destination && 'field' === $component['component'] && in_array( $component['input'], array( 'email', 'tel' ), true ) ) {
				$destination     = is_string( $value ) ? $value : null;
				$has_destination = true;
			}
		}
		$paid     = false;
		$complete = static function ( $id ) use ( $order, &$paid ): void {
			if ( (int) $id === $order->get_id() ) {
				$paid = true;
			}
		};
		$status_changed = static function ( $id, $from, $to ) use ( $complete ): void {
			if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
				$complete( $id );
			}
		};
		$threw           = null;
		$previous_method = null;
		try {
			// A REST request has no WooCommerce session, cart or customer; wc_add_notice() /
			// wc_clear_notices() (validate_fields(), most process_payment() implementations)
			// call methods on the session, and WooCommerce's own BACS, cheque and COD call
			// WC()->cart->empty_cart(). Give the gateway request-scoped ones, the session never
			// init()ed: everything lives in memory for this call, no cookie, no session row.
			if ( ! WC()->session instanceof \WC_Session ) {
				WC()->session = new \WC_Session_Handler();
			}
			if ( ! WC()->customer instanceof \WC_Customer ) {
				WC()->customer = new \WC_Customer( get_current_user_id(), true );
			}
			if ( ! WC()->cart instanceof \WC_Cart ) {
				WC()->cart = new \WC_Cart();
			}
			wc_clear_notices();
			$valid   = $gateway->validate_fields();
			$notices = self::take_error_notices();
			if ( ! $notices && false === $valid ) {
				// WooCommerce gates processing on the boolean too; a silent false still refuses.
				return self::invalid_fields( array( '_form' => array( __( 'The payment method rejected the details entered.', 'woocommerce-pos' ) ) ) );
			}
			if ( $notices ) {
				$errors = array();
				foreach ( $notices as $notice ) {
					$id = in_array( $notice['data']['id'] ?? null, $ids, true ) ? $notice['data']['id'] : null;
					if ( null === $id ) {
						$errors['_form'][] = wp_strip_all_tags( $notice['notice'] );
					} else {
						$errors[ $id ] = wp_strip_all_tags( $notice['notice'] );
					}
				}
				return self::invalid_fields( $errors );
			}
			// WooCommerce's own pay form assigns the chosen gateway before process_payment();
			// the invoice email, the pay link and wp-admin read it from the order. Remembered so
			// a refused attempt can put the previous method back (§2.1: nothing written).
			$previous_method = array( $order->get_payment_method(), $order->get_payment_method_title() );
			$order->set_payment_method( $gateway->id );
			$order->set_payment_method_title( (string) $descriptor['title'] );
			$order->save();
			add_action( 'woocommerce_payment_complete', $complete );
			add_action( 'woocommerce_order_status_changed', $status_changed, 10, 3 );
			self::$submitting_order_id = $order->get_id();
			$result                    = $gateway->process_payment( $order->get_id() );
		} catch ( \Throwable $throwable ) {
			$result = null;
			$threw  = $throwable;
		} finally {
			self::$submitting_order_id = 0;
			remove_action( 'woocommerce_payment_complete', $complete );
			remove_action( 'woocommerce_order_status_changed', $status_changed, 10 );
			foreach ( $ids as $id ) {
				unset( $_POST[ $id ] );
			}
		}
		$notices   = self::take_error_notices();
		$malformed = ! is_array( $result ) || 'success' !== ( $result['result'] ?? null );
		if ( $malformed && ! $paid ) {
			self::restore_method( $order, $previous_method );
			$detail = implode( ' ', wp_list_pluck( $notices, 'notice' ) );
			if ( $threw ) {
				// A gateway that throws instead of adding a notice still owes the operator a reason.
				$detail = '' === $detail ? $threw->getMessage() : $detail;
				Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d: %s threw %s: %s', $attempt_id, $order->get_id(), $descriptor['id'], get_class( $threw ), $threw->getMessage() ) );
			}
			return new WP_Error(
				'wcpos_provider_error',
				__( 'The payment provider failed.', 'woocommerce-pos' ),
				array(
					'status' => 502,
					'detail' => $detail,
				)
			);
		}
		$elsewhere = $paid ? null : self::redirect_host_elsewhere( $result['redirect'] ?? null );
		if ( null !== $elsewhere ) {
			// A hosted checkout: the gateway wants the browser to go somewhere the till cannot
			// follow. Nothing was sent and nothing was taken, so say so rather than stamp "sent".
			self::restore_method( $order, $previous_method );
			Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d: %s answered a redirect to %s; refused.', $attempt_id, $order->get_id(), $descriptor['id'], $elsewhere ) );
			return new WP_Error(
				'wcpos_provider_error',
				__( 'The payment provider failed.', 'woocommerce-pos' ),
				array(
					'status' => 502,
					/* translators: %s: the host the gateway wanted to redirect the browser to. */
					'detail' => sprintf( __( 'This payment method wants to open %s in a browser, which the POS cannot follow.', 'woocommerce-pos' ), $elsewhere ),
				)
			);
		}
		if ( $malformed ) {
			// The listener saw money move; a bad return value does not un-take it.
			Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d: %s paid the order but returned %s; recording anyway.', $attempt_id, $order->get_id(), $descriptor['id'], $threw ? get_class( $threw ) : 'a non-success result' ) );
		}
		$order = wc_get_order( $order->get_id() );
		$row   = null;
		$verb  = $descriptor['fields']['verb'] ?? array(
			'kind' => 'take',
			'label' => $descriptor['title'],
		);
		if ( $paid ) {
			self::clear_stamp( $order );
			$row = Webview_Passthrough::mint(
				$order,
				array(
					'id' => $attempt_id,
					'source' => 'app',
					'capture_mode' => 'gateway',
					'method_id' => $descriptor['id'],
					'provider_refs' => array(
						'transaction_id' => '' !== $order->get_transaction_id() ? $order->get_transaction_id() : null,
					),
					'cashier_id' => get_current_user_id(),
				)
			);
		} else {
			$destination = null === $destination ? null : sanitize_text_field( $destination );
			$order->update_meta_data(
				self::STAMP_META_KEY,
				array(
					'method_id' => $descriptor['id'],
					'destination' => $destination,
					'attempt_id' => $attempt_id,
					'sent_at_gmt' => gmdate( 'c' ),
					'cashier_id' => get_current_user_id(),
				)
			);
			self::remember_attempt( $order, $history, $attempt_id, 'sent' );
			/* translators: 1: the gateway's verb label (e.g. "Send invoice"), 2: the destination the cashier entered. */
			$order->add_order_note( sprintf( __( 'Sent via POS: %1$s (%2$s)', 'woocommerce-pos' ), $verb['label'], (string) $destination ) );
			$order->save();
		}
		$outcome = $paid ? 'recorded' : 'sent';
		Logger::log( sprintf( 'WCPOS gateway attempt %s on order #%d: %s via %s by cashier #%d (verb %s)', $attempt_id, $order->get_id(), $outcome, $descriptor['id'], get_current_user_id(), $verb['kind'] ) );
		return array(
			'outcome' => $outcome,
			'payment' => $row,
			'order' => $ledger->summary( $order ),
		);
	}

	/**
	 * Cancel a sent outcome without calling the gateway.
	 *
	 * @param WC_Order $order      Order object.
	 * @param array    $descriptor Method descriptor.
	 * @param string   $attempt_id The sent attempt the till means to undo.
	 * @param string   $reason     Cashier reason.
	 * @return array|WP_Error
	 */
	public static function cancel( WC_Order $order, array $descriptor, string $attempt_id, string $reason ) {
		$rows  = Ledger::instance()->read( $order );
		$stamp = self::read_stamp( $order );
		if ( ! $stamp || array_filter( $rows, static fn( $row ) => in_array( $row['status'], Ledger::COUNTING_STATUSES, true ) ) ) {
			return new WP_Error( 'wcpos_invalid_transition', __( 'This invoice cannot be cancelled.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( ( $stamp['method_id'] ?? null ) !== $descriptor['id'] ) {
			return new WP_Error( 'wcpos_invalid_transition', __( 'The invoice was sent by a different payment method.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( strtolower( $attempt_id ) !== ( $stamp['attempt_id'] ?? null ) ) {
			// A stale cancel (an older send, or another till's view) must not undo a newer send.
			return new WP_Error( 'wcpos_payment_conflict', __( 'A newer invoice has been sent for this order.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		self::clear_stamp( $order );
		// The history outlives the stamp: a late retry of this send must never send again.
		self::remember_attempt( $order, self::read_attempts( $order ), strtolower( $attempt_id ), 'cancelled' );
		/* translators: 1: the payment method title, 2: the cashier's reason. */
		$order->update_status( 'pos-open', trim( sprintf( __( 'Cancelled via POS: %1$s. %2$s', 'woocommerce-pos' ), $descriptor['title'], $reason ) ) );
		return array( 'order' => Ledger::instance()->summary( $order ) );
	}

	/**
	 * Read awaiting-customer state.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function read_stamp( WC_Order $order ): ?array {
		$stamp = $order->get_meta( self::STAMP_META_KEY );
		return is_array( $stamp ) ? $stamp : null;
	}

	/**
	 * Remove the stamp; the caller owns saving.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function clear_stamp( WC_Order $order ): void {
		$order->delete_meta_data( self::STAMP_META_KEY );
	}

	/** Clear obsolete stamps on terminal Woo transitions. */
	public static function register_hooks(): void {
		add_action(
			'woocommerce_order_status_changed',
			static function ( $id, $from, $to, $order ): void {
				if ( self::read_stamp( $order ) && in_array( $to, array_merge( wc_get_is_paid_statuses(), array( 'cancelled', 'refunded', 'trash' ) ), true ) ) {
					self::clear_stamp( $order );
					$order->save();
				}
			},
			10,
			4
		);
	}

	/**
	 * The host a gateway's redirect leaves the store for, or null when it stays on this site.
	 *
	 * A normal gateway returns the order-received URL (any path on this site is fine); a hosted
	 * checkout returns the provider's page, which only a browser can follow.
	 *
	 * @param mixed $redirect The gateway result's redirect, if any.
	 */
	private static function redirect_host_elsewhere( $redirect ): ?string {
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			return null;
		}
		$host = wp_parse_url( $redirect, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return null; // A relative path stays on this site.
		}
		// Both of the site's hosts count as "here": a gateway may build its return URL from
		// either, and they differ on sites that serve WordPress from a subdomain.
		foreach ( array( home_url(), site_url() ) as $here ) {
			if ( 0 === strcasecmp( $host, (string) wp_parse_url( $here, PHP_URL_HOST ) ) ) {
				return null;
			}
		}
		return $host;
	}

	/**
	 * Put the order's previous payment method back after an attempt that wrote nothing else.
	 *
	 * @param WC_Order   $order    Order object (the route's instance; re-read before use).
	 * @param array|null $previous `[ method id, title ]` as they were before this attempt; null when never changed.
	 */
	private static function restore_method( WC_Order $order, ?array $previous ): void {
		$fresh = null === $previous ? null : wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof WC_Order ) {
			return;
		}
		$fresh->set_payment_method( (string) $previous[0] );
		$fresh->set_payment_method_title( (string) $previous[1] );
		$fresh->save();
	}

	/**
	 * The order's attempt history: attempt id → `sent` | `cancelled`. Never cleared with the stamp.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<string, string>
	 */
	public static function read_attempts( WC_Order $order ): array {
		$history = $order->get_meta( self::ATTEMPTS_META_KEY );
		return is_array( $history ) ? array_filter( $history, 'is_string' ) : array();
	}

	/**
	 * Record an attempt's fate; the caller owns saving.
	 *
	 * @param WC_Order $order      Order object.
	 * @param array    $history    The history as read.
	 * @param string   $attempt_id Attempt id (lowercase).
	 * @param string   $fate       `sent` or `cancelled`.
	 */
	private static function remember_attempt( WC_Order $order, array $history, string $attempt_id, string $fate ): void {
		unset( $history[ $attempt_id ] );
		$history[ $attempt_id ] = $fate;
		$order->update_meta_data( self::ATTEMPTS_META_KEY, array_slice( $history, - self::ATTEMPTS_CAP, null, true ) );
	}

	/**
	 * Read and clear the error notices a gateway left; safe without a session.
	 *
	 * @return array<int, array{notice: string, data: array}>
	 */
	private static function take_error_notices(): array {
		if ( ! WC()->session instanceof \WC_Session ) {
			return array();
		}
		$notices = wc_get_notices( 'error' );
		wc_clear_notices();
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Enforce the declared schema before the gateway sees anything: required, value
	 * types and select membership. The gateway's own validate_fields() stays the truth
	 * for meaning; this only stops a client from skipping what the descriptor declared.
	 *
	 * @param array $components Normalized components.
	 * @param array $values     Submitted values.
	 * @return array<string, string> Errors keyed by component id.
	 */
	private static function validate_values( array $components, array $values ): array {
		$errors = array();
		foreach ( $components as $component ) {
			if ( 'note' === $component['component'] ) {
				continue;
			}
			$id    = $component['id'];
			$value = $values[ $id ] ?? null;
			if ( 'checkbox' === $component['component'] ) {
				if ( null !== $value && ! is_bool( $value ) ) {
					$errors[ $id ] = __( 'This field must be true or false.', 'woocommerce-pos' );
				}
				continue;
			}
			if ( null !== $value && ! is_string( $value ) ) {
				$errors[ $id ] = __( 'This field must be text.', 'woocommerce-pos' );
				continue;
			}
			if ( ! empty( $component['required'] ) && ( null === $value || '' === trim( $value ) ) ) {
				$errors[ $id ] = __( 'This field is required.', 'woocommerce-pos' );
				continue;
			}
			if ( 'select' === $component['component'] && null !== $value && '' !== $value && ! in_array( $value, wp_list_pluck( $component['options'], 'value' ), true ) ) {
				$errors[ $id ] = __( 'Choose one of the listed options.', 'woocommerce-pos' );
			}
		}
		return $errors;
	}

	/**
	 * The per-component refusal.
	 *
	 * @param array $errors Errors keyed by component id, `_form` for the rest.
	 */
	private static function invalid_fields( array $errors ): WP_Error {
		return new WP_Error(
			'wcpos_fields_invalid',
			__( 'Check the payment fields.', 'woocommerce-pos' ),
			array(
				'status' => 400,
				'errors' => $errors,
			)
		);
	}
}
