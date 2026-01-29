<?php
/**
 * Template Router Class.
 *
 * Handles routing for POS frontend templates.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WC_Abstract_Order;

/**
 * Template_Router class.
 */
class Template_Router {
	/**
	 * Auth path slug for POS authorization endpoint.
	 */
	public const AUTH_PATH = 'wcpos-auth';

	/**
	 * POS frontend slug.
	 *
	 * @var string
	 */
	private $pos_regex;

	/**
	 * POS login slug.
	 *
	 * @var string
	 */
	private $pos_login_regex;

	/**
	 * POS auth slug.
	 *
	 * @var string
	 */
	private $pos_auth_regex;

	/**
	 * POS checkout slug.
	 *
	 * @var string
	 *
	 * @note 'wcpos-checkout' slug is used instead 'checkout' to avoid conflicts with WC checkout
	 * eg: x-frame-options: SAMEORIGIN
	 */
	private $pos_checkout_regex;


	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pos_regex          = '^' . Admin\Permalink::get_slug() . '(/(.*))?/?$';
		$this->pos_login_regex    = '^wcpos-login/?';
		$this->pos_auth_regex     = '^' . self::AUTH_PATH . '/?';
		$this->pos_checkout_regex = '^wcpos-checkout/([a-z-]+)/([0-9]+)[/]?$';

		$this->add_rewrite_rules();

		add_filter( 'option_rewrite_rules', array( $this, 'rewrite_rules' ), 1 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );

		// Priority 999 to ensure this filter runs after any other plugins that may hijack the order received url.
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'order_received_url' ), 999, 2 );
	}

	/**
	 * Get the full URL for the POS authorization endpoint.
	 *
	 * @return string
	 */
	public static function get_auth_url(): string {
		return home_url( self::AUTH_PATH . '/' );
	}

	/**
	 * Make sure cache contains POS rewrite rules.
	 *
	 * @param array|bool $rules Rewrite rules.
	 *
	 * @return array|bool
	 */
	public function rewrite_rules( $rules ) {
		return isset( $rules[ $this->pos_regex ], $rules[ $this->pos_login_regex ], $rules[ $this->pos_auth_regex ], $rules[ $this->pos_checkout_regex ] ) ? $rules : false;
	}

	/**
	 * Output the matched template.
	 */
	public function template_redirect(): void {
		global $wp;

		$rewrite_rules_to_templates = array(
			$this->pos_regex          => __NAMESPACE__ . '\\Templates\\Frontend',
			$this->pos_login_regex    => __NAMESPACE__ . '\\Templates\\Login',
			$this->pos_auth_regex     => __NAMESPACE__ . '\\Templates\\Auth',
			$this->pos_checkout_regex => array(
				'order-pay'      => __NAMESPACE__ . '\\Templates\\Payment',
				'order-received' => __NAMESPACE__ . '\\Templates\\Received',
				'wcpos-receipt'  => __NAMESPACE__ . '\\Templates\\Receipt',
			),
		);

		foreach ( $rewrite_rules_to_templates as $rule => $classname ) {
			if ( $wp->matched_rule === $rule ) {
				if ( \is_array( $classname ) ) {
					$this->load_checkout_template( $classname );
				} else {
					$this->load_template( $classname );
				}
				exit;
			}
		}
	}


	/**
	 * Just like the checkout/payment.php template, we hijack the order received url so we can display a stripped down
	 * version of the receipt.
	 *
	 * @param string            $order_received_url The order received URL.
	 * @param WC_Abstract_Order $order              The order object.
	 *
	 * @return string
	 */
	public function order_received_url( string $order_received_url, WC_Abstract_Order $order ): string {
		global $wp;

		// check is pos.
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

	/**
	 * Add rewrite rules for POS endpoints.
	 *
	 * @NOTE: 'order-pay' and 'order-received' rewrite tags are added by WC
	 *
	 * @return void
	 */
	private function add_rewrite_rules(): void {
		add_rewrite_tag( '%wcpos%', '([^&]+)' );
		add_rewrite_tag( '%wcpos-receipt%', '([^&]+)' );
		add_rewrite_tag( '%wcpos-login%', '([^&]+)' );
		add_rewrite_tag( '%wcpos-auth%', '([^&]+)' );
		add_rewrite_rule( $this->pos_regex, 'index.php?wcpos=1', 'top' );
		add_rewrite_rule( $this->pos_login_regex, 'index.php?wcpos-login=1', 'top' );
		add_rewrite_rule( $this->pos_auth_regex, 'index.php?wcpos-auth=1', 'top' );
		add_rewrite_rule( $this->pos_checkout_regex, 'index.php?$matches[1]=$matches[2]&wcpos=1', 'top' );
	}

	/**
	 * Loads order templates, additionally checks query var is a valid order id.
	 *
	 * @param array $classnames Template class names keyed by query var.
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
	 * Loads all other templates.
	 *
	 * @param string $classname The template class name.
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
}
