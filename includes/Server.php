<?php
/**
 * POS Server Class.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     https://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WP_REST_Request;

class Server {
	public function __construct() {
		add_filter( 'woocommerce_rest_check_permissions', array( $this, 'check_permissions' ) );
	}

	/**
	 * @TODO - add authentication check
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return true;
	}

	/**
	 * @param string $route
	 * @param string $method
	 * @param array  $attributes
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
}
