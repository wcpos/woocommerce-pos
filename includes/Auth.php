<?php

/**
 * WC REST API Class
 *
 * @package  WCPOS\WooCommercePOS\API
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

class Auth {

	private $endpoint = \WCPOS\WooCommercePOS\PLUGIN_NAME . '/v1/authorize';

	/**
	 * Authentication for POS app using default WC Auth
	 *
	 */
	public function __construct() {
		add_filter( 'rest_index', array( $this, 'rest_index' ) );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'woocommerce_get_endpoint_url' ), 10, 4 );
//		add_action( 'parse_request', array( $this, 'parse_request' ), -1, 1 );
	}

	/**
	 * Add wc-auth method to the api response
	 *
	 * @param $response_object
	 *
	 * @return mixed
	 */
	public function rest_index( $response_object ) {
		if ( empty( $response_object->data['authentication'] ) ) {
			$response_object->data['authentication'] = array();
		}
		$response_object->data['authentication'][ \WCPOS\WooCommercePOS\SHORT_NAME ] = array(
			'authorize' => site_url( $this->endpoint ),
		);

		return $response_object;
	}

	/**
	 * Add flag for WCPOS
	 *
	 * @param string $url Endpoint url.
	 * @param string $endpoint Endpoint slug.
	 * @param string $value Query param value.
	 * @param string $permalink Permalink.
	 *
	 * @return string
	 */
	public function woocommerce_get_endpoint_url( $url, $endpoint, $value, $permalink ): string {
		if ( woocommerce_pos_request() && $endpoint == $this->endpoint ) {
			return $url . '?' . \WCPOS\WooCommercePOS\SHORT_NAME . '=1';
		}

		return $url;
	}
}
