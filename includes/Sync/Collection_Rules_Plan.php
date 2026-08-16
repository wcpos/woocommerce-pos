<?php
/**
 * WCPOS collection query rules — per-request plan.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use Throwable;
use WP_REST_Request;

use const WCPOS\WooCommercePOS\VERSION;

/**
 * One collection read's worth of Collection Rules, resolved against one request.
 *
 * Immutable after construction: the params it CLAIMS, the storage dialect it targets and
 * the sort it owns are all decided once, so no clause body re-reads the request or
 * re-detects storage halfway through a query.
 *
 * # Claims discipline
 *
 * Every param is claimed OR forwarded, never both. A claimed param is stripped from the
 * request the proxy forwards to wc/v3 (`forwarded_params()`), so wc/v3's enum validator
 * never sees a WCPOS-only `orderby` and its own `search` handling never clobbers an id
 * set this plan has taken ownership of.
 *
 * # Two application modes
 *
 * `filter()` is the direct lane: the v1 controller keeps its own `add_filter` topology
 * (Pro subclasses those callbacks) and each callback body hands its value here. This
 * method never touches global filter state.
 *
 * `around()` is the proxy lane and the ONLY path that installs anything. Bindings are
 * captured as closures — never re-derived tuples — installed, and unwound in reverse
 * inside a `finally`, so a throwing forward leaves `$wp_filter` exactly as it found it.
 *
 * # The WooCommerce-owned sorts
 *
 * The HPOS sort writes `ORDER BY` only when WooCommerce left `$clauses['orderby']` empty
 * — `wcpos/v1`'s guard, kept deliberately. The design called for retiring it on the
 * theory that a rule which claims a sort owns the ordering outright, but that theory is
 * false today: `OrdersTableQuery::sanitize_order_orderby()` maps `total` itself (to
 * `wc_orders.total_amount`, with a sanitized direction), so writing unconditionally would
 * overwrite a correct WooCommerce clause with our own and change v1's SQL. `status`,
 * `customer_id` and `payment_method` are absent from that table, so they do reach us
 * empty. The guard additionally checks that the clause WooCommerce wrote is for the sort
 * we claimed — on the proxy lane the claimed name is stripped before the forward, so a
 * non-empty clause there belongs to wc/v3's default sort, not ours.
 * `Test_Collection_Rules_Guard_HPOS` pins which sort falls on which side, so a future
 * WooCommerce mapping change fails loudly instead of silently flipping ownership.
 */
final class Collection_Rules_Plan {
	/**
	 * WC query-args hook — `meta_query` rows contributed by filter rules.
	 *
	 * @var string
	 */
	public const HOOK_QUERY_ARGS = 'woocommerce_rest_shop_order_object_query';

	/**
	 * The v1 controller's own query-args preparation step — legacy sort args.
	 *
	 * Not a WordPress hook: it is the second half of `prepare_objects_query()`, which
	 * mutates args rather than filtering them. It is dispatched through the same keyed
	 * surface so every clause body lives behind one seam.
	 *
	 * @var string
	 */
	public const HOOK_PREPARE_ARGS = 'prepare_objects_query';

	/**
	 * Legacy storage — raw id sets appended to the `WHERE` clause.
	 *
	 * @var string
	 */
	public const HOOK_POSTS_WHERE = 'posts_where';

	/**
	 * Legacy storage — a sort that the WP_Query `orderby` vocabulary cannot express.
	 *
	 * @var string
	 */
	public const HOOK_POSTS_ORDERBY = 'posts_orderby';

	/**
	 * HPOS storage — filter rules, appended to the `WHERE` clause.
	 *
	 * The `woocommerce_orders_table_query_clauses` hook carries two unrelated roles and
	 * v1 registers a separate callback for each, so the keys are suffixed by role; a
	 * single key would make each callback apply both and duplicate the `WHERE` fragment.
	 *
	 * @var string
	 */
	public const HOOK_HPOS_FILTERS = 'woocommerce_orders_table_query_clauses/filters';

