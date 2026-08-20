<?php
/**
 * Sync REST response telemetry.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Adds cheap contextual telemetry to v2 sync responses.
 *
 * Change-response bodies stay deterministic for a given store state so their
 * strong ETags remain honest validators under RFC 9110 section 8.8.1. Volatile
 * timing and memory telemetry therefore lives in headers only.
 */
final class Response_Telemetry {
	/**
	 * Request start times keyed by object hash.
	 *
	 * @var array<string, float>
	 */
	private static $started = array();

	/**
	 * Register the request lifecycle hooks once.
	 */
	public static function register_hooks(): void {
		if ( false === has_filter( 'rest_pre_dispatch', array( self::class, 'start_request' ) ) ) {
			add_filter( 'rest_pre_dispatch', array( self::class, 'start_request' ), 1, 3 );
			add_filter( 'rest_pre_dispatch', array( self::class, 'decorate_precomputed_response' ), PHP_INT_MAX, 3 );
			add_filter( 'rest_request_after_callbacks', array( self::class, 'decorate_callback_response' ), 10, 3 );
			// Auth rejections (rest_authentication_errors) skip dispatch entirely —
			// rest_post_dispatch is the one filter every served response passes.
			add_filter( 'rest_post_dispatch', array( self::class, 'ensure_contextual_headers' ), PHP_INT_MAX, 3 );
		}
	}


	/**
	 * Guarantee X-Server-Load on every served v2 sync response — including
	 * authentication rejections that never reached dispatch.
	 *
	 * @param mixed           $response REST response.
	 * @param mixed           $server   REST server.
	 * @param WP_REST_Request $request  REST request.
	 *
	 * @return mixed
	 */
	public static function ensure_contextual_headers( $response, $server, WP_REST_Request $request ) {
		if ( ! $response instanceof WP_REST_Response || ! self::is_sync_route( $request->get_route() ) ) {
			return $response;
		}
		$headers = $response->get_headers();
		if ( ! isset( $headers['X-Server-Load'] ) ) {
			$response->header( 'X-Server-Load', (string) wp_json_encode( self::server_load() ) );
		}

		return $response;
	}

	/**
	 * Start timing before validation, permissions, and controller dispatch.
	 *
	 * @param mixed           $result  Precomputed response, normally null.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return mixed
	 */
	public static function start_request( $result, $server, WP_REST_Request $request ) {
		if ( self::is_sync_route( $request->get_route() ) ) {
			self::$started[ spl_object_hash( $request ) ] = microtime( true );
		}

		return $result;
	}

	/**
	 * Decorate responses returned early by another rest_pre_dispatch filter.
	 *
	 * @param mixed           $result  Precomputed response.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return mixed
	 */
	public static function decorate_precomputed_response( $result, $server, WP_REST_Request $request ) {
		if ( empty( $result ) ) {
			return $result;
		}

		return self::finish_request( $result, $request );
	}

	/**
	 * Decorate the response after validation, permissions, and the callback.
	 *
	 * @param mixed           $response REST response.
	 * @param mixed           $handler  Matched REST handler.
	 * @param WP_REST_Request $request  REST request.
	 *
	 * @return mixed
	 */
	public static function decorate_callback_response( $response, $handler, WP_REST_Request $request ) {
		return self::finish_request( $response, $request );
	}

	/**
	 * Normalize and decorate a response when timing was started for its request.
	 *
	 * @param mixed           $response REST response.
	 * @param WP_REST_Request $request  REST request.
	 *
	 * @return mixed
	 */
	private static function finish_request( $response, WP_REST_Request $request ) {
		$key = spl_object_hash( $request );
		if ( ! isset( self::$started[ $key ] ) ) {
			return $response;
		}

		$started = self::$started[ $key ];
		unset( self::$started[ $key ] );

		if ( $response instanceof WP_Error ) {
			$response = rest_convert_error_to_response( $response );
		} else {
			$response = rest_ensure_response( $response );
		}

		return self::decorate( $response, $request, $started );
	}

