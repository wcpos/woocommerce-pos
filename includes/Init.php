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

/**
 * Init class.
 */
class Init {
	/**
	 * Observer awaiting the constructor's non-hook seed step.
	 *
	 * @var Sync\Visibility_Observer|null
	 */
	private $visibility_observer;

	/**
	 * Install the ordered wiring declared by {@see hook_rows()}.
	 *
	 * Non-hook setup stays here, interleaved at its original registration boundaries.
	 */
	public function __construct() {
		require_once PLUGIN_PATH . 'includes/wcpos-functions.php';
		require_once PLUGIN_PATH . 'includes/wcpos-store-functions.php';
		wp_cache_add_global_groups( 'wc_pos_user_uuid_locks' );

		$rows = $this->hook_rows( true );
		Hook_Manifest::validate( $rows );
		Hook_Manifest::install( wp_list_filter( $rows, array( 'phase' => 'pre-latch' ) ) );
		$sync_latched = Sync\Api::SCHEMA_VERSION === get_option( Sync\Api::SCHEMA_OPTION, null );
		foreach ( $rows as $row ) {
			if ( 'pre-latch' === $row['phase'] || ( 'sync-latched' === $row['phase'] && ! $sync_latched ) ) {
				continue;
			}
			if ( array( $this, 'init' ) === $row['callback'] ) {
				( new Sync\Config_Fingerprint() )->maybe_cleanup_legacy_options();
			}
			Hook_Manifest::install( array( $row ) );
			if ( null !== $this->visibility_observer ) {
				$this->visibility_observer->maybe_seed_hidden_tombstones();
				$this->visibility_observer = null;
			}
		}
	}

