<?php

namespace WCPOS\WooCommercePOS\API;

use Exception;
use Ramsey\Uuid\Uuid;
use WC_Data_Exception;
use WC_Order;
use WC_Order_Item;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use function in_array;
use function is_array;
use function is_callable;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use WC_Order_Query;
use WP_Query;

class Orders extends Abstracts\WC_Rest_API_Modifier {
    use Traits\Uuid_Handler;

	private $posted;

	/**
	 * Orders constructor.
	 *
	 * @param $request WP_REST_Request
	 */
	public function __construct( WP_REST_Request $request ) {
		$this->request = $request;
		$this->posted  = $this->request->get_json_params();
        $this->uuids = $this->get_all_postmeta_uuids();

		if ( 'POST' == $request->get_method() ) {
			$this->incoming_shop_order();
		}

		add_filter( 'rest_request_before_callbacks', array( $this, 'rest_request_before_callbacks' ), 10, 3 );
		add_filter( 'woocommerce_rest_shop_order_object_query', array( $this, 'order_query' ), 10, 2 );
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
		add_filter( 'posts_clauses', array( $this, 'orderby_additions' ), 10, 2 );
		add_filter( 'option_woocommerce_tax_based_on', array( $this, 'tax_based_on' ), 10, 2 );
	}

	/**
	 * Filters the response before executing any REST API callbacks.
	 *
	 * We can use this filter to bypass data validation checks
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result to send to the client.
	 *                                                                   Usually a WP_REST_Response or WP_Error.
	 * @param array                                            $handler  Route handler used for the request.
	 * @param WP_REST_Request                                  $request  Request used to generate the response.
	 */
	public function rest_request_before_callbacks( $response, $handler, $request ) {
		if ( is_wp_error( $response ) ) {
			// Check if the error code 'rest_invalid_param' exists
			if ( $response->get_error_message( 'rest_invalid_param' ) ) {
				// Get the error data for 'rest_invalid_param'
				$error_data = $response->get_error_data( 'rest_invalid_param' );

				// Check if the invalid parameter was 'line_items'
				if ( array_key_exists( 'line_items', $error_data['params'] ) ) {
					// Get the 'line_items' details
					$line_items_details = $error_data['details']['line_items'];

					// Check if 'line_items[X][quantity]' has 'rest_invalid_type'
					// Use a regular expression to match 'line_items[X][quantity]', where X is a number
					if ( $line_items_details['code'] === 'rest_invalid_type' &&
						preg_match( '/^line_items\[\d+\]\[quantity\]$/', $line_items_details['data']['param'] ) ) {
						if ( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) ) {
							unset( $error_data['params']['line_items'], $error_data['details']['line_items'] );
						}
					}

                    // Check if 'line_items[X][parent_name]' has 'rest_invalid_type'
                    // Use a regular expression to match 'line_items[X][parent_name]', where X is a number
                    if ( $line_items_details['code'] === 'rest_invalid_type' &&
                        preg_match( '/^line_items\[\d+\]\[parent_name\]$/', $line_items_details['data']['param'] ) ) {
                            unset( $error_data['params']['line_items'], $error_data['details']['line_items'] );
                    }
				}

				// Check if the invalid parameter was 'billing'
				if ( array_key_exists( 'billing', $error_data['params'] ) ) {
					// Get the 'billing' details
					$billing_details = $error_data['details']['billing'];

					// Check if 'billing' has 'rest_invalid_email'
					if ( $billing_details['code'] === 'rest_invalid_email' ) {
						unset( $error_data['params']['billing'], $error_data['details']['billing'] );
					}
				}

				// Check if the invalid parameter was 'orderby'
				if ( array_key_exists( 'orderby', $error_data['params'] ) ) {
					// Get the 'orderby' details
					$orderby_details = $error_data['details']['orderby'];

					// Get the 'orderby' request
					$orderby_request = $request->get_param( 'orderby' );

					// Extended 'orderby' values
					$orderby_extended = array(
						'status',
						'customer_id',
						'payment_method',
						'total',
					);

					// Check if 'orderby' has 'rest_not_in_enum', but is in the extended 'orderby' values
					if ( $orderby_details['code'] === 'rest_not_in_enum' && in_array( $orderby_request, $orderby_extended, true ) ) {
						unset( $error_data['params']['orderby'], $error_data['details']['orderby'] );
					}
				}

				// Check if $error_data['params'] is empty
				if ( empty( $error_data['params'] ) ) {
					return null;
				} else {
					// Remove old error data and add new error data
					$error_message = 'Invalid parameter(s): ' . implode( ', ', array_keys( $error_data['params'] ) ) . '.';

					$response->remove( 'rest_invalid_param' );
					$response->add( 'rest_invalid_param', $error_message, $error_data );
				}
			}
		}

		return $response;
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

	/**
	 * Filters the value of the woocommerce_tax_based_on option.
	 *
	 * @param mixed  $value  Value of the option.
	 * @param string $option Option name.
	 */
	public function tax_based_on( $value, $option ) {
		$tax_based_on = 'base'; // default value is base

		// try to get POS tax settings from order meta
		$raw_data = $this->request->get_json_params();
		if ( isset( $raw_data['meta_data'] ) ) {
			foreach ( $raw_data['meta_data'] as $meta ) {
				if ( '_woocommerce_pos_tax_based_on' == $meta['key'] ) {
					$tax_based_on = $meta['value'];
				}
			}
		}

		return $tax_based_on;
	}

