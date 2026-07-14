<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WC_Product_Variation;
use WC_REST_Products_Controller;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Variations document endpoint (on-demand variation fetch).
 *
 * Why: the change-signal yields BARE variation ids (no parent), and wc/v3 has no
 * cross-parent `variations?include=` — its only variation route is the
 * parent-mediated `products/<parent>/variations`. This lab endpoint resolves the
 * parent server-side (off the loaded WC_Product_Variation, zero extra SQL) and
 * hydrates through the SAME filtered products-controller path used by
 * resolve/changes, so the client pulls a deferred variation set in ONE round trip
 * with no parent->child dance. Extends wc/v3 in our `{API_NAMESPACE}{ROUTE_PREFIX}` namespace.
 */
class Variations_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;


	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'variations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_variations' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'include' => array( 'sanitize_callback' => 'wp_parse_id_list' ),
				),
			)
		);
	}

	/**
	 * GET /variations?include=12,34,56 — hydrate the given variation ids.
	 *
	 * Mirrors the wc/v3 `products?include=` shape; the parent is resolved
	 * server-side off the loaded variation object (get_parent_id), so the client
	 * never needs to know parents. Unknown / non-variation ids are skipped
	 * (deletes are handled by the change-signal tombstone path, not here).
	 */
	public function get_variations( WP_REST_Request $request ) {
		$started = microtime( true );
		$ids     = array_values( array_unique( array_map( 'intval', (array) $request->get_param( 'include' ) ) ) );
		if ( array() === $ids ) {
			return new WP_Error( 'woocommerce_pos_sync_missing_ids', 'variations requires a non-empty include list', array( 'status' => 400 ) );
		}
		// Leg-3 (ADR 0014 WP-M5): drop POS-hidden (`online_only`) variations from the served set. A hidden
		// id simply isn't hydrated → the client's targeted pull returns nothing for it → Leg-3 prunes it.
		// (Products get the equivalent exclusion via the catalog-proxy `post__not_in` filter.)
		$hidden = ( new Pos_Visibility() )->online_only_variation_ids();
		if ( array() !== $hidden ) {
			$ids = array_values( array_diff( $ids, $hidden ) );
		}

		// Hydrate through the same filtered products-controller seam as
		// resolve/changes (ADR 0003 — values come from the REST representation,
		// never raw SQL). wc_get_product() returns a WC_Product_Variation for a
		// variation id; the instanceof guard keeps a product id from being
		// hydrated through this lane.
		// Leg-3 (ADR 0014): attach each variation's stored 64-bit digest as `_rxdb_digest` so the client
		// seeds its existence-reconcile manifest from this pull too (products get theirs via the proxy
		// filter). Bulk-read once for the whole include set. A string — the digest exceeds int range.
		// Integrity_Digest is namespaced (WCPOS\WooCommercePOS\Sync); the bare
		// string 'Integrity_Digest' would resolve to a GLOBAL class and be
		// forever false from inside this namespace, so variation digests never
		// emitted (review finding 3). Use the ::class constant so it resolves to
		// the fully-qualified namespaced name.
		$digests = class_exists( Integrity_Digest::class )
			? ( new Integrity_Digest() )->read_digests( $ids )
			: array();

		$serialization_request = new WP_REST_Request( 'GET', '/' );
		$controller            = new WC_REST_Products_Controller();
		$documents             = array();
		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}
			$response = rest_ensure_response( $controller->prepare_object_for_response( $variation, $serialization_request ) );
			$payload  = rest_get_server()->response_to_data( $response, false );
			$payload  = apply_filters( 'woocommerce_pos_sync_serialized_product', $payload, $variation, $serialization_request );
			$document = array(
				'id'        => $id,
				'parent_id' => (int) $variation->get_parent_id(),
				'payload'   => $payload,
			);
			if ( isset( $digests[ $id ] ) ) {
				$document['_rxdb_digest'] = $digests[ $id ];
			}
			$documents[] = $document;
		}

		return rest_ensure_response(
			array(
				'documents' => $documents,
				'meta'      => array(
					'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
					'requested'   => \count( $ids ),
					'returned'    => \count( $documents ),
				),
			)
		);
	}
}
