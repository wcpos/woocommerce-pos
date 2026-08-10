<?php
/**
 * Tests for the template barcode runtime wiring.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Menu;
use WCPOS\WooCommercePOS\Admin\Templates\Single_Template;
use WP_UnitTestCase;

/**
 * Barcode runtime enqueue tests.
 *
 * The barcode renderer (bwip-js) is tree-shaken and bundled into each template
 * app, so no standalone ~1MB `wcpos-bwip` script should be registered and the
 * apps must not depend on one. These tests guard against reintroducing the
 * separate shared-runtime handle.
 */
class Test_Template_Barcode_Runtime extends WP_UnitTestCase {
	/**
	 * Clean up registered assets and globals.
	 */
	public function tearDown(): void {
		foreach ( array( 'wcpos-bwip', 'wcpos-template-editor', 'wcpos-template-gallery' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}

		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * The gallery bundles its own barcode runtime — no separate bwip script.
	 */
	public function test_gallery_bundles_barcode_runtime_without_separate_script(): void {
		$menu = new Menu();

		$menu->enqueue_gallery_assets();

		$this->assertArrayHasKey( 'wcpos-template-gallery', wp_scripts()->registered );
		$this->assertArrayNotHasKey( 'wcpos-bwip', wp_scripts()->registered );
		$this->assertNotContains( 'wcpos-bwip', wp_scripts()->registered['wcpos-template-gallery']->deps );
	}

	/**
	 * The template editor bundles its own barcode runtime — no separate bwip script.
	 */
	public function test_editor_bundles_barcode_runtime_without_separate_script(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wcpos_template' ) );
		set_current_screen( 'post' );
		get_current_screen()->post_type = 'wcpos_template';
		$GLOBALS['post']                = get_post( $post_id );

		$single_template = new Single_Template();
		$single_template->enqueue_scripts( 'post.php' );

		$this->assertArrayHasKey( 'wcpos-template-editor', wp_scripts()->registered );
		$this->assertArrayNotHasKey( 'wcpos-bwip', wp_scripts()->registered );
		$this->assertNotContains( 'wcpos-bwip', wp_scripts()->registered['wcpos-template-editor']->deps );
	}
}
