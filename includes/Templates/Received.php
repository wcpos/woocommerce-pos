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
use WC_REST_Orders_Controller;
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
	 * Serialize an order to JSON using the WC REST API response shape.
	 *
	 * @param WC_Order $order The order object.
	 *
	 * @return string|false JSON string or false on failure.
	 */
	public function get_order_json( WC_Order $order ) {
		$controller = new WC_REST_Orders_Controller();
		$request    = new WP_REST_Request( 'GET' );
		$request->set_param( 'id', $order->get_id() );
		$response = $controller->prepare_object_for_response( $order, $request );
		$data     = rest_get_server()->response_to_data( $response, false );

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

			$order_json               = $this->get_order_json( $order );
			$completed_status_setting = woocommerce_pos_get_settings( 'checkout', 'order_status' );
			$completed_status         = 'wc-' === substr( $completed_status_setting, 0, 3 ) ? substr( $completed_status_setting, 3 ) : $completed_status_setting;
			$order_complete           = 'pos-open' !== $completed_status;

			include woocommerce_pos_locate_template( 'received.php' );
			exit;
		} catch ( Exception $e ) {
			\wc_print_notice( $e->getMessage(), 'error' );
		}
	}
}
