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
			'pre_insert_shop_order_object',
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
		if ( $order->has_status( 'pos-open' ) ) {
			$pos_payment_url = add_query_arg( array(
				'pay_for_order' => true,
				'key'           => $order->get_order_key(),
			), get_home_url( null, '/wcpos-checkout/order-pay/' . $order->get_id() ) );

			$response->add_link( 'payment', $pos_payment_url, array( 'foo' => 'bar' ) );
		}

		return $response;
	}

	/**
	 * @param $item
	 * @param $posted
	 */
	public function rest_set_order_item( $item, $posted ) {

	}

	/**
	 * Returns array of all order ids
	 *
	 * @param array $fields
	 *
	 * @return array|void
	 */
	public function get_all_posts( array $fields = array() ) {
		global $wpdb;

		$all_posts = $wpdb->get_results( '
			SELECT ID as id FROM ' . $wpdb->posts . '
			WHERE post_type = "shop_order"
		' );

		// wpdb returns id as string, we need int
		return array_map( array( $this, 'format_id' ), $all_posts );
	}

	/**
	 *
	 *
	 * @param object $record
	 *
	 * @return object
	 */
	private function format_id( $record ) {
		$record->id = (int) $record->id;

		return $record;
	}
}
