<?php
/**
 * Tests for the Activator class.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Mockery;
use ReflectionClass;
use WP_UnitTestCase;
use WCPOS\WooCommercePOS\Activator;

/**
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\Activator
 */
class Test_Activator extends WP_UnitTestCase {

	/**
	 * @var Activator
	 */
	private $activator;

	public function setUp(): void {
		parent::setUp();
	}

	public function tearDown(): void {
		parent::tearDown();
		Mockery::close();
	}

	/**
	 * Test that db_upgrade is deferred to woocommerce_init hook.
	 *
	 * This is critical because WooCommerce doesn't initialize WC()->order_factory
	 * until the 'init' hook. If we run migrations during 'plugins_loaded', any
	 * wp_delete_post() calls will trigger 'before_delete_post' hooks from plugins
	 * like WC Subscriptions, which assume WC is fully loaded.
	 *
	 * Timeline without this fix:
	 *   plugins_loaded -> WCPOS migration -> wp_delete_post() -> WC Subscriptions
	 *   tries WC()->order_factory->get_order() -> FATAL ERROR (order_factory is null)
	 *
	 * Timeline with this fix:
	 *   plugins_loaded -> queue migration for later
	 *   init -> WooCommerce creates order_factory
	 *   woocommerce_init -> WCPOS migration runs safely
	 *
	 * @see https://github.com/wcpos/woocommerce-pos/issues/440
	 *
	 * @covers ::version_check
	 */
	public function test_db_upgrade_is_deferred_to_woocommerce_init(): void {
		// Remove any existing woocommerce_init hooks from the activator.
		remove_all_actions( 'woocommerce_init' );

		// Create a fresh Activator instance.
		$activator = new Activator();

		// Use reflection to access the private version_check method.
		$reflection = new ReflectionClass( $activator );

		// Mock get_db_version to return an old version to trigger upgrade path.
		$old_version = '1.0.0';
		update_option( 'woocommerce_pos_db_version', $old_version );

		// Call version_check which should defer db_upgrade to woocommerce_init.
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$version_check->invoke( $activator );

		// Verify that a callback was added to woocommerce_init.
		$this->assertTrue(
			has_action( 'woocommerce_init' ) !== false,
			'db_upgrade should be hooked to woocommerce_init when an upgrade is needed'
		);
	}

	/**
	 * Test that db_upgrade does NOT run immediately during version_check.
	 *
	 * This ensures the migration is deferred rather than executed inline,
	 * which would cause the WC Subscriptions conflict described in issue #440.
	 *
	 * @covers ::version_check
	 */
	public function test_db_upgrade_does_not_run_immediately(): void {
		// Track if any update files are included.
		$update_ran = false;

		// Create a test update file that sets a flag when run.
		$test_version    = '99.99.99';
		$test_update_dir = WCPOS_PLUGIN_PATH . 'includes/updates/';

		// We can't easily test this without modifying the actual update array,
		// but we CAN verify that after version_check, no posts have been deleted
		// (which would indicate the migration ran inline).

		// Create a test template post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'wcpos_template',
				'post_status' => 'publish',
				'post_title'  => 'Test Template for Migration',
			)
		);
		add_post_meta( $post_id, '_template_plugin', '1' );

		// Set an old version to trigger the 1.8.7 migration.
		update_option( 'woocommerce_pos_db_version', '1.8.6' );

		// Remove existing hooks and create fresh activator.
		remove_all_actions( 'woocommerce_init' );
		$activator = new Activator();

		// Call version_check.
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$version_check->invoke( $activator );

		// The post should still exist because migration is deferred.
		$post = get_post( $post_id );
		$this->assertNotNull(
			$post,
			'Template post should still exist after version_check because db_upgrade is deferred to woocommerce_init'
		);

		// Now trigger woocommerce_init which should run the deferred migration.
		do_action( 'woocommerce_init' );

		// After woocommerce_init, the migration should have deleted the post.
		$post_after = get_post( $post_id );
		$this->assertNull(
			$post_after,
			'Template post should be deleted after woocommerce_init runs the deferred migration'
		);
	}

	/**
	 * Test that no migration is queued when versions match (no upgrade needed).
	 *
	 * @covers ::version_check
	 */
	public function test_no_migration_queued_when_version_matches(): void {
		// Set current version so no upgrade is needed.
		update_option( 'woocommerce_pos_db_version', \WCPOS\WooCommercePOS\VERSION );

		// Remove existing hooks and create fresh activator.
		remove_all_actions( 'woocommerce_init' );
		$activator = new Activator();

		// Call version_check.
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$version_check->invoke( $activator );

		// No callback should be added to woocommerce_init for db_upgrade.
		// Note: There might be other hooks, so we check the specific callback isn't there.
		// Since we're using an anonymous function, we can only check if ANY action exists.
		// For a thorough test, we'd need to refactor to use a named method.

		// For now, we verify the priority count is 0 (no hooks added).
		global $wp_filter;
		$hook_count = isset( $wp_filter['woocommerce_init'] ) ? count( $wp_filter['woocommerce_init']->callbacks ) : 0;

		$this->assertEquals(
			0,
			$hook_count,
			'No migration should be queued when db version matches current version'
		);
	}
}
