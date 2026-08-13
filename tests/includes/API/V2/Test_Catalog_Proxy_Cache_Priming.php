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
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
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
	 * Variable products on the list lane bulk-prime their children: the
	 * price-range augmentation (Variable_Price_Range) reads every visible
	 * child variation, and without a bulk prime each wc_get_product() pays
	 * its own single-id posts + postmeta pair.
	 */
	public function test_variable_products_list_bulk_primes_variation_caches(): void {
		// The augmentation pipeline (which carries Variable_Prices) installs
		// only when Init sees a healthy sync schema — never in the test
		// bootstrap. Install it here; WP_UnitTestCase restores hooks per test.
		Augmentation_Pipeline::install();

		$parent_ids    = array();
		$variation_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$product         = ProductHelper::create_variation_product();
			$parent_ids[]    = $product->get_id();
			$variation_ids   = array_merge( $variation_ids, array_map( 'intval', $product->get_children() ) );
			// Warm the transients a live store holds in steady state: the
			// visible-children half of wc_product_children (so no priming
			// children query runs), and wc_var_prices (so WC core's
			// read_price_data skips its own _prime_post_caches of the
			// family). With both warm, the ONLY thing standing between the
			// price-range augmentation and a per-variation N+1 is the bulk
			// prime under test — the exact state behind the dev-next
			// 653-singles slow-log signature.
			$product->get_visible_children();
			$product->get_variation_prices();
		}
		// Reproduce a host WITHOUT a persistent object cache but WITH warm
		// wc_product_children transients (the dev-next reality that produced
		// the 653-singles slow-log signature): a full wp_cache_flush() would
		// also drop that transient — it lives in the object cache under the
		// test suite — making WooCommerce re-query children via WP_Query,
		// whose cached-ids path bulk-primes them and hides the N+1. So keep
		// the transient warm and evict only the variation rows themselves.
		foreach ( $variation_ids as $vid ) {
			wp_cache_delete( $vid, 'posts' );
			wp_cache_delete( $vid, 'post_meta' );
		}

		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_query_params(
			array(
				'include'  => $parent_ids,
				'per_page' => 3,
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
		$this->assertCount( 3, $response->get_data() );
		// Parents are primed by the list query itself; only per-VARIATION
		// single-id reads are the N+1 signature this guards against.
		foreach ( $this->single_id_meta_cache_reads( $queries ) as $read ) {
			preg_match( '/post_id IN \(\s*(\d+)\s*\)/i', $read, $matches );
			$this->assertNotContains( (int) $matches[1], $variation_ids, $read );
		}
		$this->assertGreaterThanOrEqual( 1, \count( $this->bulk_meta_cache_reads( $queries ) ), implode( "\n", $queries ) );
		foreach ( $this->single_id_post_cache_reads( $queries ) as $read ) {
			preg_match( '/ID IN \(\s*(\d+)\s*\)/i', $read, $matches );
			$this->assertNotContains( (int) $matches[1], $variation_ids, $read );
		}
		$this->assertGreaterThanOrEqual( 1, \count( $this->bulk_post_cache_reads( $queries ) ), implode( "\n", $queries ) );
	}

	/**
	 * Single-post _prime_post_caches() reads.
	 *
	 * @param string[] $queries Captured SQL.
	 * @return string[] Matching queries.
	 */
	private function single_id_post_cache_reads( array $queries ): array {
		return array_values( preg_grep( '/SELECT \w*posts\.\* FROM \w*posts WHERE ID IN \(\s*\d+\s*\)/i', $queries ) );
	}

	/**
	 * Multi-post _prime_post_caches() reads.
	 *
	 * @param string[] $queries Captured SQL.
	 * @return string[] Matching queries.
	 */
	private function bulk_post_cache_reads( array $queries ): array {
		return array_values( preg_grep( '/SELECT \w*posts\.\* FROM \w*posts WHERE ID IN \(\s*\d+\s*,/i', $queries ) );
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
