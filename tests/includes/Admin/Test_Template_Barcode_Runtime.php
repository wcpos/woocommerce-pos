<?php
/**
 * Tests for the shared template barcode runtime.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Menu;
use WCPOS\WooCommercePOS\Admin\Templates\Single_Template;
use WP_UnitTestCase;

/**
 * Shared barcode runtime enqueue tests.
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
	 * The gallery bundle must load after the locally shipped shared runtime.
	 */
	public function test_gallery_script_depends_on_shared_barcode_runtime(): void {
		$menu = new Menu();

		$menu->enqueue_gallery_assets();

		$this->assertStringEndsWith( '/assets/js/bwip.js', wp_scripts()->registered['wcpos-bwip']->src );
		$this->assertContains( 'wcpos-bwip', wp_scripts()->registered['wcpos-template-gallery']->deps );
	}

	/**
	 * The template editor bundle must load after the same shared runtime.
	 */
	public function test_editor_script_depends_on_shared_barcode_runtime(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'wcpos_template' ) );
		set_current_screen( 'post' );
		get_current_screen()->post_type = 'wcpos_template';
		$GLOBALS['post']                = get_post( $post_id );

		$single_template = new Single_Template();
		$single_template->enqueue_scripts( 'post.php' );

		$this->assertStringEndsWith( '/assets/js/bwip.js', wp_scripts()->registered['wcpos-bwip']->src );
		$this->assertContains( 'wcpos-bwip', wp_scripts()->registered['wcpos-template-editor']->deps );
	}
}
