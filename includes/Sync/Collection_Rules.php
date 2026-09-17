<?php
/**
 * WCPOS collection query rules.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\Services\Barcode_Field;
use WP_REST_Request;

/**
 * THE declaration table for POS collection query behaviour — one Collection Rule per
 * behaviour, declared once and applied identically on every Read Lane.
 *
 * # The problem this exists to remove
 *
 * A POS query behaviour ("sort orders by cashier-visible payment method", "let
 * `wcpos_include` narrow the result set") used to be written twice: once in the
 * `wcpos/v1` controller that owns the direct lane, and once as a hand-copied mirror
 * inside `V2\Catalog_Proxy_Controller` for the proxy lane. Two encodings of one rule
 * drift — and they had: `payment_method` sorted three different columns across the two
 * lanes and two storages. Every such divergence is a parity bug the client sees as
 * "sorting is wrong on this endpoint".
 *
 * Here, a behaviour is a ROW. Both lanes read the same row, so a lane cannot have a
 * behaviour the other lacks, and the next parity fix is one row plus one pure test.
 *
 * # Surface
 *
 * - `for_request()` — pure, memoized, never null and never throws. An unknown
 *   collection yields an EMPTY plan whose `filter()` is the identity and whose
 *   `around()` merely runs its callable. That is the adoption mechanism: a collection
 *   can be routed through the module before it has any rows, and nothing changes.
 * - `Collection_Rules_Plan::filter()` — the direct lane. Type-preserving clause
 *   bodies; it NEVER touches global filter state. Legacy callbacks can still delegate
 *   to it; collection reads use `around()` for search and visibility.
 * - `Collection_Rules_Plan::around()` — the scoped read lanes, and the ONLY install path.
 *   Callbacks are installed, the forward runs, and every binding is unwound in reverse
 *   in a `finally`.
 * - `orderby_enum()` / `collection_params()` — schema PROJECTIONS of the same rows, so
 *   the REST schema and the proxy's claim list cannot disagree with the clause logic.
 *
 * # Param-map narrowing
 *
 * Each lane passes a canonical-name => request-key map. A canonical name absent from
 * the map is INVISIBLE to the plan. This is how `wcpos/v1` keeps not supporting
 * `created_via` (its historic `@TODO`) while the row exists for the proxy: the omission
 * is a product decision recorded in one place, not an accident of which file was edited.
 *
 * A map entry is either a request key, or an array of:
 *   - `key`   (string, required) the request key to read.
 *   - `when`  (string, optional) `'search'` — claim only when the request also carries a
 *             non-empty `search`. wc/v3 resolves `search` to a matched-id set that
 *             CLOBBERS `include`/`exclude`, so the rule takes ownership of the id sets
 *             exactly then; a plain targeted pull keeps wc/v3's native semantics.
 *             A WCPOS-private key such as `wcpos_include` needs no such condition —
 *             wc/v3 never sees it.
 *   - `parse` (string, optional) `'id_list'` runs `wp_parse_id_list` at claim time.
 *             The default reproduces `wcpos/v1`'s historic `array_map( 'intval', (array) $v )`
 *             cast verbatim (a comma-joined string collapses to its first id) because
 *             v1 wire behaviour is frozen. Unifying the two is a follow-up.
 *
 * # Storage
 *
 * Rows carry a sub-array per storage dialect (`hpos` — the `wc_orders` tables, `posts` —
 * the legacy `wp_posts`/`wp_postmeta` pair). The storage is resolved ONCE, at plan
 * construction, so no clause body re-detects it halfway through a query.
 *
 * @see Collection_Rules_Plan for the per-request object.
 */
final class Collection_Rules {
	/**
	 * High Performance Order Storage — the `wc_orders` custom tables.
	 *
	 * @var string
	 */
	public const STORAGE_HPOS = 'hpos';

	/** Shared search bound; over-limit policies remain collection-specific. */
	public const SEARCH_TERM_CAP = 10;

