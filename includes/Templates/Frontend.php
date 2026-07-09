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
use WCPOS\WooCommercePOS\Services\Settings;
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
		if ( ! is_ssl() && Settings::instance()->force_ssl_enabled() ) {
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
			// translators: Authorization error shown when a logged-in user lacks permission to open the POS page.
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

		// Explicit web-bundle override (constant or env). Null when unset.
		$explicit_bundle_ref = null;
		$env_bundle_ref      = getenv( 'WCPOS_WEB_BUNDLE_REF' );
		if ( \defined( 'WCPOS_WEB_BUNDLE_REF' ) && WCPOS_WEB_BUNDLE_REF ) {
			$explicit_bundle_ref = WCPOS_WEB_BUNDLE_REF;
		} elseif ( ! empty( $_ENV['WCPOS_WEB_BUNDLE_REF'] ) ) {
			$explicit_bundle_ref = sanitize_text_field( wp_unslash( $_ENV['WCPOS_WEB_BUNDLE_REF'] ) );
		} elseif ( false !== $env_bundle_ref && '' !== $env_bundle_ref ) {
			$explicit_bundle_ref = sanitize_text_field( wp_unslash( $env_bundle_ref ) );
		} elseif ( ! empty( $_SERVER['WCPOS_WEB_BUNDLE_REF'] ) ) {
			$explicit_bundle_ref = sanitize_text_field( wp_unslash( $_SERVER['WCPOS_WEB_BUNDLE_REF'] ) );
		}

		// Default to the plugin's own major.minor so the stable lane tracks the
		// version automatically: a 1.9.x plugin loads `@1.9`, a 1.10.x plugin loads
		// `@1.10`, etc. — no edit needed as versions roll.
		$default_bundle_ref = implode( '.', \array_slice( explode( '.', VERSION ), 0, 2 ) );

		/**
		 * The web-bundle ref served from jsDelivr (or a full base URL).
		 *
		 * Override via the WCPOS_WEB_BUNDLE_REF constant / env var or this filter to
		 * point a site at another lane for testing the in-development build locally
		 * or on staging: a branch (e.g. `next`), a tag, a commit, or a full base URL
		 * (anything containing `://`, e.g. a local dev server or an EAS preview).
		 *
		 * @hook woocommerce_pos_web_bundle_ref
		 */
		$bundle_ref = (string) apply_filters( 'woocommerce_pos_web_bundle_ref', $explicit_bundle_ref ?? $default_bundle_ref );
		$bundle_ref = trim( $bundle_ref );
		if ( '' === $bundle_ref ) {
			$bundle_ref = $default_bundle_ref;
		}
		$bundle_overridden = $bundle_ref !== $default_bundle_ref;

		// No trailing slash: Metro's runtime concatenates `cdnBaseUrl` with leading-slash paths
		// (`/_expo/...`, `/assets/...`); a trailing slash here would produce `//`, which jsDelivr
		// 301-redirects with a year-long cache, breaking lazy chunk loads in the browser.
		if ( false !== strpos( (string) $bundle_ref, '://' ) ) {
			// Full base URL (local dev server, EAS preview, etc.).
			$cdn_base_url = rtrim( $bundle_ref, '/' );
		} elseif ( $development && ! $bundle_overridden ) {
			// Development default: the local web build server.
			$cdn_base_url = 'http://localhost:4567/build';
		} else {
			// jsDelivr web-bundle lane (e.g. `1.9`, `1.10`, `next`, a tag or commit).
			$cdn_base_url = 'https://cdn.jsdelivr.net/gh/wcpos/web-bundle@' . rawurlencode( $bundle_ref ) . '/build';
		}
		$wcpos_base_path      = rtrim( wp_parse_url( woocommerce_pos_url(), PHP_URL_PATH ), '/' );
		$stores               = array_map(
			function ( $store ) {
				return $store->get_data();
			},
			wcpos_get_stores()
		);

		$site_uuid = wcpos_get_site_uuid();

		$user_uuid = get_user_meta( $user->ID, '_woocommerce_pos_uuid', true );
		if ( ! $user_uuid ) {
			$user_uuid = Uuid::uuid4()->toString();
			update_user_meta( $user->ID, '_woocommerce_pos_uuid', $user_uuid );
		}

		$vars = array(
			'version'        => VERSION,
			'manifest'       => $cdn_base_url . '/metadata.json?v=' . VERSION,
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
		$cdn_base_url  = wp_json_encode( $cdn_base_url );

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
    var cdnBaseUrl = {$cdn_base_url};
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
								var source = cdnBaseUrl + '/' + bundles[index];
								getScript(source, function() {
										loadBundles(index + 1);
								}, function(error) {
										console.error(error.message);
								});
						}

						if (data.fileMetadata.web.css) {
								loadCSS(cdnBaseUrl + '/' + data.fileMetadata.web.css, function() {
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
