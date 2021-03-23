<?php

/**
 * WC REST API Class
 *
 * @package  WCPOS\WooCommercePOS\API
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use Firebase\JWT\JWT;
use WP_Error;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;

class Auth {

	/**
	 * Authentication for POS app
	 *
	 */
	public function __construct() {
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ) );
	}

	/**
	 * Check request for any login tokens
	 *
	 * @return
	 */
	public function determine_current_user( $user = null ) {
		if ( ! empty( $user ) ) {
			return $user;
		}

		// extract Bearer token from Authorization Header
		list( $token ) = sscanf( $this::get_auth_header(), 'Bearer %s' );

		if ( $token ) {
			$jwt           = new Auth\JWT();
			$decoded_token = $jwt->validate_token( $token, false );

			if ( empty( $decoded_token ) || is_wp_error( $decoded_token ) ) {
				return $user;
			} else {
				$user = ! empty( $decoded_token->data->user->id ) ? $decoded_token->data->user->id : $user;
			}

			return absint( $user );
		}

	}


	public static function get_auth_header() {
		$auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : false;
		if ( ! $auth_header ) {
			$auth_header = isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : false;
		}

		return apply_filters( 'woocommerce_pos_get_auth_header', $auth_header );
	}


}
