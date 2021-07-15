<?php
/**
 *
 *
 * @package    WCPOS\Templates
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS;

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
		add_rewrite_tag( '%checkout%', '([^&]+)' );
		add_rewrite_rule( $this->pos_rewrite_regex, 'index.php?wcpos=1', 'top' );
		add_rewrite_rule( $this->pos_checkout_rewrite_regex, 'index.php?checkout=$matches[1]', 'top' );
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

		if ( $wp->query_vars['checkout'] ) {
			$this->pos_checkout_template();
		} else {
			$this->pos_frontend_template();
		}

		exit;
	}

	/**
	 *
	 */
	private function pos_frontend_template() {
		include woocommerce_pos_locate_template( 'pos.php' );
	}

	/**
	 *
	 */
	private function pos_checkout_template() {
		include woocommerce_pos_locate_template( 'checkout.php' );
	}
}
