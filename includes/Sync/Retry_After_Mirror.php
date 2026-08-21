<?php
/**
 * Retry-After response body mirror.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\API as Root_API;
use WP_HTTP_Response;
use WP_REST_Request;

/** Mirrors Retry-After into WCPOS REST error bodies. */
final class Retry_After_Mirror {
	/** Register the response filter once. */
	public static function register_hooks(): void {
		if ( false === has_filter( 'rest_post_dispatch', array( self::class, 'filter_response' ) ) ) {
			add_filter( 'rest_post_dispatch', array( self::class, 'filter_response' ), PHP_INT_MAX - 2, 3 );
		}
	}

	/**
	 * Normalize delay-seconds or an HTTP-date to whole seconds.
	 *
	 * @param string   $value Retry-After header value.
	 * @param int|null $now   Current Unix timestamp.
	 */
	public static function seconds_from_header( string $value, ?int $now = null ): ?int {
		if ( 1 === preg_match( '/^[0-9]+$/D', $value ) ) {
			$digits = ltrim( $value, '0' );
			$digits = '' === $digits ? '0' : $digits;
			if ( strlen( $digits ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $digits ) === strlen( (string) PHP_INT_MAX ) && strcmp( $digits, (string) PHP_INT_MAX ) > 0 ) ) {
				return null;
			}
			return (int) $digits;
		}
		$target = strtotime( $value );
		if ( false === $target || ! in_array( $value, array( gmdate( 'D, d M Y H:i:s \G\M\T', $target ), gmdate( 'l, d-M-y H:i:s \G\M\T', $target ), gmdate( 'D M j H:i:s Y', $target ), gmdate( 'D M  j H:i:s Y', $target ) ), true ) ) {
			return null;
		}
		return max( 0, (int) ceil( $target - ( $now ?? time() ) ) );
	}

	/**
	 * Mirror Retry-After for WCPOS error responses.
	 *
	 * @param mixed           $response REST response.
	 * @param mixed           $server   REST server.
	 * @param WP_REST_Request $request  REST request.
	 *
	 * @return mixed
	 */
	public static function filter_response( $response, $server, WP_REST_Request $request ) {
		$route = strtolower( '/' . ltrim( $request->get_route(), '/' ) );
		if ( ! $response instanceof WP_HTTP_Response || $response->get_status() < 400 || ! self::is_wcpos_request( $request, $route ) ) {
			return $response;
		}
		$data    = $response->get_data();
		$headers = array_change_key_case( $response->get_headers(), CASE_LOWER );
		if ( ! is_array( $data ) ) {
			return $response;
		}
		if (
			! isset( $headers['retry-after'] )
			&& 503 === $response->get_status()
			&& 'wcpos_sync_unavailable' === ( $data['code'] ?? null )
			&& Health::RETRY_AFTER_SECONDS === ( $data['data']['retry_after_seconds'] ?? null )
		) {
			$response->header( 'Retry-After', (string) Health::RETRY_AFTER_SECONDS );
			return $response;
		}
		if ( ! isset( $headers['retry-after'] ) ) {
			return $response;
		}
		if ( ! isset( $data['data'] ) ) {
			$data['data'] = array();
		}
		if ( ! is_array( $data['data'] ) || array_key_exists( 'retry_after_seconds', $data['data'] ) ) {
			return $response;
		}
		$seconds = self::seconds_from_header( (string) $headers['retry-after'] );
		if ( null !== $seconds ) {
			$data['data']['retry_after_seconds'] = $seconds;
			$response->set_data( $data );
		}
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
