<?php
/**
 * Tests for catalog sync read controllers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Changes_Controller;
use WCPOS\WooCommercePOS\Sync\Digests_Controller;
use WCPOS\WooCommercePOS\Sync\Integrity_Controller;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Resolve_Controller;
use WCPOS\WooCommercePOS\Sync\Variations_Controller;
use WP_REST_Request;

/**
 * Read controller behavior tests.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Changes_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Digests_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Integrity_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Resolve_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Variations_Controller
 */
class Test_Sync_Read_Controllers extends Sync_REST_Store_Test_Case {
	/**
	 * Remove read settings after each test.
	 */
	public function tearDown(): void {
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
	}

	/**
	 * Build a GET request with query parameters.
	 *
	 * @param array $params Query parameters.
	 */
	private function request( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_query_params( $params );

		return $request;
	}

	/**
	 * Digests preserve request order and omit IDs without stored rows.
	 */
	public function test_digests_returns_stored_pairs_in_request_order(): void {
		$first  = ProductHelper::create_simple_product();
		$second = ProductHelper::create_simple_product();
		$digest = new Integrity_Digest();
		$digest->upsert_digest( $first->get_id() );
		$digest->upsert_digest( $second->get_id() );

		$response = ( new Digests_Controller() )->get_digests(
			$this->request( array( 'include' => $second->get_id() . ',' . $first->get_id() . ',999999' ) )
		);
		$ids = array_column( $response->get_data()['digests'], 'id' );

		$this->assertSame( array( $second->get_id(), $first->get_id() ), $ids );
	}

	/**
	 * Sequence-log unified mode tags rows with their client collection.
	 */
	public function test_sequence_log_all_maps_object_type_to_collection(): void {
		$product = ProductHelper::create_simple_product();
		$log     = new Change_Log();
		$log->record( 'product', $product->get_id(), 'update', 'test', false );

		$response = ( new Changes_Controller( $log ) )->sequence_log(
			$this->request(
				array(
					'collection' => 'all',
					'since' => 0,
					'limit' => 10,
				)
			)
		);
		$data = $response->get_data();

		$this->assertSame( 'products', $data['changes'][0]['collection'] );
		$this->assertSame( $product->get_id(), $data['changes'][0]['id'] );
	}

