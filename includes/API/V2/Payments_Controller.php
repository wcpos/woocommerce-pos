<?php
/**
 * Order payments contract controller.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

\defined( 'ABSPATH' ) || die;

use WC_Order;
use WC_REST_Controller;
use WCPOS\WooCommercePOS\Payments\Contract\Ledger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Records, reads, and voids manual payment rows. */
class Payments_Controller extends WC_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'orders';

	/** Register the manual payment route family. */
	public function register_routes(): void {
		$payment_path = '/' . $this->rest_base . '/(?P<id>[\d]+)/payments';
		$routes       = array(
			$payment_path => array( WP_REST_Server::CREATABLE, 'create_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/status' => array( WP_REST_Server::READABLE, 'get_status' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/void' => array( WP_REST_Server::CREATABLE, 'void_item' ),
		);
		foreach ( $routes as $route => $definition ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					array(
						'methods'             => $definition[0],
						'callback'            => array( $this, $definition[1] ),
						'permission_callback' => array( $this, 'payments_permissions_check' ),
					),
				)
			);
		}
	}

	/**
	 * Check access to payment routes.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function payments_permissions_check( $request ) {
		return current_user_can( 'publish_shop_orders' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot manage order payments.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Record money already taken by a manual payment method.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : $request->get_body_params();
		$payment = $params['payment'] ?? null;
		if ( ! is_array( $payment ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Payment must be an object.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}

		$context = array( 'cashier_id' => get_current_user_id() );
		if ( isset( $payment['store_id'] ) ) {
			$context['store_id'] = (int) $payment['store_id'];
		}
		$row = Ledger::instance()->record( $order, $payment, $context );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$refusal = Ledger::instance()->refusal_error( $row, $order );
		if ( $refusal ) {
			return $refusal;
		}
		return $this->payment_response( $row, $order );
	}

	/**
	 * Return current status for one payment row.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$row = Ledger::instance()->status( $order, strtolower( (string) $request['uuid'] ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return $this->payment_response( $row, $order );
	}

	/**
	 * Void one pending or authorized payment row.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function void_item( $request ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$reason = sanitize_text_field( (string) ( $params['reason'] ?? '' ) );
		$row    = Ledger::instance()->void( $order, strtolower( (string) $request['uuid'] ), $reason );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return $this->payment_response( $row, $order );
	}

	/**
	 * Build the standard payment and order response.
	 *
	 * @param array    $row   Payment row.
	 * @param WC_Order $order Order object.
	 */
	private function payment_response( array $row, WC_Order $order ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'payment' => Ledger::to_wire( $row ),
				'order'   => Ledger::instance()->summary( $order ),
			)
		);
	}

	/**
	 * Get an order or the standard missing-order error.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return WC_Order|\WP_Error
	 */
	private function get_order( int $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'wcpos_order_not_found', __( 'Order not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}
		return $order;
	}
}