	/**
	 * HPOS storage — the sort, written into the `ORDER BY` clause.
	 *
	 * @var string
	 */
	public const HOOK_HPOS_ORDERBY = 'woocommerce_orders_table_query_clauses/orderby';

	/**
	 * Collection slug this plan was built for.
	 *
	 * @var string
	 */
	private $collection;

	/**
	 * Declaration rows for the collection.
	 *
	 * @var array
	 */
	private $rules;

	/**
	 * Resolved storage dialect.
	 *
	 * @var string
	 */
	private $storage;

	/**
	 * Claimed canonical name => claimed value.
	 *
	 * @var array<string, mixed>
	 */
	private $claims = array();

	/**
	 * Request keys the claims were read from, so they can be stripped when forwarding.
	 *
	 * @var string[]
	 */
	private $claimed_keys = array();

	/**
	 * The canonical sort this plan owns, or null.
	 *
	 * @var string|null
	 */
	private $sort;

	/**
	 * The raw `order` param, read but never claimed — wc/v3 needs it forwarded.
	 *
	 * @var string|null
	 */
	private $request_order;

	/**
	 * Build a plan. Use `Collection_Rules::for_request()`.
	 *
	 * @internal
	 *
	 * @param string          $collection Collection slug.
	 * @param array           $rules      Declaration rows.
	 * @param string          $storage    Resolved storage dialect.
	 * @param WP_REST_Request $request    Request to claim params from.
	 * @param array           $param_map  Canonical name => request key.
	 */
	public function __construct( string $collection, array $rules, string $storage, WP_REST_Request $request, array $param_map ) {
		$this->collection = $collection;
		$this->rules      = $rules;
		$this->storage    = $storage;

		$order_key           = $this->request_key( $param_map, 'order' );
		$raw_order           = null === $order_key ? null : $request->get_param( $order_key );
		$this->request_order = \is_string( $raw_order ) && '' !== $raw_order ? $raw_order : null;

		$this->claim_sort( $request, $param_map );
		$this->claim_filters( $request, $param_map );
	}

	/**
	 * The collection this plan was built for.
	 *
	 * @return string
	 */
	public function collection(): string {
		return $this->collection;
	}

	/**
	 * The storage dialect this plan targets.
	 *
	 * @return string
	 */
	public function storage(): string {
		return $this->storage;
	}

	/**
	 * Whether this plan contributes nothing (unknown collection, or nothing claimed).
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return null === $this->sort && array() === $this->claims;
	}

	/**
	 * The canonical sort this plan owns, or null.
	 *
	 * @return string|null
	 */
	public function sort(): ?string {
		return $this->sort;
	}

	/**
	 * Canonical name => claimed value, for every param this plan took ownership of.
	 *
	 * @return array<string, mixed>
	 */
	public function claims(): array {
		$claims = $this->claims;
		if ( null !== $this->sort ) {
			$claims['orderby'] = $this->sort;
		}

		return $claims;
	}

	/**
	 * Strip every claimed request key from a set of query params.
	 *
	 * The complement of `claims()`: what remains is what the proxy forwards to wc/v3.
	 *
	 * @param array $params Query params to narrow.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params ): array {
		foreach ( $this->claimed_keys as $key ) {
			unset( $params[ $key ] );
		}

		return $params;
	}

	/**
	 * Apply this plan's clause body for one keyed role.
	 *
	 * Type-preserving: the return type always matches `$value`. An unrecognised key is a
	 * caller bug, reported through `_doing_it_wrong` and passed through unchanged rather
	 * than throwing into the middle of a query.
	 *
	 * @param string $hook    One of the `HOOK_*` constants.
	 * @param mixed  $value   The value to filter (args array, clause string, clauses array).
	 * @param mixed  ...$context Hook context — typically the query object, then its args.
	 *
	 * @return mixed
	 */
	public function filter( string $hook, $value, ...$context ) {
		switch ( $hook ) {
			case self::HOOK_QUERY_ARGS:
				return \is_array( $value ) ? $this->apply_meta_filters( $value ) : $value;

			case self::HOOK_PREPARE_ARGS:
				return \is_array( $value ) ? $this->apply_legacy_sort_args( $value ) : $value;

			case self::HOOK_POSTS_WHERE:
				return \is_string( $value ) ? $this->apply_legacy_id_sets( $value ) : $value;

			case self::HOOK_POSTS_ORDERBY:
				return \is_string( $value ) ? $this->apply_legacy_sort_clause( $value, $context[0] ?? null ) : $value;

			case self::HOOK_HPOS_FILTERS:
				return \is_array( $value ) ? $this->apply_hpos_filters( $value, $context[0] ?? null ) : $value;

			case self::HOOK_HPOS_ORDERBY:
				return \is_array( $value ) ? $this->apply_hpos_sort( $value, $context[0] ?? null, $context[1] ?? array() ) : $value;
		}

		_doing_it_wrong(
			__METHOD__,
			esc_html(
				sprintf(
					/* translators: %s: the unrecognised Collection Rules hook key. */
					__( 'Unknown Collection Rules hook "%s"; the value was passed through unchanged.', 'woocommerce-pos' ),
					$hook
				)
			),
			esc_html( VERSION )
		);

		return $value;
	}

