<?php
/**
 * POS checkout controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

use WC_Payment_Gateway;
use WC_REST_Controller;
use WCPOS\WooCommercePOS\Payments\Checkout_State_Repository;
use WCPOS\WooCommercePOS\Payments\Gateway_Contract;
use WCPOS\WooCommercePOS\Payments\Idempotency_Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POS checkout controller.
 */
class Checkout_Controller extends WC_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'orders';

	/**
	 * Checkout state repository.
	 *
	 * @var Checkout_State_Repository
	 */
	private $state_repository;

	/**
	 * Idempotency repository.
	 *
	 * @var Idempotency_Repository
	 */
	private $idempotency_repository;

	/**
	 * Shared gateway contract helper.
	 *
	 * @var Gateway_Contract
	 */
	private $gateway_contract;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->state_repository       = new Checkout_State_Repository();
		$this->idempotency_repository = new Idempotency_Repository();
		$this->gateway_contract       = new Gateway_Contract();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/checkout',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Read permissions check.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_item_permissions_check( $request ) {
		return current_user_can( 'publish_shop_orders' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view checkout state.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Create permissions check.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function create_item_permissions_check( $request ) {
		return current_user_can( 'publish_shop_orders' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot process checkout.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Create a checkout state mutation.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function create_item( $request ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		$idempotency_key = (string) $request->get_header( 'X-WCPOS-Idempotency-Key' );
		if ( empty( $idempotency_key ) ) {
			return new WP_Error(
				'wcpos_missing_idempotency_key',
				__( 'Missing X-WCPOS-Idempotency-Key header.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$request_hash = md5( wp_json_encode( $this->normalize_for_hash( $params ) ) );
		$conflict     = $this->idempotency_repository->assert_not_conflicting( 'checkout', $idempotency_key, $request_hash );
		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}

		$existing = $this->idempotency_repository->find( 'checkout', $idempotency_key );
		if ( $existing ) {
			return new WP_REST_Response( $existing['body'], $existing['status_code'] );
		}

		$gateway_id = isset( $params['gateway_id'] ) ? (string) $params['gateway_id'] : '';
		$gateway    = $this->get_gateway( $gateway_id );
		if ( ! $gateway ) {
			return new WP_Error(
				'wcpos_payment_gateway_not_found',
				__( 'Payment gateway not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$action       = isset( $params['action'] ) ? (string) $params['action'] : 'start';
		$payment_data = isset( $params['payment_data'] ) && is_array( $params['payment_data'] ) ? $params['payment_data'] : array();
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS checkout contract filter.
		$state        = apply_filters(
			'wcpos_process_checkout_action',
			array(
				'checkout_id'   => wp_generate_uuid4(),
				'order_id'      => $order->get_id(),
				'gateway_id'    => $gateway_id,
				'status'        => 'processing',
				'provider_data' => array(),
				'terminal'      => false,
			),
			$action,
			$payment_data,
			$order,
			$request
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$state = $this->normalize_state( $order->get_id(), $gateway_id, $state );
		$this->state_repository->upsert( $order->get_id(), $state );

		if ( 'completed' === $state['status'] ) {
			$order->update_meta_data( '_pos_checkout_gateway_id', $gateway_id );
			$order->update_meta_data( '_pos_checkout_idempotency_key', $idempotency_key );
			$order->save_meta_data();
		}

		$this->idempotency_repository->store( 'checkout', $idempotency_key, $request_hash, 200, $state );

		return rest_ensure_response( $state );
	}

	/**
	 * Return the last known checkout state.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_item( $request ) {
		$order = $this->get_order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$state = $this->state_repository->get( $order->get_id() );
		if ( empty( $state ) ) {
			$state = array(
				'checkout_id'   => null,
				'order_id'      => $order->get_id(),
				'gateway_id'    => $order->get_meta( '_pos_checkout_gateway_id', true ) ? $order->get_meta( '_pos_checkout_gateway_id', true ) : '',
				'status'        => 'pending',
				'provider_data' => array(),
				'terminal'      => false,
			);
		}

		return rest_ensure_response( $state );
	}

	/**
	 * Get an order by ID.
	 *
	 * @param int $order_id Order ID.
	 */
	private function get_order( int $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'wcpos_order_not_found',
				__( 'Order not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		return $order;
	}

	/**
	 * Get a payment gateway by ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 */
	private function get_gateway( string $gateway_id ): ?WC_Payment_Gateway {
		WC()->payment_gateways();
		$gateways = WC()->payment_gateways->payment_gateways();

		return $gateways[ $gateway_id ] ?? null;
	}

	/**
	 * Normalize checkout state payload.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $gateway_id Gateway ID.
	 * @param array  $state      Raw state.
	 */
	private function normalize_state( int $order_id, string $gateway_id, array $state ): array {
		return array(
			'checkout_id'   => $state['checkout_id'] ?? null,
			'order_id'      => $order_id,
			'gateway_id'    => $state['gateway_id'] ?? $gateway_id,
			'status'        => $state['status'] ?? 'processing',
			'provider_data' => isset( $state['provider_data'] ) && is_array( $state['provider_data'] ) ? $state['provider_data'] : array(),
			'terminal'      => isset( $state['terminal'] ) ? (bool) $state['terminal'] : $this->gateway_contract->is_terminal_status( (string) ( $state['status'] ?? 'processing' ) ),
		);
	}

	/**
	 * Normalize request data for idempotency hashing.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return mixed
	 */
	private function normalize_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		ksort( $value );

		foreach ( $value as $key => $nested ) {
			$value[ $key ] = $this->normalize_for_hash( $nested );
		}

		return $value;
	}
}
