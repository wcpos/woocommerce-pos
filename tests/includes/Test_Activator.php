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
	 * Role capabilities fingerprint option key.
	 */
	private const ROLE_CAPS_FINGERPRINT_OPTION = 'woocommerce_pos_role_caps_fingerprint';

	/**
	 * Reset options and hooks before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::DB_UPGRADE_LOCK_OPTION );
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, $this->role_caps_fingerprint(), false );
		// These tests pin the PLUGIN-version upgrade mechanics. Latch the sync
		// schema so an unlatched sync store does not co-trigger version_check —
		// the sync-triggered path has its own coverage in Sync\Test_Sync_Install.
		update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, false );
		remove_all_actions( 'woocommerce_init' );
		remove_all_actions( 'shutdown' );
	}

	/**
	 * Reset options and hooks after each test.
	 */
	public function tearDown(): void {
		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::DB_UPGRADE_LOCK_OPTION );
		delete_option( self::ROLE_CAPS_FINGERPRINT_OPTION );
		remove_all_actions( 'init' );
		remove_all_actions( 'woocommerce_init' );
		remove_all_actions( 'shutdown' );
		unset( $GLOBALS['wp_user_roles'] );
		global $wp_roles;
		$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring role state after capability tests.
		parent::tearDown();
	}

	/**
	 * Cashiers can create and edit catalog records by default, but cannot delete them.
	 *
	 * @covers ::create_pos_roles
	 */
	public function test_cashier_role_defaults_allow_catalog_create_and_edit_without_delete(): void {
		remove_role( 'cashier' );

		$activator       = new Activator();
		$reflection      = new ReflectionClass( $activator );
		$create_pos_roles = $reflection->getMethod( 'create_pos_roles' );
		$create_pos_roles->setAccessible( true );
		$create_pos_roles->invoke( $activator );

		$role = get_role( 'cashier' );
		$this->assertNotNull( $role );
		foreach (
			array(
				'publish_products',
				'edit_product',
				'edit_products',
				'edit_published_products',
				'edit_private_products',
				'edit_others_products',
				'publish_shop_coupons',
				'edit_shop_coupons',
				'edit_published_shop_coupons',
				'edit_private_shop_coupons',
				'edit_others_shop_coupons',
			) as $capability
		) {
			$this->assertTrue( $role->has_cap( $capability ), $capability );
		}

		foreach ( array_keys( $role->capabilities ) as $capability ) {
			$this->assertFalse( 0 === strpos( $capability, 'delete_' ), $capability );
		}
	}

	/**
	 * Re-syncing the cashier role removes the obsolete customer-create capability.
	 *
	 * @covers ::create_pos_roles
	 */
	public function test_cashier_role_resync_removes_obsolete_customer_create_capability(): void {
		// Arrange.
		$active_capability   = version_compare( WC_VERSION, '9.9', '>=' ) ? 'create_customers' : 'promote_users';
		$obsolete_capability = 'create_customers' === $active_capability ? 'promote_users' : 'create_customers';
		$cashier             = get_role( 'cashier' );
		$this->assertNotNull( $cashier );
		$cashier->add_cap( $obsolete_capability );

		// Act.
		( new Activator() )->single_activate( false );

		// Assert.
		$cashier = get_role( 'cashier' );
		$this->assertNotNull( $cashier );
		$this->assertTrue( $cashier->has_cap( $active_capability ) );
		$this->assertFalse( $cashier->has_cap( $obsolete_capability ) );
	}

	/**
	 * A failed role-option write leaves the fingerprint stale so activation retries.
	 *
	 * @covers ::single_activate
	 */
	public function test_single_activate_when_role_write_fails_does_not_update_fingerprint(): void {
		// Arrange.
		$activator = new Activator();
		$activator->single_activate( false );
		$cashier = get_role( 'cashier' );
		$this->assertNotNull( $cashier );
		$cashier->remove_cap( 'manage_product_terms' );
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, 'stale' );

		$reject_role_update = static function ( $value, $old_value ) {
			return $old_value;
		};
		$role_option        = wp_roles()->role_key;
		add_filter( "pre_update_option_{$role_option}", $reject_role_update, 10, 2 );

		// Act.
		$activator->single_activate( false );
		remove_filter( "pre_update_option_{$role_option}", $reject_role_update, 10 );

		// Assert.
		$this->assertEquals( 'stale', get_option( self::ROLE_CAPS_FINGERPRINT_OPTION ) );
	}

	/**
	 * A stale role-capabilities fingerprint re-syncs roles without a version bump.
	 *
	 * @covers ::role_caps_fingerprint
	 * @covers ::version_check
	 */
	public function test_role_caps_fingerprint_mismatch_with_current_version_resyncs_caps_and_updates_fingerprint(): void {
		// Arrange.
		remove_all_actions( 'init' );
		$activator = new Activator();
		$activator->single_activate( false );
		$cashier = get_role( 'cashier' );
		$this->assertNotNull( $cashier );
		$cashier->remove_cap( 'manage_product_terms' );
		update_option( self::DB_VERSION_OPTION, \WCPOS\WooCommercePOS\VERSION );
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, 'stale' );

		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );

		// Act.
		$version_check->invoke( $activator );
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress lifecycle hook under test.

		// Assert.
		$cashier = get_role( 'cashier' );
		$this->assertNotNull( $cashier );
		$definition = $reflection->getMethod( 'role_capability_definition' );
		$definition->setAccessible( true );
		foreach ( array_keys( $definition->invoke( null )['cashier'] ) as $capability ) {
			$this->assertTrue( $cashier->has_cap( $capability ), $capability );
		}
		$this->assertEquals(
			$this->role_caps_fingerprint(),
			get_option( self::ROLE_CAPS_FINGERPRINT_OPTION )
		);
	}

	/**
	 * Current versions and role-capabilities fingerprint do not schedule a re-sync.
	 *
	 * @covers ::role_caps_fingerprint
	 * @covers ::version_check
	 */
	public function test_role_caps_fingerprint_match_with_current_version_does_not_schedule_resync(): void {
		// Arrange.
		remove_all_actions( 'init' );
		$activator = new Activator();
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, 'stale' );
		$activator->single_activate( false );
		$cashier = get_role( 'cashier' );
		$this->assertNotNull( $cashier );
		$cashier->remove_cap( 'manage_product_terms' );
		update_option( self::DB_VERSION_OPTION, \WCPOS\WooCommercePOS\VERSION );

		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );

		// Act.
		$version_check->invoke( $activator );

		// Assert.
		$this->assertFalse( has_action( 'init' ) );
		$this->assertFalse( $cashier->has_cap( 'manage_product_terms' ) );
	}

	/**
	 * The role-capabilities fingerprint is stable and sensitive to definition changes.
	 *
	 * @covers ::role_capability_definition
	 * @covers ::role_caps_fingerprint
	 */
	public function test_role_caps_fingerprint_when_definition_changes_returns_different_value(): void {
		// Arrange.
		$activator         = ( new ReflectionClass( Activator::class ) )->newInstanceWithoutConstructor();
		$reflection        = new ReflectionClass( $activator );
		$definition        = $reflection->getMethod( 'role_capability_definition' );
		$definition->setAccessible( true );
		$role_capabilities = $definition->invoke( null );

		// Act.
		$changed_role_capabilities = $role_capabilities;
		$changed_role_capabilities['cashier']['fingerprint_test_cap'] = true;

		// Assert.
		$this->assertEquals(
			md5( wp_json_encode( $role_capabilities ) ),
			$this->role_caps_fingerprint()
		);
		$this->assertNotEquals(
			$this->role_caps_fingerprint(),
			md5( wp_json_encode( $changed_role_capabilities ) )
		);
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

	/**
	 * Test: shutdown fallback releases upgrade lock when woocommerce_init does not fire.
	 *
	 * @covers ::version_check
	 */
	public function test_shutdown_fallback_releases_upgrade_lock_when_migration_does_not_run(): void {
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

		do_action( 'shutdown' );

		$this->assertFalse(
			get_option( self::DB_UPGRADE_LOCK_OPTION, false ),
			'Shutdown fallback should release the upgrade lock if migration never runs'
		);
	}

	/**
	 * Get the current role-capabilities fingerprint without registering hooks.
	 */
	private function role_caps_fingerprint(): string {
		$reflection = new ReflectionClass( Activator::class );
		$method     = $reflection->getMethod( 'role_caps_fingerprint' );
		$method->setAccessible( true );

		return $method->invoke( $reflection->newInstanceWithoutConstructor() );
	}
}
