<?php
/**
 * Tests for the POS frontend bootstrap.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use WCPOS\WooCommercePOS\Templates\Frontend;
use WP_UnitTestCase;

/**
 * Class Test_Frontend
 */
class Test_Frontend extends WP_UnitTestCase {
	/**
	 * The bundle manifest cache key changes with the OPFS worker.
	 */
	public function test_manifest_cache_key_matches_opfs_worker_hash(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		ob_start();
		( new Frontend() )->footer();
		$output = (string) ob_get_clean();
		$hash   = hash_file( 'sha256', \WCPOS\WooCommercePOS\PLUGIN_PATH . 'assets/js/opfs.worker.js' );

		$this->assertStringContainsString( '/metadata.json?v=' . $hash, $output );
	}
}
