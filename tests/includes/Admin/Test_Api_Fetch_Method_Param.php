<?php
/**
 * Tests the api-fetch `_method` shim registration and bundle wiring.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin
 */

namespace WCPOS\WooCommercePOS\Tests\Admin;

use WCPOS\WooCommercePOS\Admin;
use WCPOS\WooCommercePOS\Admin\Menu;
use WCPOS\WooCommercePOS\Admin\Settings;
use WCPOS\WooCommercePOS\Admin\Templates\Single_Template;

use const WCPOS\WooCommercePOS\PLUGIN_NAME;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;

/**
 * Every wp-admin bundle that talks to the REST API through `wp.apiFetch` must
 * load the `_method` shim, otherwise its PUT/PATCH/DELETE calls go out as
 * POST + X-HTTP-Method-Override and die on hosts that 403 the override header.
 */
class Test_Api_Fetch_Method_Param extends \WP_UnitTestCase {
	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Keep the consent-gated PostHog calls off the network.
		add_filter( 'pre_http_request', '__return_empty_array' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos' );

		// A fresh WP_Scripts so earlier tests' registrations cannot satisfy the assertions.
		$GLOBALS['wp_scripts'] = null;
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', '__return_empty_array' );
		$GLOBALS['wp_scripts'] = null;
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	/**
	 * Test the shim is registered on admin_init with wp-api-fetch as its only dependency.
	 */
	public function test_register_scripts_registers_shim_depending_on_wp_api_fetch(): void {
		$admin = new Admin();

		$admin->register_scripts();

		$this->assertTrue( wp_script_is( Admin::API_FETCH_METHOD_PARAM_HANDLE, 'registered' ) );
		$script = wp_scripts()->registered[ Admin::API_FETCH_METHOD_PARAM_HANDLE ];
		$this->assertSame( array( 'wp-api-fetch' ), $script->deps );
		$this->assertStringEndsWith( '/assets/js/api-fetch-method-param.js', $script->src );
		$this->assertSame( 1, wp_scripts()->get_data( Admin::API_FETCH_METHOD_PARAM_HANDLE, 'group' ), 'shim must load in the footer, after wp-api-fetch' );
		$this->assertSame( 10, has_action( 'admin_init', array( $admin, 'register_scripts' ) ) );
	}

	/**
	 * Test the shim source ships with the plugin (assets/js is gitignored except for an allow-list).
	 */
	public function test_shim_source_file_ships_with_the_plugin(): void {
		$this->assertFileExists( PLUGIN_PATH . 'assets/js/api-fetch-method-param.js' );
	}

	/**
	 * Test the settings bundle depends on the shim.
	 */
	public function test_settings_bundle_depends_on_shim(): void {
		$settings = new Settings();

		$settings->enqueue_assets();

		$this->assertContains( Admin::API_FETCH_METHOD_PARAM_HANDLE, wp_scripts()->registered[ PLUGIN_NAME . '-settings' ]->deps );
	}

	/**
	 * Test the template gallery bundle depends on the shim.
	 */
	public function test_template_gallery_bundle_depends_on_shim(): void {
		$menu = new Menu();

		$menu->enqueue_gallery_assets();

		$this->assertContains( Admin::API_FETCH_METHOD_PARAM_HANDLE, wp_scripts()->registered['wcpos-template-gallery']->deps );
	}

	/**
	 * Test the template editor bundle depends on the shim.
	 */
	public function test_template_editor_bundle_depends_on_shim(): void {
		set_current_screen( 'wcpos_template' );
		$GLOBALS['post'] = self::factory()->post->create_and_get( array( 'post_type' => 'wcpos_template' ) );
		$single_template = new Single_Template();

		$single_template->enqueue_scripts( 'post.php' );

		$this->assertContains( Admin::API_FETCH_METHOD_PARAM_HANDLE, wp_scripts()->registered['wcpos-template-editor']->deps );
	}
}
