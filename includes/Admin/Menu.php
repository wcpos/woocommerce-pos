<?php
/**
 * WP Admin Menu Class.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin;

use WCPOS\WooCommercePOS\Services\Analytics;
use WCPOS\WooCommercePOS\Services\Landing_Profile;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\TRANSLATION_VERSION;
use const WCPOS\WooCommercePOS\VERSION as PLUGIN_VERSION;

/**
 * Menu class.
 */
class Menu {
	/**
	 * Unique top level menu identifier.
	 *
	 * @var string
	 */
	public $toplevel_screen_id;

	/**
	 * Unique top level menu identifier.
	 *
	 * @var string
	 */
	public $settings_screen_id;

	/**
	 * Gallery submenu page hook suffix.
	 *
	 * @var string
	 */
	public $gallery_screen_id;

	/**
	 * View POS submenu page hook suffix.
	 *
	 * @var string
	 */
	public $view_pos_screen_id;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( current_user_can( 'manage_woocommerce_pos' ) ) {
			$this->register_pos_admin();
			add_filter( 'custom_menu_order', '__return_true' );
			add_filter( 'menu_order', array( $this, 'menu_order' ), 9, 1 );
			add_filter( 'parent_file', array( $this, 'highlight_templates_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_landing_scripts_and_styles' ) );
			add_action( 'admin_footer', array( $this, 'print_upgrade_click_tracking_script' ) );
			add_action( 'admin_init', array( $this, 'redirect_template_list_page' ) );
		}