	/**
	 * Install this plan's callbacks, run `$run`, then unwind every binding in reverse.
	 *
	 * The proxy lane's ONLY install path. Bindings are closures captured here, so the
	 * unwind removes the exact callables that were added — never a re-derived tuple that
	 * could miss. An exception from `$run` propagates AFTER the unwind.
	 *
	 * @param callable $run The forward to wrap.
	 *
	 * @return mixed Whatever `$run` returns.
	 *
	 * @throws Throwable Re-thrown from `$run`, after the unwind.
	 */
	public function around( callable $run ) {
		$bindings = $this->install();

		try {
			return $run();
		} finally {
			foreach ( array_reverse( $bindings ) as $binding ) {
				remove_filter( $binding[0], $binding[1], $binding[2] );
			}
		}
	}

	/**
	 * Whether this plan claims any non-empty id set.
	 *
	 * Both Read Lanes ask the declaration table this question rather than
	 * testing for the presence of a specific request param, so a new `id_set`
	 * row applies on both lanes or neither.
	 *
	 * Note this is deliberately narrower than `isset( $request['wcpos_include'] )`:
	 * a present-but-empty value claims nothing. That is not a behaviour change —
	 * both clause bodies already skip empty sets (`apply_legacy_id_sets()` iterates
	 * `claimed_id_sets()`, `apply_hpos_filters()` guards on `array() !== $value`),
	 * so installing the callback for an empty set appended nothing anyway.
	 *
	 * @return bool
	 */
	public function claims_id_sets(): bool {
		return array() !== $this->claimed_id_sets();
	}

	/**
	 * Whether the claimed sort needs the legacy `posts_orderby` rewrite.
	 *
	 * Reads the sort's declaration instead of naming a sort inline, so a second
	 * `posts_orderby` recipe added to the table is picked up by both Read Lanes.
	 *
	 * @return bool
	 */
	public function needs_legacy_posts_orderby(): bool {
		return null !== $this->sort && isset( $this->rules['sorts'][ $this->sort ]['posts']['posts_orderby'] );
	}

