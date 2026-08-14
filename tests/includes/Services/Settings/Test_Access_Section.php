<?php
/**
 * Tests for the Access Settings Section.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services\Settings
 */

namespace WCPOS\WooCommercePOS\Tests\Services\Settings;

use WCPOS\WooCommercePOS\Services\Settings\Access_Section;
use WP_UnitTestCase;

/**
 * Test_Access_Section class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Access_Section extends WP_UnitTestCase {
	/**
	 * Section under test.
	 *
	 * @var Access_Section
	 */
	private $section;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->section = new Access_Section();
	}

	/**
	 * Tear down: reset current user and flush in-memory role state so capability-mutation
	 * tests cannot bleed into each other.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_user_roles'] );
		global $wp_roles;
		$wp_roles = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring role state after capability-mutation tests.
		parent::tearDown();
	}

	/**
	 * Verify read() returns role capability groups structured by wcpos/wc/wp keys.
	 */
	public function test_read_returns_role_capability_groups(): void {
		$settings = $this->section->read();

		$this->assertIsArray( $settings );
		$this->assertArrayHasKey( 'administrator', $settings );

		$admin = $settings['administrator'];
		$this->assertArrayHasKey( 'name', $admin );
		$this->assertArrayHasKey( 'capabilities', $admin );
		$this->assertArrayHasKey( 'wcpos', $admin['capabilities'] );
		$this->assertArrayHasKey( 'wc', $admin['capabilities'] );
		$this->assertArrayHasKey( 'wp', $admin['capabilities'] );
		$this->assertArrayHasKey( 'access_woocommerce_pos', $admin['capabilities']['wcpos'] );
		$this->assertArrayHasKey( 'read', $admin['capabilities']['wp'] );
		$this->assertTrue( $admin['capabilities']['wcpos']['access_woocommerce_pos'] );
		$this->assertTrue( $admin['capabilities']['wcpos']['manage_woocommerce_pos'] );
	}

	/**
	 * Catalog mutation capabilities can be granted through Access settings.
	 */
	public function test_write_grants_catalog_mutation_capabilities(): void {
		$capabilities = array(
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
			'publish_shop_coupons',
			'edit_shop_coupons',
			'edit_others_shop_coupons',
			'edit_private_shop_coupons',
			'edit_published_shop_coupons',
			'delete_shop_coupons',
			'delete_others_shop_coupons',
			'delete_private_shop_coupons',
			'delete_published_shop_coupons',
		);
		$role         = get_role( 'subscriber' );

		foreach ( $capabilities as $capability ) {
			$role->remove_cap( $capability );
		}

		$result = $this->section->write(
			array(
				'subscriber' => array(
					'capabilities' => array(
						'wc' => array_fill_keys( $capabilities, true ),
					),
				),
			)
		);

		foreach ( $capabilities as $capability ) {
			$this->assertTrue( $role->has_cap( $capability ), $capability );
			$this->assertTrue( $result['subscriber']['capabilities']['wc'][ $capability ], $capability );
		}
	}

	/**
	 * The `wc` group must expose every PRIMITIVE capability the v2 catalog write
	 * gate resolves to, or the documented settings-screen opt-in cannot restore
	 * catalog and coupon writes at all (#1514).
	 */
	public function test_wc_group_exposes_every_primitive_capability_catalog_writes_require(): void {
		// wc_rest_check_post_permissions() checks edit_post / delete_post / publish_posts /
		// read_private_posts, which map_meta_cap() rewrites into these primitives.
		$required = array(
			'read_private_products',
			'edit_products',
			'edit_others_products',
			'edit_private_products',
			'edit_published_products',
			'publish_products',
			'delete_products',
			'delete_others_products',
			'delete_private_products',
			'delete_published_products',
			// product_variation registers map_meta_cap = false, so the singular
			// product meta caps are checked LITERALLY for variation writes.
			'edit_product',
			'delete_product',
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
		);

		$wc = $this->section->read()['administrator']['capabilities']['wc'];

		foreach ( $required as $capability ) {
			$this->assertArrayHasKey( $capability, $wc, $capability );
		}
	}

	/**
	 * The singular coupon meta caps are deliberately NOT exposed: shop_coupon
	 * registers with map_meta_cap = true, so WordPress rewrites edit_shop_coupon /
	 * delete_shop_coupon into the primitives above and a role grant of the singular
	 * name is never read — it would be a dead toggle on the settings screen.
	 * product_variation is the opposite case, which is why edit_product and
	 * delete_product ARE exposed.
	 */
	public function test_wc_group_omits_coupon_meta_capabilities_that_map_meta_cap_rewrites(): void {
		$wc = $this->section->read()['administrator']['capabilities']['wc'];

		$this->assertArrayNotHasKey( 'edit_shop_coupon', $wc );
		$this->assertArrayNotHasKey( 'delete_shop_coupon', $wc );

		$coupon = get_post_type_object( 'shop_coupon' );
		if ( $coupon ) {
			$this->assertTrue( $coupon->map_meta_cap, 'shop_coupon maps its meta caps' );
		}

		$variation = get_post_type_object( 'product_variation' );
		if ( $variation ) {
			$this->assertFalse( $variation->map_meta_cap, 'product_variation does NOT map its meta caps' );
			$this->assertArrayHasKey( $variation->cap->edit_post, $wc );
			$this->assertArrayHasKey( $variation->cap->delete_post, $wc );
		}
	}

	/**
	 * Verify write() grants/revokes a capability on the cashier role and returns the fresh view.
	 */
	public function test_write_grants_and_revokes_capability_on_cashier_role(): void {
		// Grant access_woocommerce_pos to the cashier role.
		$result = $this->section->write(
			array(
				'cashier' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => true,
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'cashier', $result );
		$this->assertTrue( $result['cashier']['capabilities']['wcpos']['access_woocommerce_pos'] );

		// Now revoke it.
		$result = $this->section->write(
			array(
				'cashier' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => false,
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['cashier']['capabilities']['wcpos']['access_woocommerce_pos'] );
	}

	/**
	 * Granting a capability listed in the access settings view succeeds.
	 */
	public function test_write_grants_in_group_capability(): void {
		$role = get_role( 'subscriber' );
		$role->remove_cap( 'access_woocommerce_pos' );

		$result = $this->section->write(
			array(
				'subscriber' => array(
					'capabilities' => array(
						'wcpos' => array(
							'access_woocommerce_pos' => true,
						),
					),
				),
			)
		);

		$this->assertTrue( get_role( 'subscriber' )->has_cap( 'access_woocommerce_pos' ) );
		$this->assertTrue( $result['subscriber']['capabilities']['wcpos']['access_woocommerce_pos'] );
	}

	/**
	 * Granting a capability added via the woocommerce_pos_access_settings filter succeeds.
	 */
	public function test_write_grants_filter_added_capability(): void {
		$capability = 'wcpos_extension_test_cap';
		$role       = get_role( 'subscriber' );
		$role->remove_cap( $capability );

		$filter = static function ( array $settings ) use ( $capability ): array {
			foreach ( array_keys( $settings ) as $slug ) {
				$role = get_role( $slug );

				$settings[ $slug ]['capabilities']['extensions'][ $capability ] = $role
					? $role->has_cap( $capability )
					: false;
			}

			return $settings;
		};

		add_filter( 'woocommerce_pos_access_settings', $filter );

		try {
			$result = $this->section->write(
				array(
					'subscriber' => array(
						'capabilities' => array(
							'extensions' => array(
								$capability => true,
							),
						),
					),
				)
			);

			$this->assertTrue( get_role( 'subscriber' )->has_cap( $capability ) );
			$this->assertTrue( $result['subscriber']['capabilities']['extensions'][ $capability ] );
		} finally {
			remove_filter( 'woocommerce_pos_access_settings', $filter );
		}
	}

	/**
	 * A capability outside the access settings view is ignored.
	 *
	 * @see https://github.com/wcpos/woocommerce-pos/issues/1159
	 */
	public function test_write_ignores_out_of_band_capability(): void {
		$role = get_role( 'subscriber' );
		$role->remove_cap( 'manage_options' );

		$this->section->write(
			array(
				'subscriber' => array(
					'capabilities' => array(
						'wp' => array(
							'manage_options' => true,
						),
					),
				),
			)
		);

		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'manage_options' ) );
	}

	/**
	 * The administrator `read` capability cannot be removed via write().
	 */
	public function test_write_cannot_remove_administrator_read_capability(): void {
		// Attempt to revoke the `read` capability from the administrator role.
		$result = $this->section->write(
			array(
				'administrator' => array(
					'capabilities' => array(
						'wp' => array(
							'read' => false,
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		// The sanity check skips this mutation; administrator must still have read = true.
		$this->assertTrue( $result['administrator']['capabilities']['wp']['read'] );
	}

	/**
	 * Without edit_users + promote_users, write() refuses with a 403 WP_Error
	 * and mutates nothing.
	 */
	public function test_write_requires_capabilities(): void {
		wp_set_current_user( 0 );

		// Confirm the cashier role does not have delete_products before we attempt the write.
		$cashier_role = get_role( 'cashier' );
		$this->assertNotNull( $cashier_role, 'Expected cashier role to be registered in test fixtures.' );
		$this->assertFalse( $cashier_role->has_cap( 'delete_products' ) );

		$section = new Access_Section();
		$result  = $section->write(
			array(
				'cashier' => array(
					'capabilities' => array(
						'wc' => array( 'delete_products' => true ),
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 403, $result->get_error_data()['status'] );
		// Capability must not have been mutated.
		$cashier_role = get_role( 'cashier' );
		$this->assertNotNull( $cashier_role, 'Expected cashier role to be registered in test fixtures.' );
		$this->assertFalse( $cashier_role->has_cap( 'delete_products' ) );
	}
}
