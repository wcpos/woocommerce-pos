<?php

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Products;
use WCPOS\WooCommercePOS\WC_API;
use WP_REST_Request;

/**
 * @internal
 *
 * @coversNothing
 */
class Test_WC_API extends WC_REST_Unit_Test_Case {
	public function setup(): void {
		parent::setup();
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	public function test_pos_only_products_via_store_api(): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new WC_API(); // reinstantiate the class to apply the filter

		// Create a visible product
		$visible_product = ProductHelper::create_simple_product();

		// Create a product with visibility set to 'pos_only'
		$hidden_product = ProductHelper::create_simple_product();

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $hidden_product->get_id() ),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
			)
		);

		// Make WC REST request
		add_filter( 'woocommerce_rest_check_permissions', '__return_true' );
		$request  = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $visible_product->get_id(), $data[0]['id'] );

		delete_option( 'woocommerce_pos_settings_visibility' );
	}

	/**
	 * WooCommerce Analytics reads the catalogue through the wc-analytics namespace
	 * (its category filter calls wc_get_products() mid-request). That is the
	 * merchant reporting on their shop, so POS Only products must be listed there
	 * even though the request is neither wp-admin nor a POS request.
	 */
	public function test_pos_only_products_listed_on_wc_analytics_products_route(): void {
		// Arrange.
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new Products(); // reinstantiate so the constructor sees the setting.
		new WC_API();

		$visible_product = ProductHelper::create_simple_product();
		$hidden_product  = ProductHelper::create_simple_product();
		$this->set_pos_only_product_ids( array( $hidden_product->get_id() ) );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$store_request = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$store_request->set_param( 'per_page', 100 );
		$analytics_request = new WP_REST_Request( 'GET', '/wc-analytics/products' );
		$analytics_request->set_param( 'per_page', 100 );

		// Act.
		$store_response     = $this->server->dispatch( $store_request );
		$analytics_response = $this->server->dispatch( $analytics_request );

		// Assert: the same product list is filtered on the public API and complete for Analytics.
		$this->assertSame( 200, $store_response->get_status() );
		$store_ids = wp_list_pluck( $store_response->get_data(), 'id' );
		$this->assertContains( $visible_product->get_id(), $store_ids );
		$this->assertNotContains( $hidden_product->get_id(), $store_ids, 'The public API still hides POS Only products.' );

		$this->assertSame( 200, $analytics_response->get_status() );
		$analytics_ids = wp_list_pluck( $analytics_response->get_data(), 'id' );
		$this->assertContains( $visible_product->get_id(), $analytics_ids );
		$this->assertContains( $hidden_product->get_id(), $analytics_ids, 'Analytics must see POS Only products.' );

		delete_option( 'woocommerce_pos_settings_visibility' );
	}

	/**
	 * Store a POS Only product id list the way the settings screen does.
	 *
	 * @param int[] $ids Product ids.
	 */
	private function set_pos_only_product_ids( array $ids ): void {
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products'   => array(
					'default' => array(
						'pos_only'    => array( 'ids' => $ids ),
						'online_only' => array( 'ids' => array() ),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only'    => array( 'ids' => array() ),
						'online_only' => array( 'ids' => array() ),
					),
				),
			)
		);
	}

	/**
	 * WordPress matches REST routes case-insensitively, so a mixed-case path
	 * dispatches to the products controller — the POS-only exclusion must not
	 * be skippable by upper-casing the route.
	 */
	public function test_pos_only_products_excluded_on_mixed_case_route(): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new WC_API(); // reinstantiate the class to apply the filter

		$visible_product = ProductHelper::create_simple_product();
		$hidden_product  = ProductHelper::create_simple_product();

		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $hidden_product->get_id() ),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
			)
		);

		add_filter( 'woocommerce_rest_check_permissions', '__return_true' );
		$request  = new WP_REST_Request( 'GET', '/WC/V3/products' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $visible_product->get_id(), $data[0]['id'] );

		delete_option( 'woocommerce_pos_settings_visibility' );
	}

	public function test_pos_only_variations_via_store_api(): void {
		add_filter(
			'wcpos_general_settings',
			function ( $settings ) {
				$settings['pos_only_products'] = true;

				return $settings;
			}
		);
		new WC_API(); // reinstantiate the class to apply the filter

		// Create a variable product
		$variable      = ProductHelper::create_variation_product();
		$variation_ids = $variable->get_children();
		update_option(
			'woocommerce_pos_settings_visibility',
			array(
				'products' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array(),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
				'variations' => array(
					'default' => array(
						'pos_only' => array(
							'ids' => array( $variation_ids[0] ),
						),
						'online_only' => array(
							'ids' => array(),
						),
					),
				),
			)
		);

		// Make WC REST request
		add_filter( 'woocommerce_rest_check_permissions', '__return_true' );

		$request  = new WP_REST_Request( 'GET', '/wc/v3/products/' . $variable->get_id() . '/variations' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $variation_ids[1], $data[0]['id'] );

		/*
		 * @TODO should we remove the id from the parent response also?
		 * The WooCommerce code uses $object->get_children() to get the variation ids, NOT
		 * $object->get_visible_children() so it seems they return all variations ids regardless of visibility.
		 */
		// $request = new \WP_REST_Request( 'GET', '/wc/v3/products/' . $variable->get_id() );
		// $response = $this->server->dispatch( $request );

		// $data = $response->get_data();
		// $this->assertEquals( 200, $response->get_status() );
		// $this->assertEquals( $variable->get_id(), $data['id'] );
		// $this->assertEquals( 1, \count( $data['variations'] ) );
		// $this->assertEquals( $variation_ids[1], $data['variations'][0] );

		delete_option( 'woocommerce_pos_settings_visibility' );
	}
}
