<?php
/**
 * Raw REST response helper.
 *
 * @package WCPOS\WooCommercePOS\API\V1
 */

namespace WCPOS\WooCommercePOS\API\V1;

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
				// `true === $served_result` means an earlier handler already wrote
				// the body. Echoing again would append to it and corrupt the file —
				// a receipt PDF the browser then refuses to open. The own-flag check
				// below cannot see that: it only knows about THIS closure. Moving to
				// priority 30 widened the window by putting more handlers ahead of
				// us, so the guard has to look at what WordPress reports, not just
				// at what we remember doing.
				if ( true === $served_result || $served || $result !== $response ) {
					return $served_result;
				}
				$served = true;
				echo $response->get_raw_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw response bytes.

				return true;
			},
			// AFTER the wire contract at 20 ({@see \WCPOS\WooCommercePOS\Rest_Cors}):
			// echoing the body sends the headers, and CORS headers written after
			// that are dropped — a receipt PDF the browser then refuses to read.
			30,
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