	/**
	 * Split literal terms. Orders now also drop Unicode control characters between terms.
	 *
	 * Existing defaults: product phrase null uses WP_Query terms; orders use the supplied
	 * string; variation args/discovery default to '', and validation casts null to ''.
	 * Product phrases collapse over-cap terms; orders slice; v2 variations reject.
	 * Every product/variation lane uses this splitter; v1 collapses over-cap, v2 flat variations reject.
	 * Malformed UTF-8 yields an empty array, including offset-capture callers.
	 *
	 * @param string $search Search text.
	 * @param int    $flags  Split flags.
	 * @return array
	 */
	public static function search_terms( $search, $flags = PREG_SPLIT_NO_EMPTY ) {
		$terms = preg_split( '/[\s\p{Z}\p{C}]+/u', $search, -1, $flags );
		return false === $terms ? array() : $terms;
	}

	/**
	 * Legacy storage — `wp_posts` plus `wp_postmeta`.
	 *
	 * @var string
	 */
	public const STORAGE_POSTS = 'posts';

	/**
	 * Memoized plans, keyed by collection, request identity/content, storage and param map.
	 *
	 * Each entry is `array( WP_REST_Request, Collection_Rules_Plan )`; the request is
	 * kept so a recycled `spl_object_id` can never serve another request's plan.
	 *
	 * @var array<string, array{0: WP_REST_Request, 1: Collection_Rules_Plan}>
	 */
	private static $plans = array();

	/**
	 * Ceiling on the memo table, so a long-running process cannot grow it without bound.
	 *
	 * @var int
	 */
	private const PLAN_CACHE_LIMIT = 32;

	/**
	 * Build (or return the memoized) plan for one collection read.
	 *
	 * Pure: it reads the request and the declaration rows and nothing else. It never
	 * returns null and never throws — an unknown collection is an empty plan.
	 *
	 * @param string          $collection Collection slug, e.g. `orders`.
	 * @param WP_REST_Request $request    The request whose params the plan claims from.
	 * @param array           $param_map  Canonical name => request key (see class docblock).
	 * @param string|null     $storage    Storage dialect, or null to detect it.
	 *
	 * @return Collection_Rules_Plan
	 */
	public static function for_request( string $collection, WP_REST_Request $request, array $param_map = array(), ?string $storage = null ) {
		$storage = $storage ?? self::detect_storage( $collection );
		$key     = $collection . '|' . spl_object_id( $request ) . '|' . $storage . '|' . md5( (string) wp_json_encode( array( $param_map, $request->get_route(), $request->get_params() ) ) );

		if ( isset( self::$plans[ $key ] ) && self::$plans[ $key ][0] === $request ) {
			return self::$plans[ $key ][1];
		}

		if ( \count( self::$plans ) >= self::PLAN_CACHE_LIMIT ) {
			self::$plans = array();
		}

		$plan                = new Collection_Rules_Plan( $collection, self::rules( $collection ), $storage, $request, $param_map );
		self::$plans[ $key ] = array( $request, $plan );

		return $plan;
	}

	/**
	 * The `orderby` values this collection adds to the wc/v3 enum.
	 *
	 * A PROJECTION of the sort rows: the v1 REST schema, the proxy's claim list and the
	 * clause bodies all read this, so a sort cannot be advertised without being wired
	 * (or wired without being advertised).
	 *
	 * @param string $collection Collection slug.
	 *
	 * @return string[]
	 */
	public static function orderby_enum( string $collection ): array {
		$rules = self::rules( $collection );

		return array_keys( $rules['sorts'] ?? array() );
	}

