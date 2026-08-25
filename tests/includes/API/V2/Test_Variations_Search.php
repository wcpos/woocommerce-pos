<?php
/**
 * Tests for variation search on the v2 variations endpoint.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WC_Product_Variation;
use WC_REST_Product_Variations_Controller;
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;

/**
 * GET /wcpos/v2/variations search and include modes.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Variations_Controller
 */
class Test_Variations_Search extends Sync_REST_Store_Test_Case {
	/**
	 * Enable v2 routes before REST initialization.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Remove options written outside the per-test transaction.
	 */
	public function tearDown(): void {
		Augmentation_Pipeline::reset();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
		remove_all_filters( Augmentation_Pipeline::PROXY_FILTER );
		remove_all_filters( Augmentation_Pipeline::SERIALIZED_FILTER );
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Pos_Visibility::OPTION );
		parent::tearDown();
	}

	/**
	 * Create a variation with an independently controlled SKU and status.
	 *
	 * @param string $sku    Variation SKU.
	 * @param string $status Variation post status.
	 */
	private function create_variation( string $sku, string $status = 'publish' ): WC_Product_Variation {
		$parent    = ProductHelper::create_variation_product();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10' );
		$variation->set_sku( $sku );
		$variation->set_status( $status );
		$variation->save();

		return $variation;
	}

	/**
	 * Dispatch a variations request with query parameters.
	 *
	 * @param array $params Query parameters for the request.
	 */
	private function variations_request( array $params = array() ) {
		$request = $this->wp_rest_get_request( '/wcpos/v2/variations' );
		$request->set_query_params( $params );

		return $this->server->dispatch( $request );
	}

	/**
	 * Decimal-enabled catalog reads must not integer-coerce variation stock.
	 */
	public function test_decimal_stock_quantity_is_preserved_on_variation_read(): void {
		$this->setup_decimal_quantity_tests();
		$variation = $this->create_variation( 'DECIMAL-STOCK-1456' );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 1.5 );
		$variation->save();

		$response = $this->variations_request( array( 'include' => array( $variation->get_id() ) ) );
		$payload  = $response->get_data()['documents'][0]['payload'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( 1.5, $payload['stock_quantity'] );
	}

	/**
	 * Variation documents carry one stable UUID in their serialized payload.
	 */
	public function test_variation_document_payload_contains_exactly_one_valid_uuid(): void {
		Augmentation_Pipeline::install();
		$variation = $this->create_variation( 'UUID-DOCUMENT-1456' );

		$response = $this->variations_request( array( 'include' => array( $variation->get_id() ) ) );
		$payload  = $response->get_data()['documents'][0]['payload'];
		$uuids    = array_values(
			array_filter(
				$payload['meta_data'],
				static fn( array $meta ): bool => '_woocommerce_pos_uuid' === $meta['key']
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $uuids );
		$this->assertTrue( Uuid::isValid( $uuids[0]['value'] ) );
		$this->assertSame( $uuids[0]['value'], get_post_meta( $variation->get_id(), '_woocommerce_pos_uuid', true ) );
	}

	/**
	 * A variation document carries WooCommerce's VARIATION shape — nothing more, nothing less.
	 *
	 * This test used to assert a hand-copied 60-field list of the PRODUCT schema, `images`,
	 * `categories`, `related_ids`, `variations` and all, under the name "the complete v2 field
	 * set". It was green for the whole life of the bug: it pinned the products-controller output
	 * as the contract, so the day a variation stopped carrying `image` there was nothing left to
	 * notice (#1710).
	 *
	 * The expectation is now DERIVED from WooCommerce's own variations schema rather than copied
	 * out of a response. A hand-copied list can only ever ratify whatever we happened to emit the
	 * day someone wrote it down; deriving it states the actual rule — we serve what WooCommerce's
	 * variations controller serves.
	 */
	public function test_variation_document_payload_is_the_woocommerce_variation_shape(): void {
		$variation = $this->create_variation( 'FIELD-SET-1456' );

		$response = $this->variations_request( array( 'include' => array( $variation->get_id() ) ) );
		$payload  = $response->get_data()['documents'][0]['payload'];

		$this->assertSame( 200, $response->get_status() );

		$schema_fields = array_keys( ( new WC_REST_Product_Variations_Controller() )->get_public_item_schema()['properties'] );
		/*
		 * WooCommerce emits three fields its variation SCHEMA does not declare (verified against
		 * WC 10.4.3): both `_gmt` date variants, and `name`, which the variations controller
		 * gained in WC 8.3 without a matching schema entry. Named here rather than smuggled in
		 * with a loose assertion — if WooCommerce ever closes that gap, this test says so.
		 */
		$emitted_but_undeclared = array( 'date_created_gmt', 'date_modified_gmt', 'name' );
		// `_links` is appended by rest_get_server()->response_to_data(), not by the schema.
		$expected = array_unique( array_merge( $schema_fields, $emitted_but_undeclared, array( '_links' ) ) );

		$this->assertEqualsCanonicalizing( $expected, array_keys( $payload ) );

		// Named explicitly as well as covered by the derivation above: these are the fields that
		// rode on every variation for the whole of 1.10.0 and meant nothing on any of them.
		foreach ( array( 'images', 'categories', 'tags', 'brands', 'related_ids', 'price_html', 'variations', 'grouped_products', 'default_attributes' ) as $product_only ) {
			$this->assertArrayNotHasKey( $product_only, $payload, "a variation must not carry the product field {$product_only}" );
		}

		// `barcode` is a v1-lane field: on v2 the client derives it at materialization from the
		// configured carrier (sku / global_unique_id / meta key), so the server must not invent one.
		$this->assertArrayNotHasKey( 'barcode', $payload );
		$this->assertArrayHasKey( 'sku', $payload );
		$this->assertArrayHasKey( 'global_unique_id', $payload );
		$this->assertArrayHasKey( 'image', $payload );
	}

	/**
	 * A SKU shared with a simple product never serves the product as a variation.
	 *
	 * WooCommerce widens `post_type` to `array( 'product', 'product_variation' )` whenever `sku`
	 * is set, because products and variations share one SKU space. Inherited unguarded on this
	 * route, a simple product would come back as a variation document and be filed into the
	 * client's VARIATIONS collection — the mirror of the misfiled-variation pollution the client
	 * already carries a one-shot repair for, where one record matching in both collections makes
	 * every scan of that code falsely ambiguous.
	 */
	public function test_sku_lookup_never_serves_a_product_as_a_variation(): void {
		$variation = $this->create_variation( 'SHARED-SKU-CODE' );
		$product   = ProductHelper::create_simple_product();
		// Written as meta, not through the CRUD: WooCommerce rejects a duplicate SKU on save, but
		// importers and migrations write `_sku` directly and stores do carry duplicates. That is
		// the data this guard exists for.
		update_post_meta( $product->get_id(), '_sku', 'SHARED-SKU-CODE' );

		$response = $this->variations_request( array( 'sku' => 'SHARED-SKU-CODE' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $variation->get_id() ), array_column( $response->get_data()['documents'], 'id' ) );
	}

	/**
	 * A paginated search reports its total in WooCommerce's headers, not only in the body.
	 */
	public function test_search_emits_woocommerce_pagination_headers(): void {
		foreach ( range( 1, 3 ) as $index ) {
			$this->create_variation( "PAGED-HEADER-{$index}" );
		}

		$response = $this->variations_request(
			array(
				'search'   => 'PAGED-HEADER',
				'per_page' => 2,
			)
		);
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '3', (string) $headers['X-WP-Total'] );
		$this->assertSame( '2', (string) $headers['X-WP-TotalPages'] );
	}

	/**
	 * Search uses partial LIKE matching against variation SKUs only.
	 */
	public function test_search_partially_matches_sku(): void {
		$match = $this->create_variation( 'PREFIX-NEEDLE-SUFFIX' );
		$this->create_variation( 'PREFIX-OTHER-SUFFIX' );

		$response = $this->variations_request( array( 'search' => 'needle' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $match->get_id() ), array_column( $data['documents'], 'id' ) );
		$this->assertSame( 1, $data['meta']['total'] );
	}

	/**
	 * Search includes the configured custom barcode meta field.
	 */
	public function test_search_matches_configured_barcode_field(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_custom_barcode' ) );
		$match = $this->create_variation( 'NO-SKU-MATCH' );
		$match->update_meta_data( '_custom_barcode', 'BARCODE-NEEDLE-123' );
		$match->save_meta_data();

		$response = $this->variations_request( array( 'search' => 'needle' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $match->get_id() ), array_column( $response->get_data()['documents'], 'id' ) );
	}

	/**
	 * Exact SKU wins over search when both parameters are present.
	 */
	public function test_sku_exact_match_wins_over_search(): void {
		$exact = $this->create_variation( 'EXACT-SKU' );
		$this->create_variation( 'EXACT-SKU-EXTRA' );
		$this->create_variation( 'SEARCH-ONLY' );

		$response = $this->variations_request(
			array(
				'sku'    => 'EXACT-SKU',
				'search' => 'SEARCH-ONLY',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $exact->get_id() ), array_column( $response->get_data()['documents'], 'id' ) );
	}

	/**
	 * Exact SKU accepts a comma-separated list.
	 */
	public function test_sku_exact_match_accepts_comma_list(): void {
		$first  = $this->create_variation( 'LIST-FIRST' );
		$second = $this->create_variation( 'LIST-SECOND' );
		$this->create_variation( 'LIST-FIRST-EXTRA' );

		$response = $this->variations_request( array( 'sku' => 'LIST-FIRST, LIST-SECOND' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( $second->get_id(), $first->get_id() ),
			array_column( $response->get_data()['documents'], 'id' )
		);
	}

	/**
	 * Pagination returns a stable descending page and the unpaged total.
	 */
	public function test_search_paginates_with_total(): void {
		$first = $this->create_variation( 'PAGE-MATCH-1' );
		$this->create_variation( 'PAGE-MATCH-2' );
		$this->create_variation( 'PAGE-MATCH-3' );

		$response = $this->variations_request(
			array(
				'search'   => 'PAGE-MATCH',
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $first->get_id() ), array_column( $data['documents'], 'id' ) );
		$this->assertSame( 3, $data['meta']['total'] );
		$this->assertSame( 2, $data['meta']['page'] );
		$this->assertSame( 2, $data['meta']['per_page'] );
	}

	/**
	 * Search resource limits accept requests at each boundary.
	 *
	 * @dataProvider valid_search_limit_provider
	 *
	 * @param array $params Query parameters for the request.
	 */
	public function test_search_accepts_resource_limit_boundary( array $params ): void {
		$response = $this->variations_request( $params );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Search resource limits reject requests above each boundary.
	 *
	 * @dataProvider invalid_search_limit_provider
	 *
	 * @param array $params Query parameters for the request.
	 */
	public function test_search_rejects_request_over_resource_limit( array $params ): void {
		$response = $this->variations_request( $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_variations_search_limit_exceeded', $response->get_data()['code'] );
	}

	/**
	 * Requests at the configured resource limits.
	 *
	 * @return array<string, array{array<string, int|string>}>
	 */
	public function valid_search_limit_provider(): array {
		return array(
			'sku precedence'    => array(
				array(
					'sku'    => 'EXACT-SKU',
					'search' => str_repeat( 'S', 257 ),
				),
			),
			'sku length'        => array( array( 'sku' => str_repeat( 'S', 4096 ) ) ),
			'sku terms'         => array( array( 'sku' => implode( ',', array_fill( 0, 100, 'SKU' ) ) ) ),
			'search length'     => array( array( 'search' => str_repeat( 'S', 256 ) ) ),
			'search terms'      => array( array( 'search' => implode( ' ', array_fill( 0, 10, 'term' ) ) ) ),
			'page'              => array(
				array(
					'search' => 'boundary',
					'page' => 1000,
					'per_page' => 100,
				),
			),
		);
	}

	/**
	 * Requests above the configured resource limits.
	 *
	 * @return array<string, array{array<string, int|string>}>
	 */
	public function invalid_search_limit_provider(): array {
		return array(
			'sku length'        => array( array( 'sku' => str_repeat( 'S', 4097 ) ) ),
			'sku terms'         => array( array( 'sku' => implode( ',', array_fill( 0, 101, 'SKU' ) ) ) ),
			'search length'     => array( array( 'search' => str_repeat( 'S', 257 ) ) ),
			'search terms'      => array( array( 'search' => implode( ' ', array_fill( 0, 11, 'term' ) ) ) ),
			'page'              => array(
				array(
					'search' => 'boundary',
					'page' => 1001,
					'per_page' => 100,
				),
			),
		);
	}

	/**
	 * Online-only variations are excluded before pagination and counting.
	 */
	public function test_search_excludes_online_only_variation(): void {
		$visible = $this->create_variation( 'VISIBILITY-MATCH-VISIBLE' );
		$hidden  = $this->create_variation( 'VISIBILITY-MATCH-HIDDEN' );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'variations' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $hidden->get_id() ) ),
					),
				),
			)
		);

		$response = $this->variations_request( array( 'search' => 'VISIBILITY-MATCH' ) );
		$data     = $response->get_data();

		$this->assertSame( array( $visible->get_id() ), array_column( $data['documents'], 'id' ) );
		$this->assertSame( 1, $data['meta']['total'] );
	}

	/**
	 * Non-published variations are outside the search scope.
	 */
	public function test_search_excludes_non_published_variation(): void {
		$published = $this->create_variation( 'STATUS-MATCH-PUBLISHED' );
		$this->create_variation( 'STATUS-MATCH-DRAFT', 'draft' );

		$response = $this->variations_request( array( 'search' => 'STATUS-MATCH' ) );

		$this->assertSame( array( $published->get_id() ), array_column( $response->get_data()['documents'], 'id' ) );
		$this->assertSame( 1, $response->get_data()['meta']['total'] );
	}

	/**
	 * Include mode retains its existing envelope without search metadata.
	 */
	public function test_include_mode_still_returns_existing_shape(): void {
		$variation = $this->create_variation( 'INCLUDE-MODE' );

		$response = $this->variations_request( array( 'include' => (string) $variation->get_id() ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $variation->get_id(), $data['documents'][0]['id'] );
		$this->assertSame( $variation->get_parent_id(), $data['documents'][0]['parent_id'] );
		$this->assertArrayHasKey( 'payload', $data['documents'][0] );
		$this->assertSame( 1, $data['meta']['requested'] );
		$this->assertSame( 1, $data['meta']['returned'] );
		$this->assertArrayNotHasKey( 'total', $data['meta'] );
		$this->assertArrayNotHasKey( 'page', $data['meta'] );
		$this->assertArrayNotHasKey( 'per_page', $data['meta'] );
	}

	/**
	 * Requests without include or a search mode keep the existing 400.
	 */
	public function test_no_params_returns_existing_missing_ids_error(): void {
		$response = $this->variations_request();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_sync_missing_ids', $response->get_data()['code'] );
	}
}
