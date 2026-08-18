<?php
/**
 * Load required classes.
 *
 * @author    Paul Kilmurray <paul@kilbot.com>
 *
 * @see      http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WCPOS\WooCommercePOS\Admin\Consent;
use WCPOS\WooCommercePOS\Admin\Menu;
use WCPOS\WooCommercePOS\Services\Auth as AuthService;
use WCPOS\WooCommercePOS\Services\Extensions;
use WCPOS\WooCommercePOS\Services\Receipt_Snapshot_Store;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Init class.
 */
class Init {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// global helper functions.
		require_once PLUGIN_PATH . 'includes/wcpos-functions.php';
		require_once PLUGIN_PATH . 'includes/wcpos-store-functions.php';
		wp_cache_add_global_groups( 'wc_pos_user_uuid_locks' );

		// Tracking consent pop-up + callout. Registered here (during
		// plugins_loaded) so its lifecycle hooks (activated_plugin,
		// upgrader_process_complete) are in place before those actions
		// fire on a plugin activation or update request.
		new Consent();
		add_filter( 'woocommerce_pos_rest_api_controllers', array( \WCPOS\WooCommercePOS\Sync\Api::class, 'register_controllers' ) );
		add_action( \WCPOS\WooCommercePOS\Sync\Integrity_Digest::REBUILD_HOOK, array( \WCPOS\WooCommercePOS\Sync\Integrity_Digest::class, 'run_scheduled_rebuild' ) );
		// Gate on the schema latch, not a live Health probe: the latch is only
		// set AFTER install verified every table (latch-after-verify), so a
		// per-request SHOW TABLES sweep buys nothing — and a table lost after
		// latching is already survivable (observer writes fail open and the
		// REST health gate 503s the sync endpoints).
		$sync_schema_latched = \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION === get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, null );
		if ( $sync_schema_latched ) {
			// Normalize structured meta at priority 5, before revision stamps at 9
			// and UUID, digest, and variable-price stamps at priority 10. Kept out
			// of the augmentation pipeline because it also serves the order lane.
			\WCPOS\WooCommercePOS\Sync\Meta_Normalizer::register_hooks();
			add_filter( 'woocommerce_pos_sync_serialized_order', array( \WCPOS\WooCommercePOS\Sync\Pos_Uuid::class, 'stamp_serialized_record' ), 10, 3 );
			add_filter( 'woocommerce_pos_sync_order_pull_payloads', array( \WCPOS\WooCommercePOS\Sync\Integrity_Digest::class, 'stamp_proxy_order_digests' ), 10, 3 );
			// ONE seam for both product read lanes: the batch catalog proxy and the
			// per-object serializer. Every stamper is declared once inside; both
			// public filter names stay live as projections of it.
			\WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline::install();
		}

		// Identity is core, not an observer benchmark variable: every product is
		// born with a UUID even before the schema latch is healthy. The before-save
		// hook writes it in the same save.
		\WCPOS\WooCommercePOS\Sync\Pos_Uuid::register_hooks();

		if ( $sync_schema_latched ) {
			( new \WCPOS\WooCommercePOS\Sync\Sync_Journal() )->register_hooks();
			( new \WCPOS\WooCommercePOS\Sync\Sync_Journal_Purge() )->register_hooks();
			( new \WCPOS\WooCommercePOS\Sync\Integrity_Digest() )->register_hooks();
		}

		( new \WCPOS\WooCommercePOS\Sync\Config_Fingerprint() )->maybe_cleanup_legacy_options();

		// Init hooks.
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'init_rest_api' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );

		// Remove this once Pro settings have been moved to the new settings service.
		add_filter( 'pre_update_option_woocommerce_pos_pro_settings_license', array( self::class, 'remove_license_transient' ), 10, 2 );

		// Headers for API discoverability.
		add_filter( 'rest_pre_serve_request', array( $this, 'rest_pre_serve_request' ), 5, 4 );
		add_action( 'send_headers', array( $this, 'send_headers' ), 99, 1 );
		add_action( 'send_headers', array( $this, 'remove_x_frame_options' ), 9999, 1 );

		/*
		 * Add the global JWT authentication filter and its core-route audit guard.
		 *
		 * Hook order: plugins_loaded -> init (determine_current_user) -> rest_api_init
		 *
		 * This filter runs at priority 20, after WordPress core's cookie auth handlers.
		 * It must be registered here (during plugins_loaded) because determine_current_user
		 * fires during 'init', which is BEFORE rest_api_init where our API class loads.
		 * Because it authenticates WCPOS Bearer tokens on EVERY
		 * REST request (marked or not), the audit-meta guard for core routes
		 * must be registered just as unconditionally — never from the
		 * X-WCPOS-gated API class, whose marker an attacker simply omits.
		 * Registering it first lets its priority-20 provenance filter run after
		 * core's cookie handlers but before WCPOS's JWT filter.
		 */
		( new Services\Core_Order_Audit_Guard() )->register_hooks();

		// Coupon post-date touch. Unconditional and lane-agnostic on purpose: a
		// meta-only coupon edit (amount, discount_type, usage limits) never moves
		// post_modified, and the client's catalogue replication is date-based
		// (?modified_after, filtered by WooCommerce on post_modified_gmt), so an
		// untouched coupon is invisible to every other till. That is true whether
		// the edit came from the POS, wp-admin, WP-CLI or another plugin — so this
		// sits outside the schema latch above because it does not use the v2 sync
		// tables.
		\WCPOS\WooCommercePOS\Sync\Coupon_Modified_Date::register_hooks();

		add_filter( 'determine_current_user', array( $this, 'determine_current_user_early' ), 20 );
	}

	/**
	 * Clear cached data that depends on the Pro license.
	 *
	 * @param mixed $value     The new option value.
	 * @param mixed $old_value The previous option value (false when unset).
	 *
	 * @return mixed
	 */
	public static function remove_license_transient( $value, $old_value = false ) {
		// Pro's updater can react to the update_plugins deletion by reading —
		// and, when the stored instance id is blank, re-saving — the license
		// option, which re-enters this filter. Without the guard that cycle is
		// unbounded and OOMs the first license activation on a fresh install.
		static $clearing = false;
		if ( $clearing ) {
			return $value;
		}
		$clearing = true;
		delete_transient( 'woocommerce_pos_pro_license_status' );

		// The update caches bind to the license key and activation state. A
		// write that changes neither — e.g. Pro's read-side instance mint —
		// must not wipe update_plugins: Pro reacts to that deletion by
		// clearing its own update-data cache, which empties the payload of an
		// update check that is in flight when the mint occurs.
		$old = \is_array( $old_value ) ? $old_value : array();
		$new = \is_array( $value ) ? $value : array();
		if (
			(string) ( $old['key'] ?? '' ) !== (string) ( $new['key'] ?? '' )
			|| ! empty( $old['activated'] ) !== ! empty( $new['activated'] )
		) {
			delete_site_transient( 'update_plugins' );
		}
		$clearing = false;

		return $value;
	}

	/**
	 * Early authentication check for JWT tokens.
	 *
	 * This runs BEFORE rest_api_init, so we can authenticate users before WP REST API
	 * permission callbacks run. This is especially important for authorization via
	 * query parameter (?authorization=Bearer...) which some servers require.
	 *
	 * Note: We don't check for X-WCPOS header here because:
	 * 1. The header check uses getallheaders() which may not work in all environments
	 * 2. JWT authentication should work regardless - the token itself is proof of WCPOS usage
	 * 3. Invalid tokens (non-WCPOS) will fail validation anyway
	 *
	 * @param false|int $user_id User ID if one has been determined, false otherwise.
	 *
	 * @return false|int User ID if authenticated, original value otherwise.
	 */
	public function determine_current_user_early( $user_id ) {
		// Skip if user already authenticated.
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		$authenticated_user_id = AuthService::instance()->authenticate_request();
		if ( false === $authenticated_user_id || is_wp_error( $authenticated_user_id ) ) {
			return $user_id;
		}

		return $authenticated_user_id;
	}

	/**
	 * Load the required resources.
	 */
	public function init(): void {
		$this->init_common();
		$this->init_frontend();
		$this->init_admin();
		$this->init_integrations();
	}

	/**
	 * Loads the POS API and duck punches the WC REST API.
	 */
	public function init_rest_api(): void {
		$is_wcpos_request = woocommerce_pos_request();

		if ( $is_wcpos_request ) {
			if ( ! wcpos_request( 'header' ) && ! wcpos_request( 'query_var' ) ) {
				// Namespace-detected only: routes still register, but surface
				// that a proxy/WAF is stripping the X-WCPOS marker.
				$this->log_unmarked_wcpos_rest_request();
			}
			new API();
		} else {
			// Queue the registration at a later priority of the SAME
			// rest_api_init pass this method runs on (priority 20), so
			// register_rest_route() executes during the action as WP requires.
			// When this method is called outside the action (tests), the
			// add_action is simply inert.
			add_action( 'rest_api_init', array( $this, 'register_public_relay_routes' ), 30 );
			new WC_API();
		}
	}

	/**
	 * Register the relay's public consent-callback route for unmarked requests.
	 *
	 * The WCPOS Cloud Print relay proves site consent by fetching
	 * print-jobs/relay-verification WITHOUT the WCPOS request marker, so this
	 * single public route must exist even when the full WCPOS API is not
	 * loaded. Everything else stays behind the marker.
	 */
	public function register_public_relay_routes(): void {
		register_rest_route(
			SHORT_NAME . '/v1',
			'/print-jobs/relay-verification',
			array(
				'methods'             => 'GET',
				'callback'            => array( new API\V1\Print_Jobs_Controller(), 'relay_verification' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Log requests for a WCPOS namespace that omitted the request marker.
	 *
	 * Namespace detection registers the routes anyway; this surfaces that a
	 * proxy/WAF is stripping the X-WCPOS marker so misconfigured hosts stay
	 * visible in the logs. Warnings are limited by API version to avoid
	 * allowing repeated unauthenticated requests to flood WooCommerce logs.
	 */
	private function log_unmarked_wcpos_rest_request(): void {
		global $wp;

		$route = isset( $wp->query_vars['rest_route'] )
			? '/' . ltrim( sanitize_text_field( wp_unslash( (string) $wp->query_vars['rest_route'] ) ), '/' )
			: '';

		if ( 1 !== preg_match( '#^/wcpos/v([12])(?:/|$)#', $route, $matches ) ) {
			return;
		}

		// The relay's consent callback is expected unmarked traffic (see
		// register_public_relay_routes()), not a misconfigured client.
		if ( '/wcpos/v1/print-jobs/relay-verification' === $route ) {
			return;
		}

		$transient = 'wcpos_missing_request_marker_v' . $matches[1];
		if ( false !== get_transient( $transient ) ) {
			return;
		}

		set_transient( $transient, 1, 5 * MINUTE_IN_SECONDS );
		Logger::warning( $route . ': request marker missing (routes still registered via namespace detection).' );
	}

	/**
	 * Adds 'wcpos' to the query variables allowed before processing.
	 *
	 * Allows (publicly allowed) query vars to be added, removed, or changed prior
	 * to executing the query. Needed to allow custom rewrite rules using your own arguments
	 * to work, or any other custom query variables you want to be publicly available.
	 *
	 * @param string[] $query_vars The array of allowed query variable names.
	 *
	 * @return string[] The array of allowed query variable names.
	 */
	public function query_vars( array $query_vars ): array {
		$query_vars[] = SHORT_NAME;

		return $query_vars;
	}

	/**
	 * Allow pre-flight requests from WCPOS Desktop and Mobile Apps
	 * Note: pre-flight requests cannot have headers, so I can't filter by pos request
	 * See: https://fetch.spec.whatwg.org/#cors-preflight-fetch.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 *                                  Default false.
	 * @param WP_HTTP_Response $result  Result to send to the client. Usually a `WP_REST_Response`.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 *
	 * @return bool $served
	 */
	public function rest_pre_serve_request( $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ) {
		if ( 'OPTIONS' == $request->get_method() ) {
			$expose_headers = apply_filters( 'rest_exposed_cors_headers', array( 'X-WP-Total', 'X-WP-TotalPages', 'Link', 'X-Server-Load', 'Server-Timing', 'X-WCPOS-Memory-Peak', 'X-WCPOS-Pressure', 'ETag', 'Date' ), $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
			$server->send_header( 'Access-Control-Expose-Headers', implode( ', ', array_unique( $expose_headers ) ) );

			$allow_headers = array(
				'Authorization',            // For user-agent authentication with a server.
				'X-WP-Nonce',               // WordPress-specific header, used for CSRF protection.
				'Content-Disposition',      // Informs how to process the response data.
				'Content-MD5',              // For verifying data integrity.
				'Content-Type',             // Specifies the media type of the resource.
				'X-HTTP-Method-Override',   // Used to override the HTTP method.
				'X-WCPOS',                  // Used to identify WCPOS requests.
				'If-None-Match',            // Conditional sequence-log polling (304s).
			);
			$allow_headers = Sync\Header_Mirror::allow_cors_headers( $allow_headers );

			$server->send_header( 'Access-Control-Allow-Origin', '*' );
			$server->send_header( 'Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE' );
			$server->send_header( 'Access-Control-Allow-Headers', implode( ', ', $allow_headers ) );
		}

		return $served;
	}

	/**
	 * Allow HEAD checks for WP API Link URL and server uptime
	 * Fires once the requested HTTP headers for caching, content type, etc. have been sent.
	 *
	 * FIXME: Why is Link header not exposed sometimes on my development machine?
	 *
	 * @return void
	 */
	public function send_headers(): void {
		// some server convert HEAD to GET method, so use this query param instead.
		if ( isset( $_GET['_method'] ) && 'head' === strtolower( sanitize_text_field( wp_unslash( $_GET['_method'] ) ) ) ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Expose-Headers: Link' );
		}
	}

	/**
	 * Some security plugins will set X-Frame-Options: SAMEORIGIN/DENY, which will prevent the POS desktop
	 * application from opening pages like the login in an iframe.
	 *
	 * For pages we need, we will remove the X-Frame-Options header.
	 *
	 * @param mixed $wp The WP object.
	 *
	 * @return void
	 */
	public function remove_x_frame_options( $wp ): void {
		if ( woocommerce_pos_request() || isset( $wp->query_vars['wcpos-login'] ) ) {
			if ( ! headers_sent() && \function_exists( 'header_remove' ) ) {
				header_remove( 'X-Frame-Options' );
			}
		}
	}

	/**
	 * Common initializations.
	 */
	private function init_common(): void {
		// init the Services.
		SettingsService::instance();
		AuthService::instance();
		Extensions::instance();
		Receipt_Snapshot_Store::instance();

		// init other functionality needed by both frontend and admin.
		new i18n();
		new Gateways();
		new Products();
		new Orders();
		new Emails();
		new Templates();
		new Services\Stock_Validator();
		new Services\Decimal_Quantities();
		new Services\Customer_Meta_Parity();
		new Services\Print_Job_Service();
		new Services\Cloud_Print_Trigger_Service();
		new Services\Cloud_Print_Submit_Service();
		new Services\Cloud_Print_Relay_Service();
	}

	/**
	 * Frontend specific initializations.
	 */
	private function init_frontend(): void {
		if ( ! is_admin() ) {
			new Template_Router();
			new Form_Handler();
			new Storefront_Receipts();
		}
	}

	/**
	 * Admin specific initializations.
	 */
	private function init_admin(): void {
		if ( is_admin() ) {
			// Register AJAX handler before the branch so it's available during AJAX requests.
			add_action( 'wp_ajax_wcpos_track_upgrade_click_ajax', array( Menu::class, 'handle_upgrade_click_ajax' ) );
			add_action( 'admin_post_wcpos_track_upgrade_click', array( Menu::class, 'handle_upgrade_click_redirect' ) );

			if ( wp_doing_ajax() ) {
				new AJAX();
			} else {
				new Admin();
			}
		}
	}

	/**
	 * Integrations.
	 */
	private function init_integrations(): void {
		// WooCommerce Bookings - http://www.woothemes.com/products/woocommerce-bookings/
		// if ( class_exists( 'WC-Bookings' ) ) {
		// new Integrations\Bookings();
		// }.

		// Yoast SEO - https://wordpress.org/plugins/wordpress-seo/.
		if ( class_exists( 'WPSEO_Options' ) ) {
			new Integrations\WPSEO();
		}

		// wePOS alters the WooCommerce REST API, breaking the expected schema
		// It's very bad form on their part, but we need to work around it.
		new Integrations\WePOS();
	}
}
