<?php
/**
 * Permissions stamp response and change-detection tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Rest_Cors;
use WCPOS\WooCommercePOS\Services\Permissions_Stamp;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_User;

/** Permissions stamp contracts. */
class Test_Permissions_Stamp extends WCPOS_REST_Unit_Test_Case {
	/** @var string Original store currency. */
	private $currency;

	/** Start with the default cashier grants. */
	public function setUp(): void {
		parent::setUp();
		$this->currency = get_option( 'woocommerce_currency' );
		( new Activator() )->single_activate( false );
	}

	/** Restore the store setting and in-memory role state. */
	public function tearDown(): void {
		update_option( 'woocommerce_currency', $this->currency );
		remove_role( 'wcpos_test_deny_role' );
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_user_roles'] );
		global $wp_roles;
		$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore role state after capability tests.
		parent::tearDown();
	}

	/** Signed-in successful responses expose the current user's stamp. */
	public function test_wcpos_v2_response_for_signed_in_user_carries_the_stamp(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/settings' ) );
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $headers[ Permissions_Stamp::HEADER ] );
		$this->assertSame( Permissions_Stamp::for_user( wp_get_current_user() ), $headers[ Permissions_Stamp::HEADER ] );
		$this->assertContains( 'X-WCPOS-Permissions', Rest_Cors::EXPOSE_HEADERS );
	}

	/** Opted-in response bodies mirror the same stamp. */
	public function test_enveloped_response_mirrors_the_stamp(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/settings' );
		$request->set_param( '_wcpos_envelope', '1' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $response->get_headers()[ Permissions_Stamp::HEADER ], $response->get_data()['_wcpos']['permissions'] );
	}

	/** Identical inputs produce the same stamp. */
	public function test_stamp_is_stable_when_nothing_changes(): void {
		$user = new WP_User( self::factory()->user->create( array( 'role' => 'cashier' ) ) );

		$this->assertSame( Permissions_Stamp::for_user( $user ), Permissions_Stamp::for_user( $user ) );
	}

	/** Removing a role grant changes the stamp after reloading the user. */
	public function test_stamp_changes_when_a_role_capability_is_removed(): void {
		$user   = new WP_User( self::factory()->user->create( array( 'role' => 'cashier' ) ) );
		$before = Permissions_Stamp::for_user( $user );

		get_role( 'cashier' )->remove_cap( 'edit_products' );
		$user = new WP_User( $user->ID );

		$this->assertNotSame( $before, Permissions_Stamp::for_user( $user ) );
	}

	/** The effective permissions include a second role's explicit denial. */
	public function test_stamp_changes_when_a_second_role_denies_a_capability(): void {
		$user   = new WP_User( self::factory()->user->create( array( 'role' => 'cashier' ) ) );
		$before = Permissions_Stamp::for_user( $user );

		add_role( 'wcpos_test_deny_role', 'Test deny role', array( 'edit_products' => false ) );
		$user->add_role( 'wcpos_test_deny_role' );

		$this->assertNotSame( $before, Permissions_Stamp::for_user( $user ) );
	}

	/** A fresh accessible store reflects changed WooCommerce settings. */
	public function test_stamp_changes_when_a_store_setting_changes(): void {
		$user   = new WP_User( self::factory()->user->create( array( 'role' => 'cashier' ) ) );
		$before = Permissions_Stamp::for_user( $user );

		update_option( 'woocommerce_currency', 'USD' === $this->currency ? 'EUR' : 'USD' );

		$this->assertNotSame( $before, Permissions_Stamp::for_user( $user ) );
	}

	/** Error responses carry no permissions stamp. */
	public function test_error_response_carries_no_stamp(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/no-such-route' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertArrayNotHasKey( Permissions_Stamp::HEADER, $response->get_headers() );
	}

	/** Signed-out requests carry no permissions stamp. */
	public function test_signed_out_response_carries_no_stamp(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/ping' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( Permissions_Stamp::HEADER, $response->get_headers() );
	}
}
