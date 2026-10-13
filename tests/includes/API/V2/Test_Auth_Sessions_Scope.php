<?php
/**
 * Session management scope through the current REST lane.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Shop managers may manage non-administrator sessions only.
 */
class Test_Auth_Sessions_Scope extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The current shop manager.
	 *
	 * @var \WP_User
	 */
	private $manager;

	/**
	 * Users whose sessions must be removed after each case.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	/**
	 * Register real REST routes and sign in a shop manager.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->manager  = $this->factory->user->create_and_get(
			array(
				'role' => 'shop_manager',
			)
		);
		$this->user_ids = array( $this->user, $this->manager->ID );
		wp_set_current_user( $this->manager->ID );
	}

	/**
	 * Remove every fixture user's sessions before the parent resets state.
	 */
	public function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			Auth::instance()->revoke_all_refresh_tokens( $user_id );
		}
		parent::tearDown();
	}

	/**
	 * An administrator's sessions cannot be listed by a shop manager.
	 */
	public function test_shop_manager_cannot_list_an_administrators_sessions_on_v2(): void {
		// Arrange.
		Auth::instance()->generate_refresh_token( get_user_by( 'id', $this->user ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/auth/sessions' );
		$request->set_param( 'user_id', $this->user );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * A forbidden revocation leaves the administrator's session intact.
	 */
	public function test_shop_manager_cannot_revoke_an_administrators_sessions_on_v2(): void {
		// Arrange.
		Auth::instance()->generate_refresh_token( get_user_by( 'id', $this->user ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/auth/sessions' );
		$request->set_method( 'DELETE' );
		$request->set_param( 'user_id', $this->user );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertCount( 1, Auth::instance()->get_user_sessions( $this->user ) );
	}

	/**
	 * Cashier edit_users capability does not prevent manager revocation.
	 */
	public function test_shop_manager_can_still_revoke_a_cashiers_sessions_on_v2(): void {
		// Arrange.
		$cashier          = $this->factory->user->create_and_get(
			array(
				'role' => 'cashier',
			)
		);
		$this->user_ids[] = $cashier->ID;
		$this->assertTrue( user_can( $cashier, 'edit_users' ) );
		Auth::instance()->generate_refresh_token( $cashier );
		$this->assertCount( 1, Auth::instance()->get_user_sessions( $cashier->ID ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/auth/sessions' );
		$request->set_method( 'DELETE' );
		$request->set_param( 'user_id', $cashier->ID );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, Auth::instance()->get_user_sessions( $cashier->ID ) );
	}

	/**
	 * Listings omit administrators but retain customers and the manager.
	 */
	public function test_all_users_sessions_omits_administrator_rows_for_a_shop_manager_on_v2(): void {
		// Arrange.
		$customer         = $this->factory->user->create_and_get(
			array(
				'role' => 'customer',
			)
		);
		$this->user_ids[] = $customer->ID;
		Auth::instance()->generate_refresh_token( get_user_by( 'id', $this->user ) );
		Auth::instance()->generate_refresh_token( $customer );
		Auth::instance()->generate_refresh_token( $this->manager );
		$request = $this->wp_rest_get_request( '/wcpos/v2/auth/users/sessions' );

		// Act.
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$ids = array_column( $data['users'], 'user_id' );
		$this->assertNotContains( $this->user, $ids );
		$this->assertContains( $customer->ID, $ids );
		$this->assertContains( $this->manager->ID, $ids );
		$this->assertSame( count( $data['users'] ), $data['total'] );
	}
}
