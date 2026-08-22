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
 * ONE writer publishes the allow-list — {@see \WCPOS\WooCommercePOS\Rest_Cors},
 * which owns both the `rest_allowed_cors_headers` filter and the preflight
 * response. This class stays as its data collaborator: it is where the sync
 * lane declares the headers it sends, beside {@see Header_Mirror::HEADERS}
 * and {@see Store_Scope::HEADER}, rather than a second publisher. Kept as
 * two hand-maintained lists these headers drifted apart twice — first
 * `X-WCPOS-Store` (pro#425 fallout, 23bcdb47), then
 * `X-WCPOS-Idempotency-Key` (118a091f) — each time failing every
 * cross-origin request that carried them at preflight. That is the split
 * the single owner exists to make impossible.
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
