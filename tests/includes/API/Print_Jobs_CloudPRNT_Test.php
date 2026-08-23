<?php
/**
 * Star CloudPRNT print job tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Media_Types;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Print_Jobs_CloudPRNT_Test class.
 */
class Print_Jobs_CloudPRNT_Test extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Captured log messages.
	 *
	 * @var array<int, string>
	 */
	private array $logged_messages = array();

	/**
	 * Set up a registered Star CloudPRNT printer.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->jobs            = new Print_Job_Service();
		$this->logged_messages = array();
		$this->jobs->register_post_type();
		Logger::reset_dedup_state();
		add_filter(
			'woocommerce_pos_logging',
			function ( $should_log, $message ) {
				$this->logged_messages[] = $message;

				return false;
			},
			10,
			2
		);
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'              => 'p1',
						'provider'        => 'star-cloudprnt',
						'poll_token_hash' => Cloud_Print_Registry::hash_token( 'tok' ),
					),
				),
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_pos_logging' );
		Logger::reset_dedup_state();
		parent::tearDown();
	}

	/**
	 * Poll the CloudPRNT route.
	 *
	 * @param string $method           HTTP method.
	 * @param array  $params           Query params.
	 * @param bool   $set_wcpos_header Whether to set the WCPOS header.
	 */
	private function poll( string $method, array $params, bool $set_wcpos_header = true ) {
		$request = new \WP_REST_Request( $method, '/wcpos/v1/print-jobs/cloudprnt' );
		if ( $set_wcpos_header ) {
			$request->set_header( 'X-WCPOS', '1' );
		}
		$request->set_query_params(
			array_merge(
				array(
					'printer_id' => 'p1',
					'pt'         => 'tok',
				),
				$params
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * It advertises a pending job to a token-authenticated printer.
	 */
	public function test_poll_advertises_pending_job_with_token(): void {
		$id   = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);
		$response = $this->poll( 'POST', array() );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertEquals( true, $data['jobReady'] );
		$this->assertEquals( (string) $id, $data['jobToken'] );
	}

	/**
	 * It offers the job's content type as a mediaTypes list per the CloudPRNT spec.
	 *
	 * The printer compares the offered list against its decodable set and fetches
	 * with its pick; a bare mediaType string is kept for older firmware.
	 */
	public function test_poll_offers_media_types_list(): void {
		$this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);

		$data = $this->poll( 'POST', array() )->get_data();

		$this->assertEquals( array( 'application/vnd.star.starprnt' ), $data['mediaTypes'] );
		$this->assertEquals( 'application/vnd.star.starprnt', $data['mediaType'] );
	}

	/**
	 * It serves the job when the printer's requested type matches.
	 */
	public function test_get_with_matching_type_serves_job(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);

		$response = $this->poll(
			'GET',
			array(
				'token' => $id,
				'type'  => 'application/vnd.star.starprnt',
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'claimed', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It returns 415 without claiming when the printer requests an unsupported type.
	 */
	public function test_get_with_mismatched_type_returns_415_and_leaves_job_pending(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);

		$response = $this->poll(
			'GET',
			array(
				'token' => $id,
				'type'  => 'image/png',
			)
		);

		$this->assertEquals( 415, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_incompatible_media_type', $response->as_error()->get_error_code() );
		$this->assertEquals( 'pending', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It claims on GET and marks printed on DELETE.
	 */
	public function test_get_then_delete_claims_then_marks_printed(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);

		$this->assertEquals( 200, $this->poll( 'GET', array( 'token' => $id ) )->get_status() );
		$this->assertEquals( 'claimed', $this->jobs->get( $id )['status'] );

		$this->assertEquals(
			200,
			$this->poll(
				'DELETE',
				array(
					'token' => $id,
					'code'  => '200 OK',
				)
			)->get_status()
		);
		$this->assertEquals( 'printed', $this->jobs->get( $id )['status'] );
		$this->assertCount( 0, $this->logged_messages );
	}

	/**
	 * It accepts a bare HTTP success code when completing a job.
	 */
	public function test_delete_with_bare_200_marks_printed_without_logging(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);
		$this->poll( 'GET', array( 'token' => $id ) );

		$response = $this->poll(
			'DELETE',
			array(
				'token' => $id,
				'code'  => '200',
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'printed', $this->jobs->get( $id )['status'] );
		$this->assertCount( 0, $this->logged_messages );
	}

	/**
	 * It accepts successful printer status codes when completing a job.
	 */
	public function test_delete_with_success_status_code_marks_printed_without_logging(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);
		$this->poll( 'GET', array( 'token' => $id ) );

		$response = $this->poll(
			'DELETE',
			array(
				'token' => $id,
				'code'  => '211 Paper Low',
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'printed', $this->jobs->get( $id )['status'] );
		$this->assertCount( 0, $this->logged_messages );
	}

	/**
	 * It serializes one in-flight job per printer.
	 */
	public function test_poll_serializes_one_job_per_printer(): void {
		$this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'A' ),
			)
		);
		$this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'B' ),
			)
		);

		$first_response = $this->poll( 'POST', array() );
		$this->assertEquals( 200, $first_response->get_status() );
		$first = $first_response->get_data();
		$this->poll( 'GET', array( 'token' => $first['jobToken'] ) );

		$second_response = $this->poll( 'POST', array() );
		$this->assertEquals( 200, $second_response->get_status() );
		$this->assertEquals( false, $second_response->get_data()['jobReady'] );
	}

	/**
	 * It allows anonymous hardware polling to reach token validation.
	 */
	public function test_poll_allows_anonymous_printer_token_request(): void {
		wp_set_current_user( 0 );

		$response = $this->poll( 'POST', array(), false );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( false, $response->get_data()['jobReady'] );
	}

	/**
	 * It rejects invalid printer tokens.
	 */
	public function test_poll_rejects_bad_token_with_401(): void {
		$response = $this->poll( 'POST', array( 'pt' => 'wrong' ) );

		$this->assertEquals( 401, $response->get_status() );
		$this->assertEquals(
			array( '/wcpos/v1/print-jobs/cloudprnt: authentication failed for printer "p1".' ),
			$this->logged_messages
		);
		$this->assertStringNotContainsString( 'wrong', implode( ' ', $this->logged_messages ) );
	}

	/**
	 * It records the printer's last-seen timestamp on a valid poll.
	 */
	public function test_valid_poll_records_last_seen(): void {
		$registry = new \WCPOS\WooCommercePOS\Services\Cloud_Print_Registry();
		$this->assertEquals( 0, $registry->get_seen( 'p1' ) );

		$this->poll( 'POST', array() );

		$this->assertGreaterThan( 0, $registry->get_seen( 'p1' ) );
		$this->assertCount( 0, $this->logged_messages );
	}

	/**
	 * It logs a job token that cannot be resolved for the polling printer.
	 */
	public function test_missing_job_token_logs_warning(): void {
		$response = $this->poll( 'GET', array( 'token' => 999999 ) );

		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals(
			array( '/wcpos/v1/print-jobs/cloudprnt: print job "999999" was not found for printer "p1".' ),
			$this->logged_messages
		);
	}

	/**
	 * It logs a non-zero completion code reported by the printer.
	 */
	public function test_failed_completion_logs_printer_error_code(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/octet-stream',
				'payload'      => base64_encode( 'X' ),
			)
		);
		$this->poll( 'GET', array( 'token' => $id ) );

		$response = $this->poll(
			'DELETE',
			array(
				'token' => $id,
				'code'  => '500',
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'failed', $this->jobs->get( $id )['status'] );
		$this->assertEquals(
			array( sprintf( '/wcpos/v1/print-jobs/cloudprnt: printer "p1" reported failure code "500" for print job %d.', $id ) ),
			$this->logged_messages
		);
	}

	/**
	 * It logs when a claimed job renders no printable bytes.
	 */
	public function test_empty_rendered_payload_logs_error(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/octet-stream',
				'payload'      => 'not-valid-base64',
			)
		);

		$response = $this->poll( 'GET', array( 'token' => $id ) );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals(
			array( sprintf( '/wcpos/v1/print-jobs/cloudprnt: print job %d rendered an empty payload for printer "p1".', $id ) ),
			$this->logged_messages
		);
	}

	/**
	 * Poll the path-credential CloudPRNT route (printer_id and pt in the path).
	 *
	 * Star printers URL-encode the configured query string on the wire, so
	 * these requests carry no credential query params and no WCPOS header —
	 * only the `wcpos=1` marker, which survives as the sole configured pair.
	 *
	 * @param string $method     HTTP method.
	 * @param array  $params     Extra query params (the printer's own runtime params).
	 * @param string $printer_id Printer ID path segment.
	 * @param string $token      Poll token path segment.
	 */
	private function poll_path( string $method, array $params = array(), string $printer_id = 'p1', string $token = 'tok' ) {
		$request = new \WP_REST_Request( $method, '/wcpos/v1/print-jobs/cloudprnt/' . $printer_id . '/' . $token );
		$request->set_query_params( array_merge( array( 'wcpos' => '1' ), $params ) );

		return rest_do_request( $request );
	}

	/**
	 * It authenticates and advertises a pending job from path credentials alone.
	 */
	public function test_cloudprnt_path_credentials_poll_advertises_pending_job(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);

		$response = $this->poll_path( 'POST' );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( true, $data['jobReady'] );
		$this->assertEquals( (string) $id, $data['jobToken'] );
	}

	/**
	 * It rejects an invalid token in the path with 401.
	 */
	public function test_cloudprnt_path_credentials_invalid_token_returns_401(): void {
		$response = $this->poll_path( 'POST', array(), 'p1', 'wrong-token' );

		$this->assertEquals( 401, $response->get_status() );
		$this->assertEquals( 'wcpos_print_job_invalid_token', $response->as_error()->get_error_code() );
	}

	/**
	 * It claims on GET and marks printed on DELETE via path credentials.
	 */
	public function test_cloudprnt_path_credentials_get_then_delete_marks_printed(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => 'application/vnd.star.starprnt',
				'payload'      => base64_encode( 'X' ),
			)
		);

		$this->assertEquals( 200, $this->poll_path( 'GET', array( 'token' => $id ) )->get_status() );
		$this->assertEquals( 'claimed', $this->jobs->get( $id )['status'] );

		$this->assertEquals(
			200,
			$this->poll_path(
				'DELETE',
				array(
					'token' => $id,
					'code'  => '200 OK',
				)
			)->get_status()
		);
		$this->assertEquals( 'printed', $this->jobs->get( $id )['status'] );
	}

	/**
	 * Poll the CloudPRNT route with a JSON body, as the printer does.
	 *
	 * @param array $body The poll body.
	 */
	private function poll_with_body( array $body ) {
		$request = new \WP_REST_Request( 'POST', '/wcpos/v1/print-jobs/cloudprnt' );
		$request->set_header( 'X-WCPOS', '1' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_query_params(
			array(
				'printer_id' => 'p1',
				'pt'         => 'tok',
			)
		);
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * Create a published thermal receipt template.
	 *
	 * @return int The template post ID.
	 */
	private function create_thermal_template(): int {
		$tid = wp_insert_post(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'T',
			)
		);

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<receipt paper-width="32"><text>Receipt</text><cut type="partial" /></receipt>' ),
			array( 'ID' => $tid ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $tid );
		update_post_meta( $tid, '_template_engine', 'thermal' );
		wp_set_object_terms( $tid, 'receipt', 'wcpos_template_type' );

		return (int) $tid;
	}

	/**
	 * Create a template-backed job for the registered Star printer.
	 *
	 * @param bool $auto_open_drawer Whether the job asks for a drawer kick.
	 *
	 * @return int The job ID.
	 */
	private function create_template_job( bool $auto_open_drawer = false ): int {
		$order = OrderHelper::create_order();

		return $this->jobs->create(
			array(
				'printer_id'       => 'p1',
				'content_type'     => Cloud_Print_Media_Types::STARPRNT,
				'order_id'         => $order->get_id(),
				'template_id'      => (string) $this->create_thermal_template(),
				'auto_open_drawer' => $auto_open_drawer,
				'drawer_connector' => 'pin2',
			)
		);
	}

	/**
	 * Record an Encodings answer for the registered printer.
	 *
	 * @param string $encodings The Encodings answer.
	 */
	private function record_encodings( string $encodings ): void {
		( new Cloud_Print_Registry() )->record_capabilities( 'p1', array( 'Encodings' => $encodings ) );
	}

	/**
	 * It asks a printer what it is and what it can decode.
	 */
	public function test_poll_requests_client_capabilities(): void {
		$data = $this->poll( 'POST', array() )->get_data();

		$this->assertSame(
			array(
				array( 'request' => 'ClientType' ),
				array( 'request' => 'Encodings' ),
			),
			$data['clientAction']
		);
	}

	/**
	 * It caches the answers a printer sends back.
	 */
	public function test_poll_caches_the_printers_capability_answers(): void {
		$this->poll_with_body(
			array(
				'statusCode'   => '200 OK',
				'clientAction' => array(
					array(
						'request' => 'ClientType',
						'result'  => 'Star CloudPRNT TSP100IV',
					),
					array(
						'request' => 'Encodings',
						'result'  => 'application/vnd.star.starprnt,text/plain',
					),
				),
			)
		);

		$record = ( new Cloud_Print_Registry() )->get_capabilities( 'p1' );
		$this->assertSame( 'Star CloudPRNT TSP100IV', $record['client_type'] );
		$this->assertSame( array( 'application/vnd.star.starprnt', 'text/plain' ), $record['encodings'] );
		$this->assertSame( '200 OK', $record['status_code'] );
	}

	/**
	 * It stops asking once the printer has answered, until the TTL lapses.
	 */
	public function test_poll_does_not_re_ask_within_the_ttl(): void {
		$this->poll( 'POST', array() );

		$this->assertArrayNotHasKey( 'clientAction', $this->poll( 'POST', array() )->get_data() );
	}

	/**
	 * It withholds a job while the printer reports one still printing.
	 */
	public function test_poll_withholds_a_job_while_printing_is_in_progress(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => Cloud_Print_Media_Types::STARPRNT,
				'payload'      => base64_encode( 'X' ),
			)
		);

		$data = $this->poll_with_body( array( 'printingInProgress' => true ) )->get_data();

		$this->assertFalse( $data['jobReady'] );
		$this->assertSame( 'pending', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It offers every format it can render a template-backed job in.
	 */
	public function test_poll_offers_multiple_media_types_for_a_template_job(): void {
		$this->create_template_job();

		$data = $this->poll( 'POST', array() )->get_data();

		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT, Cloud_Print_Media_Types::TEXT ),
			$data['mediaTypes']
		);
		$this->assertSame( Cloud_Print_Media_Types::STARPRNT, $data['mediaType'] );
	}

	/**
	 * It offers a Line Mode-only printer only what that printer can decode.
	 */
	public function test_poll_offers_only_decodable_types_to_a_line_mode_printer(): void {
		$this->create_template_job();
		$this->record_encodings( 'text/plain,application/vnd.star.line' );

		$data = $this->poll( 'POST', array() )->get_data();

		$this->assertSame( array( Cloud_Print_Media_Types::TEXT ), $data['mediaTypes'] );
		$this->assertSame( Cloud_Print_Media_Types::TEXT, $data['mediaType'] );
	}

	/**
	 * It renders the job in the format the printer chose.
	 */
	public function test_get_renders_the_job_in_the_requested_media_type(): void {
		$id = $this->create_template_job();

		$response = $this->poll(
			'GET',
			array(
				'token' => $id,
				'type'  => Cloud_Print_Media_Types::TEXT,
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( Cloud_Print_Media_Types::TEXT, $response->get_headers()['Content-Type'] );
		$this->assertStringContainsString( 'Receipt', $response->get_raw_body() );
		// Plain text carries no command bytes, cut included.
		$this->assertStringNotContainsString( "\x1b", $response->get_raw_body() );
	}

	/**
	 * It moves cut and drawer to headers for a command-free format.
	 */
	public function test_get_sets_star_control_headers_for_text_jobs(): void {
		$id = $this->create_template_job( true );

		$headers = $this->poll(
			'GET',
			array(
				'token' => $id,
				'type'  => Cloud_Print_Media_Types::TEXT,
			)
		)->get_headers();

		$this->assertSame( 'partial', $headers['X-Star-Cut'] );
		$this->assertSame( 'end', $headers['X-Star-CashDrawer'] );
	}

	/**
	 * It leaves control headers off a format that cuts in-band.
	 */
	public function test_get_omits_star_control_headers_for_native_jobs(): void {
		$id = $this->create_template_job( true );

		$headers = $this->poll(
			'GET',
			array(
				'token' => $id,
				'type'  => Cloud_Print_Media_Types::STARPRNT,
			)
		)->get_headers();

		$this->assertArrayNotHasKey( 'X-Star-Cut', $headers );
		$this->assertArrayNotHasKey( 'X-Star-CashDrawer', $headers );
	}

	/**
	 * It refuses a type it never offered, even one it could otherwise render.
	 */
	public function test_get_rejects_a_type_that_was_not_offered(): void {
		$id = $this->create_template_job();
		$this->record_encodings( 'application/vnd.star.starprnt' );

		$response = $this->poll(
			'GET',
			array(
				'token' => $id,
				'type'  => Cloud_Print_Media_Types::TEXT,
			)
		);

		$this->assertEquals( 415, $response->get_status() );
		$this->assertSame( 'pending', $this->jobs->get( $id )['status'] );
	}

	/**
	 * It still serves an app-uploaded payload as the bytes that were uploaded.
	 */
	public function test_uploaded_payloads_keep_their_stored_type(): void {
		$id = $this->jobs->create(
			array(
				'printer_id'   => 'p1',
				'content_type' => Cloud_Print_Media_Types::STARPRNT,
				'payload'      => base64_encode( 'RAW' ),
			)
		);

		$this->assertSame(
			array( Cloud_Print_Media_Types::STARPRNT ),
			$this->poll( 'POST', array() )->get_data()['mediaTypes']
		);

		$response = $this->poll( 'GET', array( 'token' => $id ) );
		$this->assertSame( 'RAW', $response->get_raw_body() );
	}
}
