<?php

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

use WP_REST_Controller;
use WCPOS\WooCommercePOS\Services\Store;
use const WCPOS\WooCommercePOS\SHORT_NAME;

class Stores extends WP_REST_Controller {
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
	protected $rest_base = 'stores';

	/**
	 * Stores constructor.
	 */
	public function __construct() {
	}


	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Retrieve store data.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		try {
			$store = new Store();

			// Check if store data is available
			if ( ! $store ) {
					return new \WP_Error(
						'woocommerce_pos_store_not_found',
						esc_html__( 'Store not found', 'woocommerce-pos' ),
						array( 'status' => 404 )
					);
			}

			$data = $store->get_data();
			$response = rest_ensure_response( array( $data ) );

			return $response;

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'woocommerce_pos_store_retrieval_failed',
				esc_html__( 'Failed to retrieve store data', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Check if the user is logged in.
	 *
	 * @return bool|WP_Error True if the user is logged in, WP_Error otherwise.
	 */
	public function check_permissions() {
		if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'woocommerce_pos_rest_forbidden',
					esc_html__( 'You do not have permissions to view this data.', 'woocommerce-pos' ),
					array( 'status' => rest_authorization_required_code() )
				);
		}

		return true;
	}
}
