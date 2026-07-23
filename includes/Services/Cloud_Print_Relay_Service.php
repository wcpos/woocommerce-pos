<?php
/**
 * WCPOS Cloud Print relay integration.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WP_Error;

/**
 * Talks to the WCPOS Cloud Print relay: registration/consent, job-pending
 * hints, and printer status queries. All relay knowledge lives here; the
 * REST controller only wraps these methods in responses.
 */
class Cloud_Print_Relay_Service {
	const RELAY_URL = 'https://cloudprint.wcpos.com';

	/**
	 * Server-owned relay state {enabled, site_key, hint_secret, registered_at}.
	 * Deliberately a separate option from the client-writable cloud-print
	 * settings so no settings write path can clobber or leak it.
	 */
	const OPTION = 'woocommerce_pos_cloud_print_relay';

	const VERIFY_TRANSIENT        = 'wcpos_relay_verify_token';
	const STATUS_CACHE_TTL        = 30;
	const STATUS_TRANSIENT_PREFIX = 'wcpos_relay_status_';

	/**
	 * Site-wide "relay unreachable" marker: one failed status call stops
	 * further calls for the cache window, so a hanging relay costs one
	 * timeout per window instead of one per printer per admin tab.
	 */
	const DOWN_TRANSIENT = 'wcpos_relay_down';

	const REREGISTER_GUARD     = 3600;
	const REREGISTER_TRANSIENT = 'wcpos_relay_reregister_guard';
	const REREGISTER_HOOK      = 'wcpos_relay_reregister';

	/**
	 * Register relay event handlers.
	 */
	public function __construct() {
		add_action( 'woocommerce_pos_print_job_created', array( $this, 'send_hint' ), 10, 2 );
		add_action( self::REREGISTER_HOOK, array( $this, 'reregister' ) );
	}

	/**
	 * Return the pending verification token, if a registration is in flight.
	 *
	 * @return string|null
	 */
	public static function pending_verification_token(): ?string {
		$token = get_transient( self::VERIFY_TRANSIENT );
		if ( false === $token ) {
			return null;
		}
		// Single-use: consumed on first read, so a second reader (or a racing
		// registration attempt for this site) cannot replay the proof.
		delete_transient( self::VERIFY_TRANSIENT );

		return (string) $token;
	}

