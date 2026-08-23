<?php
/**
 * Tests for the Star CloudPRNT poll request body parser.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Poll_Request;
use WP_UnitTestCase;

/**
 * Cloud_Print_Poll_Request_Test class.
 */
class Cloud_Print_Poll_Request_Test extends WP_UnitTestCase {

	/**
	 * It reads printingInProgress, statusCode and clientAction answers.
	 */
	public function test_from_body_reads_the_documented_fields(): void {
		$body = wp_json_encode(
			array(
				'status'             => '00000000',
				'statusCode'         => '200 OK',
				'printerMAC'         => '00:11:62:00:00:01',
				'printingInProgress' => true,
				'clientAction'       => array(
					array(
						'request' => 'ClientType',
						'result'  => 'Star CloudPRNT',
					),
					array(
						'request' => 'Encodings',
						'result'  => 'application/vnd.star.starprnt,text/plain',
					),
				),
			)
		);

		$poll = Cloud_Print_Poll_Request::from_body( $body );

		$this->assertTrue( $poll->printing_in_progress() );
		$this->assertSame( '200 OK', $poll->status_code() );
		$this->assertSame(
			array(
				'ClientType' => 'Star CloudPRNT',
				'Encodings'  => 'application/vnd.star.starprnt,text/plain',
			),
			$poll->answers()
		);
	}

	/**
	 * It prefers already-decoded JSON params when WordPress supplied them.
	 */
	public function test_from_body_uses_decoded_params_when_given(): void {
		$poll = Cloud_Print_Poll_Request::from_body( 'not json', array( 'statusCode' => '200 OK' ) );

		$this->assertSame( '200 OK', $poll->status_code() );
	}

	/**
	 * It falls back to decoding the raw body when the params are empty.
	 */
	public function test_from_body_decodes_the_raw_body_without_params(): void {
		$poll = Cloud_Print_Poll_Request::from_body( '{"statusCode":"200 OK"}', array() );

		$this->assertSame( '200 OK', $poll->status_code() );
	}

	/**
	 * It reads printingInProgress sent as a string.
	 */
	public function test_from_body_reads_string_booleans(): void {
		$this->assertTrue( Cloud_Print_Poll_Request::from_body( '{"printingInProgress":"true"}' )->printing_in_progress() );
		$this->assertFalse( Cloud_Print_Poll_Request::from_body( '{"printingInProgress":"false"}' )->printing_in_progress() );
	}

	/**
	 * It treats a missing or unparseable body as an idle printer with no answers.
	 */
	public function test_from_body_defaults_to_idle_for_an_unusable_body(): void {
		foreach ( array( '', 'garbage', '[]', '"a string"' ) as $body ) {
			$poll = Cloud_Print_Poll_Request::from_body( $body );

			$this->assertFalse( $poll->printing_in_progress(), $body );
			$this->assertSame( '', $poll->status_code(), $body );
			$this->assertSame( array(), $poll->answers(), $body );
		}
	}

	/**
	 * It accepts `response` as the answer key alongside `result`.
	 */
	public function test_from_body_accepts_the_response_answer_key(): void {
		$poll = Cloud_Print_Poll_Request::from_body( '{"clientAction":[{"request":"ClientType","response":"TSP100IV"}]}' );

		$this->assertSame( array( 'ClientType' => 'TSP100IV' ), $poll->answers() );
	}

	/**
	 * It joins an array-valued answer into a delimited string.
	 */
	public function test_from_body_flattens_array_answers(): void {
		$poll = Cloud_Print_Poll_Request::from_body( '{"clientAction":[{"request":"Encodings","result":["text/plain","image/png"]}]}' );

		$this->assertSame( array( 'Encodings' => 'text/plain,image/png' ), $poll->answers() );
	}

	/**
	 * It skips clientAction entries that name no request or carry no answer.
	 */
	public function test_from_body_skips_malformed_client_action_entries(): void {
		$poll = Cloud_Print_Poll_Request::from_body(
			'{"clientAction":[{"result":"orphan"},{"request":"ClientType"},{"request":"","result":"x"},"junk",{"request":"Encodings","result":"text/plain"}]}'
		);

		$this->assertSame( array( 'Encodings' => 'text/plain' ), $poll->answers() );
	}

	/**
	 * It strips control characters and caps the length of untrusted answers.
	 */
	public function test_from_body_sanitizes_untrusted_answers(): void {
		$long = str_repeat( 'a', 900 );
		$poll = Cloud_Print_Poll_Request::from_body(
			wp_json_encode(
				array(
					'statusCode'   => "200\n OK\x07",
					'clientAction' => array(
						array(
							'request' => 'ClientType',
							'result'  => $long,
						),
					),
				)
			)
		);

		$this->assertSame( '200 OK', $poll->status_code() );
		$this->assertSame( 512, \strlen( $poll->answers()['ClientType'] ) );
	}
}
