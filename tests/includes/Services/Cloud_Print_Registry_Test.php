<?php
/**
 * Cloud print registry tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WP_UnitTestCase;

/**
 * Cloud_Print_Registry_Test class.
 */
class Cloud_Print_Registry_Test extends WP_UnitTestCase {
	/**
	 * Active pre_http_request callback, stored so it can be removed in tearDown.
	 *
	 * @var callable|null
	 */
	private $http_filter = null;

	/**
	 * Remove any active HTTP filter and clean transients.
	 */
	public function tearDown(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
		delete_transient( 'wcpos_cloud_print_pn_status_' . md5( 'bar' ) );
		parent::tearDown();
	}

	/**
	 * Register a pre_http_request filter that returns a faux response.
	 *
	 * @param mixed $response Faux response array or WP_Error to return.
	 */
	private function mock_http( $response ): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
		}
		$this->http_filter = static function () use ( $response ) {
			return $response;
		};

		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * Build a faux 2xx response array.
	 *
	 * @param mixed $payload Payload to JSON-encode as the body.
	 *
	 * @return array
	 */
	private function fake_response( $payload ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $payload ),
			'headers'  => array(),
		);
	}

	/**
	 * Seed a single PrintNode printer in the cloud-print option.
	 *
	 * @param string $api_key API key (empty to leave unconfigured).
	 */
	private function seed_printnode_printer( string $api_key = 'KEY' ): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'                   => 'bar',
						'name'                 => 'Bar',
						'provider'             => 'printnode',
						'printnode_api_key'    => $api_key,
						'printnode_printer_id' => 9,
					),
				),
			)
		);
	}

	/**
	 * It returns printers by id and validates poll tokens.
	 */
	public function test_get_printer_matches_id_and_validates_token(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'              => 'p1',
						'provider'        => 'star-cloudprnt',
						'poll_token_hash' => Cloud_Print_Registry::hash_token( 'secret-1' ),
					),
				),
			)
		);

		$registry = new Cloud_Print_Registry();

		$this->assertEquals( 'star-cloudprnt', $registry->get_printer( 'p1' )['provider'] );
		$this->assertEquals( true, $registry->verify_token( 'p1', 'secret-1' ) );
		$this->assertEquals( false, $registry->verify_token( 'p1', 'wrong' ) );
		$this->assertEquals( null, $registry->get_printer( 'missing' ) );
	}

	/**
	 * It slugifies a printer name into a stable id and dedupes against existing ids.
	 */
	public function test_derive_id_slugifies_name_and_dedupes(): void {
		$this->assertEquals( 'kitchen-printer', Cloud_Print_Registry::derive_id( 'Kitchen Printer', array() ) );
		$this->assertEquals( 'kitchen-printer-2', Cloud_Print_Registry::derive_id( 'Kitchen Printer!', array( 'kitchen-printer' ) ) );
		$this->assertEquals( 'kitchen-printer-3', Cloud_Print_Registry::derive_id( 'Kitchen Printer', array( 'kitchen-printer', 'kitchen-printer-2' ) ) );
		$this->assertEquals( 'printer', Cloud_Print_Registry::derive_id( '', array() ) );
	}

	/**
	 * It records and reads back a printer's last-seen timestamp.
	 */
	public function test_record_and_get_seen_roundtrip(): void {
		$registry = new Cloud_Print_Registry();
		$this->assertEquals( 0, $registry->get_seen( 'kitchen' ) );
		$registry->record_seen( 'kitchen' );
		$this->assertGreaterThan( 0, $registry->get_seen( 'kitchen' ) );
	}

	/**
	 * It reports a printer that has never polled as waiting.
	 */
	public function test_status_waiting_when_never_seen(): void {
		$registry = new Cloud_Print_Registry();
		$this->assertEquals( 'waiting', $registry->status_for( 'kitchen' ) );
	}

	/**
	 * It reports a printer polled within the TTL as connected.
	 */
	public function test_status_connected_when_recently_seen(): void {
		$registry = new Cloud_Print_Registry();
		$registry->record_seen( 'kitchen' );
		$this->assertEquals( 'connected', $registry->status_for( 'kitchen' ) );
	}

	/**
	 * It drops runtime last-seen entries for ids no longer present.
	 */
	public function test_prune_seen_drops_unlisted_ids(): void {
		$registry = new Cloud_Print_Registry();
		$registry->record_seen( 'kitchen' );
		$registry->record_seen( 'bar' );

		$registry->prune_seen( array( 'kitchen' ) );

		$this->assertGreaterThan( 0, $registry->get_seen( 'kitchen' ) );
		$this->assertEquals( 0, $registry->get_seen( 'bar' ) );
	}

	/**
	 * It reports an online PrintNode printer via the live API.
	 */
	public function test_status_for_printnode_online_returns_online(): void {
		// Arrange.
		$this->seed_printnode_printer();
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'id' => 9,
						'state' => 'online',
					),
				)
			)
		);

		// Act + Assert.
		$this->assertEquals( 'online', ( new Cloud_Print_Registry() )->status_for( 'bar' ) );
	}

	/**
	 * It reports an offline PrintNode printer via the live API.
	 */
	public function test_status_for_printnode_offline_returns_offline(): void {
		// Arrange.
		$this->seed_printnode_printer();
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'id' => 9,
						'state' => 'offline',
					),
				)
			)
		);

		// Act + Assert.
		$this->assertEquals( 'offline', ( new Cloud_Print_Registry() )->status_for( 'bar' ) );
	}

	/**
	 * It caches the PrintNode status within the TTL and does not hit HTTP again.
	 */
	public function test_status_for_printnode_caches_within_ttl(): void {
		// Arrange: first call resolves online and primes the cache.
		$this->seed_printnode_printer();
		$registry = new Cloud_Print_Registry();
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'id' => 9,
						'state' => 'online',
					),
				)
			)
		);
		$this->assertEquals( 'online', $registry->status_for( 'bar' ) );

		// Act: swap the mock to error; a cached read must not consult it.
		$this->mock_http( new \WP_Error( 'http_request_failed', 'should not be called' ) );

		// Assert.
		$this->assertEquals( 'online', $registry->status_for( 'bar' ) );
	}

	/**
	 * It returns unknown for an unconfigured PrintNode printer without any HTTP call.
	 */
	public function test_status_for_printnode_unconfigured_returns_unknown_no_http(): void {
		// Arrange.
		$this->seed_printnode_printer( '' );
		$this->mock_http( new \WP_Error( 'http_request_failed', 'should not be called' ) );

		// Act + Assert.
		$this->assertEquals( 'unknown', ( new Cloud_Print_Registry() )->status_for( 'bar' ) );
	}

	/**
	 * It leaves polling-printer status unchanged (waiting when never seen).
	 */
	public function test_status_for_polling_printer_unchanged(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'       => 'kitchen',
						'provider' => 'star-cloudprnt',
					),
				),
			)
		);

		// Act + Assert.
		$this->assertEquals( 'waiting', ( new Cloud_Print_Registry() )->status_for( 'kitchen' ) );
	}
}
