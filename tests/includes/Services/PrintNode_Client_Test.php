<?php
/**
 * PrintNode REST client tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\PrintNode_Client;

/**
 * PrintNode_Client_Test class.
 */
class PrintNode_Client_Test extends \WP_UnitTestCase {
	/**
	 * Captured request args from the last intercepted HTTP request.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Active pre_http_request callback, stored so it can be removed in tearDown.
	 *
	 * @var callable|null
	 */
	private $filter;

	/**
	 * Remove any active HTTP filter.
	 */
	public function tearDown(): void {
		if ( null !== $this->filter ) {
			remove_filter( 'pre_http_request', $this->filter, 10 );
			$this->filter = null;
		}
		parent::tearDown();
	}

	/**
	 * Register a pre_http_request filter that captures args and returns a faux response.
	 *
	 * @param mixed $response Faux response array or WP_Error to return.
	 */
	private function mock_http( $response ): void {
		$this->filter = function ( $pre, $args, $url ) use ( $response ) {
			$this->captured = array(
				'args' => $args,
				'url'  => $url,
			);

			return $response;
		};

		add_filter( 'pre_http_request', $this->filter, 10, 3 );
	}

	/**
	 * Build a faux 2xx response array.
	 *
	 * @param mixed $payload Payload to JSON-encode as the body.
	 * @param int   $code    HTTP status code.
	 *
	 * @return array
	 */
	private function fake_response( $payload, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $payload ),
			'headers'  => array(),
		);
	}

	/**
	 * Authorization header uses HTTP Basic with the key as username and empty password.
	 */
	public function test_request_authorization_header_uses_basic_key(): void {
		// Arrange.
		$this->mock_http( $this->fake_response( array( 'id' => 1 ) ) );
		$client = new PrintNode_Client( 'TESTKEY' );

		// Act.
		$client->whoami();

		// Assert.
		$expected = 'Basic ' . base64_encode( 'TESTKEY:' );
		$this->assertEquals( $expected, $this->captured['args']['headers']['Authorization'] );
	}

	/**
	 * Test whoami() parses a 200 account object into an array.
	 */
	public function test_whoami_with_200_returns_account_array(): void {
		// Arrange.
		$account = array(
			'id'    => 42,
			'email' => 'merchant@example.com',
		);
		$this->mock_http( $this->fake_response( $account ) );
		$client = new PrintNode_Client( 'TESTKEY' );

		// Act.
		$result = $client->whoami();

		// Assert.
		$this->assertEquals( $account, $result );
	}

	/**
	 * Test whoami() on a 401 returns an unauthorized WP_Error.
	 */
	public function test_whoami_with_401_returns_unauthorized_error(): void {
		// Arrange.
		$this->mock_http( $this->fake_response( array(), 401 ) );
		$client = new PrintNode_Client( 'TESTKEY' );

		// Act.
		$result = $client->whoami();

		// Assert.
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'wcpos_printnode_unauthorized', $result->get_error_code() );
	}

	/**
	 * Test printers() returns the decoded array of printers.
	 */
	public function test_printers_with_200_returns_decoded_array(): void {
		// Arrange.
		$printers = array(
			array(
				'id'    => 1,
				'name'  => 'Front',
				'state' => 'online',
			),
			array(
				'id'    => 2,
				'name'  => 'Kitchen',
				'state' => 'offline',
			),
		);
		$this->mock_http( $this->fake_response( $printers ) );
		$client = new PrintNode_Client( 'TESTKEY' );

		// Act.
		$result = $client->printers();

		// Assert.
		$this->assertEquals( $printers, $result );
	}

	/**
	 * Test submit_job() POSTs a well-formed body to /printjobs and normalizes a bare-int id.
	 */
	public function test_submit_job_posts_body_and_returns_id(): void {
		// Arrange.
		$this->mock_http( $this->fake_response( 12345 ) );
		$client = new PrintNode_Client( 'TESTKEY' );

		// Act.
		$result = $client->submit_job( 7, 'Receipt #1', 'pdf_base64', 'QkFTRTY0' );

		// Assert.
		$this->assertEquals( array( 'id' => 12345 ), $result );
		$this->assertEquals( 'POST', $this->captured['args']['method'] );
		$this->assertStringContainsString( '/printjobs', $this->captured['url'] );

		$body = json_decode( $this->captured['args']['body'], true );
		$this->assertEquals( 7, $body['printerId'] );
		$this->assertEquals( 'Receipt #1', $body['title'] );
		$this->assertEquals( 'pdf_base64', $body['contentType'] );
		$this->assertEquals( 'QkFTRTY0', $body['content'] );
		$this->assertEquals( 'WCPOS', $body['source'] );
	}

	/**
	 * Test submit_job() returns a WP_Error on a network failure without throwing.
	 */
	public function test_submit_job_with_network_error_returns_wp_error(): void {
		// Arrange.
		$this->mock_http( new \WP_Error( 'http_request_failed', 'Connection timed out.' ) );
		$client = new PrintNode_Client( 'TESTKEY' );

		// Act.
		$result = $client->submit_job( 7, 'Receipt', 'pdf_base64', 'QkFTRTY0' );

		// Assert.
		$this->assertTrue( is_wp_error( $result ) );
	}

	/**
	 * Test printer_state() maps the printer state string, defaulting to unknown.
	 */
	public function test_printer_state_maps_state_string(): void {
		// Arrange + Act + Assert: online.
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'id' => 7,
						'state' => 'online',
					),
				)
			)
		);
		$client = new PrintNode_Client( 'TESTKEY' );
		$this->assertEquals( 'online', $client->printer_state( 7 ) );

		// offline.
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'id' => 7,
						'state' => 'offline',
					),
				)
			)
		);
		$this->assertEquals( 'offline', $client->printer_state( 7 ) );

		// unexpected value.
		$this->mock_http(
			$this->fake_response(
				array(
					array(
						'id' => 7,
						'state' => 'connecting',
					),
				)
			)
		);
		$this->assertEquals( 'unknown', $client->printer_state( 7 ) );
	}

	/**
	 * Test printer_state() returns unknown on error or empty response.
	 */
	public function test_printer_state_on_error_returns_unknown(): void {
		// Arrange.
		$this->mock_http( new \WP_Error( 'http_request_failed', 'down' ) );
		$client = new PrintNode_Client( 'TESTKEY' );

		// Act + Assert.
		$this->assertEquals( 'unknown', $client->printer_state( 7 ) );

		// Empty array.
		$this->mock_http( $this->fake_response( array() ) );
		$this->assertEquals( 'unknown', $client->printer_state( 7 ) );
	}

	/**
	 * The API key never leaks into a returned WP_Error message.
	 */
	public function test_api_key_never_leaks_into_error_message(): void {
		// Arrange.
		$this->mock_http( $this->fake_response( array(), 500 ) );
		$client = new PrintNode_Client( 'SECRETKEY' );

		// Act.
		$result = $client->whoami();

		// Assert.
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertStringNotContainsString( 'SECRETKEY', $result->get_error_message() );
	}
}
