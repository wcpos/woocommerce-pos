<?php


namespace WCPOS\WooCommercePOS\API;


use WP_REST_Request;

class Orders {
	private $request;

	/**
	 * Orders constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;

		if ( 'POST' == $request->get_method() ) {
			$this->incoming_shop_order();
		}

		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array(
			$this,
			'pre_insert_shop_order_object'
		), 10, 3 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'prepare_shop_order_object' ), 10, 3 );
		add_action( 'woocommerce_rest_set_order_item', array( $this, 'rest_set_order_item' ), 10, 2 );
	}

	/**
	 *
	 */
	public function incoming_shop_order() {
		$raw_data = $this->request->get_json_params();

		/**
		 * WC REST validation enforces email address for orders
		 * this hack allows guest orders to bypass this validation
		 */
		if ( isset( $raw_data['customer_id'] ) && 0 == $raw_data['customer_id'] ) {
			add_filter( 'is_email', function ( $result, $email ) {
				if ( ! $email ) {
					return true;
				}

				return $result;
			}, 10, 2 );
		}
	}

	public function test_email() {
		$break = '';

		return true;
	}

	/**
	 * @param $order
	 * @param $request
	 * @param $creating
	 */
	public function pre_insert_shop_order_object( $order, $request, $creating ) {
		return $order;
	}

	/**
	 * @param $response
	 * @param $order
	 * @param $request
	 *
	 * @return
	 */
	public function prepare_shop_order_object( $response, $order, $request ) {
		return $response;
	}

	/**
	 * @param $item
	 * @param $posted
	 */
	public function rest_set_order_item( $item, $posted ) {

	}
}
