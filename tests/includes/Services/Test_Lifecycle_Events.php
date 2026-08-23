<?php
/**
 * Tests for the install lifecycle analytics.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Analytics;
use WCPOS\WooCommercePOS\Services\Lifecycle_Events;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_UnitTestCase;

/**
 * Tests the install lifecycle events and their consent deferral.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Lifecycle_Events
 */
class Test_Lifecycle_Events extends WP_UnitTestCase {
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

		delete_option( Lifecycle_Events::PENDING_OPTION );
		delete_option( Lifecycle_Events::INSTALL_RECORDED_OPTION );
		delete_option( 'woocommerce_pos_installed_at' );
		delete_option( 'woocommerce_pos_db_version' );
		delete_transient( 'wcpos_landing_profile' );
		delete_transient( Lifecycle_Events::REFRESH_THROTTLE_TRANSIENT );

		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );

		delete_option( Lifecycle_Events::PENDING_OPTION );
		delete_option( Lifecycle_Events::INSTALL_RECORDED_OPTION );
		delete_option( 'woocommerce_pos_installed_at' );
		delete_transient( Lifecycle_Events::REFRESH_THROTTLE_TRANSIENT );
		wp_clear_scheduled_hook( Lifecycle_Events::REFRESH_HOOK );

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
	 * Set the stored tracking consent value.
	 *
	 * @param string $value One of allowed / denied / undecided.
	 */
	private function set_consent( string $value ): void {
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = $value;
		SettingsService::instance()->save_settings( 'general', $settings );

		Analytics::instance()->clear_consent_cache();
	}

	/**
	 * Decoded bodies of every captured capture() request.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function captured_events(): array {
		$events = array();

		foreach ( $this->captured_requests as $request ) {
			$body = json_decode( $request['args']['body'] ?? '', true );
			if ( \is_array( $body ) ) {
				$events[] = $body;
			}
		}

		return $events;
	}

	/**
	 * Names of every captured event, in order.
	 *
	 * @return string[]
	 */
	private function captured_event_names(): array {
		return array_column( $this->captured_events(), 'event' );
	}

	/**
	 * Find the first captured event with the given name.
	 *
	 * @param string $event Event name.
	 *
	 * @return null|array<string, mixed>
	 */
	private function find_event( string $event ): ?array {
		foreach ( $this->captured_events() as $body ) {
			if ( ( $body['event'] ?? '' ) === $event ) {
				return $body;
			}
		}

		return null;
	}

	/**
	 * Consent is undecided at activation, so the install must be held, not lost.
	 */
	public function test_install_recorded_while_undecided_is_queued_and_not_sent(): void {
		$this->set_consent( 'undecided' );

		( new Lifecycle_Events() )->record_install();

		$this->assertNotContains( 'wcpos_installed', $this->captured_event_names() );

		$pending = get_option( Lifecycle_Events::PENDING_OPTION );
		$this->assertIsArray( $pending );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'wcpos_installed', $pending[0]['event'] );
	}

	/**
	 * Granting consent flushes the queue, preserving the original install time.
	 */
	public function test_queued_install_is_sent_once_consent_is_granted(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_install();

		$queued_timestamp = get_option( Lifecycle_Events::PENDING_OPTION )[0]['timestamp'];

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle->flush_pending();

		$install = $this->find_event( 'wcpos_installed' );
		$this->assertNotNull( $install );

		// The event must carry the real install date, not the consent date, or
		// every retention cohort is anchored to the wrong day.
		$this->assertSame( $queued_timestamp, $install['timestamp'] );
		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * A flushed queue is not replayed on the next admin page load.
	 */
	public function test_flush_is_not_repeated(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_install();

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle->flush_pending();
		$first_pass = \count( array_filter( $this->captured_event_names(), static fn ( $name ) => 'wcpos_installed' === $name ) );

		$lifecycle->flush_pending();
		$second_pass = \count( array_filter( $this->captured_event_names(), static fn ( $name ) => 'wcpos_installed' === $name ) );

		$this->assertSame( 1, $first_pass );
		$this->assertSame( 1, $second_pass );
	}

	/**
	 * A queue left over from the undecided period is discarded, not held, once
	 * the user declines.
	 */
	public function test_declining_after_queueing_discards_the_queue(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_install();
		$this->assertNotEmpty( get_option( Lifecycle_Events::PENDING_OPTION ) );

		$this->set_consent( 'denied' );
		$lifecycle->flush_pending();

		$this->assertSame( array(), $this->captured_event_names() );
		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * "No" is an answer — a declined install is dropped, never queued.
	 */
	public function test_install_is_not_queued_when_consent_is_denied(): void {
		$this->set_consent( 'denied' );

		( new Lifecycle_Events() )->record_install();

		$this->assertSame( array(), $this->captured_event_names() );
		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * Reactivating an existing install must not report a second install.
	 */
	public function test_install_is_recorded_only_once(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_install();
		$lifecycle->record_install();

		$this->assertCount( 1, (array) get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * Upgrading an existing site must not look like a fresh install.
	 */
	public function test_pre_existing_install_is_latched_without_reporting(): void {
		$this->set_consent( 'allowed' );
		update_option( 'woocommerce_pos_db_version', '1.9.17' );

		( new Lifecycle_Events() )->record_install();

		$this->assertNotContains( 'wcpos_installed', $this->captured_event_names() );
		$this->assertNotEmpty( get_option( Lifecycle_Events::INSTALL_RECORDED_OPTION ) );
	}

	/**
	 * The upgrade event carries the version it moved between.
	 */
	public function test_upgrade_reports_from_and_to_version(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_upgrade( '1.9.16', '1.10.0' );
		$lifecycle->flush_pending();

		$upgrade = $this->find_event( 'wcpos_upgraded' );
		$this->assertNotNull( $upgrade );
		$this->assertSame( '1.9.16', $upgrade['properties']['from_version'] );
		$this->assertSame( '1.10.0', $upgrade['properties']['to_version'] );
	}

	/**
	 * Install and upgrade must never capture inline, even with consent already
	 * granted.
	 *
	 * Both run before the plugin is fully booted — the activation hook fires in
	 * a request where Init never ran, and the upgrade check runs before
	 * `new Init()`. capture() reaches wcpos_get_site_uuid(), which is not
	 * defined that early, so an inline send would fatal the activation. This
	 * pins the queue-always contract that avoids it.
	 */
	public function test_install_and_upgrade_never_capture_inline(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_install();
		$lifecycle->record_upgrade( '1.9.16', '1.10.0' );

		$this->assertSame( array(), $this->captured_event_names() );
		$this->assertCount( 2, (array) get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * Lifecycle events carry their own properties only — the environment and
	 * store snapshot belongs on the `site` group, which the flush refreshes in
	 * the same pass.
	 */
	public function test_flush_refreshes_the_site_group(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_install();
		$lifecycle->flush_pending();

		$install = $this->find_event( 'wcpos_installed' );
		$this->assertNotNull( $install );
		$this->assertArrayNotHasKey( 'product_count_band', $install['properties'] );

		$group = $this->find_event( '$groupidentify' );
		$this->assertNotNull( $group );
		$this->assertArrayHasKey( 'product_count_band', $group['properties']['$group_set'] );
	}

	/**
	 * A fresh install runs the upgrade path with no prior version; that is an
	 * install, and reporting it as an upgrade too would double-count.
	 */
	public function test_upgrade_from_no_prior_version_is_not_reported(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		( new Lifecycle_Events() )->record_upgrade( '0', '1.10.0' );

		$this->assertNotContains( 'wcpos_upgraded', $this->captured_event_names() );
		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * Deactivation reports immediately — there is no later page load to flush from.
	 */
	public function test_deactivation_reports_immediately(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		( new Lifecycle_Events() )->report_deactivation();

		$deactivated = $this->find_event( 'wcpos_deactivated' );
		$this->assertNotNull( $deactivated );
		$this->assertArrayHasKey( 'days_since_install', $deactivated['properties'] );
		$this->assertArrayHasKey( 'total_pos_orders', $deactivated['properties'] );
	}

	/**
	 * A declined site reports nothing on the way out either.
	 */
	public function test_deactivation_is_silent_without_consent(): void {
		$this->set_consent( 'denied' );

		( new Lifecycle_Events() )->report_deactivation();

		$this->assertSame( array(), $this->captured_event_names() );
	}

	/**
	 * The group refresh sends the site profile — the bug this phase fixes was a
	 * group identification with an empty property set.
	 */
	public function test_group_refresh_sends_site_properties(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		( new Lifecycle_Events() )->refresh_group_properties();

		$group = $this->find_event( '$groupidentify' );
		$this->assertNotNull( $group );
		$this->assertSame( 'site', $group['properties']['$group_type'] );
		$this->assertNotEmpty( $group['properties']['$group_set'] );
		$this->assertArrayHasKey( 'php_version', $group['properties']['$group_set'] );
		$this->assertArrayHasKey( 'product_count_band', $group['properties']['$group_set'] );
	}

	/**
	 * The scheduled refresh runs from cron, where nobody is logged in.
	 */
	public function test_group_refresh_works_without_a_logged_in_user(): void {
		$this->set_consent( 'allowed' );
		wp_set_current_user( 0 );

		( new Lifecycle_Events() )->refresh_group_properties();

		$group = $this->find_event( '$groupidentify' );
		$this->assertNotNull( $group );
		$this->assertSame( 'site_' . wcpos_get_site_uuid(), $group['distinct_id'] );
	}

	/**
	 * The page-load refresh is throttled so it cannot become a per-view event.
	 */
	public function test_page_load_refresh_is_throttled(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->maybe_refresh_group_properties();
		$lifecycle->maybe_refresh_group_properties();
		$lifecycle->maybe_refresh_group_properties();

		$groups = array_filter( $this->captured_event_names(), static fn ( $name ) => '$groupidentify' === $name );
		$this->assertCount( 1, $groups );
	}

	/**
	 * Consent gates the schedule in both directions.
	 */
	public function test_refresh_is_scheduled_on_consent_and_cleared_when_withdrawn(): void {
		$this->set_consent( 'allowed' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->maybe_schedule_refresh();
		$this->assertNotFalse( wp_next_scheduled( Lifecycle_Events::REFRESH_HOOK ) );

		$this->set_consent( 'denied' );
		$lifecycle->maybe_schedule_refresh();
		$this->assertFalse( wp_next_scheduled( Lifecycle_Events::REFRESH_HOOK ) );
	}
}
