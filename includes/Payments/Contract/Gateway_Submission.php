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
use WCPOS\WooCommercePOS\Services\Stock_Validator;
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
	 * @param WC_Order           $order      Order object.
	 * @param array              $descriptor Method descriptor.
	 * @param string             $attempt_id Attempt UUID; becomes the row id when money is recorded.
	 * @param array              $values     Declared field values.
	 * @param Money_Command|null $command The till's command; its entry rides the attempt's saves (a replay never reaches here).
	 * @return array|WP_Error
	 * @throws Ledger_Persistence_Exception When the attempt cannot be persisted before dispatch; or after it, when the paid row,
	 *                                      the `settled` write or the `sent` write does not land (the attempt stays stranded).
	 */
	public static function submit( WC_Order $order, array $descriptor, string $attempt_id, array $values, ?Money_Command $command = null ) {
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
		// A new command naming a known attempt answers its fate (a paid one `paid`); Command_Log::run() keeps its entry.
		if ( $recorded && 'paid' !== ( $history[ $attempt_id ] ?? null ) ) {
			return array(
				'outcome' => 'recorded',
				'payment' => $recorded,
				'order' => $ledger->summary( $order ),
			);
		}
		// A paid attempt is terminal: the gateway took the money, so it is never dispatched again.
		if ( in_array( $history[ $attempt_id ] ?? null, array( 'sent', 'settled', 'paid' ), true ) ) {
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
			// Prevent-overselling holds the sale's stock before the gateway can take money for it, as record and
			// intent do (§7.5). A definite failure or a refused redirect undoes the attempt, and that write releases it.
			$held = Stock_Validator::instance()->validate_checkout( $order );
			if ( is_wp_error( $held ) ) {
				return $held;
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
			// Persist the attempt before external money can move (rule 7): one held write of the attempt, the
			// command's entry and the method, read back. A failed ledger mint after dispatch must not redispatch.
			$ledger->write(
				$order,
				static function ( Ledger_Draft $draft ) use ( $attempt_id, $gateway, $descriptor ): array {
					$draft->attempt( $attempt_id, 'sent' );
					$draft->order()->set_payment_method( $gateway->id );
					$draft->order()->set_payment_method_title( (string) $descriptor['title'] );
					return array( 'outcome' => 'sent' );
				},
				Ledger::MODE_HOLD,
				$command
			);
			// From here the gateway's payment_complete() meets only listeners that defer to this request (is_submitting()).
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
		// What the gateway's hooks deferred for this order lands before the outcome is read from storage.
		Ledger::flush( $order->get_id() );
		$command_id = null === $command ? null : $command->id();
		if ( $threw instanceof Ledger_Persistence_Exception && ! $dispatch_started ) {
			// Nothing was dispatched: neither the attempt nor its entry may stand, so the retry dispatches. A retryable conflict.
			self::undo( $order, $previous_method, $attempt_id, null, $command_id );
			throw $threw;
		}
		$notices   = self::take_error_notices();
		$malformed = ! is_array( $result ) || 'success' !== ( $result['result'] ?? null );
		if ( $malformed && ! $paid ) {
			// A definite failure (or a throw before dispatch) closes the attempt; a throw after dispatch stays unresolved.
			if ( $threw && $dispatch_started ) {
				self::undo( $order, $previous_method );
			} elseif ( null !== $previous_method ) {
				// A throw before anything was written (inside validate_fields()) has nothing to undo.
				self::undo( $order, $previous_method, $attempt_id, 'cancelled', $command_id );
			}
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
			self::undo( $order, $previous_method, $attempt_id, 'cancelled', $command_id );
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
			// Staged on the order the mint carries into its write (S1, read back with the row): a retry after a
			// later step failed answers `recorded`.
			self::clear_stamp( $order );
			if ( null !== $command ) {
				Command_Log::remember( $order, $command->id(), Command_Log::entry( $command->fp(), $command->kind(), $attempt_id, 'recorded' ) );
			}
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
			// The gateway's paid transition ran in its own save, so the row's write had no S2 to run; this write's S3-heal
			// appends the completion at the command's origin (the observer left the dispatch's completion to it).
			$order = $ledger->write( $order, null, Ledger::MODE_AUTO, $command )->order;
		} elseif ( $settled ) {
			try {
				$order = $ledger->write(
					$order,
					static function ( Ledger_Draft $draft ) use ( $attempt_id ): array {
						self::supersede_sent( $draft, $attempt_id );
						// Not awaiting the customer: no stamp, and a stale one from an earlier send goes.
						self::clear_stamp( $draft->order() );
						$draft->attempt( $attempt_id, 'settled' );
						return array( 'outcome' => 'settled' );
					},
					Ledger::MODE_HOLD,
					$command
				)->order;
			} catch ( Ledger_Persistence_Exception $error ) {
				self::stranded( $order, $attempt_id, $descriptor['title'], $error );
				throw $error;
			}
			/* translators: 1: the gateway's verb label (e.g. "Send invoice"), 2: the order status the merchant stored for this gateway. */
			$order->add_order_note( sprintf( __( 'Settled via POS: %1$s on %2$s', 'woocommerce-pos' ), $verb['label'], wc_get_order_status_name( $settled_status ) ) );
		} else {
			$destination = null === $destination ? null : sanitize_text_field( $destination );
			$stamp       = array(
				'method_id' => $descriptor['id'],
				'destination' => $destination,
				'attempt_id' => $attempt_id,
				'sent_at_gmt' => gmdate( 'c' ),
				'cashier_id' => get_current_user_id(),
			);
			// A callback that completes the order in a later request still names the register that sent it.
			if ( null !== $command && $command->origin()->provenance() ) {
				$stamp['provenance'] = $command->origin()->provenance();
			}
			try {
				$order = $ledger->write(
					$order,
					static function ( Ledger_Draft $draft ) use ( $attempt_id, $stamp ): void {
						self::supersede_sent( $draft, $attempt_id );
						$draft->order()->update_meta_data( self::STAMP_META_KEY, $stamp );
						$draft->attempt( $attempt_id, 'sent' );
					},
					Ledger::MODE_HOLD
				)->order;
			} catch ( Ledger_Persistence_Exception $error ) {
				self::stranded( $order, $attempt_id, $descriptor['title'], $error );
				throw $error;
			}
			/* translators: 1: the gateway's verb label (e.g. "Send invoice"), 2: the destination the cashier entered. */
			$order->add_order_note( sprintf( __( 'Sent via POS: %1$s (%2$s)', 'woocommerce-pos' ), $verb['label'], (string) $destination ) );
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
	 * The gateway answered but its outcome's write did not land. The outcome existed only in this request and storage
	 * holds the attempt `sent`, so a replay cannot derive it: the attempt stays stranded, staff are told, cancel() recovers.
	 *
	 * @param WC_Order                     $order      Order object.
	 * @param string                       $attempt_id Attempt id.
	 * @param string                       $title      Payment method title.
	 * @param Ledger_Persistence_Exception $error      The failed write.
	 */
	private static function stranded( WC_Order $order, string $attempt_id, string $title, Ledger_Persistence_Exception $error ): void {
		Logger::error( sprintf( 'WCPOS gateway attempt %s on order #%d: %s answered, but its outcome was not stored: %s', $attempt_id, $order->get_id(), $title, $error->getMessage() ), array( 'order_id' => $order->get_id() ) );
		/* translators: 1: attempt id, 2: payment method title. */
		$order->add_order_note( sprintf( __( 'WCPOS payment attempt %1$s: %2$s answered, but the POS could not record it. Check the order with the provider, then cancel the attempt at the POS.', 'woocommerce-pos' ), $attempt_id, $title ) );
	}

	/**
	 * Resolve earlier sends when a new submission replaces their invoice; staged on the draft, in place.
	 *
	 * @param Ledger_Draft $draft   The write's draft.
	 * @param string       $current The attempt this submission dispatched.
	 */
	private static function supersede_sent( Ledger_Draft $draft, string $current ): void {
		$history = self::read_attempts( $draft->order() );
		$changed = false;
		foreach ( $history as $id => $state ) {
			if ( 'sent' === $state && $current !== $id ) {
				$history[ $id ] = 'superseded';
				$changed        = true;
				// Gateway capture has no invoice-cancellation operation (unlike provider void).
				Logger::warning( sprintf( 'WCPOS gateway attempt %s on order #%d superseded; invoice cancellation is unavailable for gateway submissions.', $id, $draft->order()->get_id() ) );
			}
		}
		if ( $changed ) {
			$draft->order()->update_meta_data( self::ATTEMPTS_META_KEY, $history );
		}
	}

	/**
	 * Cancel a sent outcome without calling the gateway.
	 *
	 * @param WC_Order           $order      Order object.
	 * @param array              $descriptor Method descriptor.
	 * @param string             $attempt_id The sent attempt the till means to undo.
	 * @param string             $reason     Cashier reason.
	 * @param Money_Command|null $command The till's command; its entry rides the cancel's save.
	 * @return array|WP_Error
	 * @throws Ledger_Persistence_Exception When the stamp, the attempt's fate or the entry did not reach storage.
	 */
	public static function cancel( WC_Order $order, array $descriptor, string $attempt_id, string $reason, ?Money_Command $command = null ) {
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
		$attempt_id = strtolower( $attempt_id );
		// A stranded attempt on the status the gateway settles on: the sale it settled is not reopened.
		$settled = $stranded && $order->has_status( ( new Gateway_Contract() )->get_settled_order_status( $descriptor['id'] ) );
		/* translators: 1: the payment method title, 2: the cashier's reason. */
		$note    = trim( sprintf( __( 'Cancelled via POS: %1$s. %2$s', 'woocommerce-pos' ), $descriptor['title'], $reason ) );
		$written = null;
		try {
			$written = Ledger::instance()->write(
				$order,
				static function ( Ledger_Draft $draft ) use ( $attempt_id, $note, $settled ): array {
					self::clear_stamp( $draft->order() );
					// The history outlives the stamp: a late retry of this send must never send again.
					$draft->attempt( $attempt_id, 'cancelled' );
					if ( ! $settled ) {
						$draft->order()->set_status( 'pos-open', $note );
					}
					return array();
				},
				Ledger::MODE_HOLD,
				$command
			);
		} catch ( Ledger_Persistence_Exception $error ) {
			Logger::error( sprintf( 'WCPOS gateway attempt %s on order #%d: the cancel was not stored whole: %s', $attempt_id, $order->get_id(), $error->getMessage() ), array( 'order_id' => $order->get_id() ) );
		}
		// The write reads back the attempt, the entry and the status; the stamp's removal is checked here.
		if ( null === $written || null !== self::read_stamp( $written->order ) ) {
			if ( null !== $written ) {
				Logger::error( sprintf( 'WCPOS gateway attempt %s on order #%d: the cancel was not stored whole: the stamp stands.', $attempt_id, $order->get_id() ), array( 'order_id' => $order->get_id() ) );
			}
			if ( null !== $command ) {
				// Withdrawn so the retry re-enters cancel() rather than replaying an incomplete one.
				self::undo( $order, null, null, null, $command->id() );
			}
			throw new Ledger_Persistence_Exception( 'WCPOS gateway cancel was not persisted.' );
		}
		if ( $settled ) {
			$written->order->add_order_note( $note );
		}
		return array( 'order' => Ledger::instance()->summary( $written->order ) );
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
	 * A save moving a stamped order to a cancelled, refunded or trashed status carries the stamp's removal itself,
	 * so nothing saves again inside its status hooks. A paid status keeps it: the completion's S3 reads its origin
	 * from the stamp and clears it.
	 *
	 * @param mixed $order Order about to be saved.
	 */
	public static function clear_obsolete_stamp( $order ): void {
		$to = $order instanceof WC_Order ? ( $order->get_changes()['status'] ?? null ) : null;
		if ( null !== $to && self::read_stamp( $order ) && in_array( $to, array( 'cancelled', 'refunded', 'trash' ), true ) ) {
			self::clear_stamp( $order );
		}
	}

	/**
	 * A sent attempt a callback or the merchant marked paid with no row for it (no gateway minted one): staged on
	 * the completion write's draft as its captured row, the attempt id as the row id, so the completion names the
	 * payment and the write marks the attempt paid. The row builder is the passthrough's.
	 *
	 * @param Ledger_Draft $d The completion write's draft.
	 * @return bool Whether a row was staged; false staged nothing.
	 */
	public static function resolve_paid( Ledger_Draft $d ): bool {
		$order   = $d->order();
		$stamp   = self::read_stamp( $order ) ?? array();
		$attempt = strtolower( (string) ( $stamp['attempt_id'] ?? '' ) );
		$sent    = wp_is_uuid( $attempt ) && 'sent' === ( self::read_attempts( $order )[ $attempt ] ?? null );
		// As the passthrough's mint: a zero total took no money, so it has no row.
		if ( ! $sent || (float) $order->get_total() <= 0 || ! Completion_Stamp::is_paid( $order ) || array_filter( $d->rows(), static fn( $row ) => 'captured' === ( $row['status'] ?? '' ) ) ) {
			return false;
		}
		$transaction = $order->get_transaction_id();
		$d->put(
			Webview_Passthrough::row(
				$order,
				array(
					'id'            => $attempt,
					'source'        => 'app',
					'capture_mode'  => 'gateway',
					'method_id'     => '' !== (string) ( $stamp['method_id'] ?? '' ) ? (string) $stamp['method_id'] : $order->get_payment_method(),
					'provider_refs' => array( 'transaction_id' => '' !== $transaction ? $transaction : null ),
					'cashier_id'    => (int) ( $stamp['cashier_id'] ?? 0 ),
				)
			)
		);
		return true;
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
	 * Undo what an attempt stored that must not stand, in one held write read back: the previous payment method,
	 * the attempt closed (or withdrawn, when nothing was dispatched), the command's entry removed.
	 *
	 * @param WC_Order    $order      Order object.
	 * @param array|null  $previous   `[ method id, title ]` as they were before this attempt; null leaves the method.
	 * @param string|null $attempt_id The attempt to close or withdraw; throws after dispatch remain unresolved.
	 * @param string|null $fate       `cancelled` after a definite failure; null withdraws the attempt from the history.
	 * @param string|null $command_id The command whose entry goes: a retry meets the 409 (closed) or dispatches (withdrawn).
	 */
	private static function undo( WC_Order $order, ?array $previous, ?string $attempt_id = null, ?string $fate = null, ?string $command_id = null ): void {
		try {
			Ledger::instance()->write(
				$order,
				static function ( Ledger_Draft $draft ) use ( $previous, $attempt_id, $fate, $command_id ): void {
					$fresh = $draft->order();
					if ( null !== $previous ) {
						$fresh->set_payment_method( (string) $previous[0] );
						$fresh->set_payment_method_title( (string) $previous[1] );
					}
					$history = self::read_attempts( $fresh );
					if ( null !== $attempt_id && null !== $fate ) {
						$draft->attempt( $attempt_id, $fate );
					} elseif ( null !== $attempt_id && isset( $history[ $attempt_id ] ) ) {
						unset( $history[ $attempt_id ] );
						$fresh->update_meta_data( self::ATTEMPTS_META_KEY, $history );
					}
					if ( null !== $command_id ) {
						Command_Log::forget( $fresh, $command_id );
					}
				},
				Ledger::MODE_HOLD
			);
		} catch ( Ledger_Persistence_Exception $error ) {
			$stored = wc_get_order( $order->get_id() );
			$stored->read_meta_data( true );
			$state = self::read_attempts( $stored )[ (string) $attempt_id ] ?? null;
			$fated = null === $attempt_id || $fate === $state;
			$gone  = null === $command_id || null === Command_Log::find( $stored, array(), $command_id );
			Logger::error( sprintf( 'WCPOS gateway attempt %s on order #%d: its cleanup did not land (attempt %s: %s, command entry removed: %s): %s; the attempt needs manual reconciliation before this method is retried on the order.', (string) $attempt_id, $order->get_id(), $fate ?? 'withdrawn', wp_json_encode( $fated ), wp_json_encode( $gone ), $error->getMessage() ), array( 'order_id' => $order->get_id() ) );
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
