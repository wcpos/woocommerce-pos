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

	/** Both activation and the versioned upgrade install missing bookkeeping tables. */
	public function test_activation_and_upgrade_install_session_and_movement_tables(): void {
		global $wpdb;
		$stores = array( new \WCPOS\WooCommercePOS\Services\Register_Session_Store(), new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store() );
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
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
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
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
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
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
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
		$bump = static function ( $sql ) use ( $fixture, &$bumped ) {
			if ( ! $bumped && 0 === strpos( $sql, "RENAME TABLE {$fixture['legacy']}" ) ) {
				$bumped = true;
				$GLOBALS['wpdb']->update(
					$fixture['legacy'],
					array(
						'print_count' => 3,
						'last_printed_at_gmt' => '2026-09-11 14:00:00',
					),
					array( 'id' => $fixture['closures'][0] )
				);
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
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			$closure = ( new \WCPOS\WooCommercePOS\Services\Closure_Store() )->get( $fixture['closures'][0] );
			$this->assertSame( 3, $closure['print_count'] );
			$this->assertSame( '2026-09-11 14:00:00', $closure['last_printed_at_gmt'] );
		} finally {
			remove_filter( 'query', $bump );
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
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
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
		add_filter( 'woocommerce_pos_logging', $capture, 10, 2 );
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
			remove_filter( 'woocommerce_pos_logging', $capture, 10 );
			$this->restore_closure_schema( $fixture );
		}
	}

	/** A schema-9 install without an active register is seeded before the fold adds the closure columns. */
	public function test_schema_nine_install_without_register_seeds_one_and_latches(): void {
		// Arrange.
		global $wpdb;
		$fixture = $this->arrange_schema_nine_with_legacy_closures();
		$registers = ( new \WCPOS\WooCommercePOS\Services\Register_Store() )->table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
		$active = $wpdb->get_col( "SELECT id FROM {$registers} WHERE status = 'active'" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
		$wpdb->query( "UPDATE {$registers} SET status = 'archived' WHERE status = 'active'" );
		try {
			// Act.
			update_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION, '9' );
			( new Activator() )->install_sync_schema();

			// Assert.
			$this->assertSame( '10', get_option( \WCPOS\WooCommercePOS\Sync\Api::SCHEMA_OPTION ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
			$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$registers} WHERE status = 'active'" ) );
		} finally {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Owned table.
			$wpdb->query( "DELETE FROM {$registers} WHERE status = 'active'" );
			foreach ( $active as $id ) {
				$wpdb->update( $registers, array( 'status' => 'active' ), array( 'id' => $id ) );
			}
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
		add_filter( 'woocommerce_pos_logging', $capture, 10, 2 );
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
			remove_filter( 'woocommerce_pos_logging', $capture, 10 );
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
		// The table as schema 9 created it on dev builds of `next`.
		$this->assertNotFalse(
			$wpdb->query(
				"CREATE TABLE {$fixture['legacy']} (
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
			) {$wpdb->get_charset_collate()}"
			)
		);
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

	/** Close one more fixture session and record its closure in a legacy table.
	 *
	 * @param array  $fixture From arrange_schema_nine_with_legacy_closures().
	 * @param string $table Legacy table to insert into.
	 * @param int    $number Closure number.
	 * @return string Closure id.
	 */
	private function add_legacy_closure( array $fixture, string $table, int $number ): string {
		global $wpdb;
		$session = wp_generate_uuid4();
		$closure = wp_generate_uuid4();
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
					'closure_id' => $closure,
					'created_at_gmt' => '2026-09-11 08:00:00',
				)
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned tables; ids are prepared.
		$copied = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} SELECT %s, register_id, %s, store_id, %d, NULL, opened_at_gmt, business_day, closed_at_gmt, opened_by, closed_by, approved_by, expected, till_expected, counted, variance, period_sales_total, period_refunds_total, perpetual_sales_total, perpetual_refunds_total, unsynced_count, unsynced_total, first_sale_counter, last_sale_counter, first_receipt_id, last_receipt_id, software_version, printed_at_gmt, breakdowns, findings, 0, NULL, received_at_gmt FROM {$table} WHERE id = %s", $closure, $session, $number, $fixture['closures'][0] ) );
		$this->assertSame( 1, $copied );
		return $closure;
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
