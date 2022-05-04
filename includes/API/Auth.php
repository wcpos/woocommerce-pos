<?php

/**
 * POS Auth API
 *
 * @package  WCPOS\WooCommercePOS\API
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\API;

use Exception;
use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key as FirebaseKey;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Auth extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'jwt';

	/**
	 * Stores constructor.
	 */
	public function __construct() {

	}

	/**
	 * Validate JWT Token
	 * @TODO - flesh out this API, see https://github.com/wp-graphql/wp-graphql-jwt-authentication for inspiration
	 *
	 * @param null $token
	 * @param bool $output
	 *
	 * @return array|object|WP_Error
	 */
	public function validate_token( $token = null, $output = true ) {
		try {
			$decoded_token = FirebaseJWT::decode( $token, new FirebaseKey( $this->get_secret_key(), 'HS256' ) );
			/** The Token is decoded now validate the iss */
			if ( get_bloginfo( 'url' ) != $decoded_token->iss ) {
				/** The iss do not match, return error */
				return new WP_Error(
					'jwt_auth_bad_iss',
					'The iss do not match with this server',
					array(
						'status' => 403,
					)
				);
			}
			/** So far so good, validate the user id in the token */
			if ( ! isset( $decoded_token->data->user->id ) ) {
				/** No user id in the token, abort!! */
				return new WP_Error(
					'jwt_auth_bad_request',
					'User ID not found in the token',
					array(
						'status' => 403,
					)
				);
			}
			/** Everything looks good return the decoded token if the $output is false */
			if ( ! $output ) {
				return $decoded_token;
			}

			/** If the output is true return an answer to the request to show it */
			return array(
				'code' => 'jwt_auth_valid_token',
				'data' => array(
					'status' => 200,
				),
			);
		} catch ( Exception $e ) {
			/** Something is wrong trying to decode the token, send back the error */
			return new WP_Error(
				'jwt_auth_invalid_token',
				$e->getMessage(),
				array(
					'status' => 403,
				)
			);
		}
	}

	public function get_secret_key() {
		//$secret_key = defined( 'JWT_AUTH_SECRET_KEY' ) ? JWT_AUTH_SECRET_KEY : false;
		return '!x>),R!Z$1zLs1(*mJ3^+r#VvO(lfjBK;sqBB!|brVfL-%z+++7x!l34-z+mbQiq';
	}

	/**
	 *
	 */
	public function register_routes() {
		// Validate JWT token
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/authorize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'username' => array(
						/* translators: WordPress */
						'description' => __( 'Username', 'wordpress' ),
						'type'        => 'string',
					),
					'password' => array(
						/* translators: WordPress */
						'description' => __( 'Password', 'wordpress' ),
						'type'        => 'string',
					),
				),
			)
		);

		// Validate JWT token
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'jwt' => array(
						'description' => __( 'JWT token.', 'woocommerce-pos' ),
						'type'        => 'string',
					),
				),
			)
		);

		// Refresh JWT token
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'jwt' => array(
						'description' => __( 'JWT token.', 'woocommerce-pos' ),
						'type'        => 'string',
					),
				),
			)
		);

		// Revoke JWT token
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'jwt' => array(
						'description' => __( 'JWT token.', 'woocommerce-pos' ),
						'type'        => 'string',
					),
				),
			)
		);
	}

	/**
	 * Get the user and password in the request body and generate a JWT
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
	 */
	public function generate_token( WP_REST_Request $request ) {
		$username = $request->get_param( 'username' );
		$password = $request->get_param( 'password' );

		/** First thing, check the secret key if not exist return a error*/
		if ( ! $this->get_secret_key() ) {
			return new WP_Error(
				'[woocommerce_pos] jwt_auth_bad_config',
				__( 'JWT is not configured properly, please contact the admin', 'woocommerce-pos' ),
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
		$issued_at = time();

		/**
		 * Filters the JWT issued at time
		 *
		 * @param {string} $issued_at
		 * @returns {string} Issued at time
		 *
		 * @since 1.0.0
		 * @hook woocommerce_pos_jwt_auth_not_before
		 */
		$not_before = apply_filters( 'woocommerce_pos_jwt_auth_not_before', $issued_at, $issued_at );
		/**
		 * Filters the JWT expire time
		 *
		 * @param {string} $issued_at
		 * @returns {string} Expire time
		 *
		 * @since 1.0.0
		 * @hook woocommerce_pos_jwt_auth_expire
		 */
		$expire = apply_filters( 'woocommerce_pos_jwt_auth_expire', $issued_at + ( DAY_IN_SECONDS * 7 ), $issued_at );

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
		$token = FirebaseJWT::encode( apply_filters( 'woocommerce_pos_jwt_auth_token_before_sign', $token, $user ), $this->get_secret_key(), 'HS256' );

		/** The token is signed, now create the object with no sensible user data to the client*/
		$data = array(
			'jwt'          => $token,
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'first_name'   => $user->user_firstname,
			'last_name'    => $user->user_lastname,
			'nice_name'    => $user->user_nicename,
			'display_name' => $user->display_name,
		);

		/** Let the user modify the data before sending it back */
		$data = apply_filters( 'woocommerce_pos_jwt_auth_token_before_dispatch', $data, $user );

		return rest_ensure_response( $data );
	}

	/**
	 * Refresh JWT Token
	 *
	 */
	public function refresh_token() {

	}

	/**
	 * Revoke JWT Token
	 *
	 */
	public function revoke_token() {

	}
}
