<?php
/**
 * Tests for legacy template admin cleanup.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin;
use WP_UnitTestCase;

/**
 * Verifies template management is routed through the gallery/editor packages,
 * not the retired classic templates list screen.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Template_Admin_Legacy_Cleanup extends WP_UnitTestCase {

	/**
	 * The classic list screen should not have a PHP screen handler anymore.
	 */
	public function test_template_list_screen_has_no_legacy_php_handler(): void {
		$admin = new Admin();

		$property = new \ReflectionProperty( $admin, 'screen_handlers' );
		$property->setAccessible( true );
		$handlers = $property->getValue( $admin );

		$this->assertArrayHasKey( 'wcpos_template', $handlers, 'Template editor screen should still load the editor bridge.' );
		$this->assertArrayNotHasKey( 'edit-wcpos_template', $handlers, 'Template list screen is handled by the Template Gallery SPA redirect.' );
	}

	/**
	 * Old admin-post template actions duplicated the Template Gallery REST API.
	 */
	public function test_legacy_template_admin_post_actions_are_not_registered(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$_REQUEST['action'] = 'wcpos_activate_template';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$admin = new Admin();
		$admin->init();

		$this->assertFalse( has_action( 'admin_post_wcpos_activate_template', array( $admin, 'handle_activate_template' ) ) );
		$this->assertFalse( has_action( 'admin_post_wcpos_copy_template', array( $admin, 'handle_copy_template' ) ) );
		$this->assertFalse( has_action( 'admin_post_wcpos_install_starter', array( $admin, 'handle_install_starter' ) ) );
		$this->assertFalse( has_action( 'admin_post_wcpos_toggle_template_status', array( $admin, 'handle_toggle_template_status' ) ) );

		unset( $_REQUEST['action'] );
	}
}
