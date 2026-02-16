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
use WC_Discounts;
use WC_Product;
use WC_Product_Simple;
use WC_Tax;

/**
 * Orders Class
 * - runs for all life cycles.
 */
class Orders {
	/**
	 * Whether POS coupon recalculation context is currently active.
	 *
	 * @var bool
	 */
	private static $coupon_recalculation_active = false;

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
		add_filter( 'woocommerce_coupon_get_items_to_validate', array( $this, 'coupon_get_items_to_validate' ), 10, 2 );
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
		$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );

		// For misc products (product_id=0), create a synthetic WC_Product_Simple.
		// Requires _woocommerce_pos_data to distinguish POS items from other plugins.
		if ( 0 === $item->get_product_id() ) {
			if ( ! $pos_data_json ) {
				return $product;
			}

			$product = new WC_Product_Simple();
			$product->set_name( $item->get_name() );
			$sku = $item->get_meta( '_sku', true );
			$product->set_sku( $sku ? $sku : '' );

			// Misc products are synthetic and never persisted to DB, so we can
			// safely apply POS price context directly.
			$pos_data = json_decode( $pos_data_json, true );
			if ( JSON_ERROR_NONE === json_last_error() && \is_array( $pos_data ) ) {
				if ( isset( $pos_data['price'] ) ) {
					$product->set_price( $pos_data['price'] );
				}
				if ( isset( $pos_data['regular_price'] ) ) {
					$product->set_regular_price( $pos_data['regular_price'] );
				}
				if ( isset( $pos_data['tax_status'] ) ) {
					$product->set_tax_status( $pos_data['tax_status'] );
				}
				if ( $this->is_pos_discounted_item_on_sale( $item, $product ) && isset( $pos_data['price'] ) ) {
					$product->set_sale_price( $pos_data['price'] );
				}
			}

			return $product;
		}

		// For real products, only apply POS overrides during coupon recalculation.
		if ( ! self::$coupon_recalculation_active || ! $product || empty( $pos_data_json ) ) {
			return $product;
		}

		$pos_data = json_decode( $pos_data_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! \is_array( $pos_data ) ) {
			return $product;
		}

		// Use an isolated product instance for coupon-specific context.
		if ( $product->get_id() ) {
			$product = wc_get_product_object( $product->get_type(), $product->get_id() );
		}

		if ( isset( $pos_data['tax_status'] ) ) {
			$product->set_tax_status( $pos_data['tax_status'] );
		}

		return $product;
	}

	/**
	 * Provide coupon-validation products with POS context (sale state, tax status).
	 *
	 * This runs only inside WC_Discounts. We return per-line-item product objects
	 * so coupon rules (exclude_sale_items, tax-aware discount amounts) use POS data
	 * without mutating products used by stock update routines.
	 *
	 * @param array        $items     Discount items (stdClass objects).
	 * @param WC_Discounts $discounts Discounts context.
	 *
	 * @return array
	 */
	public function coupon_get_items_to_validate( array $items, WC_Discounts $discounts ): array {
		$object = $discounts->get_object();
		if ( ! $object instanceof WC_Order || ! woocommerce_pos_is_pos_order( $object ) ) {
			return $items;
		}

		foreach ( $items as $index => $discount_item ) {
			if ( ! isset( $discount_item->object ) || ! $discount_item->object instanceof WC_Order_Item_Product ) {
				continue;
			}

			$original_product = isset( $discount_item->product ) && $discount_item->product instanceof WC_Product
				? $discount_item->product
				: null;
			$coupon_product   = $this->build_coupon_product_context( $discount_item->object, $original_product );

			if ( $coupon_product instanceof WC_Product ) {
				$items[ $index ]->product = $coupon_product;
			}
		}

		return $items;
	}

	/**
	 * Build a product object used only for coupon validation/calculation.
	 *
	 * @param WC_Order_Item_Product $item    Order item.
	 * @param WC_Product|null       $product Current product object.
	 *
	 * @return WC_Product|null
	 */
	private function build_coupon_product_context( WC_Order_Item_Product $item, ?WC_Product $product = null ): ?WC_Product {
		$pos_data = $this->get_pos_item_data( $item );
		if ( null === $pos_data ) {
			return $product;
		}

		if ( $product && $product->get_id() ) {
			$product = wc_get_product_object( $product->get_type(), $product->get_id() );
		} elseif ( 0 === $item->get_product_id() ) {
			$product = new WC_Product_Simple();
			$product->set_name( $item->get_name() );
			$sku = $item->get_meta( '_sku', true );
			$product->set_sku( $sku ? $sku : '' );
		}

		if ( ! $product ) {
			return null;
		}

		if ( isset( $pos_data['price'] ) ) {
			$product->set_price( $pos_data['price'] );
		}
		if ( isset( $pos_data['regular_price'] ) ) {
			$product->set_regular_price( $pos_data['regular_price'] );
		}
		if ( isset( $pos_data['tax_status'] ) ) {
			$product->set_tax_status( $pos_data['tax_status'] );
		}
		if ( $this->is_pos_discounted_item_on_sale( $item, $product ) && isset( $pos_data['price'] ) ) {
			$product->set_sale_price( $pos_data['price'] );
		}

		return $product;
	}

	/**
	 * Determine whether an order item should be treated as "on sale" in coupon checks.
	 *
	 * @param WC_Order_Item_Product $item    Order item.
	 * @param WC_Product|null       $product Product context (optional).
	 *
	 * @return bool
	 */
	private function is_pos_discounted_item_on_sale( WC_Order_Item_Product $item, ?WC_Product $product = null ): bool {
		$pos_data = $this->get_pos_item_data( $item );
		if ( null === $pos_data || ! isset( $pos_data['price'], $pos_data['regular_price'] ) ) {
			return false;
		}

		$is_on_sale = (float) $pos_data['price'] < (float) $pos_data['regular_price'];
		$product    = $product ? $product : $item->get_product();

		return (bool) apply_filters(
			'woocommerce_pos_item_is_on_sale',
			$is_on_sale,
			$product,
			$item,
			$pos_data
		);
	}

	/**
	 * Decode _woocommerce_pos_data from an order item.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_pos_item_data( WC_Order_Item_Product $item ): ?array {
		$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
		if ( empty( $pos_data_json ) ) {
			return null;
		}

		$pos_data = json_decode( $pos_data_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! \is_array( $pos_data ) ) {
			return null;
		}

		return $pos_data;
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
			if ( '_woocommerce_pos_data' === $meta->key ) {
				$pos_data = json_decode( $meta->value, true );

				if ( JSON_ERROR_NONE === json_last_error() ) {
					if ( isset( $pos_data['tax_status'] ) && 'none' == $pos_data['tax_status'] ) {
						$item->set_taxes( false );
					}
				} else {
					Logger::log( 'JSON parse error: ' . json_last_error_msg() );
				}

				break;
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

		self::activate_pos_subtotal_filter();
	}

	/**
	 * Add the subtotal filter if not already active, and schedule its removal.
	 *
	 * The filter is removed on the next calculate_totals() call via
	 * woocommerce_order_after_calculate_totals, which fires at the end of
	 * recalculate_coupons().
	 */
	public static function activate_pos_subtotal_filter(): void {
		if ( has_filter( 'woocommerce_order_item_get_subtotal', array( static::class, 'filter_pos_item_subtotal' ) ) ) {
			return;
		}

		self::$coupon_recalculation_active = true;
		add_filter( 'woocommerce_order_item_get_subtotal', array( static::class, 'filter_pos_item_subtotal' ), 10, 2 );
		add_filter( 'woocommerce_order_item_get_subtotal_tax', array( static::class, 'filter_pos_item_subtotal_tax' ), 10, 2 );
		add_action( 'woocommerce_order_after_calculate_totals', array( static::class, 'deactivate_pos_subtotal_filter' ), 10, 2 );
	}

	/**
	 * Filter the line item subtotal to return the POS price (tax-exclusive).
	 *
	 * The POS stores the customer-facing price in _woocommerce_pos_data, which is
	 * tax-inclusive when the order has prices_include_tax. WooCommerce's get_subtotal()
	 * must always return a tax-exclusive value, so we extract tax when needed.
	 *
	 * @param string                $subtotal The original subtotal.
	 * @param WC_Order_Item_Product $item     The order item.
	 *
	 * @return string The tax-exclusive POS price, or the original subtotal.
	 */
	public static function filter_pos_item_subtotal( $subtotal, $item ) {
		$components = self::get_pos_price_components( $item );
		if ( null === $components ) {
			return $subtotal;
		}

		return (string) $components['subtotal'];
	}

	/**
	 * Filter the line item subtotal tax during POS coupon recalculation.
	 *
	 * Returns the tax portion of the POS price for taxable items, or '0' for
	 * tax-exempt items. This keeps subtotal and subtotal_tax consistent.
	 *
	 * @param string                $subtotal_tax The original subtotal tax.
	 * @param WC_Order_Item_Product $item         The order item.
	 *
	 * @return string
	 */
	public static function filter_pos_item_subtotal_tax( $subtotal_tax, $item ) {
		$components = self::get_pos_price_components( $item );
		if ( null === $components ) {
			return $subtotal_tax;
		}

		return (string) $components['subtotal_tax'];
	}

	/**
	 * Split the POS price into tax-exclusive subtotal and tax components.
	 *
	 * When the order has prices_include_tax and the item is taxable, extracts
	 * the tax from the inclusive POS price using WC_Tax::find_rates() and
	 * WC_Tax::calc_tax() for accuracy. This avoids deriving rates from stored
	 * values which can become inconsistent after calculate_taxes() runs.
	 *
	 * @param WC_Order_Item_Product|mixed $item The order item.
	 *
	 * @return array{subtotal: float, subtotal_tax: float}|null Components or null if not a POS item.
	 */
	private static function get_pos_price_components( $item ): ?array {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return null;
		}

		$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
		if ( empty( $pos_data_json ) ) {
			return null;
		}

		$pos_data = json_decode( $pos_data_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! \is_array( $pos_data ) || ! isset( $pos_data['price'] ) ) {
			return null;
		}

		$pos_price  = (float) $pos_data['price'] * $item->get_quantity();
		$tax_status = $pos_data['tax_status'] ?? 'taxable';

		// Non-taxable items (none or shipping-only): no product tax to extract.
		if ( 'none' === $tax_status || 'shipping' === $tax_status ) {
			return array(
				'subtotal'     => $pos_price,
				'subtotal_tax' => 0.0,
			);
		}

		// Check if the order uses tax-inclusive pricing.
		$order = $item->get_order();
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		if ( $order->get_prices_include_tax() ) {
			$tax_rates = self::get_tax_rates_for_item( $item, $order );

			if ( ! empty( $tax_rates ) ) {
				$taxes      = WC_Tax::calc_tax( $pos_price, $tax_rates, true );
				$tax_amount = array_sum( $taxes );
				$ex_tax     = $pos_price - $tax_amount;

				return array(
					'subtotal'     => $ex_tax,
					'subtotal_tax' => $tax_amount,
				);
			}

			// No tax rates found: treat as untaxed.
			return array(
				'subtotal'     => $pos_price,
				'subtotal_tax' => 0.0,
			);
		}

		// Prices exclude tax: POS price is already tax-exclusive.
		return array(
			'subtotal'     => $pos_price,
			'subtotal_tax' => (float) $item->get_subtotal_tax( 'edit' ),
		);
	}

	/**
	 * Get the applicable tax rates for an order item.
	 *
	 * Determines the tax location from the order's POS tax-based-on meta
	 * (matching the logic in get_tax_location filter) and finds the rates
	 * for the item's tax class.
	 *
	 * @param WC_Order_Item_Product $item  The order item.
	 * @param WC_Order              $order The order.
	 *
	 * @return array Tax rates array from WC_Tax::find_rates().
	 */
	private static function get_tax_rates_for_item( $item, $order ): array {
		$tax_based_on = $order->get_meta( '_woocommerce_pos_tax_based_on' );
		if ( empty( $tax_based_on ) ) {
			$tax_based_on = 'base';
		}

		if ( 'billing' === $tax_based_on ) {
			$country  = $order->get_billing_country();
			$state    = $order->get_billing_state();
			$postcode = $order->get_billing_postcode();
			$city     = $order->get_billing_city();
		} elseif ( 'shipping' === $tax_based_on ) {
			$country  = $order->get_shipping_country();
			$state    = $order->get_shipping_state();
			$postcode = $order->get_shipping_postcode();
			$city     = $order->get_shipping_city();
		} else {
			$country  = WC()->countries->get_base_country();
			$state    = WC()->countries->get_base_state();
			$postcode = WC()->countries->get_base_postcode();
			$city     = WC()->countries->get_base_city();
		}

		return WC_Tax::find_rates(
			array(
				'country'   => $country,
				'state'     => $state,
				'postcode'  => $postcode,
				'city'      => $city,
				'tax_class' => $item->get_tax_class(),
			)
		);
	}

	/**
	 * Remove the temporary subtotal filter after coupon recalculation completes.
	 *
	 * @param bool              $and_taxes Whether taxes were calculated.
	 * @param WC_Abstract_Order $order     The order object.
	 */
	public static function deactivate_pos_subtotal_filter( $and_taxes, $order ): void {
		self::$coupon_recalculation_active = false;
		remove_filter( 'woocommerce_order_item_get_subtotal', array( static::class, 'filter_pos_item_subtotal' ), 10 );
		remove_filter( 'woocommerce_order_item_get_subtotal_tax', array( static::class, 'filter_pos_item_subtotal_tax' ), 10 );
		remove_action( 'woocommerce_order_after_calculate_totals', array( static::class, 'deactivate_pos_subtotal_filter' ), 10 );
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
