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
		delete_transient( 'wcpos_cloud_print_star_status_' . md5( 'star' ) );
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
	/**
	 * It reports Star Online live device status.
	 */
	public function test_status_for_star_online_online_returns_online(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'                 => 'star',
						'provider'           => 'star-online',
						'star_api_key'       => 'KEY',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
						'star_device_id'     => 'abc',
					),
				),
			)
		);
		$this->mock_http( $this->fake_response( array( 'Status' => array( 'Online' => true ) ) ) );

		$this->assertEquals( 'online', ( new Cloud_Print_Registry() )->status_for( 'star' ) );
	}

	/**
	 * It reports malformed Star Online status as unknown.
	 */
	public function test_status_for_star_online_malformed_returns_unknown(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'                 => 'star',
						'provider'           => 'star-online',
						'star_api_key'       => 'KEY',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
						'star_device_id'     => 'abc',
					),
				),
			)
		);
		$this->mock_http( $this->fake_response( array( 'Nope' => true ) ) );

		$this->assertEquals( 'unknown', ( new Cloud_Print_Registry() )->status_for( 'star' ) );
	}

	/**
	 * It stores the answers a printer gave to our capability questions.
	 */
	public function test_record_capabilities_stores_the_printers_answers(): void {
		$registry = new Cloud_Print_Registry();

		$this->assertTrue(
			$registry->record_capabilities(
				'p1',
				array(
					'ClientType'    => 'Star CloudPRNT',
					'ClientVersion' => '4.0',
					'Encodings'     => 'application/vnd.star.starprnt, text/plain',
				),
				'200 OK'
			)
		);

		$record = $registry->get_capabilities( 'p1' );
		$this->assertSame( 'Star CloudPRNT', $record['client_type'] );
		$this->assertSame( '4.0', $record['client_version'] );
		$this->assertSame( array( 'application/vnd.star.starprnt', 'text/plain' ), $record['encodings'] );
		$this->assertSame( '200 OK', $record['status_code'] );
		$this->assertGreaterThan( 0, $record['updated'] );
	}

	/**
	 * It drops MIME parameters and duplicates from an Encodings answer.
	 */
	public function test_record_capabilities_normalizes_the_encodings_answer(): void {
		$registry = new Cloud_Print_Registry();
		$registry->record_capabilities( 'p1', array( 'Encodings' => 'TEXT/PLAIN; charset=utf-8;image/png;text/plain' ) );

		$this->assertSame(
			array( 'text/plain', 'image/png' ),
			$registry->get_capabilities( 'p1' )['encodings']
		);
	}

	/**
	 * It keeps an encodings list a later poll did not re-answer.
	 */
	public function test_record_capabilities_does_not_wipe_unanswered_fields(): void {
		$registry = new Cloud_Print_Registry();
		$registry->record_capabilities( 'p1', array( 'Encodings' => 'text/plain' ) );
		$registry->record_capabilities( 'p1', array( 'ClientType' => 'TSP100IV' ) );

		$record = $registry->get_capabilities( 'p1' );
		$this->assertSame( array( 'text/plain' ), $record['encodings'] );
		$this->assertSame( 'TSP100IV', $record['client_type'] );
	}

	/**
	 * It reports nothing stored for a printer that never answered.
	 */
	public function test_get_capabilities_defaults_for_an_unknown_printer(): void {
		$record = ( new Cloud_Print_Registry() )->get_capabilities( 'never-seen' );

		$this->assertSame( '', $record['client_type'] );
		$this->assertSame( array(), $record['encodings'] );
		$this->assertSame( 0, $record['updated'] );
		$this->assertSame( 0, $record['asked'] );
	}

	/**
	 * It writes nothing when a poll carried no answers and no status change.
	 */
	public function test_record_capabilities_is_a_no_op_without_answers(): void {
		$registry = new Cloud_Print_Registry();

		$this->assertFalse( $registry->record_capabilities( 'p1', array() ) );
		$this->assertSame( 0, $registry->get_capabilities( 'p1' )['updated'] );
	}

	/**
	 * It asks a printer for its capabilities once per TTL, not on every poll.
	 */
	public function test_capability_requests_are_rate_limited_by_the_ttl(): void {
		$registry = new Cloud_Print_Registry();

		$this->assertTrue( $registry->should_request_capabilities( 'p1' ) );
		$registry->record_capability_request( 'p1' );
		$this->assertFalse( $registry->should_request_capabilities( 'p1' ) );

		// Age the record past the TTL.
		$all                  = get_option( Cloud_Print_Registry::CAPABILITIES_OPTION );
		$all['p1']['asked']   = time() - Cloud_Print_Registry::CAPABILITIES_TTL - 1;
		update_option( Cloud_Print_Registry::CAPABILITIES_OPTION, $all, false );

		$this->assertTrue( $registry->should_request_capabilities( 'p1' ) );
	}

	/**
	 * It leaves another printer's record alone when writing its own.
	 */
	public function test_record_capabilities_does_not_clobber_another_printer(): void {
		$registry = new Cloud_Print_Registry();
		$registry->record_capabilities( 'front', array( 'Encodings' => 'application/vnd.star.starprnt' ) );
		$registry->record_capabilities( 'kitchen', array( 'Encodings' => 'text/plain' ) );

		$this->assertSame( array( 'application/vnd.star.starprnt' ), $registry->get_capabilities( 'front' )['encodings'] );
		$this->assertSame( array( 'text/plain' ), $registry->get_capabilities( 'kitchen' )['encodings'] );
	}

	/**
	 * It reads the record inside the lock, so an overlapping poll for the SAME
	 * printer cannot write back a snapshot taken before the other poll's write.
	 */
	public function test_record_capabilities_reads_inside_the_lock(): void {
		$registry = new Cloud_Print_Registry();

		// Poll A and poll B both begin here, seeing an empty record.
		$this->assertSame( array(), $registry->get_capabilities( 'front' )['encodings'] );

		// Poll A lands its answers first.
		$registry->record_capabilities( 'front', array( 'Encodings' => 'text/plain' ) );

		// Poll B now stamps `asked`. Its record must be re-read from the store,
		// not rebuilt from the empty snapshot it saw on arrival.
		$registry->record_capability_request( 'front' );

		$record = $registry->get_capabilities( 'front' );
		$this->assertSame( array( 'text/plain' ), $record['encodings'] );
		$this->assertGreaterThan( 0, $record['asked'] );
	}

	/**
	 * It skips the write rather than clobbering while another poll holds the lock.
	 */
	public function test_record_capabilities_skips_the_write_while_locked(): void {
		$registry = new Cloud_Print_Registry();
		$registry->record_capabilities( 'front', array( 'Encodings' => 'text/plain' ) );

		add_option( Cloud_Print_Registry::CAPABILITIES_LOCK, (string) time(), '', false );

		$this->assertFalse( $registry->record_capabilities( 'front', array( 'ClientType' => 'TSP100IV' ) ) );
		$this->assertSame( '', $registry->get_capabilities( 'front' )['client_type'] );
		// The record that was already there survives the contended write.
		$this->assertSame( array( 'text/plain' ), $registry->get_capabilities( 'front' )['encodings'] );

		delete_option( Cloud_Print_Registry::CAPABILITIES_LOCK );
	}

	/**
	 * It breaks a lock abandoned by a request that died holding it.
	 */
	public function test_capabilities_lock_expires(): void {
		$registry = new Cloud_Print_Registry();
		add_option(
			Cloud_Print_Registry::CAPABILITIES_LOCK,
			(string) ( time() - Cloud_Print_Registry::CAPABILITIES_LOCK_TTL - 1 ),
			'',
			false
		);

		$this->assertTrue( $registry->record_capabilities( 'front', array( 'ClientType' => 'TSP100IV' ) ) );
		$this->assertSame( 'TSP100IV', $registry->get_capabilities( 'front' )['client_type'] );
	}

	/**
	 * It drops cached capabilities for printers that were removed.
	 */
	public function test_prune_capabilities_drops_unlisted_ids(): void {
		$registry = new Cloud_Print_Registry();
		$registry->record_capabilities( 'kitchen', array( 'ClientType' => 'A' ) );
		$registry->record_capabilities( 'gone', array( 'ClientType' => 'B' ) );

		$registry->prune_capabilities( array( 'kitchen' ) );

		$this->assertSame( 'A', $registry->get_capabilities( 'kitchen' )['client_type'] );
		$this->assertSame( '', $registry->get_capabilities( 'gone' )['client_type'] );
	}
}
