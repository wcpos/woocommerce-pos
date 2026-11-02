<?php
/**
 * Tests for the v2 catalog proxy tag read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Cashier-facing tag reads through real v2 REST dispatch.
 */
class Test_Catalog_Proxy_Tags extends WCPOS_REST_Unit_Test_Case {
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
	 * Dispatch a tag collection request.
	 *
	 * @param array $params Query parameters.
	 */
	private function read( array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products/tags' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * Tag rows carry stable UUIDs in meta_data.
	 */
	public function test_list_contains_fixtures_with_stable_uuid_meta_data(): void {
		$first_tag  = wp_insert_term( 'V2 UUID Tag One', 'product_tag' );
		$second_tag = wp_insert_term( 'V2 UUID Tag Two', 'product_tag' );
		$ids        = array( (int) $first_tag['term_id'], (int) $second_tag['term_id'] );

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
	 * Tag include= and exclude= parameters retain wc/v3 semantics.
	 */
	public function test_include_and_exclude_filter_tags(): void {
		$included = wp_insert_term( 'V2 Included Tag', 'product_tag' );
		$other    = wp_insert_term( 'V2 Other Tag', 'product_tag' );
		$included_id = (int) $included['term_id'];
		$other_id    = (int) $other['term_id'];

		$include_ids = array_map( 'intval', wp_list_pluck( $this->read( array( 'include' => array( $included_id ) ) ), 'id' ) );
		$exclude_ids = array_map( 'intval', wp_list_pluck( $this->read( array( 'exclude' => array( $included_id ) ) ), 'id' ) );
		$this->assertSame( array( $included_id ), $include_ids );
		$this->assertNotContains( $included_id, $exclude_ids );
		$this->assertContains( $other_id, $exclude_ids );
	}

	/**
	 * Tag search= matches the tag name.
	 */
	public function test_search_matches_tag_name(): void {
		$match = wp_insert_term( 'V2 Needle Tag 1372', 'product_tag' );
		$other = wp_insert_term( 'V2 Haystack Tag 1372', 'product_tag' );
		$ids   = array_map( 'intval', wp_list_pluck( $this->read( array( 'search' => 'Needle Tag 1372' ) ), 'id' ) );

		$this->assertContains( (int) $match['term_id'], $ids );
		$this->assertNotContains( (int) $other['term_id'], $ids );
	}

	/**
	 * Every tag has a POS count, excluding hidden products and including zero.
	 */
	public function test_tag_pos_count_excludes_hidden_products_on_every_row(): void {
		$tag   = wp_insert_term( 'V2 Count Tag', 'product_tag' );
		$empty = wp_insert_term( 'V2 Empty Tag', 'product_tag' );
		foreach ( array( 'visible', 'hidden' ) as $name ) {
			$product = ProductHelper::create_simple_product( array( 'name' => $name ) );
			$product->set_status( 'publish' );
			$product->set_tag_ids( array( (int) $tag['term_id'] ) );
			$product->save();
		}
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $product->get_id() ) ),
					),
				),
			)
		);

		$rows = array_column( $this->read( array( 'include' => array( (int) $tag['term_id'], (int) $empty['term_id'] ) ) ), null, 'id' );

		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertArrayHasKey( 'pos_count', $row );
		}
		$this->assertSame( 1, $rows[ $tag['term_id'] ]['pos_count'] );
		$this->assertSame( 0, $rows[ $empty['term_id'] ]['pos_count'] );
	}

	/**
	 * Tag rows expose the complete v2 field set.
	 */
	public function test_tag_row_has_full_v2_field_set(): void {
		$tag = wp_insert_term( 'V2 Tag Field Set', 'product_tag' );

		$rows = $this->read( array( 'include' => array( (int) $tag['term_id'] ) ) );

		/*
		 * `_rxdb_revision` is transport metadata, not a tag field: `Sync\Revision`
		 * stamps it at priority 9 onto every record whose proxy slug the registry
		 * resolves, tags included (wired by `Sync\Augmentation_Pipeline::install()`,
		 * as `Init::__construct()` does in production). It was missing from this
		 * list while the test ran without the production read lane, which made the
		 * pin assert a row shape no deployed client receives (#1717).
		 */
		$this->assertEqualsCanonicalizing(
			array(
				'id',
				'name',
				'slug',
				'description',
				'count',
				'pos_count',
				'meta_data',
				'_rxdb_revision',
				'_links',
			),
			array_keys( $rows[0] )
		);

		/*
		 * Named rather than left to the set difference above: `_rxdb_digest` is
		 * absent from this lane STRUCTURALLY. The tags registry row declares no
		 * digest group, so `Integrity_Digest::stamp_digests()` returns the payload
		 * untouched however full the digest index is — asserted from the registry
		 * so a row gaining a group can never leave this claim quietly stale.
		 */
		$this->assertNull( Collections::row( 'tags' )['digest'] );
		$this->assertArrayNotHasKey( '_rxdb_digest', $rows[0] );
	}
}
