<?php
/**
 * Sync CORS allow-list tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact contract-focused documentation.

use WCPOS\WooCommercePOS\Sync\Cors;
use WP_UnitTestCase;

/**
 * The sync lane's contribution to the allow-list, as a unit.
 *
 * The wire contract itself — what the single owner actually publishes on a
 * preflight and through `rest_allowed_cors_headers` — is pinned in
 * {@see \WCPOS\WooCommercePOS\Tests\API\Test_Cors_Contract}, which is where
 * the client-sent header ratchet moved when Rest_Cors replaced the two
 * writers this class used to have to check separately.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Cors
 */
class Test_Cors extends WP_UnitTestCase {
	public function test_allow_headers_appends_the_pos_client_headers_without_duplicates(): void {
		$this->assertSame(
			array( 'Authorization', 'Idempotency-Key', 'If-Match', 'If-None-Match', 'X-WCPOS-Idempotency-Key', 'X-WCPOS-Store' ),
			Cors::allow_headers( array( 'Authorization' ) )
		);
		$this->assertSame(
			array( 'X-WCPOS-Store', 'Idempotency-Key', 'If-Match', 'If-None-Match', 'X-WCPOS-Idempotency-Key' ),
			Cors::allow_headers( array( 'X-WCPOS-Store' ) )
		);
	}
}
