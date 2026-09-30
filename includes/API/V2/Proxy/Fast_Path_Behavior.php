<?php
/**
 * Optional fast-path contract for a catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Lets a behavior answer one narrow request shape without the wc/v3 forward.
 *
 * A separate interface rather than a new {@see Proxy_Behavior} method, so
 * existing Proxy_Behavior implementations outside this plugin keep working.
 */
interface Fast_Path_Behavior {
	/**
	 * Answer the request directly when it is this behavior's fast-path shape.
	 *
	 * The response is returned as-is: no wc/v3 forward, no
	 * `woocommerce_pos_sync_proxy_response` filter, no post-processing.
	 *
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return null|WP_REST_Response Null when the request is not the fast-path shape.
	 */
	public function fast_response( WP_REST_Request $request ): ?WP_REST_Response;
}
