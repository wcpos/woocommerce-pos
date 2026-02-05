<?php
/**
 * WCPOS Orders Class
 * Extends WooCommerce Orders.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WC_Abstract_Order;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;
use WC_Product_Simple;

/**
 * Orders Class
 * - runs for all life cycles.
 */
class Orders {
	/**
	 * Constructor.
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
		add_action( 'woocommerce_order_applied_coupon', array( $this, 'before_coupon_recalculation' ), 10, 2 );
	}

	/**
	 * Add custom POS order statuses.
	 *
	 * @param array $order_statuses Existing order statuses.
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
	 * @param bool              $needs_payment        Whether payment is needed.
	 * @param WC_Abstract_Order $order                The order object.
	 * @param array             $valid_order_statuses Valid order statuses for payment.
	 *
	 * @return bool
	 */
	public function order_needs_payment( $needs_payment, WC_Abstract_Order $order, array $valid_order_statuses ) {
		// If the order total is zero and status is a POS status, then allow payment to be taken, ie: Gift Card.
		if ( 0 == $order->get_total() && \in_array( $order->get_status(), array( 'pos-open', 'pos-partial' ), true ) ) {
			return true;
		}

		return $needs_payment;
	}

	/**
	 * Note: the wc- prefix is not used here because it is added by WooCommerce.
	 *
	 * @param array             $order_statuses Valid order statuses.
	 * @param WC_Abstract_Order $order          The order object.
	 *
	 * @return array
	 */
	public function valid_order_statuses_for_payment( array $order_statuses, WC_Abstract_Order $order ): array {
		$order_statuses[] = 'pos-open';
		$order_statuses[] = 'pos-partial';

		return $order_statuses;
	}

	/**
	 * Valid order statuses for payment complete.
	 *
	 * @param array             $order_statuses Valid order statuses.
	 * @param WC_Abstract_Order $order          The order object.
	 *
	 * @return array
	 */
	public function valid_order_statuses_for_payment_complete( array $order_statuses, WC_Abstract_Order $order ): array {
		$order_statuses[] = 'pos-open';
		$order_statuses[] = 'pos-partial';

		return $order_statuses;
	}

	/**
	 * Payment complete order status.
	 *
	 * @param string            $status Order status.
	 * @param int               $id     Order ID.
	 * @param WC_Abstract_Order $order  The order object.
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
	 * @param array $meta_keys Hidden meta keys.
	 *
	 * @return array
	 */
	public function hidden_order_itemmeta( array $meta_keys ): array {
		return array_merge( $meta_keys, array( '_woocommerce_pos_uuid', '_woocommerce_pos_tax_status', '_woocommerce_pos_data' ) );
	}

	/**
	 * Filter the product object for an order item.
	 *
	 * @param bool|WC_Product       $product The product object or false if not found.
	 * @param WC_Order_Item_Product $item    The order item object.
	 *
	 * @return bool|WC_Product
	 */
	public function order_item_product( $product, $item ) {
		if ( ! $product && 0 === $item->get_product_id() ) {
			// @TODO - add check for $item meta = '_woocommerce_pos_misc_product'
			$product = new WC_Product_Simple();

			$product->set_name( $item->get_name() );
			$sku = $item->get_meta( '_sku', true );
			$product->set_sku( $sku ? $sku : '' );
		}

		// Apply POS price data to the product (for both misc and real products).
		// This sets sale_price so WC_Product::is_on_sale() returns true, causing
		// coupons with exclude_sale_items to skip POS-discounted items.
		if ( $product ) {
			$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
			if ( ! empty( $pos_data_json ) ) {
				$pos_data = json_decode( $pos_data_json, true );

				if ( JSON_ERROR_NONE === json_last_error() && \is_array( $pos_data ) ) {
					if ( isset( $pos_data['price'] ) ) {
						$product->set_price( $pos_data['price'] );

						/**
						 * Filter whether a POS-discounted item should be treated as "on sale."
						 *
						 * When true, the product's sale_price is set to the POS price, making
						 * is_on_sale() return true. Coupons with exclude_sale_items will skip
						 * this item.
						 *
						 * @param bool                 $is_on_sale Whether the item is on sale.
						 * @param WC_Product           $product    The product object.
						 * @param WC_Order_Item_Product $item      The order line item.
						 * @param array                $pos_data   The decoded POS data.
						 */
						$is_on_sale = isset( $pos_data['regular_price'] )
							&& (float) $pos_data['price'] < (float) $pos_data['regular_price'];

						$is_on_sale = apply_filters(
							'woocommerce_pos_item_is_on_sale',
							$is_on_sale,
							$product,
							$item,
							$pos_data
						);

						if ( $is_on_sale ) {
							$product->set_sale_price( $pos_data['price'] );
						}
					}
					if ( isset( $pos_data['regular_price'] ) ) {
						$product->set_regular_price( $pos_data['regular_price'] );
					}
					if ( isset( $pos_data['tax_status'] ) ) {
						$product->set_tax_status( $pos_data['tax_status'] );
					}
				}
			}
		}

		return $product;
	}

