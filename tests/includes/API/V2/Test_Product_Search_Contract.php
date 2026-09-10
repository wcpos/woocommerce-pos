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
 * Terms are ANDed across title, SKU, and barcode substring matches (OR fields).
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
			array( 'and-across-terms', 'banana berry', array( 'Strawberry Banana Split', 'Banana Berry Smoothie' ) ),
			array( 'accent-fold', 'creme', array( 'Crème Brûlée Kit' ) ),
			array( 'unicode-fold', 'skoda', array( 'Škoda Model Car' ) ),
			array( 'compound-substring', 'board', array( 'Skateboard Deck' ) ),
			array( 'exact-sku-first', 'RED-1', array( 'Poster', 'RED-1 Edition Poster' ) ),
			array( 'exact-barcode-first', '5012345678900', array( 'Scanner Test Item', 'Manual for 5012345678900' ) ),
			array( 'short-term', 'k2', array( 'K2 Skis' ) ),
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
		$this->assertEquals( 130, $headers['X-WP-Total'] );
	}
}
