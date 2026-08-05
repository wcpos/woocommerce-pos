<?php
/**
 * Tests for POS visibility in v2 product catalog reads.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/**
 * Product proxy reads exclude every record hidden from the POS.
 */
class Test_Catalog_Proxy_Visibility extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable the v2 routes before REST initialization.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Remove settings written outside the test transaction.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Pos_Visibility::OPTION );
	}

	/**
	 * Configure a variation as hidden from the POS.
	 *
	 * @param int $variation_id Variation ID.
	 */
	private function hide_variation( int $variation_id ): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'variations' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $variation_id ) ),
					),
				),
			)
		);
	}

	/**
	 * Read products through the v2 catalog proxy.
	 *
	 * @param array $params Query parameters.
	 */
	private function read_products( array $params = array() ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_query_params( $params );

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Return a variation from the standard WooCommerce fixture.
	 */
	private function create_variation(): WC_Product_Variation {
		$parent       = ProductHelper::create_variation_product();
		$variation_id = current( $parent->get_children() );

		return new WC_Product_Variation( $variation_id );
	}

	/**
	 * An online-only variation cannot leak through a widened SKU product query.
	 *
	 * @see https://github.com/wcpos/woocommerce-pos/issues/1442
	 */
	public function test_sku_query_excludes_online_only_variation(): void {
		$variation = $this->create_variation();
		$variation->set_sku( 'HIDDEN-VARIATION-1442' );
		$variation->save();
		$this->hide_variation( $variation->get_id() );

		$ids = array_map( 'intval', wp_list_pluck( $this->read_products( array( 'sku' => $variation->get_sku() ) ), 'id' ) );

		$this->assertNotContains( $variation->get_id(), $ids );
	}

	/**
	 * A visible variation remains available through a widened SKU product query.
	 */
	public function test_sku_query_includes_visible_variation(): void {
		$visible = $this->create_variation();
		$visible->set_sku( 'VISIBLE-VARIATION-1442' );
		$visible->save();
		$hidden = $this->create_variation();
		$this->hide_variation( $hidden->get_id() );

		$ids = array_map( 'intval', wp_list_pluck( $this->read_products( array( 'sku' => $visible->get_sku() ) ), 'id' ) );

		$this->assertContains( $visible->get_id(), $ids );
	}

	/**
	 * Excluding a hidden variation does not affect a plain product browse.
	 */
	public function test_plain_browse_still_includes_visible_products(): void {
		$product   = ProductHelper::create_simple_product();
		$variation = $this->create_variation();
		$this->hide_variation( $variation->get_id() );

		$ids = array_map( 'intval', wp_list_pluck( $this->read_products(), 'id' ) );

		$this->assertContains( $product->get_id(), $ids );
	}
}
