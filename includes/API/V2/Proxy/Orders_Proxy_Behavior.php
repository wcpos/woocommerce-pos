<?php
/**
 * Order catalog proxy behavior.
 *
 * @package WCPOS\WooCommercePOS\API\V2\Proxy
 */

namespace WCPOS\WooCommercePOS\API\V2\Proxy;

use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WP_REST_Request;

/**
 * Applies the order Collection Rules plan and v2 order wire shape.
 */
final class Orders_Proxy_Behavior extends Scoped_Proxy_Behavior {
	/**
	 * Proxy request keys claimed by the order Collection Rules plan.
	 */
	private const PARAM_MAP = array(
		'orderby'     => 'orderby',
		'order'       => 'order',
		'pos_cashier' => 'pos_cashier',
		'pos_store'   => 'pos_store',
		'created_via' => 'created_via',
		'include'     => array(
			'key' => 'include',
			'when' => 'search',
			'parse' => 'id_list',
		),
		'exclude'     => array(
			'key' => 'exclude',
			'when' => 'search',
			'parse' => 'id_list',
		),
	);

	/**
	 * The Collection Rules plan for the request in flight.
	 *
	 * @var null|\WCPOS\WooCommercePOS\Sync\Collection_Rules_Plan
	 */
	private $plan;

	/**
	 * Claim the orders params the rules table owns, then ask wc/v3 for full precision.
	 *
	 * @param array           $params  Query parameters to forward.
	 * @param WP_REST_Request $request Original proxy request.
	 *
	 * @return array
	 */
	public function forwarded_params( array $params, WP_REST_Request $request ): array {
		$this->plan = Collection_Rules::for_request( 'orders', $request, self::PARAM_MAP );
		$params     = $this->plan->forwarded_params( $params );
		$params['dp'] = '6';

		return $params;
	}

	/**
	 * Run the forward inside the Collection Rules plan for this request.
	 *
	 * @param callable $forward Forward operation.
	 *
	 * @return mixed
	 */
	protected function run( callable $forward ) {
		return null === $this->plan ? $forward() : $this->plan->around( $forward );
	}

	/**
	 * Apply the shared v2 order augmentations to every row.
	 *
	 * @param mixed $data Response data.
	 *
	 * @return mixed
	 */
	public function post_process( $data ) {
		$serializer = new Order_Serializer();
		foreach ( (array) $data as $index => $payload ) {
			if ( is_array( $payload ) ) {
				$data[ $index ] = $serializer->document( $payload, Order_Serializer::V2_AUGMENTATIONS );
			}
		}

		return $data;
	}
}
