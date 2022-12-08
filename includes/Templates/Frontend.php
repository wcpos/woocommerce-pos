<?php
/**
 *
 *
 * @package    WCPOS\WooCommercePOS\Templates\Frontend
 * @author   Paul Kilmurray <paul@kilbot.com>
 * @link     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use WCPOS\WooCommercePOS\API\Stores;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use const WCPOS\WooCommercePOS\VERSION;

class Frontend {

	public function __construct() {

	}

	/**
	 *
	 */
	public function get_template() {
		// force ssl
		if ( ! is_ssl() && woocommerce_pos_get_settings( 'general', 'force_ssl' ) ) {
			wp_safe_redirect( woocommerce_pos_url() );
			exit;
		}

		// check auth
		if ( ! is_user_logged_in() ) {
			add_filter( 'login_url', array( $this, 'login_url' ) );
			auth_redirect();
		}

		// check privileges
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
			/* translators: wordpress */
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
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
	 * Output the head scripts
	 */
	public function head() {

	}

	/**
	 * Output the footer scripts
	 */
	public function footer() {
		$development    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$user           = wp_get_current_user();
		$store_settings = new Stores();
		$github_url     = 'https://wcpos.github.io/managed-expo/';

		$vars = array(
			'version'        => VERSION,
			'manifest'       => $github_url . 'metadata.json',
			'homepage'       => woocommerce_pos_url(),
			// @TODO change prop name
			'site'           => array(
				'url'             => get_option( 'siteurl' ),
				'name'            => get_option( 'blogname' ),
				'description'     => get_option( 'blogdescription' ),
				'home'            => home_url(),
				'gmt_offset'      => get_option( 'gmt_offset' ),
				'timezone_string' => get_option( 'timezone_string' ),
				'wp_api_url'      => get_rest_url(),
				'wc_api_url'      => get_rest_url( null, 'wc/v3' ),
				'wc_api_auth_url' => get_rest_url( null, 'wcpos/v1/jwt' ),
				'locale'          => get_locale(),
			),
			'wp_credentials' => array(
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'first_name'   => $user->user_firstname,
				'last_name'    => $user->user_lastname,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'nice_name'    => $user->user_nicename,
				'last_access'  => '',
				'avatar_url'   => get_avatar_url( $user->ID ),
				'wp_nonce'     => wp_create_nonce( 'wp_rest' ),
			),
			'store'          => $store_settings->get_store()
		);

		$vars          = apply_filters( 'woocommerce_pos_admin_inline_vars', $vars );
		$initial_props = wp_json_encode( $vars );
		$dev_bundle    = 'http://localhost:19000/index.bundle?platform=web&dev=true&hot=false';

		/**
		 * getScript helper and initialProps
		 */
		echo "<script>
	function getScript(source, callback) {
	    var script = document.createElement('script');
	    var prior = document.getElementsByTagName('script')[0];
	    script.async = 1;

	    script.onload = script.onreadystatechange = function( _, isAbort ) {
	        if(isAbort || !script.readyState || /loaded|complete/.test(script.readyState) ) {
	            script.onload = script.onreadystatechange = null;
	            script = undefined;

	            if(!isAbort && callback) setTimeout(callback, 0);
	        }
	    };

	    script.src = source;
	    prior.parentNode.insertBefore(script, prior);
	}

	var initialProps={$initial_props};
</script>" . "\n";

		if ( $development ) {
			/**
			 * Development
			 */
			echo "<script>getScript('{$dev_bundle}' , () => { console.log('done') });</script>" . "\n";
		} else {
			/**
			 * Production
			 */
			echo "<script>
    var request = new Request(initialProps.manifest);

    window.fetch(request)
        .then((response) => response.json())
        .then((data) => {
            var bundle = data.fileMetadata.web.bundle;
            var bundleUrl = '{$github_url}' + '/' + bundle;
            getScript(bundleUrl, () => { console.log('done') });
        });
</script>" . "\n";
		}
	}
}
