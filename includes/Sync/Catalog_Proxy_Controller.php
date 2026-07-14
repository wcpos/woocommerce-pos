<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WP_REST_Request;
use WP_REST_Controller;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Catalog proxy endpoints — route replication READS through our `{API_NAMESPACE}{ROUTE_PREFIX}`
 * namespace instead of hitting raw `wc/v3` directly (guardrail G4: replication is
 * wrapped in our namespace so we can customize the request/response and
 * duck-punch for replication WITHOUT editing wc/v3 or the client).
 *
 * Each route forwards verbatim to its wc/v3 counterpart via `rest_do_request`,
 * preserving every query param plus the underlying status + pagination headers
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

	public function register_routes(): void {
		foreach ( self::resources() as $route => $meta ) {
			$wc_route = $meta[0];
			$resource = $meta[1];
			register_rest_route(
				Api::ROUTE_NAMESPACE,
				'/' . Api::ROUTE_PREFIX . ltrim( $route, '/' ),
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
	 * Forward the request to $wc_route via rest_do_request (preserving query
	 * params + the wc/v3 status/headers), then expose the batch through the
	 * replication seam filter. wc/v3 errors are forwarded unchanged so the
	 * client sees the same failure it would have seen hitting wc/v3 directly.
	 */
	public function proxy( WP_REST_Request $request, string $wc_route, string $resource ) {
		$inner = new WP_REST_Request( WP_REST_Server::READABLE, $wc_route );
		$inner->set_query_params( $request->get_query_params() );
		// Leg-3 (ADR 0014 WP-M5): scope the POS servable filter around THIS forward only — added, then
		// removed — so `online_only` products drop out of the served set for both greedy list pulls and
		// targeted `include=` pulls, and no other product query on the request is affected. The client
		// then never holds them, and Leg-3 prunes any that were toggled `online_only` after being pulled.
		$this->add_pos_visibility_filter( $resource );
		$response = rest_do_request( $inner );
		$this->remove_pos_visibility_filter();
		if ( $response->is_error() ) {
			return $response;
		}
		$data = apply_filters( 'woocommerce_pos_sync_proxy_response', $response->get_data(), $resource, $request );
		$response->set_data( $data );

		return $response;
	}


	/**
	 * our namespace route => [ wc/v3 route to forward to, resource slug for
	 * the seam ] — a PROJECTION of the registry's proxy capability (#421
	 * increment 4): the route/forward/slug vocabulary now has one home
	 * (Collections), and adding a proxied collection is one
	 * registry row. (The orders custom-pull lane keeps its own cursor
	 * endpoint, /orders/pull, in class-rest-controller.php.).
	 */
	private static function resources(): array {
		$resources = array();
		foreach ( Collections::with( 'proxy' ) as $collection => $row ) {
			// Orders use the custom-pull lane introduced in increment 2c.
			if ( 'orders' === $collection ) {
				continue;
			}
			$resources[ $row['proxy']['route'] ] = array( $row['proxy']['wc_route'], $row['proxy']['slug'] );
		}

		return $resources;
	}

	/**
	 * Exclude the POS-hidden (`online_only`) product ids from a `/products` forward via WC's REST query
	 * builder. No-op for other resources or when nothing is hidden, so the filter is only ever attached
	 * for the exact window it's needed.
	 */
	private function add_pos_visibility_filter( string $resource ): void {
		if ( 'products' !== $resource ) {
			return;
		}
		$exclude = ( new Pos_Visibility() )->online_only_product_ids();
		if ( array() === $exclude ) {
			return;
		}
		$this->pos_visibility_filter = static function ( $args ) use ( $exclude ) {
			$existing             = isset( $args['post__not_in'] ) && \is_array( $args['post__not_in'] ) ? $args['post__not_in'] : array();
			$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $exclude ) ) );

			return $args;
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
