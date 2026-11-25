<?php
/**
 * Received order template.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WooCommercePOS\Templates
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;
use WC_Order;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Attempts;
use WCPOS\WooCommercePOS\Payments\Contract\Declared_Fields;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger_Draft;
use WCPOS\WooCommercePOS\Payments\Gateway_Contract;
use WP_REST_Request;

/**
 * Received class.
 */
class Received {
	/**
	 * Order ID.
	 *
	 * @var int
	 */
	private $order_id;

	/**
	 * Constructor.
	 *
	 * @param int $order_id The order ID.
	 */
	public function __construct( int $order_id ) {
		$this->order_id = $order_id;

		add_filter( 'show_admin_bar', '__return_false' );
	}

	/**
	 * Fetch the order JSON via an internal REST request to the WCPOS endpoint.
	 *
	 * @param int $order_id The order ID.
	 *
	 * @return string|false JSON string or false on failure.
	 */
	public function get_order_json( int $order_id ) {
		// Grant POS access for this internal request so it passes both the
		// WC REST permission check and the POS access_woocommerce_pos gate.
		$grant_caps = function ( $allcaps ) {
			$allcaps['access_woocommerce_pos'] = true;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $grant_caps );
		add_filter( 'woocommerce_rest_check_permissions', '__return_true' );

		try {
			$request  = new WP_REST_Request( 'GET', '/wcpos/v1/orders/' . $order_id );
			$server   = rest_get_server();
			$response = $server->dispatch( $request );
			if ( $response->is_error() || $response->get_status() >= 400 ) {
				Logger::log( sprintf( 'Received order %d REST dispatch failed with status %d.', $order_id, $response->get_status() ) );
				return false;
			}
			$data     = $server->response_to_data( $response, true );
		} finally {
			remove_filter( 'user_has_cap', $grant_caps );
			remove_filter( 'woocommerce_rest_check_permissions', '__return_true' );
		}

		return wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP );
	}

	/**
	 * Get and display the received template.
	 */
	public function get_template(): void {
		try {
			$order = \wc_get_order( $this->order_id );

			if ( ! $order ) {
				wp_die( esc_html__( 'Sorry, this order is invalid.', 'woocommerce-pos' ) );
			}

			// Verify order key to prevent unauthenticated access.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Order key is the auth mechanism here, matching WooCommerce core behavior.
			$provided_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			if ( ! $provided_key || ! $order->key_is_valid( $provided_key ) ) {
				wp_die(
					esc_html__( 'Sorry, this order cannot be viewed. The order key is missing or invalid.', 'woocommerce-pos' ),
					/* translators: Short WCPOS UI label; keep concise. */
					esc_html__( 'Error', 'woocommerce-pos' ),
					array( 'response' => 403 )
				);
			}

			$order_json = $this->get_order_json( $order->get_id() );
			// Emit once the sale is settled: WooCommerce recognizes the order as paid, or
			// a POS order has reached the status the merchant configured for its gateway.
			// The second branch is what closes a "pay by invoice" sale that lands on
			// Pending payment, or a bank transfer on On hold — statuses is_paid() does not
			// admit by default. It is limited to POS orders: a web-checkout order reached
			// through this URL with its key must keep showing WooCommerce's own thank-you
			// page (bank details for an unpaid transfer), not a checkmark. An async gateway
			// configured for Completed that redirects while the order is still pending
			// matches neither branch, so it keeps waiting. A negative needs_payment() check
			// would be insufficient because zero-total failed orders do not need payment.
			// Keep the POS exclusions so parked carts never report success.
			//
			// Read the configured status only after get_order_json(): that call boots
			// the REST server, which constructs the API and, on the free plugin, installs
			// the option filter that keeps POS gateways to cash and card. Reading before
			// it would honour a stored BACS status the free plugin does not allow.
			$settled_status = wcpos_is_pos_order( $order )
				? ( new Gateway_Contract() )->get_settled_order_status( $order->get_payment_method() )
				: '';
			$order_settled  = $order->is_paid() || ( '' !== $settled_status && $order->has_status( $settled_status ) );
			$order_complete = $order_settled && ! \in_array( $order->get_status(), array( 'pos-open', 'pos-partial' ), true );

			if ( false === $order_json ) {
				$order_complete = false;
			}

			// A send-declaring Legacy gateway leaves the order unpaid and redirects here.
			// Recheck eligibility under the lock before writing the awaiting-customer stamp
			// (descriptor spec §3.2 / §4.2); reloads cannot double-stamp. Refresh the JSON even
			// when another request stamped it, then post wcpos-payment-sent. The cashier is
			// the order's _pos_user; older apps ignore the action as before. An earlier attempt
			// still in flight blocks the stamp, so the sweep keeps reconciling it.
			$order_sent = false;
			try {
				if ( false !== $order_json && ! $order_complete && $this->is_legacy_send( $order ) ) {
					$attempt_id   = wp_generate_uuid4();
					$outcome      = 'ineligible';
					$in_flight_id = '';
					Ledger::instance()->write(
						$order,
						// A false answer stages nothing, so early paths do not save; the ledger may run this twice, and the last run sets $outcome.
						static function ( Ledger_Draft $draft ) use ( $attempt_id, &$outcome, &$in_flight_id ): bool {
							$sale = $draft->order();
							if ( ! self::awaits_payment( $sale ) ) {
								$outcome = 'ineligible';
								return false;
							}
							// A reload is the same send; another method or destination is a new invoice, which supersedes the earlier one.
							$destination = '' !== $sale->get_billing_email() ? mb_substr( sanitize_text_field( $sale->get_billing_email() ), 0, 254 ) : null;
							$prior       = Attempts::of( $sale )->awaiting();
							if ( null !== $prior && $prior['method_id'] === $sale->get_payment_method() && $prior['destination'] === $destination ) {
								$outcome = 'awaiting';
								return false;
							}
							// An earlier attempt with no known outcome is reconciled first, as native submit refuses via eligible( 'submit' ); stamping would supersede it out of the sweep.
							$in_flight = Attempts::of( $sale )->eligible( 'submit' );
							if ( null !== $in_flight ) {
								$outcome      = 'in_flight';
								$in_flight_id = (string) $in_flight['id'];
								return false;
							}
							Attempts::open_as_till( $draft, $attempt_id, $sale->get_payment_method() );
							Attempts::await( $draft, $attempt_id, $destination );
							$outcome = 'written';
							return true;
						},
						Ledger::MODE_HOLD
					);
					if ( 'in_flight' === $outcome ) {
						Logger::warning( sprintf( 'WCPOS left order #%d without an invoice-sent stamp: payment attempt %s is still in flight and the sweep reconciles it first.', $order->get_id(), $in_flight_id ) );
					}
					if ( in_array( $outcome, array( 'written', 'awaiting' ), true ) ) {
						$order_json = $this->get_order_json( $order->get_id() );
						$order_sent = false !== $order_json;
					}
				}
			} catch ( \Throwable $e ) {
				Logger::warning( sprintf( 'Received order %d could not record the Legacy send: %s', $order->get_id(), $e->getMessage() ) );
			}

			include wcpos_locate_template( 'received.php' );
			exit;
		} catch ( Exception $e ) {
			\wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Whether the order is unpaid and still awaiting payment.
	 *
	 * @param WC_Order $order The order.
	 */
	private static function awaits_payment( WC_Order $order ): bool {
		return ! $order->is_paid() && $order->has_status( array( 'pending', 'on-hold' ) );
	}

	/**
	 * Whether an unpaid POS order ran a gateway that declares a send verb.
	 *
	 * @param WC_Order $order The order.
	 */
	private function is_legacy_send( WC_Order $order ): bool {
		if ( ! wcpos_is_pos_order( $order ) || ! self::awaits_payment( $order ) ) {
			return false;
		}
		$gateway = Descriptor_Builder::instance()->gateway( $order->get_payment_method() );
		$fields  = null === $gateway ? null : Declared_Fields::for_gateway( $gateway );
		return 'send' === ( $fields['verb']['kind'] ?? null );
	}
}