	/**
	 * Attach every callback this plan needs for a proxied forward.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}> Bindings, in install order.
	 */
	private function install(): array {
		$bindings = array();

		if ( $this->is_empty() ) {
			return $bindings;
		}

		// `meta_query` rows are storage-neutral (`wc_get_orders()` honours them on both),
		// and the legacy sort args are a no-op under HPOS, so one binding covers both.
		if ( array() !== $this->claimed_meta_filters() || $this->has_legacy_meta_sort() ) {
			$args_callback = function ( $args ) {
				$args = $this->filter( self::HOOK_QUERY_ARGS, $args );

				return $this->filter( self::HOOK_PREPARE_ARGS, $args );
			};
			add_filter( self::HOOK_QUERY_ARGS, $args_callback, 10, 1 );
			$bindings[] = array( self::HOOK_QUERY_ARGS, $args_callback, 10 );
		}

		if ( Collection_Rules::STORAGE_HPOS === $this->storage ) {
			// v1 registers the filter callback before the sort callback, both at priority
			// 10, so the clauses are built in that order. One closure applying them in the
			// same order produces the identical clause string.
			$clauses_callback = function ( $clauses, $query = null, $args = array() ) {
				$clauses = $this->filter( self::HOOK_HPOS_FILTERS, $clauses, $query );

				return $this->filter( self::HOOK_HPOS_ORDERBY, $clauses, $query, $args );
			};
			add_filter( 'woocommerce_orders_table_query_clauses', $clauses_callback, 10, 3 );
			$bindings[] = array( 'woocommerce_orders_table_query_clauses', $clauses_callback, 10 );

			return $bindings;
		}

		if ( $this->needs_legacy_posts_orderby() ) {
			$orderby_callback = function ( $orderby, $query = null ) {
				return $this->filter( self::HOOK_POSTS_ORDERBY, $orderby, $query );
			};
			add_filter( 'posts_orderby', $orderby_callback, 10, 2 );
			$bindings[] = array( 'posts_orderby', $orderby_callback, 10 );
		}

		if ( $this->claims_id_sets() ) {
			/*
			 * `posts_where` fires for EVERY WP_Query, and `wcpos/v1` leaves its callback
			 * installed for the remainder of the request without a post-type guard (frozen
			 * behaviour, reproduced verbatim in the clause body). The proxy lane scopes the
			 * binding to this forward AND guards it, so no unrelated query inside the
			 * forward can pick up an order id set.
			 */
			$where_callback = function ( $where, $query = null ) {
				$post_type = $query->query_vars['post_type'] ?? null;
				// Legacy order queries may carry post_type as a string OR an array
				// (wc_get_order_types() / explicit `type` args); both must match or
				// the proxy lane drops the id-set clause while v1 still applies it.
				if ( 'shop_order' !== $post_type && ( ! \is_array( $post_type ) || ! \in_array( 'shop_order', $post_type, true ) ) ) {
					return $where;
				}

				return $this->filter( self::HOOK_POSTS_WHERE, $where, $query );
			};
			add_filter( 'posts_where', $where_callback, 10, 2 );
			$bindings[] = array( 'posts_where', $where_callback, 10 );
		}

		return $bindings;
	}

	/**
	 * Claim the `orderby` param when its value names a sort this collection declares.
	 *
	 * @param WP_REST_Request $request   Request to read.
	 * @param array           $param_map Canonical name => request key.
	 */
	private function claim_sort( WP_REST_Request $request, array $param_map ): void {
		$key = $this->request_key( $param_map, 'orderby' );
		if ( null === $key ) {
			return;
		}

		$value = $request->get_param( $key );
		if ( ! \is_string( $value ) || ! isset( $this->rules['sorts'][ $value ] ) ) {
			return;
		}

		$this->sort           = $value;
		$this->claimed_keys[] = $key;
	}

	/**
	 * Claim every filter param the map exposes and the request carries.
	 *
	 * @param WP_REST_Request $request   Request to read.
	 * @param array           $param_map Canonical name => request key.
	 */
	private function claim_filters( WP_REST_Request $request, array $param_map ): void {
		foreach ( $this->rules['filters'] ?? array() as $canonical => $rule ) {
			$entry = $param_map[ $canonical ] ?? null;
			if ( null === $entry ) {
				continue;
			}
			$key = $this->request_key( $param_map, $canonical );
			if ( null === $key ) {
				continue;
			}

			$value = $request->get_param( $key );
			if ( null === $value ) {
				continue;
			}

			if ( \is_array( $entry ) && 'search' === ( $entry['when'] ?? null ) ) {
				$search = $request->get_param( 'search' );
				if ( ! \is_string( $search ) || '' === trim( $search ) ) {
					continue;
				}
			}

			$this->claims[ $canonical ] = $this->normalize( $value, $rule, \is_array( $entry ) ? ( $entry['parse'] ?? null ) : null );
			$this->claimed_keys[]       = $key;
		}
	}