	/**
	 * Attach server load to every sync response and timing to selected routes.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @param WP_REST_Request  $request  Sync REST request.
	 * @param float            $started  Request start time.
	 */
	private static function decorate( WP_REST_Response $response, WP_REST_Request $request, float $started ): WP_REST_Response {
		$response->header( 'X-Server-Load', (string) wp_json_encode( self::server_load() ) );

		// Error bodies (incl. the write contract's golden 4xx shapes) are never
		// touched — contextual headers are the only telemetry they carry.
		if ( 304 === $response->get_status() || $response->get_status() >= 400 ) {
			return $response;
		}

		$route = untrailingslashit( $request->get_route() );
		if ( self::is_changes_route( $route ) ) {
			$duration = self::duration_ms( $started );
			$response->header( 'X-WCPOS-Memory-Peak', (string) memory_get_peak_usage( true ) );
		} elseif ( self::is_metrics_route( $route ) ) {
			$duration = self::add_metrics_envelope( $response, $started );
		} else {
			return $response;
		}

		$response->header( 'Server-Timing', 'wcpos;dur=' . $duration );

		return $response;
	}

	/**
	 * Add the top-level metrics object the client's pull protocol parses.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @param float            $started  Request start time.
	 */
	private static function add_metrics_envelope( WP_REST_Response $response, float $started ): float {
		$metrics = self::metrics( $started );
		$data    = (array) $response->get_data();

		$data['metrics'] = $metrics;
		$response->set_data( $data );

		return $metrics['duration_ms'];
	}

	/**
	 * Build the common metrics payload.
	 *
	 * @param float $started Request start time.
	 *
	 * @return array{duration_ms: float, memory_peak_bytes: int}
	 */
	private static function metrics( float $started ): array {
		return array(
			'duration_ms'       => self::duration_ms( $started ),
			'memory_peak_bytes' => memory_get_peak_usage( true ),
		);
	}

	/**
	 * Wall-clock request duration in milliseconds.
	 *
	 * @param float $started Request start time.
	 */
	private static function duration_ms( float $started ): float {
		return round( ( microtime( true ) - $started ) * 1000, 3 );
	}

	/**
	 * Whether the route receives the nested metrics envelope.
	 *
	 * @param string $route Request route.
	 */
	private static function is_metrics_route( string $route ): bool {
		$base = '/' . Api::ROUTE_NAMESPACE . '/';

		// Only the pull carries a body metrics object. Push responses (success,
		// delete and error) are golden-shaped write-contract surfaces and stay
		// byte-identical — they get headers only.
		$route = strtolower( $route );

		return $base . 'orders/pull' === $route;
	}

	/**
	 * Whether the route belongs to the graduated changes surface.
	 *
	 * @param string $route Request route.
	 */
	private static function is_changes_route( string $route ): bool {
		$base = '/' . Api::ROUTE_NAMESPACE . '/';

		return 0 === strpos( strtolower( $route ), $base . 'changes/' );
	}

	/**
	 * Whether a route belongs to the v2 sync surface.
	 *
	 * @param string $route Request route.
	 */
	private static function is_sync_route( string $route ): bool {
		// WordPress matches REST routes case-insensitively, so route detection must too.
		$base  = '/' . Api::ROUTE_NAMESPACE . '/';
		$route = strtolower( untrailingslashit( $route ) );
		if ( 0 !== strpos( $route, $base ) ) {
			return false;
		}

		$routes = array(
			$base . 'status',
			$base . 'orders/pull',
			$base . 'orders/index/backfill',
			$base . 'changes/sequence-log',
			$base . 'changes/revision-hash',
			$base . 'changes/range-checksum',
			$base . 'changes/config-fingerprint',
			$base . 'changes/tick',
			$base . 'digests',
			$base . 'integrity/scan',
			$base . 'integrity/rebuild',
			$base . 'integrity/bucket',
			$base . 'uuid/backfill',
			$base . 'variations',
			$base . 'resolve/barcode',
		);

		foreach ( Collections::with( 'proxy' ) as $collection ) {
			$routes[] = $base . ltrim( $collection['proxy']['route'], '/' );
		}

		return in_array( $route, $routes, true )
			|| 0 === strpos( $route, $base . 'push/' );
	}

	/**
	 * Get the platform load average without invoking platform shell commands.
	 *
	 * @return array<int, float|int>
	 */
	public static function server_load(): array {
		if ( 0 !== stripos( PHP_OS, 'WIN' ) && function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
			if ( is_array( $load ) && 3 === count( $load ) ) {
				return array_values( $load );
			}
		}

		return array( 0, 0, 0 );
	}
}
