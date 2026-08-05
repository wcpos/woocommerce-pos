<?php
/**
 * Tests for variation search on the v2 variations endpoint.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
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
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Remove options written outside the per-test transaction.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Pos_Visibility::OPTION );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
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
			'page'              => array( array( 'search' => 'boundary', 'page' => 1000, 'per_page' => 100 ) ),
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
			'page'              => array( array( 'search' => 'boundary', 'page' => 1001, 'per_page' => 100 ) ),
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
