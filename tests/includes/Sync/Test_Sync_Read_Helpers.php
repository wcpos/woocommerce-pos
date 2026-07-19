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
use WP_REST_Request;
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
	 * Variable-price stamping replaces stale parent fields left by a simple product.
	 */
	public function test_variable_prices_replace_stale_simple_product_price_fields(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();

		foreach ( $variation_ids as $variation_id ) {
			$variation = new WC_Product_Variation( $variation_id );
			$variation->set_regular_price( '114' );
			$variation->set_sale_price( '' );
			$variation->set_price( '114' );
			$variation->save();
		}

		$data = Variable_Prices::stamp_proxy_variable_prices(
			array(
				array(
					'id'            => $product->get_id(),
					'type'          => 'variable',
					'price'         => '102',
					'regular_price' => '102',
					'sale_price'    => '90',
				),
			),
			'products',
			new WP_REST_Request( 'GET', '/wcpos/v2/products' )
		);

		$this->assertSame( '114.00', $data[0]['price'] );
		$this->assertSame( '', $data[0]['regular_price'] );
		$this->assertSame( '', $data[0]['sale_price'] );
	}

	/**
	 * A variable product with no visible prices must not retain old simple fields.
	 */
	public function test_variable_prices_clear_stale_parent_fields_when_no_child_has_a_price(): void {
		$product = ProductHelper::create_variation_product();

		foreach ( $product->get_children() as $variation_id ) {
			$variation = new WC_Product_Variation( $variation_id );
			$variation->set_regular_price( '' );
			$variation->set_sale_price( '' );
			$variation->set_price( '' );
			$variation->save();
		}

		$data = Variable_Prices::stamp_proxy_variable_prices(
			array(
				array(
					'id'            => $product->get_id(),
					'type'          => 'variable',
					'price'         => '102.00',
					'regular_price' => '102.00',
					'sale_price'    => '90.00',
					'meta_data'     => array(
						array(
							'key'   => Variable_Prices::META_KEY,
							'value' => array( 'price' => array( 'min' => '102', 'max' => '102' ) ),
						),
						array( 'key' => 'keep_me', 'value' => 'yes' ),
					),
				),
			),
			'products'
		);

		$this->assertSame( '', $data[0]['price'] );
		$this->assertSame( '', $data[0]['regular_price'] );
		$this->assertSame( '', $data[0]['sale_price'] );
		$this->assertSame( array( array( 'key' => 'keep_me', 'value' => 'yes' ) ), $data[0]['meta_data'] );
	}

	/**
	 * A POS online-only child's price must not leak into the served range (finding 6).
	 */
	public function test_variable_prices_exclude_online_only_child(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = $product->get_children();
		$cheap         = new WC_Product_Variation( $variation_ids[0] );
		$expensive     = new WC_Product_Variation( $variation_ids[1] );
		$cheap->set_regular_price( '9.50' );
		$cheap->set_price( '9.50' );
		$cheap->save();
		$expensive->set_regular_price( '25.00' );
		$expensive->set_price( '25.00' );
		$expensive->save();

		// Hide the expensive child from the POS.
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'variations' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( (int) $variation_ids[1] ) ),
					),
				),
			)
		);

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

		// The hidden child's 25.00 must not set the max — only the visible 9.50 remains.
		$this->assertSame(
			array(
				'min' => '9.50',
				'max' => '9.50',
			),
			$range
		);
	}

	/**
	 * A custom barcode field advertises no payload field (finding 9): the sync proxy
	 * (raw wc/v3) never serves a top-level field for a custom/`_`-prefixed meta key,
	 * so the honest advertised list is empty rather than a field that never arrives.
	 */
	public function test_config_fingerprint_custom_barcode_field_advertises_no_payload_field(): void {
		$fingerprint = new Config_Fingerprint();

		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_alg_ean' ) );
		$this->assertSame( array(), $fingerprint->barcode_fields( 'products' ) );

		// `_barcode` is protected meta wc/v3 strips — also not served, so also empty.
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_barcode' ) );
		$this->assertSame( array(), $fingerprint->barcode_fields( 'products' ) );

		// The two natively-served fields remain advertised.
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );
		$this->assertSame( array( 'sku' ), $fingerprint->barcode_fields( 'products' ) );
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
