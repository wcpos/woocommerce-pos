<?php
/**
 * Tests for admin menu highlighting on template pages.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin\Menu;
use WP_UnitTestCase;
use const WCPOS\WooCommercePOS\PLUGIN_NAME;

/**
 * Verifies that the POS admin sidebar menu stays expanded and the
 * correct submenu item is highlighted when editing a template.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Menu_Highlighting extends WP_UnitTestCase {

	/**
	 * The menu instance under test.
	 *
	 * @var Menu
	 */
	private $menu;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Grant the current user admin capabilities.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Menu constructor requires this capability.
		$user = wp_get_current_user();
		$user->add_cap( 'manage_woocommerce_pos' );

		$this->menu = new Menu();
	}

	/**
	 * Simulate a WP_Screen for a given post type and screen ID.
	 *
	 * @param string $post_type The post type.
	 * @param string $screen_id The screen ID.
	 */
	private function set_current_screen( string $post_type, string $screen_id ): void {
		global $current_screen;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$current_screen            = new \stdClass();
		$current_screen->post_type = $post_type;
		$current_screen->id        = $screen_id;
	}

	/**
	 * On the template edit page (post.php?action=edit for a wcpos_template),
	 * the POS menu should be the parent and Templates should be highlighted.
	 */
	public function test_template_edit_page_highlights_pos_templates(): void {
		global $submenu_file;

		$this->set_current_screen( 'wcpos_template', 'wcpos_template' );

		$result = $this->menu->highlight_templates_menu( 'post.php' );

		$this->assertSame( PLUGIN_NAME, $result, 'Parent file should be POS plugin name.' );
		$this->assertSame( 'wcpos-templates', $submenu_file, 'Submenu file should point to the templates gallery.' );
	}

	/**
	 * On the template list page (edit.php?post_type=wcpos_template),
	 * the POS menu should be the parent and Templates should be highlighted.
	 */
	public function test_template_list_page_highlights_pos_templates(): void {
		global $submenu_file;

		$this->set_current_screen( 'wcpos_template', 'edit-wcpos_template' );

		$result = $this->menu->highlight_templates_menu( 'edit.php' );

		$this->assertSame( PLUGIN_NAME, $result, 'Parent file should be POS plugin name.' );
		$this->assertSame( 'wcpos-templates', $submenu_file, 'Submenu file should point to the templates gallery.' );
	}

	/**
	 * On unrelated pages, the filter should not change the parent file.
	 */
	public function test_unrelated_page_is_not_affected(): void {
		global $submenu_file;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu_file = null;
		$this->set_current_screen( 'post', 'post' );

		$result = $this->menu->highlight_templates_menu( 'post.php' );

		$this->assertSame( 'post.php', $result, 'Parent file should remain unchanged for non-template pages.' );
		$this->assertNull( $submenu_file, 'Submenu file should not be set for non-template pages.' );
	}
}
