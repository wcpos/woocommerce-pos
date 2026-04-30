<?php
/**
 * Test_Coupons_Controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\Coupons_Controller;

/**
 * Coupons controller tests.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Coupons_Controller extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Set up test fixtures.
	 */
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Coupons_Controller();

		// WC only registers shop_coupon post type when coupons are enabled.
		// Required for CRUD permission checks in update_item_permissions_check.
		if ( ! post_type_exists( 'shop_coupon' ) ) {
			update_option( 'woocommerce_enable_coupons', 'yes' );
			register_post_type( 'shop_coupon', array(
				'public'          => false,
				'capability_type' => 'shop_coupon',
				'map_meta_cap'    => true,
			) );
		}
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test namespace property.
	 */
	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );

		$this->assertEquals( 'wcpos/v1', $namespace );
	}

	/**
	 * Test rest base property.
	 */
	public function test_rest_base(): void {
		$rest_base = $this->get_reflected_property_value( 'rest_base' );

		$this->assertEquals( 'coupons', $rest_base );
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v1/coupons', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/coupons/(?P<id>[\d]+)', $routes );
	}

	/**
	 * Get all expected fields.
	 *
	 * @return array
	 */
	public function get_expected_response_fields() {
		return array(
			'id',
			'code',
			'amount',
			'status',
			'date_created',
			'date_created_gmt',
			'date_modified',
			'date_modified_gmt',
			'discount_type',
			'description',
			'date_expires',
			'date_expires_gmt',
			'usage_count',
			'individual_use',
			'product_ids',
			'excluded_product_ids',
			'usage_limit',
			'usage_limit_per_user',
			'limit_usage_to_x_items',
			'free_shipping',
			'product_categories',
			'excluded_product_categories',
			'exclude_sale_items',
			'minimum_amount',
			'maximum_amount',
			'email_restrictions',
			'used_by',
			'meta_data',
		);
	}

	/**
	 * Test coupon API returns all expected fields.
	 */
	public function test_coupon_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$coupon  = CouponHelper::create_coupon( 'testcoupon' );
		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$response_fields = array_keys( $response->get_data() );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	/**
	 * Test coupon API returns multiple coupons.
	 */
	public function test_coupon_api_get_coupons(): void {
		$coupon1 = CouponHelper::create_coupon( 'coupon1' );
		$coupon2 = CouponHelper::create_coupon( 'coupon2' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons' );
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 2, \count( $data ) );
	}

	/**
	 * Test coupon API returns a single coupon.
	 */
	public function test_coupon_api_get_single_coupon(): void {
		$coupon  = CouponHelper::create_coupon( 'singlecoupon' );
		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $coupon->get_id(), $data['id'] );
		$this->assertEquals( 'singlecoupon', $data['code'] );
		$this->assertEquals( 'fixed_cart', $data['discount_type'] );
		$this->assertEquals( '1.00', $data['amount'] );
	}

	/**
	 * Each coupon needs a UUID.
	 */
	public function test_coupon_response_contains_uuid_meta_data(): void {
		$coupon  = CouponHelper::create_coupon( 'uuidcoupon' );
		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$uuid_value = '';
		$count      = 0;

		// Look for the _woocommerce_pos_uuid key in meta_data.
		foreach ( $data['meta_data'] as $meta ) {
			if ( '_woocommerce_pos_uuid' === $meta['key'] ) {
				$count++;
				$uuid_value = $meta['value'];
			}
		}

		$this->assertEquals( 1, $count, 'There should only be one _woocommerce_pos_uuid.' );
		$this->assertTrue( Uuid::isValid( $uuid_value ), 'The UUID value is not valid.' );
	}

	/**
	 * Test coupon API returns all IDs.
	 */
	public function test_coupon_api_get_all_ids(): void {
		$coupon1 = CouponHelper::create_coupon( 'couponid1' );
		$coupon2 = CouponHelper::create_coupon( 'couponid2' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $coupon1->get_id(), $coupon2->get_id() ), $ids );
	}

	/**
	 * The coupon endpoint should be accessible by cashiers.
	 */
	public function test_coupon_api_get_for_cashier(): void {
		$cashier_user_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $cashier_user_id );

		$coupon  = CouponHelper::create_coupon( 'cashiercoupon' );
		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $coupon->get_id(), $data['id'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Test orderby=code ascending.
	 */
	public function test_coupon_orderby_code_asc(): void {
		$coupon_b = CouponHelper::create_coupon( 'bravo' );
		$coupon_a = CouponHelper::create_coupon( 'alpha' );
		$coupon_d = CouponHelper::create_coupon( 'delta' );
		$coupon_c = CouponHelper::create_coupon( 'charlie' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons' );
		$request->set_query_params(
			array(
				'orderby' => 'code',
				'order'   => 'asc',
			)
		);
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data  = $response->get_data();
		$codes = wp_list_pluck( $data, 'code' );

		$this->assertEquals( array( 'alpha', 'bravo', 'charlie', 'delta' ), $codes );
	}

	/**
	 * Test orderby=code descending.
	 */
	public function test_coupon_orderby_code_desc(): void {
		$coupon_b = CouponHelper::create_coupon( 'bravo' );
		$coupon_a = CouponHelper::create_coupon( 'alpha' );
		$coupon_d = CouponHelper::create_coupon( 'delta' );
		$coupon_c = CouponHelper::create_coupon( 'charlie' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons' );
		$request->set_query_params(
			array(
				'orderby' => 'code',
				'order'   => 'desc',
			)
		);
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data  = $response->get_data();
		$codes = wp_list_pluck( $data, 'code' );

		$this->assertEquals( array( 'delta', 'charlie', 'bravo', 'alpha' ), $codes );
	}

	/**
	 * Test coupon API supports include filtering.
	 */
	public function test_coupon_api_get_with_includes(): void {
		$coupon1 = CouponHelper::create_coupon( 'includeone' );
		$coupon2 = CouponHelper::create_coupon( 'includetwo' );
		$coupon3 = CouponHelper::create_coupon( 'includethree' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons' );
		$request->set_query_params(
			array(
				'include' => array( $coupon1->get_id(), $coupon3->get_id() ),
			)
		);
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertEqualsCanonicalizing( array( $coupon1->get_id(), $coupon3->get_id() ), $ids );
		$this->assertNotContains( $coupon2->get_id(), $ids );
	}

	/**
	 * Test coupon API supports exclude filtering.
	 */
	public function test_coupon_api_get_with_excludes(): void {
		$coupon1 = CouponHelper::create_coupon( 'excludeone' );
		$coupon2 = CouponHelper::create_coupon( 'excludetwo' );
		$coupon3 = CouponHelper::create_coupon( 'excludethree' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons' );
		$request->set_query_params(
			array(
				'exclude' => array( $coupon2->get_id() ),
			)
		);
		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertContains( $coupon1->get_id(), $ids );
		$this->assertNotContains( $coupon2->get_id(), $ids );
		$this->assertContains( $coupon3->get_id(), $ids );
	}

	/**
	 * PATCH coupon amount should update date_modified_gmt.
	 *
	 * @see https://github.com/wcpos/woocommerce-pos-pro/issues/86
	 */
	public function test_coupon_patch_updates_date_modified_gmt(): void {
		$coupon = CouponHelper::create_coupon( 'patchtest' );

		// Record the original date_modified_gmt.
		$original_modified_gmt = get_post_field( 'post_modified_gmt', $coupon->get_id() );

		// Ensure at least 1 second passes so the timestamp must differ.
		sleep( 1 );

		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
		$request->set_method( 'PATCH' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'amount' => '99.00' ) ) );

		$this->trigger_dispatch( $request );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $data ) );
		$this->assertEquals( '99.00', $data['amount'] );

		// Verify the DB-level post_modified_gmt was updated.
		clean_post_cache( $coupon->get_id() );
		$db_modified_gmt = get_post_field( 'post_modified_gmt', $coupon->get_id() );
		$this->assertGreaterThan(
			strtotime( $original_modified_gmt ),
			strtotime( $db_modified_gmt ),
			"post_modified_gmt in DB should be updated. Original: {$original_modified_gmt}, Now: {$db_modified_gmt}"
		);

		// Response date_modified_gmt should also reflect the updated timestamp.
		$this->assertGreaterThan(
			strtotime( $original_modified_gmt ),
			strtotime( $data['date_modified_gmt'] ),
			"date_modified_gmt in response should be updated. Original: {$original_modified_gmt}, Response: {$data['date_modified_gmt']}"
		);
	}

	/**
	 * Helper to manually call wcpos_dispatch_request to set up filters.
	 *
	 * WC 10.5+ wraps REST callbacks in closures, breaking identity checks.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	private function trigger_dispatch( \WP_REST_Request $request ): void {
		$this->endpoint->wcpos_dispatch_request( null, $request, '', array() );
	}
}
