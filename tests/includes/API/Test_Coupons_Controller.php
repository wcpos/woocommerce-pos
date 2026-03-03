<?php

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\API\Coupons_Controller;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Coupons_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setUp();
		$this->endpoint = new Coupons_Controller();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_namespace_property(): void {
		$namespace = $this->get_reflected_property_value( 'namespace' );

		$this->assertEquals( 'wcpos/v1', $namespace );
	}

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

	public function test_coupon_api_get_all_fields(): void {
		$expected_response_fields = $this->get_expected_response_fields();

		$coupon   = CouponHelper::create_coupon( 'testcoupon' );
		$request  = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$response_fields = array_keys( $response->get_data() );

		$this->assertEmpty( array_diff( $expected_response_fields, $response_fields ), 'These fields were expected but not present in WCPOS API response: ' . print_r( array_diff( $expected_response_fields, $response_fields ), true ) );

		$this->assertEmpty( array_diff( $response_fields, $expected_response_fields ), 'These fields were not expected in the WCPOS API response: ' . print_r( array_diff( $response_fields, $expected_response_fields ), true ) );
	}

	public function test_coupon_api_get_coupons(): void {
		$coupon1  = CouponHelper::create_coupon( 'coupon1' );
		$coupon2  = CouponHelper::create_coupon( 'coupon2' );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/coupons' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 2, \count( $data ) );
	}

	public function test_coupon_api_get_single_coupon(): void {
		$coupon   = CouponHelper::create_coupon( 'singlecoupon' );
		$request  = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
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
		$coupon   = CouponHelper::create_coupon( 'uuidcoupon' );
		$request  = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
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

	public function test_coupon_api_get_all_ids(): void {
		$coupon1 = CouponHelper::create_coupon( 'couponid1' );
		$coupon2 = CouponHelper::create_coupon( 'couponid2' );

		$request = $this->wp_rest_get_request( '/wcpos/v1/coupons' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

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

		$coupon   = CouponHelper::create_coupon( 'cashiercoupon' );
		$request  = $this->wp_rest_get_request( '/wcpos/v1/coupons/' . $coupon->get_id() );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $coupon->get_id(), $data['id'] );

		wp_set_current_user( 0 );
	}
}
