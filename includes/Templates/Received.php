<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WooCommercePOS\Templates
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;
use WCPOS\WooCommercePOS\Server;

class Received {
	/**
	 * @var int
	 */
	private $order_id;

	public function __construct( int $order_id ) {
		$this->order_id = $order_id;

		add_filter( 'show_admin_bar', '__return_false' );
	}


	public function get_template(): void {
		try {
			// get order
			$order = \wc_get_order( $this->order_id );

			// Order or receipt url is invalid.
			if ( ! $order ) {
				wp_die( esc_html__( 'Sorry, this order is invalid.', 'woocommerce-pos' ) );
			}

			// if ( ! $order->is_paid() ) {
			// wp_die( esc_html__( 'Sorry, this order has not been paid.', 'woocommerce-pos' ) );
			// }

			/**
			 * @TODO - this is a hack and needs to be fixed
			 * @NOTE - the received template will be removed once we move to session based checkout
			 *
			 * - hardcoding the rest endpoint is a receipe for disaster
			 */
			$server                   = new Server();
			$order_json               = $server->wp_rest_request( '/wcpos/v1/orders/' . $this->order_id );
			$completed_status_setting = woocommerce_pos_get_settings( 'checkout', 'order_status' );
			$completed_status         = 'wc-' === substr( $completed_status_setting, 0, 3 ) ? substr( $completed_status_setting, 3 ) : $completed_status_setting;
			$order_complete           = $completed_status !== 'pos-open';

			// @TODO - display message for errors

			include woocommerce_pos_locate_template( 'received.php' );
			exit;
		} catch ( Exception $e ) {
			\wc_print_notice( $e->getMessage(), 'error' );
		}
	}
}
