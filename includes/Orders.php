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
		// Better email ID detection
		$email_id = 'unknown';
		if ( $email_class instanceof WC_Email && isset( $email_class->id ) ) {
			$email_id = $email_class->id;
		} elseif ( \is_object( $email_class ) && isset( $email_class->id ) ) {
			$email_id = $email_class->id;
		} elseif ( \is_string( $email_class ) ) {
			$email_id = $email_class;
		}

		// Get current filter name for additional context
		$current_filter = current_filter();
		
		// Only control emails for POS orders
		if ( ! $this->is_pos_order( $order ) ) {
			Logger::log( \sprintf(
				'WCPOS Admin Email: Order #%s not POS order (created_via: %s), Email ID: %s, Filter: %s - SKIPPING',
				$order instanceof WC_Order ? $order->get_id() : 'unknown',
				$order instanceof WC_Order ? $order->get_created_via() : 'unknown',
				$email_id,
				$current_filter
			) );

			return $enabled;
		}

		$admin_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
		$order_id             = $order instanceof WC_Order ? $order->get_id() : 'unknown';

		// Debug logging
		Logger::log( \sprintf(
			'WCPOS Admin Email Control: Order #%s, Email ID: %s, Filter: %s, Originally Enabled: %s, POS Setting: %s, Final Result: %s',
			$order_id,
			$email_id,
			$current_filter,
			$enabled ? 'YES' : 'NO',
			$admin_emails_enabled ? 'ENABLED' : 'DISABLED',
			$admin_emails_enabled ? 'ENABLED' : 'DISABLED'
		) );

		// Return the setting value, this will override any other plugin settings
		return $admin_emails_enabled;
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

		$customer_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'customer_emails' );
		$email_id                = $email_class instanceof WC_Email ? $email_class->id : 'unknown';
		$order_id                = $order instanceof WC_Order ? $order->get_id() : 'unknown';

		// Debug logging
		Logger::log( \sprintf(
			'WCPOS Customer Email Control: Order #%s, Email ID: %s, Originally Enabled: %s, POS Setting: %s, Final Result: %s',
			$order_id,
			$email_id,
			$enabled ? 'YES' : 'NO',
			$customer_emails_enabled ? 'ENABLED' : 'DISABLED',
			$customer_emails_enabled ? 'ENABLED' : 'DISABLED'
		) );

		// Return the setting value, this will override any other plugin settings
		return $customer_emails_enabled;
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

		$admin_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
		$email_id             = $email_class instanceof WC_Email ? $email_class->id : 'unknown';
		$order_id             = $order instanceof WC_Order ? $order->get_id() : 'unknown';

		// Debug logging
		Logger::log( \sprintf(
			'WCPOS Admin Recipient Filter: Order #%s, Email ID: %s, Original Recipient: %s, POS Setting: %s, Final Recipient: %s',
			$order_id,
			$email_id,
			$recipient,
			$admin_emails_enabled ? 'ENABLED' : 'DISABLED',
			$admin_emails_enabled ? $recipient : 'BLOCKED'
		) );

		// If admin emails are disabled, return empty string to prevent sending
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

		$customer_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'customer_emails' );
		$email_id                = $email_class instanceof WC_Email ? $email_class->id : 'unknown';
		$order_id                = $order instanceof WC_Order ? $order->get_id() : 'unknown';

		// Debug logging
		Logger::log( \sprintf(
			'WCPOS Customer Recipient Filter: Order #%s, Email ID: %s, Original Recipient: %s, POS Setting: %s, Final Recipient: %s',
			$order_id,
			$email_id,
			$recipient,
			$customer_emails_enabled ? 'ENABLED' : 'DISABLED',
			$customer_emails_enabled ? $recipient : 'BLOCKED'
		) );

		// If customer emails are disabled, return empty string to prevent sending
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

		// Common WooCommerce email subject patterns - more comprehensive
		$patterns = array(
			'/Your (.+) order \(#(\d+)\)/',                      // Customer emails
			'/\[(.+)\] New customer order \(#(\d+)\)/',          // New order admin email
			'/\[(.+)\] Cancelled order \(#(\d+)\)/',             // Cancelled order admin email
			'/\[(.+)\] Failed order \(#(\d+)\)/',                // Failed order admin email
			'/Order #(\d+) details/',                            // Invoice emails
			'/Note added to your order #(\d+)/',                 // Customer note
			'/\[(.+)\] Order #(\d+)/',                           // Generic admin pattern
			'/Order (\d+) \-/',                                  // Alternative order pattern
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $subject, $matches ) ) {
				$is_wc_email = true;
				// Extract order ID from the match - try different capture groups
				if ( isset( $matches[2] ) && is_numeric( $matches[2] ) ) {
					$order_id = (int) $matches[2];
				} elseif ( isset( $matches[1] ) && is_numeric( $matches[1] ) ) {
					$order_id = (int) $matches[1];
				}

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

		// More robust admin email detection
		$is_admin_email = $this->is_likely_admin_email( $atts, $subject );
		
		// Debug logging - helps troubleshoot issues
		Logger::log( \sprintf(
			'WCPOS Email Debug: Order #%d, Subject: "%s", To: "%s", Admin Email: %s',
			$order_id,
			$subject,
			$atts['to'],
			$is_admin_email ? 'YES' : 'NO'
		) );

		// Check settings and prevent sending if disabled
		if ( $is_admin_email ) {
			$admin_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
			if ( ! $admin_emails_enabled ) {
				Logger::log( 'WCPOS: Prevented admin email for POS order #' . $order_id );

				return false; // Prevent the email from being sent
			}
		} else {
			$customer_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'customer_emails' );
			if ( ! $customer_emails_enabled ) {
				Logger::log( 'WCPOS: Prevented customer email for POS order #' . $order_id );

				return false; // Prevent the email from being sent
			}
		}

		return $atts;
	}

	/**
	 * Debug function to log all email sends for troubleshooting.
	 * This helps us see exactly what emails are being triggered.
	 *
	 * @param string   $to           Email recipient.
	 * @param string   $subject      Email subject.
	 * @param string   $message      Email message.
	 * @param string   $headers      Email headers.
	 * @param WC_Email $email_object Email object.
	 */
	public function debug_email_sending( $to, $subject, $message, $headers, $email_object = null ): void {
		if ( ! $email_object instanceof WC_Email ) {
			return;
		}

		// Check if this email is related to a POS order
		$order = null;
		if ( isset( $email_object->object ) && $email_object->object instanceof WC_Order ) {
			$order = $email_object->object;
		}

		if ( $this->is_pos_order( $order ) ) {
			Logger::log( \sprintf(
				'WCPOS Email Send Debug: Email ID: %s, Order: #%s, To: %s, Subject: "%s"',
				$email_object->id,
				$order ? $order->get_id() : 'unknown',
				$to,
				$subject
			) );
		}
	}

	/**
	 * Debug function to catch all email-related filter calls.
	 * This helps us see what email hooks are being triggered.
	 */
	public function debug_all_email_filters(): void {
		$hook = current_filter();
		
		// Only log WooCommerce email filters
		if ( 0 === strpos( $hook, 'woocommerce_email_enabled_' ) ||
			 0    === strpos( $hook, 'woocommerce_email_recipient_' ) ) {
			$args  = \func_get_args();
			$order = null;
			
			// Try to extract order from arguments
			foreach ( $args as $arg ) {
				if ( $arg instanceof WC_Order ) {
					$order = $arg;

					break;
				}
			}
			
			// Only log if this is a POS order or if we can't determine the order
			if ( null === $order || $this->is_pos_order( $order ) ) {
				$email_type        = str_replace( array( 'woocommerce_email_enabled_', 'woocommerce_email_recipient_' ), '', $hook );
				$order_id          = $order ? $order->get_id() : 'unknown';
				$order_created_via = $order ? $order->get_created_via() : 'unknown';
				
				Logger::log( \sprintf(
					'WCPOS Email Filter Debug: Hook: %s, Email Type: %s, Order: #%s, Created Via: %s',
					$hook,
					$email_type,
					$order_id,
					$order_created_via
				) );
			}
		}
	}

	/**
	 * Handle order status changes for POS orders.
	 * This bypasses WooCommerce email settings and manually triggers emails based on POS settings.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public function handle_order_status_change( $order_id, $order = null ): void {
		// Get order if not provided
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		// Only handle POS orders
		if ( ! $this->is_pos_order( $order ) ) {
			return;
		}

		$current_hook = current_filter();
		Logger::log( \sprintf(
			'WCPOS Order Status Change: Order #%s, Hook: %s, Status: %s',
			$order->get_id(),
			$current_hook,
			$order->get_status()
		) );

		// Get POS email settings
		$admin_emails_enabled    = (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
		$customer_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'customer_emails' );

		// Map order status change hooks to email types
		$admin_email_triggers = array(
			'woocommerce_order_status_pending_to_processing'   => 'new_order',
			'woocommerce_order_status_pending_to_completed'    => 'new_order',
			'woocommerce_order_status_pending_to_on-hold'      => 'new_order',
			'woocommerce_order_status_failed_to_processing'    => 'new_order',
			'woocommerce_order_status_failed_to_completed'     => 'new_order',
			'woocommerce_order_status_cancelled_to_processing' => 'new_order',
			'woocommerce_order_status_on-hold_to_processing'   => 'new_order',
			'woocommerce_order_status_processing_to_cancelled' => 'cancelled_order',
			'woocommerce_order_status_pending_to_failed'       => 'failed_order',
			'woocommerce_order_status_on-hold_to_cancelled'    => 'cancelled_order',
			'woocommerce_order_status_on-hold_to_failed'       => 'failed_order',
		);

		$customer_email_triggers = array(
			'woocommerce_order_status_pending_to_on-hold'    => 'customer_on_hold_order',
			'woocommerce_order_status_pending_to_processing' => 'customer_processing_order',
			'woocommerce_order_status_pending_to_completed'  => 'customer_completed_order',
			'woocommerce_order_status_failed_to_processing'  => 'customer_processing_order',
			'woocommerce_order_status_failed_to_completed'   => 'customer_completed_order',
			'woocommerce_order_status_on-hold_to_processing' => 'customer_processing_order',
		);

		// Handle admin emails
		if ( $admin_emails_enabled && isset( $admin_email_triggers[ $current_hook ] ) ) {
			$this->force_send_admin_email( $admin_email_triggers[ $current_hook ], $order );
		} elseif ( ! $admin_emails_enabled && isset( $admin_email_triggers[ $current_hook ] ) ) {
			// Block default admin emails if POS setting is disabled
			$this->block_default_admin_email( $admin_email_triggers[ $current_hook ], $order );
		}

		// Handle customer emails
		if ( $customer_emails_enabled && isset( $customer_email_triggers[ $current_hook ] ) ) {
			$this->force_send_customer_email( $customer_email_triggers[ $current_hook ], $order );
		} elseif ( ! $customer_emails_enabled && isset( $customer_email_triggers[ $current_hook ] ) ) {
			// Block default customer emails if POS setting is disabled
			$this->block_default_customer_email( $customer_email_triggers[ $current_hook ], $order );
		}
	}

	/**
	 * Force send an admin email for POS orders, bypassing WooCommerce settings.
	 *
	 * @param string   $email_type Email type (new_order, cancelled_order, etc.).
	 * @param WC_Order $order      Order object.
	 */
	private function force_send_admin_email( $email_type, $order ): void {
		$emails     = WC()->mailer()->get_emails();
		$class_name = 'WC_Email_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $email_type ) ) );

		if ( ! isset( $emails[ $class_name ] ) ) {
			Logger::log( \sprintf( 'WCPOS: Admin email class not found: %s', $class_name ) );

			return;
		}

		$email            = $emails[ $class_name ];
		$original_enabled = $email->is_enabled();

		Logger::log( \sprintf(
			'WCPOS Force Admin Email: Order #%s, Email Type: %s, WC Enabled: %s, Forcing Send',
			$order->get_id(),
			$email_type,
			$original_enabled ? 'YES' : 'NO'
		) );

		// Temporarily enable the email if it's disabled
		if ( ! $original_enabled ) {
			$email->enabled = 'yes';
		}

		// Send the email
		try {
			$email->trigger( $order->get_id(), $order );
			Logger::log( \sprintf( 'WCPOS: Successfully sent admin email %s for order #%s', $email_type, $order->get_id() ) );
		} catch ( Exception $e ) {
			Logger::log( \sprintf( 'WCPOS: Failed to send admin email %s for order #%s: %s', $email_type, $order->get_id(), $e->getMessage() ) );
		}

		// Restore original enabled state
		$email->enabled = $original_enabled ? 'yes' : 'no';
	}

	/**
	 * Force send a customer email for POS orders, bypassing WooCommerce settings.
	 *
	 * @param string   $email_type Email type (customer_processing_order, etc.).
	 * @param WC_Order $order      Order object.
	 */
	private function force_send_customer_email( $email_type, $order ): void {
		$emails     = WC()->mailer()->get_emails();
		$class_name = 'WC_Email_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $email_type ) ) );

		if ( ! isset( $emails[ $class_name ] ) ) {
			Logger::log( \sprintf( 'WCPOS: Customer email class not found: %s', $class_name ) );

			return;
		}

		$email            = $emails[ $class_name ];
		$original_enabled = $email->is_enabled();

		Logger::log( \sprintf(
			'WCPOS Force Customer Email: Order #%s, Email Type: %s, WC Enabled: %s, Forcing Send',
			$order->get_id(),
			$email_type,
			$original_enabled ? 'YES' : 'NO'
		) );

		// Temporarily enable the email if it's disabled
		if ( ! $original_enabled ) {
			$email->enabled = 'yes';
		}

		// Send the email
		try {
			$email->trigger( $order->get_id(), $order );
			Logger::log( \sprintf( 'WCPOS: Successfully sent customer email %s for order #%s', $email_type, $order->get_id() ) );
		} catch ( Exception $e ) {
			Logger::log( \sprintf( 'WCPOS: Failed to send customer email %s for order #%s: %s', $email_type, $order->get_id(), $e->getMessage() ) );
		}

		// Restore original enabled state
		$email->enabled = $original_enabled ? 'yes' : 'no';
	}

	/**
	 * Block default admin email for POS orders when POS setting is disabled.
	 *
	 * @param string   $email_type Email type (new_order, cancelled_order, etc.).
	 * @param WC_Order $order      Order object.
	 */
	private function block_default_admin_email( $email_type, $order ): void {
		$emails     = WC()->mailer()->get_emails();
		$class_name = 'WC_Email_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $email_type ) ) );

		if ( ! isset( $emails[ $class_name ] ) ) {
			return;
		}

		$email            = $emails[ $class_name ];
		$original_enabled = $email->is_enabled();

		Logger::log( \sprintf(
			'WCPOS Block Admin Email: Order #%s, Email Type: %s, WC Enabled: %s, POS Setting: DISABLED - Blocking',
			$order->get_id(),
			$email_type,
			$original_enabled ? 'YES' : 'NO'
		) );

		// Temporarily disable the email to prevent default sending
		$email->enabled = 'no';

		// Re-enable after a short delay to restore original state
		add_action( 'shutdown', function() use ( $email, $original_enabled ): void {
			$email->enabled = $original_enabled ? 'yes' : 'no';
		} );
	}

	/**
	 * Block default customer email for POS orders when POS setting is disabled.
	 *
	 * @param string   $email_type Email type (customer_processing_order, etc.).
	 * @param WC_Order $order      Order object.
	 */
	private function block_default_customer_email( $email_type, $order ): void {
		$emails     = WC()->mailer()->get_emails();
		$class_name = 'WC_Email_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $email_type ) ) );

		if ( ! isset( $emails[ $class_name ] ) ) {
			return;
		}

		$email            = $emails[ $class_name ];
		$original_enabled = $email->is_enabled();

		Logger::log( \sprintf(
			'WCPOS Block Customer Email: Order #%s, Email Type: %s, WC Enabled: %s, POS Setting: DISABLED - Blocking',
			$order->get_id(),
			$email_type,
			$original_enabled ? 'YES' : 'NO'
		) );

		// Temporarily disable the email to prevent default sending
		$email->enabled = 'no';

		// Re-enable after a short delay to restore original state
		add_action( 'shutdown', function() use ( $email, $original_enabled ): void {
			$email->enabled = $original_enabled ? 'yes' : 'no';
		} );
	}

	/**
	 * Determine if an email is likely an admin email based on various factors.
	 *
	 * @param array  $email_args Email arguments from wp_mail.
	 * @param string $subject    Email subject line.
	 *
	 * @return bool True if this looks like an admin email.
	 */
	private function is_likely_admin_email( $email_args, $subject ) {
		$to = $email_args['to'];
		
		// Check if it's going to the main admin email
		$admin_email = get_option( 'admin_email' );
		if ( $to === $admin_email ) {
			return true;
		}
		
		// Check if it's going to any WooCommerce admin email addresses
		$wc_admin_emails = array(
			get_option( 'woocommerce_stock_email_recipient' ),
			get_option( 'admin_email' ),
		);
		
		if ( \in_array( $to, $wc_admin_emails, true ) ) {
			return true;
		}
		
		// Check subject patterns that indicate admin emails
		$admin_subject_patterns = array(
			'/^\[.*\]\s+(New|Cancelled|Failed)\s+.*(order|customer)/i',
			'/^\[.*\]\s+Order\s+#\d+/i',
		);
		
		foreach ( $admin_subject_patterns as $pattern ) {
			if ( preg_match( $pattern, $subject ) ) {
				return true;
			}
		}
		
		// Check if subject starts with [site_name] pattern (common for admin emails)
		$site_name = get_bloginfo( 'name' );
		if ( $site_name && 0 === strpos( $subject, '[' . $site_name . ']' ) ) {
			return true;
		}
		
		return false;
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
		);

		// Customer emails - these go to customers
		$customer_emails = array(
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
			'customer_invoice',
			'customer_note',
			'reset_password',     // This is a customer email, not admin
			'new_account',        // This is a customer email, not admin
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

		// CRITICAL: Hook directly into order status changes to bypass WooCommerce email settings
		// These hooks fire regardless of whether WooCommerce emails are enabled/disabled
		add_action( 'woocommerce_order_status_pending_to_processing', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_pending_to_completed', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_pending_to_on-hold', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_failed_to_processing', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_failed_to_completed', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_cancelled_to_processing', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_processing_to_cancelled', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_pending_to_failed', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_on-hold_to_cancelled', array( $this, 'handle_order_status_change' ), 5, 2 );
		add_action( 'woocommerce_order_status_on-hold_to_failed', array( $this, 'handle_order_status_change' ), 5, 2 );

		// Ultimate failsafe - use wp_mail filter to prevent sending at the last moment
		add_filter( 'wp_mail', array( $this, 'prevent_disabled_pos_emails' ), 999, 1 );

		// Add action to log all email sends for debugging (can be removed after troubleshooting)
		add_action( 'woocommerce_email_send_before', array( $this, 'debug_email_sending' ), 1, 4 );

		// Add global debugging to catch all email filter calls
		add_action( 'all', array( $this, 'debug_all_email_filters' ), 1 );
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
