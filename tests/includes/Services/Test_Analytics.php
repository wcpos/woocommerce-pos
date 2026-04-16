<?php
/**
 * Tests for the Analytics service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Analytics;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_UnitTestCase;

/**
 * Tests the Analytics service.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Analytics
 */
class Test_Analytics extends WP_UnitTestCase {
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
	 * Enable tracking consent and return the Analytics instance.
	 */
	private function enable_consent(): Analytics {
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );

		$analytics = Analytics::instance();
		$analytics->clear_consent_cache();

		return $analytics;
	}

	/**
	 * Set consent to denied.
	 */
	private function deny_consent(): Analytics {
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'denied';
		SettingsService::instance()->save_settings( 'general', $settings );

		$analytics = Analytics::instance();
		$analytics->clear_consent_cache();

		return $analytics;
	}

	/**
	 * Create a user, assign a POS UUID, and log them in.
	 */
	private function login_user_with_uuid( string $uuid = 'user-uuid-abc' ): int {
		$user_id = $this->factory()->user->create();
		update_user_meta( $user_id, '_woocommerce_pos_uuid', $uuid );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Consent undecided → analytics disabled.
	 */
	public function test_is_enabled_returns_false_when_consent_undecided(): void {
		$analytics = Analytics::instance();
		$this->assertFalse( $analytics->is_enabled() );
	}

	/**
	 * Consent denied → analytics disabled.
	 */
	public function test_is_enabled_returns_false_when_consent_denied(): void {
		$analytics = $this->deny_consent();
		$this->assertFalse( $analytics->is_enabled() );
	}

	/**
	 * Consent allowed → analytics enabled.
	 */
	public function test_is_enabled_returns_true_when_consent_allowed(): void {
		$analytics = $this->enable_consent();
		$this->assertTrue( $analytics->is_enabled() );
	}

	/**
	 * No HTTP requests fire when consent is not granted.
	 */
	public function test_capture_is_no_op_when_disabled(): void {
		$this->login_user_with_uuid();
		$analytics = Analytics::instance();

		$result = $analytics->capture( 'test_event', array( 'foo' => 'bar' ) );

		$this->assertFalse( $result );
		$this->assertSame( array(), $this->captured_requests );
	}

	/**
	 * identify() and group() also no-op when disabled.
	 */
	public function test_identify_and_group_are_no_op_when_disabled(): void {
		$this->login_user_with_uuid();
		$analytics = Analytics::instance();

		$this->assertFalse( $analytics->identify( array( 'role' => 'admin' ) ) );
		$this->assertFalse( $analytics->group( 'site', 'site-123' ) );
		$this->assertSame( array(), $this->captured_requests );
	}

	/**
	 * No capture when the user has no POS UUID, even with consent.
	 */
	public function test_capture_requires_distinct_id(): void {
		$this->enable_consent();
		// No user logged in.

		$result = Analytics::instance()->capture( 'test_event' );

		$this->assertFalse( $result );
		$this->assertSame( array(), $this->captured_requests );
	}

	/**
	 * With consent + logged-in user, capture dispatches an HTTP request
	 * to the configured host with the expected payload shape.
	 */
	public function test_capture_dispatches_request_when_enabled(): void {
		$this->login_user_with_uuid( 'user-uuid-123' );
		update_option( 'woocommerce_pos_uuid', 'site-uuid-xyz' );
		$analytics = $this->enable_consent();

		$result = $analytics->capture(
			'upgrade_cta_clicked',
			array( 'placement' => 'settings_header' )
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->captured_requests );

		$request = $this->captured_requests[0];
		$this->assertStringStartsWith( Analytics::DEFAULT_HOST, $request['url'] );
		$this->assertStringContainsString( Analytics::CAPTURE_PATH, $request['url'] );
		$this->assertFalse( $request['args']['blocking'] );

		$payload = json_decode( $request['args']['body'], true );
		$this->assertSame( 'upgrade_cta_clicked', $payload['event'] );
		$this->assertSame( 'user-uuid-123', $payload['distinct_id'] );
		$this->assertSame( Analytics::DEFAULT_TOKEN, $payload['api_key'] );
		$this->assertSame( 'settings_header', $payload['properties']['placement'] );
		$this->assertSame( array( 'site' => 'site-uuid-xyz' ), $payload['properties']['$groups'] );
		$this->assertArrayHasKey( 'plugin_version', $payload['properties'] );
	}

	/**
	 * identify() sends a $identify event with $set on the properties.
	 */
	public function test_identify_sends_set_properties(): void {
		$this->login_user_with_uuid();
		$this->enable_consent();

		Analytics::instance()->identify(
			array( 'role' => 'shop_manager' ),
			array( 'first_seen_at' => '2026-04-16' )
		);

		$this->assertCount( 1, $this->captured_requests );
		$payload = json_decode( $this->captured_requests[0]['args']['body'], true );
		$this->assertSame( '$identify', $payload['event'] );
		$this->assertSame( array( 'role' => 'shop_manager' ), $payload['properties']['$set'] );
		$this->assertSame( array( 'first_seen_at' => '2026-04-16' ), $payload['properties']['$set_once'] );
	}

	/**
	 * group() sends a $groupidentify event with the right group metadata.
	 */
	public function test_group_sends_groupidentify_event(): void {
		$this->login_user_with_uuid();
		$this->enable_consent();

		Analytics::instance()->group(
			'site',
			'site-xyz',
			array( 'product_count' => 42 )
		);

		$this->assertCount( 1, $this->captured_requests );
		$payload = json_decode( $this->captured_requests[0]['args']['body'], true );
		$this->assertSame( '$groupidentify', $payload['event'] );
		$this->assertSame( 'site', $payload['properties']['$group_type'] );
		$this->assertSame( 'site-xyz', $payload['properties']['$group_key'] );
		$this->assertSame( array( 'product_count' => 42 ), $payload['properties']['$group_set'] );
	}

	/**
	 * An empty event name is rejected.
	 */
	public function test_capture_rejects_empty_event_name(): void {
		$this->login_user_with_uuid();
		$this->enable_consent();

		$this->assertFalse( Analytics::instance()->capture( '' ) );
		$this->assertSame( array(), $this->captured_requests );
	}

	/**
	 * The filter overrides the default token.
	 */
	public function test_filter_overrides_token(): void {
		$cb = function () {
			return 'phc_override';
		};
		add_filter( 'woocommerce_pos_posthog_token', $cb );

		$this->assertSame( 'phc_override', Analytics::instance()->get_token() );

		remove_filter( 'woocommerce_pos_posthog_token', $cb );
	}

	/**
	 * The filter overrides the default host, and the host is untrailingslashed.
	 */
	public function test_filter_overrides_host_and_strips_trailing_slash(): void {
		$cb = function () {
			return 'https://ph.example.com/';
		};
		add_filter( 'woocommerce_pos_posthog_host', $cb );

		$this->assertSame( 'https://ph.example.com', Analytics::instance()->get_host() );

		remove_filter( 'woocommerce_pos_posthog_host', $cb );
	}
}
