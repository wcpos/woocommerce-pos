<?php
/**
 * Tests for the Sentry error reporter.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\Vendor\Sentry\Event;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Error_Reporter;
use WCPOS\WooCommercePOS\Services\Settings as SettingsService;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use const WCPOS\WooCommercePOS\VERSION;

/**
 * Tests the consent gate, event construction, and reporter hook paths.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Error_Reporter
 */
class Test_Error_Reporter extends WP_UnitTestCase {
	/**
	 * In-memory transport installed in the client builder.
	 *
	 * @var Stub_Transport
	 */
	private Stub_Transport $transport;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		Error_Reporter::reset_instance();
		$this->transport = new Stub_Transport();
		Error_Reporter::set_transport_factory_for_testing(
			function (): Stub_Transport {
				return $this->transport;
			}
		);
		Error_Reporter::set_dev_override_for_testing( false );
		Logger::reset_dedup_state();
		delete_transient( Error_Reporter::BACKOFF_TRANSIENT );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		Error_Reporter::set_transport_factory_for_testing( null );
		Error_Reporter::set_dev_override_for_testing( null );
		Error_Reporter::reset_instance();
		delete_transient( Error_Reporter::BACKOFF_TRANSIENT );

		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = 'undecided';
		SettingsService::instance()->save_settings( 'general', $settings );

