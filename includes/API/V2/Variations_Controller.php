<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WC_Product_Variation;
use WC_REST_Product_Variations_Controller;
use WCPOS\WooCommercePOS\Services\Barcode_Field;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Variations document endpoint (on-demand variation fetch).
 *
 * Why a flat route: the change-signal yields BARE variation ids (no parent), and WooCommerce's
 * only variation routes are parent-mediated (`products/<parent>/variations`). One flat route
 * lets the client pull a deferred variation set in ONE round trip with no parent->child dance.
 *
 * Why it EXTENDS WooCommerce's variations controller: because that is all the route ever needed.
 * WooCommerce's `get_objects()` already answers a cross-parent query — with no `product_id` in
 * the route there is no parent constraint, and `include`/`search`/`orderby`/pagination are its
 * own collection params. 1.9.x did exactly this: `parent::get_items( $request )`, one line
 * (`API\V1\Product_Variations_Controller::wcpos_get_all_items`).
 *
 * The previous version of this class extended a bare `WP_REST_Controller` and rebuilt the query
 * by hand — ~90 lines of raw postmeta SQL, five hand-declared args, no item schema — on the
 * stated grounds that "wc/v3 has no cross-parent variations?include=". That claim was false, and
 * the cost of acting on it was the payload: a variation hydrated through the PRODUCTS controller
 * carries `images[]` instead of `image`, which blanked every variation thumbnail in the POS on
 * 1.10.0 and wrote the parent's image onto every order line (#1710).
 *
 * What stays ours, and only this: the sync document envelope the engine reads
 * (`documents[].{id,parent_id,payload,_rxdb_digest}`), POS visibility, the barcode carrier
 * search, and the request bounds. Everything else is WooCommerce's.
 */
class Variations_Controller extends WC_REST_Product_Variations_Controller {
	use Endpoint_Permissions;

	private const MAX_SKU_LENGTH    = 4096;
	private const MAX_SKU_TERMS     = 100;
	private const MAX_SEARCH_LENGTH = 256;
	private const MAX_SEARCH_TERMS  = 10;
	private const MAX_PAGE          = 1000;


