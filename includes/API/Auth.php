<?php

/**
 * POS Auth API.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\API;

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
}
