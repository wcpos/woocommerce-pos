<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

use WC_Abstract_Order;

/**
 *
 */
class Templates {
	/**
	 * @var string POS frontend slug
	 */
	private $pos_regex;

	/**
	 * @var string POS login slug
	 */
	private $pos_login_regex;

	/**
	 * @var string POS checkout slug
	 * @note 'wcpos-checkout' slug is used instead 'checkout' to avoid conflicts with WC checkout
	 * eg: x-frame-options: SAMEORIGIN
	 */
	private $pos_checkout_regex;


	public function __construct() {
		$this->pos_regex          = '^' . Admin\Permalink::get_slug() . '(/(.*))?/?$';
		$this->pos_login_regex    = '^wcpos-login/?';
		$this->pos_checkout_regex = '^wcpos-checkout/([a-z-]+)/([0-9]+)[/]?$';

		$this->add_rewrite_rules();

		add_filter( 'option_rewrite_rules', array( $this, 'rewrite_rules' ), 1 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'order_received_url' ), 10, 2 );
	}

	/**
	 * @NOTE: 'order-pay' and 'order-received' rewrite tags are added by WC
	 *
	 * @return void
	 */
	private function add_rewrite_rules() {
		add_rewrite_tag( '%wcpos%', '([^&]+)' );
		add_rewrite_tag( '%wcpos-receipt%', '([^&]+)' );
		add_rewrite_tag( '%wcpos-login%', '([^&]+)' );
		add_rewrite_rule( $this->pos_regex, 'index.php?wcpos=1', 'top' );
		add_rewrite_rule( $this->pos_login_regex, 'index.php?wcpos-login=1', 'top' );
		add_rewrite_rule( $this->pos_checkout_regex, 'index.php?$matches[1]=$matches[2]&wcpos=1', 'top' );
	}

	/**
	 * Make sure cache contains POS rewrite rules.
	 *
	 * @param $rules
	 *
	 * @return array|bool
	 */
	public function rewrite_rules( $rules ) {
		return isset( $rules[ $this->pos_regex ], $rules[ $this->pos_login_regex ], $rules[ $this->pos_checkout_regex ] ) ? $rules : false;
	}

	/**
	 * Output the matched template.
	 */
	public function template_redirect(): void {
		global $wp;

		$rewrite_rules_to_templates = array(
			$this->pos_regex => __NAMESPACE__ . '\\Templates\\Frontend',
			$this->pos_login_regex => __NAMESPACE__ . '\\Templates\\Login',
			$this->pos_checkout_regex => array(
				'order-pay' => __NAMESPACE__ . '\\Templates\\Payment',
				'order-received' => __NAMESPACE__ . '\\Templates\\Received',
				'wcpos-receipt' => __NAMESPACE__ . '\\Templates\\Receipt',
			),
		);

		foreach ( $rewrite_rules_to_templates as $rule => $classname ) {
			if ( $wp->matched_rule === $rule ) {
				if ( is_array( $classname ) ) {
					$this->load_checkout_template( $classname );
				} else {
					$this->load_template( $classname );
				}
				exit;
			}
		}
	}

	/**
	 * Loads order templates, additionally checks query var is a valid order id
	 *
	 * @param array $classnames
	 *
	 * @return void
	 */
	private function load_checkout_template( array $classnames ): void {
		global $wp;

		foreach ( $classnames as $query_var => $classname ) {
			if ( isset( $wp->query_vars[ $query_var ] ) ) {
				$order_id = absint( $wp->query_vars[ $query_var ] );

				if ( class_exists( $classname ) && $order_id ) {
					$template = new $classname( $order_id );
					$template->get_template();
					return;
				}
			}
		}

		wp_die( esc_html__( 'Template not found.', 'woocommerce-pos' ) );
	}

	/**
	 * Loads all other templates
	 *
	 * @param string $classname
	 *
	 * @return void
	 */
	private function load_template( string $classname ): void {
		if ( class_exists( $classname ) ) {
			$template = new $classname();
			$template->get_template();
			return;
		}

		wp_die( esc_html__( 'Template not found.', 'woocommerce-pos' ) );
	}


	/**
	 * Just like the checkout/payment.php template, we hijack the order received url so we can display a stripped down
	 * version of the receipt.
	 *
	 * @param string            $order_received_url
	 * @param WC_Abstract_Order $order
	 *
	 * @return string
	 */
	public function order_received_url( string $order_received_url, WC_Abstract_Order $order ): string {
		global $wp;

		// check is pos
		if ( ! woocommerce_pos_request() || ! isset( $wp->query_vars['order-pay'] ) ) {
			return $order_received_url;
		}

		$redirect = add_query_arg(
			array(
				'key' => $order->get_order_key(),
			),
			get_home_url( null, '/wcpos-checkout/order-received/' . $order->get_id() )
		);

		return $redirect;
	}
}
