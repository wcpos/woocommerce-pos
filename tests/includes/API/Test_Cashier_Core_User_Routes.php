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
		$cashier_manager = $this->factory->user->create( array( 'role' => 'cashier' ) );
		get_user_by( 'id', $cashier_manager )->add_role( 'shop_manager' );
		$actors  = array(
			'administrator'        => $this->admin,
			'shop_manager'         => $this->shop_manager,
			'user_manager_no_pos'  => $this->factory->user->create( array( 'role' => 'user_manager_no_pos' ) ),
			'cashier+shop_manager' => $cashier_manager,
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

	/**
	 * Core REST: a Cashier with promote_users (WooCommerce below 9.9) must not make a
	 * customer an administrator. can_modify() clears a plain customer, so the role
	 * fence is what stands between the till and site-admin control. While WooCommerce
	 * is active its wc_modify_editable_roles() also strips administrator for non-admins,
	 * so this case is double-fenced; the shop_manager case below is ours alone.
	 */
	public function test_wp_v2_users_cannot_promote_customer_to_administrator(): void {
		// Arrange.
		get_user_by( 'id', $this->cashier )->add_cap( 'promote_users' );
		wp_set_current_user( 0 ); // Re-reading the current user picks up the new capability.
		wp_set_current_user( $this->cashier );
		$this->assertTrue( current_user_can( 'promote_user', $this->customer ), 'the capability the finding relies on' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $this->customer );
		$request->set_body_params( array( 'roles' => array( 'administrator' ) ) );

		// Act.
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_user_invalid_role', $response->get_data()['code'] );
		$this->assertSame( array( 'customer' ), get_userdata( $this->customer )->roles );
		$this->assertFalse( user_can( $this->customer, 'manage_options' ) );
	}

	/** The same through a POS bearer token, to shop manager, and on a fresh customer the Cashier created. */
	public function test_wp_v2_users_bearer_token_cashier_cannot_promote_own_customer_to_shop_manager(): void {
		// Arrange.
		get_user_by( 'id', $this->cashier )->add_cap( 'promote_users' );
		wp_set_current_user( 0 );
		$tokens = Auth::instance()->generate_token_pair( get_user_by( 'id', $this->cashier ) );
		$this->assertIsArray( $tokens );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_get_current_user();
		$this->assertSame( $this->cashier, get_current_user_id(), 'The bearer token must authenticate the Cashier.' );
		$target  = $this->factory->user->create( array( 'role' => 'customer' ) );
		$request = new WP_REST_Request( 'PUT', '/wp/v2/users/' . $target );
		$request->set_body_params( array( 'roles' => array( 'shop_manager' ) ) );

		// Act.
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_user_invalid_role', $response->get_data()['code'], wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( 'customer' ), get_userdata( $target )->roles );
	}

	/** A Cashier's get_editable_roles() is customer only; setting customer explicitly still works. */
	public function test_editable_roles_for_cashier_is_customer_only(): void {
		// Arrange.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		get_user_by( 'id', $this->cashier )->add_cap( 'promote_users' );
		wp_set_current_user( 0 ); // Re-reading the current user picks up the new capability.
		wp_set_current_user( $this->cashier );
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$request    = new WP_REST_Request( 'POST', '/wp/v2/users/' . $subscriber );
		$request->set_body_params( array( 'roles' => array( 'customer' ) ) );

		// Act.
		$editable = array_keys( get_editable_roles() );
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertSame( array( 'customer' ), $editable );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'customer' ), get_userdata( $subscriber )->roles );
	}

	/** Administrators, shop managers, a cashier+shop manager and roles without till access keep their editable roles. */
	public function test_editable_roles_actors_outside_the_rule_are_unchanged(): void {
		// Arrange.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		add_role(
			'user_manager_no_pos',
			'User manager without POS',
			array(
				'read'          => true,
				'edit_users'    => true,
				'promote_users' => true,
			)
		);
		$cashier_manager = $this->factory->user->create( array( 'role' => 'cashier' ) );
		get_user_by( 'id', $cashier_manager )->add_role( 'shop_manager' );
		$actors = array(
			'administrator'        => $this->admin,
			'shop_manager'         => $this->shop_manager,
			'user_manager_no_pos'  => $this->factory->user->create( array( 'role' => 'user_manager_no_pos' ) ),
			'cashier+shop_manager' => $cashier_manager,
		);
		$filter = array( Permission_Rules::class, 'filter_editable_roles' );

		foreach ( $actors as $name => $actor ) {
			wp_set_current_user( $actor );

			// Act.
			$with = array_keys( get_editable_roles() );
			remove_filter( 'editable_roles', $filter, PHP_INT_MAX );
			try {
				$without = array_keys( get_editable_roles() );
			} finally {
				add_filter( 'editable_roles', $filter, PHP_INT_MAX );
			}

			// Assert (WooCommerce's own shop-manager fence is what shapes these; ours adds nothing).
			$this->assertSame( $without, $with, $name );
		}
	}

	/** The role fence, like the staff rule, is registered by the Activator alone. */
	public function test_editable_roles_filter_is_registered_by_the_activator_without_init(): void {
		// Arrange.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$filter = array( Permission_Rules::class, 'filter_editable_roles' );
		remove_filter( 'editable_roles', $filter, PHP_INT_MAX );
		try {
			$this->assertFalse( has_filter( 'editable_roles', $filter ), 'the fence is off' );
			// WooCommerce already strips administrator for anyone without manage_options; editor is ours to strip.
			$this->assertArrayHasKey( 'editor', get_editable_roles(), 'nothing else supplies the fence' );

			// Act.
			new \WCPOS\WooCommercePOS\Activator();
			$registered = has_filter( 'editable_roles', $filter );
			$editable   = array_keys( get_editable_roles() );

			// Assert.
			$this->assertSame( PHP_INT_MAX, $registered );
			$this->assertSame( array( 'customer' ), $editable, 'the fence holds' );
		} finally {
			add_filter( 'editable_roles', $filter, PHP_INT_MAX );
		}
	}

	/** A role-editor plugin adding roles back at a later priority does not widen the fence. */
	public function test_editable_roles_fence_runs_after_other_filters(): void {
		// Arrange.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_set_current_user( $this->cashier );
		$widen = static function ( $roles ) {
			$roles['editor'] = array(
				'name' => 'Editor',
				'capabilities' => array(),
			);
			return $roles;
		};
		add_filter( 'editable_roles', $widen, 99 );
		try {
			// Act.
			$editable = array_keys( get_editable_roles() );
		} finally {
			remove_filter( 'editable_roles', $widen, 99 );
		}

		// Assert.
		$this->assertSame( array( 'customer' ), $editable );
	}

	/**
	 * Multisite "add existing user" stores the requested role unchecked and fires invite_user
	 * after; a till user's invite naming a staff role is deleted and refused.
	 */
	public function test_invite_user_outside_the_fence_is_deleted_and_refused(): void {
		// Arrange.
		get_user_by( 'id', $this->cashier )->add_cap( 'promote_users' );
		wp_set_current_user( 0 );
		wp_set_current_user( $this->cashier );
		add_option(
			'new_user_fencetest',
			array(
				'user_id' => $this->customer,
				'email' => 'c@example.test',
				'role' => 'administrator',
			)
		);
		$died = false;

		// Act.
		try {
			do_action( 'invite_user', $this->customer, null, 'fencetest' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired as wp-admin fires it.
		} catch ( \WPDieException $e ) {
			$died = true;
		}

		// Assert.
		$this->assertTrue( $died, 'wp_die 403' );
		$this->assertFalse( get_option( 'new_user_fencetest' ), 'the invite is gone' );
	}

	/** A till user's invite to the customer role, and any invite by an administrator, go through. */
	public function test_invite_user_inside_the_fence_or_by_an_admin_is_kept(): void {
		// Arrange.
		add_option(
			'new_user_fenceok',
			array(
				'user_id' => $this->customer,
				'email' => 'c@example.test',
				'role' => 'customer',
			)
		);
		add_option(
			'new_user_adminok',
			array(
				'user_id' => $this->customer,
				'email' => 'c@example.test',
				'role' => 'administrator',
			)
		);

		// Act.
		wp_set_current_user( $this->cashier );
		do_action( 'invite_user', $this->customer, array( 'name' => 'Customer' ), 'fenceok' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired as wp-admin fires it.
		wp_set_current_user( $this->admin );
		do_action( 'invite_user', $this->customer, array( 'name' => 'Administrator' ), 'adminok' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired as wp-admin fires it.

		// Assert.
		$this->assertSame( 'customer', get_option( 'new_user_fenceok' )['role'] );
		$this->assertSame( 'administrator', get_option( 'new_user_adminok' )['role'] );
	}

	/**
	 * With WooCommerce deactivated its wc_modify_editable_roles() is gone, but the roles and
	 * their capabilities persist: a cashier who also holds shop manager must still be held to
	 * WooCommerce's shop-manager list (customer) rather than every role.
	 */
	public function test_editable_roles_cashier_plus_shop_manager_is_fenced_without_woocommerce(): void {
		// Arrange.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$cashier_manager = $this->factory->user->create( array( 'role' => 'cashier' ) );
		get_user_by( 'id', $cashier_manager )->add_role( 'shop_manager' );
		get_user_by( 'id', $cashier_manager )->add_cap( 'promote_users' );
		wp_set_current_user( $cashier_manager );
		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
		$wc_fence = has_filter( 'editable_roles', 'wc_modify_editable_roles' );
		$this->assertNotFalse( $wc_fence, 'WooCommerce is active in the test site' );
		remove_filter( 'editable_roles', 'wc_modify_editable_roles', $wc_fence );
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $this->customer );
		$request->set_body_params( array( 'roles' => array( 'administrator' ) ) );
		try {
			// Act.
			$editable = array_keys( get_editable_roles() );
			$response = rest_get_server()->dispatch( $request );
		} finally {
			add_filter( 'editable_roles', 'wc_modify_editable_roles', $wc_fence );
		}

		// Assert.
		$this->assertSame( array( 'customer' ), $editable );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array( 'customer' ), get_userdata( $this->customer )->roles );
	}

	/** A merchant who widened WooCommerce's shop-manager list sees the same list from us. */
	public function test_editable_roles_shop_manager_follows_woocommerce_list(): void {
		// Arrange.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_set_current_user( $this->shop_manager );
		$widen = static function () {
			return array( 'customer', 'subscriber' );
		};
		add_filter( 'woocommerce_shop_manager_editable_roles', $widen );
		try {
			// Act.
			$editable = array_keys( get_editable_roles() );
		} finally {
			remove_filter( 'woocommerce_shop_manager_editable_roles', $widen );
		}

		// Assert.
		sort( $editable );
		$this->assertSame( array( 'customer', 'subscriber' ), $editable );
	}

	/** Constructing the Activator alone registers the staff rule, once, with no Init or requirement check. */
	public function test_user_meta_caps_filter_is_registered_by_the_activator_without_init(): void {
		// Arrange.
		$filter = array( Permission_Rules::class, 'map_user_meta_caps' );
		remove_filter( 'map_meta_cap', $filter, 10 );
		try {
			$this->assertFalse( has_filter( 'map_meta_cap', $filter ), 'the rule is off' );
			$this->assertTrue( current_user_can( 'edit_user', $this->shop_manager ), 'nothing else supplies the rule' );

			// Act.
			new \WCPOS\WooCommercePOS\Activator();
			$registered = has_filter( 'map_meta_cap', $filter );
			$can_edit   = current_user_can( 'edit_user', $this->shop_manager );
			new \WCPOS\WooCommercePOS\Activator();
			$count = 0;
			foreach ( $GLOBALS['wp_filter']['map_meta_cap']->callbacks[10] as $callback ) {
				if ( $filter === $callback['function'] ) {
					++$count;
				}
			}

			// Assert.
			$this->assertSame( 10, $registered );
			$this->assertFalse( $can_edit, 'the rule holds' );
			$this->assertSame( 1, $count, 'registered exactly once' );
		} finally {
			add_filter( 'map_meta_cap', $filter, 10, 4 );
		}
	}
}
