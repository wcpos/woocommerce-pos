<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WCPOS\WooCommercePOS\API\V1\Customers_Controller as V1_Customers_Controller;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
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
 * query params except where WCPOS adapts them (such as multi-term customer search),
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
	 * The `post__not_in` closure while a `/products` forward is in flight (null otherwise).
	 */
	private $pos_visibility_filter = null;
	private $pos_order_filter      = null;
	private $pos_order_filter_hook = null;

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
		$query_params           = $request->get_query_params();
		$customer_search_filter = null;
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

				// Reuse V1's search filter without importing its handling of other query parameters.
				$search_request = new WP_REST_Request();
				$search_request->set_query_params( array( 'search' => $search ) );
				$search_controller      = new V1_Customers_Controller();
				$customer_search_filter = static function ( array $prepared_args ) use ( $search_controller, $search_request ): array {
					return $search_controller->wcpos_customer_query( $prepared_args, $search_request );
				};
				add_filter( 'woocommerce_rest_customer_query', $customer_search_filter );
			}
		}

		$inner = new WP_REST_Request( WP_REST_Server::READABLE, $wc_route );
		$this->add_pos_order_filter( $resource, $query_params );
		$inner->set_query_params( $query_params );
		// Leg-3 (ADR 0014 WP-M5): scope the POS servable filter around THIS forward only — added, then
		// removed — so `online_only` products drop out of the served set for both greedy list pulls and
		// targeted `include=` pulls, and no other product query on the request is affected. The client
		// then never holds them, and Leg-3 prunes any that were toggled `online_only` after being pulled.
		$this->add_pos_visibility_filter( $resource );
		$relax_wc_permissions = \in_array( $resource, array( 'coupons', 'taxes' ), true );
		if ( $relax_wc_permissions ) {
			add_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10, 4 );
		}
		try {
			$response = rest_do_request( $inner );
		} finally {
			if ( $relax_wc_permissions ) {
				remove_filter( 'woocommerce_rest_check_permissions', array( $this, 'wcpos_check_permissions' ), 10 );
			}
			$this->remove_pos_visibility_filter();
			$this->remove_pos_order_filter();
			if ( null !== $customer_search_filter ) {
				remove_filter( 'woocommerce_rest_customer_query', $customer_search_filter );
			}
		}
		if ( $response->is_error() ) {
			return $response;
		}
		$data = apply_filters( 'woocommerce_pos_sync_proxy_response', $response->get_data(), $resource, $request );
		if ( 'orders' === $resource ) {
			foreach ( (array) $data as $index => $payload ) {
				$order          = wc_get_order( (int) ( $payload['id'] ?? 0 ) );
				$data[ $index ] = $order ? Order_Serializer::add_pos_links( $payload, $order ) : $payload;
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

	private function add_pos_order_filter( string $resource, array &$query_params ): void {
		if ( 'orders' !== $resource ) {
			return;
		}
		$filters = array();
		foreach ( array( 'pos_cashier', 'pos_store', 'created_via' ) as $key ) {
			if ( array_key_exists( $key, $query_params ) ) {
				$value = $query_params[ $key ];
				if ( 'created_via' === $key && \is_array( $value ) ) {
					$filters[ $key ] = \array_map( 'sanitize_key', \array_values( $value ) );
				} else {
					$value           = \is_scalar( $value ) ? $value : '';
					$filters[ $key ] = 'created_via' === $key ? sanitize_key( (string) $value ) : absint( $value );
				}
				unset( $query_params[ $key ] );
			}
		}
		if ( array() === $filters ) {
			return;
		}
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->pos_order_filter_hook = 'woocommerce_orders_table_query_clauses';
			$this->pos_order_filter      = static function ( array $clauses, $query ) use ( $filters ): array {
				global $wpdb;
				$orders     = $query->get_table_name( 'orders' );
				$meta       = $query->get_table_name( 'meta' );
				$operations = $query->get_table_name( 'operational_data' );
				$meta_keys  = array(
					'pos_cashier' => '_pos_user',
					'pos_store'   => '_pos_store',
				);
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names come from WooCommerce; placeholder lists are generated below.
				foreach ( $meta_keys as $key => $meta_key ) {
					if ( isset( $filters[ $key ] ) ) {
						$clauses['where'] .= $wpdb->prepare( " AND {$orders}.id IN (SELECT order_id FROM {$meta} WHERE meta_key=%s AND meta_value=%s)", $meta_key, (string) $filters[ $key ] );
					}
				}
				if ( isset( $filters['created_via'] ) ) {
					$created_via = (array) $filters['created_via'];
					if ( array() !== $created_via ) {
						$placeholders      = implode( ', ', array_fill( 0, \count( $created_via ), '%s' ) );
						$clauses['where'] .= $wpdb->prepare( " AND {$orders}.id IN (SELECT order_id FROM {$operations} WHERE created_via IN ({$placeholders}))", ...$created_via );
					}
				}
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

				return $clauses;
			};
			add_filter( $this->pos_order_filter_hook, $this->pos_order_filter, 10, 2 );
			return;
		}
		$this->pos_order_filter_hook = 'woocommerce_rest_shop_order_object_query';
		$this->pos_order_filter      = static function ( array $args ) use ( $filters ): array {
			$meta_keys = array(
				'pos_cashier' => '_pos_user',
				'pos_store'   => '_pos_store',
				'created_via' => '_created_via',
			);
			foreach ( $meta_keys as $key => $meta_key ) {
				if ( isset( $filters[ $key ] ) && array() !== $filters[ $key ] ) {
					$args['meta_query'][] = array(
						'key'   => $meta_key,
						'value' => $filters[ $key ],
					);
				}
			}

			return $args;
		};
		add_filter( $this->pos_order_filter_hook, $this->pos_order_filter );
	}

	private function remove_pos_order_filter(): void {
		if ( null !== $this->pos_order_filter ) {
			remove_filter( $this->pos_order_filter_hook, $this->pos_order_filter );
			$this->pos_order_filter      = null;
			$this->pos_order_filter_hook = null;
		}
	}
}
