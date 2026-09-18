<?php
/**
 * Poll lifecycle ordering and state transitions.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WCPOS\WooCommercePOS\Services\Print_Job_Lifecycle;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;
use WCPOS\WooCommercePOS\Services\Providers\Epson_Sdp_Adapter;

/**
 * A fake wire adapter; the job store and its state changes remain real.
 */
class Lifecycle_Poll_Adapter extends Epson_Sdp_Adapter {
	public $events = array();
	public $reject = false;

	public function negotiate( array $printer, array $poll, array $job ): array {
		$this->events[] = 'negotiate';
		return $this->reject ? array(
			'response' => array(
				'status'  => 415,
				'headers' => array(),
				'body'    => 'rejected',
			),
		) : array( 'media_type' => '' );
	}

	public function deliver( array $job, array $render, array $poll ): array {
		$this->events[] = 'deliver';
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => $render['body'],
		);
	}

	public function match_result( array $poll ): array {
		$this->events[] = 'match_result';
		return array( 'job_id' => null );
	}

	public function interpret_result( array $poll ): array {
		$this->events[] = 'interpret_result';
		return array(
			'ok'     => $poll['ok'],
			'code'   => 'TEST',
			'detail' => ' (test failure)',
		);
	}
}

/**
 * The lifecycle must order store operations, not just produce a response.
 */
class Test_Print_Job_Lifecycle extends \WP_UnitTestCase {
	private $jobs;
	private $adapter;
	private $lifecycle;

	public function setUp(): void {
		parent::setUp();
		$this->jobs = new Print_Job_Service();
		$this->jobs->register_post_type();
		$this->adapter = new Lifecycle_Poll_Adapter();
		$methods = array( 'release_stale_claims', 'find_active_claim', 'find_unconfirmed', 'next_pending', 'try_claim', 'render_job', 'record_printer_result' );
		$store = $this->getMockBuilder( Print_Job_Service::class )->onlyMethods( $methods )->getMock();
		foreach ( $methods as $method ) {
			$store->method( $method )->willReturnCallback(
				function ( ...$args ) use ( $method ) {
					$this->adapter->events[] = $method;
					return $this->jobs->$method( ...$args );
				}
			);
		}
		$registry = $this->getMockBuilder( Cloud_Print_Registry::class )->onlyMethods( array( 'record_seen', 'should_request_capabilities', 'record_capability_request', 'get_capabilities' ) )->getMock();
		$registry->method( 'record_seen' )->willReturnCallback(
			function ( $id ) {
				$this->adapter->events[] = 'record_seen';
				( new Cloud_Print_Registry() )->record_seen( $id );
			}
		);
		$registry->expects( $this->never() )->method( 'get_capabilities' );
		$registry->method( 'should_request_capabilities' )->willReturnCallback(
			function () {
				$this->adapter->events[] = 'should_request_capabilities';
				return true;
			}
		);
		$registry->method( 'record_capability_request' )->willReturnCallback(
			function () {
				$this->adapter->events[] = 'record_capability_request';
			}
		);
		$this->lifecycle = new Print_Job_Lifecycle( $store, $registry, $this->adapter );
	}

	private function job(): int {
		return $this->jobs->create( array(
			'printer_id' => 'p1',
			'payload'    => base64_encode( 'receipt' ),
		) );
	}

	public function test_poll_offer_releases_stale_claim_before_delivering_next_job(): void {
		// Arrange: without release-before-offer the old claim still occupies the queue.
		$old = $this->job();
		$this->jobs->try_claim( $old );
		update_post_meta( $old, Print_Job_Service::META_CLAIMED_AT, time() - Print_Job_Service::CLAIM_TTL - 1 );
		$next = $this->job();

		// Act.
		$response = $this->lifecycle->handle_poll( 'epson-sdp', array( 'id' => 'p1' ), array(
			'phase' => 'fetch',
			'route' => '/wcpos/v2/print-jobs/epson-sdp',
		) );

		// Assert: real state and the ordering at the lifecycle boundary.
		$this->assertSame( 'receipt', $response['body'] );
		$this->assertSame( Print_Job_Service::STATUS_FAILED, $this->jobs->get( $old )['status'] );
		$this->assertSame( Print_Job_Service::STATUS_CLAIMED, $this->jobs->get( $next )['status'] );
		$this->assertSame(
			array( 'record_seen', 'release_stale_claims', 'find_active_claim', 'next_pending', 'negotiate', 'try_claim', 'render_job', 'deliver' ),
			$this->adapter->events
		);
	}