	/**
	 * The extra REST collection params this collection's filter rows require.
	 *
	 * A PROJECTION of the filter rows, in declaration order, shaped for
	 * `WP_REST_Controller::get_collection_params()`.
	 *
	 * @param string $collection Collection slug.
	 *
	 * @return array<string, array>
	 */
	public static function collection_params( string $collection ): array {
		$params = array();

		if ( 'orders' === $collection ) {
			$params['pos_cashier'] = array(
				'description' => /* translators: REST API schema field label or error message. */ __( 'Filter orders by POS cashier.', 'woocommerce-pos' ),
				'type'        => 'integer',
				'required'    => false,
			);
			// @NOTE - this is different to 'store_id' which is the store the request was made from.
			$params['pos_store'] = array(
				'description' => /* translators: REST API schema field label or error message. */ __( 'Filter orders by POS store.', 'woocommerce-pos' ),
				'type'        => 'integer',
				'required'    => false,
			);
		}

		return $params;
	}

	/**
	 * The declaration rows for one collection.
	 *
	 * Closed table, private to the module in spirit — public only so the plan can be
	 * constructed from it and so pure tests can assert the rows without a bootstrap.
	 *
	 * A sort row MAY be bodiless (`array()`) when the collection's clauses live outside
	 * this module — see the `customers` rows. Such a row still projects into
	 * `orderby_enum()`, which is the whole point: one list, both lanes.
	 *
	 * Sort row shape (per storage):
	 *   - `hpos`  => `array( 'column' => <wc_orders column> )`
	 *   - `posts` => `array( 'posts_orderby' => <wp_posts column> )` for a column the
	 *                WP_Query `orderby` vocabulary cannot express (rewritten through
	 *                `posts_orderby`), OR
	 *                `array( 'meta_key' => ..., 'orderby' => meta_value|meta_value_num )`.
	 *   - `posts` => `array( 'meta_sort' => array( 'key' => ..., 'numeric' => bool ) )`
	 *                a postmeta sort that must NOT filter: applied as a LEFT JOIN through
	 *                `posts_clauses`, with rows that have no value for the key ordered
	 *                LAST in both directions. Use this for any user-facing column sort —
	 *                `meta_key`/`orderby` INNER JOINs and silently drops rows.
	 *
	 * Filter row shape:
	 *   - `meta`      => `array( 'key' => <meta key>, 'storage' => <optional storage lock> )`
	 *                    a `meta_query` row on the WC query args (works on both storages).
	 *   - `hpos_data` => `array( 'table' => ..., 'column' => ... )` an id subquery against
	 *                    one of HPOS's side tables, for data that is a COLUMN under HPOS
	 *                    and postmeta under legacy.
	 *   - `id_set`    => `array( 'operator' => 'IN'|'NOT IN' )` a raw id set the rule owns
	 *                    outright (see the `when => search` note in the class docblock).
	 *   - `sanitize`  => optional `'key'`, applied to each claimed value.
	 *
	 * @internal
	 *
	 * @param string $collection Collection slug.
	 *
	 * @return array{sorts?: array<string, array>, filters?: array<string, array>, search?: array<string, mixed>, visibility?: array<string, mixed>}
	 */
	public static function rules( string $collection ): array {
		$rules = array(
			'orders' => array(
				'search' => array(
					'param'      => 'search',
					'term_cap'   => self::SEARCH_TERM_CAP,
					'rank_exact' => false,
					'carriers'   => array( 'id', 'billing_email', 'first_name', 'last_name', 'company', 'email', 'phone' ),
					'hpos'       => array(
						'orders'    => array( 'id', 'billing_email' ),
						'addresses' => array( 'first_name', 'last_name', 'company', 'email', 'phone' ),
					),
					'posts'      => array(
						'id'   => 'ID',
						'meta' => array( '_billing_first_name', '_billing_last_name', '_billing_company', '_billing_email', '_billing_phone' ),
					),
				),
				'sorts' => array(
					'status' => array(
						'hpos'  => array( 'column' => 'status' ),
						'posts' => array( 'posts_orderby' => 'post_status' ),
					),
					'customer_id' => array(
						'hpos'  => array( 'column' => 'customer_id' ),
						'posts' => array(
							'meta_key' => '_customer_user', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Declaration row, not a live query arg.
							'orderby'  => 'meta_value_num',
						),
					),

					/*
					 * PARITY PIN: the two storages sort DIFFERENT things and always have.
					 * HPOS sorts the gateway id (`wc_orders.payment_method`, e.g. `pos_cash`);
					 * legacy sorts the merchant-visible title meta (`_payment_method_title`,
					 * e.g. `Cash`). `wcpos/v1` is the frozen authority, so both are reproduced
					 * verbatim and the proxy lane now adopts them. Collapsing the two onto
					 * `payment_method_title` is a deliberate behaviour change, deferred.
					 */
					'payment_method' => array(
						'hpos'  => array( 'column' => 'payment_method' ),
						'posts' => array(
							'meta_key' => '_payment_method_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Declaration row, not a live query arg.
							'orderby'  => 'meta_value',
						),
					),
					'total' => array(
						'hpos'  => array( 'column' => 'total_amount' ),
						'posts' => array(
							'meta_key' => '_order_total', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Declaration row, not a live query arg.
							'orderby'  => 'meta_value_num',
						),
					),
				),
				'filters' => array(
					'pos_cashier' => array(
						'meta' => array( 'key' => '_pos_user' ),
					),
					'pos_store' => array(
						'meta' => array( 'key' => '_pos_store' ),
					),

					/*
					 * `created_via` is a column of the HPOS operational-data table and a
					 * postmeta value under legacy storage. The row exists for both, but
					 * `wcpos/v1`'s param map omits the canonical name, so v1 continues not
					 * to support it — a recorded product decision, not a silent gift.
					 */
					'created_via' => array(
						'meta'      => array(
							'key'     => '_created_via',
							'storage' => self::STORAGE_POSTS,
						),
						'hpos_data' => array(
							'table'  => 'operational_data',
							'column' => 'created_via',
						),
						'sanitize'  => 'key',
					),
					'include' => array(
						'id_set' => array( 'operator' => 'IN' ),
					),
					'exclude' => array(
						'id_set' => array( 'operator' => 'NOT IN' ),
					),
				),
			),

			/*
			 * The POS grid's SKU / barcode / stock columns, for the product grid and the
			 * variation grid alike — the SAME four rows, from one builder, because the two
			 * surfaces drifted apart once already and a cashier sorting a column expects
			 * the same thing of both.
			 *
			 * A `meta_sort` row sorts on a postmeta value WITHOUT letting the sort decide
			 * which records exist. The obvious encoding — WP_Query's `meta_key` +
			 * `orderby => meta_value` — INNER JOINs `postmeta`, so a record with no row for
			 * that key VANISHES from the result. On a default store the barcode field is
			 * `_global_unique_id`, which most catalogues never populate, so sorting by
			 * barcode returned an EMPTY page; `orderby=sku` silently dropped everything
			 * without a SKU. A sort must never hide a record from a cashier, so these rows
			 * are applied as a LEFT JOIN with the meta-less rows ordered LAST in both
			 * directions (`Collection_Rules_Plan::apply_meta_sort_clauses()`).
			 *
			 * Neither collection is ever HPOS — both are posts on every store — so there is
			 * no `hpos` half to these rows.
			 */
			'products' => array(
				'search' => array(
					'param'      => 'search',
					// Both product lanes use the literal splitter and collapse over-cap terms to the phrase.
					'term_cap'   => self::SEARCH_TERM_CAP,
					'rank_exact' => true,
					'carriers'   => array_merge( array( 'post_title' ), Barcode_Field::search_keys() ),
					'posts'      => array( 'meta' => Barcode_Field::search_keys() ),
					'hpos'       => null,
				),
				'visibility' => array(
					'type'           => array(
						'direct' => Pos_Visibility::PRODUCTS,
						'proxy'  => Pos_Visibility::CATALOG,
					),
					'where_backstop' => true,
				),
				'sorts' => self::catalog_meta_sorts(),
			),
			'variations' => array(
				'search' => array(
					'param'           => 'search',
					'term_cap'        => self::SEARCH_TERM_CAP,
					'rank_exact'      => false,
					'carriers'        => Barcode_Field::search_keys(),
					'posts'           => array( 'meta' => Barcode_Field::search_keys() ),
					'hpos'            => null,
					'query'           => 'meta_query',
					'exact_sku_param' => 'sku',
					// Both lanes split literal terms; v1 keeps over-cap collapse/EXISTS, v2 rejects/meta_query.
					'lanes'           => array(
						'direct' => array(
							'query'           => 'wp_terms',
							'exact_sku_param' => null,
						),
					),
				),
				'visibility' => array(
					'type'           => Pos_Visibility::VARIATIONS,
					'where_backstop' => true,
				),
				'sorts' => self::catalog_meta_sorts(),
			),

			/*
			 * SORT NAMES ONLY — deliberately no clause bodies.
			 *
			 * Customers are a `WP_User_Query` over `wp_users`/`wp_usermeta`, a storage
			 * this table does not speak: it knows `hpos` and `posts`, and both are ORDER
			 * storages. The clause bodies therefore stay in each lane's own
			 * `woocommerce_rest_customer_query` callback, where they are byte-identical.
			 *
			 * What DID drift is the LIST. The v1 schema enum and the proxy's claim list
			 * were hand-kept in two files, so a sort could be advertised on one lane and
			 * silently forwarded to wc/v3 (which cannot express it) on the other. Both
			 * lanes now read `orderby_enum( 'customers' )`, which makes that impossible.
			 *
			 * Giving these rows real bodies needs a third storage dialect and two clause
			 * kinds this table has never expressed; that is a later increment.
			 */
			'customers' => array(
				'sorts' => array(
					'first_name' => array(),
					'last_name'  => array(),
					'email'      => array(),
					'role'       => array(),
					'username'   => array(),
				),
			),
		);

		return $rules[ $collection ] ?? array();
	}

