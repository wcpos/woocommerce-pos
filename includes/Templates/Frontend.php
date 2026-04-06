<?php
/**
 * Frontend template.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates;

use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Template_Router;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Frontend class.
 */
class Frontend {
	/**
	 * Stores user credentials data for use in footer().
	 *
	 * @var array
	 */
	/** Stores user credentials data for use in footer.
	 *
	 * @var array
	 */
	private $wp_credentials = array();

	/**
	 * Render the frontend template.
	 *
	 * @return void
	 */
	public function get_template(): void {
		// force ssl.
		if ( ! is_ssl() && woocommerce_pos_get_settings( 'general', 'force_ssl' ) ) {
			wp_safe_redirect( woocommerce_pos_url() );
			exit;
		}

		// check auth.
		if ( ! is_user_logged_in() ) {
			add_filter( 'login_url', array( $this, 'login_url' ) );
			auth_redirect();
		}

		// check privileges.
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
			// translators: wordpress.
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'woocommerce-pos' ) );
		}

		// disable cache plugins.
		$this->no_cache();

		// last chance before frontend template is rendered.
		do_action( 'woocommerce_pos_frontend_template_redirect' );

		/*
		 * Deprecated action.
		 *
		 * @TODO remove in 1.5.0
		 */
		if ( has_action( 'woocommerce_pos_template_redirect' ) ) {
			do_action_deprecated( 'woocommerce_pos_template_redirect', array(), 'Version_1.4.0', 'woocommerce_pos_frontend_template_redirect' );
		}

		// add head & footer actions.
		add_action( 'woocommerce_pos_head', array( $this, 'head' ) );
		add_action( 'woocommerce_pos_footer', array( $this, 'footer' ) );

		// Generate user credentials BEFORE including template to ensure cookies can be set.
		// The set_web_session_cookie() call in Auth::get_user_data() requires headers not yet sent.
		$user                 = wp_get_current_user();
		$auth_service         = Auth::instance();
		$this->wp_credentials = $auth_service->get_user_data( $user, true );

		include woocommerce_pos_locate_template( 'pos.php' );
		exit;
	}

	/**
	 * Add variable to login url to signify POS login.
	 *
	 * @param string $login_url The login URL.
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
		/**
		 * Filters whether the POS is in development mode.
		 *
		 * When true, loads the web bundle from localhost instead of CDN.
		 * Useful for local development of the web application.
		 *
		 * @since 1.8.0
		 *
		 * @param bool $development Whether development mode is enabled.
		 *                          Defaults to checking WCPOS_DEVELOPMENT constant,
		 *                          then $_ENV['DEVELOPMENT'].
		 *
		 * @hook woocommerce_pos_development_mode
		 */
		$development = apply_filters(
			'woocommerce_pos_development_mode',
			( \defined( 'WCPOS_DEVELOPMENT' ) && WCPOS_DEVELOPMENT ) || ( isset( $_ENV['DEVELOPMENT'] ) && wp_validate_boolean( sanitize_text_field( wp_unslash( $_ENV['DEVELOPMENT'] ) ) ) )
		);

		$user                 = wp_get_current_user();
		$cdn_base_url         = $development ? 'http://localhost:4567/build/' : 'https://cdn.jsdelivr.net/gh/wcpos/web-bundle@1.8/build/';
		$wcpos_base_path      = rtrim( wp_parse_url( woocommerce_pos_url(), PHP_URL_PATH ), '/' );
		$stores               = array_map(
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
			'manifest'       => $cdn_base_url . 'metadata.json?v=' . VERSION,
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
				'wc_api_url'         => trailingslashit( get_rest_url( null, 'wc/v3' ) ),
				'wcpos_api_url'      => trailingslashit( get_rest_url( null, 'wcpos/v1' ) ),
				'wcpos_login_url'    => Template_Router::get_auth_url(),
				'locale'             => get_locale(),
			),
			'wp_credentials' => $this->wp_credentials,
			'stores'         => $stores,
		);

		/**
		 * Filters the javascript variables passed to the POS.
		 *
		 * @param array $vars
		 *
		 * @returns array $vars
		 *
		 * @since 1.0.0
		 *
		 * @hook woocommerce_pos_inline_vars
		 */
		$vars          = apply_filters( 'woocommerce_pos_inline_vars', $vars );
		$initial_props = wp_json_encode( $vars );

		/**
		 * Add path to worker scripts.
		 */
		$idb_worker  = PLUGIN_URL . 'assets/js/indexeddb.worker.js';
		$opfs_worker = PLUGIN_URL . 'assets/js/opfs.worker.js';

		// getScript helper and initialProps.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline JavaScript for POS frontend
		echo "<script>
    function getScript(source, callback, onError) {
        var script = document.createElement('script');
        script.async = true;
        script.onload = script.onreadystatechange = function(_, isAbort) {
            if (isAbort || !script.readyState || /loaded|complete/.test(script.readyState)) {
                script.onload = script.onreadystatechange = null;
                script = undefined;
                if (!isAbort && callback) setTimeout(callback, 0);
            }
        };
        script.onerror = function() {
            script.onload = script.onreadystatechange = null;
            if (onError) onError(new Error('Failed to load script: ' + source));
        };
        script.src = source;
        document.head.appendChild(script);
    }

    function loadCSS(source, callback) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = source;
        link.onload = function() {
            if (callback) callback();
        };
        link.onerror = function() {
            console.error('Failed to load CSS file:', source);
        };
        document.head.appendChild(link);
    }

    var idbWorker = '{$idb_worker}';
    var opfsWorker = '{$opfs_worker}';
    var initialProps = {$initial_props};
    var cdnBaseUrl = '{$cdn_base_url}';
	var baseUrl = '{$wcpos_base_path}';
    </script>" . "\n";

		echo "<script>
		var request = new Request(initialProps.manifest);

		window.fetch(request)
				.then(function(response) { return response.json(); })
				.then(function(data) {
						// v1 metadata uses 'bundles' array (metro runtime, common, entry)
						// v0 fallback uses single 'bundle' string
						var webMeta = (data && data.fileMetadata && data.fileMetadata.web) || {};
						var bundles = Array.isArray(webMeta.bundles)
								? webMeta.bundles.filter(Boolean)
								: (webMeta.bundle ? [webMeta.bundle] : []);

						if (!bundles.length) {
								throw new Error('No JavaScript bundles declared in metadata.json');
						}

						function loadBundles(index) {
								if (index >= bundles.length) return;
								var source = cdnBaseUrl + bundles[index];
								getScript(source, function() {
										loadBundles(index + 1);
								}, function(error) {
										console.error(error.message);
								});
						}

						if (data.fileMetadata.web.css) {
								loadCSS(cdnBaseUrl + data.fileMetadata.web.css, function() {
										loadBundles(0);
								});
						} else {
								loadBundles(0);
						}
				})
				.catch(function(error) {
						console.error('Error fetching manifest:', error);
				});
		</script>" . "\n";
	}

	/**
	 * Get the POS logout URL.
	 *
	 * @return string
	 */
	private function pos_logout_url() {
		/**
		 * Get the login URL, allow other plugins to customise the URL. eg: WPS Hide Login.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook
		$login_url = apply_filters( 'login_url', site_url( '/wp-login.php' ), 'logout', false );

		$redirect_to  = urlencode( woocommerce_pos_url() );
		$reauth       = 1;
		$wcpos        = 1;
		$logout_nonce = wp_create_nonce( 'log-out' );

		return "{$login_url}?action=logout&_wpnonce={$logout_nonce}&redirect_to={$redirect_to}&reauth={$reauth}&wcpos={$wcpos}";
	}




	/**
	 * Disable caching conflicts.
	 */
	private function no_cache(): void {
		// disable W3 Total Cache minify.
		if ( ! \defined( 'DONOTMINIFY' ) ) {
			\define( 'DONOTMINIFY', 'true' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Third-party constant
		}

		// disable WP Super Cache.
		if ( ! \defined( 'DONOTCACHEPAGE' ) ) {
			\define( 'DONOTCACHEPAGE', 'true' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Third-party constant
		}

		// disable Lite Speed Cache.
		do_action( 'litespeed_control_set_nocache', 'nocache WoCommerce POS web application' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook
	}
}
