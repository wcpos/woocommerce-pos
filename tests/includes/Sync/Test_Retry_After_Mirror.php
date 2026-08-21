<?php
/**
 * Retry-After response mirror tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Health;
use WCPOS\WooCommercePOS\Sync\Retry_After_Mirror;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Retry-After body mirror contract tests.
 *
 * @group sync
 */
class Test_Retry_After_Mirror extends WCPOS_REST_Unit_Test_Case {
	/** Literal route required by the REST lane coverage gate. */
	private const CURRENT_LANE_ROUTE = '/wcpos/v2/products';

	/**
	 * Drop sync tables before routes are registered (Test_Sync_Status pattern):
	 * setUp commits state BEFORE the test transaction starts, so a mid-test
	 * DROP would be masked. Only the Health-503 test dispatches a gated route;
	 * the parser/filter tests never touch the sync store.
	 */
	public function setUp(): void {
		global $wpdb;
		foreach ( Health::required_tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known internal table names.
		}
		delete_option( Api::SCHEMA_OPTION );

		parent::setUp();
	}

	/**
	 * Restore sync tables after the rollback for later test classes.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::SCHEMA_OPTION );
		( new Activator() )->install_sync_schema();
		delete_option( Api::SCHEMA_OPTION );
	}

	/**
	 * Delay-seconds and HTTP-dates are normalized strictly.
	 */
	public function test_seconds_from_header_normalizes_supported_values(): void {
		$now = 1700000000;

		$this->assertEquals( 120, Retry_After_Mirror::seconds_from_header( '120', $now ) );
		$this->assertEquals( 0, Retry_After_Mirror::seconds_from_header( '0', $now ) );
		// HTTP-dates have whole-second resolution, so a 90.5-second target serializes at the next second.
		$this->assertEquals( 91, Retry_After_Mirror::seconds_from_header( gmdate( 'D, d M Y H:i:s \G\M\T', $now + 91 ), $now ) );
		$this->assertEquals( 0, Retry_After_Mirror::seconds_from_header( gmdate( 'D, d M Y H:i:s \G\M\T', $now - 30 ), $now ) );
		$this->assertNull( Retry_After_Mirror::seconds_from_header( 'garbage', $now ) );
		$this->assertNull( Retry_After_Mirror::seconds_from_header( '-5', $now ) );
		$this->assertNull( Retry_After_Mirror::seconds_from_header( (string) PHP_INT_MAX . '0', $now ) );
	}

	/**
	 * WCPOS errors mirror a valid header into a newly-created data array.
	 */
	public function test_wcpos_error_mirrors_retry_after_header_into_body(): void {
		$response = new WP_REST_Response(
			array(
				'code'    => 'temporary_error',
				'message' => 'Retry later.',
			),
			503,
			array( 'Retry-After' => '45' )
		);

		Retry_After_Mirror::filter_response( $response, null, $this->wp_rest_get_request( self::CURRENT_LANE_ROUTE ) );

		$this->assertEquals( 45, $response->get_data()['data']['retry_after_seconds'] );
	}

	/**
	 * Successful responses are not changed.
	 */
	public function test_successful_response_is_untouched(): void {
		$body     = array( 'ok' => true );
		$response = new WP_REST_Response( $body, 200, array( 'Retry-After' => '45' ) );

		Retry_After_Mirror::filter_response( $response, null, $this->wp_rest_get_request( self::CURRENT_LANE_ROUTE ) );

		$this->assertEquals( $body, $response->get_data() );
	}

	/**
	 * Unmarked routes outside WCPOS namespaces are not changed.
	 */
	public function test_non_wcpos_error_is_untouched(): void {
		$body     = array( 'code' => 'temporary_error' );
		$response = new WP_REST_Response( $body, 503, array( 'Retry-After' => '45' ) );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );

		Retry_After_Mirror::filter_response( $response, null, $request );

		$this->assertEquals( $body, $response->get_data() );
	}

	/**
	 * An existing body hint remains authoritative.
	 */
	public function test_existing_retry_after_seconds_is_not_overwritten(): void {
		$response = new WP_REST_Response(
			array(
				'code' => 'temporary_error',
				'data' => array( 'retry_after_seconds' => 12 ),
			),
			503,
			array( 'Retry-After' => '45' )
		);

		Retry_After_Mirror::filter_response( $response, null, $this->wp_rest_get_request( self::CURRENT_LANE_ROUTE ) );

		$this->assertEquals( 12, $response->get_data()['data']['retry_after_seconds'] );
	}

	/**
	 * The real schema-health 503 carries both back-off representations.
	 */
	public function test_health_503_carries_retry_after_header_and_body_field(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );

		$response = $this->server->dispatch( $this->wp_rest_get_request( self::CURRENT_LANE_ROUTE ) );

		$this->assertEquals( 503, $response->get_status() );
		$this->assertEquals( '30', $response->get_headers()['Retry-After'] );
		$this->assertEquals( 30, $response->get_data()['data']['retry_after_seconds'] );
	}
}
