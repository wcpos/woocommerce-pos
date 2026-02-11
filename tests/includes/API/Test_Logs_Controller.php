<?php
/**
 * Test Logs Controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Logs;

/**
 * Logs Controller test case.
 */
class Test_Logs_Controller extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Logs();
		$this->clean_log_files();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->clean_log_files();
		parent::tearDown();
	}

	/**
	 * Create a test log file in the WC logs directory.
	 *
	 * @param string $content Log file content.
	 *
	 * @return string File path.
	 */
	private function create_test_log_file( string $content ): string {
		$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
		if ( ! is_dir( $log_dir ) ) {
			mkdir( $log_dir, 0755, true );
		}
		$file = $log_dir . 'woocommerce-pos-2026-02-11-test.log';
		file_put_contents( $file, $content );

		return $file;
	}

	/**
	 * Remove test log files.
	 */
	private function clean_log_files(): void {
		$log_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
		$files   = glob( $log_dir . 'woocommerce-pos-*.log' );
		if ( $files ) {
			array_map( 'unlink', $files );
		}
	}

	/**
	 * Test that the routes are registered.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/logs', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/logs/mark-read', $routes );
	}

	/**
	 * Test the namespace property.
	 */
	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );
		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	/**
	 * Test the rest_base property.
	 */
	public function test_rest_base_property(): void {
		$rest_base = $this->get_reflected_property_value( 'rest_base' );
		$this->assertEquals( 'logs', $rest_base );
	}

	/**
	 * Test GET logs requires auth.
	 */
	public function test_get_logs_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test POST mark-read requires auth.
	 */
	public function test_mark_read_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_post_request( '/wcpos/v1/logs/mark-read' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test GET logs returns 200 with empty entries when no logs exist.
	 */
	public function test_get_logs_returns_empty_when_no_logs(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'entries', $data );
		$this->assertEmpty( $data['entries'] );
	}

	/**
	 * Test GET logs returns parsed file-based log entries.
	 */
	public function test_get_logs_parses_file_entries(): void {
		$this->create_test_log_file(
			"2026-02-11T10:00:00+00:00 ERROR Test error message\n" .
			"2026-02-11T09:00:00+00:00 WARNING Test warning | Context: some context\n" .
			"2026-02-11T08:00:00+00:00 INFO Test info message\n"
		);

		$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 3, $data['entries'] );

		// Entries should be in reverse chronological order (newest first).
		$this->assertEquals( 'error', $data['entries'][0]['level'] );
		$this->assertEquals( 'Test error message', $data['entries'][0]['message'] );
		$this->assertEquals( 'warning', $data['entries'][1]['level'] );
		$this->assertEquals( 'Test warning', $data['entries'][1]['message'] );
		$this->assertEquals( 'some context', $data['entries'][1]['context'] );
		$this->assertEquals( 'info', $data['entries'][2]['level'] );
	}

	/**
	 * Test GET logs filters by level.
	 */
	public function test_get_logs_filters_by_level(): void {
		$this->create_test_log_file(
			"2026-02-11T10:00:00+00:00 ERROR Test error\n" .
			"2026-02-11T09:00:00+00:00 WARNING Test warning\n" .
			"2026-02-11T08:00:00+00:00 INFO Test info\n"
		);

		$request = $this->wp_rest_get_request( '/wcpos/v1/logs' );
		$request->set_param( 'level', 'error' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertCount( 1, $data['entries'] );
		$this->assertEquals( 'error', $data['entries'][0]['level'] );
	}

	/**
	 * Test GET logs returns has_fatal_errors flag.
	 */
	public function test_get_logs_returns_has_fatal_errors(): void {
		$request  = $this->wp_rest_get_request( '/wcpos/v1/logs' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'has_fatal_errors', $data );
		$this->assertIsBool( $data['has_fatal_errors'] );
	}

	/**
	 * Test GET logs supports pagination.
	 */
	public function test_get_logs_supports_pagination(): void {
		$lines = '';
		for ( $i = 0; $i < 30; $i++ ) {
			$lines .= sprintf( "2026-02-11T%02d:00:00+00:00 INFO Message %d\n", $i % 24, $i );
		}
		$this->create_test_log_file( $lines );

		$request = $this->wp_rest_get_request( '/wcpos/v1/logs' );
		$request->set_param( 'per_page', 10 );
		$request->set_param( 'page', 1 );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertCount( 10, $data['entries'] );
		$this->assertEquals( '30', $response->get_headers()['X-WP-Total'] );
		$this->assertEquals( '3', $response->get_headers()['X-WP-TotalPages'] );
	}
}
