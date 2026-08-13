<?php
/**
 * Public ping REST API controller and bootstrap fast path.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WP_REST_Response;
use const WCPOS\WooCommercePOS\VERSION;
/**
 * Serves the lightweight public status response.
 */
final class Ping {
	private const ROUTE        = '/wcpos/v2/ping';
	private const PRETTY_ROUTE = '/wp-json/wcpos/v2/ping';

	/**
	 * Request-scoped host pressure bucket.
	 *
	 * @var string|null
	 */
	private static $host_pressure_bucket = null;

	/**
	 * Whether host pressure has been read this request.
	 *
	 * @var bool
	 */
	private static $host_pressure_checked = false;
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.Missing -- Typed signatures keep this bootstrap path within its strict size budget.
	/** Detect an exact raw ping request. */
	public static function matches_request( string $method, string $request_uri, ?string $rest_route ): bool {
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}
		$path = explode( '?', $request_uri, 2 )[0];

		return self::ROUTE === $rest_route || ( \strlen( $path ) >= \strlen( self::PRETTY_ROUTE ) && self::PRETTY_ROUTE === substr( $path, -\strlen( self::PRETTY_ROUTE ) ) );
	}

	/** Serve a matching request before the remaining plugins load. */
	public static function maybe_serve(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && \is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && \is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false === strpos( $request_uri, 'wcpos' ) ) {
			return;
		}
		$rest_route = isset( $_GET['rest_route'] ) && \is_string( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : null;
		if ( ! self::matches_request( $method, $request_uri, $rest_route ) ) {
			return;
		}
		$data = self::payload();
		http_response_code( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Expose-Headers: X-WCPOS-Pressure' );
		if ( isset( $data['pressure'] ) ) {
			header( 'X-WCPOS-Pressure: ' . $data['pressure'] );
		}
		if ( 'HEAD' !== $method ) {
			echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON HTTP response.
		}
		// Ship the response before exit's shutdown handlers run: the OTel
		// wordpress instrumentation reads conditional tags (is_404) at shutdown,
		// and with no query having run, WP_DEBUG_DISPLAY sites would append a
		// _doing_it_wrong notice after the JSON body (#1582). Under FPM, closing
		// the request first makes late output unreachable; elsewhere, mute
		// display so shutdown notices cannot corrupt the payload.
		if ( \function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.NoSilencedErrors.Discouraged -- last-resort mute on non-FPM SAPIs; the response is already emitted.
		}
		exit;
	}

	/** Register the canonical REST fallback. */
	public function register_routes(): void {
		register_rest_route(
			'wcpos/v2',
			'/ping',
			array(
				'methods'             => 'GET, HEAD',
				'callback'            => array( $this, 'get_ping' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** @return array<string, string[]> */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- compact typed classification.
	public function wcpos_route_classifications(): array {
		return array( 'public' => array( self::ROUTE ) );
	}

	/** Return the canonical REST response. */
	public function get_ping(): WP_REST_Response {
		$data     = self::payload();
		$response = new WP_REST_Response( $data, 200 );
		if ( isset( $data['pressure'] ) ) {
			$response->header( 'X-WCPOS-Pressure', $data['pressure'] );
		}

		return $response;
	}

	/** Convert normalized load to a pressure bucket, or read host load when omitted (memoized per request so body and header always agree). */
	public static function pressure_bucket( ?float $load = null ): ?string {
		if ( null === $load ) {
			if ( ! self::$host_pressure_checked ) {
				self::$host_pressure_checked = true;
				self::$host_pressure_bucket  = self::read_host_pressure_bucket();
			}

			return self::$host_pressure_bucket;
		}
		if ( $load < 0.9 ) {
			return 'low';
		}

		return $load <= 1.8 ? 'elevated' : 'high';
	}

	/** Read the host load average and convert it to a bucket, or null when unavailable. */
	private static function read_host_pressure_bucket(): ?string {
		if ( ! \function_exists( 'sys_getloadavg' ) || ! \is_array( $average = @sys_getloadavg() ) || ! isset( $average[0] ) ) { // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure -- call only after availability check.
			return null;
		}
		/** @var int|null $cpus */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- local static type.
		static $cpus = null;
		if ( null === $cpus ) {
			$cpuinfo = @file_get_contents( '/proc/cpuinfo' );
			$found   = \is_string( $cpuinfo ) ? preg_match_all( '/^processor\s*:/m', $cpuinfo ) : false;
			$cpus    = \is_int( $found ) && $found > 0 ? $found : 1;
		}

		return self::pressure_bucket( (float) $average[0] / $cpus );
	}

	/** @return array<string, bool|int|string> */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- compact typed payload.
	private static function payload(): array {
		$data     = array( 'ok' => true, 'ts' => time(), 'v' => VERSION ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- fixed four-field maximum.
		$pressure = self::pressure_bucket();
		if ( null !== $pressure ) {
			$data['pressure'] = $pressure;
		}

		return $data;
	}
}
