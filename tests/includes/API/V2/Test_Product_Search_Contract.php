<?php
/**
 * Tests for the shared v2 and v1 product search contract.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\Product_Search;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_Query;

/**
 * Server-side twin of the POS client's search fixture catalogue.
 *
 * Source of truth: wcpos/monorepo packages/sync-core/src/searchFixtureCatalogue.ts.
 * Every term must occur in a title, SKU, or barcode field, in any order.
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
			array( 'MY საბარგული Case', 'SKU-3013', '', '', 'instock' ),
			array( 'M3 საბარგული Case', 'SKU-3014', '', '', 'instock' ),
			array( 'Coil 0,4 ohm', 'SKU-3015', '', '', 'instock' ),
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
	 * @param array  $params Query parameters.
	 * @param string $route  Collection route.
	 */
	private function read( array $params = array(), string $route = '/wcpos/v2/products' ): array {
		$request = $this->wp_rest_get_request( $route );
		$request->set_query_params( $params );

		$response = '/wcpos/v1/products' === $route ? $this->dispatch_direct_request( $request ) : $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Isolate persistent v1 hooks as separate HTTP requests would.
	 *
	 * @param \WP_REST_Request $request Collection request.
	 * @return \WP_REST_Response
	 */
	private function dispatch_direct_request( $request ) {
		$snapshot = array();
		foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) {
			$snapshot[ $hook ] = clone $callbacks;
		}
		try {
			return $this->server->dispatch( $request );
		} finally {
			$GLOBALS['wp_filter'] = $snapshot; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore request-scoped hooks in this test process.
		}
	}

	/**
	 * Search traps shared with the client catalogue.
	 *
	 * @return array
	 */
	public function search_traps(): array {
		return array(
			array(
				'over-100-hits',
				'widget',
				array_map(
					static function ( int $id ): string {
						return 'Bulk Widget ' . $id;
					},
					range( 130, 1 )
				),
			),
			array( 'and-across-terms', 'banana berry', array( 'Strawberry Banana Split', 'Banana Berry Smoothie' ) ),
			array( 'reordered-terms', 'berry banana', array( 'Strawberry Banana Split', 'Banana Berry Smoothie' ) ),
			array( 'gapped-terms', 'banana smoothie', array( 'Banana Berry Smoothie' ) ),
			array( 'accent-fold', 'creme', array( 'Crème Brûlée Kit' ) ),
			array( 'unicode-fold', 'skoda', array( 'Škoda Model Car' ) ),
			array( 'compound-substring', 'board', array( 'Skateboard Deck' ) ),
			array( 'exact-sku-first', 'RED-1', array( 'Poster', 'RED-1 Edition Poster' ) ),
			array( 'exact-barcode-first', '5012345678900', array( 'Scanner Test Item', 'Manual for 5012345678900' ) ),
			array( 'short-term-substring', 'k2', array( 'K2 Skis' ) ),
			array( 'short-qualifier-counts', 'MY საბარგული', array( 'MY საბარგული Case' ) ),
			array( 'decimal-comma', '0,4', array( 'Coil 0,4 ohm' ) ),
			array( 'description-never-matches', 'phantom', array() ),
			array( 'out-of-stock-rows-are-searched', 'ghost', array( 'Ghost Pepper Sauce' ) ),
			array( 'stock-status-field-is-not-searched', 'outofstock', array() ),
			array( 'and-across-fields', 'cobalt zinc', array( 'Cobalt Lamp' ) ),
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
		// Arrange: the other fixtures are created in setUp().
		if ( 'over-100-hits' === $name ) {
			foreach ( range( 1, 130 ) as $id ) {
				ProductHelper::create_simple_product(
					array(
						'name' => 'Bulk Widget ' . $id,
						'sku' => 'BULK-' . $id,
						'status' => 'publish',
					)
				);
			}
		}

		foreach ( array( '/wcpos/v2/products', '/wcpos/v1/products' ) as $route ) {
			// Act.
			$rows = array();
			foreach ( range( 1, max( 1, (int) ceil( count( $expected_names ) / 100 ) ) ) as $page ) {
				$rows = array_merge(
					$rows,
					$this->read(
						array(
							'search' => $query,
							'orderby' => 'id',
							'order' => 'desc',
							'status' => 'publish',
							'per_page' => 100,
							'page' => $page,
						),
						$route
					)
				);
			}

			// Assert.
			$this->assertSame( $expected_names, wp_list_pluck( $rows, 'name' ), $name );
		}
	}

	/**
	 * Literal terms survive REST sanitation and WordPress search parsing.
	 *
	 * @dataProvider literal_phrases
	 * @param string $phrase Search phrase.
	 * @param array  $decoys Nonmatching titles.
	 * @param array  $matches Additional matching titles with reordered or separated terms.
	 */
	public function test_product_search_preserves_literal_terms( string $phrase, array $decoys, array $matches = array() ): void {
		// Arrange.
		$wanted = ProductHelper::create_simple_product( array( 'sku' => '' ) );
		wp_update_post(
			wp_slash(
				array(
					'ID' => $wanted->get_id(),
					'post_title' => 'xx' . $phrase . 'xx',
				)
			)
		);
		$this->assertSame( 'xx' . $phrase . 'xx', get_post_field( 'post_title', $wanted->get_id() ) );
		$ids = array( $wanted->get_id() );
		foreach ( $decoys as $title ) {
			$product = ProductHelper::create_simple_product(
				array(
					'name' => $title,
					'sku' => '',
					'global_unique_id' => '',
				)
			);
			$ids[]   = $product->get_id();
		}

		$expected = array( $wanted->get_id() );
		foreach ( $matches as $title ) {
			$product    = ProductHelper::create_simple_product(
				array(
					'name' => $title,
					'sku' => '',
				)
			);
			$ids[]      = $product->get_id();
			$expected[] = $product->get_id();
		}

		foreach ( array( '/wcpos/v2/products', '/wcpos/v1/products' ) as $route ) {
			// Act.
			$rows = $this->read(
				array(
					'search' => '  ' . $phrase . '  ',
					'include' => $ids,
					'orderby' => 'id',
					'order' => 'asc',
				),
				$route
			);

			// Assert.
			$this->assertSame( $expected, wp_list_pluck( $rows, 'id' ) );
		}
	}

	/**
	 * Literal punctuation and short terms are not normalized away.
	 *
	 * @return array
	 */
	public function literal_phrases(): array {
		return array(
			array( 'MY საბარგული', array( 'M3 საბარგული' ), array( 'საბარგული MY', 'MY xxxx საბარგული' ) ),
			array( 'A საბარგული', array( 'M3 საბარგული' ), array( 'საბარგული A' ) ),
			array( 'MY', array( 'M3' ) ),
			array( 'A', array( 'BBB' ) ),
			array( 'IT 5012', array( '5012 only' ), array( '5012 IT' ) ),
			array( 'A B', array( 'A xx', 'B xx' ), array( 'B A', 'A xx B' ) ),
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
			array( 'MY  საბარგული', array( 'M3 საბარგული' ), array( 'MY საბარგული', 'საბარგული MY' ) ),
			array( '東京 コー', array( '東京 別', 'コー 別' ), array( '東京 別 コー', 'コー 東京' ) ),
		);
	}

	/**
	 * Over-long term lists search as one phrase, not independent reordered words.
	 */
	public function test_product_search_collapses_an_over_long_term_list(): void {
		// Arrange.
		$phrase  = 'Amber Birch Cedar Dahlia Elm Fern Grove Hazel Iris Juniper Kelp';
		$product = ProductHelper::create_simple_product(
			array(
				'name'   => $phrase,
				'sku'    => '',
				'status' => 'publish',
			)
		);

		foreach ( array( '/wcpos/v2/products', '/wcpos/v1/products' ) as $route ) {
			// Act.
			$ordered   = $this->read( array( 'search' => $phrase ), $route );
			$reordered = $this->read( array( 'search' => 'Kelp Juniper Iris Hazel Grove Fern Elm Dahlia Cedar Birch Amber' ), $route );

			// Assert.
			$this->assertSame( array( $product->get_id() ), wp_list_pluck( $ordered, 'id' ) );
			$this->assertSame( array(), $reordered );
		}
	}

	/**
	 * Malformed UTF-8 must not turn a constrained search into the whole catalogue.
	 */
	public function test_product_search_rejects_malformed_utf8(): void {
		// Arrange.
		$query = new WP_Query();
		$query->query_vars['wcpos_search_phrase'] = "bad\xFF";

		// Act.
		$search = Product_Search::posts_search( '', $query );

		// Assert.
		$this->assertSame( ' AND 1=0 ', $search );

		// Act / Assert: neither REST lane may expose rows for malformed input.
		foreach ( array( '/wcpos/v2/products', '/wcpos/v1/products' ) as $route ) {
			$this->assertSame( array(), $this->read( array( 'search' => "bad\xFF" ), $route ), $route );
		}
	}

	/**
	 * Term filtering happens before totals and stable multi-page selection.
	 */
	public function test_term_search_pages_count_matches_once(): void {
		// Arrange.
		$ids = array();
		foreach ( range( 1, 3 ) as $index ) {
			$product = ProductHelper::create_simple_product( array( 'name' => 'Paged Phrase' ) );
			add_post_meta( $product->get_id(), '_sku', 'Paged Phrase' );
			add_post_meta( $product->get_id(), '_sku', 'Paged Phrase' );
			$ids[] = $product->get_id();
		}
		$ids[] = ProductHelper::create_simple_product( array( 'name' => 'Paged Other Phrase' ) )->get_id();
		$ids[] = ProductHelper::create_simple_product( array( 'name' => 'Phrase Paged' ) )->get_id();
		$other = ProductHelper::create_simple_product( array( 'name' => 'Paged Other' ) );
		$other->set_sku( '' );
		$other->set_global_unique_id( '' );
		$other->save();

		// Act / Assert.
		$served = array();
		foreach ( array( 1, 2, 3 ) as $page ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
			$request->set_query_params(
				array(
					'search' => 'Paged Phrase',
					'orderby' => 'id',
					'order' => 'asc',
					'per_page' => 2,
					'page' => $page,
				)
			);
			$response = $this->server->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 5, $response->get_headers()['X-WP-Total'] );
			$this->assertSame( 3, $response->get_headers()['X-WP-TotalPages'] );
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
