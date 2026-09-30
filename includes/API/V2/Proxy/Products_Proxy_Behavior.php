<?php
/**
 * Product catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan;
use WCPOS\WooCommercePOS\Sync\Digest_Index;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Store_Scope;
use WP_Date_Query;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Scopes POS product visibility and the POS product sorts to the wc/v3 forward.
 */
final class Products_Proxy_Behavior extends Scoped_Proxy_Behavior implements Fast_Path_Behavior {
	/**
	 * The one `_fields` set the fast path answers, sorted (order-insensitive match).
	 */
	private const FAST_PATH_FIELDS = array( 'date_modified_gmt', 'id', 'stock_quantity', 'stock_status' );

	/**
	 * Every query param key the fast path tolerates.
	 *
	 * `include`, `exclude`, `modified_after` and `dates_are_gmt` are reproduced in SQL.
	 * The rest are transport markers that never change which rows or values wc/v3
	 * serves: `wcpos` (the POS marker query var, the header-stripping fallback for
	 * `X-WCPOS` read by `wcpos_request()`), `_wcpos_envelope` (the opt-in
	 * {@see \WCPOS\WooCommercePOS\Sync\Response_Envelope}) and `rest_route` (the route
	 * itself on a plain-permalink site). {@see Store_Scope::PARAM} is deliberately NOT
	 * here: a Pro store can change stock per store, so a scoped read is hydrated.
	 */
	private const FAST_PATH_PARAMS = array( 'per_page', '_fields', 'include', 'exclude', 'modified_after', 'dates_are_gmt', 'wcpos', '_wcpos_envelope', 'rest_route' );

	/**
	 * Proxy request keys claimed by the product Collection Rules plan.
	 *
	 * `order` is READ but never claimed — wc/v3 needs it forwarded, and the clause
	 * bodies take the direction from the query WooCommerce builds from it.
	 */
	private const PARAM_MAP = array(
		'orderby' => 'orderby',
		'order'   => 'order',
		'search'  => 'search',
	);

	/**
	 * The Collection Rules plan for the request in flight.
	 *
	 * @var null|Collection_Rules_Plan
	 */
	private $plan;

	/**
	 * Claim the POS sorts and remember search state.
	 *
	 * A POS sort (`sku`, `barcode`, `stock_quantity`, `stock_status`) is CLAIMED, not
	 * forwarded: wc/v3's own `orderby` enum has never heard of them and answers
	 * `rest_invalid_param` (400). The plan strips the claimed key here and the sort is
	 * written back onto the inner query by the plan.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		$plan_request = clone $request;
		$plan_request->set_param( 'search', $params['search'] ?? null );
		$this->plan = Collection_Rules::for_request( 'products', $plan_request, self::PARAM_MAP );

		return $this->plan->forwarded_params( $params );
	}

	/**
	 * Install this resource's hooks and return their removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		// The stable-sort tiebreak is UNCONDITIONAL: the POS grid defaults to a
		// title sort (mono#1376), and a tied title at a page boundary would
		// otherwise skip or duplicate across the client's multi-page walk.
		// NOT the generic object_query filter: the products controller OVERWRITES
		// orderby AFTER that filter via WC()->query->get_catalog_ordering_args(),
		// so the rewrite must ride that function's own filter — the last word on
		// product catalog ordering.
		$stable_sort = static function ( $args ) {
			return Stable_Sort::with_post_id_tiebreak( (array) $args );
		};
		add_filter( 'woocommerce_get_catalog_ordering_args', $stable_sort );
		$bindings = array( array( 'woocommerce_get_catalog_ordering_args', $stable_sort, 10 ) );

		return $bindings;
	}

	/**
	 * Run the collection rules around the WooCommerce forward.
	 *
	 * @param callable $forward Forward operation.
	 * @return mixed
	 */
	protected function run( callable $forward ) {
		$plan = $this->plan ?? Collection_Rules::for_request( 'products', new WP_REST_Request( 'GET', '/wcpos/v2/products' ) );
		return $plan->around( $forward );
	}

