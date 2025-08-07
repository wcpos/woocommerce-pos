<?php
/**
 * WCPOS Emails Class
 * Handles email management for POS orders.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WC_Email;
use WC_Order;

/**
 * Emails Class
 * - manages email sending for POS orders.
 */
class Emails {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// Get filterable email arrays - allow users to customize which emails are affected
		$admin_emails = apply_filters( 'woocommerce_pos_admin_emails', array(
			'cancelled_order',
			'failed_order',
		) );

		$customer_emails = apply_filters( 'woocommerce_pos_customer_emails', array(
			'customer_failed_order',
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
		) );

		// Hook into email enabled filters - this is the main control mechanism
		foreach ( $admin_emails as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", array( $this, 'manage_admin_emails' ), 999, 3 );
		}
		foreach ( $customer_emails as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", array( $this, 'manage_customer_emails' ), 999, 3 );
		}

		// Manually trigger new_order email for POS status changes
		// WooCommerce doesn't automatically trigger new_order for pos-open/pos-partial transitions
		add_action( 'woocommerce_order_status_pos-open_to_completed', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-open_to_processing', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-open_to_on-hold', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-partial_to_completed', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-partial_to_processing', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-partial_to_on-hold', array( $this, 'trigger_new_order_email' ), 10, 2 );
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
		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return $enabled;
		}

		// Get email ID for filtering
		$email_id = $email_class instanceof WC_Email ? $email_class->id : 'unknown';

		// Get POS admin email setting
		$admin_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );



		// Allow final filtering of the email enabled status
		return apply_filters( 'woocommerce_pos_admin_email_enabled', $admin_emails_enabled, $email_id, $order, $email_class );
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
		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return $enabled;
		}

		// Get email ID for filtering
		$email_id = $email_class instanceof WC_Email ? $email_class->id : 'unknown';

		// Get POS customer email setting
		$customer_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'customer_emails' );



		// Allow final filtering of the email enabled status
		return apply_filters( 'woocommerce_pos_customer_email_enabled', $customer_emails_enabled, $email_id, $order, $email_class );
	}

	/**
	 * Manually trigger new_order admin email for POS orders.
	 * This is needed because WooCommerce doesn't automatically trigger new_order
	 * for pos-open/pos-partial status transitions.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public function trigger_new_order_email( $order_id, $order = null ): void {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return;
		}

		// Check if admin emails are enabled
		$admin_emails_enabled = (bool) woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
		if ( ! $admin_emails_enabled ) {
			return;
		}

		// Get the new_order email by ID, not class name
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		foreach ( $emails as $email ) {
			if ( 'new_order' === $email->id ) {
				// Temporarily enable the email to ensure it sends
				$original_enabled = $email->enabled;
				$email->enabled   = 'yes';
				
				// Trigger the email
				$email->trigger( $order_id, $order );
				
				// Restore original state
				$email->enabled = $original_enabled;

				break;
			}
		}
	}
}
