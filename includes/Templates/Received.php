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
					esc_html__( 'Error', 'woocommerce-pos' ),
					array( 'response' => 403 )
				);
			}

			$order_json       = $this->get_order_json( $order->get_id() );
			$payment_method   = $order->get_payment_method();
			$gateway_settings = woocommerce_pos_get_settings( 'payment_gateways' );
			$status_setting   = $gateway_settings['gateways'][ $payment_method ]['order_status'] ?? 'wc-completed';
			$completed_status = 'wc-' === substr( $status_setting, 0, 3 ) ? substr( $status_setting, 3 ) : $status_setting;
			$order_complete   = 'pos-open' !== $completed_status;

			include woocommerce_pos_locate_template( 'received.php' );
			exit;
		} catch ( Exception $e ) {
			\wc_print_notice( $e->getMessage(), 'error' );
		}
	}
}
