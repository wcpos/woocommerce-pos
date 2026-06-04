<?php
/**
 * Thin StarIO.Online (stario.online) Web API client built on the WordPress HTTP API.
 *
 * Stario.online is Star's hosted CloudPRNT relay: the printer polls Star's cloud,
 * and we push jobs to it. Every public method returns a WP_Error on failure rather
 * than throwing, and the API key is never placed in a return value, error message,
 * or log entry.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;
use WP_Error;

/**
 * Star_Online_Client class.
 */
class Star_Online_Client {
	/** Request timeout in seconds. */
	const TIMEOUT = 15;

	/**
	 * Allowlisted CloudPRNT device hosts to regional Application API base.
	 *
	 * @var array<string, string>
	 */
	private const REGION_API_BASES = array(
		'device.stario.online'    => 'https://api.stario.online/v1',
		'eu-device.stario.online' => 'https://eu-api.stario.online/v1',
	);

	/**
	 * Regional API base (no trailing slash).
	 *
	 * @var string
	 */
	private string $api_base;

	/**
	 * API key. Treated as a secret; never exposed.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_base Regional API base (use api_base_from_cloudprnt_url()).
	 * @param string $api_key  Star-Api-Key value.
	 */
	public function __construct( string $api_base, string $api_key ) {
		$this->api_base = rtrim( $api_base, '/' );
		$this->api_key  = $api_key;
	}

	/**
	 * Derive regional API base from a CloudPRNT URL, or null if not allowlisted.
	 *
	 * @param string $url CloudPRNT URL.
	 *
	 * @return string|null
	 */
	public static function api_base_from_cloudprnt_url( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! \is_string( $host ) ) {
			return null;
		}

		return self::REGION_API_BASES[ strtolower( $host ) ] ?? null;
	}

	/**
	 * Parse the device-group path from a CloudPRNT URL (.../cloudprnt/<group>).
	 *
	 * @param string $url CloudPRNT URL.
	 *
	 * @return string Group path, or '' when not present.
	 */
	public static function group_from_cloudprnt_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/cloudprnt/([^/]+)#', $path, $matches ) ) {
			return rawurldecode( $matches[1] );
		}

		return '';
	}

	/**
	 * Submit a print job to a device.
	 *
	 * @param string $group_path Device-group path.
	 * @param string $device_id  Device AccessIdentifier.
	 * @param string $title      Human-readable job name.
	 * @param string $type       Content type (e.g. text/vnd.star.markup).
	 * @param string $body       Raw job body.
	 *
	 * @return array|WP_Error array( 'id' => <string> ) on success, or WP_Error.
	 */
	public function submit_job( string $group_path, string $device_id, string $title, string $type, string $body ) {
		$url = sprintf(
			'%s/a/%s/d/%s/q',
			$this->api_base,
			rawurlencode( $group_path ),
			rawurlencode( $device_id )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Star-Api-Key'  => $this->api_key,
					'Content-Type'  => $type,
					'Star-Job-Name' => $title,
				),
				'body'    => $body,
			)
		);

		$result = $this->handle( $response );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$job_id = \is_array( $result ) && isset( $result['JobId'] ) ? (string) $result['JobId'] : '';
		if ( '' === $job_id ) {
			return new WP_Error(
				'wcpos_star_online_bad_response',
				__( 'Star Online returned an unexpected print job response.', 'woocommerce-pos' ),
				array( 'status' => 0 )
			);
		}

		return array( 'id' => $job_id );
	}

	/**
	 * List devices in a group (for the wizard picker).
	 *
	 * @param string $group_path Device-group path.
	 *
	 * @return array|WP_Error Decoded device list, or WP_Error.
	 */
	public function devices( string $group_path ) {
		$response = wp_remote_get(
			sprintf( '%s/a/%s/d', $this->api_base, rawurlencode( $group_path ) ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Star-Api-Key' => $this->api_key ),
			)
		);

		return $this->handle( $response );
	}

	/**
	 * Resolve live state of one device. Failures collapse to unknown.
	 *
	 * @param string $group_path Device-group path.
	 * @param string $device_id  Device AccessIdentifier.
	 *
	 * @return string 'online', 'offline', or 'unknown'.
	 */
	public function device_state( string $group_path, string $device_id ): string {
		$response = wp_remote_get(
			sprintf( '%s/a/%s/d/%s', $this->api_base, rawurlencode( $group_path ), rawurlencode( $device_id ) ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Star-Api-Key' => $this->api_key ),
			)
		);

		$result = $this->handle( $response );
		if ( is_wp_error( $result ) || ! \is_array( $result ) || ! isset( $result['Status']['Online'] ) ) {
			return 'unknown';
		}

		return $result['Status']['Online'] ? 'online' : 'offline';
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

		if ( $code >= 200 && $code < 300 ) {
			$decoded = json_decode( $body, true );

			return \is_array( $decoded ) ? $decoded : array();
		}

		if ( 401 === $code ) {
			return new WP_Error(
				'wcpos_star_online_unauthorized',
				__( 'Star Online authentication failed.', 'woocommerce-pos' ),
				array( 'status' => $code )
			);
		}

		if ( 403 === $code ) {
			return new WP_Error(
				'wcpos_star_online_forbidden',
				__( 'Star Online rejected the request. Check that the API key has the required permissions.', 'woocommerce-pos' ),
				array( 'status' => $code )
			);
		}

		Logger::error( 'Star Online request failed', array( 'status' => $code ) );

		return new WP_Error(
			'wcpos_star_online_http_error',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Star Online request failed with status %d.', 'woocommerce-pos' ),
				$code
			),
			array( 'status' => $code )
		);
	}
}
