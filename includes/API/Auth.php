<?php

/**
 * POS Auth API.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Services\Auth as AuthService;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Auth extends WP_REST_Controller {
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
	protected $rest_base = 'auth';

	/**
	 * Stores constructor.
	 */
	public function __construct() {
	}

	public function register_routes(): void {
		// Test authorization method support (public endpoint)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'test_authorization' ),
				'permission_callback' => '__return_true', // Public endpoint - no authentication required
			)
		);

		// Refresh access token using refresh token
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_token' ),
				'permission_callback' => '__return_true', // Public endpoint - validates refresh token internally
				'args'                => array(
					'refresh_token' => array(
						'description' => __( 'The refresh token to use for generating a new access token.', 'woocommerce-pos' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);
	}


	/**
	 * Test authorization method endpoint.
	 *
	 * This public endpoint tests whether the server supports Authorization headers
	 * or requires query parameters for authorization. This is important because
	 * some servers block Authorization headers for security reasons.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function test_authorization( WP_REST_Request $request ): WP_REST_Response {
		// Check for Authorization header
		$header_auth     = $request->get_header( 'authorization' );
		$has_header_auth = ! empty( $header_auth );

		// Check for authorization query parameter
		$param_auth     = $request->get_param( 'authorization' );
		$has_param_auth = ! empty( $param_auth );

		// Only return success if we received authorization via at least one method
		if ( ! $has_header_auth && ! $has_param_auth ) {
			return rest_ensure_response( array(
				'status'  => 'error',
				'message' => 'No authorization token detected',
			) );
		}

		$response_data = array(
			'status'     => 'success',
			'message'    => 'Authorization token detected successfully',
		);

		// Add authorization details
		$response_data['received_header_auth'] = $has_header_auth;
		if ( $has_header_auth ) {
			$response_data['header_value'] = $header_auth;
		}

		$response_data['received_param_auth'] = $has_param_auth;
		if ( $has_param_auth ) {
			$response_data['param_value'] = $param_auth;
		}

		// Indicate which method was used
		if ( $has_header_auth && $has_param_auth ) {
			$response_data['auth_method'] = 'both';
		} elseif ( $has_header_auth ) {
			$response_data['auth_method'] = 'header';
		} else {
			$response_data['auth_method'] = 'param';
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * Refresh access token using a valid refresh token.
	 *
	 * This endpoint allows clients to obtain a new access token using a valid refresh token.
	 * Compatible with the axios-auth-refresh library and follows OAuth 2.0 refresh token flow.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function refresh_token( WP_REST_Request $request ): WP_REST_Response {
		$refresh_token = $request->get_param( 'refresh_token' );

		if ( empty( $refresh_token ) ) {
			return rest_ensure_response( array(
				'error'             => 'invalid_request',
				'error_description' => 'Missing refresh_token parameter',
			), 400 );
		}

		$auth_service = AuthService::instance();
		$result       = $auth_service->refresh_access_token( $refresh_token );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			$error_msg  = $result->get_error_message();
			$status     = $result->get_error_data()['status'] ?? 400;

			// Map error codes to OAuth 2.0 standard error responses
			$oauth_error = 'invalid_grant'; // Default OAuth error for refresh token issues
			
			if ( false !== strpos( $error_code, 'invalid_token' ) || false !== strpos( $error_code, 'revoked' ) ) {
				$oauth_error = 'invalid_grant';
			} elseif ( false !== strpos( $error_code, 'user_not_found' ) ) {
				$oauth_error = 'invalid_grant';
			}

			return rest_ensure_response( array(
				'error'             => $oauth_error,
				'error_description' => $error_msg,
			), $status );
		}

		// Calculate expires_in for axios-auth-refresh compatibility
		$current_time = time();
		$expires_in   = max( 0, $result['expires_at'] - $current_time );

		// Return response in format compatible with axios-auth-refresh
		$response_data = array(
			'access_token' => $result['access_token'],
			'token_type'   => $result['token_type'],
			'expires_in'   => $expires_in,
			'expires_at'   => $result['expires_at'],
		);

		return rest_ensure_response( $response_data );
	}
}
