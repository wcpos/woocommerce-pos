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
use WC_Coupon;
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
	 * Map of temporary product IDs to category IDs for coupon validation.
	 *
	 * Static because multiple Orders instances may register hooks on the same
	 * filter (plugin init + test setUp). All instances must recognize temp IDs
	 * assigned by any other instance to avoid invalid DB reads.
	 *
	 * @var array<int, int[]>
	 */
	private static $temp_product_categories = array();

	/**
	 * Counter for generating unique temporary product IDs.
	 *
	 * @var int
	 */
	private static $temp_id_counter = PHP_INT_MAX;

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
		add_filter( 'woocommerce_bacs_process_payment_order_status', array( $this, 'offline_process_payment_order_status' ), 10, 2 );
		add_filter( 'woocommerce_cheque_process_payment_order_status', array( $this, 'offline_process_payment_order_status' ), 10, 2 );
		add_filter( 'woocommerce_cod_process_payment_order_status', array( $this, 'offline_process_payment_order_status' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_order_itemmeta' ) );
		add_filter( 'woocommerce_order_item_product', array( $this, 'order_item_product' ), 10, 2 );
		add_filter( 'woocommerce_order_get_tax_location', array( $this, 'get_tax_location' ), 10, 2 );
		add_action( 'woocommerce_order_item_after_calculate_taxes', array( $this, 'order_item_after_calculate_taxes' ) );
		add_action( 'woocommerce_order_item_shipping_after_calculate_taxes', array( $this, 'order_item_after_calculate_taxes' ) );
		add_filter( 'woocommerce_coupon_get_items_to_validate', array( $this, 'coupon_get_items_to_validate' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'coupon_is_valid_for_product' ), 10, 4 );
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
			$gateway_status = $this->get_gateway_order_status( $order->get_payment_method() );

			// This filter expects statuses without the 'wc-' prefix.
			$normalized_status = 0 === strpos( $gateway_status, 'wc-' )
				? substr( $gateway_status, 3 )
				: $gateway_status;

			if ( '' === $normalized_status ) {
				return $status;
			}

			$valid_statuses = array_map(
				function ( string $order_status ): string {
					return 0 === strpos( $order_status, 'wc-' )
						? substr( $order_status, 3 )
						: $order_status;
				},
				array_keys( wc_get_order_statuses() )
			);

			return \in_array( $normalized_status, $valid_statuses, true )
				? $normalized_status
				: $status;
		}

		return $status;
	}

	/**
	 * Process payment order status for offline gateways (BACS, cheque, COD).
	 *
	 * @param string            $status Order status from gateway.
	 * @param WC_Abstract_Order $order  The order object.
	 *
	 * @return string
	 */
	public function offline_process_payment_order_status( string $status, WC_Abstract_Order $order ): string {
		if ( ! woocommerce_pos_request() ) {
			return $status;
		}

		if ( ! $order->get_id() ) {
			return $status;
		}

		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return $status;
		}

		$gateway_order_status = $this->get_gateway_order_status( $order->get_payment_method() );

		$normalized_status = 0 === strpos( $gateway_order_status, 'wc-' )
			? substr( $gateway_order_status, 3 )
			: $gateway_order_status;

		if ( '' === $normalized_status ) {
			return $status;
		}

		$valid_statuses = array_map(
			function ( string $order_status ): string {
				return 0 === strpos( $order_status, 'wc-' )
					? substr( $order_status, 3 )
					: $order_status;
			},
			array_keys( wc_get_order_statuses() )
		);

		return \in_array( $normalized_status, $valid_statuses, true )
			? $normalized_status
			: $status;
	}

	/**
	 * Resolve the configured POS order status for a given payment gateway.
	 *
	 * Looks up the per-gateway order_status from payment_gateways settings.
	 * Falls back to 'wc-completed' if no setting is found.
	 *
	 * @param string $gateway_id The payment gateway ID.
	 *
	 * @return string The configured order status (may include wc- prefix).
	 */
	private function get_gateway_order_status( string $gateway_id ): string {
		$gateway_settings = woocommerce_pos_get_settings( 'payment_gateways' );

		if (
			is_array( $gateway_settings )
			&& isset( $gateway_settings['gateways'][ $gateway_id ]['order_status'] )
			&& is_string( $gateway_settings['gateways'][ $gateway_id ]['order_status'] )
			&& '' !== $gateway_settings['gateways'][ $gateway_id ]['order_status']
		) {
			return $gateway_settings['gateways'][ $gateway_id ]['order_status'];
		}

		return 'wc-completed';
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
			if ( $sku ) {
				$this->set_synthetic_product_sku( $product, $sku );
			}

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
				if ( ! empty( $pos_data['virtual'] ) ) {
					$product->set_virtual( true );
				}
				if ( ! empty( $pos_data['downloadable'] ) ) {
					$product->set_downloadable( true );
				}
				if ( ! empty( $pos_data['categories'] ) && is_array( $pos_data['categories'] ) ) {
					$category_ids = array_filter( array_map( 'intval', array_column( $pos_data['categories'], 'id' ) ) );
					$product->set_category_ids( $category_ids );
				}
				if ( $this->is_pos_discounted_item_on_sale( $item, $product ) && isset( $pos_data['price'] ) ) {
					$product->set_sale_price( $pos_data['price'] );
				}
			}

			return $product;
		}

		// For real products, only apply POS overrides when pos_data exists.
		if ( ! $product || empty( $pos_data_json ) ) {
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

		$is_temp_id = $product && isset( self::$temp_product_categories[ $product->get_id() ] );

		if ( $product && $product->get_id() && ! $is_temp_id ) {
			// Get a fresh product instance to apply POS overrides.
			$product = wc_get_product_object( $product->get_type(), $product->get_id() );
		} elseif ( 0 === $item->get_product_id() ) {
			$product = new WC_Product_Simple();
			$product->set_name( $item->get_name() );
			$sku = $item->get_meta( '_sku', true );
			if ( $sku ) {
				$this->set_synthetic_product_sku( $product, $sku );
			}
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
		if ( ! empty( $pos_data['virtual'] ) ) {
			$product->set_virtual( true );
		}
		if ( ! empty( $pos_data['downloadable'] ) ) {
			$product->set_downloadable( true );
		}
		if ( ! empty( $pos_data['categories'] ) && is_array( $pos_data['categories'] ) ) {
			$category_ids = array_filter( array_map( 'intval', array_column( $pos_data['categories'], 'id' ) ) );
			$product->set_category_ids( $category_ids );

			// Assign a temporary non-zero ID and prime WP caches so that
			// WC's get_the_terms() (called via wc_get_product_cat_ids) finds
			// our categories. get_the_terms() requires get_post() to succeed
			// and checks the object term cache before querying the DB.
			if ( 0 === $product->get_id() && ! empty( $category_ids ) ) {
				$temp_id = self::$temp_id_counter--;
				$product->set_id( $temp_id );
				self::$temp_product_categories[ $temp_id ] = $category_ids;

				// Prime post cache so get_post(temp_id) succeeds.
				$fake_post                = new \stdClass();
				$fake_post->ID            = $temp_id;
				$fake_post->post_type     = 'product';
				$fake_post->post_status   = 'publish';
				$fake_post->filter        = 'raw';
				$fake_post->post_parent   = 0;
				$fake_post->post_title    = '';
				$fake_post->post_content  = '';
				$fake_post->post_excerpt  = '';
				$fake_post->post_date     = '';
				$fake_post->post_date_gmt = '';
				wp_cache_set( $temp_id, $fake_post, 'posts' );
			}
		}
		if ( $this->is_pos_discounted_item_on_sale( $item, $product ) && isset( $pos_data['price'] ) ) {
			$product->set_sale_price( $pos_data['price'] );
		}

		return $product;
	}

	/**
	 * Override coupon category validation for misc products.
	 *
	 * WooCommerce uses wc_get_product_cat_ids( $product->get_id() ) to check
	 * product_categories and excluded_product_categories coupon restrictions.
	 * Synthetic misc products use temporary non-zero IDs so WC's DB lookup
	 * doesn't short-circuit. This filter re-evaluates using per-item categories.
	 *
	 * @param bool       $valid   Whether the coupon is valid for the product.
	 * @param WC_Product $product Product being validated.
	 * @param WC_Coupon  $coupon  Coupon being applied.
	 * @param mixed      $values  Values (order item or cart item data).
	 *
	 * @return bool
	 */
	public function coupon_is_valid_for_product( bool $valid, $product, $coupon, $values ): bool {
		if ( ! $product instanceof WC_Product ) {
			return $valid;
		}

		// Only handle products with temp IDs assigned by build_coupon_product_context.
		$product_id = $product->get_id();
		if ( ! isset( self::$temp_product_categories[ $product_id ] ) ) {
			return $valid;
		}

		$product_cats = $product->get_category_ids();
		if ( empty( $product_cats ) ) {
			return $valid;
		}

		// Include parent categories for hierarchy matching (parity with wc_get_product_cat_ids).
		foreach ( $product_cats as $cat ) {
			$product_cats = array_merge( $product_cats, get_ancestors( $cat, 'product_cat' ) );
		}
		$product_cats = array_unique( $product_cats );

		// Re-evaluate product_categories restriction.
		$coupon_cats = $coupon->get_product_categories();
		if ( ! empty( $coupon_cats ) ) {
			$valid = $valid && count( array_intersect( $product_cats, $coupon_cats ) ) > 0;
		}

		// Re-evaluate excluded_product_categories restriction.
		$excluded_cats = $coupon->get_excluded_product_categories();
		if ( ! empty( $excluded_cats ) && count( array_intersect( $product_cats, $excluded_cats ) ) > 0 ) {
			$valid = false;
		}

		return $valid;
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

	/**
	 * Set SKU on a synthetic product, bypassing WooCommerce's uniqueness check.
	 *
	 * Synthetic products (product_id=0) are never saved to the database, so
	 * SKU collisions with real products are irrelevant. Temporarily disabling
	 * object_read causes set_sku() to skip the wc_product_has_unique_sku() call.
	 *
	 * @param WC_Product_Simple $product Synthetic product instance.
	 * @param string            $sku     SKU value from order-item meta.
	 */
	private function set_synthetic_product_sku( WC_Product_Simple $product, string $sku ): void {
		$product->set_object_read( false );
		$product->set_sku( $sku );
		$product->set_object_read( true );
	}
}
