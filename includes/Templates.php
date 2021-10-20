<?php
/**
 *
 *
 * @package    WCPOS\WooCommercePOS\Templates
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use Exception;
use WC_Order;
use WCPOS\WooCommercePOS\Templates\Frontend;
use WCPOS\WooCommercePOS\Templates\Pay;

class Templates {

	/** @var WCPOS_Params instance */
	public $params;
	/** @var string POS frontend slug */
	private $pos_slug;
	/** @var string POS checkout slug */
	private $pos_checkout_slug;
	/** @var string regex match for frontend rewite_rule */
	private $pos_rewrite_regex;
	/** @var string regex match for checkout rewite_rule */
	private $pos_checkout_rewrite_regex;

	/**
	 *
	 */
	public function __construct() {
		$this->pos_slug                   = Admin\Permalink::get_slug();
		$this->pos_rewrite_regex          = '^' . $this->pos_slug . '/?';
		$this->pos_checkout_slug          = 'wcpos-checkout';
		$this->pos_checkout_rewrite_regex = '^' . $this->pos_checkout_slug . '/([a-z-]+)/([0-9]+)[/]?$';

		add_rewrite_tag( '%wcpos%', '([^&]+)' );
		add_rewrite_rule( $this->pos_rewrite_regex, 'index.php?wcpos=1', 'top' );
		add_rewrite_rule( $this->pos_checkout_rewrite_regex, 'index.php?$matches[1]=$matches[2]', 'top' );
		add_filter( 'option_rewrite_rules', array( $this, 'rewrite_rules' ), 1 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );

		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'order_received_url' ), 10, 2 );
	}

	/**
	 * Make sure cache contains POS rewrite rules
	 *
	 * @param $rules
	 *
	 * @return array | bool
	 */
	public function rewrite_rules( $rules ) {
		return isset( $rules[ $this->pos_rewrite_regex ], $rules[ $this->pos_checkout_rewrite_regex ] ) ? $rules : false;
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
			$template = new Pay( absint( $wp->query_vars['order-pay'] ) );
			$template->get_template();
		} elseif ( isset( $wp->query_vars['order-received'] ) ) {
			$this->pos_received_template( $wp->query_vars['order-received'] );
		} else {
			$template = new Frontend();
			$template->get_template();
		}

		exit;
	}

	/**
	 * @param $order_id
	 */
	public function pos_received_template( $order_id ) {
		$order_id = absint( $order_id );

		try {
			// get order
			$order = wc_get_order( $order_id );

			include woocommerce_pos_locate_template( 'received.php' );

		} catch ( Exception $e ) {
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * @param string $order_received_url
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function order_received_url( string $order_received_url, $order ): string {
		// check is pos
		// @TODO make sure _wp_http_referer is /wcpos-checkout/order-pay
		if ( ! woocommerce_pos_request( 'query_var' ) ) {
			return $order_received_url;
		}

		// @TODO construct url
		return '/wcpos-checkout/order-received/' . $order->get_id() . '/?wcpos=1&key=' . $order->get_order_key();
	}
}
