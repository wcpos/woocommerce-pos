<?php
/**
 * Tests for the PostHog inline script config on the landing page.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Menu;
use WCPOS\WooCommercePOS\Services\Analytics;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_UnitTestCase;

/**
 * Verifies that the inline PostHog snippet injected on the landing page
 * disables session recording when consent is granted, and falls back to
 * a no-op stub when consent is denied.
 *
 * @covers \WCPOS\WooCommercePOS\Admin\Menu
 */
class Test_Menu_PostHog_Inline_Script extends WP_UnitTestCase {
	/**
	 * Set up each test with allowed tracking consent and admin caps.
	 */
	public function setUp(): void {
		parent::setUp();

		Analytics::reset_instance();
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos' );
	}

	/**
	 * Reset analytics state between tests.
	 */
	public function tearDown(): void {
		Analytics::reset_instance();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * When consent is allowed, the PostHog init call must include
	 * `disable_session_recording: true`.
	 */
	public function test_enabled_inline_script_disables_session_recording(): void {
		$script = Menu::get_posthog_inline_script();

		$this->assertStringContainsString(
			'disable_session_recording: true',
			$script,
			'Expected the inline PostHog init config to disable session recording.'
		);
	}

	/**
	 * When consent is denied, PostHog must not be initialized and the
	 * emitted snippet should fall back to the no-op stub.
	 */
	public function test_disabled_inline_script_when_consent_not_allowed(): void {
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'denied';
		SettingsService::instance()->save_settings( 'general', $settings );
		Analytics::reset_instance();

		$script = Menu::get_posthog_inline_script();

		$this->assertStringNotContainsString(
			'posthog.init',
			$script,
			'Expected no PostHog initialization when consent is denied.'
		);
		$this->assertStringContainsString(
			'w.posthog={capture:function',
			$script,
			'Expected no-op stub with capture stub to be present when consent is denied.'
		);
	}
}
