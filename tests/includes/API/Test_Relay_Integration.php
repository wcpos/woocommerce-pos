<?php
/**
 * Cloud print relay integration tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Relay_Service;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WP_Error;
use WP_REST_Request;

/**
 * Test_Relay_Integration class.
 */
class Test_Relay_Integration extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Active outbound HTTP mock.
	 *
	 * @var callable|null
	 */
	private $http_filter;

	/**
	 * Set up print jobs and relay hooks.
	 */
	public function setUp(): void {
		parent::setUp();
		( new Print_Job_Service() )->register_post_type();
		remove_all_actions( 'woocommerce_pos_print_job_created' );
		remove_all_actions( Cloud_Print_Relay_Service::REREGISTER_HOOK );
		new Cloud_Print_Relay_Service();
	}

	/**
	 * Remove relay state and HTTP mocks.
	 */
	public function tearDown(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
		}
		delete_option( Cloud_Print_Registry::OPTION );
		delete_option( Cloud_Print_Registry::RUNTIME_OPTION );
		delete_option( Cloud_Print_Relay_Service::OPTION );
		delete_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT );
		delete_transient( Cloud_Print_Relay_Service::REREGISTER_TRANSIENT );
		delete_transient( Cloud_Print_Relay_Service::DOWN_TRANSIENT );
		wp_clear_scheduled_hook( Cloud_Print_Relay_Service::REREGISTER_HOOK );
		remove_all_filters( 'woocommerce_pos_cloud_print_relay_enabled' );
		parent::tearDown();
	}

	/**
	 * Mock all outbound HTTP requests.
	 *
	 * @param callable $callback Mock callback.
	 */
	private function mock_http( callable $callback ): void {
		$this->http_filter = $callback;
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * Build a WordPress HTTP response array.
	 *
	 * @param int   $code Response code.
	 * @param array $body JSON response body.
	 *
	 * @return array
	 */
	private function http_response( int $code, array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Store a registered relay and one printer.
	 *
	 * @param string $provider Printer provider.
	 * @param bool   $enabled  Relay enabled state.
	 */
	private function store_relay( string $provider = 'star-cloudprnt', bool $enabled = true ): void {
		update_option(
			Cloud_Print_Registry::OPTION,
			array(
				'printers'    => array(
					array(
						'id'       => 'front',
						'name'     => 'Front',
						'provider' => $provider,
					),
				),
				'assignments' => array(),
			)
		);
		update_option(
			Cloud_Print_Relay_Service::OPTION,
			array(
				'enabled'       => $enabled,
				'site_key'      => '0123456789abcdef0123456789abcdef',
				'hint_secret'   => '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f',
				'registered_at' => time(),
			)
		);
	}

	/**
	 * It returns 404 when no verification is pending.
	 */
	public function test_relay_verification_returns_404_without_pending_token(): void {
		// Arrange.
		delete_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT );

		// Act.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wcpos/v1/print-jobs/relay-verification' ) );

		// Assert.
		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals( 'wcpos_relay_no_pending_verification', $response->as_error()->get_error_code() );
	}

	/**
	 * It exposes the pending verification token once, consuming it on first read.
	 */
	public function test_relay_verification_returns_token_during_registration_window(): void {
		// Arrange.
		set_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT, 'pending-token', 300 );

		// Act.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wcpos/v1/print-jobs/relay-verification' ) );
		$replay   = rest_do_request( new WP_REST_Request( 'GET', '/wcpos/v1/print-jobs/relay-verification' ) );

		// Assert: first read succeeds, the proof is single-use.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( 'token' => 'pending-token' ), $response->get_data() );
		$this->assertEquals( 404, $replay->get_status() );
	}

	/**
	 * It serves the verification route even when the WCPOS request marker is absent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_relay_verification_route_registered_without_wcpos_marker(): void {
		// Arrange: fire rest_api_init on a fresh server exactly as WordPress
		// does for the relay's unmarked callback request — no ?wcpos=1, no
		// X-WCPOS header — so Init takes its unmarked branch for real.
		global $wp_rest_server;
		$previous       = $wp_rest_server;
		$server         = new \WP_REST_Server();
		$wp_rest_server = $server;
		do_action( 'rest_api_init', $server );
		set_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT, 'pending-token', 300 );

		// Act.
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/wcpos/v1/print-jobs/relay-verification' ) );

		// Assert.
		$wp_rest_server = $previous;
		$this->assertFalse( class_exists( 'WCPOS\\WooCommercePOS\\API\\Print_Jobs_Controller', false ) );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( 'token' => 'pending-token' ), $response->get_data() );
	}

	/**
	 * It persists authoritative relay credentials and clears the verification token.
	 */
	public function test_register_endpoint_persists_credentials_and_clears_transient(): void {
		// Arrange.
		$this->mock_http(
			function () {
				return $this->http_response(
					201,
					array(
						'site_key'         => 'abcdef0123456789abcdef0123456789',
						'hint_secret'      => '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff',
						'printer_base_url' => 'https://cloudprint.wcpos.com/p/abcdef0123456789abcdef0123456789',
					)
				);
			}
		);

		// Act.
		$response = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/relay/register' ) );
		$saved    = get_option( Cloud_Print_Relay_Service::OPTION );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( true, $saved['enabled'] );
		$this->assertEquals( 'abcdef0123456789abcdef0123456789', $saved['site_key'] );
		$this->assertEquals( '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff', $saved['hint_secret'] );
		$this->assertGreaterThan( 0, $saved['registered_at'] );
		$this->assertFalse( get_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT ) );
		$this->assertStringNotContainsString( 'hint_secret', wp_json_encode( $response->get_data() ) );
		$this->assertEquals( true, $response->get_data()['enabled'] );
	}

	/**
	 * It never trusts a relay-supplied printer base URL.
	 */
	public function test_register_ignores_relay_supplied_printer_base_url(): void {
		// Arrange: a compromised relay pointing printers at another host.
		$this->mock_http(
			function () {
				return $this->http_response(
					201,
					array(
						'site_key'         => 'abcdef0123456789abcdef0123456789',
						'hint_secret'      => '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff',
						'printer_base_url' => 'https://evil.example/p/abcdef0123456789abcdef0123456789',
					)
				);
			}
		);

		// Act.
		$response = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/relay/register' ) );

		// Assert: the URL is rebuilt from the validated site key.
		$this->assertEquals(
			'https://cloudprint.wcpos.com/p/abcdef0123456789abcdef0123456789',
			$response->get_data()['printer_base_url']
		);
	}

	/**
	 * It rejects relay registration by users who cannot manage settings.
	 */
	public function test_register_endpoint_requires_manage_permissions(): void {
		// Arrange.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user_id );

		// Act.
		$response = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/relay/register' ) );

		// Assert.
		$this->assertEquals( 403, $response->get_status() );
		$this->assertFalse( get_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT ) );
		wp_delete_user( $user_id );
	}

	/**
	 * It keeps relay credentials out of reach of general settings updates.
	 */
	public function test_client_settings_update_cannot_write_relay_section(): void {
		// Arrange.
		$this->store_relay();
		$expected = get_option( Cloud_Print_Relay_Service::OPTION );
		$request  = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers'    => array(),
				'assignments' => array(),
				'relay'       => array(
					'enabled'     => false,
					'site_key'    => 'attacker',
					'hint_secret' => 'attacker',
				),
			)
		);

		// Act.
		rest_do_request( $request );

		// Assert: the relay option is untouched by the settings write path.
		$this->assertEquals( $expected, get_option( Cloud_Print_Relay_Service::OPTION ) );
	}

	/**
	 * It exposes only the public relay settings fields.
	 */
	public function test_settings_response_never_contains_hint_secret_and_exposes_printer_base_url(): void {
		// Arrange.
		$this->store_relay( 'printnode' );
		$this->mock_http(
			function () {
				return new WP_Error( 'offline', 'offline' );
			}
		);

		// Act.
		$response = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) );
		$data     = $response->get_data();

		// Assert.
		$this->assertEquals(
			array(
				'enabled'          => true,
				'available'        => true,
				'printer_base_url' => 'https://cloudprint.wcpos.com/p/0123456789abcdef0123456789abcdef',
			),
			$data['relay']
		);
		$this->assertStringNotContainsString( 'hint_secret', wp_json_encode( $data ) );
	}

	/**
	 * It sends one non-blocking hint only for an enabled polling printer.
	 */
	public function test_job_create_fires_hint_for_polling_printer_when_relay_enabled(): void {
		// Arrange.
		$this->store_relay();
		$requests = array();
		$this->mock_http(
			function ( $pre, $args, $url ) use ( &$requests ) {
				$requests[] = compact( 'args', 'url' );

				return $this->http_response( 202, array() );
			}
		);
		$jobs = new Print_Job_Service();

		// Act.
		$jobs->create(
			array(
				'printer_id' => 'front',
				'payload'    => base64_encode( 'one' ),
			)
		);
		add_filter( 'woocommerce_pos_cloud_print_relay_enabled', '__return_false' );
		$jobs->create(
			array(
				'printer_id' => 'front',
				'payload'    => base64_encode( 'two' ),
			)
		);
		remove_filter( 'woocommerce_pos_cloud_print_relay_enabled', '__return_false' );
		$this->store_relay( 'printnode' );
		$jobs->create(
			array(
				'printer_id' => 'front',
				'payload'    => base64_encode( 'three' ),
			)
		);

		// Assert.
		$this->assertCount( 1, $requests );
		$this->assertEquals( 'https://cloudprint.wcpos.com/api/hint/0123456789abcdef0123456789abcdef', $requests[0]['url'] );
		$this->assertEquals( false, $requests[0]['args']['blocking'] );
		$this->assertEquals( 2, $requests[0]['args']['timeout'] );
		$this->assertEquals( '{"printer_id":"front"}', $requests[0]['args']['body'] );
		$this->assertArrayHasKey( 'X-Relay-Timestamp', $requests[0]['args']['headers'] );
		$this->assertArrayHasKey( 'X-Relay-Signature', $requests[0]['args']['headers'] );
	}

	/**
	 * It matches the relay's fixed HMAC reference vector.
	 */
	public function test_hint_signature_matches_reference_vector(): void {
		// Arrange.
		$secret  = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
		$message = "POST\n/api/hint/abc123\n1750000000\n{\"printer_id\":\"front\"}";

		// Act.
		$signature = Cloud_Print_Relay_Service::sign( 'POST', '/api/hint/abc123', '1750000000', '{"printer_id":"front"}', $secret );

		// Assert.
		$this->assertEquals( hash_hmac( 'sha256', $message, hex2bin( $secret ) ), $signature );
		$this->assertEquals( '7fd24e230bac267fb7d7d257f111e1e28403c348e0b1ce36f84bfc477a012bdd', $signature );
	}

	/**
	 * It maps relay blocking and falls back to the local seen window on failure.
	 */
	public function test_status_for_maps_relay_blocked_and_falls_back_when_unreachable(): void {
		// Arrange.
		$this->store_relay();
		$result = $this->http_response(
			200,
			array(
				'origin_status'         => 'blocked',
				'origin_block_signal'   => 'cloudflare-challenge',
				'last_seen_seconds_ago' => null,
			)
		);
		$this->mock_http(
			function () use ( &$result ) {
				return $result;
			}
		);

		// Act.
		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		// Assert.
		$this->assertEquals( 'blocked', $data['printers'][0]['status'] );
		$this->assertEquals( 'cloudflare-challenge', $data['printers'][0]['status_detail'] );

		// Arrange.
		delete_transient( Cloud_Print_Relay_Service::STATUS_TRANSIENT_PREFIX . 'front' );
		( new Cloud_Print_Registry() )->record_seen( 'front' );
		$result = new WP_Error( 'relay_unreachable', 'Relay unreachable' );

		// Act.
		$status = ( new Cloud_Print_Registry() )->status_for( 'front' );

		// Assert.
		$this->assertEquals( 'connected', $status );
	}

	/**
	 * It stops calling the relay for other printers once one status call fails.
	 */
	public function test_relay_outage_marks_relay_down_site_wide(): void {
		// Arrange: two polling printers, a relay that never answers.
		$this->store_relay();
		$settings               = get_option( Cloud_Print_Registry::OPTION );
		$settings['printers'][] = array(
			'id'       => 'back',
			'name'     => 'Back',
			'provider' => 'star-cloudprnt',
		);
		update_option( Cloud_Print_Registry::OPTION, $settings );
		$calls = 0;
		$this->mock_http(
			function () use ( &$calls ) {
				++$calls;

				return new WP_Error( 'relay_unreachable', 'Relay unreachable' );
			}
		);
		$registry = new Cloud_Print_Registry();

		// Act.
		$registry->status_for( 'front' );
		$registry->status_for( 'back' );

		// Assert: the second printer never triggers a second timeout.
		$this->assertEquals( 1, $calls );
		$this->assertNotFalse( get_transient( Cloud_Print_Relay_Service::DOWN_TRANSIENT ) );
	}

	/**
	 * It schedules only one guarded re-registration for an unknown relay site.
	 */
	public function test_unknown_site_status_schedules_reregistration_once(): void {
		// Arrange.
		$this->store_relay();
		$this->mock_http(
			function () {
				return $this->http_response( 404, array( 'error' => 'unknown site' ) );
			}
		);
		$registry = new Cloud_Print_Registry();

		// Act.
		$registry->status_for( 'front' );

		// Assert.
		$this->assertNotFalse( wp_next_scheduled( Cloud_Print_Relay_Service::REREGISTER_HOOK ) );

		// Arrange.
		wp_clear_scheduled_hook( Cloud_Print_Relay_Service::REREGISTER_HOOK );
		delete_transient( Cloud_Print_Relay_Service::STATUS_TRANSIENT_PREFIX . 'front' );
		delete_transient( Cloud_Print_Relay_Service::DOWN_TRANSIENT );

		// Act.
		$registry->status_for( 'front' );

		// Assert.
		$this->assertFalse( wp_next_scheduled( Cloud_Print_Relay_Service::REREGISTER_HOOK ) );
	}

	/**
	 * It backs off for the re-registration window on an unknown relay site.
	 *
	 * The 404 cannot clear inside the 30s status window — the stored site_key
	 * simply is not in the relay's registry, and re-registration is itself
	 * rate-limited to once per REREGISTER_GUARD. Retrying on the short window
	 * replays the identical 404 twice a minute indefinitely for any site that
	 * cannot re-register.
	 */
	public function test_unknown_site_status_backs_off_for_the_reregistration_window(): void {
		// Arrange.
		$this->store_relay();
		$calls = 0;
		$this->mock_http(
			function () use ( &$calls ) {
				++$calls;

				return $this->http_response( 404, array( 'error' => 'unknown site' ) );
			}
		);
		$registry = new Cloud_Print_Registry();

		// Act.
		$registry->status_for( 'front' );

		// Assert: backed off well past the short status window.
		$expiry = (int) get_option( '_transient_timeout_' . Cloud_Print_Relay_Service::DOWN_TRANSIENT );
		$this->assertGreaterThan(
			time() + Cloud_Print_Relay_Service::STATUS_CACHE_TTL,
			$expiry,
			'unknown site must not retry on the 30s status window'
		);

		// Act: a second call inside the window must not reach the relay again.
		$registry->status_for( 'front' );

		// Assert.
		$this->assertSame( 1, $calls, 'a futile unknown-site 404 must not be replayed while backed off' );
	}

	/**
	 * It keeps the relay disabled when the admin disables it mid re-registration.
	 */
	public function test_reregister_preserves_disable_that_lands_mid_flight(): void {
		// Arrange: enabled at launch; the admin disables while the outbound
		// registration request is in flight.
		$this->store_relay();
		$this->mock_http(
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, '/api/register' ) ) {
					Cloud_Print_Relay_Service::disable();

					return $this->http_response(
						201,
						array(
							'site_key'    => 'abcdef0123456789abcdef0123456789',
							'hint_secret' => '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff',
						)
					);
				}

				return new WP_Error( 'unexpected', 'unexpected request' );
			}
		);

		// Act.
		( new Cloud_Print_Relay_Service() )->reregister();
		$saved = get_option( Cloud_Print_Relay_Service::OPTION );

		// Assert: credentials refreshed, but the admin's disable wins.
		$this->assertFalse( (bool) $saved['enabled'] );
		$this->assertEquals( 'abcdef0123456789abcdef0123456789', $saved['site_key'] );
	}

	/**
	 * It clears the per-printer status backoff when re-registration succeeds.
	 *
	 * Because status() reads the per-printer negative cache before DOWN_TRANSIENT,
	 * the unknown-site 404 backoff (a full REREGISTER_GUARD window) would leave
	 * the affected printer dead for up to an hour after recovery unless that
	 * cache is cleared. A recovered site must poll the relay again on the next
	 * call instead of returning the cached failure.
	 */
	public function test_successful_reregistration_clears_unknown_site_status_backoff(): void {
		// Arrange: a registered site whose stored key the relay no longer knows.
		$this->store_relay();
		$status_calls = 0;
		$this->mock_http(
			function ( $pre, $args, $url ) use ( &$status_calls ) {
				if ( false !== strpos( $url, '/api/status/' ) ) {
					++$status_calls;

					return $this->http_response( 404, array( 'error' => 'unknown site' ) );
				}
				if ( false !== strpos( $url, '/api/register' ) ) {
					return $this->http_response(
						201,
						array(
							'site_key'    => 'abcdef0123456789abcdef0123456789',
							'hint_secret' => '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff',
						)
					);
				}

				return new WP_Error( 'unexpected', 'unexpected request' );
			}
		);
		$registry = new Cloud_Print_Registry();

		// Act: the 404 backs the printer off for the re-registration window.
		$registry->status_for( 'front' );

		// Assert: the per-printer failure is cached and the relay was hit once.
		$this->assertNotFalse(
			get_transient( Cloud_Print_Relay_Service::STATUS_TRANSIENT_PREFIX . 'front' ),
			'the unknown-site 404 must cache a per-printer failure'
		);
		$this->assertSame( 1, $status_calls );

		// Act: re-registration succeeds and adopts the relay's fresh key.
		( new Cloud_Print_Relay_Service() )->reregister();

		// Assert: the per-printer backoff is gone, so status can resume.
		$this->assertFalse(
			get_transient( Cloud_Print_Relay_Service::STATUS_TRANSIENT_PREFIX . 'front' ),
			'successful re-registration must clear the per-printer status backoff'
		);

		// Act: the recovered printer polls the relay again.
		$registry->status_for( 'front' );

		// Assert: a fresh status request was issued, not the cached failure.
		$this->assertSame(
			2,
			$status_calls,
			'a recovered printer must poll the relay again instead of returning the cached failure'
		);

		// Clean up the per-printer transient re-cached by the final poll.
		delete_transient( Cloud_Print_Relay_Service::STATUS_TRANSIENT_PREFIX . 'front' );
	}

	/**
	 * It never registers a site that opted out via the filter.
	 */
	public function test_reregister_cron_respects_opt_out_filter(): void {
		// Arrange: registered, then opted out in code.
		$this->store_relay();
		add_filter( 'woocommerce_pos_cloud_print_relay_enabled', '__return_false' );
		$calls = 0;
		$this->mock_http(
			function () use ( &$calls ) {
				++$calls;

				return $this->http_response( 201, array() );
			}
		);

		// Act.
		( new Cloud_Print_Relay_Service() )->reregister();

		// Assert: no outbound registration.
		$this->assertEquals( 0, $calls );
	}

	/**
	 * It reports the relay as available-but-unregistered without self-registering on a read.
	 */
	public function test_settings_relay_reports_available_when_unregistered(): void {
		// Arrange: no relay credentials, and a tripwire on outbound HTTP.
		$calls = 0;
		$this->mock_http(
			function () use ( &$calls ) {
				++$calls;

				return new WP_Error( 'unexpected', 'settings reads must not register' );
			}
		);

		// Act.
		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		// Assert: the settings app is told to self-register; the read itself never does.
		$this->assertEquals(
			array(
				'enabled'   => false,
				'available' => true,
			),
			$data['relay']
		);
		$this->assertEquals( 0, $calls );
	}

	/**
	 * It reports a legacy uppercase site key as registered.
	 */
	public function test_settings_relay_reports_enabled_for_uppercase_site_key(): void {
		// Arrange: a key stored before registration lowercased on write. It
		// still signs hints and status calls, so the settings app must not be
		// told the relay is unregistered.
		update_option(
			Cloud_Print_Relay_Service::OPTION,
			array(
				'enabled'       => true,
				'site_key'      => '0123456789ABCDEF0123456789ABCDEF',
				'hint_secret'   => '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f',
				'registered_at' => time(),
			)
		);
		$this->mock_http(
			function () {
				return new WP_Error( 'offline', 'offline' );
			}
		);

		// Act.
		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();
		$current_data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v2/settings/cloud-print' ) )->get_data();

		// Assert.
		$this->assertEquals(
			array(
				'enabled'          => true,
				'available'        => true,
				'printer_base_url' => 'https://cloudprint.wcpos.com/p/0123456789ABCDEF0123456789ABCDEF',
			),
			$data['relay']
		);
		$this->assertSame( $data['relay'], $current_data['relay'] );
	}

	/**
	 * It hides the relay everywhere when the opt-out filter is in place.
	 */
	public function test_relay_filter_opt_out_hides_relay_everywhere(): void {
		// Arrange: valid credentials on record, but the site opted out in code.
		$this->store_relay();
		add_filter( 'woocommerce_pos_cloud_print_relay_enabled', '__return_false' );
		$calls = 0;
		$this->mock_http(
			function () use ( &$calls ) {
				++$calls;

				return $this->http_response( 202, array() );
			}
		);

		// Act.
		$settings = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();
		$register = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/relay/register' ) );
		( new Print_Job_Service() )->create(
			array(
				'printer_id' => 'front',
				'payload'    => base64_encode( 'X' ),
			)
		);

		// Assert: state hidden, registration refused, no hint sent.
		$this->assertEquals(
			array(
				'enabled'   => false,
				'available' => false,
			),
			$settings['relay']
		);
		$this->assertEquals( 403, $register->get_status() );
		$this->assertEquals( 'wcpos_relay_disabled', $register->as_error()->get_error_code() );
		$this->assertEquals( 0, $calls );
	}

	/**
	 * It ignores the legacy stored disabled flag when credentials are valid.
	 */
	public function test_relay_state_ignores_stored_disabled_flag(): void {
		// Arrange: the brief toggle era could leave enabled=false in the option.
		$this->store_relay( 'star-cloudprnt', false );

		// Act.
		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		// Assert: the filter is the only off switch; credentials mean enabled.
		$this->assertEquals( true, $data['relay']['enabled'] );
	}
}
