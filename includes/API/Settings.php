<?php


namespace WCPOS\WooCommercePOS\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Settings {

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
	 * @return array
	 */
	public function get_settings() {
		$data = array(
			'general' => array(
				'pos_only_products' => get_option( $this->db_prefix . 'general_pos_only_products', false ),
				'decimal_qty'       => get_option( $this->db_prefix . 'general_decimal_qty', false ),
			),
		);

		/** Let the user modify the data before sending it back */
		return apply_filters( 'woocommerce_pos_settings_before_dispatch', $data );

//		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function save_settings( WP_REST_Request $request ) {
		// Get sent data and set default value
		$params = wp_parse_args( $request->get_params(), array() );

		$success = update_option( $this->db_prefix . 'general_pos_only_products', $params['pos_only_products'] );

		if ( $success ) {
			return $this->get_settings();
		}

		return new WP_Error( 'cant-save', __( 'message', 'woocommerce-pos' ), array( 'status' => 200 ) );
	}


}
