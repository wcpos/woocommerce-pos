<?php
/**
 * Tests for customer role meta protection.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Role_Meta_Guard;
use WP_REST_Request;

/**
 * Test_Role_Meta_Guard class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Role_Meta_Guard extends \WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case {
	/**
	 * Role and level keys are recognized across blog prefixes and normalized spellings.
	 */
	public function test_is_role_meta_key_matches_role_and_level_keys(): void {
		// Arrange.
		$p         = $GLOBALS['wpdb']->base_prefix;
		$role_keys = array(
			$p . 'capabilities',
			$p . 'user_level',
			$p . '2_capabilities',
			$p . '12_user_level',
			strtoupper( $p . 'capabilities' ),
			$p . 'capabilities ',
		);
		$ordinary_keys = array(
			'nickname',
			'_woocommerce_pos_uuid',
			'capabilities',
			$p . 'user-settings',
			$p . 'capabilities_backup',
			$p . 'x_capabilities',
			5,
		);

		// Act / Assert.
		foreach ( $role_keys as $key ) {
			$this->assertTrue( Role_Meta_Guard::is_role_meta_key( $key ) );
		}
		foreach ( $ordinary_keys as $key ) {
			$this->assertFalse( Role_Meta_Guard::is_role_meta_key( $key ) );
		}
	}

	/**
	 * Customer updates preserve role and level rows while saving ordinary meta.
	 */
	public function test_wc_v3_customer_update_stores_no_role_meta(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite already denies edit_users without manage_network_users.' );
		}
		// Arrange.
		global $wpdb;
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
		$id         = $this->factory->user->create( array( 'role' => 'customer' ) );
		$cap_key    = $wpdb->get_blog_prefix() . 'capabilities';
		$level_key  = $wpdb->get_blog_prefix() . 'user_level';
		$cap_rows   = get_user_meta( $id, $cap_key, false );
		$level_rows = get_user_meta( $id, $level_key, false );
		$request    = new WP_REST_Request( 'PUT', '/wc/v3/customers/' . $id );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key'   => $cap_key,
						'value' => array( 'administrator' => true ),
					),
					array(
						'key'   => $level_key,
						'value' => 10,
					),
					array(
						'key'   => $wpdb->base_prefix . '7_capabilities',
						'value' => array( 'administrator' => true ),
					),
					array(
						'key'   => 'wcpos_role_meta_probe',
						'value' => 'kept',
					),
				),
			)
		);

		// Act.
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $cap_rows, get_user_meta( $id, $cap_key, false ) );
		$this->assertSame( $level_rows, get_user_meta( $id, $level_key, false ) );
		$this->assertSame( array(), get_user_meta( $id, $wpdb->base_prefix . '7_capabilities', false ) );
		$this->assertSame( 'kept', get_user_meta( $id, 'wcpos_role_meta_probe', true ) );
		$this->assertFalse( user_can( $id, 'manage_options' ) );
	}

	/**
	 * Customer creation keeps the customer role and ordinary meta.
	 */
	public function test_wc_v3_customer_create_stores_no_role_meta(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite already denies edit_users without manage_network_users.' );
		}
		// Arrange.
		global $wpdb;
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
		$cap_key   = $wpdb->get_blog_prefix() . 'capabilities';
		$level_key = $wpdb->get_blog_prefix() . 'user_level';
		$username  = 'role-meta-' . wp_generate_uuid4();
		$request   = new WP_REST_Request( 'POST', '/wc/v3/customers' );
		$request->set_body_params(
			array(
				'email'     => $username . '@example.test',
				'username'  => $username,
				'password'  => wp_generate_password(),
				'meta_data' => array(
					array(
						'key'   => $cap_key,
						'value' => array( 'administrator' => true ),
					),
					array(
						'key'   => $level_key,
						'value' => 10,
					),
					array(
						'key'   => $wpdb->base_prefix . '7_capabilities',
						'value' => array( 'administrator' => true ),
					),
					array(
						'key'   => 'wcpos_role_meta_probe',
						'value' => 'kept',
					),
				),
			)
		);

		// Act.
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];
		$this->assertSame( array( array( 'customer' => true ) ), get_user_meta( $id, $cap_key, false ) );
		$this->assertSame( array(), get_user_meta( $id, $wpdb->base_prefix . '7_capabilities', false ) );
		$this->assertSame( 'kept', get_user_meta( $id, 'wcpos_role_meta_probe', true ) );
		$this->assertFalse( user_can( $id, 'manage_options' ) );
	}

	/**
	 * Existing ordinary meta cannot become a role meta row.
	 */
	public function test_existing_meta_row_cannot_be_renamed_to_role_meta(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite already denies edit_users without manage_network_users.' );
		}
		// Arrange.
		global $wpdb;
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
		$id       = $this->factory->user->create( array( 'role' => 'customer' ) );
		$mid      = add_user_meta( $id, 'wcpos_role_meta_probe', 'kept' );
		$cap_key  = $wpdb->get_blog_prefix() . 'capabilities';
		$cap_rows = get_user_meta( $id, $cap_key, false );
		$request  = new WP_REST_Request( 'PUT', '/wc/v3/customers/' . $id );
		$request->set_body_params(
			array(
				'meta_data' => array(
					array(
						'id'    => $mid,
						'key'   => $cap_key,
						'value' => array( 'administrator' => true ),
					),
				),
			)
		);

		// Act.
		$response = rest_get_server()->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $cap_rows, get_user_meta( $id, $cap_key, false ) );
		$this->assertFalse( user_can( $id, 'manage_options' ) );
	}
}
