<?php


namespace WCPOS\WooCommercePOS\API;

use WP_Error;
use WP_REST_Request;

class Settings extends Controller {

	/**
	 * @var string
	 */
	private $db_prefix;

	/**
	 * Stores constructor.
	 */
	public function __construct() {
		$this->db_prefix = \WCPOS\WooCommercePOS\Admin\Settings::DB_PREFIX;
	}

	/**
	 *
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/settings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_settings' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $this->namespace, '/settings', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_settings' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_woocommerce_pos' );
			},
		) );
	}

	/**
	 * @return WP_Error|\WP_HTTP_Response|\WP_REST_Response
	 */
	public function get_settings() {
		$data = array(
			'general' => array(
				'pos_only_products' => get_option( $this->db_prefix . 'general_pos_only_products', false ),
				'decimal_qty'       => get_option( $this->db_prefix . 'general_decimal_qty', false ),
			),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|\WP_HTTP_Response|\WP_REST_Response
	 */
	public function save_settings( WP_REST_Request $request ) {
		// Get sent data and set default value
		$params = wp_parse_args( $request->get_params(), array() );

		$success = update_option( $this->db_prefix . 'general_pos_only_products', $params['pos_only_products'] );

		if ( $success ) {
			return rest_ensure_response( $this->get_settings() );
		}

		return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 200 ) );
	}


}
