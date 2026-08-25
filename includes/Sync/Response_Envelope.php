<?php
/**
 * Opt-in WCPOS REST response envelope.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\API as Root_API;
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
			if ( isset( $headers['x-wcpos-pressure'] ) && '' !== (string) $headers['x-wcpos-pressure'] ) {
				$meta['pressure'] = (string) $headers['x-wcpos-pressure'];
			}

			if ( isset( $headers['x-server-load'] ) ) {
				$server_load = json_decode( (string) $headers['x-server-load'], true );
				if ( is_array( $server_load ) && 3 === count( $server_load ) ) {
					$meta['server_load'] = array_values( $server_load );
				}
			}

			if ( isset( $headers['x-wcpos-memory-peak'] ) && (int) $headers['x-wcpos-memory-peak'] >= 0 ) {
				$meta['memory_peak_bytes'] = (int) $headers['x-wcpos-memory-peak'];
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
	 * The marker is read from BOTH carriers, exactly as `wcpos_request()` reads
	 * it, and for the reason that helper has two: a proxy or WAF that strips
	 * request headers deletes `X-WCPOS` in transit, which is why the client
	 * publishes the same marker as the `wcpos` query var and sends it
	 * unconditionally. Checking only the header made the body mirror die on
	 * precisely the hostile condition it exists to survive — a stripping proxy
	 * removes `X-WP-Total` from the response AND `X-WCPOS` from the request, so
	 * a non-namespace route lost the header and its fallback together and no
	 * total could reach the client at all.
	 *
	 * `Init::init_rest_api()` already treats the query var as sufficient to load
	 * the whole POS API; a request that reached a route that way must be able to
	 * reach its envelope too, or the two disagree about what a POS request is.
	 * This does not widen the opt-in: `_wcpos_envelope=1` is still required, and
	 * route registration is untouched.
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

		if ( '1' === trim( (string) $request->get_header( 'X-WCPOS' ) ) ) {
			return true;
		}

		// The query-var marker is ambient — it belongs to the request WordPress is
		// SERVING, not to every request dispatched while serving it. Core re-applies
		// `rest_post_dispatch` to sub-requests in two places: `_embed` expansion
		// (class-wp-rest-server.php) and the batch endpoint. Without this check a
		// marked `?_embed=1` read would wrap each embedded sub-response too, and the
		// client would find `{data,_wcpos}` where it expects an embedded record.
		// The header carrier has no such problem — a sub-request is constructed fresh
		// and carries no headers — which is why only this branch is gated.
		return self::is_served_route( $route ) && \wcpos_request( 'query_var' );
	}

	/**
	 * Whether this route is the one WordPress is actually serving.
	 *
	 * `rest_route` holds the OUTER route for the whole request and, as
	 * `wcpos_request()` documents, keeps that value during internal re-dispatches —
	 * which is exactly what makes it usable to tell an outer request from a
	 * sub-request. Anything that is not the served route is a sub-request.
	 *
	 * @param string $route Normalized REST route.
	 */
	private static function is_served_route( string $route ): bool {
		global $wp;
		$outer = isset( $wp->query_vars['rest_route'] ) ? (string) $wp->query_vars['rest_route'] : '';
		if ( '' === $outer ) {
			return false;
		}

		return strtolower( '/' . ltrim( $outer, '/' ) ) === $route;
	}
}
