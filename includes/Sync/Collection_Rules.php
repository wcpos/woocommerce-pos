<?php
/**
 * WCPOS collection query rules.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Automattic\WooCommerce\Utilities\OrderUtil;
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
 *   bodies; it NEVER touches global filter state, so a v1 controller keeps owning its
 *   own `add_filter` topology (Pro subclasses those callbacks).
 * - `Collection_Rules_Plan::around()` — the proxy lane, and the ONLY install path.
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

	/**
	 * Legacy storage — `wp_posts` plus `wp_postmeta`.
	 *
	 * @var string
	 */
	public const STORAGE_POSTS = 'posts';

	/**
	 * Memoized plans, keyed by collection, request identity, storage and param map.
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
		$key     = $collection . '|' . spl_object_id( $request ) . '|' . $storage . '|' . md5( (string) wp_json_encode( $param_map ) );

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
	 * Sort row shape (per storage):
	 *   - `hpos`  => `array( 'column' => <wc_orders column> )`
	 *   - `posts` => `array( 'posts_orderby' => <wp_posts column> )` for a column the
	 *                WP_Query `orderby` vocabulary cannot express (rewritten through
	 *                `posts_orderby`), OR
	 *                `array( 'meta_key' => ..., 'orderby' => meta_value|meta_value_num )`.
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
	 * @return array{sorts?: array<string, array>, filters?: array<string, array>}
	 */
	public static function rules( string $collection ): array {
		$rules = array(
			'orders' => array(
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
		);

		return $rules[ $collection ] ?? array();
	}

	/**
	 * Resolve the storage dialect for a collection when the caller did not name one.
	 *
	 * @param string $collection Collection slug.
	 *
	 * @return string
	 */
	private static function detect_storage( string $collection ): string {
		if ( 'orders' !== $collection ) {
			return self::STORAGE_POSTS;
		}

		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled()
			? self::STORAGE_HPOS
			: self::STORAGE_POSTS;
	}
}
