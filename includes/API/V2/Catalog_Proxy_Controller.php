<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\API\V2\Proxy\Null_Proxy_Behavior;
use WCPOS\WooCommercePOS\API\V2\Proxy\Proxy_Behavior;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
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

	/** Register every proxied collection route from the collection registry. */
	public function register_routes(): void {
		foreach ( self::resources() as $route => $meta ) {
			$wc_route       = $meta[0];
			$resource       = $meta[1];
			$behavior_class = $meta[2];
			register_rest_route(
				Api::ROUTE_NAMESPACE,
				'/' . ltrim( $route, '/' ),
				array(
					'methods' => WP_REST_Server::READABLE,
					// Closure carries the target so the handler never has to re-derive
					// it from the matched route — one handler, six wired routes.
					'callback' => function ( WP_REST_Request $request ) use ( $wc_route, $resource, $behavior_class ) {
						return $this->proxy( $request, $wc_route, $resource, new $behavior_class() );
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
	public function proxy( WP_REST_Request $request, string $wc_route, string $resource, ?Proxy_Behavior $behavior = null ) {
		$behavior     = $behavior ?? self::behavior_for( $resource );
		$query_params = $behavior->forwarded_params( $request->get_query_params(), $request );
		$inner = new WP_REST_Request( WP_REST_Server::READABLE, $wc_route );
		unset( $query_params[ Store_Scope::PARAM ] );
		$inner->set_query_params( $query_params );
		// The till's store scope, republished as v1's `store_id` param (pro#425).
		// This is the greedy catalog list pull, so it is what decides whether the
		// products grid shows each store's own price or the web store's. Stamped
		// after set_query_params so a scope is never wiped by the forwarded set.
		Store_Scope::stamp( $inner );
		$forward = static function () use ( $inner ) {
			return Store_Scope::in_v2_lane( static fn() => rest_do_request( $inner ) );
		};
		$response = $behavior->around( $forward );
		if ( $response->is_error() ) {
			return $response;
		}
		$data = apply_filters( 'woocommerce_pos_sync_proxy_response', $response->get_data(), $resource, $request );
		$response->set_data( $behavior->post_process( $data ) );

		return $response;
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
			$resources[ $row['proxy']['route'] ] = array(
				$row['proxy']['wc_route'],
				$row['proxy']['slug'],
				$row['proxy']['behavior'],
			);
		}

		return $resources;
	}

	/**
	 * Resolve behavior for direct proxy() callers that did not come through route registration.
	 *
	 * @param string $resource Proxy resource slug.
	 *
	 * @return Proxy_Behavior
	 */
	private static function behavior_for( string $resource ): Proxy_Behavior {
		$row   = Collections::by_proxy_slug( $resource );
		$class = $row['proxy']['behavior'] ?? Null_Proxy_Behavior::class;

		return new $class();
	}
}
