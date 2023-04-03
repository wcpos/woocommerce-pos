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

use WC_Order;

class Orders {
	public function __construct() {
		$this->register_order_status();
		add_filter( 'wc_order_statuses', array( $this, 'wc_order_statuses' ), 10, 1 );
		add_filter('woocommerce_valid_order_statuses_for_payment', array(
			$this,
			'valid_order_statuses_for_payment',
		), 10, 2);
		add_filter('woocommerce_valid_order_statuses_for_payment_complete', array(
			$this,
			'valid_order_statuses_for_payment_complete',
		), 10, 2);
		add_filter('woocommerce_payment_complete_order_status', array(
			$this,
			'payment_complete_order_status',
		), 10, 3);

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
		$order_statuses['wc-pos-open'] = _x( 'POS - Open', 'Order status', 'woocommerce-pos' );
		$order_statuses['wc-pos-partial'] = _x( 'POS - Partial Payment', 'Order status', 'woocommerce-pos' );

		return $order_statuses;
	}

	/**
	 * Note: the wc- prefix is not used here because it is added by WooCommerce.
	 *
	 * @param array    $order_statuses
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public function valid_order_statuses_for_payment( array $order_statuses, WC_Order $order ): array {
		$order_statuses[] = 'pos-open';
		$order_statuses[] = 'pos-partial';

		return $order_statuses;
	}

	/**
	 * @param array    $order_statuses
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public function valid_order_statuses_for_payment_complete( array $order_statuses, WC_Order $order ): array {
		$order_statuses[] = 'pos-open';
		$order_statuses[] = 'pos-partial';

		return $order_statuses;
	}

	/**
	 * @param string $status
	 * @param int $id
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function payment_complete_order_status( string $status, int $id, WC_Order $order ): string {
		if ( woocommerce_pos_request() ) {
			return woocommerce_pos_get_settings( 'checkout', 'order_status' );
		}

		return $status;
	}


	private function register_order_status(): void {
		// Order status for open orders
		register_post_status('wc-pos-open', array(
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
		));

		// Order status for partial payment orders
		register_post_status('wc-pos-partial', array(
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
		));
	}

	public function manage_admin_emails( $enabled, $order, $email_class ) {
		if ( ! woocommerce_pos_request() ) {
			return $enabled;
		}

		return woocommerce_pos_get_settings( 'checkout', 'admin_emails' );
	}

	public function manage_customer_emails( $enabled, $order, $email_class ) {
		if ( ! woocommerce_pos_request() ) {
			return $enabled;
		}

		return woocommerce_pos_get_settings( 'checkout', 'customer_emails' );
	}
}