	/**
	 * Coerce a claimed value into the shape its rule expects.
	 *
	 * @param mixed       $value The raw request value.
	 * @param array       $rule  The filter row.
	 * @param string|null $parse Optional map-declared parser.
	 *
	 * @return mixed
	 */
	private function normalize( $value, array $rule, ?string $parse ) {
		if ( isset( $rule['id_set'] ) ) {
			/*
			 * `wcpos/v1` guards with `! empty()` and then casts with
			 * `array_map( 'intval', (array) $value )`, which collapses a comma-joined string
			 * to its first id. That is frozen wire behaviour, so it stays the default; the
			 * proxy map opts into `wp_parse_id_list` explicitly. Either way an empty result
			 * still counts as CLAIMED — the param is stripped from the forward — it simply
			 * contributes no clause.
			 */
			if ( 'id_list' === $parse ) {
				return wp_parse_id_list( $value );
			}

			return empty( $value ) ? array() : array_map( 'intval', (array) $value );
		}

		if ( 'key' === ( $rule['sanitize'] ?? null ) ) {
			if ( \is_array( $value ) ) {
				return array_map( 'sanitize_key', array_values( $value ) );
			}

			return sanitize_key( \is_scalar( $value ) ? (string) $value : '' );
		}

		return $value;
	}

	/**
	 * Resolve a canonical name to the request key the map exposes it under.
	 *
	 * @param array  $param_map Canonical name => request key.
	 * @param string $canonical Canonical name.
	 *
	 * @return string|null Null when the map does not expose the name.
	 */
	private function request_key( array $param_map, string $canonical ): ?string {
		$entry = $param_map[ $canonical ] ?? null;

		if ( \is_string( $entry ) && '' !== $entry ) {
			return $entry;
		}

		$key = Meta_Entry::key( $entry );
		if ( \is_array( $entry ) && \is_string( $key ) && '' !== $key ) {
			return $key;
		}

		return null;
	}

	/**
	 * The claimed id-set rules, in declaration order.
	 *
	 * @return array<string, array> Canonical name => filter row.
	 */
	private function claimed_id_sets(): array {
		$sets = array();
		foreach ( $this->rules['filters'] ?? array() as $canonical => $rule ) {
			if ( isset( $rule['id_set'], $this->claims[ $canonical ] ) && array() !== $this->claims[ $canonical ] ) {
				$sets[ $canonical ] = $rule;
			}
		}

		return $sets;
	}

	/**
	 * The claimed meta filter rules that apply to this plan's storage, in declaration order.
	 *
	 * @return array<string, array> Canonical name => filter row.
	 */
	private function claimed_meta_filters(): array {
		$metas = array();
		foreach ( $this->rules['filters'] ?? array() as $canonical => $rule ) {
			if ( ! isset( $rule['meta'], $this->claims[ $canonical ] ) ) {
				continue;
			}
			if ( isset( $rule['meta']['storage'] ) && $rule['meta']['storage'] !== $this->storage ) {
				continue;
			}
			if ( array() === $this->claims[ $canonical ] ) {
				continue;
			}
			$metas[ $canonical ] = $rule;
		}

		return $metas;
	}

	/**
	 * Whether this plan's sort is expressed as a legacy `meta_key` sort.
	 *
	 * @return bool
	 */
	private function has_legacy_meta_sort(): bool {
		return Collection_Rules::STORAGE_POSTS === $this->storage
			&& null !== $this->sort
			&& isset( $this->rules['sorts'][ $this->sort ]['posts']['meta_key'] );
	}

	/**
	 * Contribute `meta_query` rows for every claimed meta filter.
	 *
	 * Storage-neutral: `wc_get_orders()` honours `meta_query` on both storages.
	 *
	 * @param array $args WC REST query args.
	 *
	 * @return array
	 */
	private function apply_meta_filters( array $args ): array {
		foreach ( $this->claimed_meta_filters() as $canonical => $rule ) {
			$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The POS cashier/store/channel filters are meta-backed by design.
				'key'   => $rule['meta']['key'],
				'value' => $this->claims[ $canonical ],
			);
		}

