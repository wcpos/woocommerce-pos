<?php
/**
 * Tests for the v2 catalog proxy product read contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Ramsey\Uuid\Uuid;
use WC_REST_Products_Controller;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Product rows preserve the replication contract exposed by wcpos/v2.
 */
class Test_Catalog_Proxy_Products extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes and the read-time identity stamper.
	 */
	public function setUp(): void {
		Proxy_Uuid_Stamper::register_proxy_stampers();
		parent::setUp();
	}

	/**
	 * Remove sync state written outside the test transaction.
	 */
	public function tearDown(): void {
		parent::tearDown();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
	}

	/**
	 * Dispatch a product collection request.
	 *
	 * @param array $params Query parameters.
	 */
	private function read( array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Decimal-enabled catalog reads must not integer-coerce product stock.
	 */
	public function test_decimal_stock_quantity_is_preserved_on_product_read(): void {
		$this->setup_decimal_quantity_tests();
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 1.5,
			)
		);

		$rows = $this->read( array( 'include' => array( $product->get_id() ) ) );

		$this->assertCount( 1, $rows );
		$this->assertEquals( 1.5, $rows[0]['stock_quantity'] );
	}

	/**
	 * A duplicate proxy UUID is re-keyed before either row reaches the client.
	 */
	public function test_product_uuid_collision_rekeys_one_record_and_serves_distinct_uuids(): void {
		$shared = wp_generate_uuid4();
		$first  = ProductHelper::create_simple_product();
		$second = ProductHelper::create_simple_product();
		update_post_meta( $first->get_id(), Pos_Uuid::META_KEY, $shared );
		update_post_meta( $second->get_id(), Pos_Uuid::META_KEY, $shared );
		$ids = array( $first->get_id(), $second->get_id() );

		$rows = array_column( $this->read( array( 'include' => $ids ) ), null, 'id' );
		$served = array();
		foreach ( $ids as $id ) {
			$meta          = array_column( $rows[ $id ]['meta_data'], 'value', 'key' );
			$served[ $id ] = $meta[ Pos_Uuid::META_KEY ];
			$this->assertTrue( Uuid::isValid( $served[ $id ] ) );
		}
		$stored = array(
			$first->get_id()  => get_post_meta( $first->get_id(), Pos_Uuid::META_KEY, true ),
			$second->get_id() => get_post_meta( $second->get_id(), Pos_Uuid::META_KEY, true ),
		);

		$this->assertCount( 2, array_unique( $served ) );
		$this->assertCount( 2, array_unique( $stored ) );
		$this->assertContains( $shared, $stored );
		$this->assertSame( $stored, $served );
	}

	/**
	 * A product row is WooCommerce's PRODUCT shape, with no WCPOS field on top.
	 *
	 * The expectation is DERIVED from WooCommerce's own products schema rather
	 * than copied out of a response; see
	 * {@see WCPOS_REST_Unit_Test_Case::view_context_fields()} for why a copied
	 * list can only ever ratify whatever we happened to emit that day. This pin
	 * used to be a 70-entry literal, which is the shape of the problem: it could
	 * not tell a field WooCommerce declares from a field we happened to serve,
	 * so it read as agreement with wc/v3 while asserting nothing of the kind
	 * (#1712).
	 */
	public function test_product_row_is_the_woocommerce_product_shape(): void {
		$product = ProductHelper::create_simple_product();

		$rows = $this->read( array( 'include' => array( $product->get_id() ) ) );
		$row  = $rows[0];

		/*
		 * The delta is `_links` alone, and that is the load-bearing claim here: on
		 * the product lane WCPOS adds NO top-level key of its own. The POS identity
		 * rides `meta_data` — a key WooCommerce itself declares — so
		 * `Sync\Proxy_Uuid_Stamper` injects `_woocommerce_pos_uuid` into an existing
		 * field rather than widening the row (pinned by the uuid tests above).
		 * `_links` is appended by `rest_get_server()->response_to_data()` from the
		 * controller's own `prepare_links()`, not by the schema.
		 */
		$this->assertEqualsCanonicalizing(
			array_merge(
				$this->view_context_fields( ( new WC_REST_Products_Controller() )->get_public_item_schema()['properties'] ),
				array( '_links' )
			),
			array_keys( $row )
		);
		/*
		 * Implied by the equality above, kept because it names the divergence this
		 * test exists to hold: v1 aliases the POS barcode onto a top-level `barcode`
		 * field (API\V1\Products_Controller::wcpos_product_response), and v2
		 * deliberately does not — the client reads the configured barcode meta
		 * instead. A regression that re-adds it should say "barcode came back", not
		 * hand a reviewer an anonymous set difference.
		 */
		$this->assertArrayNotHasKey( 'barcode', $row );
	}

	/**
	 * Product search matches the v1 title, SKU, and barcode fields only.
	 */
	public function test_product_search(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
				);
			}
		);

		$title       = wp_generate_password( 12, false );
		$sku         = wp_generate_password( 8, false );
		$barcode     = wp_generate_password( 10, false );
		$description = 'A string containing ' . $title . ' and ' . $sku . ' and ' . $barcode;

		ProductHelper::create_simple_product( array( 'description' => $description ) );
		$product2 = ProductHelper::create_simple_product(
			array(
				'description' => $description,
				'name'        => 'Foo ' . $title . ' bar',
			)
		);
		$product3 = ProductHelper::create_simple_product(
			array(
				'description' => $description,
				'sku'         => 'foo-' . $sku . '-bar',
			)
		);
		$product4 = ProductHelper::create_simple_product( array( 'description' => $description ) );
		$product4->update_meta_data( '_barcode', 'foo-' . $barcode . '-bar' );
		$product4->save_meta_data();

		$this->assertCount( 4, $this->read( array( 'search' => '' ) ) );

		$rows = $this->read( array( 'search' => $title ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $product2->get_id(), $rows[0]['id'] );

		$rows = $this->read( array( 'search' => $sku ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $product3->get_id(), $rows[0]['id'] );

		$rows = $this->read( array( 'search' => $barcode ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( $product4->get_id(), $rows[0]['id'] );
	}

	/**
	 * An exact SKU match must not fall behind a full first page of title matches.
	 */
	public function test_product_search_ranks_exact_sku_first(): void {
		$exact = ProductHelper::create_simple_product( array( 'sku' => 'red' ) );
		$this->create_red_title_matches();

		$rows = $this->read(
			array(
				'search'   => 'red',
				'per_page' => 20,
				'orderby'  => 'id',
				'order'    => 'desc',
			)
		);

		$this->assertCount( 20, $rows );
		$this->assertSame( $exact->get_id(), $rows[0]['id'] );
	}

	/**
	 * An exact configured-barcode match must rank ahead of title matches.
	 */
	public function test_product_search_ranks_exact_barcode_first(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
				);
			}
		);

		$exact = ProductHelper::create_simple_product();
		$exact->update_meta_data( '_barcode', 'red' );
		$exact->save_meta_data();
		$this->create_red_title_matches();

		$rows = $this->read(
			array(
				'search'   => 'red',
				'per_page' => 20,
				'orderby'  => 'id',
				'order'    => 'desc',
			)
		);

		$this->assertCount( 20, $rows );
		$this->assertSame( $exact->get_id(), $rows[0]['id'] );
	}

	/**
	 * A claimed POS sort must retain the exact-carrier rank ahead of its own order.
	 */
	public function test_product_search_exact_rank_precedes_claimed_sku_sort(): void {
		$exact = ProductHelper::create_simple_product( array( 'sku' => 'red' ) );
		$this->create_red_title_matches( true );

		$rows = $this->read(
			array(
				'search'   => 'red',
				'per_page' => 20,
				'orderby'  => 'sku',
				'order'    => 'asc',
			)
		);

		$this->assertCount( 20, $rows );
		$this->assertSame( $exact->get_id(), $rows[0]['id'] );
	}

	/**
	 * Regression: wcpos/v2 search must not match product descriptions.
	 */
	public function test_product_search_does_not_match_description(): void {
		$term        = wp_generate_password( 12, false );
		$description = ProductHelper::create_simple_product( array( 'description' => 'Contains ' . $term ) );
		$title       = ProductHelper::create_simple_product( array( 'name' => 'Contains ' . $term ) );

		$rows = $this->read( array( 'search' => $term ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( $title->get_id(), $rows[0]['id'] );
		$this->assertNotSame( $description->get_id(), $rows[0]['id'] );
	}

	/**
	 * PHP's empty() calls the string "0" empty; a search for the literal term 0 is still a search.
	 */
	public function test_product_search_for_literal_zero_is_a_search(): void {
		$description = ProductHelper::create_simple_product( array( 'name' => 'Alpha', 'sku' => 'ALPHA', 'description' => 'Rated 0 stars' ) );
		$zero        = ProductHelper::create_simple_product( array( 'name' => 'Beta', 'sku' => 'ZERO-0', 'description' => 'no digits here' ) );

		$rows = $this->read( array( 'search' => '0' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( $zero->get_id(), $rows[0]['id'] );
		$this->assertNotSame( $description->get_id(), $rows[0]['id'] );
	}

	/**
	 * Port of API\V1 test_product_orderby_sku, plus the product that has no SKU at all.
	 *
	 * The sequences here used to stop at `zeta`, because every product in the fixture had
	 * a SKU — which is exactly why the sort-as-filter bug survived: with no meta-less row
	 * present, an INNER JOIN and a LEFT JOIN return the same list (#1779 follow-up).
	 */
	public function test_product_orderby_sku(): void {
		ProductHelper::create_simple_product( array( 'sku' => '987654321' ) );
		ProductHelper::create_simple_product( array( 'sku' => 'zeta' ) );
		ProductHelper::create_simple_product( array( 'sku' => '123456789' ) );
		ProductHelper::create_simple_product( array( 'sku' => 'alpha' ) );
		$this->create_product_without_meta( '_sku' );

		$rows = $this->read( array( 'orderby' => 'sku', 'order' => 'asc' ) );

		$this->assertSame( array( '123456789', '987654321', 'alpha', 'zeta', '' ), wp_list_pluck( $rows, 'sku' ) );

		$rows = $this->read( array( 'orderby' => 'sku', 'order' => 'desc' ) );

		$this->assertSame( array( 'zeta', 'alpha', '987654321', '123456789', '' ), wp_list_pluck( $rows, 'sku' ) );
	}

	/**
	 * Port of API\V1 test_product_orderby_barcode, plus a product with no barcode.
	 *
	 * The barcode row is the one that has NO postmeta row at all, which is the shape the
	 * default store is in — the barcode field defaults to `_global_unique_id`, which most
	 * catalogues never populate — and the shape that made this sort answer with an empty
	 * page. v2 serves no top-level `barcode` field by design, so the value is read back
	 * out of `meta_data`.
	 */
	public function test_product_orderby_barcode(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
				);
			}
		);

		$product1 = ProductHelper::create_simple_product();
		$product1->update_meta_data( '_barcode', 'alpha' );
		$product1->save_meta_data();

		$product2 = ProductHelper::create_simple_product();
		$product2->update_meta_data( '_barcode', 'zeta' );
		$product2->save_meta_data();

		// No `_barcode` meta row whatsoever — the row an INNER JOIN drops.
		ProductHelper::create_simple_product();

		$rows = $this->read( array( 'orderby' => 'barcode', 'order' => 'asc' ) );

		$this->assertSame( array( 'alpha', 'zeta', null ), $this->barcodes( $rows ) );

		$rows = $this->read( array( 'orderby' => 'barcode', 'order' => 'desc' ) );

		$this->assertSame( array( 'zeta', 'alpha', null ), $this->barcodes( $rows ) );
	}

	/**
	 * A sort must never change WHICH products come back — only their order.
	 *
	 * The regression this pins is the whole point: `orderby=barcode` answered a
	 * category-filtered browse window with an empty page on any store whose products
	 * carry no barcode, so the POS grid's barcode column went blank.
	 */
	public function test_product_orderby_barcode_preserves_category_membership(): void {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'barcode_field' => '_barcode',
				);
			}
		);

		$category = wp_insert_term( 'Gear', 'product_cat' );
		$this->assertIsArray( $category );
		$members = array();
		foreach ( array( 'b-alpha', null, 'b-mike' ) as $barcode ) {
			$product = ProductHelper::create_simple_product();
			$product->set_category_ids( array( (int) $category['term_id'] ) );
			$product->save();
			if ( null !== $barcode ) {
				$product->update_meta_data( '_barcode', $barcode );
				$product->save_meta_data();
			}
			$members[] = $product->get_id();
		}
		// A product OUTSIDE the category, so a filter that stopped filtering also fails.
		ProductHelper::create_simple_product();

		$unsorted = $this->read( array( 'category' => (string) $category['term_id'] ) );
		$sorted   = $this->read(
			array(
				'category' => (string) $category['term_id'],
				'orderby'  => 'barcode',
				'order'    => 'asc',
			)
		);

		sort( $members );
		$this->assertSame( $members, $this->sorted_ids( $unsorted ) );
		$this->assertSame( $members, $this->sorted_ids( $sorted ) );
	}

	/**
	 * Port of API\V1 test_product_orderby_stock_status, plus a product with no status meta.
	 *
	 * Asserted on IDS, not on the reported status: WooCommerce defaults a product with no
	 * `_stock_status` row to "instock" in the payload, so the value sequence could not
	 * tell the meta-less row apart from a real in-stock one. What this pins is that the
	 * row is still SERVED, and served last.
	 */
	public function test_product_orderby_stock_status(): void {
		$instock    = ProductHelper::create_simple_product( array( 'stock_status' => 'instock' ) );
		$outofstock = ProductHelper::create_simple_product( array( 'stock_status' => 'outofstock' ) );
		$metaless   = $this->create_product_without_meta( '_stock_status' );

		$rows = $this->read( array( 'orderby' => 'stock_status', 'order' => 'asc' ) );

		$this->assertSame(
			array( $instock->get_id(), $outofstock->get_id(), $metaless ),
			wp_list_pluck( $rows, 'id' )
		);

		$rows = $this->read( array( 'orderby' => 'stock_status', 'order' => 'desc' ) );

		$this->assertSame(
			array( $outofstock->get_id(), $instock->get_id(), $metaless ),
			wp_list_pluck( $rows, 'id' )
		);
	}

	/**
	 * Port of API\V1 test_product_orderby_stock_quantity (#1779).
	 *
	 * Products that do not manage stock carry a NULL `_stock`, and they must land LAST
	 * in both directions — MySQL would otherwise float them to the top under ASC.
	 */
	public function test_product_orderby_stock_quantity(): void {
		foreach ( array( 1, 2, null, 0, -1 ) as $quantity ) {
			ProductHelper::create_simple_product(
				array(
					'stock_quantity' => $quantity,
					'manage_stock'   => true,
				)
			);
		}
		ProductHelper::create_simple_product();

		$rows = $this->read( array( 'orderby' => 'stock_quantity', 'order' => 'asc' ) );

		$this->assertSame( array( -1, 0, 1, 2, null, null ), wp_list_pluck( $rows, 'stock_quantity' ) );

		$rows = $this->read( array( 'orderby' => 'stock_quantity', 'order' => 'desc' ) );

		$this->assertSame( array( 2, 1, 0, -1, null, null ), wp_list_pluck( $rows, 'stock_quantity' ) );
	}

	/**
	 * Port of API\V1 test_product_orderby_decimal_stock_quantity, plus an unmanaged product.
	 */
	public function test_product_orderby_decimal_stock_quantity(): void {
		$this->setup_decimal_quantity_tests();
		$this->assertTrue( woocommerce_pos_get_settings( 'general', 'decimal_qty' ) );

		foreach ( array( '11.2', '3.5', '20.7' ) as $quantity ) {
			ProductHelper::create_simple_product(
				array(
					'stock_quantity' => $quantity,
					'manage_stock'   => true,
				)
			);
		}
		ProductHelper::create_simple_product();

		$rows = $this->read( array( 'orderby' => 'stock_quantity', 'order' => 'asc' ) );

		$this->assertEquals( array( 3.5, 11.2, 20.7, null ), wp_list_pluck( $rows, 'stock_quantity' ) );

		$rows = $this->read( array( 'orderby' => 'stock_quantity', 'order' => 'desc' ) );

		$this->assertEquals( array( 20.7, 11.2, 3.5, null ), wp_list_pluck( $rows, 'stock_quantity' ) );
	}

	/**
	 * A product whose postmeta row for `$meta_key` is absent entirely.
	 *
	 * WooCommerce always writes these keys, so the row has to be removed after the fact
	 * to reproduce the catalogue shape an importer leaves behind.
	 *
	 * @param string $meta_key The meta key to strip.
	 *
	 * @return int The product id.
	 */
	private function create_product_without_meta( string $meta_key ): int {
		$product = ProductHelper::create_simple_product();
		delete_post_meta( $product->get_id(), $meta_key );
		wp_cache_flush();

		return $product->get_id();
	}

	/**
	 * Create enough title matches to fill the requested search page.
	 *
	 * @param bool $with_skus Whether to give the matches SKUs that sort before "red".
	 */
	private function create_red_title_matches( bool $with_skus = false ): void {
		for ( $i = 0; $i < 25; ++$i ) {
			$args = array( 'name' => 'Red Widget ' . $i );
			if ( $with_skus ) {
				$args['sku'] = 'aaa-' . $i;
			}
			ProductHelper::create_simple_product( $args );
		}
	}

	/**
	 * Row ids, ascending.
	 *
	 * @param array $rows Product rows.
	 *
	 * @return array<int, int>
	 */
	private function sorted_ids( array $rows ): array {
		$ids = wp_list_pluck( $rows, 'id' );
		sort( $ids );

		return $ids;
	}

	/**
	 * The configured barcode value of each row, in row order.
	 *
	 * @param array $rows Product rows.
	 *
	 * @return array<int, null|string>
	 */
	private function barcodes( array $rows ): array {
		$barcodes = array();
		foreach ( $rows as $row ) {
			$meta       = array_column( $row['meta_data'], 'value', 'key' );
			$barcodes[] = $meta['_barcode'] ?? null;
		}

		return $barcodes;
	}
}
