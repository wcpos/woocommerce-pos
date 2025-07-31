<?php
/**
 * Cashier API.
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

use Exception;
use WCPOS\WooCommercePOS\Abstracts\Store;
use WCPOS\WooCommercePOS\Services\Cashier as CashierService;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Cashier API Controller.
 */
class Cashier extends WP_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = SHORT_NAME . '/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'cashier';

	/**
	 * Register the routes for the cashier controller.
	 */
	public function register_routes(): void {
		// Get cashier data: /wcpos/v1/cashier/{id}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cashier' ),
				'permission_callback' => array( $this, 'check_cashier_permissions' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Unique identifier for the cashier (WordPress user ID).', 'woocommerce-pos' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		// Get cashier stores: /wcpos/v1/cashier/{id}/stores
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/stores',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cashier_stores' ),
				'permission_callback' => array( $this, 'check_cashier_permissions' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Unique identifier for the cashier (WordPress user ID).', 'woocommerce-pos' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		// Get specific store for cashier: /wcpos/v1/cashier/{id}/stores/{store_id}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/stores/(?P<store_id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cashier_store' ),
				'permission_callback' => array( $this, 'check_cashier_permissions' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Unique identifier for the cashier (WordPress user ID).', 'woocommerce-pos' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'store_id' => array(
						'description' => __( 'Unique identifier for the store.', 'woocommerce-pos' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Check permissions for cashier endpoints.
	 *
	 * Ensures the user is authenticated and can only access their own data.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_cashier_permissions( WP_REST_Request $request ) {
		// Check if user is authenticated
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'woocommerce_pos_rest_unauthorized',
				__( 'Authentication required.', 'woocommerce-pos' ),
				array( 'status' => 401 )
			);
		}

		$current_user_id = get_current_user_id();
		$requested_id    = (int) $request->get_param( 'id' );

		// Check if the requested user exists
		$user = get_user_by( 'id', $requested_id );
		if ( ! $user ) {
			return new WP_Error(
				'woocommerce_pos_cashier_not_found',
				__( 'Cashier not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$cashier_service = CashierService::instance();

		// Check if user has POS cashier permissions
		if ( ! $cashier_service->has_cashier_permissions( $user ) ) {
			return new WP_Error(
				'woocommerce_pos_rest_forbidden',
				__( 'User does not have POS cashier permissions.', 'woocommerce-pos' ),
				array( 'status' => 403 )
			);
		}

		// Validate access permissions
		if ( ! $cashier_service->validate_cashier_access( $current_user_id, $requested_id ) ) {
			return new WP_Error(
				'woocommerce_pos_rest_forbidden',
				__( 'You can only access your own cashier data.', 'woocommerce-pos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get cashier data.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_cashier( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error(
				'woocommerce_pos_cashier_not_found',
				__( 'Cashier not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$cashier_service = CashierService::instance();
		$data            = $cashier_service->get_cashier_data( $user, true );

		/**
		 * Filter cashier data for REST API response.
		 *
		 * @param array           $data    Cashier data.
		 * @param WP_User         $user    User object.
		 * @param WP_REST_Request $request Request object.
		 */
		$data = apply_filters( 'woocommerce_pos_rest_prepare_cashier', $data, $user, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Get stores accessible by the cashier.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_cashier_stores( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'id' );
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error(
				'woocommerce_pos_cashier_not_found',
				__( 'Cashier not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		try {
			$cashier_service = CashierService::instance();
			$stores          = $cashier_service->get_accessible_stores( $user );
			$response        = array();

			foreach ( $stores as $store ) {
				$data       = $this->prepare_store_for_response( $store, $request );
				$response[] = $this->prepare_response_for_collection( $data );
			}

			$response = rest_ensure_response( $response );
			$response->header( 'X-WP-Total', \count( $stores ) );
			$response->header( 'X-WP-TotalPages', 1 );

			return $response;
		} catch ( Exception $e ) {
			return new WP_Error(
				'woocommerce_pos_stores_retrieval_failed',
				__( 'Failed to retrieve store data.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get a specific store for the cashier.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_cashier_store( WP_REST_Request $request ) {
		$user_id  = (int) $request->get_param( 'id' );
		$store_id = (int) $request->get_param( 'store_id' );
		
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'woocommerce_pos_cashier_not_found',
				__( 'Cashier not found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$cashier_service = CashierService::instance();
		$store           = $cashier_service->get_accessible_store( $user, $store_id );

		if ( ! $store ) {
			return new WP_Error(
				'woocommerce_pos_store_not_found',
				__( 'Store not found or not accessible by this cashier.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$data = $this->prepare_store_for_response( $store, $request );

		return rest_ensure_response( $data );
	}



	/**
	 * Prepare store data for response.
	 *
	 * @param Store           $store   Store object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array Prepared store data.
	 */
	protected function prepare_store_for_response( Store $store, WP_REST_Request $request ): array {
		$data = $store->get_data();

		/*
		 * Filter store data for REST API response.
		 *
		 * @param array           $data    Store data.
		 * @param Store           $store   Store object.
		 * @param WP_REST_Request $request Request object.
		 */
		return apply_filters( 'woocommerce_pos_rest_prepare_store', $data, $store, $request );
	}
}