		// add_filter( 'woocommerce_analytics_report_menu_items', array( $this, 'analytics_menu_items' ) );.
	}

	/**
	 * Filters the order of administration menu items.
	 *
	 * A truthy value must first be passed to the {@see 'custom_menu_order'} filter
	 * for this filter to work. Use the following to enable custom menu ordering:
	 *
	 *     add_filter( 'custom_menu_order', '__return_true' );
	 *
	 * @param array $menu_order An ordered array of menu items.
	 *
	 * @return array
	 */
	public function menu_order( array $menu_order ): array {
		$woo = array_search( 'woocommerce', $menu_order, true );
		$pos = array_search( PLUGIN_NAME, $menu_order, true );

		if ( false !== $woo && false !== $pos ) {
			// rearrange menu.
			unset( $menu_order[ $pos ] );
			array_splice( $menu_order, ++$woo, 0, PLUGIN_NAME );

			// rearrange submenu.
			global $submenu;
			$pos_submenu      = &$submenu[ PLUGIN_NAME ];
			$pos_submenu[500] = $pos_submenu[1];
			unset( $pos_submenu[1] );
		}

		return $menu_order;
	}

	/**
	 * Render the upgrade page.
	 */
	public function display_upgrade_page(): void {
		include_once 'views/upgrade.php';
	}

	/**
	 * Add POS submenu to WooCommerce Analytics menu.
	 *
	 * @param array $report_pages The analytics report pages.
	 */
	public function analytics_menu_items( array $report_pages ): array {
		// Find the position of the 'Orders' item.
		$position = array_search( 'Orders', array_column( $report_pages, 'title' ), true );

		// Use array_splice to add the new item.
		array_splice(
			$report_pages,
			$position + 1,
			0,
			array(
				array(
					'id'       => 'woocommerce-analytics-pos',
					'title'    => __( 'POS', 'woocommerce-pos' ),
					'parent'   => 'woocommerce-analytics',
					'path'     => '/analytics/pos',
					'nav_args' => array(
						'order'  => 45,
						'parent' => 'woocommerce-analytics',
					),
				),
			)
		);

		return $report_pages;
	}

	/**
	 * Add POS to Admin sidebar.
	 */
	private function register_pos_admin(): void {
		$this->toplevel_screen_id = add_menu_page(
			__( 'POS', 'woocommerce-pos' ),
			__( 'POS', 'woocommerce-pos' ),
			'manage_woocommerce_pos',
			PLUGIN_NAME,
			array( $this, 'display_upgrade_page' ),
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjYwIDEyNjAiPgo8cGF0aCBmaWxsPSIjYTdhYWFkIiBkPSJNMTE3MCwwaC05MEg5MDBINzIwSDU0MEgzNjBIMTgwSDkwQzMwLDAsMCwzMCwwLDkwdjE4MGMwLDQ5LjcsNDAuMyw5MCw5MCw5MHM5MC00MC4zLDkwLTkwVjkwaDE4MHYxODAKCWMwLDQ5LjcsNDAuMyw5MCw5MCw5MHM5MC00MC4zLDkwLTkwVjkwaDE4MHYxODBjMCw0OS43LDQwLjMsOTAsOTAsOTBzOTAtNDAuMyw5MC05MFY5MGgxODB2MTgwYzAsNDkuNyw0MC4zLDkwLDkwLDkwczkwLTQwLjMsOTAtOTAKCVY5MEMxMjYwLDMwLDEyMzAsMCwxMTcwLDB6Ii8+CjxwYXRoIGZpbGw9IiNhN2FhYWQiIGQ9Ik0xMDgwLDM2MGMtNDUsNDUtMTM1LDQ1LTE4MCwwYy00NSw0NS0xMzUsNDUtMTgwLDBjLTQ1LDQ1LTEzNSw0NS0xODAsMGMtNDUsNDUtMTM1LDQ1LTE4MCwwYy00NSw0NS0xMzUsNDUtMTgwLDAKCWMtNDUsNDUtMTM1LDQ1LTE4MCwwdjkwMGwzNjAtMjcwaDgxMGM2MCwwLDkwLTMwLDkwLTkwVjM2MEMxMjE1LDQwNSwxMTI1LDQwNSwxMDgwLDM2MHogTTI2MC41LDgyOGMtMzUuNSwwLTY4LjUtMTEuMy05NS41LTMwLjQKCXYxMjUuOWMwLDE5LjMtMTUuNywzNS0zNSwzNXMtMzUtMTUuNy0zNS0zNVY1MzJjMC0xOS4zLDE1LjctMzUsMzUtMzVjMTcuOCwwLDMyLjYsMTMuMywzNC43LDMwLjZjMjcuMS0xOS4zLDYwLjEtMzAuNiw5NS44LTMwLjYKCWM5MS4zLDAsMTY1LjUsNzQuMiwxNjUuNSwxNjUuNVMzNTEuOCw4MjgsMjYwLjUsODI4eiBNNjMwLDgyOGMtOTEuNSwwLTE2Ni03NC41LTE2Ni0xNjZjMC05MS41LDc0LjUtMTY2LDE2Ni0xNjYKCWM5MS41LDAsMTY2LDc0LjUsMTY2LDE2NkM3OTYsNzUzLjUsNzIxLjUsODI4LDYzMCw4Mjh6IE05MTguMyw2MTMuOWMxMS41LDUuOCwzNS4xLDEyLjYsODIuMiwxMi42YzQ5LjUsMCw4Ni42LDYuNSwxMTMuNSwyMAoJYzMzLjUsMTYuOCw1Miw0NS4zLDUyLDgwLjJzLTE4LjUsNjMuNS01Miw4MC4yYy0yNi45LDEzLjUtNjQuMSwyMC0xMTMuNSwyMEM5NDYsODI3LDg5Niw4MTMuNiw4NTIsNzg3LjJjLTE2LjYtOS45LTIyLTMxLjQtMTItNDgKCWM5LjktMTYuNiwzMS40LTIyLDQ4LTEyYzMzLDE5LjgsNzAuOCwyOS44LDExMi41LDI5LjhjNDcuMSwwLDcwLjctNi45LDgyLjItMTIuNmM3LjMtMy42LDEzLjMtNy41LDEzLjMtMTcuNnMtNi0xNC0xMy4zLTE3LjYKCWMtMTEuNS01LjgtMzUuMS0xMi42LTgyLjItMTIuNmMtNDkuNSwwLTg2LjYtNi41LTExMy41LTIwYy0zMy41LTE2LjgtNTItNDUuMy01Mi04MC4yYzAtMzUsMTguNS02My41LDUyLTgwLjIKCWMyNi45LTEzLjUsNjQuMS0yMCwxMTMuNS0yMGM1NC41LDAsMTA0LjUsMTMuNCwxNDguNSwzOS44YzE2LjYsOS45LDIyLDMxLjQsMTIsNDhjLTkuOSwxNi42LTMxLjQsMjEuOS00OCwxMgoJYy0zMy0xOS44LTcwLjgtMjkuOC0xMTIuNS0yOS44Yy00Ny4xLDAtNzAuNyw2LjktODIuMiwxMi42Yy03LjMsMy42LTEzLjMsNy41LTEzLjMsMTcuNlM5MTEsNjEwLjMsOTE4LjMsNjEzLjl6Ii8+CjxjaXJjbGUgZmlsbD0iI2E3YWFhZCIgY3g9IjYzMCIgY3k9IjY2MiIgcj0iOTYiLz4KPGNpcmNsZSBmaWxsPSIjYTdhYWFkIiBjeD0iMjYwLjUiIGN5PSI2NjIuNSIgcj0iOTUuNSIvPgo8L3N2Zz4K'
		);

		$this->view_pos_screen_id = add_submenu_page(
			PLUGIN_NAME,
			__( 'View POS', 'woocommerce-pos' ),
			__( 'View POS', 'woocommerce-pos' ),
			'manage_woocommerce_pos',
			PLUGIN_NAME . '-view-pos',
		);

		$this->settings_screen_id = add_submenu_page(
			PLUGIN_NAME,
			// translators: wordpress.
			__( 'Settings', 'woocommerce-pos' ),
			// translators: wordpress.
			__( 'Settings', 'woocommerce-pos' ),
			'manage_woocommerce_pos',
			PLUGIN_NAME . '-settings',
			array( '\WCPOS\WooCommercePOS\Admin\Settings', 'display_settings_page' )
		);

		// Template Gallery SPA page.
		$this->gallery_screen_id = add_submenu_page(
			PLUGIN_NAME,
			__( 'Templates', 'woocommerce-pos' ),
			__( 'Templates', 'woocommerce-pos' ),
			'manage_woocommerce_pos',
			'wcpos-templates',
			array( $this, 'render_gallery_page' )
		);
		add_action( 'load-' . $this->gallery_screen_id, array( $this, 'enqueue_gallery_assets' ) );

		// adjust submenu.
		global $submenu;
		$pos_submenu       = &$submenu[ PLUGIN_NAME ];
		$pos_submenu[0][0] = __( 'Upgrade to Pro', 'woocommerce-pos' );
		$pos_submenu[0][2] = self::get_upgrade_tracking_url(
			'menu_submenu',
			admin_url( 'admin.php?page=' . PLUGIN_NAME )
		);
		$pos_submenu[1][2] = woocommerce_pos_url();

		Analytics::instance()->capture(
			'upgrade_cta_viewed',
			array(
				'placement' => 'menu_submenu',
			)
		);

		/*
		 * Fires after POS admin menus are registered.
		 *
		 * The array arguments, `$this->toplevel_screen_id` and
		 * `$this->settings_screen_id`, refers to the top-level POS menu ID and
		 * settings submenu ID respectively.
		 *
		 * @since 1.0.0
		 *
		 * @param array $menus {
		 *     An array of admin menu IDs.
		 *
		 *     @type string $toplevel The top-level POS menu ID.
		 *     @type string $settings The settings submenu ID.
		 * }
		 */
		do_action(
			'woocommerce_pos_register_pos_admin',
			array(
				'toplevel' => $this->toplevel_screen_id,
				'settings' => $this->settings_screen_id,
			)
		);
	}

	/**
	 * Enqueue landing page scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_landing_scripts_and_styles( $hook_suffix ): void {
		if ( $hook_suffix === $this->toplevel_screen_id ) {
			$analytics = Analytics::instance();
			$site_id   = $analytics->get_site_id();

			$analytics->capture(
				'upgrade_cta_viewed',
				array(
					'placement' => 'admin_landing_banner',
				)
			);

			if ( '' !== $site_id ) {
				$analytics->group( 'site', $site_id, array() );
			}

			$is_development = isset( $_ENV['DEVELOPMENT'] )
			&& wp_validate_boolean( sanitize_text_field( wp_unslash( $_ENV['DEVELOPMENT'] ) ) );
			$url            = $is_development ? 'http://localhost:9000/' : 'https://cdn.jsdelivr.net/gh/wcpos/wp-admin-landing@2/assets/';

			// Enqueue the landing page CSS from CDN.
			wp_enqueue_style(
				'wcpos-welcome',
				$url . 'css/welcome.css',
				array(),
				PLUGIN_VERSION
			);

			// Ensure WordPress bundled React and lodash are loaded as dependencies.
			wp_enqueue_script( 'react' );
			wp_enqueue_script( 'lodash' );

			// Enqueue the landing page JS from CDN, with React and lodash as dependencies.
			wp_enqueue_script(
				'wcpos-welcome',
				$url . 'js/welcome.js',
				array(
					'react',
					'react-dom',
					'wp-element',
					'lodash',
				),
				PLUGIN_VERSION,
				true
			);

			wp_add_inline_script( 'wcpos-welcome', $this->landing_inline_script(), 'before' );
			wp_add_inline_script( 'wcpos-welcome', self::get_posthog_inline_script(), 'before' );
			wp_add_inline_script( 'wcpos-welcome', $this->landing_tracking_inline_script(), 'after' );
		}
	}

	/**
	 * Generate the inline script that exposes the analytics client.
	 *
	 * When the user has explicitly allowed tracking, loads the PostHog
	 * async SDK, initializes it with the configured token/host, and
	 * identifies the current user + site.
	 *
	 * When consent has not been granted, exposes a no-op stub at
	 * `window.wcpos.posthog` so that future UI event helpers can call
	 * `.capture()` etc. unconditionally without throwing — and without
	 * any network traffic leaving the browser.
	 */
	public static function get_posthog_inline_script(): string {
		$analytics = Analytics::instance();
		$noop_stub = '(function(){var w=window.wcpos=window.wcpos||{};w.posthog={capture:function(){},identify:function(){},group:function(){},register:function(){},reset:function(){},opt_in_capturing:function(){},opt_out_capturing:function(){}};})();';

		if ( ! $analytics->is_enabled() ) {
			return $noop_stub;
		}

		$token   = wp_json_encode( $analytics->get_token() );
		$host    = wp_json_encode( $analytics->get_host() );
		$site_id = wp_json_encode( $analytics->get_site_id() );
		$user_id = wp_json_encode( $analytics->get_distinct_id() );

		// If any value fails to encode (e.g. malformed UTF-8 coming
		// from a filter override), fall back to the no-op stub rather
		// than emitting `posthog.init(, { api_host: , ... })`.
		if ( false === $token || false === $host || false === $site_id || false === $user_id ) {
			return $noop_stub;
		}

		// phpcs:disable Generic.Files.LineLength.TooLong -- PostHog snippet is a single line by design.
		$snippet = <<<JS
(function() {
	var wcpos = window.wcpos = window.wcpos || {};
	!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug getPageViewId captureTraceFeedback captureTraceMetric".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
	posthog.init(%TOKEN%, { api_host: %HOST%, capture_pageview: false, autocapture: false, persistence: 'localStorage+cookie', disable_session_recording: true });
	wcpos.posthog = posthog;
	var distinctId = %USER_ID%;
	var siteId = %SITE_ID%;
	if (distinctId) { posthog.identify(distinctId); }
	if (siteId) { posthog.group('site', siteId); }
})();
JS;
		// phpcs:enable Generic.Files.LineLength.TooLong

		return str_replace(
			array( '%TOKEN%', '%HOST%', '%USER_ID%', '%SITE_ID%' ),
			array( $token, $host, $user_id, $site_id ),
			$snippet
		);
	}

	/**
	 * Build an admin-post URL that tracks an upgrade click before redirecting.
	 *
	 * @param string $placement   Stable CTA placement identifier.
	 * @param string $destination Final redirect URL.
	 *
	 * @return string
	 */
	public static function get_upgrade_tracking_url( string $placement, string $destination ): string {
		return add_query_arg(
			array(
				'action'      => 'wcpos_track_upgrade_click',
				'placement'   => $placement,
				'destination' => $destination,
				'_wpnonce'    => wp_create_nonce( 'wcpos_track_upgrade_click' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Track an upgrade click and return a safe redirect destination.
	 *
	 * @param string $placement   Stable CTA placement identifier.
	 * @param string $destination Final redirect URL.
	 *
	 * @return string
	 */
	public static function track_upgrade_click( string $placement, string $destination ): string {
		$safe_destination = self::sanitize_upgrade_destination( $destination );

		Analytics::instance()->capture(
			'upgrade_cta_clicked',
			array(
				'placement'   => sanitize_key( $placement ),
				'destination' => $safe_destination,
			)
		);

		return $safe_destination;
	}

	/**
	 * Handle admin-post upgrade click tracking and redirect.
	 */
	public static function handle_upgrade_click_redirect(): void {
		check_admin_referer( 'wcpos_track_upgrade_click' );

		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woocommerce-pos' ) );
		}

		$placement   = isset( $_GET['placement'] ) ? sanitize_text_field( wp_unslash( $_GET['placement'] ) ) : '';
		$destination = isset( $_GET['destination'] ) ? sanitize_text_field( wp_unslash( $_GET['destination'] ) ) : '';
		$redirect_to = self::track_upgrade_click( $placement, $destination );

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Generate the inline script for landing page data.
	 *
	 * Always emits functional data (locale, version, pro status).
	 * Merges in store profile and updates-server config only when
	 * the user has explicitly allowed tracking.
	 *
	 * @return string
	 */
	private function landing_inline_script(): string {
		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$profile    = new Landing_Profile();
		$data       = $profile->get_functional_data();

		$consent = woocommerce_pos_get_settings( 'general', 'tracking_consent' );
		if ( 'allowed' === $consent ) {
			$data = array_merge( $data, $profile->get_consented_data() );
		}

		$encoded = wp_json_encode( $data, $json_flags );

		if ( false === $encoded ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WCPOS landing data JSON encoding failed: ' . json_last_error_msg() );
			$encoded = '{}';
		}

		return \sprintf(
			'var wcpos = wcpos || {}; wcpos.landing = %s;',
			$encoded
		);
	}

	/**
	 * Track clicks from the remote upgrade landing content.
	 *
	 * @return string
	 */
	private function landing_tracking_inline_script(): string {
		return <<<JS
(function() {
	var root = document.getElementById('woocommerce-pos-upgrade');
	if (!root) {
		return;
	}

	document.addEventListener('click', function(event) {
		var target = event.target;
		if (!target || !target.closest) {
			return;
		}

		var link = target.closest('#woocommerce-pos-upgrade a[href*="wcpos.com/pro"]');
		if (!link || !window.wcpos || !window.wcpos.posthog || !window.wcpos.posthog.capture) {
			return;
		}

		window.wcpos.posthog.capture('upgrade_cta_clicked', {
			placement: 'admin_landing_banner',
			destination: link.href
		});
	});
})();
JS;
	}

	/**
	 * Print a tiny global click tracker for PHP-rendered admin upsell links.
	 */
	public function print_upgrade_click_tracking_script(): void {
		$nonce = wp_create_nonce( 'wcpos_track_upgrade_click' );
		?>
		<script>
		(function() {
			if (!window.ajaxurl) {
				return;
			}

			document.addEventListener('click', function(event) {
				var target = event.target;
				if (!target || !target.closest) {
					return;
				}

				var link = target.closest('[data-wcpos-upgrade-placement]');
				if (!link) {
					return;
				}

				var placement = link.getAttribute('data-wcpos-upgrade-placement');
				var destination = link.getAttribute('href');
				if (!placement || !destination || !window.navigator || !window.navigator.sendBeacon) {
					return;
				}

				var data = new URLSearchParams();
				data.set('action', 'wcpos_track_upgrade_click_ajax');
				data.set('placement', placement);
				data.set('destination', destination);
				data.set('_ajax_nonce', '<?php echo esc_js( $nonce ); ?>');
				window.navigator.sendBeacon(window.ajaxurl, data);
			});
		})();
		</script>
		<?php
	}

	/**
	 * AJAX handler for admin upgrade click tracking.
	 */
	public static function handle_upgrade_click_ajax(): void {
		check_ajax_referer( 'wcpos_track_upgrade_click' );

		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$placement   = isset( $_POST['placement'] ) ? sanitize_text_field( wp_unslash( $_POST['placement'] ) ) : '';
		$destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '';

		self::track_upgrade_click( $placement, $destination );

		wp_send_json_success();
	}

	/**
	 * Render the Template Gallery SPA mount point.
	 */
	public function render_gallery_page(): void {
		echo '<div class="wrap"><div id="wcpos-template-gallery"></div></div>';
	}

	/**
	 * Enqueue the Template Gallery SPA assets.
	 */
	public function enqueue_gallery_assets(): void {
		$is_development = isset( $_ENV['DEVELOPMENT'] )
			&& wp_validate_boolean( sanitize_text_field( wp_unslash( $_ENV['DEVELOPMENT'] ) ) );
		$dir            = $is_development ? 'build' : 'assets';

		wp_enqueue_style(
			'wcpos-template-gallery-styles',
			PLUGIN_URL . $dir . '/css/template-gallery.css',
			array(),
			PLUGIN_VERSION
		);

		wp_enqueue_script(
			'wcpos-template-gallery',
			PLUGIN_URL . $dir . '/js/template-gallery.js',
			array( 'react', 'react-dom', 'wp-api-fetch', 'wp-url' ),
			PLUGIN_VERSION,
			true
		);

		wp_add_inline_script( 'wcpos-template-gallery', $this->gallery_inline_script(), 'before' );
	}

	/**
	 * Generate the inline script for gallery data.
	 */
	private function gallery_inline_script(): string {
		$json_encode_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		return \sprintf(
			'var wcpos = wcpos || {}; wcpos.templateGallery = { isProActive: %s, adminUrl: %s, hasPosOrders: %s }; wcpos.translationVersion = %s;',
			wp_json_encode( class_exists( '\WCPOS\WooCommercePOSPro\WooCommercePOSPro' ), $json_encode_flags ),
			wp_json_encode( untrailingslashit( admin_url() ), $json_encode_flags ),
			wp_json_encode(
				(bool) wc_get_orders(
					array(
						'limit'       => 1,
						'return'      => 'ids',
						'status'      => array( 'completed', 'processing', 'on-hold', 'pending' ),
						'created_via' => 'woocommerce-pos',
					)
				),
				$json_encode_flags
			),
			wp_json_encode( TRANSLATION_VERSION, $json_encode_flags )
		);
	}

	/**
	 * Redirect the old CPT list page (edit.php?post_type=wcpos_template) to the Gallery SPA.
	 *
	 * Only redirects the list view, not the individual post editor.
	 */
	public function redirect_template_list_page(): void {
		global $pagenow;

		if (
			'edit.php' === $pagenow
			&& isset( $_GET['post_type'] )
			&& 'wcpos_template' === $_GET['post_type']
			&& ! isset( $_GET['post_status'] )
		) {
			wp_safe_redirect( admin_url( 'admin.php?page=wcpos-templates' ) );
			exit;
		}
	}

	/**
	 * Keep the POS menu expanded and Templates submenu highlighted when editing a template.
	 *
	 * @param string $parent_file The parent file.
	 *
	 * @return string
	 */
	public function highlight_templates_menu( $parent_file ) {
		global $current_screen, $submenu_file;

		if ( isset( $current_screen->post_type ) && 'wcpos_template' === $current_screen->post_type ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$submenu_file = 'wcpos-templates';
			$parent_file  = PLUGIN_NAME;
		}

		return $parent_file;
	}

	/**
	 * Sanitize an upgrade redirect destination to trusted hosts only.
	 *
	 * @param string $destination Candidate destination URL.
	 *
	 * @return string
	 */
	private static function sanitize_upgrade_destination( string $destination ): string {
		$fallback      = 'https://wcpos.com/pro';
		$destination   = rawurldecode( $destination );
		$parsed_url    = wp_parse_url( $destination );
		$parsed_admin  = wp_parse_url( admin_url() );
		$allowed_hosts = array_filter(
			array(
				$parsed_admin['host'] ?? '',
				'wcpos.com',
				'www.wcpos.com',
			)
		);

		if ( empty( $parsed_url['host'] ) || ! \in_array( $parsed_url['host'], $allowed_hosts, true ) ) {
			return $fallback;
		}

		return esc_url_raw( $destination );
	}
}
