<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;

class Payment {
	/**
	 * @var int
	 */
	private $order_id;

	/**
	 * @var string
	 */
	private $gateway_id;

	public function __construct( int $order_id ) {
		$this->order_id   = $order_id;
		//$this->gateway_id = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';

		// this is a checkout page
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		// remove the terms and conditions checkbox
		add_filter( 'woocommerce_checkout_show_terms', '__return_false' );

		// remove junk from head
		add_filter( 'show_admin_bar', '__return_false' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'index_rel_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'start_post_rel_link', 10, 0 );
		remove_action( 'wp_head', 'parent_post_rel_link', 10, 0 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link', 10, 0 );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10, 0 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10, 0 );

		add_action( 'wp_enqueue_scripts', array( $this, 'remove_scripts' ), 100 );
	}

	/**
	 * Each theme will apply its own styles to the checkout page.
	 * I want to keep it simple, so we remove all styles and scripts associated with the active theme.
	 * NOTE: This is not perfect, we don't know the theme handle, so we just take a guess from the source URL.
	 *
	 * @return void
	 */
	public function remove_scripts(): void {
		global $wp_styles, $wp_scripts;

		// Exclude list of handles
		// @TODO - this should be a filter
		$exclude_list = array(
			'admin-bar',
			'woocommerce-general',
			'woocommerce-inline',
			'woocommerce-layout',
			'woocommerce-smallscreen',
			'woocommerce-blocktheme',
			'wp-block-library', // are we using blocks?
		);

		// Include list of handles
		// @TODO - this should be a filter
		$include_list = array();

		// Get the active theme's directory
		$active_theme_directory = basename( get_template_directory() );

		// Loop through all enqueued styles
		foreach ( $wp_styles->queue as $handle ) {
			// Skip blacklisted handles
			if ( in_array( $handle, $include_list ) ) {
				continue;
			}

			$src = $wp_styles->registered[ $handle ]->src;

			// Check if the source URL contains the active theme's directory
			if ( strpos( $src, $active_theme_directory ) !== false || in_array( $handle, $exclude_list ) ) {
				wp_dequeue_style( $handle );
			}
		}

		// Loop through all enqueued scripts
		foreach ( $wp_scripts->queue as $handle ) {
			// Skip blacklisted handles
			if ( in_array( $handle, $include_list ) ) {
				continue;
			}

			$src = $wp_scripts->registered[ $handle ]->src;

			// Check if the source URL contains the active theme's directory
			if ( strpos( $src, $active_theme_directory ) !== false || in_array( $handle, $exclude_list ) ) {
				wp_dequeue_style( $handle );
			}
		}

	}


	public function get_template(): void {
		if ( ! \defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			\define( 'WOOCOMMERCE_CHECKOUT', true );
		}

//		if ( ! $this->gateway_id ) {
//			wp_die( esc_html__( 'No gateway selected', 'woocommerce-pos' ) );
//		}

		do_action( 'woocommerce_pos_before_pay' );

		try {
			// get order
			$order = wc_get_order( $this->order_id );

			// Order or payment link is invalid.
			if ( ! $order || $order->get_id() !== $this->order_id ) {
				wp_die( esc_html__( 'Sorry, this order is invalid and cannot be paid for.', 'woocommerce-pos' ) );
			}

			// Order has already been paid for.
			if ( $order->is_paid() ) {
				wp_die( esc_html__( 'Sorry, this order has already been paid for.', 'woocommerce-pos' ) );
			}

			// set customer
			wp_set_current_user( $order->get_customer_id() );

			// create nonce for customer
			//			$nonce_field = '<input type="hidden" id="woocommerce-pay-nonce" name="woocommerce-pay-nonce" value="' . $this->create_customer_nonce() . '" />';

			// Logged in customer trying to pay for someone else's order.
			if ( ! current_user_can( 'pay_for_order', $this->order_id ) ) {
				wp_die( esc_html__( 'This order cannot be paid for. Please contact us if you need assistance.', 'woocommerce-pos' ) );
			}

			// We need to reload the gateways here to use the current customer details.
			WC()->payment_gateways()->init();
			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

//			if ( isset( $available_gateways[ $this->gateway_id ] ) ) {
//				$gateway         = $available_gateways[ $this->gateway_id ];
//				$gateway->chosen = true;
//			}

			$order_button_text = apply_filters( 'woocommerce_pay_order_button_text', __( 'Pay for order', 'woocommerce-pos' ) );

			include woocommerce_pos_locate_template( 'payment.php' );
		} catch ( Exception $e ) {
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Custom version of wp_create_nonce that uses the customer ID.
	 */
	private function create_customer_nonce() {
		$user = wp_get_current_user();
		$uid  = (int) $user->ID;
		//		if ( ! $uid ) {
		//			/** This filter is documented in wp-includes/pluggable.php */
		//			$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		//		}

		$token = '';
		$i     = wp_nonce_tick();

		return substr( wp_hash( $i . '|woocommerce-pay|' . $uid . '|' . $token, 'nonce' ), - 12, 10 );
	}
}
