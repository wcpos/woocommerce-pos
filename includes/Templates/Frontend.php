<?php
/**
 *
 *
 * @package    WCPOS\WooCommercePOS\Templates\Frontend
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;
use const WCPOS\WooCommercePOS\SHORT_NAME;

class Frontend {

	public function __construct() {

	}

	/**
	 *
	 */
	public function get_template() {
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
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) { /* translators: wordpress */
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
			define( 'DONOTMINIFY', 'true' );
		}

		// disable WP Super Cache
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', 'true' );
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
