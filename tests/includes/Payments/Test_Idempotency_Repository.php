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
 * @internal
 *
 * @coversNothing
 */
class Test_Idempotency_Repository extends WP_UnitTestCase {
	public function test_stores_and_replays_a_response_body(): void {
		$repo = new Idempotency_Repository();
		$key  = wp_generate_uuid4();
		$body = array(
			'status' => 'completed',
		);

		$repo->store( 'checkout', $key, 'hash-a', 200, $body );
		$found = $repo->find( 'checkout', $key );

		$this->assertSame( 200, $found['status_code'] );
		$this->assertSame( $body, $found['body'] );
	}
}
