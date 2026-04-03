<?php
/**
 * Shared helpers for WC REST API order isolation tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\Traits
 */

namespace WCPOS\WooCommercePOS\Tests\API\Traits;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Order;
use WP_REST_Request;

/**
 * Trait WC_REST_Order_Helpers
 *
 * Provides helper methods for constructing plain WC REST API requests
 * and creating orders with specific created_via values.
 */
trait WC_REST_Order_Helpers {
	/**
	 * Create a plain wc/v3 GET request (no X-WCPOS header).
	 *
	 * @param string $path REST route path.
	 *
	 * @return WP_REST_Request
	 */
	private function wc_rest_get_request( string $path ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_method( 'GET' );
		$request->set_route( $path );

		return $request;
	}

	/**
	 * Create an order and set its created_via value.
	 *
	 * @param string $created_via The created_via value to set.
	 * @param array  $args        Optional order arguments.
	 *
	 * @return WC_Order
	 */
	private function create_order_with_created_via( string $created_via, array $args = array() ): WC_Order {
		$order = OrderHelper::create_order( $args );

		if ( method_exists( $order, 'set_created_via' ) ) {
			$order->set_created_via( $created_via );
			$order->save();
		}

		return $order;
	}
}
