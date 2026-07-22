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
		remove_all_actions( 'wcpos_print_job_created' );
		add_action( 'wcpos_print_job_created', array( Cloud_Print_Relay_Service::class, 'send_hint' ), 10, 2 );
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
		delete_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT );
		delete_transient( Cloud_Print_Relay_Service::REREGISTER_TRANSIENT );
		wp_clear_scheduled_hook( Cloud_Print_Relay_Service::REREGISTER_HOOK );
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
				'relay'       => array(
					'enabled'       => $enabled,
					'site_key'      => '0123456789abcdef0123456789abcdef',
					'hint_secret'   => '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f',
					'registered_at' => time(),
				),
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
	 * It exposes the pending verification token without WCPOS authentication headers.
	 */
	public function test_relay_verification_returns_token_during_registration_window(): void {
		// Arrange.
		set_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT, 'pending-token', 300 );

		// Act.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wcpos/v1/print-jobs/relay-verification' ) );

		// Assert.
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
						'site_key'        => 'abcdef0123456789abcdef0123456789',
						'hint_secret'     => '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff',
						'printer_base_url' => 'https://cloudprint.wcpos.com/p/abcdef0123456789abcdef0123456789',
					)
				);
			}
		);

		// Act.
		$response = rest_do_request( $this->wp_rest_post_request( '/wcpos/v1/print-jobs/relay/register' ) );
		$saved    = get_option( Cloud_Print_Registry::OPTION );

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( true, $saved['relay']['enabled'] );
		$this->assertEquals( 'abcdef0123456789abcdef0123456789', $saved['relay']['site_key'] );
		$this->assertEquals( '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff', $saved['relay']['hint_secret'] );
		$this->assertGreaterThan( 0, $saved['relay']['registered_at'] );
		$this->assertFalse( get_transient( Cloud_Print_Relay_Service::VERIFY_TRANSIENT ) );
		$this->assertStringNotContainsString( 'hint_secret', wp_json_encode( $response->get_data() ) );
		$this->assertEquals( true, $response->get_data()['enabled'] );
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
	 * It preserves the server-owned relay section during general settings updates.
	 */
	public function test_client_settings_update_cannot_write_relay_section(): void {
		// Arrange.
		$this->store_relay();
		$expected = get_option( Cloud_Print_Registry::OPTION )['relay'];
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

		// Assert.
		$this->assertEquals( $expected, get_option( Cloud_Print_Registry::OPTION )['relay'] );
	}

	/**
	 * It exposes only the public relay settings fields.
	 */
	public function test_settings_response_never_contains_hint_secret_and_exposes_printer_base_url(): void {
		// Arrange.
		$this->store_relay( 'printnode' );
		$this->mock_http( function () { return new WP_Error( 'offline', 'offline' ); } );

		// Act.
		$response = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) );
		$data     = $response->get_data();

		// Assert.
		$this->assertEquals(
			array(
				'enabled'          => true,
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
		$jobs->create( array( 'printer_id' => 'front', 'payload' => base64_encode( 'one' ) ) );
		$this->store_relay( 'star-cloudprnt', false );
		$jobs->create( array( 'printer_id' => 'front', 'payload' => base64_encode( 'two' ) ) );
		$this->store_relay( 'printnode' );
		$jobs->create( array( 'printer_id' => 'front', 'payload' => base64_encode( 'three' ) ) );

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
		$this->mock_http( function () use ( &$result ) { return $result; } );

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
	 * It schedules only one guarded re-registration for an unknown relay site.
	 */
	public function test_unknown_site_status_schedules_reregistration_once(): void {
		// Arrange.
		$this->store_relay();
		$this->mock_http(
			function () {
				return $this->http_response( 404, array( 'message' => 'unknown site' ) );
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

		// Act.
		$registry->status_for( 'front' );

		// Assert.
		$this->assertFalse( wp_next_scheduled( Cloud_Print_Relay_Service::REREGISTER_HOOK ) );
	}
}
