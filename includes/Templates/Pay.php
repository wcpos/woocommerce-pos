<?php
/**
 *
 *
 * @package    WCPOS\WooCommercePOS\Templates\Pay
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;

class Pay {

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
		$this->gateway_id = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';

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
	 *
	 */
	public function remove_scripts() {
		global $wp_styles, $wp_scripts;

		// by default allow any styles and scripts from woocommerce and gateway plugins
		$allow = array( 'woocommerce', 'wc-', $this->gateway_id );

		foreach ( $wp_styles->queue as $style ) :
			$keep = false;
			// @TODO - add_filter styles for $allow
			foreach ( $allow as $string ) :
				if ( strpos( $style, $string ) !== false ) {
					$keep = true;
					continue;
				}
			endforeach;
			if ( ! $keep ) {
				wp_dequeue_style( $style );
			}
		endforeach;

		foreach ( $wp_scripts->queue as $script ) :
			$keep = false;
			// @TODO - add_filter scripts for $allow
			foreach ( $allow as $string ) :
				if ( strpos( $script, $string ) !== false ) {
					$keep = true;
					continue;
				}
			endforeach;
			if ( ! $keep ) {
				wp_dequeue_script( $script );
			}
		endforeach;
	}

	/**
	 *
	 */
	public function get_template() {
		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		if ( ! $this->gateway_id ) {
			wp_die( __( 'No gateway selected', 'woocommerce-pos' ) );
		}

		do_action( 'woocommerce_pos_before_pay' );

		try {
			// get order
			$order = wc_get_order( $this->order_id );

			// Order or payment link is invalid.
			if ( ! $order || $order->get_id() !== $this->order_id ) {
				throw new Exception( __( 'Sorry, this order is invalid and cannot be paid for.', 'woocommerce' ) );
			}

			// set customer
			wp_set_current_user( $order->get_customer_id() );

			// Logged in customer trying to pay for someone else's order.
			if ( ! current_user_can( 'pay_for_order', $this->order_id ) ) {
				throw new Exception( __( 'This order cannot be paid for. Please contact us if you need assistance.', 'woocommerce' ) );
			}

			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

			if ( isset( $available_gateways[ $this->gateway_id ] ) ) {
				$gateway         = $available_gateways[ $this->gateway_id ];
				$gateway->chosen = true;
			}

			include woocommerce_pos_locate_template( 'pay.php' );

		} catch ( Exception $e ) {
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}
}
