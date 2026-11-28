<?php
/**
 * WCPOS REST API v2 service pass-through.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Services\Cashier;
use WCPOS\WooCommercePOS\Services\Receipt_Email_Service;
use WCPOS\WooCommercePOS\Services\Permission_Rules;
use WCPOS\WooCommercePOS\Services\Store_Reach;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The email ACTION promotes; the data routes stay frozen at v1.
 */
class Order_Email_Controller extends \WCPOS\WooCommercePOS\API\V1\Orders_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';

	/**
	 * Register the order-email action only.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>[\d]+)/email',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'wcpos_send_email' ),
					'permission_callback' => array( $this, 'wcpos_send_email_permissions_check' ),
					'args'                => array_merge(
						$this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
						array(
							'email'   => array(
								'type'        => 'string',
								'format'      => 'email',
								'description' => /* translators: REST API schema field label or error message. */ __( 'Email address', 'woocommerce-pos' ),
								'required'    => true,
							),
							'save_to' => array(
								'type'        => 'string',
								'description' => __( 'Save email to order', 'woocommerce-pos' ),
								'required'    => false,
							),
						)
					),
				),
				'schema' => array( $this, 'wcpos_get_public_send_email_schema' ),
			)
		);

		// A separate route means a 2.0.0 server cannot ignore a template argument and send its invoice.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>[\d]+)/receipt-email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_receipt' ),
				'permission_callback' => array( $this, 'can_send_receipt' ),
				'args'                => array(
					'store_id'    => array(
						'type' => 'integer',
						'minimum' => 1,
					),
					'email'       => array(
						'type' => 'string',
						'format' => 'email',
						'required' => true,
					),
					'template_id' => array(
						'type' => 'string',
						'minLength' => 1,
						'required' => true,
					),
					'save_to'     => array(
						'type' => 'string',
						'enum' => array( '', 'billing' ),
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Check the order named in the route and its store against the current cashier.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 */
	public function can_send_receipt( WP_REST_Request $request ) {
		$allowed = $this->wcpos_send_email_permissions_check();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$id = (int) $request->get_url_params()['order_id'];
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return new WP_Error( 'wcpos_receipt_order_not_found', __( 'Order not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		$store_id = (int) $order->get_meta( '_pos_store' );
		$template_store_id = $this->template_store_id( $request, $order );
		$reach = Store_Reach::for_request( $request );
		if ( ! current_user_can( 'access_woocommerce_pos' )
			|| true !== Permission_Rules::verdict( 'orders', 'edit', $id )
			|| ! $reach->admits( $store_id )
			|| ( $store_id > 0 && ! Cashier::instance()->has_store_access( wp_get_current_user(), $store_id ) )
			|| ( $template_store_id > 0 && ( ! $reach->admits( $template_store_id ) || ! Cashier::instance()->has_store_access( wp_get_current_user(), $template_store_id ) ) ) ) {
			return new WP_Error( 'wcpos_receipt_forbidden', __( 'You cannot email this receipt.', 'woocommerce-pos' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Send the receipt selected on the sale screen.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 */
	public function send_receipt( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request->get_url_params()['order_id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wcpos_receipt_order_not_found', __( 'Order not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		return ( new Receipt_Email_Service() )->send( $order, $this->template_store_id( $request, $order ), $request['template_id'], $request['email'], 'billing' === $request['save_to'] );
	}

	/**
	 * The app's REST preamble supplies the store whose template picker made the selection.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @param WC_Order        $order Receipt order.
	 */
	private function template_store_id( WP_REST_Request $request, WC_Order $order ): int {
		if ( null !== $request['store_id'] ) {
			return (int) $request['store_id'];
		}
		$order_store_id = (int) $order->get_meta( '_pos_store' );
		return $order_store_id > 0 ? $order_store_id : (int) \WCPOS\WooCommercePOS\Services\Receipt_Data_Builder::resolve_pos_store( $order )->get_id();
	}
}
