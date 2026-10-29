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
	 * Last synced role capability names.
	 */
	private const ROLE_CAPS_SYNCED_OPTION = 'woocommerce_pos_role_caps_synced';

	/**
	 * Free's register option.
	 */
	private const REGISTER_OPTION = 'wcpos_register';

	/**
	 * Reset options and hooks before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::DB_UPGRADE_LOCK_OPTION );
		// Steady state: fingerprint current and a snapshot present. A missing
		// snapshot is itself a reason to sync, so tests that want that delete it.
		update_option(
			self::ROLE_CAPS_SYNCED_OPTION,
			array(
				'cashier'       => array(),
				'administrator' => array(),
				'shop_manager'  => array(),
			),
			false
		);
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
		delete_option( self::ROLE_CAPS_SYNCED_OPTION );
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
				'manage_woocommerce_pos_cash',
				'view_woocommerce_pos_reports',
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
		delete_option( self::ROLE_CAPS_SYNCED_OPTION );
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
		foreach ( array( 'administrator', 'shop_manager' ) as $slug ) {
			foreach ( array( 'manage_woocommerce_pos_cash', 'view_woocommerce_pos_reports', 'manage_woocommerce_pos_closures', 'reassign_woocommerce_pos_sales' ) as $capability ) {
				$this->assertTrue( get_role( $slug )->has_cap( $capability ), $capability );
			}
		}
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
	 * Upgrades preserve capability removals made by the merchant.
	 *
	 * @covers ::version_check
	 */
	public function test_version_upgrade_does_not_regrant_capability_removed_from_cashier_role(): void {
		// Arrange.
		remove_all_actions( 'init' );
		$activator = new Activator();
		$activator->single_activate( false );
		get_role( 'cashier' )->remove_cap( 'edit_products' );
		update_option( self::DB_VERSION_OPTION, '1.10.0' );
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, $this->role_caps_fingerprint() );
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );
		$definition = $reflection->getMethod( 'role_capability_definition' );
		$definition->setAccessible( true );

		// Act.
		$version_check->invoke( $activator );
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress lifecycle hook under test.

		// Assert.
		$this->assertFalse( get_role( 'cashier' )->has_cap( 'edit_products' ) );
		$this->assertSame( $this->role_caps_fingerprint(), get_option( self::ROLE_CAPS_FINGERPRINT_OPTION ) );
		$this->assertSame(
			array_merge( array( 'access_woocommerce_pos' ), array_keys( $definition->invoke( null )['cashier'] ) ),
			get_option( self::ROLE_CAPS_SYNCED_OPTION )['cashier']
		);
	}

	/**
	 * Upgrades grant new defaults without repairing merchant-removed defaults.
	 *
	 * @covers ::version_check
	 */
	public function test_version_upgrade_grants_capability_new_to_the_definition(): void {
		// Arrange.
		remove_all_actions( 'init' );
		$activator = new Activator();
		$activator->single_activate( false );
		$synced            = get_option( self::ROLE_CAPS_SYNCED_OPTION );
		$synced['cashier'] = array_values( array_diff( $synced['cashier'], array( 'manage_product_terms' ) ) );
		update_option( self::ROLE_CAPS_SYNCED_OPTION, $synced );
		get_role( 'cashier' )->remove_cap( 'manage_product_terms' );
		get_role( 'cashier' )->remove_cap( 'edit_products' );
		update_option( self::DB_VERSION_OPTION, '1.10.0' );
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, $this->role_caps_fingerprint() );
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );

		// Act.
		$version_check->invoke( $activator );
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress lifecycle hook under test.

		// Assert.
		$this->assertTrue( get_role( 'cashier' )->has_cap( 'manage_product_terms' ) );
		$this->assertFalse( get_role( 'cashier' )->has_cap( 'edit_products' ) );
	}

	/**
	 * A matching legacy fingerprint seeds the snapshot without granting capabilities.
	 *
	 * @covers ::version_check
	 */
	public function test_version_upgrade_without_synced_option_and_matching_fingerprint_grants_nothing(): void {
		// Arrange.
		remove_all_actions( 'init' );
		$activator = new Activator();
		$activator->single_activate( false );
		delete_option( self::ROLE_CAPS_SYNCED_OPTION );
		get_role( 'cashier' )->remove_cap( 'edit_products' );
		update_option( self::DB_VERSION_OPTION, '1.10.0' );
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, $this->role_caps_fingerprint() );
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );

		// Act.
		$version_check->invoke( $activator );
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress lifecycle hook under test.

		// Assert.
		$this->assertFalse( get_role( 'cashier' )->has_cap( 'edit_products' ) );
		$this->assertArrayHasKey( 'cashier', get_option( self::ROLE_CAPS_SYNCED_OPTION ) );
	}

	/**
	 * A cashier role deleted before an upgrade is recreated whole, access gate included.
	 *
	 * @covers ::create_pos_roles
	 */
	public function test_version_upgrade_recreates_missing_cashier_role_with_access_capability(): void {
		// Arrange.
		remove_all_actions( 'init' );
		$activator = new Activator();
		$activator->single_activate( false );
		remove_role( 'cashier' );
		$this->assertNull( get_role( 'cashier' ) );
		update_option( self::DB_VERSION_OPTION, '1.10.0' );
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, $this->role_caps_fingerprint() );
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );

		// Act.
		$version_check->invoke( $activator );
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress lifecycle hook under test.

		// Assert.
		$cashier = get_role( 'cashier' );
		$this->assertNotNull( $cashier );
		$this->assertTrue( $cashier->has_cap( 'access_woocommerce_pos' ) );
		$this->assertTrue( $cashier->has_cap( 'edit_products' ) );
	}

	/**
	 * A missing snapshot schedules the sync on its own and is seeded without granting.
	 *
	 * @covers ::version_check
	 */
	public function test_version_check_with_missing_synced_option_schedules_sync_and_seeds_it(): void {
		// Arrange.
		remove_all_actions( 'init' );
		$activator = new Activator();
		$activator->single_activate( false );
		delete_option( self::ROLE_CAPS_SYNCED_OPTION );
		get_role( 'cashier' )->remove_cap( 'edit_products' );
		update_option( self::DB_VERSION_OPTION, \WCPOS\WooCommercePOS\VERSION );
		update_option( self::ROLE_CAPS_FINGERPRINT_OPTION, $this->role_caps_fingerprint() );
		$reflection    = new ReflectionClass( $activator );
		$version_check = $reflection->getMethod( 'version_check' );
		$version_check->setAccessible( true );

		// Act.
		$version_check->invoke( $activator );
		$scheduled = has_action( 'init' );
		do_action( 'init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress lifecycle hook under test.

		// Assert.
		$this->assertNotFalse( $scheduled );
		$this->assertArrayHasKey( 'cashier', get_option( self::ROLE_CAPS_SYNCED_OPTION ) );
		$this->assertFalse( get_role( 'cashier' )->has_cap( 'edit_products' ) );
	}

	/**
	 * Explicit activation remains the full capability repair path.
	 *
	 * @covers ::single_activate
	 */
	public function test_activation_regrants_capability_removed_from_cashier_role(): void {
		// Arrange.
		$activator = new Activator();
		$activator->single_activate( false );
		get_role( 'cashier' )->remove_cap( 'edit_products' );

		// Act.
		$activator->single_activate( false );

		// Assert.
		$this->assertTrue( get_role( 'cashier' )->has_cap( 'edit_products' ) );
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

		do_action( 'shutdown' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress lifecycle.

		$this->assertFalse(
			get_option( self::DB_UPGRADE_LOCK_OPTION, false ),
			'Shutdown fallback should release the upgrade lock if migration never runs'
		);
	}

	/** Both activation and the versioned upgrade install the missing session table, and never a movements table. */
	public function test_activation_and_upgrade_install_session_table_without_movement_table(): void {
		global $wpdb;
		$stores = array( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() );
		$legacy = $wpdb->prefix . \WCPOS\WooCommercePOS\Services\Cash_Movement_Store::LEGACY_TABLE;
		$activator = new Activator();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			foreach ( array( 'activation', 'upgrade' ) as $path ) {
				foreach ( $stores as $store ) {
					$wpdb->query( 'DROP TABLE IF EXISTS ' . $store->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test-owned table.
					$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $store->table_name() ) );
				}
				if ( 'activation' === $path ) {
					// single_activate( false ) skips the sync schema; activation installs through this.
					$activator->install_sync_schema();
				} else {
					// db_upgrade() at the current version includes no update file (each was
					// already included once in this process) but still runs the table installs.
					$method = ( new ReflectionClass( $activator ) )->getMethod( 'db_upgrade' );
					$method->setAccessible( true );
					$method->invoke( $activator, \WCPOS\WooCommercePOS\VERSION, \WCPOS\WooCommercePOS\VERSION );
				}
				foreach ( $stores as $store ) {
					$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $store->table_name() ), $path );
				}
				$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $legacy ), $path );
			}
		} finally {
			foreach ( $stores as $store ) {
				$store->install();
			}
		}
	}

	/** Schema 10 on a schema-9 install copies both legacy closures, drops the table and latches. */
	public function test_closure_fold_on_schema_nine_install_copies_closures_and_drops_legacy_table(): void {
		// Arrange.
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$closures = new \WCPOS\WooCommercePOS\Services\Closure_Store();
			$this->assertSame(
				array(
					'id' => $fixture['closures'][0],
					'register_id' => $fixture['register_id'],
					'session_id' => $fixture['sessions'][0],
					'store_id' => 456,
					'number' => 1,
					'printed_number' => null,
					'opened_at_gmt' => '2026-09-11 08:00:00',
					'business_day' => '2026-09-11',
					// The row reads the session's close; the till's later claim stays in the document.
					'closed_at_gmt' => '2026-09-11 12:00:00',
					'opened_by' => 1,
					'closed_by' => 1,
					'approved_by' => null,
					'expected' => array( 'cash' => '150.0000' ),
					'till_expected' => array( 'cash' => '100.0000' ),
					'counted' => array( 'cash' => '101.0000' ),
					'variance' => array( 'cash' => '-49.0000' ),
					'period_sales_total' => '80.0000',
					'period_refunds_total' => '0.0000',
					'perpetual_sales_total' => '80.0000',
					'perpetual_refunds_total' => '0.0000',
					'unsynced_count' => 0,
					'unsynced_total' => '0.0000',
					'first_sale_counter' => null,
					'last_sale_counter' => null,
					'first_receipt_id' => null,
					'last_receipt_id' => null,
					'software_version' => 'fixture',
					'printed_at_gmt' => null,
					'breakdowns' => array( 'transaction_count' => 0 ),
					'findings' => null,
					'print_count' => 2,
					'last_printed_at_gmt' => '2026-09-11 12:30:00',
					'received_at_gmt' => '2026-09-11 13:00:05',
					'till_opened_at_gmt' => null,
					'till_closed_at_gmt' => '2026-09-11 13:00:00',
				),
				$closures->get( $fixture['closures'][0] )
			);
			$second = $closures->for_session( $fixture['sessions'][1] );
			$this->assertSame( $fixture['closures'][1], $second['id'] );
			$this->assertSame( 2, $second['number'] );
			$this->assertSame( 1, $second['printed_number'] );
			$this->assertSame( 0, $second['print_count'] );
			$this->assertSame( 2, $closures->last_number( $fixture['register_id'] ) );
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
			$document = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT closure FROM {$fixture['sessions_table']} WHERE id = %s", $fixture['sessions'][0] ) ), true );
			// Nested figures are objects in the document, never double-encoded strings.
			$this->assertSame( array( 'cash' => '150.0000' ), $document['expected'] );
		} finally {
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A run stopped after the rename resumes from the renamed table: no DDL, the straggler is copied, nothing else changes. */
	public function test_closure_fold_rerun_resumes_from_renamed_table(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$folded = $fixture['legacy'] . '_folded';
		$activator = new Activator();
		$fail_drop = static function ( $sql ) use ( $folded ) {
			return "DROP TABLE IF EXISTS {$folded}" === $sql ? 'INVALID DROP' : $sql;
		};
		$statements = array();
		$observe = static function ( $sql ) use ( $fixture, &$statements ) {
			if ( 0 === strpos( $sql, "ALTER TABLE {$fixture['sessions_table']}" ) || 0 === strpos( $sql, 'RENAME TABLE' ) ) {
				$statements[] = $sql;
			}
			return $sql;
		};
		$previous = $wpdb->suppress_errors();
		try {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			add_filter( 'query', $fail_drop );
			try {
				$activator->install_sync_schema();
				$this->fail( 'Expected the drop to fail.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold drop failed.', $error->getMessage() );
			}
			remove_filter( 'query', $fail_drop );
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$closures = new \WCPOS\WooCommercePOS\Services\Closure_Store();
			$before = array_map( array( $closures, 'get' ), $fixture['closures'] );
			$straggler = $this->add_legacy_closure( $fixture, $folded, 3 );

			// Act.
			add_filter( 'query', $observe );
			$activator->install_sync_schema();
			remove_filter( 'query', $observe );

			// Assert.
			$this->assertSame( array(), $statements );
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $folded ) );
			$this->assertSame( $before, array_map( array( $closures, 'get' ), $fixture['closures'] ) );
			$this->assertSame( 3, $closures->get( $straggler )['number'] );
		} finally {
			remove_filter( 'query', $fail_drop );
			remove_filter( 'query', $observe );
			$wpdb->suppress_errors( $previous );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A closure a pre-fold request commits after the first copy still lands on its session row. */
	public function test_closure_fold_copies_a_closure_committed_after_the_first_pass(): void {
		// Arrange.
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$straggler = null;
		$insert = function ( $sql ) use ( $fixture, &$straggler ) {
			if ( null === $straggler && 0 === strpos( $sql, "RENAME TABLE {$fixture['legacy']}" ) ) {
				$straggler = '';
				$straggler = $this->add_legacy_closure( $fixture, $fixture['legacy'], 3 );
			}
			return $sql;
		};
		add_filter( 'query', $insert );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			( new Activator() )->install_sync_schema();
			remove_filter( 'query', $insert );

			// Assert.
			$this->assertNotEmpty( $straggler );
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $straggler );
			$this->assertSame( 3, $closure['number'] );
			$this->assertSame( '80.0000', $closure['perpetual_sales_total'] );
		} finally {
			remove_filter( 'query', $insert );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A print a pre-fold request records after the first copy still reaches the session row. */
	public function test_closure_fold_keeps_a_print_recorded_after_the_first_pass(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$bumped = false;
		$bump = function ( $sql ) use ( $fixture, &$bumped ) {
			if ( ! $bumped && 0 === strpos( $sql, "RENAME TABLE {$fixture['legacy']}" ) ) {
				$bumped = true;
				$this->record_fixture_print( $fixture['legacy'], 'id', $fixture['closures'][0], '2026-09-11 14:00:00' );
			}
			return $sql;
		};
		add_filter( 'query', $bump );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			( new Activator() )->install_sync_schema();
			remove_filter( 'query', $bump );

			// Assert.
			$this->assertTrue( $bumped );
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $fixture['closures'][0] );
			$this->assertSame( 3, $closure['print_count'] );
			$this->assertSame( '2026-09-11 14:00:00', $closure['last_printed_at_gmt'] );
		} finally {
			remove_filter( 'query', $bump );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A pre-fold print on the legacy row and a new-code print on the copied session row both count. */
	public function test_closure_fold_with_prints_on_both_tables_after_the_first_pass_keeps_both(): void {
		// Arrange.
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$bumped = false;
		$bump = function ( $sql ) use ( $fixture, &$bumped ) {
			if ( ! $bumped && 0 === strpos( $sql, "RENAME TABLE {$fixture['legacy']}" ) ) {
				$bumped = true;
				$this->record_fixture_print( $fixture['sessions_table'], 'closure_id', $fixture['closures'][0], '2026-09-11 14:00:00' );
				$this->record_fixture_print( $fixture['legacy'], 'id', $fixture['closures'][0], '2026-09-11 14:30:00' );
			}
			return $sql;
		};
		add_filter( 'query', $bump );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			( new Activator() )->install_sync_schema();
			remove_filter( 'query', $bump );

			// Assert.
			$this->assertTrue( $bumped );
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $fixture['closures'][0] );
			$this->assertSame( 4, $closure['print_count'] );
			$this->assertSame( '2026-09-11 14:30:00', $closure['last_printed_at_gmt'] );
		} finally {
			remove_filter( 'query', $bump );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A run whose drop fails has already moved the late print; the re-run adds nothing more. */
	public function test_closure_fold_rerun_after_failed_drop_does_not_double_count_prints(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$folded = $fixture['legacy'] . '_folded';
		$bumped = false;
		$interrupt = function ( $sql ) use ( $fixture, $folded, &$bumped ) {
			if ( ! $bumped && 0 === strpos( $sql, "RENAME TABLE {$fixture['legacy']}" ) ) {
				$bumped = true;
				$this->record_fixture_print( $fixture['legacy'], 'id', $fixture['closures'][0], '2026-09-11 14:00:00' );
			}
			return "DROP TABLE IF EXISTS {$folded}" === $sql ? 'INVALID DROP' : $sql;
		};
		$activator = new Activator();
		$closures = new \WCPOS\WooCommercePOS\Services\Closure_Store();
		$previous = $wpdb->suppress_errors();
		try {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			add_filter( 'query', $interrupt );
			try {
				$activator->install_sync_schema();
				$this->fail( 'Expected the drop to fail.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold drop failed.', $error->getMessage() );
			}
			remove_filter( 'query', $interrupt );
			$after_failed_drop = $closures->get( $fixture['closures'][0] )['print_count'];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table; the id is prepared.
			$left_on_folded = $wpdb->get_var( $wpdb->prepare( "SELECT print_count FROM {$folded} WHERE id = %s", $fixture['closures'][0] ) );

			// Act.
			$activator->install_sync_schema();

			// Assert.
			$this->assertTrue( $bumped );
			$this->assertSame( 3, $after_failed_drop );
			$this->assertSame( '0', $left_on_folded );
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertSame( 3, $closures->get( $fixture['closures'][0] )['print_count'] );
		} finally {
			remove_filter( 'query', $interrupt );
			$wpdb->suppress_errors( $previous );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A failed print subtraction rolls back its session write, so the re-run copies the count once. */
	public function test_closure_fold_failed_print_subtraction_rolls_back_the_copy(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$failed = false;
		$fail_subtract = static function ( $sql ) use ( $fixture, &$failed ) {
			if ( ! $failed && 0 === strpos( $sql, "UPDATE {$fixture['legacy']} SET print_count = print_count -" ) ) {
				$failed = true;
				return 'INVALID SUBTRACT';
			}
			return $sql;
		};
		$activator = new Activator();
		$previous = $wpdb->suppress_errors();
		try {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			add_filter( 'query', $fail_subtract );
			try {
				$activator->install_sync_schema();
				$this->fail( 'Expected the subtraction to fail.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold write failed.', $error->getMessage() );
			}
			remove_filter( 'query', $fail_subtract );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table; the id is prepared.
			$number = $wpdb->get_var( $wpdb->prepare( "SELECT number FROM {$fixture['sessions_table']} WHERE id = %s", $fixture['sessions'][0] ) );

			// Act.
			$activator->install_sync_schema();

			// Assert.
			$this->assertNull( $number );
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertSame( 2, ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $fixture['closures'][0] )['print_count'] );
		} finally {
			remove_filter( 'query', $fail_subtract );
			$wpdb->suppress_errors( $previous );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** Both names present (a run stopped after the rename, then the table came back): the run finishes both and latches. */
	public function test_closure_fold_with_both_legacy_names_finishes_and_latches(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$folded = $fixture['legacy'] . '_folded';
		$this->assertNotFalse( $wpdb->query( "RENAME TABLE {$fixture['legacy']} TO {$folded}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables.
		$this->create_legacy_table( $fixture['legacy'] );
		$straggler = $this->add_legacy_closure( $fixture, $folded, 3 );
		$this->assertNotFalse( $wpdb->query( "INSERT INTO {$fixture['legacy']} SELECT * FROM {$folded} WHERE id = '{$straggler}'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables, generated UUID.
		$this->assertNotFalse( $wpdb->query( "DELETE FROM {$folded} WHERE id = '{$straggler}'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, generated UUID.
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $folded ) );
			$closures = new \WCPOS\WooCommercePOS\Services\Closure_Store();
			$this->assertSame( array( 1, 2, 3 ), array_column( array_map( array( $closures, 'get' ), array_merge( $fixture['closures'], array( $straggler ) ) ), 'number' ) );
		} finally {
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A legacy table recreated during the final drop holds the latch; the next run completes. */
	public function test_closure_fold_with_recreated_legacy_table_keeps_latch_then_completes(): void {
		// Arrange.
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$folded = $fixture['legacy'] . '_folded';
		$recreated = false;
		$recreate = function ( $sql ) use ( $fixture, $folded, &$recreated ) {
			if ( ! $recreated && "DROP TABLE IF EXISTS {$folded}" === $sql ) {
				$recreated = true;
				$this->create_legacy_table( $fixture['legacy'] );
			}
			return $sql;
		};
		add_filter( 'query', $recreate );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the recreated table to hold the latch.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold found a recreated legacy table.', $error->getMessage() );
			}
			remove_filter( 'query', $recreate );

			// Assert.
			$this->assertTrue( $recreated );
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			( new Activator() )->install_sync_schema();
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertSame( 2, ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $fixture['closures'][1] )['number'] );
		} finally {
			remove_filter( 'query', $recreate );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A table recreated after the fold but before the latch write restores the old latch; the next run copies its closure and latches. */
	public function test_closure_fold_recreated_between_fold_and_latch_restores_latch_then_completes(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$source = $fixture['legacy'] . '_source';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables, generated UUID.
		$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$source} SELECT * FROM {$fixture['legacy']} WHERE id = '{$fixture['closures'][0]}'" ) );
		$straggler = null;
		$recreate = function ( $sql ) use ( $fixture, $source, &$straggler ) {
			if ( null === $straggler && self::writes_latest_schema_latch( $sql ) ) {
				$this->create_legacy_table( $fixture['legacy'] );
				$straggler = $this->add_legacy_closure( $fixture, $fixture['legacy'], 3, $source );
			}
			return $sql;
		};
		add_filter( 'query', $recreate );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the recreated table to restore the latch.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold found a recreated legacy table.', $error->getMessage() );
			}
			remove_filter( 'query', $recreate );

			// Assert.
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$fixture['legacy']}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			( new Activator() )->install_sync_schema();
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertSame( 3, ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $straggler )['number'] );
		} finally {
			remove_filter( 'query', $recreate );
			$wpdb->query( "DROP TABLE IF EXISTS {$source}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A latch rollback the options table refuses is retried once, then logged as an error; the run still throws. */
	public function test_closure_fold_failed_latch_rollback_retries_logs_error_and_throws(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$recreated = false;
		$refused = 0;
		$refuse = function ( $sql ) use ( $fixture, &$recreated, &$refused ) {
			if ( ! $recreated && self::writes_latest_schema_latch( $sql ) ) {
				$recreated = true;
				$this->create_legacy_table( $fixture['legacy'] );
			} elseif ( $recreated && false !== strpos( $sql, $GLOBALS['wpdb']->options ) && false !== strpos( $sql, "'" . \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION . "'" ) && false !== strpos( $sql, "`option_value` = '9'" ) ) {
				++$refused;
				return 'INVALID LATCH RESTORE';
			}
			return $sql;
		};
		$logs = array();
		$capture = static function ( $enabled, $message ) use ( &$logs ) {
			if ( 'error' === \WCPOS\WooCommercePOS\Logger::$log_level ) {
				$logs[] = $message;
			}
			return false;
		};
		\WCPOS\WooCommercePOS\Logger::reset_dedup_state();
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $refuse );
		add_filter( 'wcpos_logging', $capture, 10, 2 );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the recreated table to stop the run.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold found a recreated legacy table.', $error->getMessage() );
			}

			// Assert.
			$this->assertSame( 2, $refused );
			$this->assertCount( 1, $logs );
			$this->assertStringContainsString( 'Closure fold latch rollback failed', $logs[0] );
			$this->assertStringContainsString( '"previous":"9","legacy_table":"' . $fixture['legacy'] . '"', $logs[0] );
		} finally {
			remove_filter( 'query', $refuse );
			remove_filter( 'wcpos_logging', $capture, 10 );
			$wpdb->suppress_errors( $previous );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A failed legacy-table probe after the latch write is not absence: the old latch is restored. */
	public function test_closure_fold_failed_probe_after_latch_write_restores_latch(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$latched = false;
		$break = static function ( $sql ) use ( $fixture, &$latched ) {
			$latched = $latched || self::writes_latest_schema_latch( $sql );
			return $latched && false !== strpos( $sql, "SHOW TABLES LIKE '" . $GLOBALS['wpdb']->esc_like( $fixture['legacy'] ) . "'" ) ? 'INVALID PROBE' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $break );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the failed probe to restore the latch.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold probe failed.', $error->getMessage() );
			}

			// Assert.
			$this->assertTrue( $latched );
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $previous );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A recreated legacy table restarts its sequence: a number already folded is refused before any write, logged, and the latch held. */
	public function test_closure_fold_with_colliding_recreated_number_keeps_latch_table_and_logs_error(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$folded = $fixture['legacy'] . '_folded';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables, generated UUID.
		$this->assertNotFalse( $wpdb->query( "RENAME TABLE {$fixture['legacy']} TO {$folded}" ) );
		$this->create_legacy_table( $fixture['legacy'] );
		$this->assertSame( 1, $wpdb->query( "INSERT INTO {$fixture['legacy']} SELECT * FROM {$folded} WHERE id = '{$fixture['closures'][1]}'" ) );
		$this->assertSame( 1, $wpdb->query( "DELETE FROM {$folded} WHERE id = '{$fixture['closures'][1]}'" ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $wpdb->update( $fixture['legacy'], array( 'number' => 1 ), array( 'id' => $fixture['closures'][1] ) ) );
		$logs = array();
		$capture = static function ( $enabled, $message ) use ( &$logs ) {
			if ( 'error' === \WCPOS\WooCommercePOS\Logger::$log_level ) {
				$logs[] = $message;
			}
			return false;
		};
		\WCPOS\WooCommercePOS\Logger::reset_dedup_state();
		add_filter( 'wcpos_logging', $capture, 10, 2 );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the fold to refuse a recreated legacy closure.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold found a recreated legacy closure.', $error->getMessage() );
			}

			// Assert.
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			// The renamed table was fully folded and dropped before the recreated one was read.
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $folded ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$fixture['legacy']}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			$sessions = array();
			foreach ( $fixture['sessions'] as $id ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
				$sessions[] = $wpdb->get_row( $wpdb->prepare( "SELECT number, closure_id, closure FROM {$fixture['sessions_table']} WHERE id = %s", $id ), ARRAY_A );
			}
			$this->assertSame( array( '1', $fixture['closures'][0] ), array( $sessions[0]['number'], $sessions[0]['closure_id'] ) );
			$this->assertSame( array( null, $fixture['closures'][1], null ), array_values( $sessions[1] ) );
			$this->assertCount( 1, $logs );
			$this->assertStringContainsString( 'Closure fold refused: legacy closure number already folded', $logs[0] );
			$context = array(
				'closure_id' => $fixture['closures'][1],
				'register_id' => $fixture['register_id'],
				'number' => 1,
				'session_id' => $fixture['sessions'][1],
			);
			$this->assertStringContainsString( wp_json_encode( $context ), $logs[0] );
		} finally {
			remove_filter( 'wcpos_logging', $capture, 10 );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A closure committed to a table recreated during the drop reuses a folded number: the latch holds until a human drops that table, then the rerun latches. */
	public function test_closure_fold_rerun_after_dropping_recreated_colliding_table_latches(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$folded = $fixture['legacy'] . '_folded';
		$straggler = null;
		$recreate = function ( $sql ) use ( $fixture, $folded, &$straggler ) {
			if ( null === $straggler && "DROP TABLE IF EXISTS {$folded}" === $sql ) {
				$this->create_legacy_table( $fixture['legacy'] );
				$straggler = $this->add_legacy_closure( $fixture, $fixture['legacy'], 1, $folded );
			}
			return $sql;
		};
		$silence = '__return_false';
		add_filter( 'query', $recreate );
		add_filter( 'wcpos_logging', $silence );
		try {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			$errors = array();
			foreach ( array( 'recreated', 'collision' ) as $pass ) {
				try {
					( new Activator() )->install_sync_schema();
					$errors[ $pass ] = null;
				} catch ( \RuntimeException $error ) {
					$errors[ $pass ] = $error->getMessage();
				}
				remove_filter( 'query', $recreate );
			}
			$this->assertSame(
				array(
					'recreated' => 'Closure fold found a recreated legacy table.',
					'collision' => 'Closure fold found a recreated legacy closure.',
				),
				$errors
			);
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertNotFalse( $wpdb->query( "DROP TABLE {$fixture['legacy']}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table: the human repair.

			// Act.
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$closures = new \WCPOS\WooCommercePOS\Services\Closure_Store();
			$this->assertSame( array( 1, 2 ), array_column( array_map( array( $closures, 'get' ), $fixture['closures'] ), 'number' ) );
			$this->assertNull( $closures->get( $straggler ) );
		} finally {
			remove_filter( 'query', $recreate );
			remove_filter( 'wcpos_logging', $silence );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A copy that fails partway keeps the latch; the next pass finishes it. */
	public function test_closure_fold_failing_mid_copy_keeps_latch_and_resumes(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$updates = 0;
		$fail_second = static function ( $sql ) use ( $fixture, &$updates ) {
			if ( 0 === strpos( $sql, "UPDATE `{$fixture['sessions_table']}` SET `closure_id`" ) && 2 === ++$updates ) {
				return 'INVALID FOLD WRITE';
			}
			return $sql;
		};
		$previous = $wpdb->suppress_errors();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			add_filter( 'query', $fail_second );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the second copy to fail.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold write failed.', $error->getMessage() );
			}
			remove_filter( 'query', $fail_second );

			// Assert.
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$closures = new \WCPOS\WooCommercePOS\Services\Closure_Store();
			$this->assertSame( 1, $closures->get( $fixture['closures'][0] )['number'] );
			$this->assertNull( $closures->get( $fixture['closures'][1] ) );
			( new Activator() )->install_sync_schema();
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertSame( 2, $closures->get( $fixture['closures'][1] )['number'] );
		} finally {
			remove_filter( 'query', $fail_second );
			$wpdb->suppress_errors( $previous );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A failed legacy-table probe is not absence: the fold stops before latching. */
	public function test_closure_fold_failed_probe_keeps_latch(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$break = static function ( $sql ) use ( $fixture ) {
			return false !== strpos( $sql, "SHOW TABLES LIKE '" . $GLOBALS['wpdb']->esc_like( $fixture['legacy'] ) . "'" ) ? 'INVALID PROBE' : $sql;
		};
		$previous = $wpdb->suppress_errors();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			add_filter( 'query', $break );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the failed probe to stop the fold.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold probe failed.', $error->getMessage() );
			}
			remove_filter( 'query', $break );

			// Assert.
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $previous );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A legacy closure whose session carries another closure id stops the fold with the named error. */
	public function test_closure_fold_with_mismatched_closure_keeps_latch_and_logs_error(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$this->assertSame( 1, $wpdb->update( $fixture['sessions_table'], array( 'closure_id' => wp_generate_uuid4() ), array( 'id' => $fixture['sessions'][1] ) ) );
		$logs = array();
		$capture = static function ( $enabled, $message ) use ( &$logs ) {
			$logs[] = array( \WCPOS\WooCommercePOS\Logger::$log_level, $message );
			return false;
		};
		\WCPOS\WooCommercePOS\Logger::reset_dedup_state();
		add_filter( 'wcpos_logging', $capture, 10, 2 );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the fold to refuse a mismatched closure.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold found orphaned or mismatched closures.', $error->getMessage() );
			}

			// Assert.
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$errors = array_values(
				array_filter(
					$logs,
					static function ( $entry ) {
						return 'error' === $entry[0];
					}
				)
			);
			$this->assertCount( 1, $errors );
			$this->assertStringContainsString( 'Closure fold refused: legacy closures do not match their sessions', $errors[0][1] );
			$this->assertStringContainsString( '"mismatches":1', $errors[0][1] );
		} finally {
			remove_filter( 'wcpos_logging', $capture, 10 );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** An orphaned legacy closure stops the fold: latch, table and log say so, and no closure is minted. */
	public function test_closure_fold_with_orphan_closure_keeps_latch_table_and_logs_error(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$this->assertSame( 1, $wpdb->update( $fixture['legacy'], array( 'session_id' => wp_generate_uuid4() ), array( 'id' => $fixture['closures'][1] ) ) );
		$logs = array();
		$capture = static function ( $enabled, $message ) use ( &$logs ) {
			$logs[] = array( \WCPOS\WooCommercePOS\Logger::$log_level, $message );
			return false;
		};
		\WCPOS\WooCommercePOS\Logger::reset_dedup_state();
		add_filter( 'wcpos_logging', $capture, 10, 2 );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the fold to refuse an orphaned closure.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure fold found orphaned or mismatched closures.', $error->getMessage() );
			}

			// Assert.
			$this->assertSame( '9', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertSame( '2', $wpdb->get_var( "SELECT COUNT(*) FROM {$fixture['legacy']}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			$errors = array_values(
				array_filter(
					$logs,
					static function ( $entry ) {
						return 'error' === $entry[0];
					}
				)
			);
			$this->assertCount( 1, $errors );
			$this->assertStringContainsString( 'Closure fold refused: legacy closures do not match their sessions', $errors[0][1] );
			$this->assertStringContainsString( '"orphans":1', $errors[0][1] );
			try {
				( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->create(
					array(
						'id' => wp_generate_uuid4(),
						'session_id' => $fixture['sessions'][0],
					)
				);
				$this->fail( 'Expected closure writes to wait for the fold.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Closure storage is migrating.', $error->getMessage() );
			}
		} finally {
			remove_filter( 'wcpos_logging', $capture, 10 );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A fresh install creates the closure columns and indexes; step 10 then issues no DDL. */
	public function test_fresh_session_schema_carries_closure_columns_and_indexes(): void {
		// Arrange.
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$store = new \WCPOS\WooCommercePOS\Services\Register_Session_Store();
		$table = $store->table_name();
		$alterations = 0;
		$observe = static function ( $sql ) use ( $table, &$alterations ) {
			if ( 0 === strpos( $sql, "ALTER TABLE {$table}" ) ) {
				++$alterations;
			}
			return $sql;
		};
		try {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.

			// Act.
			$store->install();
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			add_filter( 'query', $observe );
			( new Activator() )->install_sync_schema();
			remove_filter( 'query', $observe );

			// Assert.
			$this->assertSame( 0, $alterations );
			foreach ( array(
				'counting_by' => array( 'bigint', 'YES' ),
				'number' => array( 'bigint', 'YES' ),
				'printed_number' => array( 'bigint', 'YES' ),
				'print_count' => array( 'int', 'NO' ),
				'last_printed_at_gmt' => array( 'datetime', 'YES' ),
				'closure' => array( 'longtext', 'YES' ),
			) as $column => $shape ) {
				$definition = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE '{$column}'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed columns.
				$this->assertNotNull( $definition, $column );
				$this->assertSame( $shape[0], preg_replace( '/\(\d+\)$/', '', $definition['Type'] ), $column );
				$this->assertSame( $shape[1], $definition['Null'], $column );
			}
			$indexes = array();
			foreach ( $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ) as $part ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.
				$indexes[ $part['Key_name'] ]['unique'] = '0' === (string) $part['Non_unique'];
				$indexes[ $part['Key_name'] ]['columns'][ (int) $part['Seq_in_index'] ] = $part['Column_name'];
			}
			$this->assertSame(
				array(
					'unique' => true,
					'columns' => array(
						1 => 'register_id',
						2 => 'number',
					),
				),
				$indexes['register_number']
			);
			$this->assertSame(
				array(
					'unique' => true,
					'columns' => array( 1 => 'closure_id' ),
				),
				$indexes['closure_id']
			);
			$this->assertSame(
				array(
					'unique' => false,
					'columns' => array(
						1 => 'store_id',
						2 => 'closed_at_gmt',
					),
				),
				$indexes['store_closed']
			);
		} finally {
			remove_filter( 'query', $observe );
			$store->install();
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Shape a schema-9 install: sessions without the closure columns and a legacy closures
	 * table holding two closures for two closed sessions of one register.
	 *
	 * DDL commits the test transaction, so restore_closure_schema() undoes all of it.
	 */
	private function arrange_schema_nine_with_legacy_closures(): array {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$sessions = new \WCPOS\WooCommercePOS\Services\Register_Session_Store();
		$fixture = array(
			'sessions_table' => $sessions->table_name(),
			'legacy' => $wpdb->prefix . 'wcpos_closures',
			'register_id' => wp_generate_uuid4(),
			'sessions' => array( wp_generate_uuid4(), wp_generate_uuid4() ),
			'closures' => array( wp_generate_uuid4(), wp_generate_uuid4() ),
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables, fixed DDL.
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$fixture['sessions_table']} DROP INDEX register_number, DROP INDEX closure_id, DROP INDEX store_closed, DROP COLUMN number, DROP COLUMN printed_number, DROP COLUMN print_count, DROP COLUMN last_printed_at_gmt, DROP COLUMN closure" ) );
		$this->create_legacy_table( $fixture['legacy'] );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array( 0, 1 ) as $index ) {
			$sessions->create(
				array(
					'id' => $fixture['sessions'][ $index ],
					'register_id' => $fixture['register_id'],
					'store_id' => 456,
					'opened_at_gmt' => '2026-09-11 08:00:00',
					'business_day' => '2026-09-11',
					'opened_by' => 1,
					'expected_float' => null,
					'counted_float' => '100',
				)
			);
			$this->assertSame(
				1,
				$wpdb->update(
					$fixture['sessions_table'],
					array(
						'status' => 'closed',
						'closed_at_gmt' => '2026-09-11 12:00:00',
						'closed_by' => 1,
						'counted' => '{"cash":"101.0000"}',
						'closure_id' => $fixture['closures'][ $index ],
					),
					array( 'id' => $fixture['sessions'][ $index ] )
				)
			);
			$this->assertSame(
				1,
				$wpdb->insert(
					$fixture['legacy'],
					array(
						'id' => $fixture['closures'][ $index ],
						'register_id' => $fixture['register_id'],
						'session_id' => $fixture['sessions'][ $index ],
						'store_id' => 456,
						'number' => $index + 1,
						'printed_number' => 0 === $index ? null : 1,
						'opened_at_gmt' => '2026-09-11 08:00:00',
						'business_day' => '2026-09-11',
						'closed_at_gmt' => '2026-09-11 13:00:00',
						'opened_by' => 1,
						'closed_by' => 1,
						'approved_by' => null,
						'expected' => '{"cash":"150.0000"}',
						'till_expected' => '{"cash":"100.0000"}',
						'counted' => '{"cash":"101.0000"}',
						'variance' => '{"cash":"-49.0000"}',
						'period_sales_total' => '80',
						'period_refunds_total' => '0',
						'perpetual_sales_total' => '80',
						'perpetual_refunds_total' => '0',
						'unsynced_count' => 0,
						'unsynced_total' => '0',
						'software_version' => 'fixture',
						'breakdowns' => '{"transaction_count":0}',
						'print_count' => 0 === $index ? 2 : 0,
						'last_printed_at_gmt' => 0 === $index ? '2026-09-11 12:30:00' : null,
						'received_at_gmt' => '2026-09-11 13:00:05',
					)
				)
			);
		}
		return $fixture;
	}

	/** Create a legacy closures table as schema 9 created it on dev builds of `next`.
	 *
	 * @param string $table Table name.
	 */
	private function create_legacy_table( string $table ): void {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed DDL.
		$this->assertNotFalse(
			$wpdb->query(
				"CREATE TABLE {$table} (
				id CHAR(36) NOT NULL,
				register_id CHAR(36) NOT NULL,
				session_id CHAR(36) NOT NULL,
				store_id BIGINT NULL,
				number BIGINT NOT NULL,
				printed_number BIGINT NULL,
				opened_at_gmt DATETIME NOT NULL,
				business_day CHAR(10) NULL,
				closed_at_gmt DATETIME NOT NULL,
				opened_by BIGINT NOT NULL,
				closed_by BIGINT NOT NULL,
				approved_by BIGINT NULL,
				expected LONGTEXT NOT NULL,
				till_expected LONGTEXT NOT NULL,
				counted LONGTEXT NOT NULL,
				variance LONGTEXT NOT NULL,
				period_sales_total DECIMAL(19,4) NOT NULL,
				period_refunds_total DECIMAL(19,4) NOT NULL,
				perpetual_sales_total DECIMAL(19,4) NOT NULL,
				perpetual_refunds_total DECIMAL(19,4) NOT NULL,
				unsynced_count INT NOT NULL,
				unsynced_total DECIMAL(19,4) NOT NULL,
				first_sale_counter BIGINT NULL,
				last_sale_counter BIGINT NULL,
				first_receipt_id BIGINT NULL,
				last_receipt_id BIGINT NULL,
				software_version VARCHAR(64) NOT NULL,
				printed_at_gmt DATETIME NULL,
				breakdowns LONGTEXT NOT NULL,
				findings LONGTEXT NULL,
				print_count INT NOT NULL DEFAULT 0,
				last_printed_at_gmt DATETIME NULL,
				received_at_gmt DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY register_number (register_id, number),
				UNIQUE KEY session_id (session_id),
				KEY store_closed (store_id, closed_at_gmt)
			) {$collate}"
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Close one more fixture session and record its closure in a legacy table.
	 *
	 * @param array  $fixture From arrange_schema_nine_with_legacy_closures().
	 * @param string $table Legacy table to insert into.
	 * @param int    $number Closure number.
	 * @param string $source Legacy table holding the first fixture closure; defaults to $table.
	 * @return string Closure id.
	 */
	private function add_legacy_closure( array $fixture, string $table, int $number, string $source = '' ): string {
		global $wpdb;
		$session = wp_generate_uuid4();
		$closure = wp_generate_uuid4();
		$source = '' === $source ? $table : $source;
		$this->assertSame(
			1,
			$wpdb->insert(
				$fixture['sessions_table'],
				array(
					'id' => $session,
					'register_id' => $fixture['register_id'],
					'store_id' => 456,
					'status' => 'closed',
					'opened_at_gmt' => '2026-09-11 08:00:00',
					'opened_by' => 1,
					'counted_float' => '100',
					'closed_at_gmt' => '2026-09-11 12:00:00',
					'closed_by' => 1,
					'created_at_gmt' => '2026-09-11 08:00:00',
				)
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables; ids are prepared.
		$copied = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} SELECT %s, register_id, %s, store_id, %d, NULL, opened_at_gmt, business_day, closed_at_gmt, opened_by, closed_by, approved_by, expected, till_expected, counted, variance, period_sales_total, period_refunds_total, perpetual_sales_total, perpetual_refunds_total, unsynced_count, unsynced_total, first_sale_counter, last_sale_counter, first_receipt_id, last_receipt_id, software_version, printed_at_gmt, breakdowns, findings, 0, NULL, received_at_gmt FROM {$source} WHERE id = %s", $closure, $session, $number, $fixture['closures'][0] ) );
		$this->assertSame( 1, $copied );
		return $closure;
	}

	/** Record one print on a closure row the way record_print() does: an increment, never a set.
	 *
	 * @param string $table Legacy closures or sessions table.
	 * @param string $column id or closure_id.
	 * @param string $closure_id Closure UUID.
	 * @param string $at Print stamp.
	 */
	private function record_fixture_print( string $table, string $column, string $closure_id, string $at ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed column; values are prepared.
		$this->assertSame( 1, $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET print_count = print_count + 1, last_printed_at_gmt = %s WHERE {$column} = %s", $at, $closure_id ) ) );
	}

	/** Whether a statement writes the current schema version to the sync latch.
	 *
	 * @param string $sql Statement.
	 */
	private static function writes_latest_schema_latch( string $sql ): bool {
		return false !== strpos( $sql, $GLOBALS['wpdb']->options )
			&& false !== strpos( $sql, "'" . \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION . "'" )
			&& false !== strpos( $sql, "`option_value` = '" . \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION . "'" );
	}

	/** Drop the legacy table and fixture rows, then put the closure columns back.
	 *
	 * @param array $fixture From arrange_schema_nine_with_legacy_closures().
	 */
	private function restore_closure_schema( array $fixture ): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$fixture['legacy']}, {$fixture['legacy']}_folded" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy tables.
		$wpdb->delete( $fixture['sessions_table'], array( 'register_id' => $fixture['register_id'] ) );
		update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
		( new Activator() )->install_sync_schema();
		update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, false );
		$wpdb->query( 'COMMIT' );
	}

	/** Schema 11 on a schema-10 install copies both sessions' movements (one a void pair), drops the legacy table and latches. */
	public function test_movement_fold_on_schema_ten_install_copies_movements_and_drops_legacy_table(): void {
		// Arrange.
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$store = new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] . '_folded' ) );
			$this->assertSame( $fixture['expected'][0], $store->list( $fixture['sessions'][0] ) );
			$this->assertSame( $fixture['expected'][1], $store->list( $fixture['sessions'][1] ) );
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
			$stored = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT movements FROM {$fixture['sessions_table']} WHERE id = %s", $fixture['sessions'][0] ) ), true );
			// The stored entry carries no session id: the row it lives on is the session.
			$this->assertSame( array( 'id', 'type', 'amount', 'reason', 'actor', 'voids', 'voided_by', 'created_at_gmt' ), array_keys( $stored[0] ) );
		} finally {
			$this->restore_movement_schema( $fixture );
		}
	}

	/** A run stopped after the rename resumes from the renamed table: the copied session is untouched, the straggler is copied, both names go. */
	public function test_movement_fold_rerun_resumes_from_renamed_table(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$folded = $fixture['legacy'] . '_folded';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables, fixed DDL.
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$fixture['sessions_table']} ADD movements LONGTEXT NULL" ) );
		$copied = wp_json_encode( array_map( array( \WCPOS\WooCommercePOS\Services\Cash_Movement_Store::class, 'entry' ), $fixture['expected'][0] ) );
		$this->assertSame( 1, $wpdb->update( $fixture['sessions_table'], array( 'movements' => $copied ), array( 'id' => $fixture['sessions'][0] ) ) );
		$this->assertNotFalse( $wpdb->query( "RENAME TABLE {$fixture['legacy']} TO {$folded}" ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$store = new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $folded ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
			$this->assertSame( $copied, $wpdb->get_var( $wpdb->prepare( "SELECT movements FROM {$fixture['sessions_table']} WHERE id = %s", $fixture['sessions'][0] ) ) );
			$this->assertSame( $fixture['expected'][1], $store->list( $fixture['sessions'][1] ) );
		} finally {
			$this->restore_movement_schema( $fixture );
		}
	}

	/** A void old code wrote after its session was copied is a late stamp: the second pass applies it and appends the void, then latches. */
	public function test_movement_fold_second_pass_applies_a_late_void_stamp(): void {
		// Arrange: session 0 was copied before its paid-out was voided on the legacy table.
		global $wpdb;
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$folded = $fixture['legacy'] . '_folded';
		$before = array( array_merge( $fixture['expected'][0][0], array( 'voided_by' => null ) ) );
		$this->fold_session_early( $fixture, $before );
		$this->assertNotFalse( $wpdb->query( "RENAME TABLE {$fixture['legacy']} TO {$folded}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables.
		$store = new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $folded ) );
			$this->assertSame( $fixture['expected'][0], $store->list( $fixture['sessions'][0] ) );
			$this->assertSame( $fixture['expected'][1], $store->list( $fixture['sessions'][1] ) );
		} finally {
			$this->restore_movement_schema( $fixture );
		}
	}

	/** Any difference other than a late void stamp is refused before any write, the latch held. */
	public function test_movement_fold_with_folded_amount_mismatch_refuses(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$before = $fixture['expected'][0];
		$before[0]['amount'] = '6.0000';
		$this->fold_session_early( $fixture, $before );
		$logs = $this->capture_error_logs();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the fold to refuse a mismatched amount.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Movement fold found a movement for a session already folded.', $error->getMessage() );
			}

			// Assert.
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table; the id is prepared.
			$this->assertSame( wp_json_encode( array_map( array( \WCPOS\WooCommercePOS\Services\Cash_Movement_Store::class, 'entry' ), $before ) ), $wpdb->get_var( $wpdb->prepare( "SELECT movements FROM {$fixture['sessions_table']} WHERE id = %s", $fixture['sessions'][0] ) ) );
			$this->assertCount( 1, $logs->entries );
			$this->assertStringContainsString( 'Movement fold refused: session already folded', $logs->entries[0] );
		} finally {
			$logs->stop();
			$this->restore_movement_schema( $fixture );
		}
	}

	/** Add the movements column and give session 0 an array as an earlier copy wrote it.
	 *
	 * @param array $fixture From arrange_schema_ten_with_legacy_movements().
	 * @param array $rows Movement rows the earlier copy held.
	 */
	private function fold_session_early( array $fixture, array $rows ): void {
		global $wpdb;
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$fixture['sessions_table']} ADD movements LONGTEXT NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed DDL.
		$movements = wp_json_encode( array_map( array( \WCPOS\WooCommercePOS\Services\Cash_Movement_Store::class, 'entry' ), $rows ) );
		$this->assertSame( 1, $wpdb->update( $fixture['sessions_table'], array( 'movements' => $movements ), array( 'id' => $fixture['sessions'][0] ) ) );
	}

	/** A copy stopped after the first session keeps the latch; the next run copies the rest without duplicating the first. */
	public function test_movement_fold_failing_mid_copy_keeps_latch_and_resumes(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$writes = 0;
		$fail_second = static function ( $sql ) use ( $fixture, &$writes ) {
			if ( 0 === strpos( $sql, "UPDATE {$fixture['sessions_table']} SET movements" ) && 2 === ++$writes ) {
				return 'INVALID MOVEMENT COPY';
			}
			return $sql;
		};
		$store = new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store();
		$previous = $wpdb->suppress_errors();
		try {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			add_filter( 'query', $fail_second );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the second session copy to fail.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Movement fold write failed.', $error->getMessage() );
			}
			remove_filter( 'query', $fail_second );
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, fixed predicate.
			$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$fixture['sessions_table']} WHERE register_id = '{$fixture['register_id']}' AND movements IS NOT NULL" ) );

			// Act.
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertSame( $fixture['expected'][0], $store->list( $fixture['sessions'][0] ) );
			$this->assertSame( $fixture['expected'][1], $store->list( $fixture['sessions'][1] ) );
		} finally {
			remove_filter( 'query', $fail_second );
			$wpdb->suppress_errors( $previous );
			$this->restore_movement_schema( $fixture );
		}
	}

	/** An orphaned legacy movement stops the fold before any write: latch, table and the named error say so. */
	public function test_movement_fold_with_orphan_movement_keeps_latch_table_and_logs_error(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$this->assertSame( 1, $wpdb->update( $fixture['legacy'], array( 'session_id' => wp_generate_uuid4() ), array( 'id' => $fixture['expected'][1][0]['id'] ) ) );
		$logs = $this->capture_error_logs();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the fold to refuse an orphaned movement.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Movement fold found orphaned movements.', $error->getMessage() );
			}

			// Assert.
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertSame( '3', $wpdb->get_var( "SELECT COUNT(*) FROM {$fixture['legacy']}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table, fixed predicate.
			$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$fixture['sessions_table']} WHERE register_id = '{$fixture['register_id']}' AND movements IS NOT NULL" ) );
			$this->assertCount( 1, $logs->entries );
			$this->assertStringContainsString( 'Movement fold refused: legacy movements have no session', $logs->entries[0] );
			$this->assertStringContainsString( '"orphans":1', $logs->entries[0] );
		} finally {
			$logs->stop();
			$this->restore_movement_schema( $fixture );
		}
	}

	/** A paid-in old code wrote after its session was copied is new data: the second pass appends it to the array and the run latches. */
	public function test_movement_fold_second_pass_appends_a_late_paid_in(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$folded = $fixture['legacy'] . '_folded';
		$this->fold_session_early( $fixture, $fixture['expected'][0] );
		$late = $this->add_legacy_movement( $fixture, $fixture['sessions'][0], '2026-09-11 11:00:00' );
		$this->assertNotFalse( $wpdb->query( "RENAME TABLE {$fixture['legacy']} TO {$folded}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables.
		$store = new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $folded ) );
			$listed = $store->list( $fixture['sessions'][0] );
			$this->assertSame( $fixture['expected'][0], array_slice( $listed, 0, 2 ) );
			$this->assertSame(
				array(
					'id' => $late,
					'session_id' => $fixture['sessions'][0],
					'type' => 'paid_in',
					'amount' => '1.0000',
					'reason' => 'Pre-fold code',
					'actor' => 1,
					'voids' => null,
					'voided_by' => null,
					'created_at_gmt' => '2026-09-11 11:00:00',
				),
				$listed[2]
			);
			$this->assertSame( $fixture['expected'][1], $store->list( $fixture['sessions'][1] ) );
		} finally {
			$this->restore_movement_schema( $fixture );
		}
	}

	/** While a legacy movements table exists, a new movement is refused rather than appended beside the copy. */
	public function test_movement_create_while_legacy_table_exists_refuses(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$fixture['sessions_table']} ADD movements LONGTEXT NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed DDL.
		$store = new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store();
		$id = wp_generate_uuid4();
		try {
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );

			// Act.
			try {
				$store->create(
					array(
						'id' => $id,
						'session_id' => $fixture['sessions'][1],
						'type' => 'paid_in',
						'amount' => '1',
						'reason' => 'During the fold',
						'actor' => 1,
						'created_at_gmt' => '2026-09-11 10:30:00',
					)
				);
				$this->fail( 'Expected movement writes to wait for the fold.' );
			} catch ( \RuntimeException $error ) {
				// Assert.
				$this->assertSame( 'Movement storage is migrating.', $error->getMessage() );
			}
			$this->assertNull( $store->get( $fixture['sessions'][1], $id ) );
			// Mid-fold the legacy rows still read through the store.
			$this->assertSame( $fixture['expected'][0], $store->list( $fixture['sessions'][0] ) );
		} finally {
			$this->restore_movement_schema( $fixture );
		}
	}

	/** A movements table recreated after the fold but before the latch write restores the old latch; the next run copies it and latches. */
	public function test_movement_fold_recreated_between_fold_and_latch_restores_latch_then_completes(): void {
		// Arrange.
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$straggler = null;
		$recreate = function ( $sql ) use ( $fixture, &$straggler ) {
			if ( null === $straggler && self::writes_latest_schema_latch( $sql ) ) {
				$straggler = '';
				$this->create_legacy_movements_table( $fixture['legacy'] );
				$straggler = $this->add_legacy_movement( $fixture, $fixture['sessions'][2], '2026-09-11 10:10:00' );
			}
			return $sql;
		};
		add_filter( 'query', $recreate );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the recreated table to restore the latch.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Movement fold found a recreated legacy table.', $error->getMessage() );
			}
			remove_filter( 'query', $recreate );

			// Assert.
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			( new Activator() )->install_sync_schema();
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$store = new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store();
			$this->assertSame( array( $straggler ), array_column( $store->list( $fixture['sessions'][2] ), 'id' ) );
			$this->assertSame( $fixture['expected'][0], $store->list( $fixture['sessions'][0] ) );
		} finally {
			remove_filter( 'query', $recreate );
			$this->restore_movement_schema( $fixture );
		}
	}

	/** A latch rollback the options table refuses after a recreated movements table is retried once, then logged; the run still throws. */
	public function test_movement_fold_failed_latch_rollback_retries_logs_error_and_throws(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_ten_with_legacy_movements();
		$recreated = false;
		$refused = 0;
		$refuse = function ( $sql ) use ( $fixture, &$recreated, &$refused ) {
			if ( ! $recreated && self::writes_latest_schema_latch( $sql ) ) {
				$recreated = true;
				$this->create_legacy_movements_table( $fixture['legacy'] );
			} elseif ( $recreated && false !== strpos( $sql, $GLOBALS['wpdb']->options ) && false !== strpos( $sql, "'" . \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION . "'" ) && false !== strpos( $sql, "`option_value` = '10'" ) ) {
				++$refused;
				return 'INVALID LATCH RESTORE';
			}
			return $sql;
		};
		$logs = $this->capture_error_logs();
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $refuse );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the recreated table to stop the run.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Movement fold found a recreated legacy table.', $error->getMessage() );
			}

			// Assert.
			$this->assertSame( 2, $refused );
			$this->assertCount( 1, $logs->entries );
			$this->assertStringContainsString( 'Movement fold latch rollback failed', $logs->entries[0] );
			$this->assertStringContainsString( '"previous":"10","legacy_table":"' . $fixture['legacy'] . '"', $logs->entries[0] );
		} finally {
			remove_filter( 'query', $refuse );
			$logs->stop();
			$wpdb->suppress_errors( $previous );
			$this->restore_movement_schema( $fixture );
		}
	}

	/** A fresh install creates the movements column; step 11 then issues no DDL. */
	public function test_fresh_session_schema_carries_movements_column(): void {
		// Arrange.
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$store = new \WCPOS\WooCommercePOS\Services\Register_Session_Store();
		$table = $store->table_name();
		$alterations = 0;
		$observe = static function ( $sql ) use ( $table, &$alterations ) {
			if ( 0 === strpos( $sql, "ALTER TABLE {$table}" ) ) {
				++$alterations;
			}
			return $sql;
		};
		try {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table.

			// Act.
			$store->install();
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
			add_filter( 'query', $observe );
			( new Activator() )->install_sync_schema();
			remove_filter( 'query', $observe );

			// Assert.
			$this->assertSame( 0, $alterations );
			$definition = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'movements'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed column.
			$this->assertSame( array( 'longtext', 'YES' ), array( $definition['Type'], $definition['Null'] ) );
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
		} finally {
			remove_filter( 'query', $observe );
			$store->install();
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Shape a schema-10 install: sessions without the movements column and a legacy movements
	 * table holding a paid-out and its void for one session and a paid-in for another. A third
	 * session has no movements.
	 *
	 * DDL commits the test transaction, so restore_movement_schema() undoes all of it.
	 */
	private function arrange_schema_ten_with_legacy_movements(): array {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$sessions = new \WCPOS\WooCommercePOS\Services\Register_Session_Store();
		$fixture = array(
			'sessions_table' => $sessions->table_name(),
			'legacy' => $wpdb->prefix . \WCPOS\WooCommercePOS\Services\Cash_Movement_Store::LEGACY_TABLE,
			'register_id' => wp_generate_uuid4(),
			'sessions' => array( wp_generate_uuid4(), wp_generate_uuid4(), wp_generate_uuid4() ),
		);
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$fixture['sessions_table']} DROP COLUMN movements" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed DDL.
		$this->create_legacy_movements_table( $fixture['legacy'] );
		foreach ( $fixture['sessions'] as $id ) {
			$this->assertIsArray(
				$sessions->create(
					array(
						'id' => $id,
						'register_id' => $fixture['register_id'],
						'store_id' => 456,
						'opened_at_gmt' => '2026-09-11 08:00:00',
						'business_day' => '2026-09-11',
						'opened_by' => 1,
						'expected_float' => null,
						'counted_float' => '100',
					)
				)
			);
			// One register holds one open session at a time: close each before opening the next.
			$this->assertSame( 1, $wpdb->update( $fixture['sessions_table'], array( 'status' => 'closed' ), array( 'id' => $id ) ) );
		}
		$paid_out = wp_generate_uuid4();
		$void = wp_generate_uuid4();
		$rows = array(
			array( $fixture['sessions'][0], $void, 'void', '0.0000', '', $paid_out, null, '2026-09-11 10:05:00' ),
			array( $fixture['sessions'][0], $paid_out, 'paid_out', '5.0000', 'Change', null, $void, '2026-09-11 10:00:00' ),
			array( $fixture['sessions'][1], wp_generate_uuid4(), 'paid_in', '20.0000', 'Float top-up', null, null, '2026-09-11 09:00:00' ),
		);
		$fixture['expected'] = array( array(), array() );
		foreach ( $rows as list( $session, $id, $type, $amount, $reason, $voids, $voided_by, $at ) ) {
			$row = array(
				'id' => $id,
				'session_id' => $session,
				'type' => $type,
				'amount' => $amount,
				'reason' => $reason,
				'actor' => 1,
				'voids' => $voids,
				'voided_by' => $voided_by,
				'created_at_gmt' => $at,
			);
			$this->assertSame( 1, $wpdb->insert( $fixture['legacy'], $row ) );
			$fixture['expected'][ $session === $fixture['sessions'][0] ? 0 : 1 ][] = $row;
		}
		// Listed oldest first: the paid-out, then its void.
		$fixture['expected'][0] = array_reverse( $fixture['expected'][0] );
		return $fixture;
	}

	/** Create a legacy movements table as dev builds of `next` created it.
	 *
	 * @param string $table Table name.
	 */
	private function create_legacy_movements_table( string $table ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed DDL.
		$this->assertNotFalse( $wpdb->query( "CREATE TABLE {$table} ( id CHAR(36) NOT NULL, session_id CHAR(36) NOT NULL, type VARCHAR(16) NOT NULL, amount DECIMAL(19,4) NOT NULL, reason TEXT NOT NULL, actor BIGINT NOT NULL, voids CHAR(36) NULL, voided_by CHAR(36) NULL, created_at_gmt DATETIME NOT NULL, PRIMARY KEY  (id), KEY session_id (session_id), KEY voids (voids) ) {$wpdb->get_charset_collate()}" ) );
	}

	/** Record one paid-in in the legacy movements table the way pre-fold code did.
	 *
	 * @param array  $fixture From arrange_schema_ten_with_legacy_movements().
	 * @param string $session_id Session UUID.
	 * @param string $at Movement time.
	 * @return string Movement id.
	 */
	private function add_legacy_movement( array $fixture, string $session_id, string $at ): string {
		global $wpdb;
		$id = wp_generate_uuid4();
		$this->assertSame(
			1,
			$wpdb->insert(
				$fixture['legacy'],
				array(
					'id' => $id,
					'session_id' => $session_id,
					'type' => 'paid_in',
					'amount' => '1.0000',
					'reason' => 'Pre-fold code',
					'actor' => 1,
					'created_at_gmt' => $at,
				)
			)
		);
		return $id;
	}

	/** Capture error-level log lines until stop() is called. */
	private function capture_error_logs(): object {
		$logs = new class() {
			/**
			 * Captured error messages.
			 *
			 * @var string[]
			 */
			public $entries = array();

			/** Remove the capture. */
			public function stop(): void {
				remove_filter( 'wcpos_logging', array( $this, 'capture' ), 10 );
			}

			/** Keep error-level messages and suppress the write.
			 *
			 * @param bool   $enabled Whether logging is enabled.
			 * @param string $message Formatted message.
			 */
			public function capture( $enabled, $message ): bool {
				if ( 'error' === \WCPOS\WooCommercePOS\Logger::$log_level ) {
					$this->entries[] = $message;
				}
				return false;
			}
		};
		\WCPOS\WooCommercePOS\Logger::reset_dedup_state();
		add_filter( 'wcpos_logging', array( $logs, 'capture' ), 10, 2 );
		return $logs;
	}

	/** Drop the legacy tables and fixture sessions, then put the movements column back.
	 *
	 * @param array $fixture From arrange_schema_ten_with_legacy_movements().
	 */
	private function restore_movement_schema( array $fixture ): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$fixture['legacy']}, {$fixture['legacy']}_folded" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy tables.
		$wpdb->delete( $fixture['sessions_table'], array( 'register_id' => $fixture['register_id'] ) );
		update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '10' );
		( new Activator() )->install_sync_schema();
		update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, false );
		$wpdb->query( 'COMMIT' );
	}

	/** Schema 12 keeps the register with the most recent session under its UUID, retires the others with full rows, drops the table and latches. */
	public function test_register_fold_on_schema_eleven_keeps_most_recent_and_retires_others(): void {
		// Arrange.
		$fixture = $this->arrange_schema_eleven_with_legacy_registers();
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$option = get_option( self::REGISTER_OPTION );
			// The stored row keeps the dev site's store for Pro's adoption; Free serves it with none.
			$this->assertSame( $fixture['rows']['recent'], $option['register'] );
			$this->assertSame( 12, $option['register']['store_id'] );
			$this->assertSame(
				array(
					$fixture['rows']['idle']['id'] => $fixture['rows']['idle'],
					$fixture['rows']['older']['id'] => $fixture['rows']['older'],
				),
				$option['retired']
			);
			$registers = new \WCPOS\WooCommercePOS\Services\Register_Store();
			$this->assertSame( array( $fixture['rows']['recent']['id'] ), array_column( $registers->list( array() ), 'id' ) );
			$this->assertNull( $registers->get( $fixture['rows']['recent']['id'] )['store_id'] );
			$this->assertSame( 'Older till', $registers->display_name( $fixture['rows']['older']['id'] ) );

			// Act: a re-run has no table to copy and changes nothing.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertSame( $option, get_option( self::REGISTER_OPTION ) );
		} finally {
			$this->restore_register_schema( $fixture );
		}
	}

	/** Without any session the oldest register is kept; an option register the table also holds is kept instead. */
	public function test_register_fold_keeps_oldest_without_sessions_and_an_option_register_the_table_holds(): void {
		global $wpdb;
		foreach ( array( 'no sessions', 'option holds one' ) as $case ) {
			// Arrange.
			$fixture = $this->arrange_schema_eleven_with_legacy_registers();
			if ( 'no sessions' === $case ) {
				$wpdb->delete( $fixture['sessions_table'], array( 'register_id' => $fixture['rows']['recent']['id'] ) );
				$wpdb->delete( $fixture['sessions_table'], array( 'register_id' => $fixture['rows']['older']['id'] ) );
				$keep = 'idle';
			} else {
				update_option( self::REGISTER_OPTION, array( 'register' => array_replace( $fixture['rows']['older'], array( 'name' => 'Renamed by new code' ) ) ), true );
				$keep = 'older';
			}
			try {
				// Act.
				update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
				( new Activator() )->install_sync_schema();

				// Assert.
				$option = get_option( self::REGISTER_OPTION );
				$this->assertSame( $fixture['rows'][ $keep ]['id'], $option['register']['id'], $case );
				$this->assertSame( 'option holds one' === $case ? 'Renamed by new code' : 'Idle till', $option['register']['name'], $case );
				$this->assertSame( $fixture['rows'][ $keep ]['store_id'], $option['register']['store_id'], $case );
				$this->assertEqualsCanonicalizing( array_diff( array_column( $fixture['rows'], 'id' ), array( $fixture['rows'][ $keep ]['id'] ) ), array_keys( $option['retired'] ), $case );
				$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ), $case );
			} finally {
				$this->restore_register_schema( $fixture );
			}
		}
	}

	/** A register minted lazily before the step ran, which the table does not hold, is retired rather than lost. */
	public function test_register_fold_retires_an_option_register_the_table_does_not_hold(): void {
		// Arrange.
		$fixture = $this->arrange_schema_eleven_with_legacy_registers();
		$minted = array(
			'id' => wp_generate_uuid4(),
			'name' => 'Register',
			'store_id' => null,
			'default_float' => null,
			'created_at_gmt' => '2026-10-19 08:00:00',
		);
		update_option( self::REGISTER_OPTION, array( 'register' => $minted ), true );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$option = get_option( self::REGISTER_OPTION );
			$this->assertSame( $fixture['rows']['recent']['id'], $option['register']['id'] );
			$this->assertSame( $minted, $option['retired'][ $minted['id'] ] );
		} finally {
			$this->restore_register_schema( $fixture );
		}
	}

	/** An empty legacy table mints no register: the option stays absent for the lazy default, and the table goes. */
	public function test_register_fold_with_empty_table_leaves_no_option(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_eleven_with_legacy_registers();
		$wpdb->query( "DELETE FROM {$fixture['legacy']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy table.
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( get_option( self::REGISTER_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
		} finally {
			$this->restore_register_schema( $fixture );
		}
	}

	/** An option that does not read back exactly (unwritten, or one retired name off) stops the step: error logged, old latch and table kept. */
	public function test_register_fold_with_unverified_option_keeps_latch_and_table_and_logs_error(): void {
		foreach ( array( 'unwritten', 'renamed' ) as $case ) {
			// Arrange.
			$fixture = $this->arrange_schema_eleven_with_legacy_registers();
			$refuse = static function ( $value, $old_value ) use ( $case, $fixture ) {
				if ( 'unwritten' === $case ) {
					return $old_value;
				}
				$value['retired'][ $fixture['rows']['older']['id'] ]['name'] = 'Stale name';
				return $value;
			};
			add_filter( 'pre_update_option_' . self::REGISTER_OPTION, $refuse, 10, 2 );
			$logs = $this->capture_error_logs();
			try {
				// Act.
				update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
				( new Activator() )->install_sync_schema();

				// Assert.
				$this->assertSame( '11', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ), $case );
				$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] . '_folded' ), $case );
				$this->assertCount( 1, $logs->entries, $case );
				$this->assertStringContainsString( 'Register fold refused: the option did not read back', $logs->entries[0], $case );
			} finally {
				remove_filter( 'pre_update_option_' . self::REGISTER_OPTION, $refuse, 10 );
				$logs->stop();
				$this->restore_register_schema( $fixture );
			}
		}
	}

	/** A run stopped after the rename resumes from the renamed table and latches. */
	public function test_register_fold_rerun_resumes_from_renamed_table(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_eleven_with_legacy_registers();
		$this->assertNotFalse( $wpdb->query( "RENAME TABLE {$fixture['legacy']} TO {$fixture['legacy']}_folded" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy tables.
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] . '_folded' ) );
			$option = get_option( self::REGISTER_OPTION );
			$this->assertSame( $fixture['rows']['recent'], $option['register'] );
			$this->assertEqualsCanonicalizing( array( $fixture['rows']['idle']['id'], $fixture['rows']['older']['id'] ), array_keys( $option['retired'] ) );
		} finally {
			$this->restore_register_schema( $fixture );
		}
	}

	/** The table is renamed before the copy: a pre-fold write after that fails against the missing table rather than landing unseen. */
	public function test_register_fold_write_after_rename_fails_instead_of_being_lost(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_eleven_with_legacy_registers();
		$late = null;
		$write = static function ( $sql ) use ( $wpdb, $fixture, &$late ) {
			if ( null === $late && 0 === strpos( $sql, 'SELECT r.id' ) ) {
				$late = $wpdb->insert(
					$fixture['legacy'],
					array(
						'id' => wp_generate_uuid4(),
						'name' => 'Late till',
						'created_at_gmt' => '2026-09-12 08:00:00',
						'last_seen_at_gmt' => '2026-09-12 08:00:00',
					)
				);
			}
			return $sql;
		};
		$previous = $wpdb->suppress_errors();
		add_filter( 'query', $write );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertFalse( $late );
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$option = get_option( self::REGISTER_OPTION );
			$this->assertNotContains( 'Late till', array_merge( array( $option['register']['name'] ), array_column( $option['retired'], 'name' ) ) );
		} finally {
			remove_filter( 'query', $write );
			$wpdb->suppress_errors( $previous );
			$this->restore_register_schema( $fixture );
		}
	}

	/** A registers table recreated before the latch write restores the old latch; the next run copies it and latches. */
	public function test_register_fold_recreated_between_fold_and_latch_restores_latch_then_completes(): void {
		// Arrange.
		$fixture = $this->arrange_schema_eleven_with_legacy_registers();
		$straggler = null;
		$recreate = function ( $sql ) use ( $fixture, &$straggler ) {
			if ( null === $straggler && self::writes_latest_schema_latch( $sql ) ) {
				$straggler = $this->add_legacy_register( $fixture['legacy'], 'Straggler', null, '2026-09-12 08:00:00', true );
			}
			return $sql;
		};
		add_filter( 'query', $recreate );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '11' );
			try {
				( new Activator() )->install_sync_schema();
				$this->fail( 'Expected the recreated table to restore the latch.' );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'Register fold found a recreated legacy table.', $error->getMessage() );
			}
			remove_filter( 'query', $recreate );

			// Assert.
			$this->assertSame( '11', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertTrue( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			( new Activator() )->install_sync_schema();
			$this->assertSame( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$this->assertFalse( \WCPOS\WooCommercePOS\Sync\Health::table_exists( $fixture['legacy'] ) );
			$option = get_option( self::REGISTER_OPTION );
			$this->assertSame( $fixture['rows']['recent']['id'], $option['register']['id'] );
			$this->assertSame( 'Straggler', $option['retired'][ $straggler['id'] ]['name'] );
			$this->assertArrayHasKey( $fixture['rows']['older']['id'], $option['retired'] );
		} finally {
			remove_filter( 'query', $recreate );
			$this->restore_register_schema( $fixture );
		}
	}

	/**
	 * Shape a schema-11 install: a legacy registers table holding a register whose session opened
	 * most recently, an older one with an earlier session, and an idle one (oldest, in store 7).
	 *
	 * DDL commits the test transaction, so restore_register_schema() undoes all of it.
	 */
	private function arrange_schema_eleven_with_legacy_registers(): array {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		delete_option( self::REGISTER_OPTION );
		$legacy = $wpdb->prefix . \WCPOS\WooCommercePOS\Services\Register_Store::LEGACY_TABLE;
		$fixture = array(
			'legacy' => $legacy,
			'sessions_table' => ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->table_name(),
			'rows' => array(
				'recent' => $this->add_legacy_register( $legacy, 'Recent till', 12, '2026-09-02 08:00:00', true ),
				'older' => $this->add_legacy_register( $legacy, 'Older till', null, '2026-09-03 08:00:00' ),
				'idle' => $this->add_legacy_register( $legacy, 'Idle till', 7, '2026-09-01 08:00:00' ),
			),
		);
		foreach ( array(
			'recent' => '2026-09-11 08:00:00',
			'older' => '2026-09-10 08:00:00',
		) as $key => $opened ) {
			$this->assertIsArray(
				( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->create(
					array(
						'id' => wp_generate_uuid4(),
						'register_id' => $fixture['rows'][ $key ]['id'],
						'opened_at_gmt' => $opened,
						'opened_by' => 1,
						'expected_float' => null,
						'counted_float' => '100',
					)
				)
			);
		}
		return $fixture;
	}

	/** Insert a register into the legacy table as schema 11 stored it, creating the table if asked.
	 *
	 * @param string   $table Legacy table.
	 * @param string   $name Register name.
	 * @param int|null $store_id Store.
	 * @param string   $created Creation stamp.
	 * @param bool     $create Create the table first when missing.
	 * @return array The row as the option keeps it.
	 */
	private function add_legacy_register( string $table, string $name, ?int $store_id, string $created, bool $create = false ): array {
		global $wpdb;
		if ( $create ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table, fixed DDL.
			$this->assertNotFalse( $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} ( id CHAR(36) NOT NULL, name VARCHAR(191) NOT NULL, store_id BIGINT NULL, default_float DECIMAL(19,4) NULL, platform VARCHAR(16) NOT NULL DEFAULT '', app_version VARCHAR(64) NOT NULL DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'active', counters_started_at_gmt DATETIME NULL, created_at_gmt DATETIME NOT NULL, last_seen_at_gmt DATETIME NOT NULL, PRIMARY KEY  (id), KEY store_status (store_id, status) ) {$wpdb->get_charset_collate()}" ) );
		}
		$row = array(
			'id' => wp_generate_uuid4(),
			'name' => $name,
			'store_id' => $store_id,
			'default_float' => '25.0000',
			'created_at_gmt' => $created,
		);
		$this->assertSame( 1, $wpdb->insert( $table, $row + array( 'last_seen_at_gmt' => $created ) ) );
		return $row;
	}

	/** Drop the legacy table, the option and fixture sessions, and put the latch back.
	 *
	 * @param array $fixture From arrange_schema_eleven_with_legacy_registers().
	 */
	private function restore_register_schema( array $fixture ): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$fixture['legacy']}, {$fixture['legacy']}_folded" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned legacy tables.
		foreach ( $fixture['rows'] as $row ) {
			$wpdb->delete( $fixture['sessions_table'], array( 'register_id' => $row['id'] ) );
		}
		delete_option( self::REGISTER_OPTION );
		update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_VERSION, false );
		$wpdb->query( 'COMMIT' );
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
