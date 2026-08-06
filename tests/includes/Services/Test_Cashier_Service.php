<?php
/**
 * Tests for the WCPOS Cashier Service.
 *
 * Tests the cashier service functionality including:
 * - Singleton instance
 * - UUID generation and persistence
 * - Cashier data retrieval
 * - Store access management
 * - Permission validation
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\FunctionsMockerHack;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\API\Traits\Uuid_Handler;
use WCPOS\WooCommercePOS\Services\Cashier;
use WP_User;

/**
 * Test_Cashier_Service class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Cashier_Service extends WC_Unit_Test_Case {
	/**
	 * The Cashier service instance.
	 *
	 * @var Cashier
	 */
	private $service;

	/**
	 * Test user.
	 *
	 * @var WP_User
	 */
	private $user;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = Cashier::instance();

		// Create a test user with shop_manager role (has POS permissions).
		$user_id    = $this->factory->user->create(
			array(
				'role' => 'shop_manager',
			)
		);
		$this->user = get_user_by( 'id', $user_id );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		FunctionsMockerHack::get_hack_instance()->reset();
		if ( $this->user ) {
			wp_delete_user( $this->user->ID );
		}
		parent::tearDown();
	}

	/**
	 * Simulate a multisite install inside plugin code (CodeHacker only rewrites
	 * calls in includes/, so test code still sees the real single-site functions).
	 *
	 * @param int $blog_id Blog id reported to plugin code.
	 */
	private function mock_multisite( int $blog_id = 2 ): void {
		FunctionsMockerHack::add_function_mocks(
			array(
				'is_multisite'        => function () {
					return true;
				},
				'get_current_blog_id' => function () use ( $blog_id ) {
					return $blog_id;
				},
			)
		);
	}

	/**
	 * Build an object exposing the Uuid_Handler user-uuid path, as used by the
	 * /customers endpoint (Customers_Controller::wcpos_customer_response).
	 *
	 * @return object Object with a public ensure( WP_User $user ): string method.
	 */
	private function customers_uuid_handler() {
		return new class() {
			use Uuid_Handler;

			/**
			 * Serve the user uuid exactly as the /customers endpoint does.
			 *
			 * @param WP_User $user User object.
			 */
			public function ensure( WP_User $user ): string {
				$this->maybe_add_user_uuid( $user );

				return (string) get_user_meta( $user->ID, '_woocommerce_pos_uuid', true );
			}
		};
	}

	/**
	 * Test singleton instance.
	 */
	public function test_singleton_instance(): void {
		$instance1 = Cashier::instance();
		$instance2 = Cashier::instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test get_cashier_uuid generates a valid UUID.
	 */
	public function test_get_cashier_uuid_generates_valid_uuid(): void {
		$uuid = $this->service->get_cashier_uuid( $this->user );

		$this->assertNotEmpty( $uuid );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$uuid
		);
	}

	/**
	 * Test get_cashier_uuid returns consistent UUID.
	 */
	public function test_get_cashier_uuid_is_consistent(): void {
		$uuid1 = $this->service->get_cashier_uuid( $this->user );
		$uuid2 = $this->service->get_cashier_uuid( $this->user );

		$this->assertEquals( $uuid1, $uuid2 );
	}

	/**
	 * Test get_cashier_uuid persists to user meta.
	 */
	public function test_get_cashier_uuid_persists_to_user_meta(): void {
		$uuid = $this->service->get_cashier_uuid( $this->user );

		// Check it was stored in user meta.
		$stored_uuid = get_user_meta( $this->user->ID, '_woocommerce_pos_uuid', true );

		$this->assertEquals( $uuid, $stored_uuid );
	}

	/**
	 * Test get_cashier_uuid generates unique UUIDs for different users.
	 */
	public function test_get_cashier_uuid_unique_per_user(): void {
		$user2_id = $this->factory->user->create(
			array(
				'role' => 'shop_manager',
			)
		);
		$user2    = get_user_by( 'id', $user2_id );

		$uuid1 = $this->service->get_cashier_uuid( $this->user );
		$uuid2 = $this->service->get_cashier_uuid( $user2 );

		$this->assertNotEquals( $uuid1, $uuid2 );

		wp_delete_user( $user2_id );
	}

	/**
	 * Test the cashier uuid matches the identity served by the /customers
	 * endpoint (Uuid_Handler::maybe_add_user_uuid) on multisite.
	 *
	 * The POS client keys its RxDB documents on this uuid, so the same WP_User
	 * must present ONE identity regardless of which endpoint reads them.
	 */
	public function test_get_cashier_uuid_multisite_matches_customers_endpoint_uuid(): void {
		$this->mock_multisite();

		$cashier_uuid  = $this->service->get_cashier_uuid( $this->user );
		$customer_uuid = $this->customers_uuid_handler()->ensure( $this->user );

		$this->assertSame( $customer_uuid, $cashier_uuid );
	}

	/**
	 * Test the cashier uuid is stored under the plain network-wide meta key on
	 * multisite, not a per-blog key.
	 */
	public function test_get_cashier_uuid_multisite_stores_network_wide_key(): void {
		$this->mock_multisite( 2 );

		$uuid = $this->service->get_cashier_uuid( $this->user );

		$this->assertSame( $uuid, get_user_meta( $this->user->ID, '_woocommerce_pos_uuid', true ) );
	}

	/**
	 * Test a legacy per-blog uuid (minted by the old multisite branch) is adopted
	 * as the network identity, so existing multisite cashiers keep their uuid.
	 */
	public function test_get_cashier_uuid_multisite_adopts_legacy_per_blog_uuid(): void {
		$legacy_uuid = wp_generate_uuid4();
		update_user_meta( $this->user->ID, '_woocommerce_pos_uuid_2', $legacy_uuid );
		$this->mock_multisite( 2 );

		$uuid = $this->service->get_cashier_uuid( $this->user );

		$this->assertSame( $legacy_uuid, $uuid );
		$this->assertSame( $legacy_uuid, get_user_meta( $this->user->ID, '_woocommerce_pos_uuid', true ) );
	}

	/**
	 * Test an existing network-wide uuid wins over a legacy per-blog uuid, because
	 * the network uuid is what /customers has already served to clients.
	 */
	public function test_get_cashier_uuid_multisite_prefers_network_uuid_over_legacy(): void {
		$network_uuid = wp_generate_uuid4();
		$legacy_uuid  = wp_generate_uuid4();
		update_user_meta( $this->user->ID, '_woocommerce_pos_uuid', $network_uuid );
		update_user_meta( $this->user->ID, '_woocommerce_pos_uuid_2', $legacy_uuid );
		$this->mock_multisite( 2 );

		$uuid = $this->service->get_cashier_uuid( $this->user );

		$this->assertSame( $network_uuid, $uuid );
	}

	/**
	 * Test a legacy per-blog uuid already owned by ANOTHER user's network identity
	 * is not served as a duplicate RxDB key — a fresh uuid is minted instead.
	 */
	public function test_get_cashier_uuid_multisite_rejects_legacy_uuid_owned_by_other_user(): void {
		$owner_id = $this->factory->user->create( array( 'role' => 'shop_manager' ) );
		$uuid     = wp_generate_uuid4();
		update_user_meta( $owner_id, '_woocommerce_pos_uuid', $uuid );
		update_user_meta( $this->user->ID, '_woocommerce_pos_uuid_2', $uuid );
		$this->mock_multisite( 2 );

		$victim_uuid = $this->service->get_cashier_uuid( $this->user );

		$this->assertNotEmpty( $victim_uuid );
		$this->assertNotSame( $uuid, $victim_uuid );

		wp_delete_user( $owner_id );
	}

	/**
	 * Test an invalid stored value is replaced with a freshly minted valid uuid
	 * (the authority validates uuid shape; the old code served any truthy meta).
	 */
	public function test_get_cashier_uuid_replaces_invalid_stored_uuid(): void {
		update_user_meta( $this->user->ID, '_woocommerce_pos_uuid', 'not-a-valid-uuid' );

		$uuid = $this->service->get_cashier_uuid( $this->user );

		$this->assertNotEquals( 'not-a-valid-uuid', $uuid );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$uuid
		);
		$this->assertSame( $uuid, get_user_meta( $this->user->ID, '_woocommerce_pos_uuid', true ) );
	}

	/**
	 * Test the lock-timeout fallback returns a concurrently persisted winner.
	 */
	public function test_get_cashier_uuid_lock_timeout_preserves_concurrent_insert(): void {
		$winner_uuid   = wp_generate_uuid4();
		$fallback_uuid = wp_generate_uuid4();
		FunctionsMockerHack::add_function_mocks(
			array(
				'usleep'           => function () {
				},
				'wp_cache_add'      => function () {
					return false;
				},
				'wp_generate_uuid4' => function () use ( $winner_uuid, $fallback_uuid ) {
					\add_user_meta( $this->user->ID, '_woocommerce_pos_uuid', $winner_uuid, true );

					return $fallback_uuid;
				},
			)
		);

		$uuid = $this->service->get_cashier_uuid( $this->user );

		$this->assertSame( $winner_uuid, $uuid );
		$this->assertSame( $winner_uuid, get_user_meta( $this->user->ID, '_woocommerce_pos_uuid', true ) );
	}

	/**
	 * Test get_cashier_data returns expected structure.
	 */
	public function test_get_cashier_data_structure(): void {
		$data = $this->service->get_cashier_data( $this->user );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'uuid', $data );
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'username', $data );
		$this->assertArrayHasKey( 'first_name', $data );
		$this->assertArrayHasKey( 'last_name', $data );
		$this->assertArrayHasKey( 'email', $data );
		$this->assertArrayHasKey( 'display_name', $data );
		$this->assertArrayHasKey( 'nice_name', $data );
		$this->assertArrayHasKey( 'roles', $data );
		$this->assertArrayHasKey( 'last_access', $data );
		$this->assertArrayHasKey( 'avatar_url', $data );
		$this->assertArrayHasKey( 'stores', $data );
	}

	/**
	 * Test get_cashier_data returns correct user data.
	 */
	public function test_get_cashier_data_returns_correct_user_data(): void {
		$data = $this->service->get_cashier_data( $this->user );

		$this->assertEquals( $this->user->ID, $data['id'] );
		$this->assertEquals( $this->user->user_login, $data['username'] );
		$this->assertEquals( $this->user->user_email, $data['email'] );
		$this->assertSame( $this->user->roles, $data['roles'] );
	}

	/**
	 * Test get_cashier_data without stores.
	 */
	public function test_get_cashier_data_without_stores(): void {
		$data = $this->service->get_cashier_data( $this->user, false );

		$this->assertArrayNotHasKey( 'stores', $data );
	}

	/**
	 * Test get_cashier_data with stores.
	 */
	public function test_get_cashier_data_with_stores(): void {
		$data = $this->service->get_cashier_data( $this->user, true );

		$this->assertArrayHasKey( 'stores', $data );
		$this->assertIsArray( $data['stores'] );
	}

	/**
	 * Test get_accessible_stores returns array.
	 */
	public function test_get_accessible_stores_returns_array(): void {
		$stores = $this->service->get_accessible_stores( $this->user );

		$this->assertIsArray( $stores );
	}

	/**
	 * Test has_store_access.
	 */
	public function test_has_store_access(): void {
		$stores = $this->service->get_accessible_stores( $this->user );

		if ( empty( $stores ) ) {
			$this->markTestSkipped( 'No stores available for testing' );
		}

		$store_id   = $stores[0]->get_id();
		$has_access = $this->service->has_store_access( $this->user, $store_id );

		$this->assertTrue( $has_access );
	}

	/**
	 * Test has_store_access returns false for non-existent store.
	 */
	public function test_has_store_access_returns_false_for_non_existent_store(): void {
		$has_access = $this->service->has_store_access( $this->user, 999999 );

		$this->assertFalse( $has_access );
	}

	/**
	 * Test get_accessible_store returns store when accessible.
	 */
	public function test_get_accessible_store_returns_store(): void {
		$stores = $this->service->get_accessible_stores( $this->user );

		if ( empty( $stores ) ) {
			$this->markTestSkipped( 'No stores available for testing' );
		}

		$store_id = $stores[0]->get_id();
		$store    = $this->service->get_accessible_store( $this->user, $store_id );

		$this->assertNotNull( $store );
		$this->assertEquals( $store_id, $store->get_id() );
	}

	/**
	 * Test get_accessible_store returns null for non-accessible store.
	 */
	public function test_get_accessible_store_returns_null_for_non_accessible_store(): void {
		$store = $this->service->get_accessible_store( $this->user, 999999 );

		$this->assertNull( $store );
	}

	/**
	 * Test update_last_access updates user meta.
	 */
	public function test_update_last_access_updates_user_meta(): void {
		$timestamp = '2024-01-15 10:30:00';

		$result = $this->service->update_last_access( $this->user, $timestamp );

		$this->assertTrue( (bool) $result );

		$stored_timestamp = get_user_meta( $this->user->ID, '_woocommerce_pos_last_access', true );
		$this->assertEquals( $timestamp, $stored_timestamp );
	}

	/**
	 * Test update_last_access uses current time when no timestamp provided.
	 */
	public function test_update_last_access_uses_current_time(): void {
		$result = $this->service->update_last_access( $this->user );

		$this->assertTrue( (bool) $result );

		$stored_timestamp = get_user_meta( $this->user->ID, '_woocommerce_pos_last_access', true );
		$this->assertNotEmpty( $stored_timestamp );
	}

	/**
	 * Test has_cashier_permissions returns true for shop_manager.
	 */
	public function test_has_cashier_permissions_for_shop_manager(): void {
		$has_permissions = $this->service->has_cashier_permissions( $this->user );

		$this->assertTrue( $has_permissions );
	}

	/**
	 * Test has_cashier_permissions returns true for administrator.
	 */
	public function test_has_cashier_permissions_for_administrator(): void {
		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		$admin    = get_user_by( 'id', $admin_id );

		$has_permissions = $this->service->has_cashier_permissions( $admin );

		$this->assertTrue( $has_permissions );

		wp_delete_user( $admin_id );
	}

	/**
	 * Test has_cashier_permissions returns false for subscriber.
	 */
	public function test_has_cashier_permissions_returns_false_for_subscriber(): void {
		$subscriber_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		$subscriber    = get_user_by( 'id', $subscriber_id );

		$has_permissions = $this->service->has_cashier_permissions( $subscriber );

		$this->assertFalse( $has_permissions );

		wp_delete_user( $subscriber_id );
	}

	/**
	 * Test validate_cashier_access allows access to own data.
	 */
	public function test_validate_cashier_access_allows_own_data(): void {
		$is_valid = $this->service->validate_cashier_access( $this->user->ID, $this->user->ID );

		$this->assertTrue( $is_valid );
	}

	/**
	 * Test validate_cashier_access allows admin to access other user.
	 */
	public function test_validate_cashier_access_allows_admin_to_access_other(): void {
		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $admin_id );

		$is_valid = $this->service->validate_cashier_access( $admin_id, $this->user->ID );

		$this->assertTrue( $is_valid );

		wp_delete_user( $admin_id );
	}

	/**
	 * Test validate_cashier_access denies access for users without manage_woocommerce.
	 *
	 * Note: shop_manager has manage_woocommerce capability, so they CAN access other users.
	 * Only users without this capability are denied.
	 */
	public function test_validate_cashier_access_denies_non_manager_access_to_other(): void {
		// Create a user without manage_woocommerce capability.
		$editor_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $editor_id );

		$is_valid = $this->service->validate_cashier_access( $editor_id, $this->user->ID );

		$this->assertFalse( $is_valid );

		wp_delete_user( $editor_id );
	}

	/**
	 * Test validate_cashier_access allows shop_manager to access other user.
	 *
	 * Shop managers have manage_woocommerce capability, so they can access other users' data.
	 */
	public function test_validate_cashier_access_allows_shop_manager_to_access_other(): void {
		$shop_manager_id = $this->factory->user->create(
			array(
				'role' => 'shop_manager',
			)
		);
		wp_set_current_user( $shop_manager_id );

		$is_valid = $this->service->validate_cashier_access( $shop_manager_id, $this->user->ID );

		$this->assertTrue( $is_valid );

		wp_delete_user( $shop_manager_id );
	}

	/**
	 * Test cashier data filter is applied.
	 */
	public function test_cashier_data_filter_applied(): void {
		add_filter(
			'woocommerce_pos_cashier_data',
			function ( $data, $user, $include_stores ) {
				$data['custom_field'] = 'filtered_value';

				return $data;
			},
			10,
			3
		);

		$data = $this->service->get_cashier_data( $this->user );

		$this->assertArrayHasKey( 'custom_field', $data );
		$this->assertEquals( 'filtered_value', $data['custom_field'] );

		remove_all_filters( 'woocommerce_pos_cashier_data' );
	}

	/**
	 * Test accessible stores filter is applied.
	 */
	public function test_accessible_stores_filter_applied(): void {
		add_filter(
			'woocommerce_pos_cashier_accessible_stores',
			function ( $stores, $user ) {
				// Return empty array to test filter.
				return array();
			},
			10,
			2
		);

		$stores = $this->service->get_accessible_stores( $this->user );

		$this->assertEmpty( $stores );

		remove_all_filters( 'woocommerce_pos_cashier_accessible_stores' );
	}
}
