<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WooCommerce POS
 */

namespace WCPOS\WooCommercePOS\Templates;

use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Services\Auth;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use const WCPOS\WooCommercePOS\VERSION;
use const WCPOS\WooCommercePOS\PLUGIN_URL;

/**
 * Frontend class.
 */
class Frontend {

	/**
	 * @return void
	 */
	public function get_template(): void {
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
			// translators: wordpress
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
		}

		// disable cache plugins
		$this->no_cache();

		// last chance before frontend template is rendered
		do_action( 'woocommerce_pos_frontend_template_redirect' );

		/**
		 * Deprecated action.
		 *
		 * @TODO remove in 1.5.0
		 */
		if ( has_action( 'woocommerce_pos_template_redirect' ) ) {
			do_action_deprecated( 'woocommerce_pos_template_redirect', array(), 'Version_1.4.0', 'woocommerce_pos_frontend_template_redirect' );
		}

		// add head & footer actions
		add_action( 'woocommerce_pos_head', array( $this, 'head' ) );
		add_action( 'woocommerce_pos_footer', array( $this, 'footer' ) );

		include woocommerce_pos_locate_template( 'pos.php' );
		exit;
	}

	/**
	 * Add variable to login url to signify POS login.
	 *
	 * @param $login_url
	 *
	 * @return mixed
	 */
	public function login_url( $login_url ) {
		return add_query_arg( SHORT_NAME, '1', $login_url );
	}

	/**
	 * Output the head scripts.
	 */
	public function head(): void {
	}

	/**
	 * Output the footer scripts.
	 */
	public function footer(): void {
		$development    = isset( $_ENV['DEVELOPMENT'] ) && $_ENV['DEVELOPMENT'];
		$user           = wp_get_current_user();
		$github_url     = 'https://cdn.jsdelivr.net/gh/wcpos/web-bundle@1.6/';
		$auth_service   = Auth::instance();
		$stores         = array_map(
			function ( $store ) {
				return $store->get_data();
			},
			wcpos_get_stores()
		);

		$site_uuid = get_option( 'woocommerce_pos_uuid' );
		if ( ! $site_uuid ) {
			$site_uuid = Uuid::uuid4()->toString();
			update_option( 'woocommerce_pos_uuid', $site_uuid );
		}

		$user_uuid = get_user_meta( $user->ID, '_woocommerce_pos_uuid', true );
		if ( ! $user_uuid ) {
			$user_uuid = Uuid::uuid4()->toString();
			update_user_meta( $user->ID, '_woocommerce_pos_uuid', $user_uuid );
		}

		$vars = array(
			'version'        => VERSION,
			'manifest'       => $github_url . 'metadata.json',
			'homepage'       => woocommerce_pos_url(),
			'logout_url'     => $this->pos_logout_url(),
			'site'           => array(
				'uuid'               => $site_uuid,
				'url'                => get_option( 'siteurl' ),
				'name'               => get_option( 'blogname' ),
				'description'        => get_option( 'blogdescription' ),
				'home'               => home_url(),
				'gmt_offset'         => get_option( 'gmt_offset' ),
				'timezone_string'    => get_option( 'timezone_string' ),
				'wp_version'         => get_bloginfo( 'version' ),
				'wc_version'         => WC()->version,
				'wcpos_version'      => VERSION,
				'wp_api_url'         => get_rest_url(),
				'wc_api_url'         => get_rest_url( null, 'wc/v3' ),
				'wc_api_auth_url'    => get_rest_url( null, 'wcpos/v1/jwt' ),
				'locale'             => get_locale(),
				'use_jwt_as_param'   => woocommerce_pos_get_settings( 'tools', 'use_jwt_as_param' ),
			),
			'wp_credentials' => $auth_service->get_user_data( $user ),
			'stores'         => $stores,
		);

		/**
		 * Filters the javascript variables passed to the POS.
		 *
		 * @param {array} $vars
		 *
		 * @returns {array} $vars
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_inline_vars
		 */
		$vars          = apply_filters( 'woocommerce_pos_inline_vars', $vars );
		$initial_props = wp_json_encode( $vars );
		$dev_bundle    = 'http://localhost:8081/index.bundle?platform=web&dev=true&hot=false';

		/**
		 * Add path to worker scripts
		 */
		$idbWorker = PLUGIN_URL . 'assets/js/indexeddb.worker.js';

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

	var idbWorker = '{$idbWorker}';
	var initialProps = {$initial_props};
</script>" . "\n";

		/**
		 * The actual app bundle
		 */
		if ( $development ) {
			// Development
			echo "<script>getScript('{$dev_bundle}' , () => { console.log('done') });</script>" . "\n";
		} else {
			// Production
			echo "<script>
    var request = new Request(initialProps.manifest);

    window.fetch(request)
        .then((response) => response.json())
        .then((data) => {
            var bundle = data.fileMetadata.web.bundle;
            var bundleUrl = '{$github_url}' + bundle;
            getScript(bundleUrl, () => { console.log('done') });
        });
</script>" . "\n";
		}
	}

	/**
	 *
	 */
	private function pos_logout_url() {
		/**
		 * Get the login URL, allow other plugins to customise the URL. eg: WPS Hide Login
		 */
		$login_url = apply_filters( 'login_url', site_url( '/wp-login.php' ), 'logout', false );

		$redirect_to = urlencode( woocommerce_pos_url() );
		$reauth = 1;
		$wcpos = 1;
		$logout_nonce = wp_create_nonce( 'log-out' );

		$logout_url = "{$login_url}?action=logout&_wpnonce={$logout_nonce}&redirect_to={$redirect_to}&reauth={$reauth}&wcpos={$wcpos}";

		return $logout_url;
	}




	/**
	 * Disable caching conflicts.
	 */
	private function no_cache(): void {
		// disable W3 Total Cache minify
		if ( ! \defined( 'DONOTMINIFY' ) ) {
			\define( 'DONOTMINIFY', 'true' );
		}

		// disable WP Super Cache
		if ( ! \defined( 'DONOTCACHEPAGE' ) ) {
			\define( 'DONOTCACHEPAGE', 'true' );
		}

		// disable Lite Speed Cache
		do_action( 'litespeed_control_set_nocache', 'nocache WoCommerce POS web application' );
	}
}
