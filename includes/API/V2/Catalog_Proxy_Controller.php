<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\API\V1\Customers_Controller as V1_Customers_Controller;
use WCPOS\WooCommercePOS\API\V1\Taxes_Controller as V1_Taxes_Controller;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Store_Scope;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Catalog proxy endpoints — route replication READS through our `{API_NAMESPACE}`
 * namespace instead of hitting raw `wc/v3` directly (guardrail G4: replication is
 * wrapped in our namespace so we can customize the request/response and
 * duck-punch for replication WITHOUT editing wc/v3 or the client).
 *
 * Routes forward to their wc/v3 counterparts via `rest_do_request`, preserving
 * query params except where WCPOS adapts them (such as multi-term customer search
 * and the WCPOS-extended customer sorts),
 * plus the underlying status + pagination headers
 * (so the client's existing array-shaped parsing and `length < per_page`
 * pagination keep working), then exposes a single `woocommerce_pos_sync_proxy_response`
 * filter seam — `($data, $resource, $request)` — so replication can shape the
 * batch in one place. Today it is a faithful pass-through; the point is the
 * controlled seam + decoupling the client from wc/v3 routes/versioning.
 *
 * Per-object serialization customization (`woocommerce_pos_sync_serialized_product`
 * etc.) stays on the per-id paths — variations/resolve/changes — where the WC
 * object is actually loaded; this list proxy keeps wc/v3's own serialization and
 * filters the batch as a whole.
 */
