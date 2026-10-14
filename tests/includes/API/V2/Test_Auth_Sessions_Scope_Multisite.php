<?php
/**
 * Session scope across sites in a WordPress network.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Shop managers cannot manage administrators of any network site.
 *
 * Creating a site fires the deprecated wpmu_new_blog hook (WooCommerce still listens);
 * the WordPress test case fails any undeclared deprecation, so it is declared here.
 *
 * @group multisite
 * @expectedDeprecated wpmu_new_blog
 */
class Test_Auth_Sessions_Scope_Multisite extends WCPOS_REST_Unit_Test_Case {
	/**
	 * The second site's ID.
	 *
	 * @var int
	 */
	private $site_id;
	/**
	 * Fixture users whose refresh tokens must be revoked.
	 *
	 * @var int[]
	 */
	private $user_ids = array();
	/**
	 * The last sessions response for error-code assertions.
	 *
	 * @var \WP_REST_Response
	 */
	private $response;

	/**
	 * Sign in a main-site manager and create a second site.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a network: run with .phpunit.multisite.xml.dist.' );
		}
		$manager = self::factory()->user->create(
			array(
				'role' => 'shop_manager',
			)
		);
		wp_set_current_user( $manager );
		$this->site_id  = self::factory()->blog->create();
		$this->user_ids = array( $this->user, $manager );
	}
	/**
	 * Revoke fixture sessions before WordPress rolls back the sites.
	 */
	public function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			Auth::instance()->revoke_all_refresh_tokens( $user_id );
		}
		parent::tearDown();
	}
	/**
	 * Archived-site administrators remain protected (#2205).
	 */
	public function test_shop_manager_cannot_list_sessions_of_an_administrator_of_an_archived_site_on_v2(): void {
		// Arrange.
		$user = $this->second_site_user( 'administrator' );
		update_blog_status( $this->site_id, 'archived', '1' );
		// Act.
		$status = $this->sessions_status( $user );
		// Assert.
		$this->assertSame( 403, $status );
		$this->assertSame( 'rest_forbidden', $this->response->get_data()['code'] );
	}
	/**
	 * Live-site administrators remain protected.
	 */
	public function test_shop_manager_cannot_list_sessions_of_an_administrator_of_another_live_site_on_v2(): void {
		// Arrange.
		$user = $this->second_site_user( 'administrator' );
		// Act.
		$status = $this->sessions_status( $user );
		// Assert.
		$this->assertSame( 403, $status );
		$this->assertSame( 'rest_forbidden', $this->response->get_data()['code'] );
	}
	/**
	 * Membership of another site alone does not prevent session access.
	 */
	public function test_shop_manager_can_list_sessions_of_a_subscriber_of_another_site_on_v2(): void {
		// Arrange.
		$user = $this->second_site_user( 'subscriber' );
		// Act.
		$status = $this->sessions_status( $user );
		// Assert.
		$this->assertSame( 200, $status );
	}
	/**
	 * Create a main-site subscriber with the requested second-site role.
	 *
	 * @param string $role Second-site role.
	 * @return int
	 */
	private function second_site_user( string $role ): int {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		add_user_to_blog( $this->site_id, $user_id, $role );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Create a session and request its listing as the manager.
	 *
	 * @param int $user_id Target user ID.
	 * @return int
	 */
	private function sessions_status( int $user_id ): int {
		Auth::instance()->generate_refresh_token( get_user_by( 'id', $user_id ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/auth/sessions' );
		$request->set_param( 'user_id', $user_id );
		$this->response = $this->server->dispatch( $request );
		return $this->response->get_status();
	}
}
