<?php
/**
 * Star CloudPRNT print job tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Logger;
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
}
