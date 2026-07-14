<?php
/**
 * Tests for sync read-surface helpers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Config_Fingerprint;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Term_Meta_Adapter;
use WCPOS\WooCommercePOS\Sync\Variable_Prices;
use WP_UnitTestCase;

/**
 * Read helper tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Config_Fingerprint
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Visibility
 * @covers \WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper
 * @covers \WCPOS\WooCommercePOS\Sync\Term_Meta_Adapter
 * @covers \WCPOS\WooCommercePOS\Sync\Variable_Prices
 */
class Test_Sync_Read_Helpers extends WP_UnitTestCase {
	/**
	 * Remove options written outside the per-test assertions.
	 */
	public function tearDown(): void {
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		delete_option( Config_Fingerprint::CLEANUP_VERSION_OPTION );
		parent::tearDown();
	}

	/**
	 * Visibility reads the configured scope and sanitizes duplicate/invalid IDs.
	 */
	public function test_pos_visibility_returns_unique_positive_online_only_ids(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( '4', 4, 0, -1, 8 ) ),
					),
				),
			)
		);

		$this->assertSame( array( 4, 8 ), ( new Pos_Visibility() )->online_only_product_ids() );
		$this->assertSame( array(), ( new Pos_Visibility() )->online_only_variation_ids() );
	}

	/**
	 * Stored visibility ids do not apply while the production feature toggle is disabled.
	 */
	public function test_pos_visibility_with_toggle_disabled_returns_no_exclusions(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => false ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( 4 ) ),
					),
				),
				'variations' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( 8 ) ),
					),
				),
			)
		);

		$this->assertSame( array(), ( new Pos_Visibility() )->online_only_product_ids() );
		$this->assertSame( array(), ( new Pos_Visibility() )->online_only_variation_ids() );
	}

	/**
	 * Term adapter exposes the WC data meta contract over term meta.
	 */
	public function test_term_meta_adapter_mints_and_persists_identity(): void {
		$term = wp_insert_term( 'Sync identity term', 'product_cat' );
		$this->assertNotWPError( $term );

		$term_id = (int) $term['term_id'];
		$uuid    = Pos_Uuid::ensure_uuid( new Term_Meta_Adapter( $term_id ) );

		$this->assertTrue( Pos_Uuid::is_uuid( $uuid ) );
		$this->assertSame( $uuid, get_term_meta( $term_id, Api::UUID_META_KEY, true ) );
	}

	/**
	 * Product proxy reinjects protected UUID meta from its bulk read.
	 */
	public function test_product_proxy_reinjects_existing_uuid(): void {
		$product = ProductHelper::create_simple_product();
		$uuid    = wp_generate_uuid4();
		$product->update_meta_data( Api::UUID_META_KEY, $uuid );
		$product->save_meta_data();

		$data = Proxy_Uuid_Stamper::stamp_proxy_products(
			array(
				array(
					'id' => $product->get_id(),
					'name' => 'Product',
				),
			),
			'products'
		);

		$this->assertSame( $uuid, Pos_Uuid::read_valid_uuid_from_meta( $data[0]['meta_data'] ) );
		$this->assertSame( 'Product', $data[0]['name'] );
	}

	/**
	 * Variable prices are recomputed from current child values.
	 */
	public function test_variable_prices_stamp_current_child_range(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$first         = new WC_Product_Variation( $variation_ids[0] );
		$second        = new WC_Product_Variation( $variation_ids[1] );
		$first->set_regular_price( '9.50' );
		$first->set_price( '9.50' );
		$first->save();
		$second->set_regular_price( '25.00' );
		$second->set_price( '25.00' );
		$second->save();

		$data = Variable_Prices::stamp_proxy_variable_prices(
			array(
				array(
					'id' => $product->get_id(),
					'type' => 'variable',
				),
			),
			'products'
		);
		$range = null;
		foreach ( $data[0]['meta_data'] as $meta ) {
			if ( Variable_Prices::META_KEY === $meta['key'] ) {
				$range = $meta['value']['price'];
			}
		}

		$this->assertSame(
			array(
				'min' => '9.50',
				'max' => '25.00',
			),
			$range
		);
	}

	/**
	 * Fingerprints are stable and move with the live barcode option.
	 */
	public function test_config_fingerprint_tracks_live_barcode_mapping(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );
		$fingerprint = new Config_Fingerprint();
		$before      = $fingerprint->fingerprint( 'products' );

		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_global_unique_id' ) );

		$this->assertNotSame( $before, $fingerprint->fingerprint( 'products' ) );
		$this->assertSame( array( 'global_unique_id' ), $fingerprint->barcode_fields( 'products' ) );
		$this->assertSame( array(), $fingerprint->barcode_fields( 'tax_rates' ) );
	}
}
