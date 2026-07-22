<?php
/**
 * WCPOS Cloud Print relay integration.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WP_Error;
use WP_REST_Response;

/**
 * Cloud_Print_Relay_Service class.
 */
class Cloud_Print_Relay_Service {
	const RELAY_URL              = 'https://cloudprint.wcpos.com';
	const VERIFY_TRANSIENT       = 'wcpos_relay_verify_token';
	const STATUS_CACHE_TTL       = 30;
	const REREGISTER_GUARD       = 3600;
	const STATUS_TRANSIENT_PREFIX = 'wcpos_relay_status_';
	const REREGISTER_TRANSIENT   = 'wcpos_relay_reregister_guard';
	const REREGISTER_HOOK        = 'wcpos_relay_reregister';

	/**
	 * Register relay event handlers.
	 */
	public function __construct() {
		add_action( 'wcpos_print_job_created', array( self::class, 'send_hint' ), 10, 2 );
		add_action( self::REREGISTER_HOOK, array( self::class, 'reregister' ) );
	}

	/**
	 * Return a pending verification token to the relay.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function verification_response() {
		$token = get_transient( self::VERIFY_TRANSIENT );
		if ( false === $token ) {
			return new WP_Error(
				'wcpos_relay_no_pending_verification',
				__( 'No relay verification is pending.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'token' => (string) $token ), 200 );
	}

	/**
	 * Register this site with the relay.
	 *
	 * The relay calls verification_response() while this blocking request is open.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function register_response() {
		$result = self::register_site();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Disable relay use while retaining deterministic site credentials.
	 *
	 * @return WP_REST_Response
	 */
	public static function disable_response(): WP_REST_Response {
		$settings = get_option( Cloud_Print_Registry::OPTION, array() );
		$settings = \is_array( $settings ) ? $settings : array();
		$relay    = isset( $settings['relay'] ) && \is_array( $settings['relay'] ) ? $settings['relay'] : array();
		$relay['enabled']  = false;
		$settings['relay'] = $relay;
		update_option( Cloud_Print_Registry::OPTION, $settings );

		return new WP_REST_Response( array( 'enabled' => false ), 200 );
	}

	/**
	 * Send a best-effort hint when a polling printer gets a job.
	 *
	 * @param int    $job_id     Print job ID.
	 * @param string $printer_id Printer ID.
	 */
	public static function send_hint( $job_id, $printer_id ): void {
		$relay = self::relay_settings();
		if ( empty( $relay['enabled'] ) || ! self::valid_credentials( $relay ) ) {
			return;
		}

		$printer = ( new Cloud_Print_Registry() )->get_printer( sanitize_text_field( (string) $printer_id ) );
		if ( null === $printer || ! Provider::is_polling( (string) ( $printer['provider'] ?? '' ) ) ) {
			return;
		}

		$site_key  = (string) $relay['site_key'];
		$secret    = (string) $relay['hint_secret'];
		$path      = '/api/hint/' . $site_key;
		$timestamp = (string) time();
		$body      = wp_json_encode( array( 'printer_id' => (string) $printer_id ) );

		// Best-effort by design: a lost hint costs at most one heartbeat
		// interval of print latency, so failures are deliberately silent.
		wp_remote_post(
			self::relay_url() . $path,
			array(
				'blocking' => false,
				'timeout'  => 2,
				'headers'  => self::signed_headers( 'POST', $path, $timestamp, $body, $secret ),
				'body'     => $body,
			)
		);
	}

	/**
	 * Query the cached relay status for a printer.
	 *
	 * @param string $printer_id Printer ID.
	 *
	 * @return array|null Relay status or null on failure/when disabled.
	 */
	public static function status( string $printer_id ): ?array {
		$relay = self::relay_settings();
		if ( empty( $relay['enabled'] ) || ! self::valid_credentials( $relay ) ) {
			return null;
		}

		$key    = self::STATUS_TRANSIENT_PREFIX . $printer_id;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return ! empty( $cached['failed'] ) ? null : $cached;
		}

		$site_key = (string) $relay['site_key'];
		$path     = '/api/status/' . $site_key;
		$timestamp = (string) time();
		$response  = wp_remote_get(
			self::relay_url() . $path . '?printer_id=' . rawurlencode( $printer_id ),
			array(
				'timeout' => 3,
				'headers' => self::signed_headers( 'GET', $path, $timestamp, $printer_id, (string) $relay['hint_secret'] ),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( $key, array( 'failed' => true ), self::STATUS_CACHE_TTL );

			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 404 === $code && false !== stripos( $body, 'unknown site' ) ) {
			self::schedule_reregistration();
		}
		$data = json_decode( $body, true );
		if ( 200 !== $code || ! \is_array( $data ) ) {
			set_transient( $key, array( 'failed' => true ), self::STATUS_CACHE_TTL );

			return null;
		}

		$status = array(
			'origin_status'         => sanitize_text_field( (string) ( $data['origin_status'] ?? '' ) ),
			'origin_block_signal'   => sanitize_text_field( (string) ( $data['origin_block_signal'] ?? '' ) ),
			'last_seen_seconds_ago' => isset( $data['last_seen_seconds_ago'] ) ? max( 0, (int) $data['last_seen_seconds_ago'] ) : null,
		);
		set_transient( $key, $status, self::STATUS_CACHE_TTL );

		return $status;
	}

