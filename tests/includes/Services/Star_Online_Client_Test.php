<?php
/**
 * Star_Online_Client tests.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

use WCPOS\WooCommercePOS\Services\Star_Online_Client;

/**
 * @internal
 *
 * @coversNothing
 */
class Star_Online_Client_Test extends WP_UnitTestCase {
	private array $captured = array();

	public function set_up(): void {
		parent::set_up();
		$this->captured = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tear_down();
	}

	public function intercept( $pre, $args, $url ) {
		$this->captured = array( 'url' => $url, 'args' => $args );

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'JobId' => '689', 'Name' => 'F1' ) ),
			'response' => array( 'code' => 201, 'message' => 'Created' ),
		);
	}

	public function test_region_base_from_cloudprnt_url(): void {
		$this->assertSame(
			'https://eu-api.stario.online/v1',
			Star_Online_Client::api_base_from_cloudprnt_url( 'https://eu-device.stario.online/cloudprnt/kilbot' )
		);
		$this->assertSame(
			'https://api.stario.online/v1',
			Star_Online_Client::api_base_from_cloudprnt_url( 'https://device.stario.online/cloudprnt/shop' )
		);
		$this->assertNull(
			Star_Online_Client::api_base_from_cloudprnt_url( 'https://evil.example.com/cloudprnt/x' )
		);
	}

	public function test_group_from_cloudprnt_url(): void {
		$this->assertSame( 'kilbot', Star_Online_Client::group_from_cloudprnt_url( 'https://eu-device.stario.online/cloudprnt/kilbot' ) );
	}

	public function test_submit_job_posts_markup_with_header_and_encoded_path(): void {
		$client = new Star_Online_Client( 'https://eu-api.stario.online/v1', 'KEY-123' );
		$result = $client->submit_job( 'my group', 'dev/1', 'Order #5', 'text/vnd.star.markup', '[bold: on]Hi[bold: off]' );

		$this->assertSame( '689', $result['id'] );
		$this->assertSame(
			'https://eu-api.stario.online/v1/a/my%20group/d/dev%2F1/q',
			$this->captured['url']
		);
		$this->assertSame( 'KEY-123', $this->captured['args']['headers']['Star-Api-Key'] );
		$this->assertSame( 'text/vnd.star.markup', $this->captured['args']['headers']['Content-Type'] );
		$this->assertSame( '[bold: on]Hi[bold: off]', $this->captured['args']['body'] );
	}

	public function test_device_state_reads_status_online(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		$filter = static function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'Status' => array( 'Online' => true ) ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		try {
			$client = new Star_Online_Client( 'https://eu-api.stario.online/v1', 'KEY' );
			$this->assertSame( 'online', $client->device_state( 'kilbot', 'abc' ) );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}
	}

	public function test_forbidden_response_reports_permissions_error(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		$filter = static function () {
			return array(
				'headers'  => array(),
				'body'     => 'Forbidden',
				'response' => array( 'code' => 403, 'message' => 'Forbidden' ),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		try {
			$client = new Star_Online_Client( 'https://eu-api.stario.online/v1', 'KEY' );
			$result = $client->devices( 'kilbot' );

			$this->assertWPError( $result );
			$this->assertSame( 'wcpos_star_online_forbidden', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertStringContainsString( 'permissions', $result->get_error_message() );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}
	}
}