	public function register_routes(): void {
		/*
		 * ONLY the flat sync route. `parent::register_routes()` is deliberately not called: the
		 * v2 namespace is a read/sync surface, and writes ride Write_Controller, which already
		 * pushes through WooCommerce's nested routes. Registering WC's CRUD routes here would
		 * widen the POS-marker-gated surface for no consumer.
		 *
		 * The args and the schema are WooCommerce's own, so `include`, `search`, `orderby`,
		 * `order`, `offset`, `page`, `per_page`, `status` … all behave exactly as they do on
		 * wc/v3, and the route documents itself in the REST index.
		 */
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/variations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_variations' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Narrow WooCommerce's variation query to what the POS may serve.
	 *
	 * Everything WooCommerce already understands — `include`, `offset`, `order`, pagination,
	 * status — comes from `parent::prepare_objects_query()`. Layered on top: POS visibility, the
	 * barcode-carrier search, and the sort keys the POS grids offer. This is the seam 1.9.x used
	 * for the same job (`API\V1\Product_Variations_Controller::prepare_objects_query`).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array
	 */
	protected function prepare_objects_query( $request ) {
		/*
		 * WooCommerce splits `sku` on commas without trimming, so `sku=A, B` looks for " B".
		 * Normalize before it sees the param rather than reimplementing its matching.
		 */
		$sku = (string) ( $request->get_param( 'sku' ) ?? '' );
		if ( '' !== $sku ) {
			$terms = array_values(
				array_filter(
					array_map( 'trim', explode( ',', $sku ) ),
					static function ( string $term ): bool {
						return '' !== $term;
					}
				)
			);
			$request->set_param( 'sku', implode( ',', $terms ) );
		}

		$args = parent::prepare_objects_query( $request );

		/*
		 * A product is not a variation document.
		 *
		 * WooCommerce widens `post_type` to `array( 'product', 'product_variation' )` whenever
		 * `sku` is set, because the two share one SKU space. On THIS route that would serve a
		 * simple product as a variation — and the client would file it into its variations
		 * collection, the mirror image of the misfiled-variation pollution it already carries a
		 * one-shot repair for. Type purity on a variations route is ours to enforce.
		 */
		$args['post_type'] = $this->post_type;

		/*
		 * `search` means the barcode CARRIERS here, not the post title.
		 *
		 * WooCommerce maps `search` onto `s`, which searches post_title/content — useless for a
		 * variation, whose title is a generated attribute string. The POS searches what a cashier
		 * actually types or scans: the SKU and whichever meta key the store configured as its
		 * barcode field (`Barcode_Field::search_keys()`). Any term matching any carrier wins,
		 * which is the semantics the previous hand-rolled SQL had and the specs pin.
		 *
		 * `sku` is left to WooCommerce: its own exact/comma-list handling is what the
		 * sku-beats-search precedence rule relies on.
		 */
		$search = (string) ( $request->get_param( 'search' ) ?? '' );
		if ( '' !== $sku ) {
			// SKU is an exact lookup and outranks a fuzzy one; leaving WooCommerce's post-title
			// `s` in place would AND the two and return nothing.
			unset( $args['s'] );
		}
		if ( '' !== $search && '' === $sku ) {
			unset( $args['s'] );
			$carriers = array( 'relation' => 'OR' );
			foreach ( (array) preg_split( '/\s+/', trim( $search ), -1, PREG_SPLIT_NO_EMPTY ) as $term ) {
				foreach ( Barcode_Field::search_keys() as $key ) {
					$carriers[] = array(
						'key'     => $key,
						'value'   => $term,
						'compare' => 'LIKE',
					);
				}
			}
			if ( 1 < \count( $carriers ) ) {
				$args['meta_query'] = $this->add_meta_query( $args, $carriers ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}
		}

		// A discovery search only ever offers what is for sale. (The `include` lane hydrates by id
		// and deliberately does not apply this — the client asks for those ids by name.)
		if ( '' !== $search || '' !== $sku ) {
			$args['post_status'] = 'publish';
		}

		// Leg-3 (ADR 0014 WP-M5): POS-hidden (`online_only`) variations are never served. As a
		// query exclusion rather than a post-hoc filter of the result, so paging and totals count
		// the same set the client is allowed to see.
		$hidden = ( new Pos_Visibility() )->hidden_ids( Pos_Visibility::VARIATIONS );
		if ( array() !== $hidden ) {
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $hidden );
		}

		// The POS sorts on fields WooCommerce does not offer as orderby values.
		if ( isset( $request['orderby'] ) ) {
			switch ( $request['orderby'] ) {
				case 'sku':
					$args['meta_key'] = '_sku'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$args['orderby']  = 'meta_value';

					break;
				case 'barcode':
					$args['meta_key'] = Barcode_Field::orderby_key(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$args['orderby']  = 'meta_value';

					break;
				case 'stock_quantity':
					$args['meta_key'] = '_stock'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$args['orderby']  = 'meta_value_num';

					break;
				case 'stock_status':
					$args['meta_key'] = '_stock_status'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$args['orderby']  = 'meta_value';

					break;
			}
		}

		return $args;
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
		_prime_post_caches( $ids, true, true );

		// Hydrate through THE product assembly line (Product_Serializer), the same
		// seam resolve/changes use (ADR 0003 — values come from the REST
		// representation, never raw SQL). wc_get_product() returns a
		// WC_Product_Variation for a variation id; the instanceof guard keeps a
		// product id from being hydrated through this lane.
		// Leg-3 (ADR 0014): attach each variation's stored 64-bit digest as `_rxdb_digest` so the client
		// seeds its existence-reconcile manifest from this pull too (products get theirs via the proxy
		// filter). Bulk-read once for the whole include set. A string — the digest exceeds int range.
		// ::class, never the bare string: from inside this namespace
		// class_exists( 'Digest_Index' ) probes the GLOBAL namespace and is
		// forever false, so variation digests would never emit (review finding 3).
		// Variations read the PRODUCTS id-space — one registry row owns both
		// object types, so this lane cannot drift from the proxy lane's answer.
		$digests = class_exists( Digest_Index::class )
			? ( new Digest_Index() )->read_digests( 'products', $ids )
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

		$response = rest_ensure_response(
			array(
				'documents' => $documents,
				'meta'      => $meta,
			)
		);

		/*
		 * The pagination WooCommerce would have sent.
		 *
		 * No v2 route emitted `X-WP-Total`/`X-WP-TotalPages` — including this one, the only one
		 * that paginates. The client asks for them on every v2 GET (the response envelope mirrors
		 * them into the body), so it has been receiving an empty mirror and falling back to
		 * short-page detection, which cannot tell "last page" from "the server truncated".
		 */
		if ( null !== $search_meta && $response instanceof WP_REST_Response ) {
			$response->header( 'X-WP-Total', (string) $search_meta['total'] );
			$response->header(
				'X-WP-TotalPages',
				(string) ( $search_meta['per_page'] > 0 ? (int) ceil( $search_meta['total'] / $search_meta['per_page'] ) : 0 )
			);
		}

		return $response;
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
	 *
	 * The query is WooCommerce's — `prepare_objects_query()` + `get_objects()`, the same pair its
	 * own `get_items()` uses. This method previously hand-built the SQL: a `wp_posts`/`wp_postmeta`
	 * INNER JOIN with `LIKE` predicates assembled per (field, term) pair, a second COUNT(DISTINCT)
	 * query for the total, and the hidden-id exclusion spliced into the same placeholder list. All
	 * of it duplicated `WP_Query` — which is where such copies go wrong, quietly and later.
	 *
	 * @return array{0: array<int, int>, 1: array{total: int, page: int, per_page: int}}
	 */
	private function search_variation_ids( WP_REST_Request $request ): array {
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?? 10 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$request->set_param( 'per_page', $per_page );
		$request->set_param( 'page', $page );

		$results = $this->get_objects( $this->prepare_objects_query( $request ) );

		$ids = array();
		foreach ( $results['objects'] as $object ) {
			if ( $object instanceof WC_Product_Variation ) {
				$ids[] = $object->get_id();
			}
		}

		return array(
			$ids,
			array(
				'total'    => (int) ( $results['total'] ?? \count( $ids ) ),
				'page'     => $page,
				'per_page' => $per_page,
			),
		);
	}
}
