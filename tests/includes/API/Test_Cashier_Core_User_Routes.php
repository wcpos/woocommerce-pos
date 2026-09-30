<?php
/**
 * A stock Cashier must not edit staff accounts through WordPress core or wc/v3.
 *
 * The staff rule from #1918 (Permission_Rules::can_modify()) must hold wherever
 * WordPress checks a user edit, not only on the POS lanes (#2104). The
 * administrator cases pass without it (WooCommerce's wc_modify_map_meta_cap).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WP_REST_Request;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Cashier_Core_User_Routes extends WCPOS_REST_Unit_Test_Case {
	/** @var int */
	private $cashier;
	/** @var int */
	private $shop_manager;
	/** @var int */
	private $admin;
	/** @var int */
	private $other_cashier;
	/** @var int */
	private $customer;

	public function setUp(): void {
		parent::setUp();
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite already denies edit_users without manage_network_users.' );
		}
		$this->cashier       = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$this->other_cashier = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$this->shop_manager  = $this->factory->user->create( array( 'role' => 'shop_manager', 'user_email' => 'sm@example.test' ) );
		$this->admin         = $this->factory->user->create( array( 'role' => 'administrator', 'user_email' => 'admin@example.test' ) );
		$this->customer      = $this->factory->user->create( array( 'role' => 'customer', 'user_email' => 'c@example.test' ) );
		wp_set_current_user( $this->cashier );
	}

	/** The capability every core route consults. */
	public function test_cashier_lacks_edit_user_on_staff(): void {
		$this->assertFalse( current_user_can( 'edit_user', $this->admin ), 'admin' );
		$this->assertFalse( current_user_can( 'edit_user', $this->shop_manager ), 'shop manager' ); // FAILS on main.
		$this->assertFalse( current_user_can( 'edit_user', $this->other_cashier ), 'other cashier' ); // FAILS on main.
		// wc_rest_check_user_permissions() asks edit_users with the object id.
		$this->assertFalse( current_user_can( 'edit_users', $this->shop_manager ), 'wc/v3 form' ); // FAILS on main.
	}

	/** Legitimate till work keeps working. */
	public function test_cashier_keeps_customer_and_self_edits(): void {
		$this->assertTrue( current_user_can( 'edit_user', $this->customer ) );
		$this->assertTrue( current_user_can( 'edit_user', $this->cashier ) );
	}

	/** Core REST: set a shop manager's password (takeover of manage_woocommerce). */
	public function test_wp_v2_users_cannot_reset_shop_manager_password(): void {
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $this->shop_manager );
		$request->set_body_params( array( 'password' => 'attacker-chosen-1!', 'email' => 'attacker@example.test' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) ); // FAILS on main: 200.
		$this->assertSame( 'sm@example.test', get_userdata( $this->shop_manager )->user_email );
	}

	/** Core REST: administrators are already fenced by WooCommerce. */
	public function test_wp_v2_users_cannot_reset_admin_password(): void {
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $this->admin );
		$request->set_body_params( array( 'password' => 'attacker-chosen-1!' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/** wc/v3 answers the POS bearer token too; profile fields of an admin must be fenced. */
	public function test_wc_v3_customers_cannot_edit_admin_profile(): void {
		$request = new WP_REST_Request( 'PUT', '/wc/v3/customers/' . $this->admin );
		$request->set_body_params( array( 'first_name' => 'Owned' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) ); // FAILS on main: 200.
	}

	/** Customers stay editable at the till through wc/v3 and core alike. */
	public function test_customer_edits_still_allowed(): void {
		$request = new WP_REST_Request( 'PUT', '/wc/v3/customers/' . $this->customer );
		$request->set_body_params( array( 'first_name' => 'Walk-in' ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}
}