	/**
	 * Build a relay-compatible HMAC signature.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      Request path without query string.
	 * @param string $timestamp Unix timestamp.
	 * @param string $payload   Signed payload.
	 * @param string $secret    Hex-encoded signing secret.
	 *
	 * @return string Lowercase hexadecimal signature.
	 */
	public static function sign( string $method, string $path, string $timestamp, string $payload, string $secret ): string {
		$key = hex2bin( $secret );

		return false === $key ? '' : hash_hmac( 'sha256', $method . "\n" . $path . "\n" . $timestamp . "\n" . $payload, $key );
	}

	/**
	 * Build the public printer URL for a registered site.
	 *
	 * @param string $site_key Relay site key.
	 */
	public static function printer_base_url( string $site_key ): string {
		return self::relay_url() . '/p/' . rawurlencode( $site_key );
	}

	/**
	 * Re-register after the relay reports an unknown site.
	 */
	public static function reregister(): void {
		self::register_site();
	}

	/**
	 * Perform relay registration and persist its authoritative response.
	 *
	 * @return array|WP_Error Public relay fields or an error.
	 */
	private static function register_site() {
		try {
			$token = bin2hex( random_bytes( 24 ) );
		} catch ( \Exception $exception ) {
			return self::registration_error( __( 'Could not create a relay verification token.', 'woocommerce-pos' ) );
		}
		set_transient( self::VERIFY_TRANSIENT, $token, 5 * MINUTE_IN_SECONDS );
		$response = wp_remote_post(
			self::relay_url() . '/api/register',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'site_url'     => home_url(),
						'verify_token' => $token,
					)
				),
			)
		);
		delete_transient( self::VERIFY_TRANSIENT );

		if ( is_wp_error( $response ) ) {
			return self::registration_error( $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 201 !== wp_remote_retrieve_response_code( $response ) ) {
			$message = \is_array( $data ) ? (string) ( $data['message'] ?? $data['error'] ?? '' ) : '';

			return self::registration_error( '' !== $message ? sanitize_text_field( $message ) : __( 'Relay registration failed.', 'woocommerce-pos' ) );
		}

		$data        = \is_array( $data ) ? $data : array();
		$site_key    = sanitize_text_field( (string) ( $data['site_key'] ?? '' ) );
		$hint_secret = sanitize_text_field( (string) ( $data['hint_secret'] ?? '' ) );
		$base_url    = esc_url_raw( (string) ( $data['printer_base_url'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/i', $site_key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/i', $hint_secret ) || '' === $base_url ) {
			return self::registration_error( __( 'Relay registration returned invalid credentials.', 'woocommerce-pos' ) );
		}

		$settings          = get_option( Cloud_Print_Registry::OPTION, array() );
		$settings          = \is_array( $settings ) ? $settings : array();
		$settings['relay'] = array(
			'enabled'       => true,
			'site_key'      => $site_key,
			'hint_secret'   => strtolower( $hint_secret ),
			'registered_at' => time(),
		);
		update_option( Cloud_Print_Registry::OPTION, $settings );

		return array(
			'enabled'          => true,
			'printer_base_url' => $base_url,
		);
	}

	/**
	 * Return stored relay settings.
	 *
	 * @return array
	 */
	private static function relay_settings(): array {
		$settings = get_option( Cloud_Print_Registry::OPTION, array() );

		return \is_array( $settings ) && isset( $settings['relay'] ) && \is_array( $settings['relay'] ) ? $settings['relay'] : array();
	}

	/**
	 * Check stored relay credentials before using them for signing.
	 *
	 * @param array $relay Relay settings.
	 */
	private static function valid_credentials( array $relay ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/i', (string) ( $relay['site_key'] ?? '' ) )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/i', (string) ( $relay['hint_secret'] ?? '' ) );
	}

	/**
	 * Build signed relay request headers.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      Request path.
	 * @param string $timestamp Unix timestamp.
	 * @param string $payload   Signed payload.
	 * @param string $secret    Hex-encoded signing secret.
	 *
	 * @return array
	 */
	private static function signed_headers( string $method, string $path, string $timestamp, string $payload, string $secret ): array {
		return array(
			'X-Relay-Timestamp' => $timestamp,
			'X-Relay-Signature' => self::sign( $method, $path, $timestamp, $payload, $secret ),
			'Content-Type'      => 'application/json',
		);
	}

	/**
	 * Schedule one guarded background re-registration.
	 */
	private static function schedule_reregistration(): void {
		if ( false !== get_transient( self::REREGISTER_TRANSIENT ) ) {
			return;
		}
		set_transient( self::REREGISTER_TRANSIENT, true, self::REREGISTER_GUARD );
		wp_schedule_single_event( time(), self::REREGISTER_HOOK );
	}

	/**
	 * Build a consistent registration error.
	 *
	 * @param string $message Error message.
	 *
	 * @return WP_Error
	 */
	private static function registration_error( string $message ): WP_Error {
		return new WP_Error( 'wcpos_relay_registration_failed', $message, array( 'status' => 502 ) );
	}

	/**
	 * Return the filterable relay base URL.
	 */
	private static function relay_url(): string {
		return untrailingslashit( esc_url_raw( (string) apply_filters( 'woocommerce_pos_cloud_print_relay_url', self::RELAY_URL ) ) );
	}
}
