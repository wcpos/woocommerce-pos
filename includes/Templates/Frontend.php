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

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Cashier;
use WCPOS\WooCommercePOS\Services\Lifecycle_Events;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Template_Router;
use WCPOS\WooCommercePOS\Sync\Collection_Rules;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;
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
			wp_safe_redirect( wcpos_url() );
			exit;
		}

		// check auth.
		if ( ! is_user_logged_in() ) {
			add_filter( 'login_url', array( $this, 'login_url' ) );
			auth_redirect();
		}

		// check privileges.
		$user = wp_get_current_user();
		if ( ! Cashier::instance()->can_open_pos( $user ) ) {
			wp_die( esc_html( Cashier::instance()->missing_pos_capabilities_message( $user ) ), '', array( 'response' => 403 ) );
		}

		// disable cache plugins.
		$this->no_cache();

		// last chance before frontend template is rendered.
		do_action( 'wcpos_frontend_template_redirect' );
		do_action_deprecated( 'woocommerce_pos_frontend_template_redirect', array(), '2.0.0', 'wcpos_frontend_template_redirect' );

		// add head & footer actions.
		add_action( 'wcpos_head', array( $this, 'head' ) );
		add_action( 'wcpos_footer', array( $this, 'footer' ) );
		// A theme's pos.php override fires the 1.x names itself; the bridge
		// lives on the listener side (ADR 0041).
		add_action( 'woocommerce_pos_head', array( $this, 'legacy_head' ) );
		add_action( 'woocommerce_pos_footer', array( $this, 'legacy_footer' ) );

		// Generate user credentials BEFORE including template to ensure cookies can be set.
		// The set_web_session_cookie() call in Auth::get_user_data() requires headers not yet sent.
		$user                 = wp_get_current_user();
		$auth_service         = Auth::instance();
		$this->wp_credentials = $auth_service->get_user_data( $user, true );

		// The activation funnel's step the admin side cannot see: the POS itself
		// being opened. Recorded here rather than by tracking the menu link, so
		// a bookmark, a direct URL or a till that never touches wp-admin all
		// count — and so it counts opens, not clicks that may never arrive.
		//
		// Everything above has already established that this is a logged-in user
		// with `access_woocommerce_pos`, past the SSL redirect.
		( new Lifecycle_Events() )->report_app_opened();

		include wcpos_locate_template( 'pos.php' );
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
	 * Re-fire `woocommerce_pos_head` (1.x) as `wcpos_head`.
	 */
	public function legacy_head(): void {
		_deprecated_hook( 'woocommerce_pos_head', '2.0.0', 'wcpos_head' );
		do_action( 'wcpos_head' );
	}

	/**
	 * Re-fire `woocommerce_pos_footer` (1.x) as `wcpos_footer`.
	 */
	public function legacy_footer(): void {
		_deprecated_hook( 'woocommerce_pos_footer', '2.0.0', 'wcpos_footer' );
		do_action( 'wcpos_footer' );
	}

	/**
	 * Output the head scripts.
	 */
	public function head(): void {
	}

	/**
	 * Resolve the explicit web-bundle override, or null when unset.
	 */
	public static function explicit_web_bundle_ref(): ?string {
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
		return $explicit_bundle_ref;
	}

	/**
	 * CDN lane shared by plugin assets and font packs.
	 */
	public static function cdn_ref(): string {
		$ref = implode( '.', \array_slice( explode( '.', VERSION ), 0, 2 ) );
		return 'next' === self::explicit_web_bundle_ref() ? 'next' : $ref;
	}

	/**
	 * Whether the site runs the POS in development mode (local bundles and assets).
	 */
	public static function is_development_mode(): bool {
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
		 * @hook wcpos_development_mode
		 */
		$filtered = apply_filters(
			'wcpos_development_mode',
			( \defined( 'WCPOS_DEVELOPMENT' ) && WCPOS_DEVELOPMENT ) || ( isset( $_ENV['DEVELOPMENT'] ) && wp_validate_boolean( sanitize_text_field( wp_unslash( $_ENV['DEVELOPMENT'] ) ) ) )
		);
		return (bool) apply_filters_deprecated( 'woocommerce_pos_development_mode', array( $filtered ), '2.0.0', 'wcpos_development_mode' );
	}

	/**
	 * Output the footer scripts.
	 */
	public function footer(): void {
		$development = self::is_development_mode();

		$user                 = wp_get_current_user();

		$explicit_bundle_ref = self::explicit_web_bundle_ref();

		// Default to the plugin's own major.minor so the stable lane tracks the
		// version automatically: a 1.9.x plugin loads `@1.9`, a 1.10.x plugin loads
		// `@1.10`, etc. — no edit needed as versions roll.

		/*
		 * One jsDelivr ref per lane, named after the lane (owner ruling, 2026-09-04):
		 *   released lane → `@<major.minor>` (this default; the tag is cut at release)
		 *   next lane     → `@next` — the `next` BRANCH of wcpos/web-bundle IS the dev
		 *                   lane's tag. There is no versioned/prerelease tag for `next`.
		 *                   dev-next sets WCPOS_WEB_BUNDLE_REF=next to load it.
		 */
		// When `next` becomes `main`, the released ref simply becomes the new
		// major.minor (e.g. `@1.11`) via this default.
		$default_bundle_ref = implode( '.', \array_slice( explode( '.', VERSION ), 0, 2 ) );

		/**
		 * The web-bundle ref served from jsDelivr (or a full base URL).
		 *
		 * Override via the WCPOS_WEB_BUNDLE_REF constant / env var or this filter to
		 * point a site at another lane for testing the in-development build locally
		 * or on staging: the `next` lane's ref is the branch `next`
		 * (https://cdn.jsdelivr.net/gh/wcpos/web-bundle@next); a tag, a commit, or a
		 * full base URL (anything containing `://`, e.g. a local dev server or an EAS
		 * preview) also work.
		 *
		 * @hook wcpos_web_bundle_ref
		 */
		$bundle_ref = apply_filters( 'wcpos_web_bundle_ref', $explicit_bundle_ref ?? $default_bundle_ref );
		$bundle_ref = (string) apply_filters_deprecated( 'woocommerce_pos_web_bundle_ref', array( $bundle_ref ), '2.0.0', 'wcpos_web_bundle_ref' );
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
		$wcpos_base_path      = rtrim( wp_parse_url( wcpos_url(), PHP_URL_PATH ), '/' );
		$stores               = array_map(
			function ( $store ) {
				return $store->get_data();
			},
			wcpos_get_stores()
		);

		$site_uuid        = wcpos_get_site_uuid();
		$opfs_worker_hash = hash_file( 'sha256', PLUGIN_PATH . 'assets/js/sqlite.worker.js' );
		$worker_version   = false === $opfs_worker_hash ? null : Web_Bundle_Loader_Config::worker_rxdb_version( PLUGIN_PATH . 'assets/js/sqlite.worker.js', $opfs_worker_hash );
		$sqlite_wasm_hash = hash_file( 'sha256', PLUGIN_PATH . 'assets/js/sqlite3.wasm' );
		// Hash the concatenated worker and WASM SHA-256 digests so either asset busts
		// the manifest and worker caches together (wcpos/monorepo#2242).
		$opfs_worker_hash = false === $opfs_worker_hash || false === $sqlite_wasm_hash
			? VERSION
			: hash( 'sha256', $opfs_worker_hash . $sqlite_wasm_hash );

		// Pos_Uuid is the sole authority for `_woocommerce_pos_uuid`: the value here
		// must match what /cashier and /customers serve, or the client forks identities.
		$user_uuid = Pos_Uuid::ensure_user_uuid( $user );

		$pro_version  = \defined( '\WCPOS\WooCommercePOSPro\VERSION' ) ? (string) \constant( '\WCPOS\WooCommercePOSPro\VERSION' ) : null;
		$manifest_url = Web_Bundle_Loader_Config::uses_manifest( $bundle_ref, $development, $bundle_overridden )
			? Web_Bundle_Loader_Config::manifest_url( $bundle_ref, VERSION, $pro_version, $worker_version, null !== $pro_version || Web_Bundle_Loader_Config::is_prerelease( VERSION ) ? $site_uuid : null )
			: null;

		$vars = array(
			'loader'         => array(
				'manifest_url'      => $manifest_url,
				'fallback_manifest' => $cdn_base_url . '/metadata.json?v=' . $opfs_worker_hash,
				'fallback_base'     => $cdn_base_url,
				'beacon_url'        => Web_Bundle_Loader_Config::BEACON_URL,
				'timeout_ms'        => Web_Bundle_Loader_Config::MANIFEST_TIMEOUT_MS,
				'lane'              => 'next' === $bundle_ref ? 'next' : ( preg_match( '/^[0-9]+\.[0-9]+$/', $bundle_ref ) ? $bundle_ref : null ),
				'worker_version'    => $worker_version,
				'plugin_version'    => VERSION,
				'pro_version'       => $pro_version,
				'signing_keys'      => Web_Bundle_Loader_Config::SIGNING_PUBLIC_KEYS_JWK,
			),
			'version'        => VERSION,
			'manifest'       => $cdn_base_url . '/metadata.json?v=' . $opfs_worker_hash,
			'homepage'       => wcpos_url(),
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
				'wcpos_api_url'      => trailingslashit( get_rest_url( null, 'wcpos/v2' ) ),
				'wcpos_login_url'    => Template_Router::get_auth_url(),
				'locale'             => get_locale(),
				// The browser POS never calls wcpos/v2/site: this block IS its site row.
				'search_meta_keys'   => Collection_Rules::client_search_meta_keys(),
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
		 * @hook wcpos_inline_vars
		 */
		$vars = apply_filters( 'wcpos_inline_vars', $vars );
		$vars = apply_filters_deprecated( 'woocommerce_pos_inline_vars', array( $vars ), '2.0.0', 'wcpos_inline_vars' );
		$initial_props = wp_json_encode( $vars );
		$cdn_base_url  = wp_json_encode( $cdn_base_url );

		/**
		 * Add path to worker scripts.
		 */
		$idb_worker  = PLUGIN_URL . 'assets/js/indexeddb.worker.js';
		$opfs_worker = add_query_arg(
			'ver',
			$opfs_worker_hash,
			PLUGIN_URL . 'assets/js/sqlite.worker.js'
		);

		// Frontend globals and initialProps.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline JavaScript for POS frontend
		echo "<script>
    var idbWorker = '{$idb_worker}';
    var opfsWorker = '{$opfs_worker}';
    var initialProps = {$initial_props};
    var cdnBaseUrl = {$cdn_base_url};
	var baseUrl = '{$wcpos_base_path}';
    </script>" . "\n";

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted, bundled loader printed inline.
		echo '<script>' . file_get_contents( PLUGIN_PATH . 'assets/js/pos-loader.js' ) . "</script>\n";
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

		$redirect_to  = urlencode( wcpos_url() );
		$reauth       = 1;
		$wcpos        = 1;
		$logout_nonce = wp_create_nonce( 'log-out' );

		return "{$login_url}?action=logout&_wpnonce={$logout_nonce}&redirect_to={$redirect_to}&reauth={$reauth}&wcpos={$wcpos}";
	}




	/**
	 * Disable caching conflicts.
	 */
	private function no_cache(): void {
		if ( ! headers_sent() ) {
			nocache_headers();
		}

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
