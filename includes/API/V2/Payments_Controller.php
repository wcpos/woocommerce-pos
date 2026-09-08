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
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Exposes the locked order payment route family. */
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

	/** Register the order payment route family. */
	public function register_routes(): void {
		$payment_path = '/' . $this->rest_base . '/(?P<id>[\d]+)/payments';
		$routes       = array(
			$payment_path => array( WP_REST_Server::CREATABLE, 'create_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/status' => array( WP_REST_Server::READABLE, 'get_status' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/intent' => array( WP_REST_Server::CREATABLE, 'intent_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/capture' => array( WP_REST_Server::CREATABLE, 'capture_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/refund' => array( WP_REST_Server::CREATABLE, 'refund_item' ),
			$payment_path . '/(?P<uuid>[0-9a-fA-F-]{36})/void' => array( WP_REST_Server::CREATABLE, 'void_item' ),
		);
		foreach ( $routes as $route => $definition ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					array(
						'methods'             => $definition[0],
						'callback'            => function ( $request ) use ( $definition ) {
							// Each body fetches its order only after acquiring the lock, including GET status.
							return Order_Lock::instance()->with_lock( (int) $request['id'], fn() => $this->{$definition[1]}( $request ) );
						},
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
	 * Create a payment intent and return its transient handoff.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function intent_item( $request ) {
		return $this->provider_item( $request, 'intent' );
	}

	/**
	 * Capture a payment through its handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function capture_item( $request ) {
		return $this->provider_item( $request, 'capture' );
	}

	/**
	 * Allocate a refund through its handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function refund_item( $request ) {
		return $this->provider_item( $request, 'refund' );
	}

	/**
	 * Dispatch the new provider operations, inside the route's order lock.
	 *
	 * @param WP_REST_Request $request   Request object.
	 * @param string          $operation Ledger operation.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function provider_item( WP_REST_Request $request, string $operation ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$context = $params['context'] ?? array();
		// Money is a decimal string on the wire, but a client that sends the JSON number
		// is not wrong enough to refuse — the ledger validates the value either way.
		if ( ! is_array( $context ) || ( 'intent' === $operation && ! is_array( $params['payment'] ?? null ) ) || ( 'refund' === $operation && ( ! is_scalar( $params['amount'] ?? null ) || ! is_numeric( $params['amount'] ) || ! isset( $params['refund_id'] ) || ! is_numeric( $params['refund_id'] ) || (int) $params['refund_id'] != $params['refund_id'] ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Invalid payment operation parameters.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$context['cashier_id'] = get_current_user_id();
		$id = strtolower( (string) $request['uuid'] );
		$ledger = Ledger::instance();
		if ( 'intent' === $operation ) {
			$result = $ledger->intent( $order, $id, $params['payment'], $context );
		} elseif ( 'capture' === $operation ) {
			$result = $ledger->capture( $order, $id, $context );
		} else {
			$result = $ledger->refund( $order, $id, (int) $params['refund_id'], (string) $params['amount'] );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'refund' === $operation ) {
			return rest_ensure_response( array( 'payment' => Ledger::to_wire( $result ) ) );
		}
		$response = $this->payment_response( 'intent' === $operation ? $result['payment'] : $result, $order );
		if ( 'intent' === $operation ) {
			$response->set_data( array_merge( $response->get_data(), array( 'handoff' => empty( $result['handoff'] ) ? new \stdClass() : $result['handoff'] ) ) );
		}
		return $response;
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
