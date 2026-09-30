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

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Permission_Rules;
use WP_REST_Request;

/**
 * Test_Cashier_Core_User_Routes class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Cashier_Core_User_Routes extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Stock Cashier, the acting user.
	 *
	 * @var int
	 */
	private $cashier;
	/**
	 * Shop manager target.
	 *
	 * @var int
	 */
	private $shop_manager;
	/**
	 * Administrator target.
	 *
	 * @var int
	 */
	private $admin;
	/**
	 * Second Cashier target.
	 *
	 * @var int
	 */
	private $other_cashier;
	/**
	 * Customer target.
	 *
	 * @var int
	 */
	private $customer;

	/**
	 * Create the accounts and sign in as the Cashier.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite already denies edit_users without manage_network_users.' );
		}
		$this->cashier       = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$this->other_cashier = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$this->shop_manager  = $this->factory->user->create(
			array(
				'role'       => 'shop_manager',
				'user_email' => 'sm@example.test',
			)
		);
		$this->admin         = $this->factory->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'admin@example.test',
			)
		);
		$this->customer      = $this->factory->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'c@example.test',
			)
		);
		wp_set_current_user( $this->cashier );
	}

	/**
	 * Remove the bearer header and any role a test added.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		remove_role( 'user_manager_no_pos' );
		remove_role( 'till_lead' );
		parent::tearDown();
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
		$request->set_body_params(
			array(
				'password' => 'attacker-chosen-1!',
				'email'    => 'attacker@example.test',
			)
		);
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

	/** The wc/v3 route answers the POS bearer token too; profile fields of an admin must be fenced. */
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

	/** A Cashier signed in only by its POS bearer token cannot change a shop manager's credentials. */
	public function test_wp_v2_users_bearer_token_cashier_cannot_change_shop_manager_credentials(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$tokens = Auth::instance()->generate_token_pair( get_user_by( 'id', $this->cashier ) );
		$this->assertIsArray( $tokens );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_get_current_user();
		$this->assertSame( $this->cashier, get_current_user_id(), 'The bearer token must authenticate the Cashier.' );
		$hash_before = get_userdata( $this->shop_manager )->user_pass;
		$request     = new WP_REST_Request( 'POST', '/wp/v2/users/' . $this->shop_manager );
		$request->set_body_params(
			array(
				'email'    => 'attacker@example.test',
				'password' => 'attacker-chosen-1!',
			)
		);

		// Act.
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( 'sm@example.test', get_userdata( $this->shop_manager )->user_email );
		$this->assertSame( $hash_before, get_userdata( $this->shop_manager )->user_pass );
	}

	/** A customer who also holds author is staff by capability, whatever the first role says. */
	public function test_edit_user_customer_with_author_role_is_denied(): void {
		// Arrange.
		$target = $this->factory->user->create( array( 'role' => 'customer' ) );
		get_user_by( 'id', $target )->add_role( 'author' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $target );
		$request->set_body_params( array( 'email' => 'attacker@example.test' ) );

		// Act.
		$can_edit = current_user_can( 'edit_user', $target );
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertFalse( $can_edit );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/** A subscriber stays editable at the till (#1918). */
	public function test_edit_user_subscriber_target_is_allowed(): void {
		// Arrange.
		$target = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Act.
		$can_edit = current_user_can( 'edit_user', $target );

		// Assert.
		$this->assertTrue( $can_edit );
	}

	/** The Cashier keeps editing its own profile. */
	public function test_edit_user_own_profile_is_allowed(): void {
		// Arrange.
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_body_params( array( 'first_name' => 'Till' ) );

		// Act.
		$can_edit = current_user_can( 'edit_user', $this->cashier );
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertTrue( $can_edit );
		$this->assertSame( 200, $response->get_status() );
	}

	/** Administrators, shop managers and roles without till access see exactly what they saw before. */
	public function test_user_meta_caps_actors_outside_the_rule_are_unchanged(): void {
		// Arrange.
		add_role(
			'user_manager_no_pos',
			'User manager without POS',
			array(
				'read'       => true,
				'list_users' => true,
				'edit_users' => true,
			)
		);
		$multi_role = $this->factory->user->create( array( 'role' => 'customer' ) );
		get_user_by( 'id', $multi_role )->add_role( 'author' );
		$actors  = array(
			'administrator'       => $this->admin,
			'shop_manager'        => $this->shop_manager,
			'user_manager_no_pos' => $this->factory->user->create( array( 'role' => 'user_manager_no_pos' ) ),
		);
		$targets = array(
			'administrator'   => $this->factory->user->create( array( 'role' => 'administrator' ) ),
			'shop_manager'    => $this->factory->user->create( array( 'role' => 'shop_manager' ) ),
			'editor'          => $this->factory->user->create( array( 'role' => 'editor' ) ),
			'customer'        => $this->customer,
			'customer+author' => $multi_role,
			'cashier'         => $this->other_cashier,
		);
		$caps    = array( 'edit_user', 'delete_user', 'promote_user', 'edit_users' );
		$filter  = array( Permission_Rules::class, 'map_user_meta_caps' );

		foreach ( $actors as $actor_name => $actor ) {
			foreach ( $targets as $target_name => $target ) {
				foreach ( $caps as $cap ) {
					// Act.
					$with = user_can( $actor, $cap, $target );
					remove_filter( 'map_meta_cap', $filter, 10 );
					try {
						$without = user_can( $actor, $cap, $target );
					} finally {
						add_filter( 'map_meta_cap', $filter, 10, 4 );
					}

					// Assert.
					$this->assertSame( $without, $with, $actor_name . ' ' . $cap . ' ' . $target_name );
				}
			}
		}
	}

	/** A custom role with till access and user caps is held to the same rule as a Cashier. */
	public function test_user_meta_caps_custom_role_with_pos_access_is_governed(): void {
		// Arrange.
		add_role(
			'till_lead',
			'Till lead',
			array(
				'read'                   => true,
				'access_woocommerce_pos' => true,
				'list_users'             => true,
				'edit_users'             => true,
				'promote_users'          => true,
			)
		);
		$lead = $this->factory->user->create( array( 'role' => 'till_lead' ) );

		// Act.
		$edit_shop_manager = user_can( $lead, 'edit_user', $this->shop_manager );
		$edit_customer     = user_can( $lead, 'edit_user', $this->customer );
		$promote_self      = user_can( $lead, 'promote_user', $lead );

		// Assert.
		$this->assertFalse( $edit_shop_manager, 'shop manager' );
		$this->assertTrue( $edit_customer, 'customer' );
		$this->assertFalse( $promote_self, 'promote self' );
	}
}
