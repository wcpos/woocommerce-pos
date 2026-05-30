<?php
/**
 * Thin PrintNode REST API client built on the WordPress HTTP API.
 *
 * Wraps the PrintNode cloud print service. Every public method is total: it
 * returns a WP_Error on any failure rather than throwing, and the API key is
 * never placed in a return value, error message, or log entry.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WP_Error;
use WCPOS\WooCommercePOS\Logger;

/**
 * PrintNode_Client class.
 */
class PrintNode_Client {
	/**
	 * PrintNode REST base URL (no trailing slash).
	 */
	const BASE_URL = 'https://api.printnode.com';

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * PrintNode API key. Treated as a secret; never exposed.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key PrintNode API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Fetch the account associated with the API key.
	 *
	 * @return array|WP_Error Decoded account object, or WP_Error on failure.
	 */
	public function whoami() {
		$response = wp_remote_get(
			self::BASE_URL . '/whoami',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
			)
		);

		return $this->handle( $response );
	}

	/**
	 * List the printers available to the account.
	 *
	 * @return array|WP_Error Decoded array of printers, or WP_Error on failure.
	 */
	public function printers() {
		$response = wp_remote_get(
			self::BASE_URL . '/printers',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
			)
		);

		return $this->handle( $response );
	}

	/**
	 * Submit a print job.
	 *
	 * @param int    $printer_id   Target printer id.
	 * @param string $title        Human-readable job title.
	 * @param string $content_type PrintNode contentType ('pdf_base64' or 'raw_base64').
	 * @param string $base64       Base64-encoded job content.
	 *
	 * @return array|WP_Error array( 'id' => <int> ) on success, or WP_Error on failure.
	 */
	public function submit_job( int $printer_id, string $title, string $content_type, string $base64 ) {
		$body = array(
			'printerId'   => $printer_id,
			'title'       => $title,
			'contentType' => $content_type,
			'content'     => $base64,
			'source'      => 'WCPOS',
		);

		$response = wp_remote_post(
			self::BASE_URL . '/printjobs',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array_merge(
					$this->headers(),
					array( 'Content-Type' => 'application/json' )
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$result = $this->handle( $response );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalize_job_id( $result );
	}

	/**
	 * Resolve the live state of a single printer.
	 *
	 * Never returns a WP_Error; any failure collapses to 'unknown'.
	 *
	 * @param int $printer_id Printer id to query.
	 *
	 * @return string 'online', 'offline', or 'unknown'.
	 */
	public function printer_state( int $printer_id ): string {
		$response = wp_remote_get(
			self::BASE_URL . '/printers/' . $printer_id,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
			)
		);

		$result = $this->handle( $response );

		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result[0] ) || ! is_array( $result[0] ) ) {
			return 'unknown';
		}

		$state = isset( $result[0]['state'] ) ? $result[0]['state'] : '';

		if ( 'online' === $state ) {
			return 'online';
		}

		if ( 'offline' === $state ) {
			return 'offline';
		}

		return 'unknown';
	}

	/**
	 * Build the common request headers, including HTTP Basic auth.
	 *
	 * The API key is the username with an empty password.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
			'Accept'        => 'application/json',
		);
	}

	/**
	 * Convert a raw WordPress HTTP response into decoded data or a WP_Error.
	 *
	 * @param array|WP_Error $response Result of a wp_remote_* call.
	 *
	 * @return array|WP_Error
	 */
	private function handle( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 === $code || 201 === $code ) {
			$decoded = json_decode( $body, true );

			if ( null === $decoded && 'null' !== trim( (string) $body ) ) {
				return $this->http_error( $code );
			}

			return $decoded;
		}

		if ( 401 === $code ) {
			return new WP_Error(
				'wcpos_printnode_unauthorized',
				__( 'PrintNode authentication failed.', 'woocommerce-pos' ),
				array( 'status' => 401 )
			);
		}

		return $this->http_error( $code );
	}

	/**
	 * Build a generic HTTP error that includes the status code but never the key.
	 *
	 * @param int $code HTTP status code.
	 *
	 * @return WP_Error
	 */
	private function http_error( int $code ): WP_Error {
		Logger::error( 'PrintNode request failed', array( 'status' => $code ) );

		return new WP_Error(
			'wcpos_printnode_http_error',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'PrintNode request failed with status %d.', 'woocommerce-pos' ),
				$code
			),
			array( 'status' => $code )
		);
	}

	/**
	 * Normalize a submit_job response into array( 'id' => <int> ).
	 *
	 * PrintNode may return the new job id as a bare integer, or as an object
	 * containing an `id` field.
	 *
	 * @param mixed $decoded Decoded response body.
	 *
	 * @return array|WP_Error
	 */
	private function normalize_job_id( $decoded ) {
		if ( is_int( $decoded ) || ( is_string( $decoded ) && ctype_digit( $decoded ) ) ) {
			return array( 'id' => (int) $decoded );
		}

		if ( is_array( $decoded ) && isset( $decoded['id'] ) ) {
			return array( 'id' => (int) $decoded['id'] );
		}

		return new WP_Error(
			'wcpos_printnode_http_error',
			__( 'PrintNode returned an unexpected print job response.', 'woocommerce-pos' ),
			array( 'status' => 0 )
		);
	}
}
