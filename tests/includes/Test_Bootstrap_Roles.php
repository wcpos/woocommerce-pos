<?php
/**
 * The test bootstrap must leave the role objects holding WooCommerce's capabilities.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use WP_UnitTestCase;

/**
 * Guards the roles rebuild in tests/bootstrap.php.
 *
 * WooCommerce grants its capabilities through WP_Roles::add_cap(), which never
 * reaches the WP_Role objects that capability checks read. Without the rebuild a
 * fresh test database (the wordpress-develop trunk library, hence every RC/beta
 * matrix lane) leaves administrators and shop managers without a single
 * WooCommerce capability and every REST test answers 403.
 *
 * @internal
 */
class Test_Bootstrap_Roles extends WP_UnitTestCase {
	/**
	 * The role objects, not just the roles option, carry WooCommerce's capabilities.
	 *
	 * @dataProvider provide_woocommerce_roles
	 *
	 * @param string $slug Role slug.
	 */
	public function test_role_object_holds_woocommerce_capabilities( string $slug ): void {
		$role = get_role( $slug );

		$this->assertNotNull( $role, "The {$slug} role is missing." );
		$this->assertTrue( $role->has_cap( 'manage_woocommerce' ), "The {$slug} role object lacks manage_woocommerce." );
		$this->assertTrue( $role->has_cap( 'edit_shop_orders' ), "The {$slug} role object lacks edit_shop_orders." );
		$this->assertTrue( $role->has_cap( 'access_woocommerce_pos' ), "The {$slug} role object lacks access_woocommerce_pos." );
	}

	/**
	 * A user created during the run resolves WooCommerce capabilities through those objects.
	 *
	 * @dataProvider provide_woocommerce_roles
	 *
	 * @param string $slug Role slug.
	 */
	public function test_fresh_user_resolves_woocommerce_capabilities( string $slug ): void {
		$user_id = self::factory()->user->create( array( 'role' => $slug ) );

		$this->assertTrue( user_can( $user_id, 'manage_woocommerce' ), "A fresh {$slug} cannot manage_woocommerce." );
		$this->assertTrue( user_can( $user_id, 'publish_shop_orders' ), "A fresh {$slug} cannot publish_shop_orders." );
	}

	/**
	 * Roles WooCommerce grants its management capabilities to.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_woocommerce_roles(): array {
		return array(
			'administrator' => array( 'administrator' ),
			'shop_manager'  => array( 'shop_manager' ),
		);
	}
}
