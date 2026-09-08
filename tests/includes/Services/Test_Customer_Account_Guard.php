<?php
/**
 * Tests for the staff account capability guard.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Customer_Account_Guard;
use WP_UnitTestCase;

/**
 * Staff account guard tests.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Customer_Account_Guard
 */
class Test_Customer_Account_Guard extends WP_UnitTestCase {
	/**
	 * Verify administrator actor may modify staff.
	 */
	public function test_administrator_actor_may_modify_staff(): void {
		$actor_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$target_id = $this->factory->user->create( array( 'role' => 'shop_manager' ) );

		$allowed = Customer_Account_Guard::can_modify( $actor_id, $target_id );

		$this->assertTrue( $allowed );
		wp_delete_user( $actor_id );
		wp_delete_user( $target_id );
	}

	/**
	 * Verify self edit is allowed.
	 */
	public function test_self_edit_is_allowed(): void {
		$actor_id = $this->factory->user->create( array( 'role' => 'cashier' ) );

		$allowed = Customer_Account_Guard::can_modify( $actor_id, $actor_id );

		$this->assertTrue( $allowed );
		wp_delete_user( $actor_id );
	}

	/**
	 * Verify cashier tier actor cannot modify manage woocommerce target.
	 */
	public function test_cashier_tier_actor_cannot_modify_manage_woocommerce_target(): void {
		$actor_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$target_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $actor_id )->add_cap( 'edit_users' );
		get_user_by( 'id', $target_id )->add_cap( 'manage_woocommerce' );

		$allowed = Customer_Account_Guard::can_modify( $actor_id, $target_id );

		$this->assertFalse( $allowed );
		wp_delete_user( $actor_id );
		wp_delete_user( $target_id );
	}

	/**
	 * Verify customer role target is allowed.
	 */
	public function test_customer_role_target_is_allowed(): void {
		$actor_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$target_id = $this->factory->user->create( array( 'role' => 'customer' ) );

		$allowed = Customer_Account_Guard::can_modify( $actor_id, $target_id );

		$this->assertTrue( $allowed );
		wp_delete_user( $actor_id );
		wp_delete_user( $target_id );
	}

	/**
	 * An unresolvable target is denied rather than waved through.
	 */
	public function test_unknown_target_is_denied(): void {
		$actor_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$target_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		wp_delete_user( $target_id );

		$allowed = Customer_Account_Guard::can_modify( $actor_id, $target_id );

		$this->assertFalse( $allowed );
		wp_delete_user( $actor_id );
	}

	/**
	 * A logged-out actor is denied, including against user ID zero.
	 */
	public function test_missing_actor_is_denied(): void {
		$this->assertFalse( Customer_Account_Guard::can_modify( 0, 0 ) );
	}

	/**
	 * The target's own roles are allowed through, then restored.
	 */
	public function test_allow_target_roles_adds_target_roles_and_restores(): void {
		$target_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$restore = Customer_Account_Guard::allow_target_roles( $target_id );
		$during  = apply_filters( 'woocommerce_shop_manager_editable_roles', array( 'customer' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook, applied here only to observe the filter.
		$restore();
		$after = apply_filters( 'woocommerce_shop_manager_editable_roles', array( 'customer' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook, applied here only to observe the filter.

		$this->assertContains( 'subscriber', $during );
		$this->assertSame( array( 'customer' ), $after );
		wp_delete_user( $target_id );
	}

	/**
	 * Filtered capabilities are normalized and de-duplicated.
	 */
	public function test_protected_capabilities_filter_is_honoured_and_deduplicated(): void {
		$filter = static function () {
			return array( 'custom_staff', '', 'custom_staff' );
		};
		add_filter( 'woocommerce_pos_protected_account_capabilities', $filter );

		try {
			$capabilities = Customer_Account_Guard::protected_capabilities();

			$this->assertSame( array( 'custom_staff' ), $capabilities );
		} finally {
			remove_filter( 'woocommerce_pos_protected_account_capabilities', $filter );
		}
	}
}
