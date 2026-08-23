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
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
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
		delete_option( Lifecycle_Events::FIRST_ORDER_OPTION );
		delete_option( Lifecycle_Events::FIRST_OPEN_OPTION );
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
		delete_option( Lifecycle_Events::LAST_ORDER_BAND_OPTION );
		delete_option( Lifecycle_Events::FIRST_ORDER_OPTION );
		delete_option( Lifecycle_Events::FIRST_OPEN_OPTION );
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

		// Banded, not raw — an exact order count never leaves the store.
		$this->assertArrayHasKey( 'order_count_band', $deactivated['properties'] );
		$this->assertArrayNotHasKey( 'total_pos_orders', $deactivated['properties'] );
	}

	/**
	 * Deactivation re-reads consent instead of trusting a cached answer.
	 *
	 * A network-wide deactivation walks every blog inside one request. Analytics
	 * caches the consent answer per request, so without an explicit clear the
	 * first blog's "yes" would be reused for blogs that said no.
	 */
	public function test_deactivation_rereads_consent_rather_than_trusting_the_cache(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Prime the request cache with an allowed answer, the way the first blog
		// of a network deactivation would.
		$this->set_consent( 'allowed' );
		$this->assertTrue( Analytics::instance()->is_enabled() );

		// Now change the stored answer WITHOUT clearing the cache — this is what
		// switch_to_blog() effectively does.
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'denied';
		SettingsService::instance()->save_settings( 'general', $settings );
		$this->assertTrue( Analytics::instance()->is_enabled() );

		( new Lifecycle_Events() )->report_deactivation();

		$this->assertSame( array(), $this->captured_event_names() );
	}

	/**
	 * Deactivation survives a userless context.
	 *
	 * `wp plugin deactivate` runs with no current user, so a distinct id derived
	 * from the logged-in user is empty and capture() would drop the event.
	 */
	public function test_deactivation_reports_without_a_logged_in_user(): void {
		$this->set_consent( 'allowed' );
		wp_set_current_user( 0 );

		( new Lifecycle_Events() )->report_deactivation();

		$deactivated = $this->find_event( 'wcpos_deactivated' );
		$this->assertNotNull( $deactivated );
		$this->assertSame( 'site_' . wcpos_get_site_uuid(), $deactivated['distinct_id'] );
	}

	/**
	 * The install epoch is stamped at activation, not at consent time.
	 *
	 * Without this, a site that leaves consent undecided for a month has its
	 * install date created a month late, and under-reports its own age forever.
	 */
	public function test_recording_an_install_stamps_the_install_epoch(): void {
		$this->set_consent( 'undecided' );
		$this->assertFalse( get_option( 'woocommerce_pos_installed_at' ) );

		$before = time();
		( new Lifecycle_Events() )->record_install();

		$installed_at = get_option( 'woocommerce_pos_installed_at' );
		$this->assertNotFalse( $installed_at );
		$this->assertGreaterThanOrEqual( $before, (int) $installed_at );
	}

	/**
	 * The consent prompt's own view is queued, never sent.
	 *
	 * The prompt only renders while the answer is undecided, so transmitting
	 * anything at that moment would be telemetry from someone who has not
	 * agreed to any.
	 */
	public function test_consent_prompt_view_is_queued_not_sent(): void {
		$this->set_consent( 'undecided' );

		( new Lifecycle_Events() )->record_consent_prompt_viewed( 'modal' );

		$this->assertSame( array(), $this->captured_event_names() );

		$pending = get_option( Lifecycle_Events::PENDING_OPTION );
		$this->assertIsArray( $pending );
		$this->assertSame( 'consent_notice_viewed', $pending[0]['event'] );
		$this->assertSame( 'modal', $pending[0]['properties']['surface'] );
	}

	/**
	 * The prompt re-renders on every admin screen until answered; only the
	 * first sighting is recorded.
	 */
	public function test_consent_prompt_view_is_recorded_once(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_consent_prompt_viewed( 'callout' );
		$lifecycle->record_consent_prompt_viewed( 'callout' );
		$lifecycle->record_consent_prompt_viewed( 'callout' );

		$this->assertCount( 1, (array) get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * Granting consent reports the acceptance and releases what was held.
	 */
	public function test_granting_consent_reports_and_flushes(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->set_consent( 'undecided' );
		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_consent_prompt_viewed( 'modal' );

		// Write the choice the way the REST route does, without clearing the
		// cached answer — report_consent_granted() must handle that itself.
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		SettingsService::instance()->save_settings( 'general', $settings );

		$lifecycle->report_consent_granted();

		$accepted = $this->find_event( 'consent_notice_accepted' );
		$this->assertNotNull( $accepted );

		// No surface on the acceptance — the server cannot know which prompt was
		// answered. It travels on the paired view instead.
		$this->assertArrayNotHasKey( 'surface', $accepted['properties'] );

		$viewed = $this->find_event( 'consent_notice_viewed' );
		$this->assertNotNull( $viewed );
		$this->assertSame( 'modal', $viewed['properties']['surface'] );

		// The queued view went out in the same pass.
		$this->assertNotNull( $this->find_event( 'consent_notice_viewed' ) );
		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * Declining sends nothing — not the decline, and not the queued view.
	 */
	public function test_declining_consent_transmits_nothing(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_consent_prompt_viewed( 'callout' );

		$this->set_consent( 'denied' );
		$lifecycle->flush_pending();

		$this->assertSame( array(), $this->captured_event_names() );
		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );
	}

	/**
	 * A refusal empties the queue in the request that records it.
	 *
	 * Waiting for the next admin_init would leave the events the user just
	 * declined sitting in wp_options in the meantime.
	 */
	public function test_discarding_pending_empties_the_queue(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_consent_prompt_viewed( 'callout' );
		$this->assertNotEmpty( get_option( Lifecycle_Events::PENDING_OPTION ) );

		$lifecycle->discard_pending();

		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );
		$this->assertSame( array(), $this->captured_event_names() );
	}

	/**
	 * Opening the POS is reported once per user per day, not per page load.
	 *
	 * A till is reloaded constantly. Without the window this would repeat the
	 * flood that made `upgrade_cta_viewed` 90% of the dataset.
	 */
	public function test_app_open_is_reported_once_per_day(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->report_app_opened();
		$lifecycle->report_app_opened();
		$lifecycle->report_app_opened();

		$opens = array_filter( $this->captured_event_names(), static fn ( $name ) => 'pos_app_opened' === $name );
		$this->assertCount( 1, $opens );

		$event = $this->find_event( 'pos_app_opened' );
		$this->assertTrue( $event['properties']['is_first_open'] );
	}

	/**
	 * The first-open latch is written even without consent, so a site that
	 * consents later does not have a later open mislabelled as its first.
	 */
	public function test_first_open_latch_is_written_without_consent(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->report_app_opened();

		$this->assertSame( array(), $this->captured_event_names() );
		$this->assertNotFalse( get_option( Lifecycle_Events::FIRST_OPEN_OPTION ) );

		// Now they consent. The next open is NOT their first, and must not claim to be.
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle->report_app_opened();

		$event = $this->find_event( 'pos_app_opened' );
		$this->assertNotNull( $event );
		$this->assertFalse( $event['properties']['is_first_open'] );
	}

	/**
	 * The first POS sale is the activation milestone the north-star metric
	 * rests on, so it must be reported exactly once.
	 */
	public function test_first_pos_order_is_reported_once(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		$lifecycle = new Lifecycle_Events();
		$lifecycle->maybe_record_first_pos_order( $order->get_id(), $order );

		// Sent immediately, NOT queued: a POS-only store may never load a
		// wp-admin page, and the queue is drained on admin_init.
		$first = $this->find_event( 'pos_first_order' );
		$this->assertNotNull( $first );
		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );

		// A second sale must not report a second "first".
		$second = OrderHelper::create_order();
		$second->set_created_via( 'woocommerce-pos' );
		$second->save();

		$before = \count( $this->captured_event_names() );
		$lifecycle->maybe_record_first_pos_order( $second->get_id(), $second );

		$this->assertSame( $before, \count( $this->captured_event_names() ) );
	}

	/**
	 * An offline sale is dated by when it was rung up, not when it synced.
	 *
	 * The POS sells offline and syncs later, and WCPOS preserves the client's
	 * date_created. Dating the milestone by the sync would report a sale made on
	 * day 3 as ten days to first revenue, and file it in the wrong cohort.
	 */
	public function test_first_pos_order_is_dated_by_the_sale_not_the_sync(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		// Installed 10 days ago; the sale happened 7 days ago and is syncing now.
		update_option( 'woocommerce_pos_installed_at', time() - ( 10 * DAY_IN_SECONDS ) );
		$sold_at = time() - ( 7 * DAY_IN_SECONDS );

		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_date_created( $sold_at );
		$order->save();

		( new Lifecycle_Events() )->maybe_record_first_pos_order( $order->get_id(), $order );

		$event = $this->find_event( 'pos_first_order' );
		$this->assertNotNull( $event );

		// 3 days from install to first sale — not the 10 that "now" would give.
		$this->assertSame( 3, $event['properties']['days_since_install'] );
		$this->assertSame( gmdate( 'c', $sold_at ), $event['timestamp'] );
	}

	/**
	 * A sale that did not come from the POS is not an activation.
	 */
	public function test_non_pos_orders_do_not_report_activation(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$order = OrderHelper::create_order();
		$order->set_created_via( 'checkout' );
		$order->save();

		( new Lifecycle_Events() )->maybe_record_first_pos_order( $order->get_id(), $order );

		$this->assertNotContains( 'pos_first_order', $this->captured_event_names() );
		$this->assertFalse( get_option( Lifecycle_Events::FIRST_ORDER_OPTION ) );
	}

	/**
	 * The milestone happens once, so an undecided answer must not lose it.
	 */
	public function test_first_pos_order_is_queued_while_consent_is_undecided(): void {
		$this->set_consent( 'undecided' );

		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();

		( new Lifecycle_Events() )->maybe_record_first_pos_order( $order->get_id(), $order );

		$this->assertSame( array(), $this->captured_event_names() );

		$pending = get_option( Lifecycle_Events::PENDING_OPTION );
		$this->assertIsArray( $pending );
		$this->assertSame( 'pos_first_order', $pending[0]['event'] );
	}

	/**
	 * The latch stores a CONSTANT, which is the whole reason it is safe.
	 *
	 * add_option() does a read then an INSERT ... ON DUPLICATE KEY UPDATE. With
	 * differing values a racing loser's upsert changes the row, so add_option()
	 * returns true for both callers and the "first" event fires twice. With an
	 * identical value the loser changes nothing and correctly gets false. If
	 * someone turns these latches back into timestamps, this test should fail.
	 */
	public function test_latch_is_a_real_claim_not_a_timestamp(): void {
		$this->assertSame( '1', Lifecycle_Events::LATCH_VALUE );

		$this->assertTrue( add_option( Lifecycle_Events::FIRST_ORDER_OPTION, Lifecycle_Events::LATCH_VALUE, '', true ) );

		// The second claim of the same latch must lose.
		$this->assertFalse( add_option( Lifecycle_Events::FIRST_ORDER_OPTION, Lifecycle_Events::LATCH_VALUE, '', true ) );
	}

	/**
	 * Opening the POS drains the queue.
	 *
	 * A POS-only store may never load a wp-admin page, and admin_init is where
	 * the queue is normally drained — so without this the install and first-sale
	 * events would sit unsent forever on the stores that use the product most.
	 */
	public function test_opening_the_pos_flushes_queued_events(): void {
		$this->set_consent( 'undecided' );

		$lifecycle = new Lifecycle_Events();
		$lifecycle->record_install();
		$this->assertNotEmpty( get_option( Lifecycle_Events::PENDING_OPTION ) );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->set_consent( 'allowed' );

		$lifecycle->report_app_opened();

		$this->assertNotNull( $this->find_event( 'wcpos_installed' ) );
		$this->assertFalse( get_option( Lifecycle_Events::PENDING_OPTION ) );
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
	 * The refresh persists the churn band for uninstall.php to read.
	 *
	 * uninstall.php runs with no plugin code and no warm cache, so without this
	 * the churn metric would be present or absent depending on how recently the
	 * site happened to load an admin page.
	 */
	public function test_group_refresh_persists_the_order_band(): void {
		$this->set_consent( 'allowed' );
		delete_option( Lifecycle_Events::LAST_ORDER_BAND_OPTION );

		( new Lifecycle_Events() )->refresh_group_properties();

		$band = get_option( Lifecycle_Events::LAST_ORDER_BAND_OPTION );
		$this->assertNotFalse( $band );
		$this->assertContains(
			$band,
			array_merge(
				array_map( 'strval', array_keys( \WCPOS\WooCommercePOS\Services\Analytics_Profile::COUNT_BANDS ) ),
				array( \WCPOS\WooCommercePOS\Services\Analytics_Profile::OVERFLOW_BAND )
			)
		);

		$group = $this->find_event( '$groupidentify' );
		$this->assertNotNull( $group );
		$this->assertSame( $group['properties']['$group_set']['order_count_band'], $band );
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