	/**
	 * Declare bootstrap wiring in registration order, without installing it.
	 *
	 * Null hooks invoke registrars immediately; their internal priorities/arity stay
	 * in register_hooks(). Phases keep the schema read after pre-latch hooks;
	 * sync-latched rows are post-read hooks omitted when the latch is down.
	 * The guard registrar MUST precede the JWT row: both register at priority 20,
	 * after core cookie/application-password handlers. Reversing them attributes
	 * JWT identity to prior authentication and fails open on /wc/v3/orders.
	 *
	 * @param bool $sync_latched Whether the verified sync schema latch is set.
	 * @return array Ordered rows consumed by Hook_Manifest::install().
	 */
	public function hook_rows( bool $sync_latched ): array {
		$rows = array(
			array(
				'hook'     => null,
				'callback' => static function (): void {
					new Consent();
				},
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Default 10; lifecycle hooks must exist during plugins_loaded, before activation/update actions.',
				'phase'    => 'pre-latch',
			),
			array(
				'hook'     => 'woocommerce_pos_rest_api_controllers',
				'callback' => array( Sync\Api::class, 'register_controllers' ),
				'priority' => 10,
				'args'     => 1,
				'reason'   => 'Default; sole callback. Response registrars stay inside this filter to retain REST activation timing.',
				'phase'    => 'pre-latch',
			),
			array(
				'hook'     => Sync\Integrity_Digest::REBUILD_HOOK,
				'callback' => array( Sync\Integrity_Digest::class, 'run_scheduled_rebuild' ),
				'priority' => 10,
				'args'     => 1,
				'reason'   => 'Default; sole callback. Unlatched so an already-scheduled rebuild still has a listener.',
				'phase'    => 'pre-latch',
			),
			array(
				'hook'     => null,
				'callback' => array( Sync\Meta_Normalizer::class, 'register_hooks' ),
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Priority 5 before revision 9 and augmentation 10, so revisions match bare wc/v3 rereads; also serves orders.',
				'phase'    => 'sync-latched',
			),
			array(
				'hook'     => 'woocommerce_pos_sync_serialized_order',
				'callback' => array( Sync\Pos_Uuid::class, 'stamp_serialized_record' ),
				'priority' => 10,
				'args'     => 3,
				'reason'   => 'After normalization at 5, in step with product stampers at 10.',
				'phase'    => 'sync-latched',
			),
			array(
				'hook'     => null,
				'callback' => array( Sync\Augmentation_Pipeline::class, 'install' ),
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Revision 9 hashes normalized, unaugmented bytes; UUID/digest/projections at 10 preserve extension order, including order-pull digests.',
				'phase'    => 'sync-latched',
			),
			array(
				'hook'     => null,
				'callback' => array( Sync\Pos_Uuid::class, 'register_hooks' ),
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Default 10; identity is unconditional: before-save UUIDs land in the same write and native restores re-prove ownership (ADR 0038).',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => null,
				'callback' => static function (): void {
					( new Sync\Sync_Journal() )->register_hooks();
				},
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Default 10; dirty order updates coalesce until shutdown at PHP_INT_MAX, after WooCommerce customer 10/session 20 saves.',
				'phase'    => 'sync-latched',
			),
			array(
				'hook'     => null,
				'callback' => function (): void {
					$this->visibility_observer = new Sync\Visibility_Observer();
					$this->visibility_observer->register_hooks();
				},
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Default 10 after journal; records servable-set transitions, using generic pre-delete_option; Init then seeds tombstones.',
				'phase'    => 'sync-latched',
			),
			array(
				'hook'     => null,
				'callback' => static function (): void {
					( new Sync\Sync_Journal_Purge() )->register_hooks();
				},
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Default 10; sole cron listener; the registrar also schedules the daily purge.',
				'phase'    => 'sync-latched',
			),
			array(
				'hook'     => null,
				'callback' => static function (): void {
					( new Sync\Integrity_Digest() )->register_hooks();
				},
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Default 10, shutdown PHP_INT_MAX; journal registers first on shared hooks (reason unknown); dirty digests coalesce until flush.',
				'phase'    => 'sync-latched',
			),
			array(
				'hook'     => 'init',
				'callback' => array( $this, 'init' ),
				'priority' => 10,
				'args'     => 1,
				'reason'   => 'Default 10; free services must exist before Pro init at 20.',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => 'rest_api_init',
				'callback' => array( $this, 'init_rest_api' ),
				'priority' => 20,
				'args'     => 1,
				'reason'   => 'Original reason unknown (8f2b9eac); Pro deliberately registers before free at 9.',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => 'query_vars',
				'callback' => array( $this, 'query_vars' ),
				'priority' => 10,
				'args'     => 1,
				'reason'   => 'Default; appends one variable.',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => 'pre_update_option_woocommerce_pos_pro_settings_license',
				'callback' => array( self::class, 'remove_license_transient' ),
				'priority' => 10,
				'args'     => 2,
				'reason'   => 'Default; the reentrancy guard, not priority, makes legacy Pro license cache invalidation safe (f33b8d655).',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => null,
				'callback' => array( Rest_Cors::class, 'register_hooks' ),
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Unconditional for unmarked preflights/relay; serve at 20 after core CORS at 10 so WCPOS is the last writer.',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => 'send_headers',
				'callback' => array( $this, 'send_headers' ),
				'priority' => 99,
				'args'     => 1,
				'reason'   => 'Unknown beyond running late for WPSEO integration (62da70551).',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => 'send_headers',
				'callback' => array( $this, 'remove_x_frame_options' ),
				'priority' => 9999,
				'args'     => 1,
				'reason'   => 'Must remove X-Frame-Options AFTER security plugins set it (80ee545a5).',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => null,
				'callback' => static function (): void {
					( new Services\Core_Order_Audit_Guard() )->register_hooks();
				},
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Auth provenance at 20 MUST register before JWT at 20, after core cookie/password auth; rest_pre_dispatch reads it at 10.',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => null,
				'callback' => array( Sync\Coupon_Modified_Date::class, 'register_hooks' ),
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Default 10; unconditional meta-only coupon edit timestamps for date-based replication; journal uses wall clock, not post_modified.',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => 'determine_current_user',
				'callback' => array( $this, 'determine_current_user_early' ),
				'priority' => 20,
				'args'     => 1,
				'reason'   => 'At 20 AFTER the audit guard and core cookie/password handlers; register before init, regardless of the request marker.',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => null,
				'callback' => static function (): void {
					( new Services\Lifecycle_Events() )->register_hooks();
				},
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Default 10; append after auth pair; admin_init flushes pending events and consent-gates refresh scheduling, not the cron listener.',
				'phase'    => 'post-latch',
			),
			array(
				'hook'     => null,
				'callback' => static function (): void {
					Services\Error_Reporter::instance()->register_hooks();
				},
				'priority' => 10,
				'args'     => 0,
				'reason'   => 'Append after lifecycle; REST priority 999 reports the final response status, gated by consent (#1811).',
				'phase'    => 'post-latch',
			),
		);

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $sync_latched ): bool {
					return $sync_latched || 'sync-latched' !== $row['phase'];
				}
			)
		);
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
	 * Allow HEAD checks for WP API Link URL and server uptime.
	 *
	 * This is the NON-REST lane and is not part of the REST wire contract
	 * ({@see Rest_Cors}): `send_headers` fires from `WP::main()`, which a REST
	 * request never reaches — core's `rest_api_loaded()` runs on
	 * `parse_request` and dies. What it serves is the app's site-discovery
	 * probe against an ordinary page: the app reads the `Link:
	 * <.../wp-json/>; rel="https://api.w.org/"` header cross-origin to find
	 * the REST root, which needs both headers below. Some servers turn HEAD
	 * into GET, hence the `?_method=head` query param rather than the method.
	 *
	 * This is live, not legacy. The client calls it on every Connect:
	 * `packages/core/src/screens/auth/hooks/use-url-discovery.ts` issues
	 * `http.head()` against the site root, and
	 * `packages/hooks/src/use-http-client/use-http-client.tsx` sets
	 * `params._method = 'HEAD'` on every HEAD request (both in the client
	 * monorepo). That same client code deliberately omits the `X-WCPOS`
	 * marker for HEAD, so this handler cannot be marker-gated and must stay
	 * unconditional. 521ccb9a added it; the `?wcpos=1` gate it originally
	 * carried is long gone.
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
	 * Groups constructed so far in this request (test seam; see constructed_groups()).
	 *
	 * @var array<string, bool>
	 */
	private static array $constructed = array();

	/**
	 * Common initializations, by request lane.
	 *
	 * Every request gets the services whose hooks WooCommerce consults on a
	 * plain shopper page BEFORE any order exists: translations, the product
	 * visibility filters, the order statuses and the read-side order filters
	 * (My Account renders POS orders), the gateway registration (WooCommerce
	 * builds its gateway list on cart pages) and the reserved-stock filter
	 * (POS drafts must reduce online availability at add-to-cart time).
	 *
	 * Everything else is constructed only on the lanes that use it, and the
	 * order-event services additionally on the first order write of ANY request
	 * ({@see ensure_order_services()}), so the lane classifier is an
	 * optimisation rather than a correctness gate. Measured 2026-09-03: a
	 * storefront page loaded ~80 plugin files and 22 objects for hooks that
	 * never fire there (see .claude/research/2026-09-03-lazy-service-construction-spec.md).
	 */
	private function init_common(): void {
		self::$constructed['always'] = true;

		// init the Services.
		SettingsService::instance();
		AuthService::instance();

		// Needed on every lane, including a plain storefront page.
		new i18n();
		new Gateways();
		new Products();
		new Orders();
		Services\Stock_Validator::instance();
		Services\Order_Write_Intent::register();

		if ( Services\Request_Lane::is_storefront() ) {
			// Order-event services arrive on the first order write, if any.
			self::arm_order_services();
			return;
		}

		self::ensure_order_services();
		self::construct_pos_services();
	}

	/**
	 * Services only POS, admin, REST, cron and CLI requests use.
	 */
	private static function construct_pos_services(): void {
		if ( isset( self::$constructed['pos'] ) ) {
			return;
		}
		self::$constructed['pos'] = true;
		Extensions::instance();
		new Services\Decimal_Quantities();
		new Services\Customer_Meta_Parity();
	}

	/**
	 * Hook the order-event services to the first order write of the request.
	 *
	 * Every WooCommerce order write — create, update, status transition,
	 * `payment_complete()`, refund — goes through `WC_Abstract_Order::save()`,
	 * which fires `woocommerce_before_order_object_save` before the data store
	 * writes and before `woocommerce_new_order` / `woocommerce_order_status_changed`
	 * / `woocommerce_payment_complete` fire. Priority 0 there means every
	 * observer exists before any order is written — on a webhook, a cron
	 * spawned from a page view, a third-party plugin creating an order on
	 * `template_redirect`, or a lane the classifier got wrong. Nothing in the
	 * order group listens to trash or delete, so those need no arming.
	 */
	private static function arm_order_services(): void {
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'ensure_order_services' ), 0, 0 );
	}

	/**
	 * Construct the order-event services exactly once per request.
	 *
	 * Idempotent and safe to call after `init`; each service handles its own
	 * late registration. Fires `woocommerce_pos_order_services_ready` once so
	 * Pro and extensions can construct their own order-event services at the
	 * same moment on every lane.
	 */
	public static function ensure_order_services(): void {
		if ( isset( self::$constructed['order'] ) ) {
			return;
		}
		self::$constructed['order'] = true;

		Receipt_Snapshot_Store::instance();
		new Emails();
		new Templates();
		new Services\Print_Job_Service();
		new Services\Cloud_Print_Trigger_Service();
		new Services\Cloud_Print_Submit_Service();
		new Services\Cloud_Print_Relay_Service();

		/**
		 * Fires once per request when the POS order-event services exist:
		 * eagerly on POS, admin, REST, cron and CLI requests (from this
		 * plugin's `init` callback at priority 10), and on a storefront
		 * request the moment the first order is about to be written.
		 *
		 * Because the eager firing happens at `init` priority 10, a listener
		 * added later than that (for example from another plugin's `init`
		 * callback at priority 20) must check `did_action()` first and
		 * construct immediately when the action has already fired.
		 *
		 * @since 1.10.8
		 */
		do_action( 'woocommerce_pos_order_services_ready' );
	}

	/**
	 * Which service groups this request has constructed: 'always', 'order', 'pos'.
	 *
	 * @internal Test seam.
	 *
	 * @return string[]
	 */
	public static function constructed_groups(): array {
		return array_keys( self::$constructed );
	}

	/**
	 * Forget which groups were constructed. Tests only.
	 *
	 * @internal
	 */
	public static function reset_request_state(): void {
		self::$constructed = array();
		Services\Request_Lane::reset();
	}

	/**
	 * Frontend specific initializations.
	 */
	private function init_frontend(): void {
		if ( is_admin() ) {
			return;
		}
		// The public receipt shortcode and the My Account receipt action are
		// storefront features; they construct the template services when used.
		new Storefront_Receipts();
		if ( ! Services\Request_Lane::is_storefront() ) {
			// The POS routes (rewrite rules, checkout context, order-pay and
			// coupon forms) only matter on requests the classifier saw as POS.
			new Template_Router();
			new Form_Handler();
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
		// Its only hook is admin_init (a conflict notice), so admin lane only.
		if ( is_admin() ) {
			new Integrations\WePOS();
		}

		// WooCommerce Tax - https://wordpress.org/plugins/woocommerce-services/
		// Its class exists whenever the plugin is active, but its callbacks are
		// only hooked when automated taxes are on and the store country is
		// supported, so the integration looks them up on the hooks at
		// recalculation time instead of gating on the class here.
		new Integrations\WooCommerce_Tax();
	}
}