	/**
	 * Answer the reconciliation read (#2113) from ONE SQL query, without hydration.
	 *
	 * Rows are published `product` posts (no variations; by
	 * {@see Digest_Index::published_product_predicate_sql()}, the reconcile listing's rule),
	 * minus the POS-hidden catalog ids ({@see Pos_Visibility::CATALOG}), narrowed by
	 * `include` (which wins over `exclude`, as in WP_Query) and `modified_after`,
	 * ordered `post_date DESC, ID ASC` like the hydrated default plus Stable_Sort.
	 *
	 * Stock values are read from the product's own postmeta (`_stock`, `_stock_status`)
	 * exactly as `WC_Product::get_stock_quantity()` / `get_stock_status()` read them.
	 * `wc_product_meta_lookup` is deliberately NOT used: it gates the quantity on managed
	 * stock (null for an unmanaged product even when `_stock` holds a value the product
	 * reports) and can lag meta written outside WooCommerce's data layer, either of which
	 * would make the listing disagree with the product permanently.
	 *
	 * Before the query, wc/v3's own permission check for the listing runs
	 * ({@see self::wc_v3_permission()}), and its refusal is answered as wc/v3 would.
	 * WooCommerce's product-query filters (`woocommerce_rest_product_object_query` and the
	 * other listing filters the hydrated forward applies) are not honoured by the fast path,
	 * so a plugin that narrows the product REST listing through them does not narrow this
	 * one; only the permission check is reproduced.
	 *
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return null|WP_REST_Response Null when not the fast-path shape or wc/v3's route is absent.
	 */
	public function fast_response( WP_REST_Request $request ): ?WP_REST_Response {
		if ( ! $this->is_fast_path_request( $request ) ) {
			return null;
		}
		$permission = $this->wc_v3_permission( $request );
		if ( true !== $permission ) {
			return $permission instanceof WP_Error ? rest_convert_error_to_response( $permission ) : null;
		}
		global $wpdb;
		$params = $request->get_query_params();
		$posts  = $wpdb->posts;

		$sql = "SELECT {$posts}.ID AS id, {$posts}.post_modified_gmt AS date_modified_gmt,"
			. ' pm_stock.meta_value AS meta_stock, pm_status.meta_value AS meta_stock_status'
			. " FROM {$posts}"
			// The first meta row, as get_post_meta( $id, $key, true ) reads it; a duplicate row must not repeat the product.
			. " LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.meta_id = ( SELECT MIN( m_stock.meta_id ) FROM {$wpdb->postmeta} m_stock WHERE m_stock.post_id = {$posts}.ID AND m_stock.meta_key = '_stock' )"
			. " LEFT JOIN {$wpdb->postmeta} pm_status ON pm_status.meta_id = ( SELECT MIN( m_status.meta_id ) FROM {$wpdb->postmeta} m_status WHERE m_status.post_id = {$posts}.ID AND m_status.meta_key = '_stock_status' )"
			. " WHERE {$posts}.post_type = 'product'"
			// The shared published rule, posts table for both aliases: its variation branch never matches a `product` row.
			. ' AND ' . Digest_Index::published_product_predicate_sql( $posts, $posts );
		$sql     = ( new Pos_Visibility() )->apply_to_sql_where( $sql, "{$posts}.ID", Pos_Visibility::CATALOG );
		$include = wp_parse_id_list( $params['include'] ?? array() );
		$exclude = wp_parse_id_list( $params['exclude'] ?? array() );
		if ( array() !== $include ) {
			$sql .= " AND {$posts}.ID IN (" . implode( ',', $include ) . ')';
		} elseif ( array() !== $exclude ) {
			$sql .= " AND {$posts}.ID NOT IN (" . implode( ',', $exclude ) . ')';
		}
		if ( isset( $params['modified_after'] ) ) {
			// wc/v3's own date_query clause, so the timezone conversion is core's.
			$column = rest_sanitize_boolean( $params['dates_are_gmt'] ?? false ) ? 'post_modified_gmt' : 'post_modified';
			$sql   .= ( new WP_Date_Query(
				array(
					array(
						'column' => $column,
						'after'  => $params['modified_after'],
					),
				),
				'post_modified'
			) )->get_sql();
		}
		$sql .= " ORDER BY {$posts}.post_date DESC, {$posts}.ID ASC";

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value is prepared or an absint id list above.
		if ( '' !== $wpdb->last_error ) {
			Logger::error( 'Products fast path query failed: ' . $wpdb->last_error );

			return rest_convert_error_to_response(
				new WP_Error( 'woocommerce_pos_rest_cannot_fetch', __( 'Could not fetch products.', 'woocommerce-pos' ), array( 'status' => 500 ) )
			);
		}

		$statuses = wc_get_product_stock_status_options();
		$data     = array();
		foreach ( (array) $rows as $row ) {
			$modified = (string) $row['date_modified_gmt'];
			$data[]   = array(
				'id'                => (int) $row['id'],
				'date_modified_gmt' => ( '' === $modified || '0000-00-00 00:00:00' === $modified ) ? null : mysql_to_rfc3339( $modified ),
				// As WC_Product::set_stock_quantity() reads `_stock`: '' is null, not gated on `_manage_stock`.
				'stock_quantity'    => ( null === $row['meta_stock'] || '' === (string) $row['meta_stock'] ) ? null : wc_stock_amount( $row['meta_stock'] ),
				// WooCommerce's own fallback for an unknown or missing stock status.
				'stock_status'      => isset( $statuses[ (string) $row['meta_stock_status'] ] ) ? $row['meta_stock_status'] : 'instock',
			);
		}
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) \count( $data ) );
		$response->header( 'X-WP-TotalPages', '1' );

		return $response;
	}

	/**
	 * Run wc/v3's OWN permission check for the products listing.
	 *
	 * Run because the fast path skips the forward: calls the `/wc/v3/products` GET handler's
	 * registered `permission_callback` (with its `woocommerce_rest_check_permissions` filter)
	 * as `WP_REST_Server::respond_to_request()` does, mapping a refusal to that method's error.
	 *
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return null|true|WP_Error True when allowed; the refusal; null when the route or its
	 *                            callback is not registered (the hydrated forward then answers).
	 */
	private function wc_v3_permission( WP_REST_Request $request ) {
		$routes = rest_get_server()->get_routes();
		foreach ( $routes['/wc/v3/products'] ?? array() as $handler ) {
			if ( empty( $handler['methods']['GET'] ) || empty( $handler['permission_callback'] ) ) {
				continue;
			}
			$inner = new WP_REST_Request( 'GET', '/wc/v3/products' );
			$inner->set_query_params( $request->get_query_params() );
			$permission = \call_user_func( $handler['permission_callback'], $inner );
			if ( true === $permission || is_wp_error( $permission ) ) {
				return $permission;
			}

			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.' ), array( 'status' => rest_authorization_required_code() ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own string, as respond_to_request() builds it.
		}

		return null;
	}

	/**
	 * Whether the request is exactly the fast-path shape; anything else is hydrated.
	 *
	 * ALL of: `per_page` is `-1`; `_fields` (parsed and trimmed as core does) is exactly
	 * {@see self::FAST_PATH_FIELDS}, no duplicates, nothing else; every query param key
	 * is in {@see self::FAST_PATH_PARAMS}; no store scope is in effect (the forward would
	 * stamp one); `dates_are_gmt`, when present, is a boolean wc/v3 accepts; and
	 * `modified_after`, when present, is a date wc/v3 accepts (a truthy `rest_parse_date()`,
	 * core's `date-time` format check), so an invalid one still gets wc/v3's 400.
	 *
	 * @param WP_REST_Request $request Original proxy request.
	 */
	private function is_fast_path_request( WP_REST_Request $request ): bool {
		$params = $request->get_query_params();
		if ( ! \in_array( $params['per_page'] ?? null, array( -1, '-1' ), true )
			|| array() !== array_diff( array_keys( $params ), self::FAST_PATH_PARAMS )
			|| null !== Store_Scope::current() ) {
			return false;
		}
		$fields = wp_parse_list( $params['_fields'] ?? '' );
		foreach ( $fields as $field ) {
			if ( ! \is_string( $field ) ) {
				return false;
			}
		}
		$fields = array_map( 'trim', $fields );
		sort( $fields );
		if ( self::FAST_PATH_FIELDS !== $fields
			|| ( isset( $params['dates_are_gmt'] ) && ! rest_is_boolean( $params['dates_are_gmt'] ) ) ) {
			return false;
		}

		return ! isset( $params['modified_after'] )
			|| ( \is_string( $params['modified_after'] ) && (bool) rest_parse_date( $params['modified_after'] ) );
	}
}