	public function test_poll_advertise_checks_capabilities_before_recording_request(): void {
		// Arrange.
		$id = $this->job();
		$poll = array(
			'phase'       => 'advertise',
			'token'       => 0,
			'route'       => '/wcpos/v2/print-jobs/cloudprnt',
			'answers'     => array(),
			'status_code' => '',
		);

		// Act.
		$this->lifecycle->handle_poll( 'star-cloudprnt', array( 'id' => 'p1' ), $poll );

		// Assert.
		$this->assertSame( Print_Job_Service::STATUS_PENDING, $this->jobs->get( $id )['status'] );
		$this->assertSame(
			array( 'record_seen', 'release_stale_claims', 'find_active_claim', 'next_pending', 'should_request_capabilities', 'record_capability_request' ),
			$this->adapter->events
		);
	}

	public function test_poll_status_notification_leaves_job_pending_without_capability_reads(): void {
		// Arrange: the registry mock rejects any get_capabilities call.
		$id = $this->job();
		$poll = $this->adapter->parse(
			array(
				'params' => array( 'ConnectionType' => 'SetStatus' ),
				'body'   => '',
				'route'  => '/wcpos/v2/print-jobs/epson-sdp',
			)
		);

		// Act.
		$response = $this->lifecycle->handle_poll( 'epson-sdp', array( 'id' => 'p1' ), $poll );

		// Assert.
		$this->assertSame( 'advertise', $poll['phase'] );
		$this->assertSame( '<response success="true" code="" status=""/>', $response['body'] );
		$this->assertSame( Print_Job_Service::STATUS_PENDING, $this->jobs->get( $id )['status'] );
		$this->assertSame( array( 'record_seen', 'release_stale_claims' ), $this->adapter->events );
	}

	public function test_poll_negotiate_rejection_leaves_job_unclaimed(): void {
		// Arrange.
		$id = $this->job();
		$this->adapter->reject = true;

		// Act.
		$response = $this->lifecycle->handle_poll( 'epson-sdp', array( 'id' => 'p1' ), array(
			'phase' => 'fetch',
			'route' => '/wcpos/v2/print-jobs/epson-sdp',
		) );

		// Assert.
		$this->assertSame( 415, $response['status'] );
		$this->assertSame( Print_Job_Service::STATUS_PENDING, $this->jobs->get( $id )['status'] );
		$this->assertSame(
			array( 'record_seen', 'release_stale_claims', 'find_active_claim', 'next_pending', 'negotiate' ),
			$this->adapter->events
		);
	}

	public function test_poll_result_records_failure_before_storing_printer_reason(): void {
		// Arrange: record_printer_result clears META_ERROR, so logging must follow it.
		$id = $this->job();
		$this->jobs->try_claim( $id );

		// Act.
		$this->lifecycle->handle_poll( 'epson-sdp', array( 'id' => 'p1' ), array(
			'phase' => 'result',
			'ok'    => false,
			'route' => '/wcpos/v2/print-jobs/epson-sdp',
		) );

		// Assert.
		$this->assertSame( Print_Job_Service::STATUS_FAILED, $this->jobs->get( $id )['status'] );
		$this->assertSame( 'TEST (test failure)', $this->jobs->get( $id )['error'] );
		$this->assertSame(
			array( 'record_seen', 'release_stale_claims', 'match_result', 'find_active_claim', 'find_unconfirmed', 'interpret_result', 'record_printer_result' ),
			$this->adapter->events
		);
	}

	public function test_poll_late_result_matches_unconfirmed_job(): void {
		// Arrange: the result arrives after the claim expires.
		$id = $this->job();
		$this->jobs->try_claim( $id );
		update_post_meta( $id, Print_Job_Service::META_CLAIMED_AT, time() - Print_Job_Service::CLAIM_TTL - 1 );

		// Act.
		$response = $this->lifecycle->handle_poll( 'epson-sdp', array( 'id' => 'p1' ), array(
			'phase' => 'result',
			'ok'    => true,
			'route' => '/wcpos/v2/print-jobs/epson-sdp',
		) );

		// Assert.
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( Print_Job_Service::STATUS_PRINTED, $this->jobs->get( $id )['status'] );
		$this->assertSame( '', $this->jobs->get( $id )['error'] );
		$this->assertSame( '', get_post_meta( $id, Print_Job_Service::META_UNCONFIRMED, true ) );
		$this->assertSame(
			array( 'record_seen', 'release_stale_claims', 'match_result', 'find_active_claim', 'find_unconfirmed', 'interpret_result', 'record_printer_result' ),
			$this->adapter->events
		);
	}
}
