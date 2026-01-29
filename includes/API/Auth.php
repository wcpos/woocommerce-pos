<?php
/**
 * POS Auth API.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Services\Auth as AuthService;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Auth class.
 */
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

	/**
	 * Register the routes for the auth controller.
	 */
	public function register_routes(): void {
		// Test authorization method support (public endpoint).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'test_authorization' ),
				'permission_callback' => '__return_true', // Public endpoint - no authentication required.
			)
		);

		// Refresh access token using refresh token.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'refresh_token' ),
				'permission_callback' => '__return_true', // Public endpoint - validates refresh token internally.
				'args'                => array(
					'refresh_token' => array(
						'description' => __( 'The refresh token to use for generating a new access token.', 'woocommerce-pos' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);

		// Get user sessions.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sessions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sessions' ),
				'permission_callback' => array( $this, 'check_session_permissions' ),
				'args'                => array(
					'user_id' => array(
						'description'       => __( 'The user ID to get sessions for. Defaults to current user.', 'woocommerce-pos' ),
						'type'              => 'integer',
						'required'          => false,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		// Delete all sessions or all except current.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sessions',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_all_sessions' ),
				'permission_callback' => array( $this, 'check_session_permissions' ),
				'args'                => array(
					'user_id'        => array(
						'description'       => __( 'The user ID to delete sessions for.', 'woocommerce-pos' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'except_current' => array(
						'description' => __( 'Whether to keep the current session.', 'woocommerce-pos' ),
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
					),
				),
			)
		);

		// Delete specific session by JTI.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sessions/(?P<jti>[a-f0-9\-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_session' ),
				'permission_callback' => array( $this, 'check_session_permissions' ),
				'args'                => array(
					'jti'     => array(
						'description'       => __( 'The session JTI to delete.', 'woocommerce-pos' ),
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							// Validate UUID format.
							return preg_match( '/^[a-f0-9\-]{36}$/i', $param );
						},
					),
					'user_id' => array(
						'description'       => __( 'The user ID that owns the session.', 'woocommerce-pos' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		// Get all users with active sessions (admin/manager only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/users/sessions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_all_users_sessions' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
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
		// Check for Authorization header.
		$header_auth     = $request->get_header( 'authorization' );
		$has_header_auth = ! empty( $header_auth );

		// Check for authorization query parameter.
		$param_auth     = $request->get_param( 'authorization' );
		$has_param_auth = ! empty( $param_auth );

		// Only return success if we received authorization via at least one method.
		if ( ! $has_header_auth && ! $has_param_auth ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => 'No authorization token detected',
				)
			);
		}

		$response_data = array(
			'status'     => 'success',
			'message'    => 'Authorization token detected successfully',
		);

		// Add authorization details.
		$response_data['received_header_auth'] = $has_header_auth;
		if ( $has_header_auth ) {
			$response_data['header_value'] = $header_auth;
		}

		$response_data['received_param_auth'] = $has_param_auth;
		if ( $has_param_auth ) {
			$response_data['param_value'] = $param_auth;
		}

		// Indicate which method was used.
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
			return rest_ensure_response(
				array(
					'error'             => 'invalid_request',
					'error_description' => 'Missing refresh_token parameter',
				),
				400
			);
		}

		$auth_service = AuthService::instance();
		$result       = $auth_service->refresh_access_token( $refresh_token );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			$error_msg  = $result->get_error_message();
			$status     = $result->get_error_data()['status'] ?? 400;

			// Map error codes to OAuth 2.0 standard error responses.
			$oauth_error = 'invalid_grant'; // Default OAuth error for refresh token issues.

			if ( false !== strpos( $error_code, 'invalid_token' ) || false !== strpos( $error_code, 'revoked' ) ) {
				$oauth_error = 'invalid_grant';
			} elseif ( false !== strpos( $error_code, 'user_not_found' ) ) {
				$oauth_error = 'invalid_grant';
			}

			return rest_ensure_response(
				array(
					'error'             => $oauth_error,
					'error_description' => $error_msg,
				),
				$status
			);
		}

		// Calculate expires_in for axios-auth-refresh compatibility.
		$current_time = time();
		$expires_in   = max( 0, $result['expires_at'] - $current_time );

		// Return response in format compatible with axios-auth-refresh.
		$response_data = array(
			'access_token' => $result['access_token'],
			'token_type'   => $result['token_type'],
			'expires_in'   => $expires_in,
			'expires_at'   => $result['expires_at'],
		);

		return rest_ensure_response( $response_data );
	}

	/**
	 * Get sessions for a user.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_sessions( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $request->get_param( 'user_id' );

		// Default to current user if not specified.
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$auth_service = AuthService::instance();
		$sessions     = $auth_service->get_user_sessions( (int) $user_id );

		// Get current JTI if available from the request token.
		$current_jti = $this->get_current_jti_from_request( $request );

		// Mark the current session.
		foreach ( $sessions as &$session ) {
			$session['is_current'] = ( ! empty( $current_jti ) && $session['jti'] === $current_jti );
		}

		return rest_ensure_response(
			array(
				'user_id'  => $user_id,
				'sessions' => $sessions,
			)
		);
	}

	/**
	 * Delete a specific session.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_session( WP_REST_Request $request ): WP_REST_Response {
		$jti     = $request->get_param( 'jti' );
		$user_id = $request->get_param( 'user_id' );

		if ( empty( $jti ) || empty( $user_id ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Missing required parameters.', 'woocommerce-pos' ),
				),
				400
			);
		}

		$auth_service = AuthService::instance();

		// Revoke session and blacklist it - this invalidates all access tokens for this session.
		$result = $auth_service->revoke_session_with_blacklist( (int) $user_id, $jti );

		if ( $result ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Session revoked successfully.', 'woocommerce-pos' ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => false,
				'message' => __( 'Failed to revoke session.', 'woocommerce-pos' ),
			),
			404
		);
	}

	/**
	 * Delete all sessions for a user.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_all_sessions( WP_REST_Request $request ): WP_REST_Response {
		$user_id        = $request->get_param( 'user_id' );
		$except_current = $request->get_param( 'except_current' );

		if ( empty( $user_id ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Missing user_id parameter.', 'woocommerce-pos' ),
				),
				400
			);
		}

		$auth_service = AuthService::instance();

		if ( $except_current ) {
			// Get current JTI from request.
			$current_jti = $this->get_current_jti_from_request( $request );

			if ( empty( $current_jti ) ) {
				return rest_ensure_response(
					array(
						'success' => false,
						'message' => __( 'Could not determine current session.', 'woocommerce-pos' ),
					),
					400
				);
			}

			$result = $auth_service->revoke_all_sessions_except( (int) $user_id, $current_jti );
		} else {
			$result = $auth_service->revoke_all_refresh_tokens( (int) $user_id );
		}

		if ( $result ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Sessions revoked successfully.', 'woocommerce-pos' ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => false,
				'message' => __( 'Failed to revoke sessions.', 'woocommerce-pos' ),
			),
			500
		);
	}

	/**
	 * Get all users with active sessions (admin/manager only).
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_all_users_sessions( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$auth_service = AuthService::instance();

		// Get all users who have refresh tokens.
		$user_ids = $wpdb->get_col(
			"SELECT DISTINCT user_id 
			FROM {$wpdb->usermeta} 
			WHERE meta_key = '_woocommerce_pos_refresh_tokens'"
		);

		$users_data = array();

		foreach ( $user_ids as $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) {
				continue;
			}

			$sessions = $auth_service->get_user_sessions( (int) $user_id );

			// Only include users with active sessions.
			if ( empty( $sessions ) ) {
				continue;
			}

			// Find the most recent activity.
			$last_active = 0;
			foreach ( $sessions as $session ) {
				if ( $session['last_active'] > $last_active ) {
					$last_active = $session['last_active'];
				}
			}

			$users_data[] = array(
				'user_id'       => (int) $user_id,
				'username'      => $user->user_login,
				'display_name'  => $user->display_name,
				'avatar_url'    => get_avatar_url( $user_id, array( 'size' => 96 ) ),
				'session_count' => \count( $sessions ),
				'last_active'   => $last_active,
				'sessions'      => $sessions,
			);
		}

		// Sort by last_active descending (most recent first).
		usort(
			$users_data,
			function ( $a, $b ) {
				return $b['last_active'] - $a['last_active'];
			}
		);

		return rest_ensure_response(
			array(
				'users' => $users_data,
				'total' => \count( $users_data ),
			)
		);
	}

	/**
	 * Check session management permissions.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return bool
	 */
	public function check_session_permissions( WP_REST_Request $request ): bool {
		// User must be logged in.
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$target_user_id = $request->get_param( 'user_id' );

		// Default to current user if not specified (for GET requests).
		if ( empty( $target_user_id ) ) {
			$target_user_id = get_current_user_id();
		}

		$auth_service = AuthService::instance();

		return $auth_service->can_manage_user_sessions( (int) $target_user_id );
	}

	/**
	 * Check admin/manager permissions.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return bool
	 */
	public function check_admin_permissions( WP_REST_Request $request ): bool {
		// Only administrators and shop managers.
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get current JTI from the request's authorization token.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 *
	 * @return null|string
	 */
	private function get_current_jti_from_request( WP_REST_Request $request ): ?string {
		// Try to get the token from Authorization header.
		$auth_header = $request->get_header( 'authorization' );

		if ( empty( $auth_header ) ) {
			// Try query parameter.
			$auth_header = $request->get_param( 'authorization' );
		}

		if ( empty( $auth_header ) ) {
			return null;
		}

		// Extract token from "Bearer TOKEN".
		$token = str_replace( 'Bearer ', '', $auth_header );

		if ( empty( $token ) ) {
			return null;
		}

		// Try to decode the token to get JTI.
		$auth_service = AuthService::instance();
		$decoded      = $auth_service->validate_token( $token, 'refresh' );

		if ( is_wp_error( $decoded ) ) {
			// If it's not a refresh token, it might be an access token
			// Access tokens don't have JTI, so return null.
			return null;
		}

		return $decoded->jti ?? null;
	}
}
