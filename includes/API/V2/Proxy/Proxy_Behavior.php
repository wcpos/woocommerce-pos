<?php
/**
 * Per-resource catalog proxy contract.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WP_REST_Request;

/**
 * Vary only the three resource-specific phases of a catalog proxy read.
 */
interface Proxy_Behavior {
	/**
	 * Adjust query parameters before forwarding them to wc/v3.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array;

	/**
	 * Install resource hooks around one forward and unwind them afterward.
	 *
	 * @param callable $forward Forward operation.
	 *
	 * @return mixed
	 */
	public function around( callable $forward );

	/**
	 * Apply resource-specific response shaping.
	 *
	 * @param mixed $data Response data.
	 *
	 * @return mixed
	 */
	public function post_process( $data );
}
