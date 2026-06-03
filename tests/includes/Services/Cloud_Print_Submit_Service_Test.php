<?php
/**
 * Cloud print submit service tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Submit_Service;
use WCPOS\WooCommercePOS\Services\Cloud_Print_Trigger_Service;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Cloud_Print_Submit_Service_Test class.
 */
class Cloud_Print_Submit_Service_Test extends \WC_REST_Unit_Test_Case {
	/**
	 * Job store.
	 *
	 * @var Print_Job_Service
	 */
	private $jobs;

	/**
	 * Captured PrintNode request body (decoded JSON) from the mocked HTTP call.
	 *
	 * @var array|null
	 */
	private $captured_body;

	/**
	 * Whether the mocked HTTP transport was hit.
	 *
	 * @var bool
	 */
	private $http_called;

	/**
	 * Set up service and CPT.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->jobs          = new Print_Job_Service();
		$this->jobs->register_post_type();
		$this->captured_body = null;
		$this->http_called   = false;
	}

	/**
	 * Tear down — clear option and HTTP filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'woocommerce_pos_settings_cloud_print' );

		// Clear any submit locks and scheduled retry events seeded by tests.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wcpos_pn_submit_lock_%'" );
		$crons = _get_cron_array();
		if ( \is_array( $crons ) ) {
			foreach ( $crons as $hooks ) {
				if ( isset( $hooks[ Cloud_Print_Trigger_Service::CRON_SUBMIT ] ) ) {
					foreach ( $hooks[ Cloud_Print_Trigger_Service::CRON_SUBMIT ] as $event ) {
						wp_clear_scheduled_hook( Cloud_Print_Trigger_Service::CRON_SUBMIT, $event['args'] );
					}
				}
			}
		}

		parent::tearDown();
	}

	/**
	 * Create a thermal template post with raw markup, bypassing wp_kses.
	 *
	 * @param string $engine Template engine slug.
	 *
	 * @return int Template post ID.
	 */
	private function create_thermal_template( string $engine = 'thermal' ): int {
		$tid = wp_insert_post(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'T',
				'post_content' => '',
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $tid, 'wp_insert_post() returned a WP_Error creating the thermal template.' );
		$this->assertIsInt( $tid );
		$this->assertGreaterThan( 0, $tid, 'wp_insert_post() failed to create the thermal template post.' );

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<receipt paper-width="48"><text>Order #{{order.number}}</text><cut /></receipt>' ),
			array( 'ID' => $tid ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertNotFalse( $updated, 'Failed to write raw template content via $wpdb->update().' );
		clean_post_cache( $tid );

		update_post_meta( $tid, '_template_engine', $engine );
		wp_set_object_terms( $tid, 'receipt', 'wcpos_template_type' );

		return (int) $tid;
	}

	/**
	 * Seed a single PrintNode printer into the cloud-print settings option.
	 *
	 * @param array $overrides Printer field overrides.
	 */
	private function seed_printnode_printer( array $overrides = array() ): void {
		$printer = array_merge(
			array(
				'id'                   => 'pn',
				'name'                 => 'PrintNode',
				'provider'             => 'printnode',
				'printnode_api_key'    => 'secret-key',
				'printnode_printer_id' => 42,
			),
			$overrides
		);
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array( $printer ),
				'assignments' => array(),
			)
		);
	}

	/**
	 * Install a pre_http_request mock for the PrintNode /printjobs endpoint.
	 *
	 * @param mixed $return Value returned for matching requests (response array or WP_Error).
	 */
	private function mock_printnode( $return ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $return ) {
				if ( false === strpos( (string) $url, '/printjobs' ) ) {
					return $pre;
				}
				$this->http_called   = true;
				$this->captured_body = json_decode( (string) ( $args['body'] ?? '' ), true );

				if ( is_wp_error( $return ) ) {
					return $return;
				}

				return array(
					'headers'  => array(),
					'body'     => (string) $return,
					'response' => array(
						'code'    => 201,
						'message' => 'Created',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * It submits a PDF job to PrintNode and records the returned job id + PRINTED status.
	 */
	public function test_submit_pdf_job_calls_printnode_and_marks_printed(): void {
		// Arrange.
		$this->seed_printnode_printer();
		$this->mock_printnode( '987' );
		$tid    = $this->create_thermal_template();
		$order  = OrderHelper::create_order();
		$job_id = $this->jobs->create(
			array(
				'printer_id'   => 'pn',
				'order_id'     => $order->get_id(),
				'template_id'  => (string) $tid,
				'content_type' => 'application/pdf',
				'pn_kind'      => 'pdf',
			)
		);

		// Act.
		( new Cloud_Print_Submit_Service() )->submit( $job_id );

		// Assert.
		$this->assertTrue( $this->http_called );
		$this->assertEquals( 'pdf_base64', $this->captured_body['contentType'] );
		$job = $this->jobs->get( $job_id );
		$this->assertEquals( '987', $job['external_job_id'] );
		$this->assertEquals( Print_Job_Service::STATUS_PRINTED, $job['status'] );
	}

	/**
	 * It submits a raw ESC/POS job to PrintNode as raw_base64.
	 */
	public function test_submit_escpos_job_uses_raw_base64_content_type(): void {
		// Arrange.
		$this->seed_printnode_printer( array( 'printnode_format' => 'raw' ) );
		$this->mock_printnode( '5' );
		$tid    = $this->create_thermal_template();
		$order  = OrderHelper::create_order();
		$job_id = $this->jobs->create(
			array(
				'printer_id'   => 'pn',
				'order_id'     => $order->get_id(),
				'template_id'  => (string) $tid,
				'content_type' => 'application/octet-stream',
				'pn_kind'      => 'escpos',
			)
		);

		// Act.
		( new Cloud_Print_Submit_Service() )->submit( $job_id );

		// Assert.
		$this->assertTrue( $this->http_called );
		$this->assertEquals( 'raw_base64', $this->captured_body['contentType'] );
		$this->assertEquals( Print_Job_Service::STATUS_PRINTED, $this->jobs->get( $job_id )['status'] );
	}

	/**
	 * It reschedules a retry (PENDING) on the first transient PrintNode error.
	 */
	public function test_submit_reschedules_retry_on_first_printnode_error(): void {
		// Arrange.
		$this->seed_printnode_printer();
		$this->mock_printnode( new \WP_Error( 'boom', 'PrintNode unavailable' ) );
		$tid    = $this->create_thermal_template();
		$order  = OrderHelper::create_order();
		$job_id = $this->jobs->create(
			array(
				'printer_id'   => 'pn',
				'order_id'     => $order->get_id(),
				'template_id'  => (string) $tid,
				'content_type' => 'application/pdf',
				'pn_kind'      => 'pdf',
			)
		);

		// Act.
		( new Cloud_Print_Submit_Service() )->submit( $job_id );

		// Assert.
		$job = $this->jobs->get( $job_id );
		$this->assertEquals( Print_Job_Service::STATUS_PENDING, $job['status'] );
		$this->assertEquals( '', $job['external_job_id'] );
		$this->assertEquals( 1, (int) get_post_meta( $job_id, Print_Job_Service::META_SUBMIT_ATTEMPTS, true ) );
		$this->assertNotEquals( '', (string) get_post_meta( $job_id, Print_Job_Service::META_ERROR, true ) );
		$this->assertNotFalse( wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $job_id ) ) );
	}

	/**
	 * It marks the job FAILED once MAX_ATTEMPTS is reached, with no further retry.
	 */
	public function test_submit_marks_failed_after_max_attempts(): void {
		// Arrange.
		$this->seed_printnode_printer();
		$this->mock_printnode( new \WP_Error( 'boom', 'PrintNode unavailable' ) );
		$tid    = $this->create_thermal_template();
		$order  = OrderHelper::create_order();
		$job_id = $this->jobs->create(
			array(
				'printer_id'   => 'pn',
				'order_id'     => $order->get_id(),
				'template_id'  => (string) $tid,
				'content_type' => 'application/pdf',
				'pn_kind'      => 'pdf',
			)
		);
		// Simulate having already exhausted all but the final attempt.
		update_post_meta( $job_id, Print_Job_Service::META_SUBMIT_ATTEMPTS, Cloud_Print_Submit_Service::MAX_ATTEMPTS - 1 );

		// Act.
		( new Cloud_Print_Submit_Service() )->submit( $job_id );

		// Assert.
		$job = $this->jobs->get( $job_id );
		$this->assertEquals( Print_Job_Service::STATUS_FAILED, $job['status'] );
		$this->assertEquals( Cloud_Print_Submit_Service::MAX_ATTEMPTS, (int) get_post_meta( $job_id, Print_Job_Service::META_SUBMIT_ATTEMPTS, true ) );
		$this->assertFalse( wp_next_scheduled( Cloud_Print_Trigger_Service::CRON_SUBMIT, array( $job_id ) ) );
	}

	/**
	 * It does not submit when another worker holds the per-job submit lock.
	 */
	public function test_submit_skips_when_lock_held(): void {
		// Arrange.
		$this->seed_printnode_printer();
		$this->mock_printnode( '777' );
		$tid    = $this->create_thermal_template();
		$order  = OrderHelper::create_order();
		$job_id = $this->jobs->create(
			array(
				'printer_id'   => 'pn',
				'order_id'     => $order->get_id(),
				'template_id'  => (string) $tid,
				'content_type' => 'application/pdf',
				'pn_kind'      => 'pdf',
			)
		);
		// Simulate a concurrent worker holding the lock.
		add_option( 'wcpos_pn_submit_lock_' . $job_id, (string) time(), '', false );

		// Act.
		( new Cloud_Print_Submit_Service() )->submit( $job_id );

		// Assert.
		$this->assertFalse( $this->http_called );
		$this->assertEquals( Print_Job_Service::STATUS_PENDING, $this->jobs->get( $job_id )['status'] );
	}

	/**
	 * It marks the job FAILED without any HTTP call when the api key is missing.
	 */
	public function test_submit_missing_api_key_fails_without_http(): void {
		// Arrange.
		$this->seed_printnode_printer( array( 'printnode_api_key' => '' ) );
		$this->mock_printnode( '1' );
		$tid    = $this->create_thermal_template();
		$order  = OrderHelper::create_order();
		$job_id = $this->jobs->create(
			array(
				'printer_id'   => 'pn',
				'order_id'     => $order->get_id(),
				'template_id'  => (string) $tid,
				'content_type' => 'application/pdf',
				'pn_kind'      => 'pdf',
			)
		);

		// Act.
		( new Cloud_Print_Submit_Service() )->submit( $job_id );

		// Assert.
		$this->assertFalse( $this->http_called );
		$this->assertEquals( Print_Job_Service::STATUS_FAILED, $this->jobs->get( $job_id )['status'] );
	}

	/**
	 * It is idempotent — a job already carrying an external_job_id is not resubmitted.
	 */
	public function test_submit_is_idempotent_for_already_submitted_job(): void {
		// Arrange.
		$this->seed_printnode_printer();
		$this->mock_printnode( '111' );
		$tid    = $this->create_thermal_template();
		$order  = OrderHelper::create_order();
		$job_id = $this->jobs->create(
			array(
				'printer_id'   => 'pn',
				'order_id'     => $order->get_id(),
				'template_id'  => (string) $tid,
				'content_type' => 'application/pdf',
				'pn_kind'      => 'pdf',
			)
		);
		$this->jobs->record_external_submission( $job_id, 'printnode', '555', 'submitted' );

		// Act.
		( new Cloud_Print_Submit_Service() )->submit( $job_id );

		// Assert.
		$this->assertFalse( $this->http_called );
		$this->assertEquals( '555', $this->jobs->get( $job_id )['external_job_id'] );
	}
}
