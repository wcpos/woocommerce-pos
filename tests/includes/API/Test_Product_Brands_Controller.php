<?php
/**
 * Test_Product_Brands_Controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\API\Product_Brands_Controller;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_Product_Brands_Controller extends WCPOS_REST_Unit_Test_Case {
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( Product_Brands_Controller::class ) ) {
			$this->markTestSkipped( 'Product brands REST controller is not available in this WooCommerce version.' );
		}

		if ( ! taxonomy_exists( 'product_brand' ) ) {
			$this->markTestSkipped( 'Product brand taxonomy is not available in this WooCommerce version.' );
		}

		$this->endpoint = new Product_Brands_Controller();
	}

	public function test_product_brand_api_get_all_ids(): void {
		$brand1 = wp_insert_term( 'Fast Path Brand One', 'product_brand' );
		$brand2 = wp_insert_term( 'Fast Path Brand Two', 'product_brand' );
		$request = $this->wp_rest_get_request( '/wcpos/v1/products/brands' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertContains( $brand1['term_id'], $ids );
		$this->assertContains( $brand2['term_id'], $ids );
	}

	public function test_product_brand_api_get_all_ids_with_include_filter(): void {
		$brand1 = wp_insert_term( 'Fast Include Brand One', 'product_brand' );
		$brand2 = wp_insert_term( 'Fast Include Brand Two', 'product_brand' );
		$request = $this->wp_rest_get_request( '/wcpos/v1/products/brands' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$request->set_param( 'include', array( $brand1['term_id'] ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertEquals( array( $brand1['term_id'] ), $ids );
		$this->assertNotContains( $brand2['term_id'], $ids );
	}

	public function test_product_brand_api_get_all_ids_with_exclude_filter(): void {
		$brand1 = wp_insert_term( 'Fast Exclude Brand One', 'product_brand' );
		$brand2 = wp_insert_term( 'Fast Exclude Brand Two', 'product_brand' );
		$request = $this->wp_rest_get_request( '/wcpos/v1/products/brands' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );
		$request->set_param( 'exclude', array( $brand1['term_id'] ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertNotContains( $brand1['term_id'], $ids );
		$this->assertContains( $brand2['term_id'], $ids );
	}
}
