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
use WCPOS\WooCommercePOS\Logger;
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

		return wp_json_encode( $data );
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
			if ( ! $provided_key || $provided_key !== $order->get_order_key() ) {
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
			$settled_status = woocommerce_pos_is_pos_order( $order )
				? ( new Gateway_Contract() )->get_settled_order_status( $order->get_payment_method() )
				: '';
			$order_settled  = $order->is_paid() || ( '' !== $settled_status && $order->has_status( $settled_status ) );
			$order_complete = $order_settled && ! \in_array( $order->get_status(), array( 'pos-open', 'pos-partial' ), true );

			if ( false === $order_json ) {
				$order_complete = false;
			}

			include woocommerce_pos_locate_template( 'received.php' );
			exit;
		} catch ( Exception $e ) {
			\wc_print_notice( $e->getMessage(), 'error' );
		}
	}
}
