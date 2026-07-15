<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WC_REST_Products_Controller;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL allowlists are fixed class constants; values use placeholders.

/**
 * Barcode resolve endpoint (POS scan path).
 *
 * Why: a cashier scanning an unknown barcode needs one round trip that
 * answers product OR variation directly — no parent->child REST dance.
 * Discovery is raw SQL over ids only (ADR 0003) and hookable so plugins
 * that own barcode storage can override resolution entirely; the returned
 * payload is hydrated through the filtered REST serialization path.
 *
 * Not final: the woocommerce_pos_sync_resolve_barcode_matches filter is applied
 * through a protected seam that the unit harness subclasses (its
 * apply_filters stub is identity).
 */
class Resolve_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;

	private const PRODUCT_POST_TYPES_SQL = "('product','product_variation')";
	private const BARCODE_META_KEYS_SQL  = "('_sku','_global_unique_id','_barcode')";


	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'resolve/barcode',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'resolve_barcode' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'code' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * GET /resolve/barcode?code=<string>.
	 *
	 * 200 always: not-found is a result, not an error — the POS must be able
	 * to distinguish "no such barcode" from a failed request.
	 */
	public function resolve_barcode( WP_REST_Request $request ) {
		$started = microtime( true );
		$code    = trim( (string) ( $request->get_param( 'code' ) ?? '' ) );

		if ( '' === $code ) {
			return new WP_Error( 'woocommerce_pos_sync_missing_code', 'Barcode resolve requires a non-empty code parameter', array( 'status' => 400 ) );
		}

		// Discovery: raw SQL finds candidate ids only (ADR 0003 — discovery
		// only, never values). ACTIVE-FIELD-FIRST (review finding 1): a scan
		// resolves against the merchant's configured barcode field before any
		// hard-coded key, so a stale value left on an inactive key (e.g. a
		// left-over `_sku`) can never beat a match on the active field. The
		// hard-coded barcode-bearing keys are consulted ONLY as a fallback when
		// the active field yields no match at all. GROUP BY collapses records
		// matching on several keys within a phase.
		$barcode_field = Settings::instance()->barcode_field();
		$matches       = $this->discover_by_meta_key( $code, $barcode_field );
		if ( array() === $matches ) {
			$matches = $this->discover_by_fallback_keys( $code, $barcode_field );
		}
		$matches = $this->apply_matches_filter( $matches, $code );
		$visibility        = new Pos_Visibility();
		$hidden_products   = $visibility->online_only_product_ids();
		$hidden_variations = $visibility->online_only_variation_ids();
		$matches = array_values(
			array_filter(
				$matches,
				static function ( $candidate ) use ( $hidden_products, $hidden_variations ): bool {
					$id         = (int) ( $candidate['id'] ?? 0 );
					$type       = (string) ( $candidate['type'] ?? 'product' );
					$hidden_ids = 'variation' === $type ? $hidden_variations : $hidden_products;

					return ! \in_array( $id, $hidden_ids, true );
				}
			)
		);

		$match = null;
		if ( array() !== $matches ) {
			$first   = $matches[0];
			$product = wc_get_product( (int) ( $first['id'] ?? 0 ) );
			if ( $product ) {
				// Hydrate only the first match, and only through the filtered
				// REST path — mirrors class-changes-controller.php
				// revision_hash; raw projections are never trusted (ADR 0003).
				$serialization_request = new WP_REST_Request( 'GET', '/' );
				$controller            = new WC_REST_Products_Controller();
				$response              = rest_ensure_response( $controller->prepare_object_for_response( $product, $serialization_request ) );
				$payload               = rest_get_server()->response_to_data( $response, false );
				$payload               = apply_filters( 'woocommerce_pos_sync_serialized_product', $payload, $product, $serialization_request );
				$type                  = (string) ( $first['type'] ?? 'product' );
				$match                 = array(
					'id'        => (int) ( $first['id'] ?? 0 ),
					'type'      => $type,
					'parent_id' => 'variation' === $type ? (int) $product->get_parent_id() : 0,
					'payload'   => $payload,
				);
			}
		}

		return rest_ensure_response(
			array(
				'code'      => $code,
				'found'     => null !== $match,
				'match'     => $match,
				'ambiguous' => array_values( \array_slice( $matches, 1 ) ),
				'meta'      => array(
					'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
					'candidates'  => \count( $matches ),
				),
			)
		);
	}

	/**
	 * Discover candidate {id,type} matches on a SINGLE meta key (the active
	 * barcode field). Empty meta key (unconfigured) → no matches, so the caller
	 * falls through to the hard-coded keys.
	 */
	private function discover_by_meta_key( string $code, string $meta_key ): array {
		if ( '' === trim( $meta_key ) ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type FROM {$wpdb->posts} p"
				. ' INNER JOIN ' . $wpdb->postmeta . ' pm ON pm.post_id = p.ID'
				. ' WHERE pm.meta_key = %s AND pm.meta_value = %s'
				. ' AND p.post_type IN ' . self::PRODUCT_POST_TYPES_SQL
				. " AND p.post_status = 'publish'"
				. ' GROUP BY p.ID ORDER BY p.ID ASC',
				$meta_key,
				$code
			),
			ARRAY_A
		);

		return $this->rows_to_matches( $rows );
	}

	/**
	 * Fallback discovery across the hard-coded barcode-bearing keys, EXCLUDING
	 * the active field (already tried by discover_by_meta_key). Runs only when
	 * the active field produced no match.
	 */
	private function discover_by_fallback_keys( string $code, string $active_field ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type FROM {$wpdb->posts} p"
				. ' INNER JOIN ' . $wpdb->postmeta . ' pm ON pm.post_id = p.ID'
				. ' WHERE pm.meta_key IN ' . self::BARCODE_META_KEYS_SQL
				. ' AND pm.meta_key <> %s'
				. ' AND pm.meta_value = %s'
				. ' AND p.post_type IN ' . self::PRODUCT_POST_TYPES_SQL
				. " AND p.post_status = 'publish'"
				. ' GROUP BY p.ID ORDER BY p.ID ASC',
				$active_field,
				$code
			),
			ARRAY_A
		);

		return $this->rows_to_matches( $rows );
	}

	/**
	 * Map raw {ID,post_type} discovery rows to the {id,type} match shape.
	 *
	 * @param mixed $rows
	 */
	private function rows_to_matches( $rows ): array {
		$rows    = \is_array( $rows ) ? $rows : array();
		$matches = array();
		foreach ( $rows as $row ) {
			$matches[] = array(
				'id'   => (int) $row['ID'],
				'type' => 'product_variation' === (string) $row['post_type'] ? 'variation' : 'product',
			);
		}

		return $matches;
	}

	/**
	 * Seam for woocommerce_pos_sync_resolve_barcode_matches: plugins that own
	 * barcode storage (ADR 0003: value-based lookups must be hookable) can
	 * replace discovery output entirely. Protected so the unit harness —
	 * whose apply_filters stub is identity — can subclass the override path.
	 */
	protected function apply_matches_filter( array $matches, string $code ): array {
		$filtered = apply_filters( 'woocommerce_pos_sync_resolve_barcode_matches', $matches, $code );

		return \is_array( $filtered ) ? array_values( $filtered ) : array();
	}
}
