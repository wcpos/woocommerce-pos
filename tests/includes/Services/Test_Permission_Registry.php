<?php
/**
 * Frozen capability contracts and registry integration tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use ReflectionMethod;
use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Services\Cashier;
use WCPOS\WooCommercePOS\Services\Permission_Registry;
use WCPOS\WooCommercePOS\Services\Settings\Access_Section;
use WP_UnitTestCase;
use WP_User;

/**
 * Registry contracts.
 *
 * @internal
 * @coversNothing
 */
class Test_Permission_Registry extends WP_UnitTestCase {
	/** Reset the test seam and in-memory role state. */
	public function tearDown(): void {
		Permission_Registry::reset_test_entries();
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_user_roles'] );
		global $wp_roles;
		$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore role state after capability tests.
		parent::tearDown();
	}

	/** Freeze the original API groups and their order. */
	public function test_access_capability_groups_match_the_two_point_zero_lists(): void {
		// WC 9.9 replaced promote_users with create_customers for customer creation.
		$customer_create_cap = \defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '>=' ) // @phpstan-ignore-line
			? 'create_customers'
			: 'promote_users';

		$expected = array(
			'wcpos' => array(
				'access_woocommerce_pos',
				'manage_woocommerce_pos',
				'manage_woocommerce_pos_cash',
				'view_woocommerce_pos_reports',
				'manage_woocommerce_pos_closures',
				'reassign_woocommerce_pos_sales',
				'view_woocommerce_pos_costs',
			),
			'wc' => array(
				$customer_create_cap,
				'read_private_products',
				'edit_product',
				'edit_products',
				'edit_others_products',
				'edit_private_products',
				'edit_published_products',
				'publish_products',
				'delete_product',
				'delete_products',
				'delete_others_products',
				'delete_private_products',
				'delete_published_products',
				'read_private_shop_orders',
				'publish_shop_orders',
				'edit_shop_orders',
				'edit_others_shop_orders',
				'edit_users',
				'list_users',
				'manage_product_terms',
				'read_private_shop_coupons',
				'edit_shop_coupons',
				'edit_others_shop_coupons',
				'edit_private_shop_coupons',
				'edit_published_shop_coupons',
				'publish_shop_coupons',
				'delete_shop_coupons',
				'delete_others_shop_coupons',
				'delete_private_shop_coupons',
				'delete_published_shop_coupons',
			),
			'wp' => array(
				'read',
			),
		);
		$method = new ReflectionMethod( Access_Section::class, 'get_caps' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( null ) );
	}

	/** Freeze the original default grants, allowing only their order to change. */
	public function test_role_capability_definition_matches_the_two_point_zero_definition(): void {
		// WC 9.9 replaced promote_users with create_customers for customer creation.
		$customer_create_cap = \defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9', '>=' ) // @phpstan-ignore-line
			? 'create_customers'
			: 'promote_users';

		// Cashier role.
		$cashier_capabilities = array(
			'manage_woocommerce_pos_cash'  => true,
			'view_woocommerce_pos_reports' => true,
			'read'                         => true,
			'read_private_products'        => true,
			'publish_products'             => true,
			'edit_product'                 => true,
			'edit_products'                => true,
			'edit_published_products'      => true,
			'edit_private_products'        => true,
			'edit_others_products'         => true,
			'read_private_shop_orders'     => true,
			'publish_shop_orders'          => true,
			'edit_shop_orders'             => true,
			'edit_others_shop_orders'      => true,
			'list_users'                   => true,
			$customer_create_cap           => true,
			'edit_users'                   => true,
			'read_private_shop_coupons'    => true,
			'publish_shop_coupons'         => true,
			'edit_shop_coupons'            => true,
			'edit_published_shop_coupons'  => true,
			'edit_private_shop_coupons'    => true,
			'edit_others_shop_coupons'     => true,
			'manage_product_terms'         => true,
			'view_woocommerce_pos_costs'   => true,
		);

		$expected = array(
			'cashier'       => $cashier_capabilities,
			'administrator' => array(
				'manage_woocommerce_pos',
				'manage_woocommerce_pos_cash',
				'view_woocommerce_pos_reports',
				'manage_woocommerce_pos_closures',
				'reassign_woocommerce_pos_sales',
				'access_woocommerce_pos',
				'edit_wcpos_store',
				'read_wcpos_store',
				'delete_wcpos_store',
				'edit_wcpos_stores',
				'edit_others_wcpos_stores',
				'publish_wcpos_stores',
				'read_private_wcpos_stores',
				'delete_wcpos_stores',
				'delete_private_wcpos_stores',
				'delete_published_wcpos_stores',
				'delete_others_wcpos_stores',
				'edit_private_wcpos_stores',
				'edit_published_wcpos_stores',
				'view_woocommerce_pos_costs',
			),
			'shop_manager'  => array(
				'manage_woocommerce_pos',
				'access_woocommerce_pos',
				'manage_woocommerce_pos_cash',
				'view_woocommerce_pos_reports',
				'manage_woocommerce_pos_closures',
				'reassign_woocommerce_pos_sales',
				'view_woocommerce_pos_costs',
			),
		);
		$actual = Activator::role_capability_definition();
		$this->assertEqualsCanonicalizing( array_keys( $expected['cashier'] ), array_keys( $actual['cashier'] ) );
		foreach ( $actual['cashier'] as $grant ) {
			$this->assertSame( true, $grant );
		}
		foreach ( array( 'administrator', 'shop_manager' ) as $role ) {
			$this->assertEqualsCanonicalizing( $expected[ $role ], $actual[ $role ] );
		}
	}

	/** Access vocabulary and activation defaults come from the registry. */
	public function test_registry_is_the_only_capability_source(): void {
		$this->assertSame( Permission_Registry::capability_names(), Access_Section::capability_names() );
		$definition = Activator::role_capability_definition();
		foreach ( Permission_Registry::defaults_for_role( 'cashier' ) as $capability ) {
			$this->assertContains( $capability, array_merge( array( 'access_woocommerce_pos' ), array_keys( $definition['cashier'] ) ) );
		}

		( new Activator() )->single_activate( false );

		foreach ( array( 'administrator', 'shop_manager', 'cashier' ) as $role ) {
			foreach ( Permission_Registry::defaults_for_role( $role ) as $capability ) {
				$this->assertTrue( get_role( $role )->has_cap( $capability ), $role . ': ' . $capability );
			}
		}
	}

	/** A fresh cashier is served exactly its registry defaults, in order. */
	public function test_effective_capabilities_of_a_default_cashier_are_its_registry_defaults(): void {
		remove_role( 'cashier' );
		( new Activator() )->single_activate( false );
		$user = new WP_User( self::factory()->user->create( array( 'role' => 'cashier' ) ) );

		$this->assertSame( Permission_Registry::defaults_for_role( 'cashier' ), Access_Section::effective_capabilities( $user ) );
	}

	/** Every owner-facing row has a translated label. */
	public function test_labels_name_every_owner_facing_row(): void {
		$rows = array_values( array_diff( array_unique( array_column( Permission_Registry::entries(), 'row' ) ), array( 'pos_prerequisites' ) ) );
		$this->assertSame( $rows, array_keys( Permission_Registry::labels() ) );
		foreach ( Permission_Registry::labels() as $label ) {
			$this->assertNotEmpty( $label );
		}
	}

	/** Seeing cost is a data permission introduced in 2.2.0 under its own label. */
	public function test_view_costs_is_a_data_permission_introduced_in_two_point_two(): void {
		$entries = array_column( Permission_Registry::entries(), null, 'capability' );

		$this->assertArrayHasKey( 'view_woocommerce_pos_costs', $entries );
		$entry = $entries['view_woocommerce_pos_costs'];
		$this->assertSame( 'view_costs', $entry['row'] );
		$this->assertSame( 'products', $entry['group'] );
		$this->assertSame( 'data', $entry['kind'] );
		$this->assertSame( 'store', $entry['tier'] );
		$this->assertSame( array( 'administrator', 'shop_manager', 'cashier' ), $entry['defaults'] );
		$this->assertSame( '2.2.0', $entry['introduced'] );
		$this->assertNull( $entry['split_from'] );
		$this->assertSame( 'See cost, profit and margin', Permission_Registry::labels()['view_costs'] );
	}

	/** A capability exposed by an extension is served when the cashier holds it. */
	public function test_filter_added_capability_reaches_the_cashier_record(): void {
		$filter = static function ( $settings ) {
			$settings['cashier']['capabilities']['wcpos']['wcpos_test_extra_cap'] = get_role( 'cashier' )->has_cap( 'wcpos_test_extra_cap' );
			return $settings;
		};
		add_filter( 'wcpos_access_settings', $filter );
		get_role( 'cashier' )->add_cap( 'wcpos_test_extra_cap' );
		try {
			$user = new WP_User( self::factory()->user->create( array( 'role' => 'cashier' ) ) );
			$data = Cashier::instance()->get_cashier_data( $user, false );

			$this->assertContains( 'wcpos_test_extra_cap', $data['capabilities'] );
		} finally {
			remove_filter( 'wcpos_access_settings', $filter );
			get_role( 'cashier' )->remove_cap( 'wcpos_test_extra_cap' );
		}
	}
}
