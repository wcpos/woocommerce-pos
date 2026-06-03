<?php
/**
 * Raw REST response helper.
 *
 * @package WCPOS\WooCommercePOS\API
 */

namespace WCPOS\WooCommercePOS\API;

use WP_REST_Response;

/**
 * Raw_Response class.
 */
class Raw_Response extends WP_REST_Response {
	/**
	 * Raw response body.
	 *
	 * @var string
	 */
	private $raw_body;

	/**
	 * Constructor.
	 *
	 * @param string $body Raw response body.
	 */
	private function __construct( string $body ) {
		parent::__construct( null, 200 );
		$this->raw_body = $body;
	}

	/**
	 * Serve raw bytes from a REST callback.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type Content type.
	 * @param array  $headers      Extra headers.
	 *
	 * @return self
	 */
	public static function serve( string $body, string $content_type, array $headers = array() ): self {
		$response = new self( $body );
		$response->header( 'Content-Type', $content_type );

		foreach ( $headers as $name => $value ) {
			$response->header( (string) $name, (string) $value );
		}

		$served = false;
		add_filter(
			'rest_pre_serve_request',
			static function ( $served_result, $result ) use ( $response, &$served ) {
				if ( $served || $result !== $response ) {
					return $served_result;
				}
				$served = true;
				echo $response->get_raw_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw response bytes.

				return true;
			},
			10,
			2
		);

		return $response;
	}

	/**
	 * Get the raw response body for tests and direct consumers.
	 *
	 * @return string
	 */
	public function get_raw_body(): string {
		return $this->raw_body;
	}
}
