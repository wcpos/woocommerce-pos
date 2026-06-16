<?php
/**
 * Tests for menu-related analytics instrumentation.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Menu;
use WCPOS\WooCommercePOS\Services\Analytics;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_UnitTestCase;

/**
 * Tests menu analytics hooks.
 *
 * @covers \WCPOS\WooCommercePOS\Admin\Menu
 */
class Test_Menu_Analytics extends WP_UnitTestCase {
	/**
	 * The menu instance under test.
	 *
	 * @var Menu
	 */
	private $menu;

	/**
	 * Captured outbound HTTP requests.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $captured_requests = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		Analytics::reset_instance();
		$this->captured_requests = array();

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, '_woocommerce_pos_uuid', 'user-uuid-abc' );
		wp_set_current_user( $user_id );

		$user = wp_get_current_user();
		$user->add_cap( 'manage_woocommerce_pos' );

		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );

		update_option( 'woocommerce_pos_uuid', 'site-uuid-xyz' );

		$this->menu = new Menu();
		$this->captured_requests = array();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
		Analytics::reset_instance();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Intercept outbound HTTP so tests never hit the network.
	 *
	 * @param false|array|\WP_Error $preempt     Whether to short-circuit.
	 * @param array                 $parsed_args Request args.
	 * @param string                $url         Request URL.
	 *
	 * @return array
	 */
	public function intercept_http( $preempt, $parsed_args, $url ) {
		$this->captured_requests[] = array(
			'url'  => $url,
			'args' => $parsed_args,
		);

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '',
			'headers'  => array(),
		);
	}

	/**
	 * Tracking an upgrade click should capture the event and return the destination.
	 */
	public function test_track_upgrade_click_captures_event_and_returns_destination(): void {
		$destination = Menu::track_upgrade_click( 'plugin_row_action', 'https://wcpos.com/pro' );

		$this->assertSame( 'https://wcpos.com/pro', $destination );
		$this->assertCount( 1, $this->captured_requests );

		$payload = json_decode( $this->captured_requests[0]['args']['body'], true );
		$this->assertSame( 'upgrade_cta_clicked', $payload['event'] );
		$this->assertSame( 'plugin_row_action', $payload['properties']['placement'] );
		$this->assertSame( 'https://wcpos.com/pro', $payload['properties']['destination'] );
	}

	/**
	 * Loading the admin landing page should bind the current site group.
	 */
	public function test_enqueue_landing_scripts_binds_the_site_group(): void {
		$this->menu->enqueue_landing_scripts_and_styles( $this->menu->toplevel_screen_id );

		$events = array_map(
			static function ( array $request ): array {
				return (array) json_decode( $request['args']['body'], true );
			},
			$this->captured_requests
		);

		$group_events = array_values(
			array_filter(
				$events,
				static function ( array $payload ): bool {
					return isset( $payload['event'] ) && '$groupidentify' === $payload['event'];
				}
			)
		);

		$this->assertNotEmpty( $group_events, 'Expected a $groupidentify event when the landing page loads.' );
		$this->assertSame( 'site', $group_events[0]['properties']['$group_type'] );
		$this->assertSame( 'site-uuid-xyz', $group_events[0]['properties']['$group_key'] );
	}

	/**
	 * Registering the POS admin menu runs on every wp-admin page load, so it
	 * must not emit an `upgrade_cta_viewed` impression. This previously fired
	 * once per request and dominated 85% of the analytics stream.
	 */
	public function test_menu_registration_emits_no_upgrade_view(): void {
		$this->captured_requests = array();

		// Re-run menu registration (constructor → register_pos_admin()).
		new Menu();

		$views = array_filter(
			$this->captured_requests,
			static function ( array $request ): bool {
				$payload = json_decode( $request['args']['body'], true );

				return isset( $payload['event'] ) && 'upgrade_cta_viewed' === $payload['event'];
			}
		);

		$this->assertSame( array(), $views, 'Menu registration must not emit upgrade_cta_viewed.' );
	}

	/**
	 * The admin landing banner impression is de-duplicated, so reloading the
	 * landing screen does not emit a fresh `upgrade_cta_viewed` each time.
	 */
	public function test_admin_landing_banner_view_is_deduplicated(): void {
		$this->menu->enqueue_landing_scripts_and_styles( $this->menu->toplevel_screen_id );
		$this->menu->enqueue_landing_scripts_and_styles( $this->menu->toplevel_screen_id );

		$banner_views = array_filter(
			$this->captured_requests,
			static function ( array $request ): bool {
				$payload = json_decode( $request['args']['body'], true );

				return isset( $payload['event'] )
					&& 'upgrade_cta_viewed' === $payload['event']
					&& 'admin_landing_banner' === ( $payload['properties']['placement'] ?? '' );
			}
		);

		$this->assertCount( 1, $banner_views, 'Landing banner view should be captured once per de-dup window.' );
	}
}
