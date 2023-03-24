<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WC_Order;
use WCPOS\WooCommercePOS\Templates\Frontend;

/**
 *
 */
class Templates {
	/**
	 * @var string POS frontend slug
	 */
	private $pos_slug;

	/**
	 * @var string POS checkout slug
	 * @note 'wcpos-checkout' slug is used instead 'checkout' to avoid conflicts with WC checkout
	 * eg: x-frame-options: SAMEORIGIN
	 */
	private $pos_checkout_slug;

	/**
	 * @var string regex match for frontend rewite_rule
	 */
	private $pos_rewrite_regex;

	/**
	 * @var string regex match for checkout rewite_rule
	 */
	private $pos_checkout_rewrite_regex;


	public function __construct() {
		$this->pos_slug                   = Admin\Permalink::get_slug();
		$this->pos_rewrite_regex          = '^' . $this->pos_slug . '/?';
		$this->pos_checkout_slug          = 'wcpos-checkout';
		$this->pos_checkout_rewrite_regex = '^' . $this->pos_checkout_slug . '/([a-z-]+)/([0-9]+)[/]?$';

		// Note: 'order-pay' and 'order-received' rewrite tags are added by WC
		add_rewrite_tag( '%wcpos%', '([^&]+)' );
		add_rewrite_tag( '%wcpos-receipt%', '([^&]+)' );
		add_rewrite_rule( $this->pos_rewrite_regex, 'index.php?wcpos=1', 'top' );
		add_rewrite_rule( $this->pos_checkout_rewrite_regex, 'index.php?$matches[1]=$matches[2]&wcpos=1', 'top' );
		add_filter( 'option_rewrite_rules', array( $this, 'rewrite_rules' ), 1 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );

		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'order_received_url' ), 10, 2 );
	}

	/**
	 * Make sure cache contains POS rewrite rules.
	 *
	 * @param $rules
	 *
	 * @return array|bool
	 */
	public function rewrite_rules( $rules ) {
		return isset( $rules[ $this->pos_rewrite_regex ], $rules[ $this->pos_checkout_rewrite_regex ] ) ? $rules : false;
	}

	/**
	 * Output the matched template.
	 */
	public function template_redirect(): void {
		global $wp;

		// URL matches checkout slug
		if ( $wp->matched_rule == $this->pos_checkout_rewrite_regex ) {
			$classname = null;
			$order_id  = null;

			if ( isset( $wp->query_vars['order-pay'] ) ) {
				$classname = __NAMESPACE__ . '\\Templates\\Pay';
				$order_id  = absint( $wp->query_vars['order-pay'] );
			} elseif ( isset( $wp->query_vars['order-received'] ) ) {
				$classname = __NAMESPACE__ . '\\Templates\\Received';
				$order_id  = absint( $wp->query_vars['order-received'] );
			} elseif ( isset( $wp->query_vars['wcpos-receipt'] ) ) {
				$classname = __NAMESPACE__ . '\\Templates\\Receipt';
				$order_id  = absint( $wp->query_vars['wcpos-receipt'] );
			}

			if ( class_exists( $classname ) && $order_id ) {
				$template = new $classname( $order_id );
				$template->get_template();
			} else {
				wp_die( esc_html__( 'Template not found.', 'woocommerce-pos' ) );
			}
			exit;
		}

		// URL matches pos slug
		if ( $wp->matched_rule == $this->pos_rewrite_regex ) {
			$template = new Frontend();
			$template->get_template();
			exit;
		}
	}

	/**
	 * Just like the checkout/payment.php template, we hijack the order received url so we can display a stripped down
	 * version of the receipt.
	 *
	 * @param string   $order_received_url
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function order_received_url( string $order_received_url, WC_Order $order ): string {
		// check is pos
		if ( ! woocommerce_pos_request() ) {
			return $order_received_url;
		}

		$redirect = add_query_arg(array(
			'key' => $order->get_order_key(),
		), get_home_url( null, '/wcpos-checkout/order-received/' . $order->get_id() ));

		return $redirect;
	}
}
