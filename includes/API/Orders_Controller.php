<?php

namespace WCPOS\WooCommercePOS\API;

\defined('ABSPATH') || die;

if ( ! class_exists('WC_REST_Orders_Controller') ) {
	return;
}

use Exception;
use WC_Order_Query;
use WC_REST_Orders_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Request;

/**
 * Orders controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Products_Controller methods
 */
class Orders_Controller extends WC_REST_Orders_Controller {
	use Traits\Uuid_Handler;
	use Traits\WCPOS_REST_API;

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_pos_rest_dispatch_orders_request', array( $this, 'wcpos_dispatch_request' ), 10, 4 );

		// no constructor for WC_REST_Orders_Controller
		// parent::__construct();
	}

	/**
	 * Modify the collection params.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();
		
		// Modify the per_page argument to allow -1
		$params['per_page']['minimum'] = -1;
		
		return $params;
	}

	/**
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ): mixed {
		$this->wcpos_register_wc_rest_api_hooks();
		$params = $request->get_params();

		// Optimised query for getting all product IDs
		if ( isset( $params['posts_per_page'] ) && -1 == $params['posts_per_page'] && isset( $params['fields'] ) ) {
			$dispatch_result = $this->wcpos_get_all_posts( $params['fields'] );
		}

		return $dispatch_result;
	}

	/**
	 * Register hooks to modify WC REST API response.
	 */
	public function wcpos_register_wc_rest_api_hooks(): void {
	}

	/**
	 * Returns array of all order ids.
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function wcpos_get_all_posts(array $fields = array() ): array {
		$args = array(
			'limit'  => -1,
			'return' => 'ids',
			'status' => array_keys( wc_get_order_statuses() ), // Get valid order statuses
		);

		$order_query = new WC_Order_Query( $args );

		try {
			$order_ids = $order_query->get_orders();

			return array_map( array( $this, 'wcpos_format_id' ), $order_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching order IDs: ' . $e->getMessage() );

			return new \WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching order IDs.',
				array( 'status' => 500 )
			);
		}
	}
}
