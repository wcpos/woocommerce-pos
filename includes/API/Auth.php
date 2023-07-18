<?php

/**
 * POS Auth API.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function is_wp_error;
use function rest_ensure_response;
use function wp_authenticate;

class Auth extends Abstracts\Controller {
	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'jwt';

    /**
     *
     */
    protected $auth_service;

	/**
	 * Stores constructor.
	 */
	public function __construct() {
        $this->auth_service = new \WCPOS\WooCommercePOS\Services\Auth();
	}

	/**
	 *
	 */
	public function register_routes(): void {
		// Generate JWT token
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/authorize',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'generate_token' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
                    // special case for user=demo param
                    if ( $request->get_param( 'user' ) === 'demo' ) {
                        return true;
                    }

					$authorization = $request->get_header( 'authorization' );

					return ! is_null( $authorization );
				},
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
	 * Get the user and password in the request body and generate a JWT.
     * @NOTE - not allowing REST Auth at the moment
	 *
	 * @param WP_REST_Request $request
	 * @return WP_Error|WP_REST_Response
	 */
	public function generate_token( WP_REST_Request $request ) {
		$token                     = str_replace( 'Basic ', '', $request->get_header( 'authorization' ) );
		$decoded                   = base64_decode( $token, true );
		list($username, $password) = explode( ':', $decoded );

		/** Try to authenticate the user with the passed credentials*/
		$user = wp_authenticate( $username, $password );

		// If the authentication fails return an error
		if ( is_wp_error( $user ) ) {
			$error_code = $user->get_error_code();

			$data = new WP_Error(
				'woocommerce_pos_' . $error_code,
				$user->get_error_message( $error_code ),
				array(
					'status' => 403,
				)
			);
		} else {
            $data = $this->auth_service->generate_token( $user );
        }

		/**
		 * Let the user modify the data before sending it back
		 *
		 * @param {object} $data
		 * @param {WP_User} $user
		 *
		 * @returns {object} Response data
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_jwt_auth_token_before_dispatch
		 */
		$data = apply_filters( 'woocommerce_pos_jwt_auth_token_before_dispatch', $data, $user );

		return rest_ensure_response( $data );
	}

    /**
     * Validate JWT Token.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function validate_token( WP_REST_Request $request ): WP_REST_Response {
        $token = $request->get_param( 'jwt' );
        $result = $this->auth_service->validate_token( $token );
        return rest_ensure_response( $result );
    }

	/**
	 * Refresh JWT Token.
	 */
	public function refresh_token(): void {
	}

	/**
	 * Revoke JWT Token.
	 */
	public function revoke_token(): void {
	}
}
