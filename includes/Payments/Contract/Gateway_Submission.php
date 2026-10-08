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
	 * @param string   $attempt_id Attempt UUID.
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
		$attempt_id = strtolower( $attempt_id );
		$ledger = Ledger::instance();
		$rows = $ledger->read( $order );
		foreach ( $rows as $row ) {
			if ( ( $row['provider_refs']['attempt_id'] ?? null ) === $attempt_id ) {
				return array(
					'outcome' => 'recorded',
					'payment' => $row,
					'order' => $ledger->summary( $order ),
				);
			}
		}
		if ( ( self::read_stamp( $order )['attempt_id'] ?? null ) === $attempt_id ) {
			return array(
				'outcome' => 'sent',
				'payment' => null,
				'order' => $ledger->summary( $order ),
			);
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
		$components = $descriptor['fields']['components'] ?? array();
		$ids = array();
		$required = null;
		$destination = null;
		$has_destination = false;
		foreach ( $components as $component ) {
			if ( 'note' === $component['component'] ) {
				continue;
			}
			$id = $component['id'];
			$ids[] = $id;
			unset( $_POST[ $id ] );
			$value = $values[ $id ] ?? null;
			if ( 'checkbox' === $component['component'] ? true === $value : is_string( $value ) ) {
				$_POST[ $id ] = 'checkbox' === $component['component'] ? '1' : $value;
			}
			if ( null === $required && ! empty( $component['required'] ) && empty( $value ) ) {
				$required = $id;
			}
			if ( ! $has_destination && 'field' === $component['component'] && in_array( $component['input'], array( 'email', 'tel' ), true ) ) {
				$destination = is_string( $value ) ? $value : null;
				$has_destination = true;
			}
		}
		$paid = false;
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
		try {
			$gateway->validate_fields();
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			if ( $notices ) {
				$errors = array();
				foreach ( $notices as $notice ) {
					$id = in_array( $notice['data']['id'] ?? null, $ids, true ) ? $notice['data']['id'] : $required;
					if ( null === $id ) {
						$errors['_form'][] = wp_strip_all_tags( $notice['notice'] );
					} else {
						$errors[ $id ] = wp_strip_all_tags( $notice['notice'] );
					}
				}
				return new WP_Error(
					'wcpos_fields_invalid',
					__( 'Check the payment fields.', 'woocommerce-pos' ),
					array(
						'status' => 400,
						'errors' => $errors,
					)
				);
			}
			add_action( 'woocommerce_payment_complete', $complete );
			add_action( 'woocommerce_order_status_changed', $status_changed, 10, 3 );
			self::$submitting_order_id = $order->get_id();
			$result = $gateway->process_payment( $order->get_id() );
		} catch ( \Throwable $throwable ) {
			$result = null;
		} finally {
			self::$submitting_order_id = 0;
			remove_action( 'woocommerce_payment_complete', $complete );
			remove_action( 'woocommerce_order_status_changed', $status_changed, 10 );
			foreach ( $ids as $id ) {
				unset( $_POST[ $id ] );
			}
		}
		$notices = wc_get_notices( 'error' );
		wc_clear_notices();
		if ( ! is_array( $result ) || 'success' !== ( $result['result'] ?? null ) ) {
			return new WP_Error(
				'wcpos_provider_error',
				__( 'The payment provider failed.', 'woocommerce-pos' ),
				array(
					'status' => 502,
					'detail' => implode( ' ', wp_list_pluck( $notices, 'notice' ) ),
				)
			);
		}
		$order = wc_get_order( $order->get_id() );
		$row = null;
		$verb = $descriptor['fields']['verb'] ?? array(
			'kind' => 'take',
			'label' => $descriptor['title'],
		);
		if ( $paid ) {
			self::clear_stamp( $order );
			$row = Webview_Passthrough::mint(
				$order,
				array(
					'source' => 'app',
					'capture_mode' => 'gateway',
					'method_id' => $descriptor['id'],
					'provider_refs' => array(
						'transaction_id' => '' !== $order->get_transaction_id() ? $order->get_transaction_id() : null,
						'attempt_id' => $attempt_id,
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
	 * @param string   $reason     Cashier reason.
	 * @return array|WP_Error
	 */
	public static function cancel( WC_Order $order, array $descriptor, string $reason ) {
		$rows = Ledger::instance()->read( $order );
		if ( ! self::read_stamp( $order ) || array_filter( $rows, static fn( $row ) => in_array( $row['status'], Ledger::COUNTING_STATUSES, true ) ) ) {
			return new WP_Error( 'wcpos_invalid_transition', __( 'This invoice cannot be cancelled.', 'woocommerce-pos' ), array( 'status' => 409 ) );
		}
		self::clear_stamp( $order );
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
}
