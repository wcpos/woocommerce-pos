<?php


namespace WCPOS\WooCommercePOS\API;

use WP_REST_Request;

class Settings {
	private $request;

	/**
	 * Stores constructor.
	 */
	public function __construct() {

	}

	/**
	 *
	 */
	public function get_settings() {
		$prefix = \WCPOS\WooCommercePOS\Admin\Settings::DB_PREFIX;

		$data = array(
			'general' => array(
				'pos_only_products' => get_option( $prefix . 'general_pos_only_products' ),
				'decimal_qty'       => get_option( $prefix . 'general_decimal_qty' ),
			),
		);

		/** Let the user modify the data before sending it back */
		return apply_filters( 'woocommerce_pos_settings_before_dispatch', $data );
	}

}
