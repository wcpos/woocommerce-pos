<?php
/**
 * POS Server Class.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     https://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\Services\Auth;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Server class.
 */
class Server {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'check_permissions' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'track_session_activity' ), 10, 3 );
	}

	/**
	 * Check permissions for WooCommerce REST API requests.
	 *
	 * @TODO - add authentication check
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return true;
	}

	/**
	 * Make a WP REST API request.
	 *
	 * @param string $route      The REST route.
	 * @param string $method     The HTTP method.
	 * @param array  $attributes The request attributes.
	 *
	 * @return false|string
	 */
	public function wp_rest_request( string $route, string $method = 'GET', array $attributes = array() ) {
		$request  = new WP_REST_Request( $method, $route );
		$server   = rest_get_server();
		$response = $server->dispatch( $request );
		$result   = $server->response_to_data( $response, false );
		$result   = wp_json_encode( $result, 0 );

		$json_error_message = $this->get_json_last_error();

		if ( $json_error_message ) {
			$this->set_status( 500 );
			$json_error_obj = new WP_Error(
				'rest_encode_error',
				$json_error_message,
				array( 'status' => 500 )
			);

			$result = rest_convert_error_to_response( $json_error_obj );
			$result = wp_json_encode( $result->data, 0 );
		}

		return $result;
	}

	/**
	 * Returns if an error occurred during most recent JSON encode/decode.
	 *
	 * @See - wp-includes/rest-api/class-wp-rest-server.php
	 *
	 * Strings to be translated will be in format like
	 * "Encoding error: Maximum stack depth exceeded".
	 */
	protected function get_json_last_error() {
		$last_error_code = json_last_error();

		if ( JSON_ERROR_NONE === $last_error_code || empty( $last_error_code ) ) {
			return false;
		}

		return json_last_error_msg();
	}

	/**
	 * Track session activity on every authenticated REST API request.
	 *
	 * @param mixed           $result Response to replace the requested version with.
	 * @param WP_REST_Server  $server Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 *
	 * @return mixed
	 */
	public function track_session_activity( $result, $server, $request ) {
		// Only track for WCPOS API requests.
		$route = $request->get_route();
		if ( ! str_starts_with( $route, '/wcpos/' ) ) {
			return $result;
		}

		// Skip for auth endpoints (they handle their own tracking).
		if ( str_starts_with( $route, '/wcpos/v1/auth/' ) ) {
			return $result;
		}

		// Get authorization token.
		$token = $this->extract_token_from_request( $request );
		if ( empty( $token ) ) {
			return $result;
		}

		// Validate and extract refresh_jti from access token.
		$auth_service = Auth::instance();
		$decoded      = $auth_service->validate_token( $token, 'access' );

		if ( is_wp_error( $decoded ) ) {
			return $result;
		}

		// Update session activity if we have refresh_jti.
		if ( ! empty( $decoded->refresh_jti ) && ! empty( $decoded->data->user->id ) ) {
			$this->update_session_activity_throttled(
				$decoded->data->user->id,
				$decoded->refresh_jti
			);
		}

		return $result;
	}

	/**
	 * Extract token from request (header or query param).
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return string
	 */
	private function extract_token_from_request( WP_REST_Request $request ): string {
		// Try Authorization header.
		$auth_header = $request->get_header( 'authorization' );

		if ( empty( $auth_header ) ) {
			// Try query parameter.
			$auth_header = $request->get_param( 'authorization' );
		}

		if ( empty( $auth_header ) ) {
			return '';
		}

		// Extract token from "Bearer TOKEN".
		return str_replace( 'Bearer ', '', $auth_header );
	}

	/**
	 * Update session activity with rate limiting (once per minute).
	 *
	 * @param int    $user_id User ID.
	 * @param string $jti Refresh token JTI.
	 */
	private function update_session_activity_throttled( int $user_id, string $jti ): void {
		// Rate limit: only update once per minute per session.
		$cache_key   = "wcpos_session_activity_{$user_id}_{$jti}";
		$last_update = wp_cache_get( $cache_key, 'wcpos' );

		// If never updated or last update was more than 60 seconds ago.
		if ( false === $last_update || ( time() - $last_update ) > 60 ) {
			Auth::instance()->update_session_activity( $user_id, $jti );
			wp_cache_set( $cache_key, time(), 'wcpos', 60 );
		}
	}
}
