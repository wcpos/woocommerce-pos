<?php
/**
 * Scoped catalog proxy behavior base.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WP_REST_Request;

/**
 * Gives every resource the same install/run/finally/unwind discipline.
 */
abstract class Scoped_Proxy_Behavior implements Proxy_Behavior {
	/**
	 * Forward every param untouched.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		return $params;
	}

	/**
	 * Install this resource's hooks, run the forward, and always unwind.
	 *
	 * @param callable $forward Forward operation.
	 *
	 * @return mixed
	 */
	public function around( callable $forward ) {
		$bindings = $this->install();

		try {
			return $this->run( $forward );
		} finally {
			foreach ( array_reverse( $bindings ) as $binding ) {
				remove_filter( $binding[0], $binding[1], $binding[2] );
			}
		}
	}

	/**
	 * Return the response data unshaped.
	 *
	 * @param mixed $data Response data.
	 *
	 * @return mixed
	 */
	public function post_process( $data ) {
		return $data;
	}

	/**
	 * Install hooks and return their exact removal tuples.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int}>
	 */
	protected function install(): array {
		return array();
	}

	/**
	 * Run the forward, optionally through a resource plan.
	 *
	 * @param callable $forward Forward operation.
	 *
	 * @return mixed
	 */
	protected function run( callable $forward ) {
		return $forward();
	}
}
