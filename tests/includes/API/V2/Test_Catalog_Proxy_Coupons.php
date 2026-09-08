<?php
/**
 * Tests for the v2 catalog proxy coupon read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Cashier-facing coupon reads through real v2 REST dispatch.
 */
class Test_Catalog_Proxy_Coupons extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes before REST initialization and authenticate a cashier.
	 */
	public function setUp(): void {
		// The identity stamper alone is not the lane a client reads through — see
		// {@see WCPOS_REST_Unit_Test_Case::install_sync_read_lane()}.
		$this->install_sync_read_lane();
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/** Remove sync state written outside the test transaction. */
	public function tearDown(): void {
		parent::tearDown();
		$this->uninstall_sync_read_lane();
	}

	/**
	 * Dispatch a coupon collection request.
	 *
	 * @param array $params Query parameters.
	 */
	private function read( array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/coupons' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * Coupon rows carry stable UUIDs in meta_data.
	 */
	public function test_list_contains_fixtures_with_stable_uuid_meta_data(): void {
		$first_coupon  = CouponHelper::create_coupon( 'v2-uuid-coupon-one' );
		$second_coupon = CouponHelper::create_coupon( 'v2-uuid-coupon-two' );
		$ids           = array( $first_coupon->get_id(), $second_coupon->get_id() );

		$first  = array_column( $this->read( array( 'include' => $ids ) ), null, 'id' );
		$second = array_column( $this->read( array( 'include' => $ids ) ), null, 'id' );
		$this->assertEqualsCanonicalizing( $ids, array_map( 'intval', array_keys( $first ) ) );
		foreach ( $ids as $id ) {
			$first_meta  = array_column( $first[ $id ]['meta_data'], 'value', 'key' );
			$second_meta = array_column( $second[ $id ]['meta_data'], 'value', 'key' );
			$this->assertArrayHasKey( '_woocommerce_pos_uuid', $first_meta );
			$this->assertArrayHasKey( '_woocommerce_pos_uuid', $second_meta );
			$this->assertTrue( Uuid::isValid( $first_meta['_woocommerce_pos_uuid'] ) );
			$this->assertSame( $first_meta['_woocommerce_pos_uuid'], $second_meta['_woocommerce_pos_uuid'] );
		}
	}

	/**
	 * Coupon include= and exclude= parameters retain wc/v3 semantics.
	 */
	public function test_include_and_exclude_filter_coupons(): void {
		$included    = CouponHelper::create_coupon( 'v2-included-coupon' );
		$other       = CouponHelper::create_coupon( 'v2-other-coupon' );
		$included_id = $included->get_id();
		$other_id    = $other->get_id();

		$include_ids = array_map( 'intval', wp_list_pluck( $this->read( array( 'include' => array( $included_id ) ) ), 'id' ) );
		$exclude_ids = array_map( 'intval', wp_list_pluck( $this->read( array( 'exclude' => array( $included_id ) ) ), 'id' ) );
		$this->assertSame( array( $included_id ), $include_ids );
		$this->assertNotContains( $included_id, $exclude_ids );
		$this->assertContains( $other_id, $exclude_ids );
	}

	/**
	 * Coupon search= matches the coupon code.
	 */
	public function test_search_matches_coupon_code(): void {
		$match = CouponHelper::create_coupon( 'v2-needle-coupon-1372' );
		$other = CouponHelper::create_coupon( 'v2-haystack-coupon-1372' );
		$ids   = array_map( 'intval', wp_list_pluck( $this->read( array( 'search' => 'needle-coupon-1372' ) ), 'id' ) );

		$this->assertContains( $match->get_id(), $ids );
		$this->assertNotContains( $other->get_id(), $ids );
	}

	/**
	 * Coupon rows expose the complete v2 field set.
	 */
	public function test_coupon_row_has_full_v2_field_set(): void {
		$coupon = CouponHelper::create_coupon( 'v2-coupon-field-set' );

		$rows = $this->read( array( 'include' => array( $coupon->get_id() ) ) );

		/*
		 * `_rxdb_revision` is transport metadata, not a coupon field: `Sync\Revision`
		 * stamps it at priority 9 onto every record whose proxy slug the registry
		 * resolves, coupons included (wired by `Sync\Augmentation_Pipeline::install()`,
		 * as `Init::__construct()` does in production). It was missing from this list
		 * while the test ran without the production read lane, which made the pin
		 * assert a row shape no deployed client receives (#1717).
		 */
		$this->assertEqualsCanonicalizing(
			array(
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
				'_rxdb_revision',
				'_links',
			),
			array_keys( $rows[0] )
		);
		/*
		 * Named rather than left to the set difference above: `_rxdb_digest` is
		 * absent from this lane STRUCTURALLY. The coupons registry row declares no
		 * digest group, so `Integrity_Digest::stamp_digests()` returns the payload
		 * untouched however full the digest index is — asserted from the registry
		 * so a row gaining a group can never leave this claim quietly stale.
		 */
		$this->assertNull( Collections::row( 'coupons' )['digest'] );
		$this->assertArrayNotHasKey( '_rxdb_digest', $rows[0] );
	}
}