		parent::tearDown();
	}

	/**
	 * Consent states that must keep all reporting paths disabled.
	 *
	 * @return array<string, array{string}>
	 */
	public function consent_not_allowed_provider(): array {
		return array(
			'undecided' => array( 'undecided' ),
			'denied'    => array( 'denied' ),
		);
	}

	/**
	 * No report path builds the SDK or sends an event without consent.
	 *
	 * @dataProvider consent_not_allowed_provider
	 *
	 * @param string $consent Consent state under test.
	 */
	public function test_report_paths_without_consent_do_not_initialize_client( string $consent ): void {
		// Arrange.
		$this->set_consent( $consent );

		// Act.
		Logger::error( 'forced' );
		$this->dispatch_error_route();
		Error_Reporter::instance()->report_fatal( $this->fatal_error() );

		// Assert.
		$this->assertSame( array(), $this->transport->events );
		$this->assertFalse( Error_Reporter::instance()->is_initialized() );
	}

	/**
	 * An allowed logger error produces one identified, tagged event.
	 */
	public function test_logger_error_with_consent_sends_expected_event(): void {
		// Arrange.
		$this->enable_consent();
		$site_uuid = wcpos_get_site_uuid();

		// Act.
		Logger::error( 'boom', array( 'k' => 'v' ) );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$event = $this->transport->events[0];
		$this->assertSame( 'error', (string) $event->getLevel() );
		$this->assertStringContainsString( 'boom', (string) $event->getMessage() );
		$this->assertStringContainsString( 'Context:', (string) $event->getMessage() );
		$this->assertStringContainsString( '[k] => v', (string) $event->getMessage() );
		$this->assertSame( $site_uuid, $event->getUser()->getId() );
		$this->assertSame( 'wcpos-php@' . VERSION, $event->getRelease() );

		$tags = $event->getTags();
		foreach ( array( 'wp_version', 'wc_version', 'php_version', 'pro_version', 'multisite', 'source' ) as $tag ) {
			$this->assertArrayHasKey( $tag, $tags );
			$this->assertIsString( $tags[ $tag ] );
		}
		$this->assertSame( 'logger', $tags['source'] );
	}

	/**
	 * A 500 WCPOS REST error groups by its stable error code.
	 */
	public function test_rest_error_with_consent_sends_code_fingerprint_and_tags(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		$this->dispatch_error_route();

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$event = $this->transport->events[0];
		$this->assertSame( array( 'wcpos-rest', 'wcpos_test_boom' ), $event->getFingerprint() );
		$this->assertSame( '/wcpos/v1/error-reporter-500', $event->getTags()['route'] );
		$this->assertSame( 'GET', $event->getTags()['method'] );
		$this->assertSame( '500', $event->getTags()['status'] );
	}

	/**
	 * An in-plugin fatal produces a fatal-level event with a stable fingerprint.
	 */
	public function test_in_plugin_fatal_with_consent_sends_fatal_event(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		Error_Reporter::instance()->report_fatal( $this->fatal_error() );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$event = $this->transport->events[0];
		$this->assertSame( 'fatal', (string) $event->getLevel() );
		$this->assertSame( 'wcpos-fatal', $event->getFingerprint()[0] );
	}

	/**
	 * The per-request cap suppresses a second distinct logger error.
	 */
	public function test_two_distinct_logger_errors_send_only_first_event(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		Logger::error( 'first error' );
		Logger::error( 'second error' );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$this->assertSame( 'first error', $this->transport->events[0]->getMessage() );
	}

	/**
	 * A fatal outside WCPOS is ignored before client construction.
	 */
	public function test_fatal_outside_plugin_path_sends_no_event(): void {
		// Arrange.
		$this->enable_consent();
		$error         = $this->fatal_error();
		$error['file'] = '/var/www/html/wp-includes/plugin.php';

		// Act.
		Error_Reporter::instance()->report_fatal( $error );

		// Assert.
		$this->assertSame( array(), $this->transport->events );
	}

	/**
	 * A non-fatal PHP error type is ignored.
	 */
	public function test_non_fatal_error_type_inside_plugin_sends_no_event(): void {
		// Arrange.
		$this->enable_consent();
		$error         = $this->fatal_error();
		$error['type'] = E_WARNING;

		// Act.
		Error_Reporter::instance()->report_fatal( $error );

		// Assert.
		$this->assertSame( array(), $this->transport->events );
	}

	/**
	 * A development site never reports, even with consent allowed.
	 *
	 * Drives the dev answer through the test seam because the real check
	 * reads constants (WP_DEBUG / WCPOS_DEV) that cannot be redefined
	 * mid-process — the seam sits directly behind them.
	 */
	public function test_dev_environment_with_consent_disables_reporting(): void {
		// Arrange.
		$this->enable_consent();
		Error_Reporter::set_dev_override_for_testing( true );

		// Act.
		$enabled = Error_Reporter::instance()->is_enabled();
		Logger::error( 'dev site error' );

		// Assert.
		$this->assertFalse( $enabled );
		$this->assertSame( array(), $this->transport->events );
		$this->assertFalse( Error_Reporter::instance()->is_initialized() );
	}

	/**
	 * The host kill switch can disable reporting after consent.
	 */
	public function test_kill_switch_with_consent_sends_no_event(): void {
		// Arrange.
		$this->enable_consent();
		add_filter( 'woocommerce_pos_error_reporting_enabled', '__return_false' );

		// Act.
		Logger::error( 'blocked by host' );

		// Assert.
		remove_filter( 'woocommerce_pos_error_reporting_enabled', '__return_false' );
		$this->assertSame( array(), $this->transport->events );
	}

	/**
	 * The host filter cannot turn reporting on without merchant consent.
	 */
	public function test_filter_without_consent_cannot_enable_reporting(): void {
		// Arrange.
		$this->set_consent( 'denied' );
		add_filter( 'woocommerce_pos_error_reporting_enabled', '__return_true' );

		// Act.
		Logger::error( 'must remain blocked' );

		// Assert.
		remove_filter( 'woocommerce_pos_error_reporting_enabled', '__return_true' );
		$this->assertSame( array(), $this->transport->events );
		$this->assertFalse( Error_Reporter::instance()->is_initialized() );
	}

	/**
	 * A settings filter cannot manufacture consent the merchant never gave.
	 *
	 * `Settings::tracking_consent()` reads the filtered view, so gating on it
	 * would let any plugin filtering woocommerce_pos_general_settings switch
	 * reporting on for a store that declined.
	 */
	public function test_settings_filter_claiming_consent_sends_no_event(): void {
		// Arrange.
		$this->set_consent( 'denied' );
		$claim_consent = static function ( $settings ) {
			$settings['tracking_consent'] = 'allowed';

			return $settings;
		};
		add_filter( 'woocommerce_pos_general_settings', $claim_consent );

		// Act.
		Logger::error( 'must not be reported' );

		// Assert.
		remove_filter( 'woocommerce_pos_general_settings', $claim_consent );
		$this->assertSame( array(), $this->transport->events );
		$this->assertFalse( Error_Reporter::instance()->is_initialized() );
	}

	/**
	 * Consent recorded before the move out of the legacy `tools` option still counts.
	 */
	public function test_legacy_tools_consent_is_honoured_by_the_raw_read(): void {
		// Arrange: general has no tracking_consent key at all.
		update_option( 'woocommerce_pos_settings_general', array() );
		update_option( 'woocommerce_pos_settings_tools', array( 'tracking_consent' => 'allowed' ) );

		// Act.
		$consent = SettingsService::instance()->raw_tracking_consent();

		// Assert.
		delete_option( 'woocommerce_pos_settings_tools' );
		$this->assertSame( 'allowed', $consent );
	}

	/**
	 * Scrubbing removes merchant host, query, headers, cookies, and environment.
	 */
	public function test_scrub_event_removes_request_pii_and_sets_site_user(): void {
		// Arrange.
		$site_uuid = wcpos_get_site_uuid();
		$event     = Event::createEvent();
		$event->setRequest(
			array(
				'url'     => 'https://shop.example.com/wp-json/wcpos/v1/orders?secret=1',
				'method'  => 'POST',
				'cookies' => array( 'a' => 'b' ),
				'headers' => array( 'Authorization' => 'Bearer x' ),
				'env'     => array( 'REMOTE_ADDR' => '1.2.3.4' ),
			)
		);
		$event->setServerName( 'shop.example.com' );

		// Act.
		$scrubbed = Error_Reporter::scrub_event( $event );

		// Assert.
		$this->assertSame(
			array(
				'method' => 'POST',
				'url'    => '/wp-json/wcpos/v1/orders',
			),
			$scrubbed->getRequest()
		);
		$this->assertSame( '', $scrubbed->getServerName() );
		$this->assertSame( $site_uuid, $scrubbed->getUser()->getId() );
	}

	/**
	 * Logger de-duplication prevents the duplicate from reaching the reporter.
	 */
	public function test_duplicate_logger_error_forwards_only_first_write(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		Logger::error( 'same error' );
		Logger::error( 'same error' );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
	}

	/**
	 * A 500 outside the WCPOS namespaces is another plugin's problem.
	 */
	public function test_non_wcpos_route_500_sends_no_event(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		$this->dispatch_stub_route( 'other/v1', '/boom', 500 );

		// Assert.
		$this->assertSame( array(), $this->transport->events );
	}

	/**
	 * A WCPOS client error is not a server fault and is never reported.
	 */
	public function test_wcpos_route_below_500_sends_no_event(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		$this->dispatch_stub_route( 'wcpos/v1', '/client-error', 400 );

		// Assert.
		$this->assertSame( array(), $this->transport->events );
	}

	/**
	 * A fatal still reports after an earlier error consumed the normal slot.
	 */
	public function test_fatal_after_logger_error_still_sends_its_own_event(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		Logger::error( 'earlier error' );
		Error_Reporter::instance()->report_fatal( $this->fatal_error() );

		// Assert.
		$this->assertCount( 2, $this->transport->events );
		$this->assertSame( 'fatal', (string) $this->transport->events[1]->getLevel() );
	}

	/**
	 * Only one fatal is reported per request.
	 */
	public function test_second_fatal_in_one_request_sends_no_event(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		Error_Reporter::instance()->report_fatal( $this->fatal_error() );
		Error_Reporter::instance()->report_fatal( $this->fatal_error() );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
	}

	/**
	 * Absolute paths never leave the server inside message text.
	 *
	 * Shared hosting bakes the store domain into the docroot, so a fatal's
	 * textual stack trace is a hostname leak unless the paths are redacted.
	 */
	public function test_scrub_event_redacts_absolute_paths_from_the_message(): void {
		// Arrange.
		$event = Event::createEvent();
		$event->setMessage(
			'Uncaught TypeError in ' . PLUGIN_PATH . 'includes/Init.php:12'
				. ' Stack trace: #0 ' . ABSPATH . 'wp-includes/class-wp-hook.php(324)'
		);

		// Act.
		$scrubbed = Error_Reporter::scrub_event( $event );

		// Assert.
		$message = (string) $scrubbed->getMessage();
		$this->assertStringNotContainsString( ABSPATH, $message );
		$this->assertStringNotContainsString( untrailingslashit( WP_CONTENT_DIR ), $message );
		$this->assertStringContainsString( '<wp-content>', $message );
		$this->assertStringContainsString( 'includes/Init.php:12', $message );
	}

	/**
	 * A site that suppressed WCPOS logging does not get remote egress instead.
	 */
	public function test_logging_filter_disabled_sends_no_event(): void {
		// Arrange.
		$this->enable_consent();
		add_filter( 'woocommerce_pos_logging', '__return_false' );

		// Act.
		Logger::error( 'suppressed locally' );

		// Assert.
		remove_filter( 'woocommerce_pos_logging', '__return_false' );
		$this->assertSame( array(), $this->transport->events );
	}

	/**
	 * Logger events group on the bare message, not the context.
	 */
	public function test_logger_event_fingerprint_excludes_the_context(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		Logger::error( 'recurring failure', array( 'order_id' => 4242 ) );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$this->assertSame(
			array( 'wcpos-log', 'error', 'recurring failure' ),
			$this->transport->events[0]->getFingerprint()
		);
	}

	/**
	 * Resource ids are collapsed out of the route tag.
	 */
	public function test_rest_route_tag_collapses_resource_ids(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		$this->dispatch_stub_route( 'wcpos/v1', '/things/4242', 500 );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$this->assertSame( '/wcpos/v1/things/{id}', $this->transport->events[0]->getTags()['route'] );
	}

	/**
	 * A failed send suppresses delivery for the rest of the backoff window.
	 */
	public function test_transport_failure_suppresses_the_next_request(): void {
		// Arrange.
		$this->enable_consent();
		$this->transport->fail = true;

		// Act: first request fails and latches the backoff.
		Logger::error( 'first failing send' );

		// A second request: fresh reporter, working transport.
		Error_Reporter::reset_instance();
		$this->transport       = new Stub_Transport();
		$this->transport->fail = false;
		Logger::reset_dedup_state();
		Logger::error( 'second error' );

		// Assert.
		$this->assertSame( array(), $this->transport->events );
	}

	/**
	 * Save allowed tracking consent using the production settings service.
	 */
	private function enable_consent(): void {
		$this->set_consent( 'allowed' );
	}

	/**
	 * Save a tracking consent state.
	 *
	 * @param string $consent Consent state.
	 */
	private function set_consent( string $consent ): void {
		$settings                     = (array) woocommerce_pos_get_settings( 'general' );
		$settings['tracking_consent'] = $consent;
		SettingsService::instance()->save_settings( 'general', $settings );
	}

	/**
	 * Dispatch the stub WCPOS 500 route through the real REST server.
	 */
	private function dispatch_error_route(): void {
		$this->dispatch_stub_route( 'wcpos/v1', '/error-reporter-500', 500 );
	}

	/**
	 * Register and dispatch a stub route returning a WP_Error of one status.
	 *
	 * @param string $namespace REST namespace, e.g. 'wcpos/v1'.
	 * @param string $route     Route path, e.g. '/boom'.
	 * @param int    $status    HTTP status the route's WP_Error carries.
	 */
	private function dispatch_stub_route( string $namespace, string $route, int $status ): void {
		$register_route = static function () use ( $namespace, $route, $status ): void {
			register_rest_route(
				$namespace,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => static function () use ( $status ): WP_Error {
						return new WP_Error( 'wcpos_test_boom', 'boom', array( 'status' => $status ) );
					},
					'permission_callback' => '__return_true',
				)
			);
		};

		add_action( 'rest_api_init', $register_route );
		do_action( 'rest_api_init', rest_get_server() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook under test.
		remove_action( 'rest_api_init', $register_route );

		rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . $namespace . $route ) );
	}

	/**
	 * Build a representative in-plugin fatal error.
	 *
	 * @return array{type: int, message: string, file: string, line: int}
	 */
	private function fatal_error(): array {
		return array(
			'type'    => E_ERROR,
			'message' => 'fatal boom',
			'file'    => PLUGIN_PATH . 'includes/Init.php',
			'line'    => 42,
		);
	}
}
