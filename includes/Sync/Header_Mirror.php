<?php
/**
 * WCPOS sync write header mirror.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WP_Error;
use WP_REST_Request;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Standard-header MIRROR cross-check (ADR 0011) for the write path — the generic
 * per-collection push (Woo_RxDB_Sync_Write_Controller, /push/{collection}).
 *
 * The JSON body stays CANONICAL. The client MAY also send the sync-control signals as standard HTTP
 * headers — Idempotency-Key (= mutationId) and If-Match (= baseRevision) — for a standards-shaped wire
 * surface (proxies/observability). These headers are only a cross-check: a mangled-but-parseable header
 * (a proxy requoting, a Cloudflare-weakened ETag) is worse than a missing one, so when BOTH a header and
 * its body field are present and DISAGREE we reject (422) rather than trust either side.
 */
final class Header_Mirror {
	/** The header names this contract adds, in their canonical (sent) casing. */
	public const HEADERS = array( 'Idempotency-Key', 'If-Match' );

	/**
	 * @return WP_Error|null  A 422 WP_Error on header/body divergence, or null when they agree or are absent.
	 */
	public static function assert( WP_REST_Request $request, string $body_mutation_id, $body_base_revision ) {
		$header_mutation_id = self::header_value( $request, 'idempotency-key' );
		if ( null !== $header_mutation_id && $header_mutation_id !== $body_mutation_id ) {
			return new WP_Error( 'woo_rxdb_sync_header_body_mismatch', 'Idempotency-Key header disagrees with body mutationId.', array( 'status' => 422 ) );
		}
		$header_base_revision = self::unquote_entity_tag( self::header_value( $request, 'if-match' ) );
		// A non-string baseRevision (a malformed body) is treated as absent, not cast to "Array" with a notice.
		$body_revision = is_string( $body_base_revision ) ? $body_base_revision : '';
		if ( null !== $header_base_revision && $header_base_revision !== $body_revision ) {
			return new WP_Error( 'woo_rxdb_sync_header_body_mismatch', 'If-Match header disagrees with body baseRevision.', array( 'status' => 422 ) );
		}
		return null;
	}

	/** rest_allowed_cors_headers filter: let the mirror headers through CORS preflight so a cross-origin
	 * browser transport (an absolute sync base URL) can send them — WP's default allow-list excludes them. */
	public static function allow_cors_headers( array $headers ): array {
		return array_values( array_unique( array_merge( $headers, self::HEADERS ) ) );
	}

	private static function header_value( WP_REST_Request $request, string $name ): ?string {
		$value = $request->get_header( $name );
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/** Strip an optional weak indicator (W/) and surrounding double quotes from an entity-tag, so the bare
	 * revision compares equal even if an intermediary weakened a strong tag (RFC 9110 §8.8.3). */
	private static function unquote_entity_tag( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = preg_replace( '/^W\//', '', trim( $value ) );
		if ( strlen( $value ) >= 2 && '"' === $value[0] && '"' === substr( $value, -1 ) ) {
			$value = substr( $value, 1, -1 );
		}
		return $value;
	}
}
