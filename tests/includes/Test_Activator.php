<?php
/**
 * Tests for the Activator class.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use ReflectionClass;
use WP_UnitTestCase;
use WCPOS\WooCommercePOS\Activator;

/**
 * Tests for Activator behavior.
 *
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\Activator
 */
class Test_Activator extends WP_UnitTestCase {
	/**
	 * DB version option key.
	 */
	private const DB_VERSION_OPTION = 'woocommerce_pos_db_version';

	/**
	 * DB upgrade lock option key (WP_Upgrader appends ".lock").
	 */
	private const DB_UPGRADE_LOCK_OPTION = 'woocommerce_pos_db_upgrade_lock.lock';

	/**
	 * Reset options and hooks before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::DB_UPGRADE_LOCK_OPTION );
		remove_all_actions( 'woocommerce_init' );
	}

	/**
	 * Reset options and hooks after each test.
	 */
	public function tearDown(): void {
		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::DB_UPGRADE_LOCK_OPTION );
		remove_all_actions( 'woocommerce_init' );
		parent::tearDown();
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

		// Set an old version to trigger the upgrade path.
		update_option( self::DB_VERSION_OPTION, '1.0.0' );

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
		update_option( self::DB_VERSION_OPTION, '1.8.6' );

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
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WooCommerce hook.
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
		update_option( self::DB_VERSION_OPTION, \WCPOS\WooCommercePOS\VERSION );

		// Remove existing hooks and create fresh activator.
		remove_all_actions( 'woocommerce_init' );
		$activator = new Activator();

		// Call version_check.
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$version_check->invoke( $activator );

		// Verify no hooks were added to woocommerce_init.
		global $wp_filter;
		$hook_count = isset( $wp_filter['woocommerce_init'] ) ? count( $wp_filter['woocommerce_init']->callbacks ) : 0;

		$this->assertEquals(
			0,
			$hook_count,
			'No migration should be queued when db version matches current version'
		);
	}

	/**
	 * Test: no migration is queued if a fresh upgrade lock exists.
	 *
	 * @covers ::version_check
	 */
	public function test_no_migration_queued_when_fresh_upgrade_lock_exists(): void {
		update_option( self::DB_VERSION_OPTION, '1.8.6' );
		update_option( self::DB_UPGRADE_LOCK_OPTION, time(), false );

		$activator     = new Activator();
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$version_check->invoke( $activator );

		$this->assertFalse(
			has_action( 'woocommerce_init' ),
			'No migration should be queued while a fresh upgrade lock exists'
		);
	}

	/**
	 * Test: migration is queued when upgrade lock has expired.
	 *
	 * @covers ::version_check
	 */
	public function test_migration_queued_when_upgrade_lock_has_expired(): void {
		update_option( self::DB_VERSION_OPTION, '1.8.6' );
		update_option( self::DB_UPGRADE_LOCK_OPTION, time() - 601, false );

		$activator     = new Activator();
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$version_check->invoke( $activator );

		$this->assertTrue(
			has_action( 'woocommerce_init' ) !== false,
			'Migration should be queued when upgrade lock has expired'
		);
	}

	/**
	 * Test: an upgrade lock is set when migration is queued.
	 *
	 * @covers ::version_check
	 */
	public function test_upgrade_lock_is_set_when_migration_is_queued(): void {
		update_option( self::DB_VERSION_OPTION, '1.8.6' );

		$activator     = new Activator();
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$version_check->invoke( $activator );

		$this->assertGreaterThan(
			0,
			(int) get_option( self::DB_UPGRADE_LOCK_OPTION, 0 ),
			'Migration queueing should set an upgrade lock'
		);
	}
}
