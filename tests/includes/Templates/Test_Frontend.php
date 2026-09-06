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
	 * The web-bundle lane note uses a block comment for its aligned list.
	 */
	public function test_web_bundle_lane_note_uses_block_comment(): void {
		$source   = (string) file_get_contents( \WCPOS\WooCommercePOS\PLUGIN_PATH . 'includes/Templates/Frontend.php' );
		$expected = implode(
			"\n",
			array(
				"\t\t/*",
				"\t\t * One jsDelivr ref per lane, named after the lane (owner ruling, 2026-09-04):",
				"\t\t *   released lane → `@<major.minor>` (this default; the tag is cut at release)",
				"\t\t *   next lane     → `@next` — the `next` BRANCH of wcpos/web-bundle IS the dev",
				"\t\t *                   lane's tag. There is no versioned/prerelease tag for `next`.",
				"\t\t *                   dev-next sets WCPOS_WEB_BUNDLE_REF=next to load it.",
				"\t\t */",
			)
		);

		$this->assertStringContainsString( $expected, $source );
	}

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
