<?php
/**
 * Tests for the v2 catalog proxy brand read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Ramsey\Uuid\Uuid;
use WC_REST_Product_Brands_Controller;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Cashier-facing brand reads through real v2 REST dispatch.
 */
class Test_Catalog_Proxy_Brands extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes before REST initialization and authenticate a cashier.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'product_brand' ) || ! class_exists( 'WC_REST_Product_Brands_Controller' ) ) {
			$this->markTestSkipped( 'WooCommerce product brand taxonomy or REST controller is unavailable in this test environment.' );
		}

		Proxy_Uuid_Stamper::register_proxy_stampers();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/** Remove sync state written outside the test transaction. */
	public function tearDown(): void {
		parent::tearDown();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
	}

	/**
	 * Dispatch a brand collection request.
	 *
	 * @param array $params Query parameters.
	 */
	private function read( array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products/brands' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * Brand rows carry stable UUIDs in meta_data.
	 */
	public function test_list_contains_fixtures_with_stable_uuid_meta_data(): void {
		$first_brand  = wp_insert_term( 'V2 UUID Brand One', 'product_brand' );
		$second_brand = wp_insert_term( 'V2 UUID Brand Two', 'product_brand' );
		$ids          = array( (int) $first_brand['term_id'], (int) $second_brand['term_id'] );

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
	 * Brand include= and exclude= parameters retain wc/v3 semantics.
	 */
	public function test_include_and_exclude_filter_brands(): void {
		$included = wp_insert_term( 'V2 Included Brand', 'product_brand' );
		$other    = wp_insert_term( 'V2 Other Brand', 'product_brand' );
		$included_id = (int) $included['term_id'];
		$other_id    = (int) $other['term_id'];

		$include_ids = array_map( 'intval', wp_list_pluck( $this->read( array( 'include' => array( $included_id ) ) ), 'id' ) );
		$exclude_ids = array_map( 'intval', wp_list_pluck( $this->read( array( 'exclude' => array( $included_id ) ) ), 'id' ) );
		$this->assertSame( array( $included_id ), $include_ids );
		$this->assertNotContains( $included_id, $exclude_ids );
		$this->assertContains( $other_id, $exclude_ids );
	}

	/**
	 * A brand row carries WooCommerce's BRAND shape plus the POS identity meta.
	 *
	 * Brands had no field-set pin at all (#1712): the only `array_keys` in this
	 * file walks ids, not fields, so the payload could have changed shape with
	 * the whole suite green — which is exactly how the v2 variation payload
	 * changed under us (#1710).
	 *
	 * The expectation is DERIVED from WooCommerce's own brands schema rather
	 * than copied out of a response; see
	 * {@see WCPOS_REST_Unit_Test_Case::view_context_fields()} for why a copied
	 * list can only ever ratify whatever we happened to emit that day.
	 */
	public function test_brand_row_is_the_woocommerce_brand_shape(): void {
		$brand = wp_insert_term( 'V2 Brand Field Set', 'product_brand' );

		$rows = $this->read( array( 'include' => array( (int) $brand['term_id'] ) ) );

		$this->assertCount( 1, $rows );
		/*
		 * `meta_data` is the one key WCPOS adds, and it is load-bearing rather than
		 * cosmetic: wc/v3 serves no meta on a term at all, so `Sync\Proxy_Uuid_Stamper`
		 * injects the record's `_woocommerce_pos_uuid` here. Without it the uuid-native
		 * client has no primary key for the row and throws. `_links` is appended by
		 * `rest_get_server()->response_to_data()`, not by the schema.
		 */
		$this->assertEqualsCanonicalizing(
			array_merge(
				$this->view_context_fields( ( new WC_REST_Product_Brands_Controller() )->get_public_item_schema()['properties'] ),
				array( 'meta_data', '_links' )
			),
			array_keys( $rows[0] )
		);
	}

	/**
	 * Brand search= matches the brand name.
	 */
	public function test_search_matches_brand_name(): void {
		$match = wp_insert_term( 'V2 Needle Brand 1372', 'product_brand' );
		$other = wp_insert_term( 'V2 Haystack Brand 1372', 'product_brand' );
		$ids   = array_map( 'intval', wp_list_pluck( $this->read( array( 'search' => 'Needle Brand 1372' ) ), 'id' ) );

		$this->assertContains( (int) $match['term_id'], $ids );
		$this->assertNotContains( (int) $other['term_id'], $ids );
	}
}
