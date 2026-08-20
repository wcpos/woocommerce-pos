<?php
/**
 * WCPOS sync CORS allow-list.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

/**
 * The single registration point for the request headers the v2 sync lane
 * sends, so CORS preflight lets them through.
 *
 * ## Why this exists
 *
 * Any cross-origin web client (standalone connect mode, a localhost dev
 * server) preflights every engine request: Authorization and the custom
 * WCPOS headers make each one non-simple. The preflight is an OPTIONS
 * request that cannot carry the headers themselves (so it cannot be gated
 * by the WCPOS marker), and a header missing from
 * `Access-Control-Allow-Headers` does not merely lose its feature — the
 * browser refuses the ACTUAL request outright, taking the whole v2 lane
 * down for that client.
 *
 * TWO writers publish the allow-list and both must carry this set:
 * `Init::rest_pre_serve_request()` answers every REST preflight with a
 * self-contained list (the API class only loads for marker/namespace
 * detected requests, which a preflight is not guaranteed to be), and
 * `API::rest_allowed_cors_headers()` feeds WP core's filter. Kept as two
 * hand-maintained lists they drifted apart once — `X-WCPOS-Store` made it
 * into the filter but not the preflight handler, and every engine request
 * from a cross-origin web client failed preflight (pro#425 fallout) —
 * hence this shared merge that both writers call.
 */
final class Cors {
	/**
	 * POS-client request headers beyond WP core's CORS defaults.
	 *
	 * - `Idempotency-Key` / `If-Match` — the v2 write path's standard-header
	 *   mirror ({@see Header_Mirror::HEADERS}).
	 * - `If-None-Match` — conditional sequence-log polling (304s).
	 * - `X-WCPOS-Idempotency-Key` — the checkout/refund lane's idempotency
	 *   key (v1-shaped, predates the ADR 0011 mirror).
	 * - `X-WCPOS-Store` — the till's store scope ({@see Store_Scope::HEADER},
	 *   pro#425).
	 *
	 * @return string[] Header names in their canonical (sent) casing.
	 */
	public static function headers(): array {
		return array_merge(
			Header_Mirror::HEADERS,
			array(
				'If-None-Match',
				'X-WCPOS-Idempotency-Key',
				Store_Scope::HEADER,
			)
		);
	}

	/**
	 * Merge the sync-lane header set into a CORS allow-list.
	 *
	 * @param string[] $allow_headers The allow-list under construction.
	 *
	 * @return string[] The allow-list with the sync-lane headers, deduplicated.
	 */
	public static function allow_headers( array $allow_headers ): array {
		return array_values( array_unique( array_merge( $allow_headers, self::headers() ) ) );
	}
}
