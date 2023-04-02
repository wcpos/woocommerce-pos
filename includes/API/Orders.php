<?php

namespace WCPOS\WooCommercePOS\API;

use Exception;
use Ramsey\Uuid\Uuid;
use WC_Data_Exception;
use WC_Order;
use WC_Order_Item;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Response;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;

class Orders {
	private $request;

	private $posted;

	/**
	 * Orders constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;
		$this->posted  = $this->request->get_json_params();

		if ( 'POST' == $request->get_method() ) {
			$this->incoming_shop_order();
		}

		add_filter('woocommerce_rest_pre_insert_shop_order_object', array(
			$this,
			'pre_insert_shop_order_object',
		), 10, 3);
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'order_response' ), 10, 3 );
		add_filter( 'woocommerce_order_get_items', array( $this, 'order_get_items' ), 10, 3 );

		add_action( 'woocommerce_rest_set_order_item', array( $this, 'rest_set_order_item' ), 10, 2 );
		add_filter('woocommerce_product_variation_get_attributes', array(
			$this,
			'product_variation_get_attributes',
		), 10, 2);
		add_action( 'woocommerce_before_order_object_save', array( $this, 'before_order_object_save' ), 10, 2 );
	}


	public function incoming_shop_order(): void {
		$raw_data = $this->request->get_json_params();

		/*
		 * WC REST validation enforces email address for orders
		 * this hack allows guest orders to bypass this validation
		 */
		if ( isset( $raw_data['customer_id'] ) && 0 == $raw_data['customer_id'] ) {
			add_filter('is_email', function ( $result, $email ) {
				if ( ! $email ) {
					return true;
				}

				return $result;
			}, 10, 2);
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
		$break = '';

		return $order;
	}

	/**
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Order         $order    Object data.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function order_response( WP_REST_Response $response, WC_Order $order, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		/**
		 * make sure the order has a uuid
		 */
		$uuid = $order->get_meta( '_woocommerce_pos_uuid' );
		if ( ! $uuid ) {
			$uuid = Uuid::uuid4()->toString();
			$order->update_meta_data( '_woocommerce_pos_uuid', $uuid );
			$order->save_meta_data();
			$data['meta_data'] = $order->get_meta_data();
		}

		/**
		 * reset the new response data
		 */
		$response->set_data( $data );

		/**
		 * Add link for order payment.
		 */
		$pos_payment_url = add_query_arg(array(
			'pay_for_order' => true,
			'key'           => $order->get_order_key(),
		), get_home_url( null, '/wcpos-checkout/order-pay/' . $order->get_id() ));

		$response->add_link( 'payment', $pos_payment_url, array( 'foo' => 'bar' ) );

		/**
		 * Add link for order receipt.
		 */
		$pos_receipt_url = get_home_url( null, '/wcpos-checkout/wcpos-receipt/' . $order->get_id() );
		$response->add_link( 'receipt', $pos_receipt_url );

		return $response;
	}

	/**
	 * @param $items WC_Order_Item[]
	 * @param $order WC_Order
	 * @param $item_type string[] ['line_item' | 'fee' | 'shipping' | 'tax' | 'coupon']
	 * @return WC_Order_Item[]
	 */
	public function order_get_items( array $items, WC_Order $order, array $item_type ): array {
		foreach ( $items as $item ) {
			/**
			 * make sure the cart items have a uuid
			 */
			$uuid = $item->get_meta( '_woocommerce_pos_uuid' );
			if ( ! $uuid ) {
				$uuid = Uuid::uuid4()->toString();
				$item->update_meta_data( '_woocommerce_pos_uuid', $uuid );
				$item->save_meta_data();
			}
		}

		return $items;
	}

	/**
	 * @param $item
	 * @param $posted
	 *
	 * @throws Exception
	 */
	public function rest_set_order_item( $item, $posted ): void {
		/*
		 * fixes two problems wth WC REST API
		 * - variation meta_data with 'any' are not being saved
		 * - default variation meta_data is always added (not unique)
		 */
		if ( isset( $posted['variation_id'] ) && 0 !== $posted['variation_id'] ) {
			$variation  = wc_get_product( (int) $posted['variation_id'] );
			$valid_keys = array();

			if ( \is_callable( array( $variation, 'get_variation_attributes' ) ) ) {
				foreach ( $variation->get_variation_attributes() as $attribute_name => $attribute ) {
					$valid_keys[] = str_replace( 'attribute_', '', $attribute_name );
				}

				if ( isset( $posted['meta_data'] ) && \is_array( $posted['meta_data'] ) ) {
					foreach ( $posted['meta_data'] as $meta ) {
						// fix initial item creation
						if ( isset( $meta['attr_id'] ) ) {
							if ( 0 == $meta['attr_id'] ) {
								// not a taxonomy
								if ( \in_array( strtolower( $meta['display_key'] ), $valid_keys, true ) ) {
									$item->add_meta_data( strtolower( $meta['display_key'] ), $meta['display_value'], true );
								}
							} else {
								$taxonomy = wc_attribute_taxonomy_name_by_id( $meta['attr_id'] );

								$terms = get_the_terms( (int) $posted['product_id'], $taxonomy );
								if ( ! empty( $terms ) ) {
									foreach ( $terms as $term ) {
										if ( $term->name === $meta['display_value'] ) {
											$item->add_meta_data( $taxonomy, $term->slug, true );
										}
									}
								}
							}
						}
						// fix subsequent overwrites
						if ( wc_attribute_taxonomy_id_by_name( $meta['key'] ) || \in_array( $meta['key'], $valid_keys, true ) ) {
							$item->add_meta_data( $meta['key'], $meta['value'], true );
						}
					}
				}
			}
		}
	}

	/**
	 * @param $value
	 * @param WC_Product_Variation $variation
	 *
	 * @return void
	 */
	public function product_variation_get_attributes( $value, WC_Product_Variation $variation ) {
		/*
		 * - could fix 'any' options here using raw posted data
		 * - may be useful for product title generation
		 */

		return $value;
	}

	/**
	 * Add custom 'created_via' prop for POS orders, used in WC Admin display
	 *
	 * @param WC_Order $order The object being saved.
	 * @throws WC_Data_Exception
	 */
	public function before_order_object_save( WC_Order $order ) {
		if ( $order->get_id() === 0 ) {
			$order->set_created_via( PLUGIN_NAME );
		}
	}

	/**
	 * Returns array of all order ids.
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
    		AND post_status != "trash"
		' );

		// wpdb returns id as string, we need int
		return array_map( array( $this, 'format_id' ), $all_posts );
	}

	/**
	 * @param object $record
	 *
	 * @return object
	 */
	private function format_id( $record ) {
		$record->id = (int) $record->id;

		return $record;
	}
}