	/**
	 * Register this site with the relay and persist its credentials.
	 *
	 * The relay fetches the verification token from this site while the
	 * outbound request below is still open, proving consent and that WCPOS
	 * is actually installed at the claimed URL.
	 *
	 * @param bool $admin_initiated True for an explicit admin action; false for
	 *                              background re-registration, which must never
	 *                              flip a relay the admin has since disabled.
	 *
	 * @return array|WP_Error Public relay fields or an error.
	 */
	public static function register_site( bool $admin_initiated = true ) {
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
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/i', $site_key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/i', $hint_secret ) ) {
			return self::registration_error( __( 'Relay registration returned invalid credentials.', 'woocommerce-pos' ) );
		}

		// Re-read at completion time: a background re-registration must adopt
		// the admin's latest enabled/disabled intent, not the state at launch.
		$current = self::settings();
		update_option(
			self::OPTION,
			array(
				'enabled'       => $admin_initiated ? true : ! empty( $current['enabled'] ),
				'site_key'      => strtolower( $site_key ),
				'hint_secret'   => strtolower( $hint_secret ),
				'registered_at' => time(),
			)
		);
		delete_transient( self::DOWN_TRANSIENT );

		// The printer URL is always rebuilt from the validated site_key —
		// never from the relay response — so a compromised relay cannot
		// point printers (and their tokens) at another host.
		return array(
			'enabled'          => true,
			'printer_base_url' => self::printer_base_url( strtolower( $site_key ) ),
		);
	}

	/**
	 * Disable relay use while retaining the deterministic site credentials.
	 *
	 * @return array Public relay state.
	 */
	public static function disable(): array {
		$stored            = self::settings();
		$stored['enabled'] = false;
		update_option( self::OPTION, $stored );

		return array( 'enabled' => false );
	}

	/**
	 * Public relay state for REST responses: never includes the secret.
	 *
	 * @return array
	 */
	public static function public_state(): array {
		$relay = self::settings();
		$state = array( 'enabled' => ! empty( $relay['enabled'] ) );
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', (string) ( $relay['site_key'] ?? '' ) ) ) {
			$state['printer_base_url'] = self::printer_base_url( (string) $relay['site_key'] );
		}

		return $state;
	}

	/**
	 * Send a best-effort hint when a polling printer gets a job.
	 *
	 * @param int    $job_id     Print job ID.
	 * @param string $printer_id Printer ID.
	 */
	public function send_hint( $job_id, $printer_id ): void {
		$relay = self::settings();
		if ( empty( $relay['enabled'] ) || ! self::valid_credentials( $relay ) ) {
			return;
		}

		$printer = ( new Cloud_Print_Registry() )->get_printer( sanitize_text_field( (string) $printer_id ) );
		if ( null === $printer || ! Provider::is_polling( (string) ( $printer['provider'] ?? '' ) ) ) {
			return;
		}

		$site_key  = (string) $relay['site_key'];
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
				'headers'  => self::signed_headers( 'POST', $path, $timestamp, $body, (string) $relay['hint_secret'] ),
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
		$relay = self::settings();
		if ( empty( $relay['enabled'] ) || ! self::valid_credentials( $relay ) ) {
			return null;
		}

		$key    = self::STATUS_TRANSIENT_PREFIX . $printer_id;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return ! empty( $cached['failed'] ) ? null : $cached;
		}
		if ( false !== get_transient( self::DOWN_TRANSIENT ) ) {
			return null;
		}

		$site_key  = (string) $relay['site_key'];
		$path      = '/api/status/' . $site_key;
		$timestamp = (string) time();
		$response  = wp_remote_get(
			self::relay_url() . $path . '?printer_id=' . rawurlencode( $printer_id ),
			array(
				'timeout' => 3,
				'headers' => self::signed_headers( 'GET', $path, $timestamp, $printer_id, (string) $relay['hint_secret'] ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::note_status_failure( $key );

			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		// Exact match on the relay's machine-readable error field; the
		// registry was rebuilt, so a guarded re-registration restores the
		// same deterministic site key.
		if ( 404 === $code && \is_array( $data ) && 'unknown site' === ( $data['error'] ?? '' ) ) {
			self::schedule_reregistration();
		}
		if ( 200 !== $code || ! \is_array( $data ) ) {
			self::note_status_failure( $key );

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
	 * The relay's block signal for a printer, when it reports one.
	 *
	 * Reads the same transient cache as status(), so calling both costs one
	 * relay round-trip at most.
	 *
	 * @param string $printer_id Printer ID.
	 *
	 * @return string|null
	 */
	public static function status_detail( string $printer_id ): ?string {
		$status = self::status( $printer_id );
		if ( null !== $status && 'blocked' === $status['origin_status'] && '' !== $status['origin_block_signal'] ) {
			return $status['origin_block_signal'];
		}

		return null;
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
	 *
	 * Bails when the admin has disabled the relay in the meantime — a
	 * pending cron event must never re-enable it behind their back.
	 */
	public function reregister(): void {
		$relay = self::settings();
		if ( empty( $relay['enabled'] ) ) {
			return;
		}
		self::register_site( false );
	}

	/**
	 * Return stored relay settings.
	 *
	 * @return array
	 */
	private static function settings(): array {
		$stored = get_option( self::OPTION, array() );

		return \is_array( $stored ) ? $stored : array();
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
	 * Record a failed status call: per-printer negative cache plus the
	 * site-wide down marker so other printers skip their calls entirely.
	 *
	 * @param string $transient_key Per-printer status transient key.
	 */
	private static function note_status_failure( string $transient_key ): void {
		set_transient( $transient_key, array( 'failed' => true ), self::STATUS_CACHE_TTL );
		set_transient( self::DOWN_TRANSIENT, true, self::STATUS_CACHE_TTL );
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
