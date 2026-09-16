<?php
/**
 * Search and visibility Collection Rules across read lanes.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

class Test_Collection_Rules_Search_Parity extends WCPOS_REST_Unit_Test_Case {
	private $barcode_settings;

	public function setUp(): void {
		$this->barcode_settings = static function ( $settings ) {
			$settings['barcode_field'] = '_rank_barcode';
			return $settings;
		};
		add_filter( 'woocommerce_pos_general_settings', $this->barcode_settings );
		parent::setUp();
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_pos_general_settings', $this->barcode_settings );
		parent::tearDown();
		$this->uninstall_sync_read_lane();
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Pos_Visibility::OPTION );
	}

	/** Exact SKU and configured barcode matches rank first on both product lanes. */
	public function test_product_search_both_lanes_return_same_ranked_ids(): void {
		// Arrange.
		$this->install_sync_read_lane();
		$exact   = ProductHelper::create_simple_product( array( 'name' => 'Plain poster', 'sku' => 'RANK-PROBE' ) );
		$partial = ProductHelper::create_simple_product( array( 'name' => 'RANK-PROBE edition', 'sku' => 'OTHER-PROBE' ) );
		$exact->update_meta_data( '_rank_barcode', 'BARCODE-PROBE' );
		$exact->save();
		$partial->update_meta_data( '_rank_barcode', 'BARCODE-PROBE-extra' );
		$partial->save();
		foreach ( array( 'RANK-PROBE', 'BARCODE-PROBE' ) as $phrase ) {
			// Act: isolate v1's persistent hooks, as separate HTTP requests would.
			$lanes = array();
			foreach ( array( '/wcpos/v1/products', '/wcpos/v2/products' ) as $route ) {
				$snapshot = array();
				foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) {
					$snapshot[ $hook ] = clone $callbacks;
				}
				try {
					$request = $this->wp_rest_get_request( $route );
					$request->set_query_params( array( 'search' => $phrase, 'orderby' => 'id', 'order' => 'desc' ) );
					$response = $this->server->dispatch( $request );
					$this->assertSame( 200, $response->get_status() );
					$lanes[] = wp_list_pluck( $response->get_data(), 'id' );
				} finally {
					$GLOBALS['wp_filter'] = $snapshot;
				}
			}
			// Assert.
			$this->assertSame( array( $exact->get_id(), $partial->get_id() ), $lanes[0] );
			$this->assertSame( $lanes[0], $lanes[1] );
		}
	}

	/** Plain words AND across title/meta, independently of their order. */
	public function test_product_search_two_words_returns_same_ordered_ids(): void {
		// Arrange.
		$this->install_sync_read_lane();
		$phrase   = ProductHelper::create_simple_product( array( 'name' => 'blue shirt', 'sku' => 'phrase-probe' ) );
		$reversed = ProductHelper::create_simple_product( array( 'name' => 'shirt blue', 'sku' => 'reversed-probe' ) );
		$across   = ProductHelper::create_simple_product( array( 'name' => 'blue', 'sku' => 'shirt-cross-probe' ) );
		ProductHelper::create_simple_product( array( 'name' => 'blue trousers', 'sku' => 'blue-only-probe' ) );
		ProductHelper::create_simple_product( array( 'name' => 'red shirt', 'sku' => 'shirt-only-probe' ) );
		$expected = array( $across->get_id(), $reversed->get_id(), $phrase->get_id() );
		$lanes    = array();

		// Act: isolate persistent v1 hooks as separate HTTP requests would.
		foreach ( array( '/wcpos/v1/products', '/wcpos/v2/products' ) as $route ) {
			$snapshot = array();
			foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) {
				$snapshot[ $hook ] = clone $callbacks;
			}
			try {
				$request = $this->wp_rest_get_request( $route );
				$request->set_query_params( array( 'search' => 'blue shirt', 'orderby' => 'id', 'order' => 'desc' ) );
				$response = $this->server->dispatch( $request );
				$this->assertSame( 200, $response->get_status() );
				$lanes[] = wp_list_pluck( $response->get_data(), 'id' );
			} finally {
				$GLOBALS['wp_filter'] = $snapshot;
			}
		}

		// Assert.
		$this->assertSame( $expected, $lanes[0] );
		$this->assertSame( $expected, $lanes[1] );
	}

	/** Direct variations collapse over-cap phrases; the flat v2 lane still rejects them. */
	public function test_variation_search_over_cap_keeps_each_lanes_policy(): void {
		// Arrange.
		$this->install_sync_read_lane();
		$parent   = ProductHelper::create_variation_product();
		$children = $parent->get_children();
		$target   = wc_get_product( $children[0] );
		$other    = wc_get_product( $children[1] );
		$phrase   = 'amber birch cedar dahlia elm fern grove hazel iris juniper kelp';
		$target->set_sku( $phrase );
		$target->save();
		$other->set_sku( 'kelp juniper iris hazel grove fern elm dahlia cedar birch amber' );
		$other->save();

		foreach ( array( '/wcpos/v1/products/variations', '/wcpos/v1/products/' . $parent->get_id() . '/variations', '/wcpos/v2/variations' ) as $route ) {
			$snapshot = array();
			foreach ( $GLOBALS['wp_filter'] as $hook => $callbacks ) {
				$snapshot[ $hook ] = clone $callbacks;
			}
			try {
				// Act.
				$request = $this->wp_rest_get_request( $route );
				$request->set_query_params( array( 'search' => $phrase ) );
				$response = $this->server->dispatch( $request );

				// Assert.
				if ( '/wcpos/v2/variations' === $route ) {
					$this->assertSame( 400, $response->get_status() );
					$this->assertSame( 'woocommerce_pos_variations_search_limit_exceeded', $response->get_data()['code'] );
				} else {
					$this->assertSame( 200, $response->get_status() );
					$this->assertSame( array( $target->get_id() ), wp_list_pluck( $response->get_data(), 'id' ) );
				}
			} finally {
				$GLOBALS['wp_filter'] = $snapshot;
			}
		}
	}

	/** A late include cannot undo the variation visibility exclusion. */
	public function test_variation_visibility_late_include_keeps_hidden_id_excluded(): void {
		// Arrange.
		$this->install_sync_read_lane();
		$parent = ProductHelper::create_variation_product();
		$hidden = current( $parent->get_children() );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option( Pos_Visibility::OPTION, array( 'variations' => array( 'default' => array( 'online_only' => array( 'ids' => array( $hidden ) ) ) ) ) );
		$late_include = static function ( $query ) use ( $hidden ) {
			if ( 'product_variation' === $query->get( 'post_type' ) ) {
				$query->set( 'post__in', array( $hidden ) );
			}
		};
		add_action( 'pre_get_posts', $late_include, 99 );
		try {
			$request = $this->wp_rest_get_request( '/wcpos/v2/variations' );
			$request->set_query_params( array( 'include' => array( $hidden ) ) );
			// Act.
			$response = $this->server->dispatch( $request );
			// Assert.
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( array(), $response->get_data() );
		} finally {
			remove_action( 'pre_get_posts', $late_include, 99 );
		}
	}
}
