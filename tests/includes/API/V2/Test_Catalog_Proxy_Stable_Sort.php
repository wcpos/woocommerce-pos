<?php
/**
 * Tests for the v2 catalog proxy stable-sort tiebreaks.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Tie-prone sorts must carry a deterministic id tiebreak (mono#1372).
 *
 * ORDER BY a tied column alone gives MySQL no total order across separate
 * offset queries, so a tie at a page boundary can appear on two pages or on
 * neither while the POS client walks a multi-page window. The client renders
 * ties in ascending id order, so the wire must too.
 */
class Test_Catalog_Proxy_Stable_Sort extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable v2 routes before REST initialization and authenticate a cashier.
	 */
	public function setUp(): void {
		Proxy_Uuid_Stamper::register_proxy_stampers();
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'cashier' ) ) );
	}

	/** Remove sync state written outside the test transaction. */
	public function tearDown(): void {
		parent::tearDown();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
	}

	/**
	 * Dispatch a proxy collection read.
	 *
	 * @param string $route  Route below /wcpos/v2.
	 * @param array  $params Query parameters.
	 */
	private function read( string $route, array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2' . $route );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * Products tied on title list in ascending id order, whatever the direction.
	 */
	public function test_tied_product_titles_resolve_by_ascending_id(): void {
		$ids = array();
		foreach ( array( 3, 1, 2 ) as $suffix ) {
			$product = new \WC_Product_Simple();
			$product->set_name( 'Gift Card' );
			$product->set_regular_price( '10.00' );
			$product->save();
			$ids[ $suffix ] = $product->get_id();
		}
		sort( $ids );

		foreach ( array( 'asc', 'desc' ) as $order ) {
			$rows = $this->read(
				'/products',
				array(
					'orderby'  => 'title',
					'order'    => $order,
					'per_page' => 10,
				)
			);
			$tied = array_values(
				array_filter(
					array_map( 'intval', array_column( $rows, 'id' ) ),
					static fn ( int $id ): bool => \in_array( $id, $ids, true )
				)
			);
			$this->assertSame( array_values( $ids ), $tied, "tied titles must list id-ascending ({$order})" );
		}
	}

	/**
	 * A tie straddling a page boundary appears exactly once across the walk.
	 */
	public function test_title_walk_pages_partition_tied_rows(): void {
		$ids = array();
		for ( $index = 0; $index < 4; $index++ ) {
			$product = new \WC_Product_Simple();
			$product->set_name( 'Duplicate Title' );
			$product->set_regular_price( '10.00' );
			$product->save();
			$ids[] = $product->get_id();
		}
		sort( $ids );

		$walked = array();
		for ( $page = 1; $page <= 2; $page++ ) {
			$rows   = $this->read(
				'/products',
				array(
					'orderby'  => 'title',
					'order'    => 'asc',
					'per_page' => 2,
					'page'     => $page,
				)
			);
			$walked = array_merge( $walked, array_map( 'intval', array_column( $rows, 'id' ) ) );
		}

		$tied = array_values( array_intersect( $walked, $ids ) );
		$this->assertSame( $ids, $tied, 'each tied row appears exactly once, in id order, across pages' );
	}

	/**
	 * Same-named categories under different parents list in ascending term_id order.
	 */
	public function test_tied_category_names_resolve_by_ascending_term_id(): void {
		$first_parent  = wp_insert_term( 'Parent Alpha', 'product_cat' );
		$second_parent = wp_insert_term( 'Parent Beta', 'product_cat' );
		$first_child   = wp_insert_term(
			'Duplicate Child',
			'product_cat',
			array( 'parent' => (int) $first_parent['term_id'] )
		);
		$second_child  = wp_insert_term(
			'Duplicate Child',
			'product_cat',
			array( 'parent' => (int) $second_parent['term_id'] )
		);
		$tied_ids      = array( (int) $first_child['term_id'], (int) $second_child['term_id'] );
		sort( $tied_ids );

		foreach ( array( 'asc', 'desc' ) as $order ) {
			$rows = $this->read(
				'/products/categories',
				array(
					'orderby'  => 'name',
					'order'    => $order,
					'per_page' => 20,
				)
			);
			$tied = array_values(
				array_filter(
					array_map( 'intval', array_column( $rows, 'id' ) ),
					static fn ( int $id ): bool => \in_array( $id, $tied_ids, true )
				)
			);
			$this->assertSame( $tied_ids, $tied, "tied names must list term_id-ascending ({$order})" );
		}
	}

	/**
	 * Coupons tied on creation date list in ascending id order.
	 */
	public function test_tied_coupon_dates_resolve_by_ascending_id(): void {
		$stamp = '2026-01-02 03:04:05';
		$ids   = array();
		foreach ( array( 'zzz-tied', 'aaa-tied' ) as $code ) {
			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->save();
			wp_update_post(
				array(
					'ID'            => $coupon->get_id(),
					'post_date'     => $stamp,
					'post_date_gmt' => $stamp,
					'edit_date'     => true,
				)
			);
			// The tie premise must hold or the assertion below tests nothing.
			$this->assertSame( $stamp, get_post( $coupon->get_id() )->post_date );
			$ids[] = $coupon->get_id();
		}
		sort( $ids );

		$rows = $this->read(
			'/coupons',
			array(
				'orderby'  => 'date',
				'order'    => 'desc',
				'per_page' => 10,
			)
		);
		$tied = array_values(
			array_filter(
				array_map( 'intval', array_column( $rows, 'id' ) ),
				static fn ( int $id ): bool => \in_array( $id, $ids, true )
			)
		);
		$this->assertSame( $ids, $tied, 'tied dates must list id-ascending even under desc' );
	}
}
