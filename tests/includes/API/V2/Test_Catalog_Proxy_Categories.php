<?php
/**
 * Tests for the v2 catalog proxy category read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Ramsey\Uuid\Uuid;
use WC_REST_Product_Categories_Controller;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Cashier-facing category reads through real v2 REST dispatch.
 */
class Test_Catalog_Proxy_Categories extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes before REST initialization and authenticate a cashier.
	 */
	public function setUp(): void {
		Proxy_Uuid_Stamper::register_proxy_stampers();
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/** Remove sync state written outside the test transaction. */
	public function tearDown(): void {
		parent::tearDown();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
	}

	/**
	 * Dispatch a category collection request.
	 *
	 * @param array $params Query parameters.
	 */
	private function read( array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products/categories' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * Category rows carry stable UUIDs in meta_data.
	 */
	public function test_list_contains_fixtures_with_stable_uuid_meta_data(): void {
		$first_category  = wp_insert_term( 'V2 UUID Category One', 'product_cat' );
		$second_category = wp_insert_term( 'V2 UUID Category Two', 'product_cat' );
		$ids             = array( (int) $first_category['term_id'], (int) $second_category['term_id'] );

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
	 * Category include= and exclude= parameters retain wc/v3 semantics.
	 */
	public function test_include_and_exclude_filter_categories(): void {
		$included = wp_insert_term( 'V2 Included Category', 'product_cat' );
		$other    = wp_insert_term( 'V2 Other Category', 'product_cat' );
		$included_id = (int) $included['term_id'];
		$other_id    = (int) $other['term_id'];

		$include_ids = array_map( 'intval', wp_list_pluck( $this->read( array( 'include' => array( $included_id ) ) ), 'id' ) );
		$exclude_ids = array_map( 'intval', wp_list_pluck( $this->read( array( 'exclude' => array( $included_id ) ) ), 'id' ) );
		$this->assertSame( array( $included_id ), $include_ids );
		$this->assertNotContains( $included_id, $exclude_ids );
		$this->assertContains( $other_id, $exclude_ids );
	}

	/**
	 * Category search= matches the category name.
	 */
	public function test_search_matches_category_name(): void {
		$match = wp_insert_term( 'V2 Needle Category 1372', 'product_cat' );
		$other = wp_insert_term( 'V2 Haystack Category 1372', 'product_cat' );
		$ids   = array_map( 'intval', wp_list_pluck( $this->read( array( 'search' => 'Needle Category 1372' ) ), 'id' ) );

		$this->assertContains( (int) $match['term_id'], $ids );
		$this->assertNotContains( (int) $other['term_id'], $ids );
	}

	/**
	 * A duplicate term UUID is re-keyed before either category reaches the client.
	 */
	public function test_category_uuid_collision_rekeys_one_term_and_serves_distinct_uuids(): void {
		$shared = wp_generate_uuid4();
		$first  = wp_insert_term( 'V2 UUID Collision Category One', 'product_cat' );
		$second = wp_insert_term( 'V2 UUID Collision Category Two', 'product_cat' );
		$ids    = array( (int) $first['term_id'], (int) $second['term_id'] );
		add_term_meta( $ids[0], Pos_Uuid::META_KEY, $shared, true );
		add_term_meta( $ids[1], Pos_Uuid::META_KEY, $shared, true );

		$rows   = array_column( $this->read( array( 'include' => $ids ) ), null, 'id' );
		$served = array();
		foreach ( $ids as $id ) {
			$meta          = array_column( $rows[ $id ]['meta_data'], 'value', 'key' );
			$served[ $id ] = $meta[ Pos_Uuid::META_KEY ];
			$this->assertTrue( Uuid::isValid( $served[ $id ] ) );
		}
		$stored = array(
			$ids[0] => get_term_meta( $ids[0], Pos_Uuid::META_KEY, true ),
			$ids[1] => get_term_meta( $ids[1], Pos_Uuid::META_KEY, true ),
		);

		$this->assertCount( 2, array_unique( $served ) );
		$this->assertCount( 2, array_unique( $stored ) );
		$this->assertContains( $shared, $stored );
		$this->assertSame( $stored, $served );
	}

	/**
	 * A category row carries WooCommerce's CATEGORY shape plus the POS identity meta.
	 *
	 * The expectation is DERIVED from WooCommerce's own product-categories schema
	 * rather than copied out of a response; see
	 * {@see WCPOS_REST_Unit_Test_Case::view_context_fields()} for why a copied
	 * list can only ever ratify whatever we happened to emit that day — which is
	 * exactly how the v2 variation payload changed shape under us with the whole
	 * suite green (#1710, #1712).
	 */
	public function test_category_row_is_the_woocommerce_category_shape(): void {
		$category = wp_insert_term( 'V2 Category Field Set', 'product_cat' );

		$rows = $this->read( array( 'include' => array( (int) $category['term_id'] ) ) );

		$this->assertCount( 1, $rows );
		/*
		 * `meta_data` is the one key WCPOS adds, and it is load-bearing rather than
		 * cosmetic: wc/v3 serves no meta on a term at all, so `Sync\Proxy_Uuid_Stamper`
		 * injects the record's `_woocommerce_pos_uuid` here. Without it the uuid-native
		 * client has no primary key for the row and throws — the same addition, for the
		 * same reason, that the brands lane makes. `_links` is appended by
		 * `rest_get_server()->response_to_data()`, not by the schema.
		 */
		$this->assertEqualsCanonicalizing(
			array_merge(
				$this->view_context_fields( ( new WC_REST_Product_Categories_Controller() )->get_public_item_schema()['properties'] ),
				array( 'meta_data', '_links' )
			),
			array_keys( $rows[0] )
		);
	}
}
