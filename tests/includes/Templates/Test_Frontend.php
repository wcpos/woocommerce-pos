<?php
/**
 * Tests for the POS frontend bootstrap.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use WCPOS\WooCommercePOS\Templates\Frontend;
use WP_UnitTestCase;
use WPDieException;

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

	/**
	 * The frontend refuses a multi-role user missing the cashier capability.
	 */
	public function test_get_template_two_role_user_missing_publish_shop_orders_dies_with_capability_message(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'frontend-denied-user',
				'user_pass'  => 'test-password',
				'role'       => 'customer',
			)
		);
		$user    = wp_set_current_user( $user_id );
		$user->add_role( 'administrator' );
		$user->add_cap( 'publish_shop_orders', false );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve the exact test environment for cleanup.
		$https            = $_SERVER['HTTPS'] ?? null;
		$_SERVER['HTTPS'] = 'on';
		$handler          = static function () {
			return static function ( $message, $title, $args ) {
				self::assertSame( 403, $args['response'] );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Capture wp_die output for assertions, not rendering.
				throw new WPDieException( $message );
			};
		};
		add_filter( 'wp_die_handler', $handler );
		// The template ends in exit; on code without the gate this test would
		// otherwise end the whole PHPUnit run with a success status. Fail loudly
		// instead if execution ever passes the gate.
		$past_gate = static function () {
			throw new \RuntimeException( 'Frontend gate let a user without publish_shop_orders through.' );
		};
		add_action( 'woocommerce_pos_frontend_template_redirect', $past_gate );
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'publish_shop_orders' );

		try {
			( new Frontend() )->get_template();
		} finally {
			remove_action( 'woocommerce_pos_frontend_template_redirect', $past_gate );
			remove_filter( 'wp_die_handler', $handler );
			wp_set_current_user( 0 );
			if ( null === $https ) {
				unset( $_SERVER['HTTPS'] );
			} else {
				$_SERVER['HTTPS'] = $https;
			}
		}
	}
}
