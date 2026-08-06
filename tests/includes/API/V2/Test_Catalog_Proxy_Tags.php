<?php
/**
 * Tests for the v2 catalog proxy tag read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Cashier-facing tag reads through real v2 REST dispatch.
 */
class Test_Catalog_Proxy_Tags extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes before REST initialization and authenticate a cashier.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		Proxy_Uuid_Stamper::register_proxy_stampers();
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/** Remove sync state written outside the test transaction. */
	public function tearDown(): void {
		parent::tearDown();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
		delete_option( Api::OPTION_ENABLED );
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
	 * Tag rows expose the complete v2 field set.
	 */
	public function test_tag_row_has_full_v2_field_set(): void {
		$tag = wp_insert_term( 'V2 Tag Field Set', 'product_tag' );

		$rows = $this->read( array( 'include' => array( (int) $tag['term_id'] ) ) );

		$this->assertEqualsCanonicalizing(
			array(
				'id',
				'name',
				'slug',
				'description',
				'count',
				'meta_data',
				'_links',
			),
			array_keys( $rows[0] )
		);
	}
}
