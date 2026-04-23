<?php
/**
 * Tests for the POS idempotency repository.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments
 */

namespace WCPOS\WooCommercePOS\Tests\Payments;

use WCPOS\WooCommercePOS\Payments\Idempotency_Repository;
use WP_UnitTestCase;

/**
 * Idempotency repository tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Idempotency_Repository extends WP_UnitTestCase {
	/**
	 * It stores and replays a response body.
	 */
	public function test_stores_and_replays_a_response_body(): void {
		$repo = new Idempotency_Repository();
		$key  = wp_generate_uuid4();
		$body = array(
			'status' => 'completed',
		);

		$repo->store( 'checkout', $key, 'hash-a', 200, $body );
		$found = $repo->find( 'checkout', $key );

		$this->assertIsArray( $found );
		$this->assertArrayHasKey( 'status_code', $found );
		$this->assertArrayHasKey( 'body', $found );
		$this->assertSame( 200, $found['status_code'] );
		$this->assertSame( $body, $found['body'] );
	}

	/**
	 * It replays a stored response when the same request is claimed again.
	 */
	public function test_claim_returns_existing_response_when_request_was_already_stored(): void {
		$repo = new Idempotency_Repository();
		$key  = wp_generate_uuid4();
		$body = array(
			'status' => 'completed',
		);

		$repo->store( 'checkout:123', $key, 'hash-a', 200, $body );

		$claim = $repo->claim( 'checkout:123', $key, 'hash-a' );

		$this->assertIsArray( $claim );
		$this->assertSame( 200, $claim['status_code'] );
		$this->assertSame( $body, $claim['body'] );
	}

	/**
	 * It rejects conflicting request hashes while a claim is in flight.
	 */
	public function test_claim_rejects_conflicting_in_flight_request(): void {
		$repo = new Idempotency_Repository();
		$key  = wp_generate_uuid4();

		$this->assertTrue( $repo->claim( 'checkout:123', $key, 'hash-a' ) );

		$conflict = $repo->claim( 'checkout:123', $key, 'hash-b' );

		$this->assertWPError( $conflict );
		$this->assertSame( 'wcpos_idempotency_conflict', $conflict->get_error_code() );
	}
}
