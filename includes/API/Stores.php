<?php
/**
 * Stores API.
 *
 * @package WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

use WP_REST_Controller;
use WCPOS\WooCommercePOS\Abstracts\Store;
use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Stores API.
 */
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
	 * Register the routes for the objects of the controller.
	 */
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_item' ),
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
			$stores = wcpos_get_stores();

			$response = array();
			foreach ( $stores as $store ) {
				$data = $this->prepare_item_for_response( $store, $request );
				$response[] = $this->prepare_response_for_collection( $data );
			}

			$response = rest_ensure_response( $response );
			$response->header( 'X-WP-Total', count( $stores ) );
			$response->header( 'X-WP-TotalPages', 1 );

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
	 * Retrieve a single store.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$store = wcpos_get_store( $request['id'] );

		if ( ! $store ) {
			return new \WP_Error(
				'woocommerce_pos_store_not_found',
				esc_html__( 'Store not found', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$data = $this->prepare_item_for_response( $store, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Prepare a single product output for response.
	 *
	 * @param Store           $store    Store object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $store, $request ) {
		$data = $store->get_data();
		$response = rest_ensure_response( $data );
		$response->add_links( $this->prepare_links( $store, $request ) );

		/**
		 * Filter the data for a response.
		 *
		 * The dynamic portion of the hook name, $this->post_type, refers to post_type of the post being
		 * prepared for the response.
		 *
		 * @param WP_REST_Response   $response   The response object.
		 * @param Store              $store      Store object.
		 * @param WP_REST_Request    $request    Request object.
		 */
		return apply_filters( 'woocommerce_pos_rest_prepare_store', $response, $store, $request );
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

		/**
		 * Prepare links for the request.
		 *
		 * @param WC_Product      $product Product object.
		 * @param WP_REST_Request $request Request object.
		 * @return array Links for the given product.
		 */
	protected function prepare_links( $store, $request ) {
		$links = array(
			'self' => array(
				'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $store->get_id() ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		return $links;
	}
}
