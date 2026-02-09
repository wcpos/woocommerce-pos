<?php
/**
 * WCPOS Emails Class
 * Handles email management for POS orders.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
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
		// Get filterable email arrays - allow users to customize which emails are affected.
		$admin_emails = apply_filters(
			'woocommerce_pos_admin_emails',
			array(
				'cancelled_order',
				'failed_order',
			)
		);

		$customer_emails = apply_filters(
			'woocommerce_pos_customer_emails',
			array(
				'customer_failed_order',
				'customer_on_hold_order',
				'customer_processing_order',
				'customer_completed_order',
				'customer_refunded_order',
			)
		);

		// Hook into email enabled filters - this is the main control mechanism.
		foreach ( $admin_emails as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", array( $this, 'manage_admin_emails' ), 999, 3 );
		}
		foreach ( $customer_emails as $email_id ) {
			add_filter( "woocommerce_email_enabled_{$email_id}", array( $this, 'manage_customer_emails' ), 999, 3 );
		}

		// Control new_order recipients (admin + cashier) via the recipient filter.
		add_filter( 'woocommerce_email_recipient_new_order', array( $this, 'filter_new_order_recipients' ), 10, 3 );

		// Manually trigger new_order email for POS status changes.
		// WooCommerce doesn't automatically trigger new_order for pos-open/pos-partial transitions.
		add_action( 'woocommerce_order_status_pos-open_to_completed', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-open_to_processing', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-open_to_on-hold', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-partial_to_completed', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-partial_to_processing', array( $this, 'trigger_new_order_email' ), 10, 2 );
		add_action( 'woocommerce_order_status_pos-partial_to_on-hold', array( $this, 'trigger_new_order_email' ), 10, 2 );
	}

	/**
	 * Manage admin email sending for POS orders.
	 * Handles cancelled_order and failed_order via the enabled filter.
	 * Note: new_order is handled separately via filter_new_order_recipients.
	 *
	 * @param bool           $enabled     Whether the email is enabled.
	 * @param null|WC_Order  $order       The order object.
	 * @param mixed|WC_Email $email_class The email class.
	 *
	 * @return bool Whether the email should be sent.
	 */
	public function manage_admin_emails( $enabled, $order, $email_class ) {
		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return $enabled;
		}

		$email_id = $email_class instanceof WC_Email ? $email_class->id : 'unknown';
		$settings = woocommerce_pos_get_settings( 'checkout', 'admin_emails' );

		// Master toggle off = all disabled.
		if ( empty( $settings['enabled'] ) ) {
			$is_enabled = false;
		} else {
			// Individual toggle (default true if key not set).
			$is_enabled = $settings[ $email_id ] ?? true;
		}

		/**
		 * Filters whether a specific admin email is enabled for a POS order.
		 *
		 * @since 1.4.12
		 *
		 * @param bool     $is_enabled  Whether the email is enabled.
		 * @param string   $email_id    The WooCommerce email ID.
		 * @param WC_Order $order       The order object.
		 * @param WC_Email $email_class The email class instance.
		 */
		return apply_filters( 'woocommerce_pos_admin_email_enabled', $is_enabled, $email_id, $order, $email_class );
	}

	/**
	 * Manage customer email sending for POS orders.
	 *
	 * @param bool           $enabled     Whether the email is enabled.
	 * @param null|WC_Order  $order       The order object.
	 * @param mixed|WC_Email $email_class The email class.
	 *
	 * @return bool Whether the email should be sent.
	 */
	public function manage_customer_emails( $enabled, $order, $email_class ) {
		if ( ! woocommerce_pos_is_pos_order( $order ) ) {
			return $enabled;
		}

		$email_id = $email_class instanceof WC_Email ? $email_class->id : 'unknown';
		$settings = woocommerce_pos_get_settings( 'checkout', 'customer_emails' );

		// Master toggle off = all disabled.
		if ( empty( $settings['enabled'] ) ) {
			$is_enabled = false;
		} else {
			// Individual toggle (default true if key not set).
			$is_enabled = $settings[ $email_id ] ?? true;
		}

		/**
		 * Filters whether a specific customer email is enabled for a POS order.
		 *
		 * @since 1.4.12
		 *
		 * @param bool     $is_enabled  Whether the email is enabled.
		 * @param string   $email_id    The WooCommerce email ID.
		 * @param WC_Order $order       The order object.
		 * @param WC_Email $email_class The email class instance.
		 */
		return apply_filters( 'woocommerce_pos_customer_email_enabled', $is_enabled, $email_id, $order, $email_class );
	}

	/**
	 * Filter new_order email recipients for POS orders.
	 *
	 * Builds the recipient list based on admin and cashier email settings.
	 * Uses the recipient filter (not the enabled filter) so that admin and
	 * cashier toggles can work independently.
	 *
	 * @param string        $recipient Comma-separated recipient emails.
	 * @param null|WC_Order $order     The order object.
	 * @param null|WC_Email $email     The email class instance.
	 *
	 * @return string Filtered comma-separated recipient emails.
	 */
	public function filter_new_order_recipients( $recipient, $order = null, $email = null ) {
		if ( ! $order instanceof WC_Order || ! woocommerce_pos_is_pos_order( $order ) ) {
			return $recipient;
		}

		$admin_settings   = woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
		$cashier_settings = woocommerce_pos_get_settings( 'checkout', 'cashier_emails' );

		$admin_wants_new_order = ! empty( $admin_settings['enabled'] )
			&& ( $admin_settings['new_order'] ?? true );

		// If admin doesn't want new_order, clear the default recipient list.
		if ( ! $admin_wants_new_order ) {
			$recipient = '';
		}

		// Check if cashier should receive the email.
		$cashier_wants_new_order = ! empty( $cashier_settings['enabled'] )
			&& ( $cashier_settings['new_order'] ?? true );

		if ( $cashier_wants_new_order ) {
			$cashier_email = $this->get_cashier_email( $order );

			if ( $cashier_email ) {
				// Dedup: don't add if already in the recipient list.
				$existing = array_map( 'trim', explode( ',', $recipient ) );
				$existing = array_filter( $existing );

				if ( ! \in_array( $cashier_email, $existing, true ) ) {
					$existing[] = $cashier_email;
				}

				$recipient = implode( ', ', $existing );
			}
		}

		return $recipient;
	}

	/**
	 * Manually trigger new_order admin email for POS orders.
	 *
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

		// Check if anyone wants the new_order email.
		$admin_settings   = woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
		$cashier_settings = woocommerce_pos_get_settings( 'checkout', 'cashier_emails' );

		$admin_wants = ! empty( $admin_settings['enabled'] )
			&& ( $admin_settings['new_order'] ?? true );
		$cashier_wants = ! empty( $cashier_settings['enabled'] )
			&& ( $cashier_settings['new_order'] ?? true );

		if ( ! $admin_wants && ! $cashier_wants ) {
			return;
		}

		// Get the new_order email by ID, not class name.
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		foreach ( $emails as $email ) {
			if ( 'new_order' === $email->id ) {
				// Temporarily enable the email to ensure it sends.
				$original_enabled = $email->enabled;
				$email->enabled   = 'yes';

				// Trigger the email.
				// @phpstan-ignore-next-line.
				$email->trigger( $order_id, $order );

				// Restore original state.
				$email->enabled = $original_enabled;

				break;
			}
		}
	}

	/**
	 * Get the cashier's email address from the order.
	 *
	 * @param WC_Order $order The order object.
	 *
	 * @return string The cashier email address, or empty string if not found.
	 */
	private function get_cashier_email( WC_Order $order ): string {
		$cashier_id = $order->get_meta( '_pos_user' );

		if ( empty( $cashier_id ) ) {
			return '';
		}

		$user = get_user_by( 'id', $cashier_id );

		if ( ! $user || empty( $user->user_email ) ) {
			return '';
		}

		return $user->user_email;
	}
}