	/**
	 * The four POS column sorts, shared by `products` and `variations`.
	 *
	 * One builder rather than two copied blocks: these two collections carry the same
	 * cashier-facing columns, and the previous copy-per-controller encoding is exactly how
	 * the variation lane kept a defect the product lane had already fixed.
	 *
	 * @return array<string, array>
	 */
	private static function catalog_meta_sorts(): array {
		return array(
			'sku' => array(
				'posts' => array(
					'meta_sort' => array( 'key' => '_sku' ),
				),
			),

			/*
			 * The barcode meta key is a store setting, so the row reads the same accessor
			 * the controllers do rather than hard-coding a key that would drift.
			 */
			'barcode' => array(
				'posts' => array(
					'meta_sort' => array( 'key' => Barcode_Field::orderby_key() ),
				),
			),

			/*
			 * `_stock` is written as NULL for everything that does not manage stock, so this
			 * row needs the same meta-less-last ordering as the rest — it is not a special
			 * case, it was merely the first one noticed.
			 */
			'stock_quantity' => array(
				'posts' => array(
					'meta_sort' => array(
						'key'     => '_stock',
						'numeric' => true,
					),
				),
			),
			'stock_status' => array(
				'posts' => array(
					'meta_sort' => array( 'key' => '_stock_status' ),
				),
			),
		);
	}

	/**
	 * Resolve the storage dialect for a collection when the caller did not name one.
	 *
	 * @param string $collection Collection slug.
	 *
	 * @return string
	 */
	public static function detect_storage( string $collection ): string {
		if ( 'orders' !== $collection ) {
			return self::STORAGE_POSTS;
		}

		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled()
			? self::STORAGE_HPOS
			: self::STORAGE_POSTS;
	}
}
