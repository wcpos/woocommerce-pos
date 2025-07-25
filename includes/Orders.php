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
use WC_Order;
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

		// POS email management - higher priority to override other plugins
		$this->setup_email_management();
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
	 * Manage admin email sending for POS orders.
	 * Only affects orders created via WooCommerce POS.
	 *
	 * @param bool           $enabled     Whether the email is enabled.
	 * @param null|WC_Order  $order       The order object.
	 * @param mixed|WC_Email $email_class The email class.
	 *
	 * @return bool Whether the email should be sent.
	 */
	public function manage_admin_emails( $enabled, $order, $email_class ) {
		// Only control emails for POS orders
		if ( ! $this->is_pos_order( $order ) ) {
			return $enabled;
		}

		// Return the setting value, this will override any other plugin settings
		return (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
	}

	/**
	 * Manage customer email sending for POS orders.
	 * Only affects orders created via WooCommerce POS.
	 *
	 * @param bool           $enabled     Whether the email is enabled.
	 * @param null|WC_Order  $order       The order object.
	 * @param mixed|WC_Email $email_class The email class.
	 *
	 * @return bool Whether the email should be sent.
	 */
	public function manage_customer_emails( $enabled, $order, $email_class ) {
		// Only control emails for POS orders
		if ( ! $this->is_pos_order( $order ) ) {
			return $enabled;
		}

		// Return the setting value, this will override any other plugin settings
		return (bool) woocommerce_pos_get_settings( 'checkout', 'customer_emails' );
	}

	/**
	 * Filter admin email recipients for POS orders as a safety net.
	 * If admin emails are disabled, return empty string to prevent sending.
	 *
	 * @param string         $recipient   The recipient email address.
	 * @param null|WC_Order  $order       The order object.
	 * @param mixed|WC_Email $email_class The email class.
	 * @param array          $args        Additional arguments.
	 *
	 * @return string The recipient email or empty string to prevent sending.
	 */
	public function filter_admin_email_recipients( $recipient, $order, $email_class, $args = array() ) {
		// Only control emails for POS orders
		if ( ! $this->is_pos_order( $order ) ) {
			return $recipient;
		}

		// If admin emails are disabled, return empty string to prevent sending
		$admin_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
		if ( ! $admin_emails_enabled ) {
			return '';
		}

		return $recipient;
	}

	/**
	 * Filter customer email recipients for POS orders as a safety net.
	 * If customer emails are disabled, return empty string to prevent sending.
	 *
	 * @param string         $recipient   The recipient email address.
	 * @param null|WC_Order  $order       The order object.
	 * @param mixed|WC_Email $email_class The email class.
	 * @param array          $args        Additional arguments.
	 *
	 * @return string The recipient email or empty string to prevent sending.
	 */
	public function filter_customer_email_recipients( $recipient, $order, $email_class, $args = array() ) {
		// Only control emails for POS orders
		if ( ! $this->is_pos_order( $order ) ) {
			return $recipient;
		}

		// If customer emails are disabled, return empty string to prevent sending
		$customer_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'customer_emails' );
		if ( ! $customer_emails_enabled ) {
			return '';
		}

		return $recipient;
	}

	/**
	 * Filter the product object for an order item.
	 *
	 * @param bool|WC_Product       $product The product object or false if not found
	 * @param WC_Order_Item_Product $item    The order item object
	 *
	 * @return bool|WC_Product
	 */
	public function order_item_product( $product, $item ) {
		if ( ! $product && 0 === $item->get_product_id() ) {
			// @TODO - add check for $item meta = '_woocommerce_pos_misc_product'
			$product = new WC_Product_Simple();

			// set name & sku
			$product->set_name( $item->get_name() );
			$product->set_sku( $item->get_meta( '_sku', true ) ?: '' );

			// set price and regular_price
			$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
			$pos_data      = json_decode( $pos_data_json, true );

			if ( JSON_ERROR_NONE === json_last_error() && \is_array( $pos_data ) ) {
				if ( isset( $pos_data['price'] ) ) {
					$product->set_price( $pos_data['price'] );
					$product->set_sale_price( $pos_data['price'] );
				}
				if ( isset( $pos_data['regular_price'] ) ) {
					$product->set_regular_price( $pos_data['regular_price'] );
				}
				if ( isset( $pos_data['tax_status'] ) ) {
					$product->set_tax_status( $pos_data['tax_status'] );
				}
			}
		}

		return $product;
	}

	/**
	 * Get tax location for this order.
	 *
	 * @param array             $args  array Override the location.
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
	 * Ultimate failsafe to prevent disabled POS emails from being sent.
	 * This hooks into wp_mail as the final layer of protection.
	 *
	 * @param array $atts The wp_mail arguments.
	 *
	 * @return array|false The wp_mail arguments or false to prevent sending.
	 */
	public function prevent_disabled_pos_emails( $atts ) {
		// Check if this email is related to a WooCommerce order
		if ( ! isset( $atts['subject'] ) || ! \is_string( $atts['subject'] ) ) {
			return $atts;
		}

		// Look for WooCommerce order patterns in the subject line
		$subject     = $atts['subject'];
		$is_wc_email = false;
		$order_id    = null;

		// Common WooCommerce email subject patterns
		$patterns = array(
			'/Your (.+) order \(#(\d+)\)/',                    // Customer emails
			'/\[(.+)\] New customer order \(#(\d+)\)/',        // New order admin email
			'/\[(.+)\] Cancelled order \(#(\d+)\)/',           // Cancelled order
			'/\[(.+)\] Failed order \(#(\d+)\)/',              // Failed order
			'/Order #(\d+) details/',                          // Invoice emails
			'/Note added to your order #(\d+)/',               // Customer note
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $subject, $matches ) ) {
				$is_wc_email = true;
				// Extract order ID from the match
				$order_id = isset( $matches[2] ) ? (int) $matches[2] : ( isset( $matches[1] ) ? (int) $matches[1] : null );

				break;
			}
		}

		// If this doesn't appear to be a WooCommerce email, let it through
		if ( ! $is_wc_email || ! $order_id ) {
			return $atts;
		}

		// Get the order and check if it's a POS order
		$order = wc_get_order( $order_id );
		if ( ! $this->is_pos_order( $order ) ) {
			return $atts;
		}

		// Determine if this is likely an admin or customer email based on recipient and content
		$to             = $atts['to'];
		$admin_email    = get_option( 'admin_email' );
		$is_admin_email = ( $to === $admin_email || 0 === strpos( $subject, '[' ) );

		// Check settings and prevent sending if disabled
		if ( $is_admin_email ) {
			$admin_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
			if ( ! $admin_emails_enabled ) {
				// Log for debugging purposes
				Logger::log( 'WCPOS: Prevented admin email for POS order #' . $order_id );

				return false; // Prevent the email from being sent
			}
		} else {
			$customer_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'customer_emails' );
			if ( ! $customer_emails_enabled ) {
				// Log for debugging purposes
				Logger::log( 'WCPOS: Prevented customer email for POS order #' . $order_id );

				return false; // Prevent the email from being sent
			}
		}

		return $atts;
	}

	/**
	 * Check if an order was created via WooCommerce POS.
	 *
	 * @param null|WC_Order $order The order object.
	 *
	 * @return bool True if the order was created via POS, false otherwise.
	 */
	private function is_pos_order( $order ) {
		// Handle various input types and edge cases
		if ( ! $order instanceof WC_Order ) {
			// Sometimes the order is passed as an ID
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}
			
			// If we still don't have a valid order, return false
			if ( ! $order instanceof WC_Order ) {
				return false;
			}
		}

		// Check if the order was created via WooCommerce POS
		return 'woocommerce-pos' === $order->get_created_via();
	}

	/**
	 * Setup email management hooks for POS orders.
	 * Uses high priority (999) to ensure these settings override other plugins.
	 */
	private function setup_email_management(): void {
		// Admin emails - these go to store administrators
		$admin_emails = array(
			'new_order',
			'cancelled_order',
			'failed_order',
			'reset_password',
			'new_account',
		);

		// Customer emails - these go to customers
		$customer_emails = array(
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
			'customer_invoice',
			'customer_note',
		);

		// Hook into email enabled filters with high priority
		foreach ( $admin_emails as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", array( $this, 'manage_admin_emails' ), 999, 3 );
		}
		foreach ( $customer_emails as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", array( $this, 'manage_customer_emails' ), 999, 3 );
		}

		// Additional safety net - hook into the recipient filters as well to ensure no emails go out when disabled
		foreach ( $admin_emails as $email_id ) {
			add_filter( "woocommerce_email_recipient_{$email_id}", array( $this, 'filter_admin_email_recipients' ), 999, 4 );
		}
		foreach ( $customer_emails as $email_id ) {
			add_filter( "woocommerce_email_recipient_{$email_id}", array( $this, 'filter_customer_email_recipients' ), 999, 4 );
		}

		// Ultimate failsafe - use wp_mail filter to prevent sending at the last moment
		add_filter( 'wp_mail', array( $this, 'prevent_disabled_pos_emails' ), 999, 1 );
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
}