	public function test_email() {
		$break = '';

		return true;
	}

	/**
	 * Filter the query arguments for a request.
	 *
	 * @param array           $args    Key value array of query var to query value.
	 * @param WP_REST_Request $request The request used.
	 *
	 * @return array $args Key value array of query var to query value.
	 */
	public function order_query( $args, WP_REST_Request $request ) {

		return $args;
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

        /**
         * Make sure we parse the meta data before returning the response
         */
        $order->save_meta_data(); // make sure the meta data is saved
        $data['meta_data'] = $this->parse_meta_data( $order );

        $response->set_data( $data );
        // $this->log_large_rest_response( $response, $order->get_id() );

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
			$this->maybe_add_order_item_uuid( $item );
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

			if ( is_callable( array( $variation, 'get_variation_attributes' ) ) ) {
				foreach ( $variation->get_variation_attributes() as $attribute_name => $attribute ) {
					$valid_keys[] = str_replace( 'attribute_', '', $attribute_name );
				}

				if ( isset( $posted['meta_data'] ) && is_array( $posted['meta_data'] ) ) {
					foreach ( $posted['meta_data'] as $meta ) {
						// fix initial item creation
						if ( isset( $meta['attr_id'] ) ) {
							if ( 0 == $meta['attr_id'] ) {
								// not a taxonomy
								if ( in_array( strtolower( $meta['display_key'] ), $valid_keys, true ) ) {
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
						if ( wc_attribute_taxonomy_id_by_name( $meta['key'] ) || in_array( $meta['key'], $valid_keys, true ) ) {
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

		/**
		 * Add cashier user id to order meta
		 * Note: There should only be one cashier per order, currently this will overwrite previous cashier id
		 */
		$user_id = get_current_user_id();
		$cashier_id = $order->get_meta( '_pos_user' );

		if ( ! $cashier_id ) {
			$order->update_meta_data( '_pos_user', $user_id );
		}
	}

	/**
	 * Filters all query clauses at once, for convenience.
	 *
	 * Covers the WHERE, GROUP BY, JOIN, ORDER BY, DISTINCT,
	 * fields (SELECT), and LIMIT clauses.
	 *
	 * @param string[] $clauses {
	 *     Associative array of the clauses for the query.
	 *
	 *     @type string $where    The WHERE clause of the query.
	 *     @type string $groupby  The GROUP BY clause of the query.
	 *     @type string $join     The JOIN clause of the query.
	 *     @type string $orderby  The ORDER BY clause of the query.
	 *     @type string $distinct The DISTINCT clause of the query.
	 *     @type string $fields   The SELECT clause of the query.
	 *     @type string $limits   The LIMIT clause of the query.
	 * }
	 * @param WP_Query $wp_query   The WP_Query instance (passed by reference).
	 */
	public function orderby_additions( array $clauses, WP_Query $wp_query ): array {
		global $wpdb;

		$order = isset( $wp_query->query_vars['order'] ) ? $wp_query->query_vars['order'] : 'DESC';

		if ( isset( $wp_query->query_vars['orderby'] ) ) {

			// add option to order by status
			if ( 'status' === $wp_query->query_vars['orderby'] ) {
				$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}posts AS order_posts ON {$wpdb->prefix}posts.ID = order_posts.ID ";
				$clauses['orderby'] = ' order_posts.post_status ' . $order;
			}

			// add option to order by customer_id
			if ( 'customer_id' === $wp_query->query_vars['orderby'] ) {
				$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}postmeta AS customer_meta ON {$wpdb->prefix}posts.ID = customer_meta.post_id ";
				$clauses['where'] .= " AND customer_meta.meta_key = '_customer_user' ";
				$clauses['orderby'] = ' customer_meta.meta_value ' . $order;
			}

			// add option to order by payment_method
			if ( 'payment_method' === $wp_query->query_vars['orderby'] ) {
				$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}postmeta AS payment_method_meta ON {$wpdb->prefix}posts.ID = payment_method_meta.post_id ";
				$clauses['where'] .= " AND payment_method_meta.meta_key = '_payment_method' ";
				$clauses['orderby'] = ' payment_method_meta.meta_value ' . $order;
			}

			// add option to order by total
			if ( 'total' === $wp_query->query_vars['orderby'] ) {
				$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}postmeta AS total_meta ON {$wpdb->prefix}posts.ID = total_meta.post_id ";
				$clauses['where'] .= " AND total_meta.meta_key = '_order_total' ";
				$clauses['orderby'] = ' total_meta.meta_value+0 ' . $order;
			}
		}

		return $clauses;
	}

	/**
	 * Returns array of all order ids.
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function get_all_posts( array $fields = array() ): array {
		$args = array(
			'limit' => -1,
			'return' => 'ids',
			'status' => array_keys( wc_get_order_statuses() ), // Get valid order statuses
		);

		$order_query = new WC_Order_Query( $args );

		try {
			$order_ids = $order_query->get_orders();
			return array_map( array( $this, 'format_id' ), $order_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching order IDs: ' . $e->getMessage() );
			return new WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching order IDs.',
				array( 'status' => 500 )
			);
		}
	}
}