		return $args;
	}

	/**
	 * Map a claimed sort onto legacy storage's `meta_key` / `orderby` query args.
	 *
	 * @param array $args WC REST query args.
	 *
	 * @return array
	 */
	private function apply_legacy_sort_args( array $args ): array {
		if ( Collection_Rules::STORAGE_POSTS !== $this->storage || null === $this->sort ) {
			return $args;
		}

		$rule = $this->rules['sorts'][ $this->sort ]['posts'] ?? array();
		if ( ! isset( $rule['meta_key'], $rule['orderby'] ) ) {
			return $args;
		}

		$args['meta_key'] = $rule['meta_key']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Meta sorts are the only encoding legacy order storage has.
		$args['orderby']  = $rule['orderby'];

		return $args;
	}

	/**
	 * Append claimed id sets to a legacy `WHERE` clause.
	 *
	 * @param string $where The `WHERE` clause so far.
	 *
	 * @return string
	 */
	private function apply_legacy_id_sets( string $where ): string {
		global $wpdb;

		if ( Collection_Rules::STORAGE_POSTS !== $this->storage ) {
			return $where;
		}

		foreach ( $this->claimed_id_sets() as $canonical => $rule ) {
			$ids         = $this->claims[ $canonical ];
			$ids_format  = implode( ',', array_fill( 0, \count( $ids ), '%d' ) );
			$operator    = $rule['id_set']['operator'];
			$where      .= $wpdb->prepare( " AND {$wpdb->posts}.ID {$operator} ($ids_format) ", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $operator comes from the declaration table and $ids_format is generated from array_fill with %d placeholders.
		}

		return $where;
	}

	/**
	 * Rewrite a legacy `ORDER BY` clause for a sort WP_Query cannot express.
	 *
	 * @param string $orderby The `ORDER BY` clause so far.
	 * @param mixed  $query   The WP_Query instance.
	 *
	 * @return string
	 */
	private function apply_legacy_sort_clause( string $orderby, $query ): string {
		global $wpdb;

		if ( Collection_Rules::STORAGE_POSTS !== $this->storage || null === $this->sort ) {
			return $orderby;
		}

		$column = $this->rules['sorts'][ $this->sort ]['posts']['posts_orderby'] ?? null;
		if ( null === $column ) {
			return $orderby;
		}

		$post_type = $query->query_vars['post_type'] ?? null;
		if ( 'shop_order' !== $post_type && ( ! \is_array( $post_type ) || ! \in_array( 'shop_order', $post_type, true ) ) ) {
			return $orderby;
		}

		/*
		 * The direction comes from the query WooCommerce built, exactly as the HPOS sort
		 * takes it from that query's args — one derivation for both storages and both
		 * Read Lanes. `WP_Query::get_posts()` normalises `order` (upper-cased, defaulting
		 * to DESC) before `posts_orderby` fires, and it is populated from the same request
		 * `order` param v1 used to read directly, so this is byte-identical on the direct
		 * lane while giving the proxy lane the same answer instead of its own hard-coded
		 * DESC. The terminal `ASC` is v1's own fallback, reached only if nothing at all
		 * supplied a direction.
		 */
		$order = $query->query_vars['order'] ?? $this->request_order ?? 'ASC';
		$order = \is_scalar( $order ) ? strtoupper( (string) $order ) : 'ASC';
		// $request_order is the RAW request param — it feeds SQL text below, so it
		// must never carry anything but the two legal directions.
		$order = \in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'ASC';

		return "{$wpdb->posts}.{$column} {$order}";
	}

	/**
	 * Append claimed filters to the HPOS clause set.
	 *
	 * @param array $clauses The HPOS query clauses.
	 * @param mixed $query   The OrdersTableQuery instance.
	 *
	 * @return array
	 */
	private function apply_hpos_filters( array $clauses, $query ): array {
		global $wpdb;

		if ( Collection_Rules::STORAGE_HPOS !== $this->storage || ! \is_object( $query ) || ! method_exists( $query, 'get_table_name' ) ) {
			return $clauses;
		}

		$orders = $query->get_table_name( 'orders' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names come from WooCommerce; placeholder lists are generated per value below.
		foreach ( $this->rules['filters'] ?? array() as $canonical => $rule ) {
			if ( ! isset( $this->claims[ $canonical ] ) ) {
				continue;
			}
			$value = $this->claims[ $canonical ];

			if ( isset( $rule['hpos_data'] ) ) {
				$values = array_values( (array) $value );
				if ( array() === $values ) {
					continue;
				}
				$table              = $query->get_table_name( $rule['hpos_data']['table'] );
				$column             = $rule['hpos_data']['column'];
				$placeholders       = implode( ', ', array_fill( 0, \count( $values ), '%s' ) );
				$clauses['where'] .= $wpdb->prepare( " AND {$orders}.id IN (SELECT order_id FROM {$table} WHERE {$column} IN ({$placeholders}))", ...$values );

				continue;
			}

			if ( isset( $rule['id_set'] ) && array() !== $value ) {
				$clauses['where'] .= ' AND ' . $orders . '.id ' . $rule['id_set']['operator'] . ' (' . implode( ',', array_map( 'intval', $value ) ) . ')';
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return $clauses;
	}

	/**
	 * Write the claimed sort into the HPOS `ORDER BY` clause.
	 *
	 * Deferential by design — see "the WooCommerce-owned sorts" in the class docblock.
	 *
	 * @param array $clauses The HPOS query clauses.
	 * @param mixed $query   The OrdersTableQuery instance.
	 * @param array $args    The query args.
	 *
	 * @return array
	 */
	private function apply_hpos_sort( array $clauses, $query, array $args ): array {
		if ( Collection_Rules::STORAGE_HPOS !== $this->storage || null === $this->sort ) {
			return $clauses;
		}

		/*
		 * WooCommerce maps SOME of these names itself (`total` is in
		 * `OrdersTableQuery::sanitize_order_orderby()`'s table today), and when it does it
		 * has already written a correct clause with a properly sanitized direction — so we
		 * defer, exactly as v1's guard did.
		 *
		 * The `orderby` conjunct is what makes that guard correct on BOTH lanes. v1 leaves
		 * the claimed name on the request, so a non-empty clause is always WooCommerce
		 * mapping OUR sort (the conjunct is redundant there, and v1's SQL is unchanged).
		 * The proxy must STRIP the claimed name — wc/v3's enum would 400 on it — so the
		 * inner query carries wc/v3's default `date` instead, and its non-empty clause has
		 * nothing to do with the sort the client asked for. Testing the bare emptiness
		 * there would silently drop the sort.
		 *
		 * `Test_Collection_Rules_Guard_HPOS` pins which sorts fall on which side.
		 */
		$woocommerce_mapped_our_sort = ( $args['orderby'] ?? null ) === $this->sort;
		if ( $woocommerce_mapped_our_sort && isset( $clauses['orderby'] ) && '' !== $clauses['orderby'] ) {
			return $clauses;
		}
		if ( ! \is_object( $query ) || ! method_exists( $query, 'get_table_name' ) ) {
			return $clauses;
		}

		$column = $this->rules['sorts'][ $this->sort ]['hpos']['column'] ?? null;
		if ( null === $column ) {
			return $clauses;
		}

		// v1 verbatim: the direction comes from the query args WooCommerce built from the
		// request (which carries wc/v3's own `order` default), falling back to ASC.
		// Whitelisted before interpolation — same defense as the legacy path. Legal
		// values pass through byte-verbatim (the clause goldens pin the casing).
		$order              = $args['order'] ?? 'ASC';
		$order              = \is_scalar( $order ) ? (string) $order : 'ASC';
		$order              = \in_array( strtoupper( $order ), array( 'ASC', 'DESC' ), true ) ? $order : 'ASC';
		$clauses['orderby'] = $query->get_table_name( 'orders' ) . '.' . $column . ' ' . $order;

		return $clauses;
	}
}
