<?php
/**
 * Tests for the Consent admin class.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Consent;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_REST_Request;
use WP_UnitTestCase;
use const WCPOS\WooCommercePOS\PLUGIN_FILE;

/**
 * Covers the consent opt-in lifecycle, REST endpoint, and enqueue
 * gating.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Consent extends WP_UnitTestCase {

	/**
	 * Consent instance under test.
	 *
	 * @var Consent
	 */
	private $consent;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$user = wp_get_current_user();
		$user->add_cap( 'manage_woocommerce_pos' );

		// Reset consent setting to 'undecided' baseline.
		$settings                     = SettingsService::instance()->get_settings( 'general' );
		$settings['tracking_consent'] = 'undecided';
		SettingsService::instance()->save_settings( 'general', $settings );

		delete_transient( Consent::MODAL_TRANSIENT );

		$this->consent = new Consent();
	}

	/**
	 * Clean up transient between tests.
	 */
	public function tearDown(): void {
		delete_transient( Consent::MODAL_TRANSIENT );
		parent::tearDown();
	}

	/**
	 * Activation of our plugin file sets the modal transient when
	 * consent is still 'undecided'.
	 */
	public function test_activation_sets_transient_for_our_plugin(): void {
		$this->consent->on_plugin_activated( plugin_basename( PLUGIN_FILE ) );

		$this->assertNotFalse(
			get_transient( Consent::MODAL_TRANSIENT ),
			'Modal transient should be set after our plugin is activated.'
		);
	}

	/**
	 * Activation of some other plugin must NOT set our transient.
	 */
	public function test_activation_of_other_plugin_does_not_set_transient(): void {
		$this->consent->on_plugin_activated( 'some-other-plugin/some-other-plugin.php' );

		$this->assertFalse(
			get_transient( Consent::MODAL_TRANSIENT ),
			'Modal transient should not be set when a different plugin activates.'
		);
	}

	/**
	 * Activation of our plugin must NOT set the transient when
	 * the user has already recorded a consent choice.
	 */
	public function test_activation_skipped_when_already_decided(): void {
		$settings                     = SettingsService::instance()->get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );

		$this->consent->on_plugin_activated( plugin_basename( PLUGIN_FILE ) );

		$this->assertFalse(
			get_transient( Consent::MODAL_TRANSIENT ),
			'Modal transient should not be set when the user has already decided.'
		);
	}

	/**
	 * Plugin updates that include our file set the transient.
	 */
	public function test_upgrader_process_complete_sets_transient_on_update(): void {
		$this->consent->on_upgrader_process_complete(
			null,
			array(
				'type'    => 'plugin',
				'action'  => 'update',
				'plugins' => array( plugin_basename( PLUGIN_FILE ) ),
			)
		);

		$this->assertNotFalse(
			get_transient( Consent::MODAL_TRANSIENT ),
			'Modal transient should be set when our plugin is updated.'
		);
	}

	/**
	 * Plugin updates that don't include our file must not set the transient.
	 */
	public function test_upgrader_process_complete_ignores_other_plugins(): void {
		$this->consent->on_upgrader_process_complete(
			null,
			array(
				'type'    => 'plugin',
				'action'  => 'update',
				'plugins' => array( 'some-other-plugin/some-other-plugin.php' ),
			)
		);

		$this->assertFalse(
			get_transient( Consent::MODAL_TRANSIENT ),
			'Modal transient should not be set when a different plugin is updated.'
		);
	}

	/**
	 * Non-plugin upgrader events (core, theme) must not set the transient.
	 */
	public function test_upgrader_process_complete_ignores_non_plugin_updates(): void {
		$this->consent->on_upgrader_process_complete(
			null,
			array(
				'type'    => 'core',
				'action'  => 'update',
				'plugins' => array( plugin_basename( PLUGIN_FILE ) ),
			)
		);

		$this->assertFalse(
			get_transient( Consent::MODAL_TRANSIENT ),
			'Modal transient should only fire for plugin update events.'
		);
	}

	/**
	 * REST permission callback rejects users without the POS capability.
	 */
	public function test_permission_check_denies_non_manager(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->consent->permission_check();

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wcpos_consent_forbidden', $result->get_error_code() );
	}

	/**
	 * REST permission callback allows users with manage_woocommerce_pos.
	 */
	public function test_permission_check_allows_manager(): void {
		$this->assertTrue( $this->consent->permission_check() );
	}

	/**
	 * Save an allowed choice and confirm the pending transient clears.
	 */
	public function test_save_consent_allowed(): void {
		set_transient( Consent::MODAL_TRANSIENT, 1, 60 );

		$request = new WP_REST_Request( 'POST', '/wcpos/v1/consent' );
		$request->set_param( 'consent', 'allowed' );

		$response = $this->consent->save_consent( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			'allowed',
			woocommerce_pos_get_settings( 'general', 'tracking_consent' )
		);
		$this->assertFalse(
			get_transient( Consent::MODAL_TRANSIENT ),
			'Saving a decision should clear the pending modal transient.'
		);
	}

	/**
	 * Save a denied choice successfully.
	 */
	public function test_save_consent_denied(): void {
		$request = new WP_REST_Request( 'POST', '/wcpos/v1/consent' );
		$request->set_param( 'consent', 'denied' );

		$response = $this->consent->save_consent( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			'denied',
			woocommerce_pos_get_settings( 'general', 'tracking_consent' )
		);
	}

	/**
	 * Reject invalid choice values.
	 */
	public function test_save_consent_rejects_invalid_value(): void {
		$request = new WP_REST_Request( 'POST', '/wcpos/v1/consent' );
		$request->set_param( 'consent', 'maybe' );

		$result = $this->consent->save_consent( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wcpos_consent_invalid', $result->get_error_code() );
	}

	/**
	 * The REST route is registered under the WCPOS namespace at rest_api_init.
	 */
	public function test_rest_route_is_registered(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core WP hook.
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/consent', $routes );
	}

	/**
	 * No mount point is rendered on screens outside the allowlist, even
	 * when consent is undecided.
	 */
	public function test_mount_point_not_rendered_on_unrelated_screen(): void {
		ob_start();
		$this->consent->maybe_render_mount_point( 'edit.php' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The mount point renders on the plugins screen when consent is undecided.
	 */
	public function test_mount_point_rendered_on_plugins_screen(): void {
		ob_start();
		$this->consent->maybe_render_mount_point( 'plugins.php' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="wcpos-consent-root"', $output );
	}

	/**
	 * The mount point renders on the dashboard when consent is undecided.
	 */
	public function test_mount_point_rendered_on_dashboard(): void {
		ob_start();
		$this->consent->maybe_render_mount_point( 'index.php' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="wcpos-consent-root"', $output );
	}

	/**
	 * Once the user has made a decision, the mount point disappears from
	 * the plugins screen — we stop asking.
	 */
	public function test_mount_point_hidden_once_consent_decided(): void {
		$settings                     = SettingsService::instance()->get_settings( 'general' );
		$settings['tracking_consent'] = 'denied';
		SettingsService::instance()->save_settings( 'general', $settings );

		ob_start();
		$this->consent->maybe_render_mount_point( 'plugins.php' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
