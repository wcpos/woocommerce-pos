<?php
/**
 *
 *
 * @package    WCPOS\Templates
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use Exception;

class Templates {

	/** @var string POS frontend slug */
	private $pos_slug;

	/** @var string POS checkout slug */
	private $pos_checkout_slug;

	/** @var string regex match for frontend rewite_rule */
	private $pos_rewrite_regex;

	/** @var string regex match for checkout rewite_rule */
	private $pos_checkout_rewrite_regex;

	/** @var WCPOS_Params instance */
	public $params;

	/**
	 *
	 */
	public function __construct() {
//		$this->pos_slug          = Admin\Permalink::get_slug();
		$this->pos_slug                   = 'wcpos';
		$this->pos_rewrite_regex          = '^' . $this->pos_slug . '/?$';
		$this->pos_checkout_slug          = 'wcpos-checkout';
		$this->pos_checkout_rewrite_regex = '^' . $this->pos_checkout_slug . '/([0-9]+)[/]?$';

		add_rewrite_tag( '%wcpos%', '([^&]+)' );
		add_rewrite_rule( $this->pos_rewrite_regex, 'index.php?wcpos=1', 'top' );
		add_rewrite_rule( $this->pos_checkout_rewrite_regex, 'index.php?order-pay=$matches[1]', 'top' );
		add_filter( 'option_rewrite_rules', array( $this, 'rewrite_rules' ), 1 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );
	}

	/**
	 * Make sure cache contains POS rewrite rules
	 *
	 * @param $rules
	 *
	 * @return array | bool
	 */
	public function rewrite_rules( $rules ) {
		return isset( $rules[ $this->pos_rewrite_regex ] ) && isset( $rules[ $this->pos_checkout_rewrite_regex ] ) ? $rules : false;
	}

	/**
	 * Output the POS template
	 */
	public function template_redirect() {
		global $wp_query, $wp;

		// check is pos
		if ( ! woocommerce_pos_request( 'query_var' ) ) {
			return;
		}

		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$this->pos_checkout_template( $wp->query_vars['order-pay'] );
		} else {
			$this->pos_frontend_template();
		}

		exit;
	}

	/**
	 *
	 */
	private function pos_frontend_template() {
		// force ssl
		if ( ! is_ssl() ) {
			wp_safe_redirect( woocommerce_pos_url() );
			exit;
		}

		// check auth
		if ( ! is_user_logged_in() ) {
			add_filter( 'login_url', array( $this, 'login_url' ) );
			auth_redirect();
		}

		// check privileges
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) /* translators: wordpress */ {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		// disable cache plugins
		$this->no_cache();

		// last chance before template is rendered
		do_action( 'woocommerce_pos_template_redirect' );

		// add head & footer actions
		add_action( 'woocommerce_pos_head', array( $this, 'head' ) );
		add_action( 'woocommerce_pos_footer', array( $this, 'footer' ) );

		include woocommerce_pos_locate_template( 'pos.php' );
	}

	/**
	 *
	 */
	private function pos_checkout_template( $order_id ) {
		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		do_action( 'woocommerce_pos_before_pay' );

		$order_id = absint( $order_id );

		try {
			// get order
			$order = wc_get_order( $order_id );

			// Order or payment link is invalid.
			if ( ! $order || $order->get_id() !== $order_id ) {
				throw new Exception( __( 'Sorry, this order is invalid and cannot be paid for.', 'woocommerce' ) );
			}

			// set customer
//			wp_set_current_user( $order->get_customer_id() );

			// Logged in customer trying to pay for someone else's order.
			if ( ! current_user_can( 'pay_for_order', $order_id ) ) {
				throw new Exception( __( 'This order cannot be paid for. Please contact us if you need assistance.', 'woocommerce' ) );
			}

			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

			if ( count( $available_gateways ) ) {
				current( $available_gateways )->set_current();
			}

			include woocommerce_pos_locate_template( 'checkout.php' );

		} catch ( Exception $e ) {
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Add variable to login url to signify POS login
	 *
	 * @param $login_url
	 *
	 * @return mixed
	 */
	public function login_url( $login_url ) {
		return add_query_arg( SHORT_NAME, '1', $login_url );
	}

	/**
	 * Disable caching conflicts
	 */
	private function no_cache() {
		// disable W3 Total Cache minify
		if ( ! defined( 'DONOTMINIFY' ) ) {
			define( "DONOTMINIFY", "true" );
		}

		// disable WP Super Cache
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( "DONOTCACHEPAGE", "true" );
		}
	}

	/**
	 * Output the head scripts
	 */
	public function head() {

	}

	/**
	 * Output the footer scripts
	 */
	public function footer() {

	}
}
