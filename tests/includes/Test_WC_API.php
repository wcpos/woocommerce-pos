<?php

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\WC_API;

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

	/**
	 *
	 */
	public function test_pos_only_products_via_store_api() {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'pos_only_products' => true,
				);
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
		$request = new \WP_REST_Request( 'GET', '/wc/v3/products' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $visible_product->get_id(), $data[0]['id'] );

		delete_option( 'woocommerce_pos_settings_visibility' );
	}

		/**
		 *
		 */
	public function test_pos_only_variations_via_store_api() {
		add_filter(
			'woocommerce_pos_general_settings',
			function () {
				return array(
					'pos_only_products' => true,
				);
			}
		);
		new WC_API(); // reinstantiate the class to apply the filter

		// Create a variable product
		$variable = ProductHelper::create_variation_product();
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

		$request = new \WP_REST_Request( 'GET', '/wc/v3/products/' . $variable->get_id() . '/variations' );
		$response = $this->server->dispatch( $request );

		$data = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, \count( $data ) );
		$this->assertEquals( $variation_ids[1], $data[0]['id'] );

		/**
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
