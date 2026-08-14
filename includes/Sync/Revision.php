<?php
/**
 * WCPOS sync store component.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * THE canonical record revision (one function, G1) — a stable content hash of a
 * record's wc/v3 serialization, used for optimistic-concurrency on writes and as
 * the `baseRevision` a client sends back on an update. Distinct from the
 * change-signal's internal change-DETECTION hash (class-change-log): this is the
 * record's content revision exposed to the client.
 *
 * Stable by construction so it changes ONLY when the record's representation does:
 *  - volatile/derived REST fields are stripped — `related_ids` comes back in random
 *    order every GET, `_links` is request-derived — shared with the change-log via
 *    the `woocommerce_pos_sync_revision_excluded_fields` filter;
 *  - keys are sorted recursively, so wc/v3 (or a filter) re-ordering properties is
 *    not seen as a change.
 *
 * IMPORTANT: always compute over the BARE wc/v3 serialization — never a payload that
 * has had `_woocommerce_pos_uuid` re-injected — because the conflict check re-reads
 * the record via wc/v3 (which omits that protected meta), so the two must hash the
 * same bytes.
 */
class Revision {

	/**
	 * #423 step 1b: stamp each proxied record's CANONICAL revision as a
	 * top-level `_rxdb_revision` (transport metadata, like `_rxdb_digest`) so
	 * the client lane builders stop synthesizing date/id stand-ins that can
	 * never match a push-side recompute. Registered at priority 9 — BEFORE
	 * the uuid/digest stampers (priority 10) augment the payload — so the
	 * stamped bytes equal what the write path's revision_for computes from a
	 * bare wc/v3 re-read. Orders additionally identity-strip (HPOS exposes
	 * the protected uuid meta on reads).
	 */
	public static function register_proxy_stamps(): void {
		add_filter( 'woocommerce_pos_sync_proxy_response', array( __CLASS__, 'stamp_proxy_revisions' ), 9, 3 );
	}

	/**
	 * Remove the canonical revision stamper.
	 */
	public static function unregister_proxy_stamps(): void {
		remove_filter( 'woocommerce_pos_sync_proxy_response', array( __CLASS__, 'stamp_proxy_revisions' ), 9 );
	}

	public static function stamp_proxy_revisions( $data, $resource = '', $request = null ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$row = Collections::by_proxy_slug( (string) $resource );
		if ( null === $row ) {
			return $data;
		}
		$is_order = 'order' === $row['object_type'];
		foreach ( $data as $index => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$data[ $index ]['_rxdb_revision'] = $is_order
				? Order_Serializer::canonical_revision( $record )
				: self::compute( $record );
		}
		return $data;
	}

	public static function compute( array $data ): string {
		return 'sha256:' . hash( 'sha256', (string) wp_json_encode( self::canonicalize( $data ) ) );
	}

	public static function canonicalize( array $data ): array {
		$excluded = apply_filters( 'woocommerce_pos_sync_revision_excluded_fields', array( 'related_ids', '_links', 'links' ) );
		foreach ( (array) $excluded as $field ) {
			unset( $data[ $field ] );
		}
		return self::sort_keys_recursive( $data );
	}

	private static function sort_keys_recursive( array $data ): array {
		ksort( $data );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::sort_keys_recursive( $value );
				// Taxonomy collections are sets; other lists retain semantic order.
				if ( ! in_array( $key, array( 'categories', 'tags', 'brands' ), true ) || count( $value ) < 2 || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
					continue;
				}
				foreach ( $value as $term ) {
					$id = is_array( $term ) ? ( $term['id'] ?? null ) : ( is_object( $term ) ? ( $term->id ?? null ) : null );
					if ( ! is_numeric( $id ) ) {
						continue 2;
					}
				}
				usort(
					$data[ $key ],
					static function ( $left, $right ): int {
						$left_id  = is_array( $left ) ? $left['id'] : $left->id;
						$right_id = is_array( $right ) ? $right['id'] : $right->id;
						return (int) $left_id <=> (int) $right_id;
					}
				);
			}
		}
		return $data;
	}
}
