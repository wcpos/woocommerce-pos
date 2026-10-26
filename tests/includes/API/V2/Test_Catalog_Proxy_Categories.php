<?php
/**
 * Tests for the v2 catalog proxy category read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WC_REST_Product_Categories_Controller;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Cashier-facing category reads through real v2 REST dispatch.
 */
class Test_Catalog_Proxy_Categories extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes before REST initialization and authenticate a cashier.
	 */
	public function setUp(): void {
		parent::setUp();
		// The identity stamper alone is not the lane a client reads through — see
		// {@see WCPOS_REST_Unit_Test_Case::install_sync_read_lane()}.
		$this->install_sync_read_lane();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/** Remove sync state written outside the test transaction. */
	public function tearDown(): void {
		parent::tearDown();
		$this->uninstall_sync_read_lane();
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Pos_Visibility::OPTION );
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
	 * Descendant products count once, while POS-hidden products do not count.
	 */
	public function test_category_pos_count_includes_descendants_and_excludes_hidden_products(): void {
		$parent    = wp_insert_term( 'V2 Count Parent', 'product_cat' );
		$parent_id = (int) $parent['term_id'];
		$child     = wp_insert_term( 'V2 Count Child', 'product_cat', array( 'parent' => $parent_id ) );
		$child_id  = (int) $child['term_id'];
		foreach ( array( array( $child_id ), array( $child_id, $parent_id ), array( $parent_id ) ) as $category_ids ) {
			$product = ProductHelper::create_simple_product();
			$product->set_status( 'publish' );
			$product->set_category_ids( $category_ids );
			$product->save();
		}
		$hidden = ProductHelper::create_simple_product();
		$hidden->set_status( 'publish' );
		$hidden->set_category_ids( array( $parent_id ) );
		$hidden->save();
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $hidden->get_id() ) ),
					),
				),
			)
		);

		$rows = array_column( $this->read( array( 'include' => array( $parent_id, $child_id ) ) ), null, 'id' );

		$this->assertSame( 3, $rows[ $parent_id ]['pos_count'] );
		$this->assertSame( 2, $rows[ $child_id ]['pos_count'] );
		$this->assertArrayHasKey( 'count', $rows[ $parent_id ] );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/products/categories/' . $parent_id ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $response->get_data()['pos_count'] );
	}

	/**
	 * A category containing only a draft has no published POS products.
	 */
	public function test_category_pos_count_excludes_draft_products(): void {
		$category = wp_insert_term( 'V2 Draft Category', 'product_cat' );
		$product  = ProductHelper::create_simple_product();
		$product->set_status( 'draft' );
		$product->set_category_ids( array( (int) $category['term_id'] ) );
		$product->save();

		$rows = $this->read( array( 'include' => array( (int) $category['term_id'] ) ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]['pos_count'] );
	}

	/**
	 * A category row carries WooCommerce's shape plus POS identity meta and count.
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
		 * `meta_data` is a BUSINESS key WCPOS adds, and it is load-bearing
		 * rather than cosmetic: wc/v3 serves no meta on a term at all, so
		 * `Sync\Proxy_Uuid_Stamper` injects the record's `_woocommerce_pos_uuid`
		 * here. Without it the uuid-native client has no primary key for the row and
		 * throws — the same addition, for the same reason, that the brands lane
		 * makes. `_rxdb_revision` is transport metadata, stamped on every proxied
		 * record by `Sync\Revision` at priority 9 (wired by
		 * `Sync\Augmentation_Pipeline::install()`, as `Init::__construct()` does in
		 * production). `pos_count` counts POS-visible products including descendants.
		 * `_links` is appended by
		 * `rest_get_server()->response_to_data()`, not by the schema.
		 */
		$this->assertEqualsCanonicalizing(
			array_merge(
				$this->view_context_fields( ( new WC_REST_Product_Categories_Controller() )->get_public_item_schema()['properties'] ),
				array( 'meta_data', 'pos_count', '_rxdb_revision', '_links' )
			),
			array_keys( $rows[0] )
		);

		/*
		 * Named rather than left to the set difference above: `_rxdb_digest` is
		 * absent from this lane STRUCTURALLY, not because the fixture happens to
		 * carry no stored digest. The categories registry row declares no digest
		 * group, so `Integrity_Digest::stamp_digests()` returns the payload
		 * untouched however full the digest index is — asserted from the registry
		 * so a row gaining a group can never leave this claim quietly stale.
		 */
		$this->assertNull( Collections::row( 'categories' )['digest'] );
		$this->assertArrayNotHasKey( '_rxdb_digest', $rows[0] );
	}
}
