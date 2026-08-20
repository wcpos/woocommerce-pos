<?php
/**
 * Opt-in WCPOS REST response envelope.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\API as Root_API;
use WCPOS\WooCommercePOS\API\V2\Ping;
use WP_HTTP_Response;
use WP_REST_Request;

/** Mirrors response metadata into an opt-in body envelope. */
final class Response_Envelope {
	/** Register the response filter once. */
	public static function register_hooks(): void {
		if ( false === has_filter( 'rest_post_dispatch', array( self::class, 'filter_response' ) ) ) {
			add_filter( 'rest_post_dispatch', array( self::class, 'filter_response' ), PHP_INT_MAX - 1, 3 );
		}
	}

	/**
	 * Wrap an opted-in successful WCPOS response.
	 *
	 * @param mixed           $response REST response.
	 * @param mixed           $server   REST server.
	 * @param WP_REST_Request $request  REST request.
	 *
	 * @return mixed
	 */
	public static function filter_response( $response, $server, WP_REST_Request $request ) {
		$route = strtolower( '/' . ltrim( $request->get_route(), '/' ) );
		if (
			! $response instanceof WP_HTTP_Response
			|| '1' !== (string) $request->get_param( '_wcpos_envelope' )
			|| ! self::is_wcpos_request( $request, $route )
			|| 0 === strpos( $route, '/' . Api::ROUTE_NAMESPACE . '/push/' )
			// Raw byte responses (receipt PDFs, printer payloads) serve
			// get_raw_body() through their own rest_pre_serve_request callback;
			// wrapping their unused JSON data would promise an envelope the
			// client never receives.
			|| $response instanceof \WCPOS\WooCommercePOS\API\V1\Raw_Response
			// The reachability ping is deliberately dependency-free: its live
			// fast path (Ping::maybe_serve) echoes and exits before any REST
			// filter exists, and its payload already body-mirrors the pressure
			// metadata. Exempting it here keeps the REST fallback identical to
			// the fast path instead of pretending coverage the live route
			// cannot have.
			|| '/' . Api::ROUTE_NAMESPACE . '/ping' === $route
			|| 304 === $response->get_status()
			|| $response->get_status() >= 400
		) {
			return $response;
		}

		$headers = array_change_key_case( $response->get_headers(), CASE_LOWER );
		$meta    = array( 'v' => 1 );
		foreach ( array(
			'total' => 'x-wp-total',
			'total_pages' => 'x-wp-totalpages',
		) as $field => $header ) {
			if ( isset( $headers[ $header ] ) && is_numeric( $headers[ $header ] ) && (int) $headers[ $header ] >= 0 ) {
				$meta[ $field ] = (int) $headers[ $header ];
			}
		}

		$page = $request->get_param( 'page' );
		if ( is_numeric( $page ) && (int) $page > 0 ) {
			$meta['page'] = (int) $page;
		} elseif ( isset( $meta['total_pages'] ) ) {
			$meta['page'] = 1;
		}

		if ( isset( $headers['etag'] ) ) {
			if ( '' !== (string) $headers['etag'] ) {
				$meta['validator'] = (string) $headers['etag'];
			}
		} else {
			$pressure = isset( $headers['x-wcpos-pressure'] ) ? (string) $headers['x-wcpos-pressure'] : Ping::pressure_bucket();
			if ( null !== $pressure && '' !== $pressure ) {
				$meta['pressure'] = $pressure;
			}

			$server_load = isset( $headers['x-server-load'] )
				? json_decode( (string) $headers['x-server-load'], true )
				: Response_Telemetry::server_load();
			if ( is_array( $server_load ) && 3 === count( $server_load ) ) {
				$meta['server_load'] = array_values( $server_load );
			}

			$memory_peak = isset( $headers['x-wcpos-memory-peak'] )
				? (int) $headers['x-wcpos-memory-peak']
				: memory_get_peak_usage( true );
			if ( $memory_peak >= 0 ) {
				$meta['memory_peak_bytes'] = $memory_peak;
			}
		}

		// Serialize response-level links INTO data before wrapping (single-item
		// responses attach _links at serving time via response_to_data; without
		// this, WP would append _links as a third top-level key and body.data
		// would lose them), then clear them so they are not appended again.
		$data = $response->get_data();
		if ( $response instanceof \WP_REST_Response && \function_exists( 'rest_get_server' ) ) {
			$links = $response->get_links();
			if ( array() !== $links ) {
				$data = rest_get_server()->response_to_data( $response, false );
				foreach ( array_keys( $links ) as $rel ) {
					$response->remove_link( (string) $rel );
				}
			}
		}

		$response->set_data(
			array(
				'data' => $data,
				'_wcpos' => $meta,
			)
		);

		return $response;
	}

	/**
	 * Whether this is a WCPOS namespace route or explicitly marked request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param string          $route   Normalized REST route.
	 */
	private static function is_wcpos_request( WP_REST_Request $request, string $route ): bool {
		foreach ( Root_API::ROUTE_NAMESPACES as $namespace ) {
			if ( 0 === strpos( $route, '/' . strtolower( $namespace ) . '/' ) ) {
				return true;
			}
		}

		return '1' === trim( (string) $request->get_header( 'X-WCPOS' ) );
	}
}
