<?php
/**
 * Tests for the v2 product search contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Server-side twin of the POS client's search fixture catalogue.
 *
 * Source of truth: wcpos/monorepo packages/sync-core/src/searchFixtureCatalogue.ts.
 * The complete phrase must occur within one title, SKU, or barcode field.
 * Exact SKU/barcode matches rank first, then id descending; descriptions never match.
 */
class Test_Product_Search_Contract extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Enable the sync read lane and create fixtures in ascending id order.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->install_sync_read_lane();

		$fixtures = array(
			array( 'Banana Berry Smoothie', 'SKU-2001', '', '', 'instock' ),
			array( 'Banana Bread', 'SKU-2002', '', '', 'instock' ),
			array( 'Berry Tart', 'SKU-2003', '', '', 'instock' ),
			array( 'Strawberry Banana Split', 'SKU-2004', '', '', 'instock' ),
			array( 'Crème Brûlée Kit', 'SKU-3001', '', '', 'instock' ),
			array( 'Škoda Model Car', 'SKU-3002', '', '', 'instock' ),
			array( 'Skateboard Deck', 'SKU-3003', '', '', 'instock' ),
			array( 'Poster', 'RED-1', '', '', 'instock' ),
			array( 'RED-1 Edition Poster', 'SKU-3005', '', '', 'instock' ),
			array( 'Scanner Test Item', 'SKU-3006', '5012345678900', '', 'instock' ),
			array( 'Manual for 5012345678900', 'SKU-3007', '', '', 'instock' ),
			array( 'K2 Skis', 'SKU-3008', '', '', 'instock' ),
			array( 'Plain Mug', 'SKU-3009', '', 'A phantom word lives only in the description.', 'instock' ),
			array( 'Ghost Pepper Sauce', 'SKU-3010', '', '', 'outofstock' ),
			array( 'Cobalt Lamp', 'ZINC-77', '', '', 'instock' ),
		);

		foreach ( $fixtures as $fixture ) {
			ProductHelper::create_simple_product(
				array(
					'name'             => $fixture[0],
					'sku'              => $fixture[1],
					'global_unique_id' => $fixture[2],
					'description'      => $fixture[3],
					'stock_status'     => $fixture[4],
					'status'           => 'publish',
				)
			);
		}
	}

	/**
	 * Remove sync state written outside the test transaction.
	 */
	public function tearDown(): void {
		parent::tearDown();
		$this->uninstall_sync_read_lane();
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
	 * Search traps shared with the client catalogue.
	 *
	 * @return array
	 */
	public function search_traps(): array {
		return array(
			array( 'complete-phrase', 'banana berry', array( 'Banana Berry Smoothie' ) ),
			array( 'accent-fold', 'creme', array( 'Crème Brûlée Kit' ) ),
			array( 'unicode-fold', 'skoda', array( 'Škoda Model Car' ) ),
			array( 'compound-substring', 'board', array( 'Skateboard Deck' ) ),
			array( 'exact-sku-first', 'RED-1', array( 'Poster', 'RED-1 Edition Poster' ) ),
			array( 'exact-barcode-first', '5012345678900', array( 'Scanner Test Item', 'Manual for 5012345678900' ) ),
			array( 'short-term', 'k2', array( 'K2 Skis' ) ),
			array( 'description-never-matches', 'phantom', array() ),
			array( 'out-of-stock-rows-are-searched', 'ghost', array( 'Ghost Pepper Sauce' ) ),
			array( 'stock-status-field-is-not-searched', 'outofstock', array() ),
			array( 'no-cross-field-phrase', 'cobalt zinc', array() ),
			array( 'no-match', 'zzqx', array() ),
		);
	}

	/**
	 * Match the client catalogue's expected names in exact-rank/newest-first order.
	 *
	 * @dataProvider search_traps
	 * @param string $name           Trap name.
	 * @param string $query          Search terms.
	 * @param array  $expected_names Expected product names in order.
	 */
	public function test_product_search_trap_returns_expected_ranked_names( string $name, string $query, array $expected_names ): void {
		$rows = $this->read(
			array(
				'search'  => $query,
				'orderby' => 'id',
				'order'   => 'desc',
				'status'  => 'publish',
			)
		);

		$this->assertSame( $expected_names, wp_list_pluck( $rows, 'name' ), $name );
	}

	/**
	 * Literal phrases survive REST sanitation and WordPress search parsing.
	 *
	 * @dataProvider literal_phrases
	 * @param string $phrase Search phrase.
	 * @param array  $decoys Nonmatching titles.
	 */
	public function test_phrase_search_matches_only_complete_literal_title( string $phrase, array $decoys ): void {
		$wanted = ProductHelper::create_simple_product( array( 'sku' => '' ) );
		wp_update_post( wp_slash( array( 'ID' => $wanted->get_id(), 'post_title' => 'xx' . $phrase . 'xx' ) ) );
		$this->assertSame( 'xx' . $phrase . 'xx', get_post_field( 'post_title', $wanted->get_id() ) );
		$ids = array( $wanted->get_id() );
		foreach ( $decoys as $title ) {
			$product = ProductHelper::create_simple_product( array( 'name' => $title, 'sku' => '' ) );
			$ids[]   = $product->get_id();
		}

		$rows = $this->read( array( 'search' => '  ' . $phrase . '  ', 'include' => $ids ) );

		$this->assertSame( array( $wanted->get_id() ), wp_list_pluck( $rows, 'id' ) );
	}

	/**
	 * Literal transport and per-field phrase cases.
	 *
	 * @return array
	 */
	public function literal_phrases(): array {
		return array(
			array( 'MY საბარგული', array( 'M3 საბარგული', 'საბარგული MY', 'MY xxxx საბარგული' ) ),
			array( 'A საბარგული', array( 'M3 საბარგული', 'საბარგული A' ) ),
			array( 'MY', array( 'M3' ) ),
			array( 'A', array( 'BBB' ) ),
			array( 'A B', array( 'B A', 'A xx B' ) ),
			array( '0', array( 'BBB' ) ),
			array( '0.4', array( '0 4', '4.0' ) ),
			array( 'red-shirt', array( 'red shirt' ) ),
			array( 'MY+საბარგული', array( 'MY საბარგული', 'MY,საბარგული' ) ),
			array( '100%', array( '1000' ) ),
			array( '%30', array( '30', '0' ) ),
			array( 'MY_code', array( 'MYXcode' ) ),
			array( 'MY\\code', array( 'MYcode' ) ),
			array( 'MY"code', array( 'MYcode', 'MY code' ) ),
			array( "MY'code", array( 'MYcode' ) ),
			array( 'MY  საბარგული', array( 'MY საბარგული' ) ),
			array( '東京 コー', array( '東京 別 コー' ) ),
		);
	}

	/**
	 * Phrase filtering happens before totals and stable multi-page selection.
	 */
	public function test_phrase_search_pages_count_matches_once(): void {
		$ids = array();
		foreach ( range( 1, 3 ) as $index ) {
			$product = ProductHelper::create_simple_product( array( 'name' => 'Paged Phrase' ) );
			add_post_meta( $product->get_id(), '_sku', 'Paged Phrase' );
			add_post_meta( $product->get_id(), '_sku', 'Paged Phrase' );
			$ids[] = $product->get_id();
		}
		ProductHelper::create_simple_product( array( 'name' => 'Paged Other Phrase' ) );
		ProductHelper::create_simple_product( array( 'name' => 'Phrase Paged' ) );
		$served = array();
		foreach ( array( 1, 2 ) as $page ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
			$request->set_query_params( array( 'search' => 'Paged Phrase', 'orderby' => 'title', 'order' => 'desc', 'per_page' => 2, 'page' => $page ) );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 3, $response->get_headers()['X-WP-Total'] );
			$this->assertSame( 2, $response->get_headers()['X-WP-TotalPages'] );
			$served = array_merge( $served, wp_list_pluck( $response->get_data(), 'id' ) );
		}
		$this->assertSame( $ids, $served );
	}

	/**
	 * Search pagination serves hits beyond the first 100 and reports the full total.
	 */
	public function test_over_100_hits_page_past_the_first_is_served(): void {
		for ( $n = 1; $n <= 130; ++$n ) {
			ProductHelper::create_simple_product(
				array(
					'name'   => 'Bulk Widget ' . $n,
					'sku'    => 'BULK-' . $n,
					'status' => 'publish',
				)
			);
		}

		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_query_params(
			array(
				'search'   => 'widget',
				'per_page' => 100,
				'page'     => 2,
				'orderby'  => 'id',
				'order'    => 'desc',
				'status'   => 'publish',
			)
		);

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 30, $response->get_data() );
		$headers = $response->get_headers();
		$this->assertSame( 130, $headers['X-WP-Total'] );
	}
}
