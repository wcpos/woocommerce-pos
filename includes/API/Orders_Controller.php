<?php

namespace WCPOS\WooCommercePOS\API;

\defined('ABSPATH') || die;

if ( ! class_exists('WC_REST_Orders_Controller') ) {
	return;
}

use Exception;
use WC_Order;
use WC_Order_Query;
use WC_REST_Orders_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Request;
use WP_REST_Response;

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

		if ( method_exists( parent::class, '__construct' ) ) {
			parent::__construct();
		}
	}

	/**
	 * Modify the collection params.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();
		
		// Modify the per_page argument to allow -1
		$params['per_page']['minimum'] = -1;
		$params['orderby']['enum']     = array_merge(
			$params['orderby']['enum'],
			array( 'status', 'customer_id', 'payment_method', 'total' )
		);

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
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'wcpos_order_response' ), 10, 3 );
	}

	/**
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Order         $order    Object data.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function wcpos_order_response( WP_REST_Response $response, WC_Order $order, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		// Add UUID to order
		$this->maybe_add_post_uuid( $order );

		// Add payment link to the order.
		$pos_payment_url = add_query_arg(array(
			'pay_for_order' => true,
			'key'           => $order->get_order_key(),
		), get_home_url( null, '/wcpos-checkout/order-pay/' . $order->get_id() ));

		$response->add_link( 'payment', $pos_payment_url, array( 'foo' => 'bar' ) );

		// Add receipt link to the order.
		$pos_receipt_url = get_home_url( null, '/wcpos-checkout/wcpos-receipt/' . $order->get_id() );
		$response->add_link( 'receipt', $pos_receipt_url );

		// Make sure we parse the meta data before returning the response
		$order->save_meta_data(); // make sure the meta data is saved
		$data['meta_data'] = $this->wcpos_parse_meta_data( $order );

		$response->set_data( $data );
		// $this->log_large_rest_response( $response, $order->get_id() );

		return $response;
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

	/**
	 * Prepare objects query.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	protected function prepare_objects_query( $request ) {
		$args = parent::prepare_objects_query( $request );

		// Add custom 'orderby' options
		if ( isset( $request['orderby'] ) ) {
			switch ( $request['orderby'] ) {
				case 'status':
					$args['orderby'] = 'post_status';

					break;
				case 'customer_id':
					$args['meta_key'] = '_customer_user';
					$args['orderby']  = 'meta_value_num';

					break;
				case 'payment_method':
					$args['meta_key'] = '_payment_method_title';
					$args['orderby']  = 'meta_value';

					break;
				case 'total':
					$args['meta_key'] = '_order_total';
					$args['orderby']  = 'meta_value';

					break;
			}
		}

		return $args;
	}
}