	/**
	 * Revision hash hydrates and hashes the filtered product representation.
	 */
	public function test_revision_hash_returns_product_revision(): void {
		$product  = ProductHelper::create_simple_product();
		$response = ( new Changes_Controller() )->revision_hash(
			$this->request(
				array(
					'collection' => 'products',
					'since_id'   => max( 0, $product->get_id() - 1 ),
					'limit'      => 1,
				)
			)
		);
		$data = $response->get_data();

		$this->assertSame( $product->get_id(), $data['changes'][0]['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $data['changes'][0]['revision'] );
	}

	/**
	 * Config fingerprint exposes all supported collection snapshots by default.
	 */
	public function test_config_fingerprint_returns_supported_collections(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );
		$response = ( new Changes_Controller() )->config_fingerprint( $this->request() );
		$before   = $response->get_data();

		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_global_unique_id' ) );
		$response = ( new Changes_Controller() )->config_fingerprint( $this->request() );
		$after    = $response->get_data();

		$this->assertArrayHasKey( 'products', $after['fingerprints'] );
		$this->assertArrayHasKey( 'variations', $after['fingerprints'] );
		$this->assertArrayHasKey( 'tax_rates', $after['fingerprints'] );
		$this->assertNotSame( $before['fingerprints']['products'], $after['fingerprints']['products'] );
		$this->assertSame( array( 'global_unique_id' ), $after['barcode_fields']['products'] );
	}

	/**
	 * Variation hydration excludes records configured as online-only.
	 */
	public function test_variations_excludes_online_only_ids(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = array_map( 'intval', $product->get_children() );
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'variations' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $variation_ids[1] ) ),
					),
				),
			)
		);

		$response = ( new Variations_Controller() )->get_variations(
			$this->request( array( 'include' => $variation_ids ) )
		);
		$data = $response->get_data();

		$this->assertSame( array( $variation_ids[0] ), array_column( $data['documents'], 'id' ) );
		$this->assertSame( $product->get_id(), $data['documents'][0]['parent_id'] );
	}

	/**
	 * Barcode resolution hydrates the matching product through REST serialization.
	 */
	public function test_resolve_barcode_returns_matching_product(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_sku( 'SYNC-SCAN-001' );
		$product->save();

		$response = ( new Resolve_Controller() )->resolve_barcode(
			$this->request( array( 'code' => 'SYNC-SCAN-001' ) )
		);
		$data = $response->get_data();

		$this->assertTrue( $data['found'] );
		$this->assertSame( $product->get_id(), $data['match']['id'] );
		$this->assertSame( 'product', $data['match']['type'] );
	}

	/**
	 * Barcode resolution includes the merchant's configured custom meta key.
	 */
	public function test_resolve_barcode_returns_product_matching_configured_custom_field(): void {
		$product = ProductHelper::create_simple_product();
		$product->update_meta_data( '_alg_ean', 'SYNC-CUSTOM-001' );
		$product->save_meta_data();
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_alg_ean' ) );

		$response = ( new Resolve_Controller() )->resolve_barcode(
			$this->request( array( 'code' => 'SYNC-CUSTOM-001' ) )
		);
		$data = $response->get_data();

		$this->assertTrue( $data['found'] );
		$this->assertSame( $product->get_id(), $data['match']['id'] );
	}

	/**
	 * Barcode resolution does not hydrate an online-only product.
	 */
	public function test_resolve_barcode_excludes_online_only_product(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_sku( 'SYNC-HIDDEN-PRODUCT' );
		$product->save();
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $product->get_id() ) ),
					),
				),
			)
		);

		$response = ( new Resolve_Controller() )->resolve_barcode(
			$this->request( array( 'code' => 'SYNC-HIDDEN-PRODUCT' ) )
		);
		$data = $response->get_data();

		$this->assertFalse( $data['found'] );
		$this->assertNull( $data['match'] );
	}

	/**
	 * Barcode resolution does not hydrate an online-only variation.
	 */
	public function test_resolve_barcode_excludes_online_only_variation(): void {
		$product      = ProductHelper::create_variation_product();
		$variation_id = (int) $product->get_children()[0];
		$variation    = new WC_Product_Variation( $variation_id );
		$variation->set_sku( 'SYNC-HIDDEN-VARIATION' );
		$variation->save();
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

		$response = ( new Resolve_Controller() )->resolve_barcode(
			$this->request( array( 'code' => 'SYNC-HIDDEN-VARIATION' ) )
		);
		$data = $response->get_data();

		$this->assertFalse( $data['found'] );
		$this->assertNull( $data['match'] );
	}

	/**
	 * Integrity bucket lists the authoritative current product digest.
	 */
	public function test_integrity_bucket_lists_current_product_digest(): void {
		$product     = ProductHelper::create_simple_product();
		$bucket_size = 1000;
		$bucket      = (int) floor( $product->get_id() / $bucket_size );

		$response = ( new Integrity_Controller() )->bucket_list(
			$this->request(
				array(
					'collection'  => 'products',
					'bucket_size' => $bucket_size,
					'bucket'      => $bucket,
				)
			)
		);
		$rows = $response->get_data()['ids'];
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( $product->get_id() === $candidate['id'] ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( 'product', $row['object_type'] );
		$this->assertNotSame( '', $row['digest'] );
	}

	/**
	 * Integrity drill-down reports no drift after the stored digest is refreshed.
	 */
	public function test_integrity_scan_bucket_matches_fresh_stored_digest(): void {
		$product     = ProductHelper::create_simple_product();
		$bucket_size = 1000;
		$bucket      = (int) floor( $product->get_id() / $bucket_size );
		( new Integrity_Digest() )->upsert_digest( $product->get_id() );

		$response = ( new Integrity_Controller() )->scan(
			$this->request(
				array(
					'bucket_size' => $bucket_size,
					'bucket'      => $bucket,
				)
			)
		);

		$this->assertNotContains( $product->get_id(), array_column( $response->get_data()['changes'], 'id' ) );
	}

	/**
	 * A product ID sent to the variations lane is ignored.
	 */
	public function test_variations_skips_non_variation_ids(): void {
		$product  = ProductHelper::create_simple_product();
		$response = ( new Variations_Controller() )->get_variations(
			$this->request( array( 'include' => array( $product->get_id() ) ) )
		);

		$this->assertSame( array(), $response->get_data()['documents'] );
		$this->assertNotInstanceOf( WC_Product_Variation::class, wc_get_product( $product->get_id() ) );
	}
}
