<?php
/**
 * Tests for the PostHog inline script config on the landing page.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use ReflectionMethod;
use WCPOS\WooCommercePOS\Admin\Menu;
use WCPOS\WooCommercePOS\Services\Analytics;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_UnitTestCase;

/**
 * @covers \WCPOS\WooCommercePOS\Admin\Menu
 */
class Test_Menu_PostHog_Inline_Script extends WP_UnitTestCase {
	/**
	 * Menu instance under test.
	 *
	 * @var Menu
	 */
	private $menu;

	public function setUp(): void {
		parent::setUp();

		Analytics::reset_instance();
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos' );

		$this->menu = new Menu();
	}

	public function tearDown(): void {
		Analytics::reset_instance();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	public function test_enabled_inline_script_disables_session_recording(): void {
		$method = new ReflectionMethod( Menu::class, 'posthog_inline_script' );
		$method->setAccessible( true );

		$script = (string) $method->invoke( $this->menu );

		$this->assertStringContainsString(
			'disable_session_recording: true',
			$script,
			'Expected the inline PostHog init config to disable session recording.'
		);
	}

	public function test_disabled_inline_script_when_consent_not_allowed(): void {
		$settings = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'denied';
		SettingsService::instance()->save_settings( 'general', $settings );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos' );

		$this->menu = new Menu();

		$method = new ReflectionMethod( Menu::class, 'posthog_inline_script' );
		$method->setAccessible( true );

		$script = (string) $method->invoke( $this->menu );

		$this->assertStringNotContainsString(
			'posthog.init',
			$script,
			'Expected no PostHog initialization when consent is denied.'
		);
		$this->assertStringContainsString(
			'wcpos.posthog={capture:function',
			$script,
			'Expected no-op stub with capture stub to be present when consent is denied.'
		);
	}
}
}
