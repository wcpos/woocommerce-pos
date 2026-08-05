<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WC_Product_Variation;
use WCPOS\WooCommercePOS\API\V1\Traits\Product_Helpers;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
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
	use Product_Helpers;

	private const MAX_SKU_LENGTH    = 4096;
	private const MAX_SKU_TERMS     = 100;
	private const MAX_SEARCH_LENGTH = 256;
	private const MAX_SEARCH_TERMS  = 10;
	private const MAX_PAGE          = 1000;


	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'variations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_variations' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'include'  => array( 'sanitize_callback' => 'wp_parse_id_list' ),
					'search'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'sku'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'per_page' => array(
						'default' => 10,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'default' => 1,
						'sanitize_callback' => 'absint',
					),
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
		$started     = microtime( true );
		$search_meta = null;
		if ( $request->has_param( 'sku' ) || $request->has_param( 'search' ) ) {
			$validation = $this->validate_search_request( $request );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			list( $ids, $search_meta ) = $this->search_variation_ids( $request );
		} else {
			$ids = array_values( array_unique( array_map( 'intval', (array) $request->get_param( 'include' ) ) ) );
			if ( array() === $ids ) {
				return new WP_Error( 'woocommerce_pos_sync_missing_ids', 'variations requires a non-empty include list', array( 'status' => 400 ) );
			}
			// Leg-3 (ADR 0014 WP-M5): drop POS-hidden (`online_only`) variations from the served set. A hidden
			// id simply isn't hydrated → the client's targeted pull returns nothing for it → Leg-3 prunes it.
			// (Products get the equivalent exclusion via the catalog-proxy `post__not_in` filter.)
			$ids = ( new Pos_Visibility() )->filter_visible_children( $ids );
		}

		// Hydrate through THE product assembly line (Product_Serializer), the same
		// seam resolve/changes use (ADR 0003 — values come from the REST
		// representation, never raw SQL). wc_get_product() returns a
		// WC_Product_Variation for a variation id; the instanceof guard keeps a
		// product id from being hydrated through this lane.
		// Leg-3 (ADR 0014): attach each variation's stored 64-bit digest as `_rxdb_digest` so the client
		// seeds its existence-reconcile manifest from this pull too (products get theirs via the proxy
		// filter). Bulk-read once for the whole include set. A string — the digest exceeds int range.
		// ::class, never the bare string: from inside this namespace
		// class_exists( 'Integrity_Digest' ) probes the GLOBAL namespace and is
		// forever false, so variation digests would never emit (review finding 3).
		$digests = class_exists( Integrity_Digest::class )
			? ( new Integrity_Digest() )->read_digests( $ids )
			: array();

		$serialization_request = new WP_REST_Request( 'GET', '/' );
		$serializer            = new Product_Serializer();
		$documents             = array();
		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}
			$payload  = $serializer->serialize( $variation, $serialization_request );
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

		$meta = array(
			'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'requested'   => \count( $ids ),
			'returned'    => \count( $documents ),
		);
		if ( null !== $search_meta ) {
			$meta = array_merge( $meta, $search_meta );
		}

		return rest_ensure_response(
			array(
				'documents' => $documents,
				'meta'      => $meta,
			)
		);
	}

	/**
	 * Reject search requests that could build excessively large SQL queries or offsets.
	 *
	 * @return true|WP_Error
	 */
	private function validate_search_request( WP_REST_Request $request ) {
		if ( $request->has_param( 'sku' ) ) {
			$sku = (string) $request->get_param( 'sku' );
			if ( self::MAX_SKU_LENGTH < \strlen( $sku ) ) {
				return new WP_Error( 'woocommerce_pos_variations_search_limit_exceeded', 'sku must not exceed 4096 bytes', array( 'status' => 400 ) );
			}
			$skus = array_filter(
				array_map( 'trim', explode( ',', $sku ) ),
				static function ( string $term ): bool {
					return '' !== $term;
				}
			);
			if ( self::MAX_SKU_TERMS < \count( $skus ) ) {
				return new WP_Error( 'woocommerce_pos_variations_search_limit_exceeded', 'sku must not contain more than 100 comma-separated terms', array( 'status' => 400 ) );
			}
		} else {
			$search = (string) $request->get_param( 'search' );
			if ( self::MAX_SEARCH_LENGTH < \strlen( $search ) ) {
				return new WP_Error( 'woocommerce_pos_variations_search_limit_exceeded', 'search must not exceed 256 bytes', array( 'status' => 400 ) );
			}
			$terms = (array) preg_split( '/\s+/', trim( $search ), -1, PREG_SPLIT_NO_EMPTY );
			if ( self::MAX_SEARCH_TERMS < \count( $terms ) ) {
				return new WP_Error( 'woocommerce_pos_variations_search_limit_exceeded', 'search must not contain more than 10 whitespace-separated terms', array( 'status' => 400 ) );
			}
		}

		if ( self::MAX_PAGE < (int) $request->get_param( 'page' ) ) {
			return new WP_Error( 'woocommerce_pos_variations_search_limit_exceeded', 'page must not exceed 1000', array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Discover a page of published, POS-visible variation ids by SKU/barcode.
	 */
	private function search_variation_ids( WP_REST_Request $request ): array {
		global $wpdb;

		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?? 10 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$args     = array( 'product_variation', 'publish' );

		if ( $request->has_param( 'sku' ) ) {
			$skus = array_values(
				array_filter(
					array_map( 'trim', explode( ',', (string) $request->get_param( 'sku' ) ) ),
					static function ( string $sku ): bool {
						return '' !== $sku;
					}
				)
			);
			if ( array() === $skus ) {
				$match_sql = '1 = 0';
			} else {
				$match_sql = 'pm.meta_key = %s AND pm.meta_value IN (' . implode( ',', array_fill( 0, \count( $skus ), '%s' ) ) . ')';
				$args[]    = '_sku';
				$args       = array_merge( $args, $skus );
			}
		} else {
			$terms         = preg_split( '/\s+/', trim( (string) $request->get_param( 'search' ) ), -1, PREG_SPLIT_NO_EMPTY );
			$barcode_field = $this->wcpos_get_barcode_field();
			$fields        = '_sku' === $barcode_field ? array( '_sku' ) : array( '_sku', $barcode_field );
			$matches       = array();
			foreach ( $terms as $term ) {
				foreach ( $fields as $field ) {
					$matches[] = '(pm.meta_key = %s AND pm.meta_value LIKE %s)';
					$args[]    = $field;
					$args[]    = '%' . $wpdb->esc_like( $term ) . '%';
				}
			}
			$match_sql = array() === $matches ? '1 = 0' : '(' . implode( ' OR ', $matches ) . ')';
		}

		$where_sql = " FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID"
			. ' WHERE p.post_type = %s AND p.post_status = %s AND (' . $match_sql . ')';
		// This fragment is prepared once at the end with the rest of $args, so the ids ride the same
		// placeholder list rather than going through Pos_Visibility::apply_to_sql_where().
		$hidden = ( new Pos_Visibility() )->hidden_ids( Pos_Visibility::VARIATIONS );
		if ( array() !== $hidden ) {
			$where_sql .= ' AND p.ID NOT IN (' . implode( ',', array_fill( 0, \count( $hidden ), '%d' ) ) . ')';
			$args       = array_merge( $args, $hidden );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL fragments are fixed; all values use placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT p.ID)' . $where_sql, $args ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT p.ID' . $where_sql . ' ORDER BY p.ID DESC LIMIT %d OFFSET %d',
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return array(
			array_map( 'intval', $ids ),
			array(
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
		);
	}
}
