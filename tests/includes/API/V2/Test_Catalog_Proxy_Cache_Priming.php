<?php
/**
 * Tests for bulk cache priming on v2 product and variation reads.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Cache priming prevents postmeta query-per-product reads.
 */
class Test_Catalog_Proxy_Cache_Priming extends WCPOS_REST_Unit_Test_Case {
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
	 * Product list hydration reads postmeta in bulk.
	 */
	public function test_products_list_bulk_primes_postmeta_cache(): void {
		$product_ids = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$product_ids[] = ProductHelper::create_simple_product()->get_id();
		}
		wp_cache_flush();

		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_query_params(
			array(
				'include'  => $product_ids,
				'per_page' => 8,
			)
		);
		$queries       = array();
		$capture_query = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};

		add_filter( 'query', $capture_query );
		$response = $this->server->dispatch( $request );
		remove_filter( 'query', $capture_query );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 8, $response->get_data() );
		// Un-primed hydration pays update_meta_cache() per product — a
		// single-id `post_id IN (n)` postmeta SELECT each. On this lane WP
		// core's WP_Query cached-ids path bulk-primes (WP >= 6.1), so this is
		// a regression guard for the lane, whoever does the priming. WC's
		// read_meta() SELECTs bypass the WP meta cache; out of scope here.
		$this->assertSame( 0, \count( $this->single_id_meta_cache_reads( $queries ) ), implode( "\n", $queries ) );
		$this->assertGreaterThanOrEqual( 1, \count( $this->bulk_meta_cache_reads( $queries ) ), implode( "\n", $queries ) );
	}

	/**
	 * Variation list hydration reads postmeta in bulk.
	 */
	public function test_variations_list_bulk_primes_postmeta_cache(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		for ( $i = 0; $i < 2; $i++ ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_regular_price( '10' );
			$variation->save();
			$variation_ids[] = $variation->get_id();
		}
		wp_cache_flush();

		$request = $this->wp_rest_get_request( '/wcpos/v2/variations' );
		$request->set_query_params( array( 'include' => $variation_ids ) );
		$queries       = array();
		$capture_query = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;
			return $query;
		};

		add_filter( 'query', $capture_query );
		$response = $this->server->dispatch( $request );
		remove_filter( 'query', $capture_query );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertCount( 4, $response->get_data()['documents'] );
		// The shared PARENT product legitimately hydrates once per request
		// (O(1), not per-variation) — only per-VARIATION single-id reads are
		// the N+1 signature this change eliminates.
		foreach ( $this->single_id_meta_cache_reads( $queries ) as $read ) {
			preg_match( '/post_id IN \(\s*(\d+)\s*\)/i', $read, $matches );
			$this->assertNotContains( (int) $matches[1], array_map( 'intval', $variation_ids ), $read );
		}
		$this->assertGreaterThanOrEqual( 1, \count( $this->bulk_meta_cache_reads( $queries ) ), implode( "\n", $queries ) );
	}

	/**
	 * Single-post update_meta_cache() reads — the per-object N+1
	 * signature that bulk priming exists to eliminate.
	 *
	 * @param string[] $queries Captured SQL.
	 * @return string[] Matching queries.
	 */
	private function single_id_meta_cache_reads( array $queries ): array {
		return array_values( preg_grep( '/SELECT post_id, meta_key, meta_value FROM \w*postmeta WHERE post_id IN \(\s*\d+\s*\)/i', $queries ) );
	}

	/**
	 * Multi-post update_meta_cache() reads — proof a bulk prime actually
	 * ran (guards the silent-no-op failure mode where nothing primes and
	 * nothing hydrates meta at all).
	 *
	 * @param string[] $queries Captured SQL.
	 * @return string[] Matching queries.
	 */
	private function bulk_meta_cache_reads( array $queries ): array {
		return array_values( preg_grep( '/SELECT post_id, meta_key, meta_value FROM \w*postmeta WHERE post_id IN \(\s*\d+\s*,/i', $queries ) );
	}
}
