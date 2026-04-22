<?php
/**
 * Tracking consent opt-in.
 *
 * Shows a pop-up modal when the plugin is activated or updated, and a
 * persistent callout on the Plugins screen and Dashboard until the user
 * makes a decision. Once the user has chosen allow/deny we stop asking.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin;

use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use const WCPOS\WooCommercePOS\PLUGIN_FILE;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_URL;
use const WCPOS\WooCommercePOS\SHORT_NAME;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Class Consent.
 *
 * Registered from both the plugin bootstrap (for the lifecycle hooks) and
 * from Admin::init() (so the frontend asset is enqueued on wp-admin page
 * loads).
 */
class Consent {
	/**
	 * Transient name used to auto-open the consent modal on the next
	 * admin page load after activation or update.
	 */
	public const MODAL_TRANSIENT = 'wcpos_show_consent_modal';

	/**
	 * Transient lifetime in seconds (10 minutes).
	 */
	public const MODAL_TRANSIENT_TTL = 600;

	/**
	 * Hook suffixes where the inline callout + modal mount point are
	 * allowed. The Plugins screen is the primary target (users land
	 * here after activation) and the Dashboard is the fallback.
	 *
	 * @var string[]
	 */
	private const ALLOWED_HOOK_SUFFIXES = array( 'plugins.php', 'index.php' );

