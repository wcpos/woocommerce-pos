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
	 * Fatal error types recognized at shutdown.
	 *
	 * @return array<string, array{int}>
	 */
	public function fatal_error_type_provider(): array {
		return array(
			'engine fatal'      => array( E_ERROR ),
			'user fatal'        => array( E_USER_ERROR ),
			'recoverable fatal' => array( E_RECOVERABLE_ERROR ),
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
	 * WCPOS REST namespaces the reporter captures 500s from.
	 *
	 * Both lanes are exercised because the route check matches both. Covering
	 * only the current one would let the `/wcpos/v1/` branch be deleted from
	 * `filter_rest_request_after_callbacks()` with the suite still green.
	 *
	 * @return array<string, array{string}>
	 */
	public function wcpos_rest_namespace_provider(): array {
		return array(
			'v1 lane' => array( 'wcpos/v1' ),
			'v2 lane' => array( 'wcpos/v2' ),
		);
	}

	/**
	 * A 500 WCPOS REST error groups by its stable error code.
	 *
	 * @dataProvider wcpos_rest_namespace_provider
	 *
	 * @param string $namespace REST namespace under test.
	 */
	public function test_rest_error_with_consent_sends_code_fingerprint_and_tags( string $namespace ): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		$this->dispatch_stub_route( $namespace, '/error-reporter-500', 500 );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$event = $this->transport->events[0];
		$this->assertSame( array( 'wcpos-rest', 'wcpos_test_boom' ), $event->getFingerprint() );
		$this->assertSame( '/' . $namespace . '/error-reporter-500', $event->getTags()['route'] );
		$this->assertSame( 'GET', $event->getTags()['method'] );
		$this->assertSame( '500', $event->getTags()['status'] );
	}

	/**
	 * An in-plugin fatal produces a fatal-level event with a stable fingerprint.
	 *
	 * @dataProvider fatal_error_type_provider
	 *
	 * @param int $type Fatal error type.
	 */
	public function test_in_plugin_fatal_with_consent_sends_fatal_event( int $type ): void {
		// Arrange.
		$this->enable_consent();
		$error         = $this->fatal_error();
		$error['type'] = $type;

		// Act.
		Error_Reporter::instance()->report_fatal( $error );

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
	 * No send gate anywhere reads consent through the filtered settings view.
	 *
	 * `Settings::tracking_consent()` applies `woocommerce_pos_general_settings`,
	 * so any gate deciding whether data LEAVES the site must call
	 * `raw_tracking_consent()` instead. Hardening one gate and leaving the
	 * others is no hardening at all — a filter simply picks another door. The
	 * two allowed exceptions are the consent prompt's own visibility checks,
	 * which are UI decisions a host may legitimately override.
	 */
	public function test_no_send_gate_reads_consent_through_the_settings_filter(): void {
		// Arrange.
		$includes = \dirname( __DIR__, 3 ) . '/includes';
		$allowed  = array( 'Admin/Consent.php' );

		// Act.
		$offenders = array();
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = str_replace( $includes . '/', '', $file->getPathname() );
			if ( \in_array( $relative, $allowed, true ) || 0 === strpos( $relative, 'Services/Settings' ) ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );
			if ( preg_match( '/(?<!raw_)tracking_consent\(\)/', $contents ) ) {
				$offenders[] = $relative;
			}
		}
		sort( $offenders );

		// Assert.
		$this->assertSame(
			array(),
			$offenders,
			'These files gate on the FILTERED consent view. Use Settings::raw_tracking_consent() '
				. 'for anything that decides whether data leaves the site, or add the file to $allowed '
				. 'if it is genuinely a UI-only read: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * An unset consent reads as `undecided`, not an empty string.
	 *
	 * The value is mirrored to the POS client, whose schema expects one of the
	 * three states, so the raw read must fall back to the section default the
	 * filtered accessor would have supplied.
	 */
	public function test_raw_consent_falls_back_to_undecided_when_never_set(): void {
		// Arrange.
		update_option( 'woocommerce_pos_settings_general', array() );

		// Act.
		$consent = SettingsService::instance()->raw_tracking_consent();

		// Assert.
		$this->assertSame( 'undecided', $consent );
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
				'url'     => 'https://shop.example.com/wp-json/wcpos/v2/orders?secret=1',
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
				'url'    => '/wp-json/wcpos/v2/orders',
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
		$this->dispatch_stub_route( 'wcpos/v2', '/client-error', 400 );

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
	 * A logged `critical` must not consume the shutdown fatal's slot.
	 *
	 * `Logger::$log_level` is public, so anything can log at `critical`. If
	 * that claimed the reserved slot, the real fatal arriving later in the
	 * same request would be dropped — the starvation the second slot exists
	 * to prevent.
	 */
	public function test_logged_critical_does_not_consume_the_fatal_slot(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		Logger::set_log_level( 'critical' );
		Logger::log( 'critical but not a php fatal' );
		Logger::set_log_level( 'info' );
		Error_Reporter::instance()->report_fatal( $this->fatal_error() );

		// Assert.
		$this->assertCount( 2, $this->transport->events );
		$this->assertSame( 'fatal', $this->transport->events[1]->getTags()['source'] );
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
	 * Logger fingerprints cannot retain an absolute server path.
	 */
	public function test_logger_event_fingerprint_redacts_absolute_paths(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		Logger::error( 'failure in ' . PLUGIN_PATH . 'includes/Init.php' );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$fingerprint_message = $this->transport->events[0]->getFingerprint()[2];
		$this->assertStringNotContainsString( untrailingslashit( WP_CONTENT_DIR ), $fingerprint_message );
		$this->assertStringNotContainsString( ABSPATH, $fingerprint_message );
		$this->assertStringContainsString( 'includes/Init.php', $fingerprint_message );
	}

	/**
	 * Resource ids are collapsed out of the route tag.
	 */
	public function test_rest_route_tag_collapses_resource_ids(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		$this->dispatch_stub_route( 'wcpos/v2', '/things/4242', 500 );

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$this->assertSame( '/wcpos/v2/things/{id}', $this->transport->events[0]->getTags()['route'] );
	}

	/**
	 * Every regex-matched route value is removed from the off-site route tag.
	 */
	public function test_rest_route_tag_redacts_dynamic_printer_credentials(): void {
		// Arrange.
		$this->enable_consent();

		// Act.
		$this->dispatch_stub_route(
			'wcpos/v2',
			'/print-jobs/cloudprnt/(?P<printer_id>[^/]+)/(?P<pt>[^/]+)',
			500,
			'/print-jobs/cloudprnt/printer-alpha/secret-token'
		);

		// Assert.
		$this->assertCount( 1, $this->transport->events );
		$this->assertSame(
			'/wcpos/v2/print-jobs/cloudprnt/{param}/{param}',
			$this->transport->events[0]->getTags()['route']
		);
	}

	/**
	 * The SDK stays disabled when its serializer's mbstring functions are absent.
	 */
	public function test_missing_mbstring_functions_prevent_client_initialization(): void {
		$script = <<<'PHP'
<?php
$root = getcwd();
define( 'ABSPATH', $root . '/' );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
define( 'WP_TESTS_DOMAIN', 'example.test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WCPOS\\WooCommercePOS\\PLUGIN_PATH', $root . '/' );
define( 'WCPOS\\WooCommercePOS\\VERSION', 'test' );
require $root . '/vendor_prefixed/autoload.php';
require $root . '/includes/Services/Error_Reporter.php';
require $root . '/tests/includes/Services/Stub_Transport.php';

use WCPOS\WooCommercePOS\Services\Error_Reporter;
use WCPOS\WooCommercePOS\Tests\Services\Stub_Transport;

Error_Reporter::set_transport_factory_for_testing(
	static function (): Stub_Transport {
		return new Stub_Transport();
	}
);
$reporter = Error_Reporter::instance();
$method   = new ReflectionMethod( $reporter, 'get_client' );
$method->setAccessible( true );
$method->invoke( $reporter );
echo $reporter->is_initialized() ? 'initialized' : 'disabled';
PHP;

		$process = proc_open(
			array( PHP_BINARY, '-d', 'disable_functions=mb_detect_encoding,mb_convert_encoding,mb_strlen,mb_substr' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			\dirname( __DIR__, 3 )
		);

		$this->assertIsResource( $process );
		fwrite( $pipes[0], $script );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), $errors );
		$this->assertSame( 'disabled', $output );
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
		$this->dispatch_stub_route( 'wcpos/v2', '/error-reporter-500', 500 );
	}

	/**
	 * Register and dispatch a stub route returning a WP_Error of one status.
	 *
	 * @param string      $namespace     REST namespace, e.g. 'wcpos/v2'.
	 * @param string      $route         Route path, e.g. '/boom'.
	 * @param int         $status        HTTP status the route's WP_Error carries.
	 * @param null|string $request_route Concrete route to dispatch when registration uses a regex.
	 */
	private function dispatch_stub_route( string $namespace, string $route, int $status, ?string $request_route = null ): void {
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

		rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . $namespace . ( $request_route ?? $route ) ) );
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
