<?php

/**
 * WC REST API Class
 *
 * @package  WCPOS\WooCommercePOS\API
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\API;

use Firebase\JWT\JWT;
use WP_Error;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;

class Auth {

	/**
	 * Authentication for POS app
	 *
	 */
	public function __construct() {

	}

	/**
	 * Get the user and password in the request body and generate a JWT
	 *
	 * @param $request
	 *
	 * @return mixed|void|WP_Error
	 */
	public function generate_jwt_token( $request ) {
		//$secret_key = defined( 'JWT_AUTH_SECRET_KEY' ) ? JWT_AUTH_SECRET_KEY : false;
		$secret_key = '!x>),R!Z$1zLs1(*mJ3^+r#VvO(lfjBK;sqBB!|brVfL-%z+++7x!l34-z+mbQiq';
		$username   = $request->get_param( 'username' );
		$password   = $request->get_param( 'password' );

		/** First thing, check the secret key if not exist return a error*/
		if ( ! $secret_key ) {
			return new WP_Error(
				'[woocommerce_pos] jwt_auth_bad_config',
				__( 'JWT is not configurated properly, please contact the admin', PLUGIN_NAME ),
				array(
					'status' => 403,
				)
			);
		}
		/** Try to authenticate the user with the passed credentials*/
		$user = wp_authenticate( $username, $password );

		/** If the authentication fails return a error*/
		if ( is_wp_error( $user ) ) {
			$error_code = $user->get_error_code();

			return new WP_Error(
				'[woocommerce_pos] ' . $error_code,
				$user->get_error_message( $error_code ),
				array(
					'status' => 403,
				)
			);
		}

		/** Valid credentials, the user exists create the according Token */
		$issued_at  = time();
		$not_before = apply_filters( 'woocommerce_pos_jwt_auth_not_before', $issued_at, $issued_at );
		$expire     = apply_filters( 'woocommerce_pos_jwt_auth_expire', $issued_at + ( DAY_IN_SECONDS * 7 ), $issued_at );

		$token = array(
			'iss'  => get_bloginfo( 'url' ),
			'iat'  => $issued_at,
			'nbf'  => $not_before,
			'exp'  => $expire,
			'data' => array(
				'user' => array(
					'id' => $user->data->ID,
				),
			),
		);

		/** Let the user modify the token data before the sign. */
		$token = JWT::encode( apply_filters( 'woocommerce_pos_jwt_auth_token_before_sign', $token, $user ), $secret_key );

		/** The token is signed, now create the object with no sensible user data to the client*/
		$data = array(
			'jwt_token'         => $token,
			'user_email'        => $user->data->user_email,
			'user_nicename'     => $user->data->user_nicename,
			'user_display_name' => $user->data->display_name,
		);

		/** Let the user modify the data before send it back */
		return apply_filters( 'woocommerce_pos_jwt_auth_token_before_dispatch', $data, $user );
	}

	/**
	 * Validate JWT Token
	 *
	 */
	public function validate_jwt_token() {
		return 'Hello World';
	}

	/**
	 * Refresh JWT Token
	 *
	 */
	public function refresh_jwt_token() {

	}

	/**
	 * Revoke JWT Token
	 *
	 */
	public function revoke_jwt_token() {

	}
}
