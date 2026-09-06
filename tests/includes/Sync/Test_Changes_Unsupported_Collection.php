<?php
/**
 * The /changes/* lanes refuse an unsupported collection instead of serving products under its name.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use WCPOS\WooCommercePOS\Sync\Api;

/**
 * Pins the fail-closed rule from free#1740 (survey D2/D2b): the hash lanes serve
 * `products` and `tax_rates`, the sequence-log stream serves `all`, `products` and
 * `tax_rates`, and everything else is a 400 — never a silent products substitution.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Changes_Controller
 */
class Test_Changes_Unsupported_Collection extends Sync_REST_Store_Test_Case {
	private const UNSUPPORTED = 'woocommerce_pos_sync_unsupported_collection';

	/**
	 * Dispatch a /changes/* route with a collection and return the response.
	 *
	 * @param string $route      Route under /changes/.
	 * @param string $collection Requested collection.
	 */
	private function changes( string $route, string $collection ): \WP_REST_Response {
		$request = $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/changes/' . $route );
		$request->set_query_params( array( 'collection' => $collection ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * The body `code`, if any.
	 *
	 * @param \WP_REST_Response $response The dispatched response.
	 */
	private function code( \WP_REST_Response $response ): ?string {
		$data = $response->get_data();
		return \is_array( $data ) ? ( $data['code'] ?? null ) : null;
	}

	/**
	 * Every route × every value that names a collection the lane does not serve.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function unsupported_collections(): array {
		$cases = array();
		foreach ( array( 'revision-hash', 'range-checksum', 'sequence-log' ) as $route ) {
			foreach ( array( 'coupons', 'customers', 'orders', 'variations', 'nonsense' ) as $collection ) {
				$cases[ $route . ' / ' . $collection ] = array( $route, $collection );
			}
		}
		$cases['revision-hash / all']  = array( 'revision-hash', 'all' );
		$cases['range-checksum / all'] = array( 'range-checksum', 'all' );
		return $cases;
	}

	/**
	 * An unsupported collection is a 400 that names it, before any row is served.
	 *
	 * @dataProvider unsupported_collections
	 *
	 * @param string $route      Route under /changes/.
	 * @param string $collection Requested collection.
	 */
	public function test_changes_lane_refuses_unsupported_collection_with_400( string $route, string $collection ): void {
		// Act.
		$response = $this->changes( $route, $collection );
		$data     = $response->get_data();
		// Assert.
		$this->assertSame( 400, $response->get_status(), $route . ' / ' . $collection );
		$this->assertSame( self::UNSUPPORTED, $this->code( $response ), $route . ' / ' . $collection );
		$this->assertStringContainsString( $collection, (string) ( $data['message'] ?? '' ) );
		$this->assertSame( $collection, $data['data']['collection'] ?? null );
		$this->assertArrayNotHasKey( 'changes', $data );
		$this->assertArrayNotHasKey( 'buckets', $data );
	}

	/**
	 * The values each lane documents are still served (or fail for their own reasons,
	 * never as an unsupported collection).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function supported_collections(): array {
		return array(
			'revision-hash / products'   => array( 'revision-hash', 'products' ),
			'revision-hash / tax_rates'  => array( 'revision-hash', 'tax_rates' ),
			'range-checksum / products'  => array( 'range-checksum', 'products' ),
			'range-checksum / tax_rates' => array( 'range-checksum', 'tax_rates' ),
			'sequence-log / all'         => array( 'sequence-log', 'all' ),
			'sequence-log / products'    => array( 'sequence-log', 'products' ),
			'sequence-log / tax_rates'   => array( 'sequence-log', 'tax_rates' ),
		);
	}

	/**
	 * A documented collection is served with 200 and never refused as unsupported.
	 *
	 * @dataProvider supported_collections
	 *
	 * @param string $route      Route under /changes/.
	 * @param string $collection Requested collection.
	 */
	public function test_changes_lane_serves_its_documented_collections( string $route, string $collection ): void {
		// Act.
		$response = $this->changes( $route, $collection );
		// Assert.
		$this->assertNotSame( 'rest_no_route', $this->code( $response ), $route . ' is not a registered route' );
		$this->assertNotSame( self::UNSUPPORTED, $this->code( $response ), $route . ' / ' . $collection );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/** A missing collection keeps the documented `products` default on every lane. */
	public function test_changes_lane_defaults_a_missing_collection_to_products(): void {
		foreach ( array( 'revision-hash', 'range-checksum', 'sequence-log' ) as $route ) {
			// Act.
			$response = $this->server->dispatch( $this->wp_rest_get_request( '/' . Api::ROUTE_NAMESPACE . '/changes/' . $route ) );
			// Assert.
			$this->assertSame( 200, $response->get_status(), $route );
			$this->assertSame( 'products', $response->get_data()['collection'] ?? null, $route );
		}
	}
}