class Catalog_Proxy_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;

	/**
	 * Customer `orderby` values WCPOS implements but wc/v3 does not accept.
	 *
	 * `V1_Customers_Controller::get_collection_params()` widens the customer
	 * orderby enum with exactly these five, and `wcpos_customer_query()` maps
	 * each onto a `WP_User_Query` column or meta sort. wc/v3's own enum is
	 * `id | include | name | registered_date`, so forwarding one of these
	 * verbatim is a `rest_invalid_param` 400 — the proxy strips it off the
	 * inner request and re-applies it through the V1 handler instead.
	 *
	 * Keep in sync with the enum in `V1_Customers_Controller`.
	 */
	private const WCPOS_CUSTOMER_ORDERBY = array(
		'first_name',
		'last_name',
		'email',
		'role',
		'username',
	);

	/**
	 * Canonical Collection Rule name => the request key this lane exposes it under.
	 *
	 * The proxy lane's narrowing map. `include`/`exclude` are claimed ONLY when the
	 * request also carries a search term: wc/v3 resolves `search` to a matched-id set
	 * that clobbers them, so the rule takes ownership exactly then and a plain targeted
	 * pull keeps wc/v3's native include semantics (including its ordering). Unlike the
	 * v1 map, this one exposes `created_via` — see `Sync\Collection_Rules`.
	 */
	private const ORDERS_PARAM_MAP = array(
		'orderby'     => 'orderby',
		'order'       => 'order',
		'pos_cashier' => 'pos_cashier',
		'pos_store'   => 'pos_store',
		'created_via' => 'created_via',
		'include'     => array(
			'key'   => 'include',
			'when'  => 'search',
			'parse' => 'id_list',
		),
		'exclude'     => array(
			'key'   => 'exclude',
			'when'  => 'search',
			'parse' => 'id_list',
		),
	);

	/**
	 * The `post__not_in` closure while a `/products` forward is in flight (null otherwise).
	 */
	private $pos_visibility_filter = null;

	public function register_routes(): void {
		foreach ( self::resources() as $route => $meta ) {
			$wc_route = $meta[0];
			$resource = $meta[1];
			register_rest_route(
				Api::ROUTE_NAMESPACE,
				'/' . ltrim( $route, '/' ),
				array(
					'methods' => WP_REST_Server::READABLE,
					// Closure carries the target so the handler never has to re-derive
					// it from the matched route — one handler, six wired routes.
					'callback' => function ( WP_REST_Request $request ) use ( $wc_route, $resource ) {
						return $this->proxy( $request, $wc_route, $resource );
					},
					'permission_callback' => array( $this, 'permissions_check' ),
				)
			);
		}
	}

	/**
	 * Forward the request to $wc_route via rest_do_request (adapting query
	 * params where needed and preserving wc/v3 status/headers), then expose the batch through the
	 * replication seam filter. wc/v3 errors are forwarded unchanged so the
	 * client sees the same failure it would have seen hitting wc/v3 directly.
	 */
	public function proxy( WP_REST_Request $request, string $wc_route, string $resource ) {
		$query_params          = $request->get_query_params();
		$customer_query_params = array();
		$customer_query_filter = null;
		$tax_filter_controller = null;
		$tax_query_filter      = null;
		if ( 'customers' === $resource && ! isset( $query_params['role'] ) ) {
			// The POS customer space is ALL WordPress users under the #1379
			// ruling (1.9 parity: v1 enumerated all users). wc/v3 defaults to
			// role=customer, so override it for targeted and untargeted pulls;
			// an explicit client role remains untouched. This subsumes the
			// earlier #1378 targeted-include exception.
			$query_params['role'] = 'all';
		}
		if ( 'customers' === $resource && isset( $query_params['search'] ) ) {
			$search = trim( (string) $query_params['search'] );
			// V1 parity: ANY non-empty search uses the per-term user-table filter (#1277).
			// wc/v3's own customer search misses first_name/last_name/billing meta, so even
			// single-word searches must go through it (and this keeps behavior consistent
			// across WooCommerce versions).
			if ( '' !== $search ) {
				unset( $query_params['search'] );
				$customer_query_params['search'] = $search;
			}
		}
		if ( 'customers' === $resource && isset( $query_params['orderby'] ) ) {
			$orderby = (string) $query_params['orderby'];
			// V1 parity (monorepo#1028): wc/v3's customer `orderby` enum is
			// id/include/name/registered_date, so a WCPOS-extended sort is a
			// rest_invalid_param 400 on the inner request — not a silent
			// ignore. Strip it and re-apply through the same V1 handler the
			// multi-term search uses. `order` (asc/desc) is wc/v3-native and
			// forwards untouched; WP_User_Query honours it for every branch
			// wcpos_customer_query sets.
			if ( \in_array( $orderby, self::WCPOS_CUSTOMER_ORDERBY, true ) ) {
				unset( $query_params['orderby'] );
				$customer_query_params['orderby'] = $orderby;
			}
		}
		if ( array() !== $customer_query_params ) {
			// Reuse V1's customer query filter without importing its handling of
			// other query parameters — the synthetic request carries only the
			// params we deliberately delegated, so nothing else leaks in.
			$customer_request = new WP_REST_Request();
			$customer_request->set_query_params( $customer_query_params );
			$customer_controller   = new V1_Customers_Controller();
			$customer_query_filter = static function ( array $prepared_args ) use ( $customer_controller, $customer_request ): array {
				return $customer_controller->wcpos_customer_query( $prepared_args, $customer_request );
			};
			add_filter( 'woocommerce_rest_customer_query', $customer_query_filter );
		}
		if ( 'taxes' === $resource ) {
			foreach ( array( 'include', 'exclude' ) as $param ) {
				if ( isset( $query_params[ $param ] ) ) {
					$query_params[ 'wcpos_' . $param ] = wp_parse_id_list( $query_params[ $param ] );
					unset( $query_params[ $param ] );
				}
			}
		}

		$orders_plan = null;
		if ( 'orders' === $resource ) {
			// Parity by construction: the same Collection Rules the wcpos/v1 controller
			// applies on the direct lane, installed around this forward and unwound after
			// it. Claimed params are stripped so wc/v3 never sees a WCPOS-only `orderby`
			// and never resolves `search` over an id set the rule now owns.
			$orders_plan  = Collection_Rules::for_request( 'orders', $request, self::ORDERS_PARAM_MAP );
			$query_params = $orders_plan->forwarded_params( $query_params );
		}

		$inner = new WP_REST_Request( WP_REST_Server::READABLE, $wc_route );
		unset( $query_params[ Store_Scope::PARAM ] );
		$inner->set_query_params( $query_params );
		// The till's store scope, republished as v1's `store_id` param (pro#425).
		// This is the greedy catalog list pull, so it is what decides whether the
		// products grid shows each store's own price or the web store's. Stamped
		// after set_query_params so a scope is never wiped by the forwarded set.
		Store_Scope::stamp( $inner );
		if ( 'orders' === $resource ) {
			// Server-authoritative, overriding any client-sent dp: every WCPOS
			// order surface serializes money at six decimals (v1 parity), so
			// revision hashes agree across the proxy, pull, and write lanes.
			$inner->set_param( 'dp', '6' );
		}
		// Leg-3 (ADR 0014 WP-M5): scope the POS servable filter around THIS forward only — added, then
		// removed — so `online_only` products drop out of the served set for both greedy list pulls and
		// targeted `include=` pulls, and no other product query on the request is affected. The client
		// then never holds them, and Leg-3 prunes any that were toggled `online_only` after being pulled.
		$this->add_pos_visibility_filter( $resource );
		$relax_wc_permissions = \in_array( $resource, array( 'coupons', 'taxes' ), true );
		if ( $relax_wc_permissions ) {
			add_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10, 4 );
		}
		$prime_product_caches = null;
		if ( 'products' === $resource ) {
			$prime_product_caches = static function ( $found_posts, $query ) use ( &$prime_product_caches ) {
				if ( ! \in_array( 'product', (array) $query->get( 'post_type' ), true ) ) {
					return $found_posts;
				}
				remove_filter( 'found_posts', $prime_product_caches, 10 );
				if ( 'ids' === $query->get( 'fields' ) && ! empty( $query->posts ) ) {
					_prime_post_caches( array_map( 'intval', (array) $query->posts ), true, true );
				}

				return $found_posts;
			};
			add_filter( 'found_posts', $prime_product_caches, 10, 2 );
		}
		try {
			if ( 'taxes' === $resource && ( isset( $query_params['wcpos_include'] ) || isset( $query_params['wcpos_exclude'] ) ) ) {
				$tax_filter_controller = new V1_Taxes_Controller();
				$tax_filter_controller->wcpos_dispatch_request( null, $inner, '', array() );
				remove_filter( 'woocommerce_rest_prepare_tax', array( $tax_filter_controller, 'wcpos_prepare_tax_response' ), 10 );
				remove_filter( 'woocommerce_rest_tax_query', array( $tax_filter_controller, 'wcpos_tax_query' ), 10 );
				$tax_query_filter = static function ( string $query ) use ( $tax_filter_controller ): string {
					return $tax_filter_controller->wcpos_tax_add_include_exclude_to_sql( $query );
				};
				add_filter( 'query', $tax_query_filter, 10, 1 );
			}
			$forward  = static function () use ( $inner ) {
				// Ours for the duration of the forward (pro#425 review): a consumer
				// may shape this response, but must leave stock wc/v3 alone.
				return Store_Scope::in_v2_lane(
					static function () use ( $inner ) {
						return rest_do_request( $inner );
					}
				);
			};
			$response = null === $orders_plan ? $forward() : $orders_plan->around( $forward );
		} finally {
			if ( null !== $prime_product_caches ) {
				remove_filter( 'found_posts', $prime_product_caches, 10 );
			}
			if ( null !== $tax_filter_controller ) {
				remove_filter( 'woocommerce_rest_tax_query', array( $tax_filter_controller, 'wcpos_tax_query' ), 10 );
				remove_filter( 'query', array( $tax_filter_controller, 'wcpos_tax_add_include_exclude_to_sql' ), 10 );
				remove_filter( 'woocommerce_rest_prepare_tax', array( $tax_filter_controller, 'wcpos_prepare_tax_response' ), 10 );
			}
			if ( null !== $tax_query_filter ) {
				remove_filter( 'query', $tax_query_filter, 10 );
			}
			if ( $relax_wc_permissions ) {
				remove_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10 );
			}
			$this->remove_pos_visibility_filter();
			if ( null !== $customer_query_filter ) {
				remove_filter( 'woocommerce_rest_customer_query', $customer_query_filter );
			}
		}
		if ( $response->is_error() ) {
			return $response;
		}
		$data = apply_filters( 'woocommerce_pos_sync_proxy_response', $response->get_data(), $resource, $request );
		if ( 'orders' === $resource ) {
			$serializer = new Order_Serializer();
			foreach ( (array) $data as $index => $payload ) {
				if ( ! is_array( $payload ) ) {
					continue;
				}
				// Same assembly as the pull lane, minus the pull-only
				// `woocommerce_pos_sync_serialized_order` filter — this lane already
				// ran `woocommerce_pos_sync_proxy_response` above.
				$data[ $index ] = $serializer->document( $payload, Order_Serializer::V2_AUGMENTATIONS );
			}
		}
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Authorize proxied coupon and tax reads for POS users.
	 *
	 * WooCommerce checks the `shop_coupon` post type for coupons and the `settings`
	 * object for taxes. This filter is attached only while those wc/v3 requests are
	 * in flight, so other permission checks remain unchanged.
	 *
	 * @param bool   $permission The current permission.
	 * @param string $context    The request context.
	 * @param int    $object_id  The object ID.
	 * @param string $post_type  The object type passed by WooCommerce.
	 *
	 * @return bool
	 */
	public function wcpos_check_permissions( $permission, $context, $object_id, $post_type ) {
		if ( ! $permission && \in_array( $post_type, array( 'settings', 'shop_coupon' ), true ) && 'read' === $context ) {
			$permission = current_user_can( 'access_woocommerce_pos' );
		}

		return $permission;
	}


	/**
	 * our namespace route => [ wc/v3 route to forward to, resource slug for
	 * the seam ] — a PROJECTION of the registry's proxy capability (#421
	 * increment 4): the route/forward/slug vocabulary now has one home
	 * (Collections), and adding a proxied collection is one registry row.
	 *
	 * Orders proxy /orders here for the browser-window / targeted / query-total
	 * reads (the client assembles each document's uuid identity from the served
	 * payload); the checkpointed greedy order lane keeps its own cursor endpoint
	 * /orders/pull in Orders_Controller.
	 */
	private static function resources(): array {
		$resources = array();
		foreach ( Collections::with( 'proxy' ) as $row ) {
			$resources[ $row['proxy']['route'] ] = array( $row['proxy']['wc_route'], $row['proxy']['slug'] );
		}

		return $resources;
	}

	/**
	 * Exclude the POS-hidden (`online_only`) product and variation ids from a `/products` forward via WC's REST query
	 * builder. No-op for other resources or when nothing is hidden, so the filter is only ever attached
	 * for the exact window it's needed.
	 */
	private function add_pos_visibility_filter( string $resource ): void {
		if ( 'products' !== $resource ) {
			return;
		}
		$visibility = new Pos_Visibility();
		// Nothing hidden → don't attach a filter that would be a no-op. Sync\Pos_Visibility owns the
		// rule itself: which ids are hidden, the feature gate, and the post__in-vs-post__not_in trap.
		if ( array() === $visibility->hidden_ids( Pos_Visibility::CATALOG ) ) {
			return;
		}
		$this->pos_visibility_filter = static function ( $args ) use ( $visibility ) {
			return $visibility->apply_to_wp_query_args( (array) $args, 'products' );
		};
		add_filter( 'woocommerce_rest_product_object_query', $this->pos_visibility_filter );
	}

	private function remove_pos_visibility_filter(): void {
		if ( null !== $this->pos_visibility_filter ) {
			remove_filter( 'woocommerce_rest_product_object_query', $this->pos_visibility_filter );
			$this->pos_visibility_filter = null;
		}
	}
}