	/**
	 * Get tax location for this order.
	 *
	 * @param array             $args  Override the location.
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
	 *
	 * @return void
	 */
	public function order_item_after_calculate_taxes( $item ): void {
		$meta_data = $item->get_meta_data();

		foreach ( $meta_data as $meta ) {
			foreach ( $meta_data as $meta ) {
				if ( '_woocommerce_pos_data' === $meta->key ) {
					$pos_data = json_decode( $meta->value, true );

					if ( JSON_ERROR_NONE === json_last_error() ) {
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

	/**
	 * Activate the POS subtotal filter before coupon recalculation.
	 *
	 * WooCommerce's recalculate_coupons() uses get_subtotal() as the base price for
	 * coupon calculations. The POS stores the original price in subtotal and the
	 * discounted price in _woocommerce_pos_data meta.
	 *
	 * This hook fires from apply_coupon() BEFORE recalculate_coupons() runs, so we
	 * add a filter to temporarily return the POS price as the subtotal.
	 *
	 * For remove_coupon(), there is no "before" hook in WooCommerce core. The filter
	 * is activated manually in Form_Handler::coupon_action() before calling
	 * $order->remove_coupon().
	 *
	 * @see WC_Abstract_Order::apply_coupon()
	 * @see WC_Abstract_Order::recalculate_coupons()
	 *
	 * @param \WC_Coupon        $coupon The coupon object.
	 * @param WC_Abstract_Order $order  The order object.
	 */
	public function before_coupon_recalculation( $coupon, $order ): void {
		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return;
		}

		$this->activate_pos_subtotal_filter();
	}

	/**
	 * Add the subtotal filter if not already active, and schedule its removal.
	 *
	 * The filter is removed on the next calculate_totals() call via
	 * woocommerce_order_after_calculate_totals, which fires at the end of
	 * recalculate_coupons().
	 */
	public function activate_pos_subtotal_filter(): void {
		if ( has_filter( 'woocommerce_order_item_get_subtotal', array( $this, 'filter_pos_item_subtotal' ) ) ) {
			return;
		}

		add_filter( 'woocommerce_order_item_get_subtotal', array( $this, 'filter_pos_item_subtotal' ), 10, 2 );
		add_filter( 'woocommerce_order_item_get_subtotal_tax', array( $this, 'filter_pos_item_subtotal_tax' ), 10, 2 );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'deactivate_pos_subtotal_filter' ), 10, 2 );
	}

	/**
	 * Filter the line item subtotal to return the POS-discounted price.
	 *
	 * Only affects items with _woocommerce_pos_data meta containing a 'price' field.
	 * Items without POS data are returned unchanged.
	 *
	 * @param string                $subtotal The original subtotal.
	 * @param WC_Order_Item_Product $item     The order item.
	 *
	 * @return string The POS price if available, otherwise the original subtotal.
	 */
	public function filter_pos_item_subtotal( $subtotal, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $subtotal;
		}

		$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
		if ( empty( $pos_data_json ) ) {
			return $subtotal;
		}

		$pos_data = json_decode( $pos_data_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! \is_array( $pos_data ) || ! isset( $pos_data['price'] ) ) {
			return $subtotal;
		}

		return (string) ( (float) $pos_data['price'] * $item->get_quantity() );
	}

	/**
	 * Filter the line item subtotal tax during POS coupon recalculation.
	 *
	 * When the POS sets tax_status to 'none', subtotal tax should be 0.
	 *
	 * @param string                $subtotal_tax The original subtotal tax.
	 * @param WC_Order_Item_Product $item         The order item.
	 *
	 * @return string
	 */
	public function filter_pos_item_subtotal_tax( $subtotal_tax, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $subtotal_tax;
		}

		$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
		if ( empty( $pos_data_json ) ) {
			return $subtotal_tax;
		}

		$pos_data = json_decode( $pos_data_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! \is_array( $pos_data ) ) {
			return $subtotal_tax;
		}

		if ( isset( $pos_data['tax_status'] ) && 'none' === $pos_data['tax_status'] ) {
			return '0';
		}

		return $subtotal_tax;
	}

	/**
	 * Remove the temporary subtotal filter after coupon recalculation completes.
	 *
	 * @param bool              $and_taxes Whether taxes were calculated.
	 * @param WC_Abstract_Order $order     The order object.
	 */
	public function deactivate_pos_subtotal_filter( $and_taxes, $order ): void {
		remove_filter( 'woocommerce_order_item_get_subtotal', array( $this, 'filter_pos_item_subtotal' ), 10 );
		remove_filter( 'woocommerce_order_item_get_subtotal_tax', array( $this, 'filter_pos_item_subtotal_tax' ), 10 );
		remove_action( 'woocommerce_order_after_calculate_totals', array( $this, 'deactivate_pos_subtotal_filter' ), 10 );
	}

	/**
	 * Register the POS order statuses.
	 */
	private function register_order_status(): void {
		// Order status for open orders.
		register_post_status(
			'wc-pos-open',
			array(
				'label'                     => _x( 'POS - Open', 'Order status', 'woocommerce-pos' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				// translators: %s is the number of orders with POS - Open status.
				'label_count'               => _n_noop(
					'POS - Open <span class="count">(%s)</span>',
					'POS - Open <span class="count">(%s)</span>',
					'woocommerce-pos'
				),
			)
		);

		// Order status for partial payment orders.
		register_post_status(
			'wc-pos-partial',
			array(
				'label'                     => _x( 'POS - Partial Payment', 'Order status', 'woocommerce-pos' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				// translators: %s is the number of orders with POS - Partial Payment status.
				'label_count'               => _n_noop(
					'POS - Partial Payment <span class="count">(%s)</span>',
					'POS - Partial Payment <span class="count">(%s)</span>',
					'woocommerce-pos'
				),
			)
		);
	}
}
