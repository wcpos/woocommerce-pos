<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Settings;
use function define;
use function defined;

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
        $this->check_troubleshooting_form_submission();
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

		add_action( 'wp_enqueue_scripts', array( $this, 'remove_scripts_and_styles' ), 100 );

		add_filter( 'option_woocommerce_tax_display_cart', array( $this, 'tax_display_cart' ), 10, 2 );
	}

	/**
	 * Each theme will apply its own styles to the checkout page.
	 * I want to keep it simple, so we remove all styles and scripts associated with the active theme.
	 * NOTE: This is not perfect, we don't know the theme handle, so we just take a guess from the source URL.
	 *
	 * @return void
	 */
    /**
     * Remove enqueued scripts and styles.
     *
     * This function dequeues all scripts and styles that are not specified in the WooCommerce POS settings,
     * unless they are specifically included by the 'woocommerce_pos_payment_template_dequeue_script_handles'
     * and 'woocommerce_pos_payment_template_dequeue_style_handles' filters.
     *
     * @since 1.3.0
     */
    public function remove_scripts_and_styles(): void {
        global $wp_styles, $wp_scripts;

        /**
         * List of script handles to exclude from the payment template.
         *
         * @since 1.3.0
         */
        $script_exclude_list = apply_filters(
            'woocommerce_pos_payment_template_dequeue_script_handles',
            woocommerce_pos_get_settings( 'checkout', 'dequeue_script_handles' )
        );

        /**
         * List of style handles to exclude from the payment template.
         *
         * @since 1.3.0
         */
        $style_exclude_list = apply_filters(
            'woocommerce_pos_payment_template_dequeue_style_handles',
            woocommerce_pos_get_settings( 'checkout', 'dequeue_style_handles' )
        );

        // Loop through all enqueued styles and dequeue those that are in the exclusion list
        if ( is_array( $style_exclude_list ) ) {
            foreach ( $wp_styles->queue as $handle ) {
                if ( in_array( $handle, $style_exclude_list ) ) {
                    wp_dequeue_style( $handle );
                }
            }
        }

        // Loop through all enqueued scripts and dequeue those that are in the exclusion list
        if ( is_array( $script_exclude_list ) ) {
            foreach ( $wp_scripts->queue as $handle ) {
                if ( in_array( $handle, $script_exclude_list ) ) {
                    wp_dequeue_script( $handle );
                }
            }
        }
    }


    /**
     * @return void
     */
    private function check_troubleshooting_form_submission() {
        // Check if our form has been submitted
        if ( isset( $_POST['troubleshooting_form_nonce'] ) ) {
            // Verify the nonce
            if ( ! wp_verify_nonce( $_POST['troubleshooting_form_nonce'], 'troubleshooting_form_nonce' ) ) {
                // Nonce doesn't verify, we should stop execution here
                die( 'Nonce value cannot be verified.' );
            }

            // This will hold your sanitized data
            $sanitized_data = array();

            // Sanitize all_styles array
            if ( isset( $_POST['all_styles'] ) && is_array( $_POST['all_styles'] ) ) {
                $sanitized_data['all_styles'] = array_map( 'sanitize_text_field', $_POST['all_styles'] );
            }

            // Sanitize styles array
            if ( isset( $_POST['styles'] ) && is_array( $_POST['styles'] ) ) {
                $sanitized_data['styles'] = array_map( 'sanitize_text_field', $_POST['styles'] );
            } else {
                $sanitized_data['styles'] = array();  // consider all styles unchecked if 'styles' is not submitted
            }

            // Sanitize all_scripts array
            if ( isset( $_POST['all_scripts'] ) && is_array( $_POST['all_scripts'] ) ) {
                $sanitized_data['all_scripts'] = array_map( 'sanitize_text_field', $_POST['all_scripts'] );
            }

            // Sanitize scripts array
            if ( isset( $_POST['scripts'] ) && is_array( $_POST['scripts'] ) ) {
                $sanitized_data['scripts'] = array_map( 'sanitize_text_field', $_POST['scripts'] );
            } else {
                $sanitized_data['scripts'] = array();  // consider all scripts unchecked if 'scripts' is not submitted
            }

            // Calculate unchecked styles and scripts
            $unchecked_styles = isset( $sanitized_data['all_styles'] ) ? array_diff( $sanitized_data['all_styles'], $sanitized_data['styles'] ) : array();
            $unchecked_scripts = isset( $sanitized_data['all_scripts'] ) ? array_diff( $sanitized_data['all_scripts'], $sanitized_data['scripts'] ) : array();

            // @TODO - the save settings function should allow saving by key
            $settings = new Settings();
            $checkout_settings = $settings->get_checkout_settings();
            $new_settings = array_merge(
                $checkout_settings,
                array(
                    'dequeue_style_handles' => $unchecked_styles,
                    'dequeue_script_handles' => $unchecked_scripts,
                )
            );
            $settings->save_settings( 'checkout', $new_settings );
        }
    }


    /**
     * @return void
     */
    public function get_template(): void {
		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		//      if ( ! $this->gateway_id ) {
		//          wp_die( esc_html__( 'No gateway selected', 'woocommerce-pos' ) );
		//      }

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

			// get cashier from order meta_data with key _pos_user
			$cashier = $order->get_meta( '_pos_user', true );
			$cashier = get_user_by( 'id', $cashier );

			// create nonce for cashier to apply coupons
			$coupon_nonce = wp_create_nonce( 'pos_coupon_action' );
            $troubleshooting_form_nonce = wp_create_nonce( 'troubleshooting_form_nonce' );

			/**
			 * The wp_set_current_user() function changes the global user object but it does not authenticate the user
			 * for the current session. This means that it will not affect nonce creation or validation because WordPress
			 * nonces are tied to the user's session.
			 *
			 * @TODO - is this the best way to do this?
			 */
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

			//          if ( isset( $available_gateways[ $this->gateway_id ] ) ) {
			//              $gateway         = $available_gateways[ $this->gateway_id ];
			//              $gateway->chosen = true;
			//          }

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

	/**
	 * Filters the value of the woocommerce_tax_display_cart option.
	 * The POS is always exclusive of tax, so we show the same for the payments page to avoid confusion.
	 *
	 * @param mixed  $value  Value of the option.
	 * @param string $option Option name.
	 */
	public function tax_display_cart( $value, $option ): string {
		return 'excl';
	}
}
