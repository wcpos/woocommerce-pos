<?php
/**
 * Tests for the wp-admin landing page bootstrap wiring.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Menu;
use WCPOS\WooCommercePOS\Services\Analytics;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_UnitTestCase;

/**
 * Tests that the landing page delegates PostHog ownership to the bundle.
 *
 * @covers \WCPOS\WooCommercePOS\Admin\Menu
 */
class Test_Menu_Landing_Bootstrap extends WP_UnitTestCase {
	/**
	 * The menu instance under test.
	 *
	 * @var Menu
	 */
	private $menu;

	/**
	 * Original general settings snapshot for restoration.
	 *
	 * @var array
	 */
	private $original_general_settings = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		Analytics::reset_instance();

		// Keep the consent-gated server-side capture/group calls off the network.
		add_filter( 'pre_http_request', '__return_empty_array' );

		// Clear any landing handle left registered by an earlier test so the
		// inline-script assertions below see only this test's enqueue.
		wp_deregister_script( 'wcpos-welcome' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos' );

		// Consent ON: under the old code this is exactly when the plugin emitted
		// a real posthog.init + identify, so the regression assertions below are
		// meaningful rather than vacuous.
		$this->original_general_settings = (array) wcpos_get_settings( 'general' );
		$settings                        = $this->original_general_settings;
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );

		$this->menu = new Menu();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		SettingsService::instance()->save_settings( 'general', $this->original_general_settings );
		remove_filter( 'pre_http_request', '__return_empty_array' );
		remove_action( 'admin_print_styles', array( $this->menu, 'print_landing_resource_hints' ), 1 );
		Analytics::reset_instance();
		wp_set_current_user( 0 );
		wp_deregister_script( 'wcpos-welcome' );

		parent::tearDown();
	}

	/**
	 * Reads the inline scripts attached to the landing bundle at a position.
	 *
	 * @param string $position Either `before` or `after`.
	 *
	 * @return string Concatenated inline script source.
	 */
	private function inline_scripts( string $position ): string {
		$data = wp_scripts()->get_data( 'wcpos-welcome', $position );

		return \is_array( $data ) ? implode( "\n", $data ) : (string) $data;
	}

	/**
	 * The landing page must still inject the functional data block so the bundle
	 * can read locale/version/anon_id/bootstrap_flags.
	 */
	public function test_landing_page_injects_functional_data(): void {
		$this->menu->enqueue_landing_scripts_and_styles( $this->menu->toplevel_screen_id );

		$this->assertStringContainsString( 'wcpos.landing', $this->inline_scripts( 'before' ) );
	}

	/**
	 * The plugin must NOT initialise PostHog on the landing page — the bundle
	 * owns init/identify (flag-before-identify). An early init here shares the
	 * bundle's localStorage identity and breaks anon-bucket exposure.
	 */
	public function test_landing_page_does_not_initialise_posthog(): void {
		$this->menu->enqueue_landing_scripts_and_styles( $this->menu->toplevel_screen_id );

		$before = $this->inline_scripts( 'before' );
		$this->assertStringNotContainsString( 'posthog.init', $before );
		$this->assertStringNotContainsString( 'posthog.identify', $before );
	}

	/**
	 * The redundant DOM-level CTA tracker (which depended on window.wcpos.posthog
	 * and double-fired against the bundle's own React tracking) must be gone.
	 */
	public function test_landing_page_has_no_duplicate_cta_tracking(): void {
		$this->menu->enqueue_landing_scripts_and_styles( $this->menu->toplevel_screen_id );

		$this->assertStringNotContainsString( 'upgrade_cta_clicked', $this->inline_scripts( 'after' ) );
	}

	/**
	 * Captures what the resource hint printer writes.
	 *
	 * @return string The printed hints.
	 */
	private function resource_hints(): string {
		ob_start();
		$this->menu->print_landing_resource_hints();

		return (string) ob_get_clean();
	}

	/**
	 * The landing page prints its hints ahead of core's styles (priority 20).
	 */
	public function test_landing_page_registers_resource_hints_before_admin_styles(): void {
		$this->menu->enqueue_landing_scripts_and_styles( $this->menu->toplevel_screen_id );

		$this->assertSame( 1, has_action( 'admin_print_styles', array( $this->menu, 'print_landing_resource_hints' ) ) );
	}

	/**
	 * No other admin screen gets the hints.
	 */
	public function test_other_screens_get_no_resource_hints(): void {
		$this->menu->enqueue_landing_scripts_and_styles( 'edit.php' );

		$this->assertFalse( has_action( 'admin_print_styles', array( $this->menu, 'print_landing_resource_hints' ) ) );
	}

	/**
	 * Development loads the bundle from localhost, so the CDN hints would be wasted.
	 */
	public function test_development_gets_no_resource_hints(): void {
		$original_env        = $_ENV;
		$_ENV['DEVELOPMENT'] = '1';

		$this->menu->enqueue_landing_scripts_and_styles( $this->menu->toplevel_screen_id );
		$_ENV = $original_env;

		$this->assertFalse( has_action( 'admin_print_styles', array( $this->menu, 'print_landing_resource_hints' ) ) );
	}

	/**
	 * With consent the CDN and PostHog hints are all printed.
	 */
	public function test_resource_hints_with_consent(): void {
		$output = $this->resource_hints();

		$this->assertStringContainsString( '<link rel="preconnect" href="https://cdn.jsdelivr.net">' . "\n", $output );
		$this->assertStringContainsString( '<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">' . "\n", $output );
		$this->assertStringContainsString( '<link rel="preconnect" href="https://ph.wcpos.com" crossorigin>' . "\n", $output );
	}

	/**
	 * Without consent nothing connects to PostHog.
	 */
	public function test_resource_hints_without_consent_skip_posthog(): void {
		$settings                     = $this->original_general_settings;
		$settings['tracking_consent'] = 'denied';
		SettingsService::instance()->save_settings( 'general', $settings );
		Analytics::reset_instance();

		$output = $this->resource_hints();

		$this->assertStringContainsString( '<link rel="preconnect" href="https://cdn.jsdelivr.net">', $output );
		$this->assertStringContainsString( '<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">', $output );
		$this->assertStringNotContainsString( 'ph.wcpos.com', $output );
	}

	/**
	 * The PostHog hint follows the configured analytics host.
	 */
	public function test_resource_hints_use_the_filtered_analytics_host(): void {
		$host = static function () {
			return 'https://analytics.example.com';
		};
		add_filter( 'wcpos_posthog_host', $host );

		$output = $this->resource_hints();
		remove_filter( 'wcpos_posthog_host', $host );

		$this->assertStringContainsString( '<link rel="preconnect" href="https://analytics.example.com" crossorigin>', $output );
		$this->assertStringNotContainsString( 'ph.wcpos.com', $output );
	}
}
