<?php
/**
 * Tests for REST request diagnostics during plugin initialization.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use ReflectionClass;
use WCPOS\WooCommercePOS\Init;
use WCPOS\WooCommercePOS\Logger;
use WC_Unit_Test_Case;

/**
 * Init REST logging test case.
 */
class Test_Init_REST_Logging extends WC_Unit_Test_Case {

	/**
	 * Captured log messages.
	 *
	 * @var array<int, string>
	 */
	private array $logged_messages = array();

	/**
	 * Init instance without constructor side effects.
	 *
	 * @var Init
	 */
	private Init $init;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->logged_messages = array();
		$this->init            = ( new ReflectionClass( Init::class ) )->newInstanceWithoutConstructor();
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
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wp;

		$wp->query_vars = array();
		delete_transient( 'wcpos_missing_request_marker_v1' );
		delete_transient( 'wcpos_missing_request_marker_v2' );
		remove_all_filters( 'woocommerce_pos_logging' );
		Logger::reset_dedup_state();
		parent::tearDown();
	}

	/**
	 * It logs the exact v1 endpoint before an unmarked route is rejected.
	 */
	public function test_v1_request_without_marker_logs_exact_endpoint(): void {
		global $wp;

		$wp->query_vars = array( 'rest_route' => '/wcpos/v1/print-jobs/cloudprnt' );

		$this->init->init_rest_api();

		$this->assertEquals(
			array( '/wcpos/v1/print-jobs/cloudprnt: missing WCPOS request marker.' ),
			$this->logged_messages
		);
	}

	/**
	 * It also covers requests to the v2 namespace used by the next branch.
	 */
	public function test_v2_request_without_marker_logs_exact_endpoint(): void {
		global $wp;

		$wp->query_vars = array( 'rest_route' => '/wcpos/v2/orders' );

		$this->init->init_rest_api();

		$this->assertEquals(
			array( '/wcpos/v2/orders: missing WCPOS request marker.' ),
			$this->logged_messages
		);
	}

	/**
	 * It does not warn for an unrelated REST namespace.
	 */
	public function test_non_wcpos_request_does_not_log_missing_marker_warning(): void {
		global $wp;

		$wp->query_vars = array( 'rest_route' => '/wp/v2/users' );

		$this->init->init_rest_api();

		$this->assertCount( 0, $this->logged_messages );
	}

	/**
	 * It rate-limits repeated missing-marker warnings for a namespace.
	 */
	public function test_missing_marker_warning_is_rate_limited_by_namespace(): void {
		global $wp;

		$wp->query_vars = array( 'rest_route' => '/wcpos/v1/print-jobs/cloudprnt' );
		$this->init->init_rest_api();

		Logger::reset_dedup_state();
		$wp->query_vars = array( 'rest_route' => '/wcpos/v1/orders' );
		$this->init->init_rest_api();

		$this->assertCount( 1, $this->logged_messages );
	}
}
