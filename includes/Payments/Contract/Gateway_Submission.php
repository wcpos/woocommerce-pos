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
use WCPOS\WooCommercePOS\Payments\Gateway_Contract;
use WCPOS\WooCommercePOS\Payments\Paid_Date_Suppression;
use WP_Error;

/** Reads what a Woo gateway did, within the route's existing order lock. */
class Gateway_Submission {
	public const STAMP_META_KEY = '_wcpos_awaiting_customer';
	/** Every sent, settled or cancelled attempt for the order's life, so a delayed retry never sends again. */
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
	 * @param array    $command    The till's command (`id`, `fp`, `kind`); its entry rides the attempt's saves.
	 * @return array|WP_Error
	 * @throws Ledger_Persistence_Exception When the attempt cannot be persisted before dispatch, or a paid row cannot be recorded.
	 */
	public static function submit( WC_Order $order, array $descriptor, string $attempt_id, array $values, array $command = array() ) {
		if ( 'gateway' !== $descriptor['capture']['mode'] ) {
			return new WP_Error( 'wcpos_capture_mode_unsupported', __( 'Payment capture mode is unsupported.', 'woocommerce-pos' ), array( 'status' => 501 ) );
		}
		if ( ! wp_is_uuid( $attempt_id ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Attempt ID must be a UUID.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		if ( ! wcpos_is_pos_order( $order ) ) {
			return new WP_Error( 'wcpos_invalid_transition', __( 'Only POS orders take a gateway submission.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		$attempt_id = strtolower( $attempt_id );
		$ledger     = Ledger::instance();
		$rows       = $ledger->read( $order );
		// A recorded attempt is the row itself: the attempt id is the client-minted row id.
		$history  = self::read_attempts( $order );
		$recorded = $ledger->find( $order, $attempt_id );
		$stored   = empty( $command['id'] ) ? null : Command_Log::find( $order, array(), $command['id'] );
		// A paid attempt answers `paid`, except to the command that first answered `recorded` (ADR 0040 rule 5).
		if ( $recorded && ( 'paid' !== ( $history[ $attempt_id ] ?? null ) || 'recorded' === ( $stored['outcome'] ?? null ) ) ) {
			self::answered( $order, $command, $attempt_id, 'recorded' );
			return array(
				'outcome' => 'recorded',
				'payment' => $recorded,
				'order' => $ledger->summary( $order ),
			);
		}
		// A paid attempt is terminal: the gateway took the money, so it is never dispatched again.
		if ( in_array( $history[ $attempt_id ] ?? null, array( 'sent', 'settled', 'paid' ), true ) ) {
			self::answered( $order, $command, $attempt_id, $history[ $attempt_id ] );
			return array(
				'outcome' => $history[ $attempt_id ],
				'payment' => null,
				'order' => $ledger->summary( $order ),
			);
		}
		if ( in_array( $history[ $attempt_id ] ?? null, array( 'cancelled', 'superseded' ), true ) ) {
			// The till undid or replaced this send; a late retry must not send again.
			return new WP_Error( 'wcpos_payment_conflict', __( 'This attempt was cancelled or superseded at the till.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		// The history no longer names the attempt (ATTEMPTS_CAP): the command map still answers it.
		if ( $stored ) {
			return array(
				'outcome' => $stored['outcome'],
				'payment' => null,
				'order' => $ledger->summary( $order ),
			);
		}
		// Only a NEW attempt needs the method enabled: a replay above answers what already
		// happened, even after the merchant has switched the method off.
		if ( empty( $descriptor['pos_enabled'] ) ) {
			return new WP_Error( 'wcpos_payment_method_disabled', __( 'Payment method is not enabled for the POS.', 'woocommerce-pos' ), array( 'status' => 403 ) );
		}
		// A new attempt needs an open sale; a replay above has already answered what happened (§4.2).
		$closed = $ledger->accepts_tender( $order );
		if ( $closed ) {
			return $closed;
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
		$dispatch_started = false;
		$threw           = null;
		$previous_method = null;
		// The status the merchant stored as THIS gateway's POS Order Status (§3.1 `settled`),
		// read once before the gateway runs and compared with the fresh order after it.
		$settled_status = ( new Gateway_Contract() )->get_settled_order_status( $descriptor['id'] );
		// Armed at PHP_INT_MAX so a third-party filter between priority 10 and here cannot hand
		// back the stored status while date_paid is empty; the rule is Paid_Date_Suppression.
		$suppress_date_paid = Paid_Date_Suppression::filter_for( $order->get_id(), $settled_status );
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
				// Without session initialisation the cart adds no shutdown cookie or saved-cart
				// hooks; without the persistent cart, a gateway's empty_cart() cannot delete the
				// cashier's own saved storefront cart.
				add_filter( 'woocommerce_cart_session_initialize', array( self::class, 'deny' ) );
				try {
					WC()->cart = new \WC_Cart();
				} finally {
					remove_filter( 'woocommerce_cart_session_initialize', array( self::class, 'deny' ) );
				}
			}
			add_filter( 'woocommerce_persistent_cart_enabled', array( self::class, 'deny' ) );
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
			if ( '' !== $settled_status && ! in_array( $settled_status, wc_get_is_paid_statuses(), true ) ) {
				// Armed before the method save so it covers every write inside the submit
				// window; WooCommerce only asks from set_status() on a real status change.
				add_filter( 'woocommerce_payment_complete_order_status', $suppress_date_paid, PHP_INT_MAX, 3 );
			}
			// Persist the attempt before external money can move: a failed ledger mint must not redispatch.
			self::remember_attempt( $order, $history, $attempt_id, 'sent' );
			self::remember_command( $order, $command, $attempt_id, 'sent' );
			$order->set_payment_method( $gateway->id );
			$order->set_payment_method_title( (string) $descriptor['title'] );
			Ledger::saving( $order->get_id(), fn() => $order->save() );
			$persisted = wc_get_order( $order->get_id() );
			$persisted->read_meta_data( true );
			$landed = self::read_attempts( $persisted );
			if ( 'sent' !== ( $landed[ $attempt_id ] ?? null ) || ! self::command_kept( $order->get_id(), $command, 'sent' ) ) {
				// One half did not land: nothing was dispatched, so neither the attempt nor its entry may stand.
				if ( isset( $landed[ $attempt_id ] ) ) {
					unset( $landed[ $attempt_id ] );
					$persisted->update_meta_data( self::ATTEMPTS_META_KEY, $landed );
				}
				if ( ! empty( $command['id'] ) ) {
					Command_Log::forget( $persisted, $command['id'] );
				}
				Ledger::saving( $persisted->get_id(), fn() => $persisted->save() );
				throw new Ledger_Persistence_Exception( 'WCPOS gateway attempt was not persisted.' );
			}
			add_action( 'woocommerce_payment_complete', $complete );
			add_action( 'woocommerce_order_status_changed', $status_changed, 10, 3 );
			self::$submitting_order_id = $order->get_id();
			$dispatch_started          = true;
			$result                    = $gateway->process_payment( $order->get_id() );
		} catch ( \Throwable $throwable ) {
			$result = null;
			$threw  = $throwable;
		} finally {
			self::$submitting_order_id = 0;
			remove_filter( 'woocommerce_persistent_cart_enabled', array( self::class, 'deny' ) );
			remove_filter( 'woocommerce_payment_complete_order_status', $suppress_date_paid, PHP_INT_MAX );
			remove_action( 'woocommerce_payment_complete', $complete );
			remove_action( 'woocommerce_order_status_changed', $status_changed, 10 );
			foreach ( $ids as $id ) {
				unset( $_POST[ $id ] );
			}
		}
		if ( $threw instanceof Ledger_Persistence_Exception && ! $dispatch_started ) {
			// Nothing was dispatched and nothing durable records the attempt: a retryable conflict.
			self::restore_method( $order, $previous_method, null );
			throw $threw;
		}
		$notices   = self::take_error_notices();
		$malformed = ! is_array( $result ) || 'success' !== ( $result['result'] ?? null );
		if ( $malformed && ! $paid ) {
			// A definite failure (or a throw before dispatch) closes the attempt; a throw after dispatch stays unresolved.
			self::restore_method( $order, $previous_method, $threw && $dispatch_started ? null : $attempt_id, $command['id'] ?? null );
			$detail = implode( ' ', wp_list_pluck( $notices, 'notice' ) );
			if ( $threw ) {
				// A gateway that throws instead of adding a notice still owes the operator a reason.
				$detail = '' === $detail ? $threw->getMessage() : $detail;
				Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d: %s threw %s: %s', $attempt_id, $order->get_id(), $descriptor['id'], get_class( $threw ), $threw->getMessage() ) );
			}
			if ( $threw && $dispatch_started ) {
				// The attempt stays `sent` with no stamp: tell staff where it stands and how to clear it.
				/* translators: 1: attempt id, 2: payment method title. */
				$order->add_order_note( sprintf( __( 'WCPOS payment attempt %1$s: %2$s failed after the payment was sent. Check with the provider whether money was taken, then cancel the attempt at the POS.', 'woocommerce-pos' ), $attempt_id, $descriptor['title'] ) );
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
			self::restore_method( $order, $previous_method, $attempt_id, $command['id'] ?? null );
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
		// No money observed, but the gateway landed the fresh order on the merchant's stored status
		// (§3.1 `settled`): the sale is finished at the till (BACS stored as Pending is "pay by
		// invoice"). The reader itself refuses the never-settled statuses (failed, cancelled,
		// refunded, checkout-draft, the parked POS ones).
		$settled = ! $paid && '' !== $settled_status && $order->has_status( $settled_status );
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
		} elseif ( $settled ) {
			$history = self::supersede_sent( $order, $history );
			// Not awaiting the customer: no stamp, and a stale one from an earlier send goes.
			self::clear_stamp( $order );
			self::remember_attempt( $order, $history, $attempt_id, 'settled' );
			self::remember_command( $order, $command, $attempt_id, 'settled' );
			/* translators: 1: the gateway's verb label (e.g. "Send invoice"), 2: the order status the merchant stored for this gateway. */
			$order->add_order_note( sprintf( __( 'Settled via POS: %1$s on %2$s', 'woocommerce-pos' ), $verb['label'], wc_get_order_status_name( $settled_status ) ) );
			Ledger::saving( $order->get_id(), fn() => $order->save() );
			self::verify_command( $order->get_id(), $command, $attempt_id, 'settled' );
		} else {
			$history     = self::supersede_sent( $order, $history );
			$destination = null === $destination ? null : sanitize_text_field( $destination );
			$stamp       = array(
				'method_id' => $descriptor['id'],
				'destination' => $destination,
				'attempt_id' => $attempt_id,
				'sent_at_gmt' => gmdate( 'c' ),
				'cashier_id' => get_current_user_id(),
			);
			// A callback that completes the order in a later request still names the register that sent it.
			if ( Completion_Stamp::provenance( $order->get_id() ) ) {
				$stamp['provenance'] = Completion_Stamp::provenance( $order->get_id() );
			}
			$order->update_meta_data( self::STAMP_META_KEY, $stamp );
			self::remember_attempt( $order, $history, $attempt_id, 'sent' );
			/* translators: 1: the gateway's verb label (e.g. "Send invoice"), 2: the destination the cashier entered. */
			$order->add_order_note( sprintf( __( 'Sent via POS: %1$s (%2$s)', 'woocommerce-pos' ), $verb['label'], (string) $destination ) );
			Ledger::saving( $order->get_id(), fn() => $order->save() );
		}
		if ( $paid ) {
			// The completion waited for this row (Completion_Stamp::awaits_mint()): stamp it now.
			Completion_Stamp::stamp( wc_get_order( $order->get_id() ) );
			if ( ! empty( $command['id'] ) ) {
				$recorded = wc_get_order( $order->get_id() );
				self::remember_command( $recorded, $command, $attempt_id, 'recorded' );
				Ledger::saving( $recorded->get_id(), fn() => $recorded->save() );
				self::verify_command( $order->get_id(), $command, $attempt_id, 'recorded' );
			}
		}
		$outcome = $paid ? 'recorded' : ( $settled ? 'settled' : 'sent' );
		Logger::log( sprintf( 'WCPOS gateway attempt %s on order #%d: %s via %s by cashier #%d (verb %s)', $attempt_id, $order->get_id(), $outcome, $descriptor['id'], get_current_user_id(), $verb['kind'] ) );
		return array(
			'outcome' => $outcome,
			'payment' => $row,
			'order' => $ledger->summary( $order ),
		);
	}

	/**
	 * Resolve earlier sends when a new submission replaces their invoice.
	 *
	 * @param WC_Order $order   Order object.
	 * @param array    $history Earlier attempts, excluding the current dispatch.
	 */
	private static function supersede_sent( WC_Order $order, array $history ): array {
		foreach ( $history as $id => $state ) {
			if ( 'sent' === $state ) {
				$history[ $id ] = 'superseded';
				// Gateway capture has no invoice-cancellation operation (unlike provider void).
				Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d superseded; invoice cancellation is unavailable for gateway submissions.', $id, $order->get_id() ) );
			}
		}
		return $history;
	}

	/**
	 * Cancel a sent outcome without calling the gateway.
	 *
	 * @param WC_Order $order      Order object.
	 * @param array    $descriptor Method descriptor.
	 * @param string   $attempt_id The sent attempt the till means to undo.
	 * @param string   $reason     Cashier reason.
	 * @param array    $command    The till's command; its entry rides the cancel's save.
	 * @return array|WP_Error
	 * @throws Ledger_Persistence_Exception When the stamp, the attempt's fate or the entry did not reach storage.
	 */
	public static function cancel( WC_Order $order, array $descriptor, string $attempt_id, string $reason, array $command = array() ) {
		$rows  = Ledger::instance()->read( $order );
		$stamp = self::read_stamp( $order );
		// A send whose gateway threw after dispatch never got its stamp; its history still says `sent`.
		// Accepted only where a stamp would still stand (register_hooks() clears it on these statuses).
		$stranded = ! $stamp && 'sent' === ( self::read_attempts( $order )[ strtolower( $attempt_id ) ] ?? null )
			&& ! $order->has_status( array( 'cancelled', 'refunded', 'trash' ) );
		if ( $stranded && ( $order->is_paid() || $order->get_date_paid( 'edit' ) ) && Money::minor( Ledger::instance()->paid( $rows ) ) < Money::minor( $order->get_total() ) ) {
			// The gateway paid but no row records it: cancelling would reopen the sale for a second charge.
			/* translators: %s: attempt id. */
			return new WP_Error( 'wcpos_invalid_transition', sprintf( __( 'Payment attempt %s may already have been paid by the gateway; reconcile the payment before cancelling it.', 'woocommerce-pos' ), strtolower( $attempt_id ) ), array( 'status' => 409 ) );
		}
		if ( ( ! $stamp && ! $stranded ) || array_filter( $rows, static fn( $row ) => in_array( $row['status'], Ledger::COUNTING_STATUSES, true ) ) ) {
			return new WP_Error( 'wcpos_invalid_transition', __( 'This invoice cannot be cancelled.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( $stamp && ( $stamp['method_id'] ?? null ) !== $descriptor['id'] ) {
			return new WP_Error( 'wcpos_invalid_transition', __( 'The invoice was sent by a different payment method.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		if ( $stamp && strtolower( $attempt_id ) !== ( $stamp['attempt_id'] ?? null ) ) {
			// A stale cancel (an older send, or another till's view) must not undo a newer send.
			return new WP_Error( 'wcpos_payment_conflict', __( 'A newer invoice has been sent for this order.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		self::clear_stamp( $order );
		// The history outlives the stamp: a late retry of this send must never send again.
		self::remember_attempt( $order, self::read_attempts( $order ), strtolower( $attempt_id ), 'cancelled' );
		self::remember_command( $order, $command, strtolower( $attempt_id ) );
		/* translators: 1: the payment method title, 2: the cashier's reason. */
		Ledger::saving( $order->get_id(), fn() => $order->update_status( 'pos-open', trim( sprintf( __( 'Cancelled via POS: %1$s. %2$s', 'woocommerce-pos' ), $descriptor['title'], $reason ) ) ) );
		// The cancel is three meta rows: it stands only when all three landed.
		$stored  = wc_get_order( $order->get_id() );
		$stored->read_meta_data( true );
		$cleared = null === self::read_stamp( $stored );
		$fated   = 'cancelled' === ( self::read_attempts( $stored )[ strtolower( $attempt_id ) ] ?? null );
		$kept    = self::command_kept( $order->get_id(), $command );
		if ( ! $cleared || ! $fated || ! $kept ) {
			if ( ! empty( $command['id'] ) ) {
				// Withdrawn so the retry re-enters cancel() rather than replaying an incomplete one.
				Command_Log::forget( $stored, $command['id'] );
				Ledger::saving( $stored->get_id(), fn() => $stored->save() );
			}
			Logger::error( sprintf( 'WCPOS gateway attempt %s on order #%d: the cancel was not stored whole (stamp cleared: %s, attempt cancelled: %s, command entry: %s).', strtolower( $attempt_id ), $order->get_id(), wp_json_encode( $cleared ), wp_json_encode( $fated ), wp_json_encode( $kept ) ), array( 'order_id' => $order->get_id() ) );
			throw new Ledger_Persistence_Exception( 'WCPOS gateway cancel was not persisted.' );
		}
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

	/**
	 * A filter callback only this class owns: removing it can never strip a site's own
	 * `__return_false` on the same hook.
	 */
	public static function deny(): bool {
		return false;
	}

	/** Clear obsolete stamps on terminal Woo transitions. */
	public static function register_hooks(): void {
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'clear_obsolete_stamp' ), 10, 1 );
	}

	/**
	 * A save moving a stamped order to a paid, cancelled, refunded or trashed status carries the stamp's
	 * removal itself, so nothing saves again inside its status hooks. A completion first remembers the
	 * provenance the stamp names (Completion_Stamp::memoise()).
	 *
	 * @param mixed $order Order about to be saved.
	 */
	public static function clear_obsolete_stamp( $order ): void {
		$to = $order instanceof WC_Order ? ( $order->get_changes()['status'] ?? null ) : null;
		if ( null === $to || ! self::read_stamp( $order ) || ! in_array( $to, array_merge( wc_get_is_paid_statuses(), array( 'cancelled', 'refunded', 'trash' ) ), true ) ) {
			return;
		}
		$completed = Completion_Stamp::completed_statuses( $order );
		if ( in_array( $to, $completed, true ) && ! in_array( $order->get_data()['status'] ?? '', $completed, true ) ) {
			Completion_Stamp::memoise( $order );
		}
		self::clear_stamp( $order );
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
	 * @param WC_Order    $order             Order object (the route's instance; re-read before use).
	 * @param array|null  $previous          `[ method id, title ]` as they were before this attempt; null when never changed.
	 * @param string|null $cancelled_attempt Attempt to close after a definite failure; throws after dispatch remain unresolved.
	 * @param string|null $command_id        The submit's command, whose entry goes with a closed attempt.
	 */
	private static function restore_method( WC_Order $order, ?array $previous, ?string $cancelled_attempt, ?string $command_id = null ): void {
		$fresh = null === $previous ? null : wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof WC_Order ) {
			return;
		}
		$fresh->set_payment_method( (string) $previous[0] );
		$fresh->set_payment_method_title( (string) $previous[1] );
		if ( null !== $cancelled_attempt ) {
			self::remember_attempt( $fresh, self::read_attempts( $fresh ), $cancelled_attempt, 'cancelled' );
			if ( null !== $command_id ) {
				// The attempt is terminal and the command stored nothing: a retry meets the 409, never a dispatch.
				Command_Log::forget( $fresh, $command_id );
			}
		}
		Ledger::saving( $fresh->get_id(), fn() => $fresh->save() );
		if ( null === $cancelled_attempt ) {
			return;
		}
		// The decline is the truth: the cleanup is two meta rows, so a half that did not land is written once more.
		for ( $pass = 0; ; ++$pass ) {
			$stored = wc_get_order( $order->get_id() );
			$stored->read_meta_data( true );
			$fated = 'cancelled' === ( self::read_attempts( $stored )[ $cancelled_attempt ] ?? null );
			$gone  = null === $command_id || null === Command_Log::find( $stored, array(), $command_id );
			if ( ( $fated && $gone ) || $pass > 0 ) {
				break;
			}
			self::remember_attempt( $stored, self::read_attempts( $stored ), $cancelled_attempt, 'cancelled' );
			if ( null !== $command_id ) {
				Command_Log::forget( $stored, $command_id );
			}
			Ledger::saving( $stored->get_id(), fn() => $stored->save() );
		}
		if ( ! $fated || ! $gone ) {
			Logger::error( sprintf( 'WCPOS gateway attempt %s on order #%d was declined, but its cleanup did not land (attempt cancelled: %s, command entry removed: %s); the attempt needs manual reconciliation before this method is retried on the order.', $cancelled_attempt, $order->get_id(), wp_json_encode( $fated ), wp_json_encode( $gone ) ), array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * The order's attempt history: attempt id → `sent` | `settled` | `paid` | `cancelled` | `superseded`. Never cleared with the stamp.
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
	 * @param string   $fate       `sent` (persisted before dispatch), `settled`, `cancelled` or `superseded`; `paid` is set by the ledger.
	 */
	private static function remember_attempt( WC_Order $order, array $history, string $attempt_id, string $fate ): void {
		unset( $history[ $attempt_id ] );
		$history[ $attempt_id ] = $fate;
		$order->update_meta_data( self::ATTEMPTS_META_KEY, array_slice( $history, - self::ATTEMPTS_CAP, null, true ) );
	}

	/**
	 * Keep the entry of a submit answered from the attempt history under a new command id: it
	 * changed nothing else, but a 2xx command id is never free for another body (spec §5.2).
	 *
	 * @param WC_Order $order      Order object.
	 * @param array    $command    The till's command.
	 * @param string   $attempt_id The attempt it names.
	 * @param string   $outcome    The outcome it answered.
	 * @throws Ledger_Persistence_Exception When the entry did not reach storage.
	 */
	private static function answered( WC_Order $order, array $command, string $attempt_id, string $outcome ): void {
		if ( empty( $command['id'] ) || Command_Log::find( $order, array(), $command['id'] ) ) {
			return;
		}
		self::remember_command( $order, $command, $attempt_id, $outcome );
		Ledger::saving( $order->get_id(), fn() => $order->save() );
		if ( ! self::command_kept( $order->get_id(), $command, $outcome ) ) {
			Logger::error( sprintf( 'WCPOS gateway attempt %s on order #%d: a replay under a new command id was not stored; nothing moved, its retry answers again.', $attempt_id, $order->get_id() ), array( 'order_id' => $order->get_id() ) );
			throw new Ledger_Persistence_Exception( 'WCPOS gateway command was not persisted.' );
		}
	}

	/**
	 * Whether storage holds the command's entry with its fingerprint (and outcome, when named).
	 *
	 * @param int         $order_id Order id.
	 * @param array       $command  The till's command; empty is always kept.
	 * @param string|null $outcome  The outcome the entry must hold.
	 */
	private static function command_kept( int $order_id, array $command, ?string $outcome = null ): bool {
		if ( empty( $command['id'] ) ) {
			return true;
		}
		$stored = wc_get_order( $order_id );
		$stored->read_meta_data( true );
		$kept = Command_Log::find( $stored, array(), $command['id'] );
		return $kept && $kept['fp'] === $command['fp'] && ( null === $outcome || $kept['outcome'] === $outcome );
	}

	/**
	 * After the money moved: an entry that did not land is logged and the answer refused (409).
	 *
	 * @param int    $order_id   Order id.
	 * @param array  $command    The till's command.
	 * @param string $attempt_id The attempt.
	 * @param string $outcome    The outcome it answered.
	 * @throws Ledger_Persistence_Exception When storage does not hold the entry.
	 */
	private static function verify_command( int $order_id, array $command, string $attempt_id, string $outcome ): void {
		if ( self::command_kept( $order_id, $command, $outcome ) ) {
			return;
		}
		Logger::error( sprintf( 'WCPOS gateway attempt %s on order #%d was %s, but its command entry was not stored; the attempt history still answers its replay by attempt id.', $attempt_id, $order_id, $outcome ), array( 'order_id' => $order_id ) );
		throw new Ledger_Persistence_Exception( 'WCPOS gateway command was not persisted.' );
	}

	/**
	 * Stage a submit's or cancel's command entry; the caller saves.
	 *
	 * @param WC_Order    $order      Order object.
	 * @param array       $command    The till's command; empty stores nothing.
	 * @param string      $attempt_id The attempt it names.
	 * @param string|null $outcome    A submit's wire outcome.
	 */
	private static function remember_command( WC_Order $order, array $command, string $attempt_id, ?string $outcome = null ): void {
		if ( ! empty( $command['id'] ) ) {
			Command_Log::remember( $order, $command['id'], Command_Log::entry( $command['fp'], $command['kind'], $attempt_id, $outcome ) );
		}
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
