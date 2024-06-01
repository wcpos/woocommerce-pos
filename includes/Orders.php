<?php
/**
 * WCPOS Orders Class
 * Extends WooCommerce Orders.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WC_Abstract_Order;
use WC_Product;
use WC_Product_Simple;
use WC_Order;

/**
 * Orders Class
 * - runs for all life cycles
 */
class Orders {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->register_order_status();
		add_filter( 'wc_order_statuses', array( $this, 'wc_order_statuses' ), 10, 1 );
		add_filter( 'woocommerce_order_needs_payment', array( $this, 'order_needs_payment' ), 10, 3 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'valid_order_statuses_for_payment' ), 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( $this, 'valid_order_statuses_for_payment_complete' ), 10, 2 );
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'payment_complete_order_status' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_order_itemmeta' ) );
		add_filter( 'woocommerce_order_item_product', array( $this, 'order_item_product' ), 10, 2 );
		add_filter( 'woocommerce_order_get_tax_location', array( $this, 'get_tax_location' ), 10, 2 );
		add_action( 'woocommerce_order_item_after_calculate_taxes', array( $this, 'order_item_after_calculate_taxes' ) );
		add_action( 'woocommerce_order_item_shipping_after_calculate_taxes', array( $this, 'order_item_after_calculate_taxes' ) );

		// order emails
		$admin_emails = array(
			'new_order',
			'cancelled_order',
			'failed_order',
			'reset_password',
			'new_account',
		);
		$customer_emails = array(
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
			'customer_invoice',
			'customer_note',
		);
		foreach ( $admin_emails as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", array( $this, 'manage_admin_emails' ), 10, 3 );
		}
		foreach ( $customer_emails as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", array( $this, 'manage_customer_emails' ), 10, 3 );
		}
	}

	/**
	 * @param array $order_statuses
	 *
	 * @return array
	 */
	public function wc_order_statuses( array $order_statuses ): array {
		$order_statuses['wc-pos-open']    = _x( 'POS - Open', 'Order status', 'woocommerce-pos' );
		$order_statuses['wc-pos-partial'] = _x( 'POS - Partial Payment', 'Order status', 'woocommerce-pos' );

		return $order_statuses;
	}

	/**
	 * WooCommerce order-pay form won't allow processing of orders with total = 0.
	 *
	 * NOTE: $needs_payment is meant to be a boolean, but I have seen it as null.
	 *
	 * @param bool              $needs_payment
	 * @param WC_Abstract_Order $order
	 * @param array             $valid_order_statuses
	 *
	 * @return bool
	 */
	public function order_needs_payment( $needs_payment, WC_Abstract_Order $order, array $valid_order_statuses ) {
		// If the order total is zero and status is a POS status, then allow payment to be taken, ie: Gift Card
		if ( 0 == $order->get_total() && \in_array( $order->get_status(), array( 'pos-open', 'pos-partial' ), true ) ) {
			return true;
		}

		return $needs_payment;
	}

	/**
	 * Note: the wc- prefix is not used here because it is added by WooCommerce.
	 *
	 * @param array             $order_statuses
	 * @param WC_Abstract_Order $order
	 *
	 * @return array
	 */
	public function valid_order_statuses_for_payment( array $order_statuses, WC_Abstract_Order $order ): array {
		$order_statuses[] = 'pos-open';
		$order_statuses[] = 'pos-partial';

		return $order_statuses;
	}

	/**
	 * @param array             $order_statuses
	 * @param WC_Abstract_Order $order
	 *
	 * @return array
	 */
	public function valid_order_statuses_for_payment_complete( array $order_statuses, WC_Abstract_Order $order ): array {
		$order_statuses[] = 'pos-open';
		$order_statuses[] = 'pos-partial';

		return $order_statuses;
	}

	/**
	 *
	 *
	 * @param string            $status
	 * @param int               $id
	 * @param WC_Abstract_Order $order
	 *
	 * @return string
	 */
	public function payment_complete_order_status( string $status, int $id, WC_Abstract_Order $order ): string {
		if ( woocommerce_pos_request() ) {
			return woocommerce_pos_get_settings( 'checkout', 'order_status' );
		}

		return $status;
	}

	/**
	 * Hides uuid from appearing on Order Edit page.
	 *
	 * @param array $meta_keys
	 *
	 * @return array
	 */
	public function hidden_order_itemmeta( array $meta_keys ): array {
		return array_merge( $meta_keys, array( '_woocommerce_pos_uuid', '_woocommerce_pos_tax_status', '_woocommerce_pos_data' ) );
	}

	/**
	 *
	 */
	public function manage_admin_emails( $enabled, $order, $email_class ) {
		if ( ! woocommerce_pos_request() ) {
			return $enabled;
		}

		return woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
	}

	/**
	 *
	 */
	public function manage_customer_emails( $enabled, $order, $email_class ) {
		if ( ! woocommerce_pos_request() ) {
			return $enabled;
		}

		return woocommerce_pos_get_settings( 'checkout', 'customer_emails' );
	}

	/**
	 * Register the POS order statuses.
	 */
	private function register_order_status(): void {
		// Order status for open orders
		register_post_status(
			'wc-pos-open',
			array(
				'label'                     => _x( 'POS - Open', 'Order status', 'woocommerce-pos' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				// translators: %s is the number of orders with POS - Open status
				'label_count'               => _n_noop(
					'POS - Open <span class="count">(%s)</span>',
					'POS - Open <span class="count">(%s)</span>',
					'woocommerce-pos'
				),
			)
		);

		// Order status for partial payment orders
		register_post_status(
			'wc-pos-partial',
			array(
				'label'                     => _x( 'POS - Partial Payment', 'Order status', 'woocommerce-pos' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				// translators: %s is the number of orders with POS - Partial Payment status
				'label_count'               => _n_noop(
					'POS - Partial Payment <span class="count">(%s)</span>',
					'POS - Partial Payment <span class="count">(%s)</span>',
					'woocommerce-pos'
				),
			)
		);
	}

	/**
	 * Filter the product object for an order item.
	 *
	 * @param WC_Product|bool       $product The product object or false if not found
	 * @param WC_Order_Item_Product $item    The order item object
	 *
	 * @return WC_Product|bool
	 */
	public function order_item_product( $product, $item ) {
		if ( ! $product ) {
			// @TODO - add check for $item meta = '_woocommerce_pos_misc_product'
			$product = new WC_Product_Simple();
			$product->set_name( $item->get_name() );
		}

		return $product;
	}

	/**
	 * Get tax location for this order.
	 *
	 * @param array             $args array Override the location.
	 * @param WC_Abstract_Order $order The order object.
	 *
	 * @return array
	 */
	public function get_tax_location( $args, WC_Abstract_Order $order ) {
		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return $args;
		}

		$tax_based_on = $order->get_meta( '_woocommerce_pos_tax_based_on' );

		if ( $order instanceof WC_Order ) {
			if ( 'billing' == $tax_based_on ) {
				$args['country']  = $order->get_billing_country();
				$args['state']    = $order->get_billing_state();
				$args['postcode'] = $order->get_billing_postcode();
				$args['city']     = $order->get_billing_city();
			} elseif ( 'shipping' == $tax_based_on ) {
				$args['country']  = $order->get_shipping_country();
				$args['state']    = $order->get_shipping_state();
				$args['postcode'] = $order->get_shipping_postcode();
				$args['city']     = $order->get_shipping_city();
			} else {
				$args['country']  = WC()->countries->get_base_country();
				$args['state']    = WC()->countries->get_base_state();
				$args['postcode'] = WC()->countries->get_base_postcode();
				$args['city']     = WC()->countries->get_base_city();
			}
		}

		return $args;
	}

	/**
	 * Calculate taxes for an order item.
	 *
	 * @param WC_Order_Item|WC_Order_Item_Shipping $item Order item object.
	 */
	public function order_item_after_calculate_taxes( $item ) {
		$meta_data = $item->get_meta_data();

		foreach ( $meta_data as $meta ) {
			foreach ( $meta_data as $meta ) {
				if ( '_woocommerce_pos_data' === $meta->key ) {
					$pos_data = json_decode( $meta->value, true );

					if ( json_last_error() === JSON_ERROR_NONE ) {
						if ( isset( $pos_data['tax_status'] ) && 'none' == $pos_data['tax_status'] ) {
							$item->set_taxes( false );
						}
					} else {
						Logger::log( 'JSON parse error: ' . json_last_error_msg() );
					}
				}
			}
		}
	}
}