	/**
	 * Register lifecycle + REST hooks.
	 */
	public function __construct() {
		// Lifecycle — set the "show the modal" flag.
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 1 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_process_complete' ), 10, 2 );

		// Render — enqueue the React bundle on qualifying admin screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_mount_point' ) );

		// REST — persistence endpoint for the user's choice.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Flag the consent modal for display when our plugin is activated.
	 *
	 * Fires after activation via the 'activated_plugin' action. Only sets
	 * the transient for our plugin file, and only when the user has not
	 * already made a decision.
	 *
	 * @param string $plugin The activated plugin file, relative to WP_PLUGIN_DIR.
	 */
	public function on_plugin_activated( $plugin ): void {
		if ( ! is_string( $plugin ) ) {
			return;
		}

		if ( plugin_basename( PLUGIN_FILE ) !== $plugin ) {
			return;
		}

		$this->maybe_set_modal_transient();
	}

	/**
	 * Flag the consent modal after our plugin is updated via the updater.
	 *
	 * @param mixed $upgrader Instance of the upgrader performing the update.
	 * @param array $data     Array of bulk item update data.
	 */
	public function on_upgrader_process_complete( $upgrader, $data ): void {
		if ( ! \is_array( $data ) ) {
			return;
		}

		$type   = isset( $data['type'] ) ? $data['type'] : '';
		$action = isset( $data['action'] ) ? $data['action'] : '';
		if ( 'plugin' !== $type || 'update' !== $action ) {
			return;
		}

		// Normalize both upgrader payload shapes: bulk updates pass a
		// 'plugins' array while single-plugin updates pass a scalar
		// 'plugin' key.
		$plugins = array();
		if ( isset( $data['plugin'] ) && \is_string( $data['plugin'] ) ) {
			$plugins[] = $data['plugin'];
		}
		if ( isset( $data['plugins'] ) && \is_array( $data['plugins'] ) ) {
			$plugins = array_merge( $plugins, $data['plugins'] );
		}

		$target = plugin_basename( PLUGIN_FILE );
		if ( ! \in_array( $target, $plugins, true ) ) {
			return;
		}

		$this->maybe_set_modal_transient();
	}

	/**
	 * Set the modal display transient only if the user hasn't yet decided.
	 *
	 * Keeps the transient from piling up for users who have already
	 * opted in or out.
	 */
	private function maybe_set_modal_transient(): void {
		if ( 'undecided' !== woocommerce_pos_get_settings( 'general', 'tracking_consent' ) ) {
			return;
		}

		set_transient( self::MODAL_TRANSIENT, 1, self::MODAL_TRANSIENT_TTL );
	}

	/**
	 * Decide whether to enqueue the consent bundle on the current screen.
	 *
	 * Runs on every admin page but only does work on the two allowed
	 * screens and only while the user has not made a decision.
	 *
	 * @param string $hook_suffix WordPress admin page hook suffix.
	 */
	public function maybe_enqueue( $hook_suffix ): void {
		if ( ! $this->should_render( $hook_suffix ) ) {
			return;
		}

		$is_development = isset( $_ENV['DEVELOPMENT'] )
			&& wp_validate_boolean( sanitize_text_field( wp_unslash( $_ENV['DEVELOPMENT'] ) ) );
		$dir            = $is_development ? 'build' : 'assets';

		wp_enqueue_style(
			PLUGIN_NAME . '-consent-styles',
			PLUGIN_URL . $dir . '/css/consent.css',
			array(),
			VERSION
		);

		wp_enqueue_script(
			PLUGIN_NAME . '-consent',
			PLUGIN_URL . $dir . '/js/consent.js',
			array( 'react', 'react-dom', 'wp-url' ),
			VERSION,
			true
		);

		wp_add_inline_script(
			PLUGIN_NAME . '-consent',
			$this->inline_script( $hook_suffix ),
			'before'
		);
	}

	/**
	 * Print the mount point element. Paired with maybe_enqueue().
	 *
	 * @param string|null $hook_suffix Optional hook suffix override (used by tests).
	 */
	public function maybe_render_mount_point( $hook_suffix = null ): void {
		if ( null === $hook_suffix ) {
			// WP screen ids differ from hook_suffixes (e.g. 'dashboard' vs
			// 'index.php'). Prefer $GLOBALS['hook_suffix'] which is set right
			// before admin_notices fires, and fall back to current_screen.
			$hook_suffix = '';
			if ( isset( $GLOBALS['hook_suffix'] ) && \is_string( $GLOBALS['hook_suffix'] ) ) {
				$hook_suffix = $GLOBALS['hook_suffix'];
			} else {
				$screen      = get_current_screen();
				$hook_suffix = $screen ? $screen->id : '';
			}
		}

		if ( ! $this->should_render( $hook_suffix ) ) {
			return;
		}

		echo '<div id="wcpos-consent-root"></div>';
	}

	/**
	 * Register the consent REST endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			SHORT_NAME . '/v1',
			'/consent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_consent' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'consent' => array(
						'type'     => 'string',
						'enum'     => array( 'allowed', 'denied' ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * REST permission callback. Must be able to manage WCPOS.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error( 'wcpos_consent_forbidden', __( 'You do not have permission to update WCPOS settings.', 'woocommerce-pos' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Persist the user's consent choice.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_consent( WP_REST_Request $request ) {
		$choice = $request->get_param( 'consent' );
		if ( ! \in_array( $choice, array( 'allowed', 'denied' ), true ) ) {
			return new WP_Error( 'wcpos_consent_invalid', __( 'Invalid consent value.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}

		$settings = woocommerce_pos_get_settings( 'general' );
		if ( ! \is_array( $settings ) ) {
			return new WP_Error( 'wcpos_consent_load_failed', __( 'Unable to load general settings.', 'woocommerce-pos' ), array( 'status' => 500 ) );
		}

		$settings['tracking_consent'] = $choice;
		$result                       = SettingsService::instance()->save_settings( 'general', $settings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Decision recorded — clear any pending auto-open flag.
		delete_transient( self::MODAL_TRANSIENT );

		return new WP_REST_Response( array( 'consent' => $choice ), 200 );
	}

	/**
	 * Determine whether the consent UI should render on the given screen.
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 */
	private function should_render( $hook_suffix ): bool {
		if ( ! \is_string( $hook_suffix ) || '' === $hook_suffix ) {
			return false;
		}

		if ( ! \in_array( $hook_suffix, self::ALLOWED_HOOK_SUFFIXES, true ) ) {
			return false;
		}

		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return false;
		}

		if ( 'undecided' !== woocommerce_pos_get_settings( 'general', 'tracking_consent' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build the inline configuration object read by the React bundle.
	 *
	 * @param string $hook_suffix Admin page hook suffix for the current request.
	 */
	private function inline_script( $hook_suffix ): string {
		// Modal is only auto-opened on the Plugins screen (where users
		// land after activation) and only when the transient is set.
		// We clear the transient immediately so it only fires once.
		$show_modal = false;
		if ( 'plugins.php' === $hook_suffix && get_transient( self::MODAL_TRANSIENT ) ) {
			$show_modal = true;
			delete_transient( self::MODAL_TRANSIENT );
		}

		$config = array(
			'restUrl'     => esc_url_raw( rest_url( SHORT_NAME . '/v1/consent' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'showModal'   => $show_modal,
			'showCallout' => true,
		);

		return 'var wcpos = wcpos || {}; wcpos.consent = ' . wp_json_encode( $config ) . ';';
	}
}
