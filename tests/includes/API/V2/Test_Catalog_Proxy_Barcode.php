<?php
/**
 * Tests for barcode parity across the v2 catalog read/write surface.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WCPOS\WooCommercePOS\Tests\Sync\Sync_REST_Store_Test_Case;
use WP_REST_Response;

/**
 * Barcode reads, config envelopes, resolution, and writes use the same fields.
 */
class Test_Catalog_Proxy_Barcode extends Sync_REST_Store_Test_Case {
	/**
	 * Enable the v2 routes before REST initialization.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
	}

	/**
	 * Remove the feature flag written outside the test transaction.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Create a variation from the standard WooCommerce test fixture.
	 */
	private function create_variation(): WC_Product_Variation {
		$parent       = ProductHelper::create_variation_product();
		$variation_id = current( $parent->get_children() );

		return new WC_Product_Variation( $variation_id );
	}

	/**
	 * Read one product through the v2 catalog proxy.
	 *
	 * @param int $product_id Product ID.
	 */
	private function read_product( int $product_id ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_param( 'include', array( $product_id ) );

		$response = $this->server->dispatch( $request );
		$rows     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $rows );

		return $rows[0];
	}

	/**
	 * Read one variation through the v2 targeted variation endpoint.
	 *
	 * @param int $variation_id Variation ID.
	 */
	private function read_variation( int $variation_id ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/variations' );
		$request->set_param( 'include', array( $variation_id ) );

		$response  = $this->server->dispatch( $request );
		$documents = $response->get_data()['documents'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $documents );

		return $documents[0];
	}

	/**
	 * Return a keyed view of REST meta_data entries.
	 *
	 * @param array $payload REST payload.
	 */
	private function meta_data( array $payload ): array {
		return array_column( $payload['meta_data'], 'value', 'key' );
	}

	/**
	 * Resolve a barcode through the public v2 route.
	 *
	 * @param string $code Barcode value.
	 */
	private function resolve_barcode( string $code ): WP_REST_Response {
		$request = $this->wp_rest_get_request( '/wcpos/v2/resolve/barcode' );
		$request->set_param( 'code', $code );

		return $this->server->dispatch( $request );
	}

	/**
	 * Read the config-fingerprint barcode field envelope.
	 */
	private function barcode_fields(): array {
		$request  = $this->wp_rest_get_request( '/wcpos/v2/changes/config-fingerprint' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		return $response->get_data()['barcode_fields'];
	}

	/**
	 * Read the client-visible revision from the v2 product lane.
	 *
	 * @param int $product_id Product ID.
	 */
	private function product_revision( int $product_id ): string {
		// The proxy revision stamper is opt-in for tests (Test_Sync_Read_Controllers
		// pattern) — register it for this read so we get the CLIENT-visible revision.
		Revision::register_proxy_stamps();
		try {
			$product = $this->read_product( $product_id );
		} finally {
			Revision::unregister_proxy_stamps();
		}
		$this->assertArrayHasKey( '_rxdb_revision', $product );

		return $product['_rxdb_revision'];
	}

	/**
	 * Build and dispatch an update mutation envelope.
	 *
	 * @param string $collection    Collection route.
	 * @param string $record_id     Record UUID.
	 * @param string $base_revision Current record revision.
	 * @param string $barcode       New barcode value.
	 * @param string $field         Payload field, or custom meta key.
	 */
	private function push_barcode_update(
		string $collection,
		string $record_id,
		string $base_revision,
		string $barcode,
		string $field = '_barcode'
	): WP_REST_Response {
		$mutation_id = wp_generate_uuid4();
		$payload     = 'global_unique_id' === $field
			? array( 'global_unique_id' => $barcode )
			: array(
				'meta_data' => array(
					array(
						'key'   => $field,
						'value' => $barcode,
					),
				),
			);
		$envelope    = array(
			'mutationId'   => $mutation_id,
			'operation'    => 'update',
			'collection'   => $collection,
			'recordId'     => $record_id,
			'baseRevision' => $base_revision,
			'payload'      => $payload,
		);
		$request     = $this->wp_rest_post_request( '/wcpos/v2/push/' . $collection );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $mutation_id );
		$request->set_header( 'If-Match', '"' . $base_revision . '"' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * A configured custom meta field is readable and wins over fallback fields.
	 */
	public function test_custom_meta_mapping_is_readable_and_resolves_active_field_first(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_barcode' ) );

		$product = ProductHelper::create_simple_product();
		$product->update_meta_data( '_barcode', 'CUSTOM-PRODUCT-1372' );
		$product->save();
		$product_fallback = ProductHelper::create_simple_product();
		$product_fallback->set_sku( 'CUSTOM-PRODUCT-1372' );
		$product_fallback->save();

		$variation = $this->create_variation();
		$variation->update_meta_data( '_barcode', 'CUSTOM-VARIATION-1372' );
		$variation->save();
		$variation_fallback = ProductHelper::create_simple_product();
		$variation_fallback->set_sku( 'CUSTOM-VARIATION-1372' );
		$variation_fallback->save();

		$product_row       = $this->read_product( $product->get_id() );
		$variation_payload = $this->read_variation( $variation->get_id() )['payload'];
		$product_resolved  = $this->resolve_barcode( 'CUSTOM-PRODUCT-1372' );
		$variation_resolved = $this->resolve_barcode( 'CUSTOM-VARIATION-1372' );

		$this->assertSame( 'CUSTOM-PRODUCT-1372', $this->meta_data( $product_row )['_barcode'] );
		$this->assertSame( 'CUSTOM-VARIATION-1372', $this->meta_data( $variation_payload )['_barcode'] );
		$this->assertSame( 200, $product_resolved->get_status() );
		$this->assertTrue( $product_resolved->get_data()['found'] );
		$this->assertSame( $product->get_id(), $product_resolved->get_data()['match']['id'] );
		$this->assertSame( 'product', $product_resolved->get_data()['match']['type'] );
		$this->assertSame( array(), $product_resolved->get_data()['ambiguous'] );
		$this->assertSame( 200, $variation_resolved->get_status() );
		$this->assertTrue( $variation_resolved->get_data()['found'] );
		$this->assertSame( $variation->get_id(), $variation_resolved->get_data()['match']['id'] );
		$this->assertSame( 'variation', $variation_resolved->get_data()['match']['type'] );
		$this->assertSame( array(), $variation_resolved->get_data()['ambiguous'] );
	}

	/**
	 * Native barcode mappings advertise and serve their client payload fields.
	 */
	public function test_native_mappings_emit_envelope_fields_and_global_unique_id_values(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_global_unique_id( '4006381333931' );
		$product->save();
		$variation = $this->create_variation();
		$variation->set_global_unique_id( '9780201379624' );
		$variation->save();

		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_global_unique_id' ) );
		$global_fields    = $this->barcode_fields();
		$product_row      = $this->read_product( $product->get_id() );
		$variation_record = $this->read_variation( $variation->get_id() );

		$this->assertSame( array( 'global_unique_id' ), $global_fields['products'] );
		$this->assertSame( array( 'global_unique_id' ), $global_fields['variations'] );
		$this->assertSame( '4006381333931', $product_row['global_unique_id'] );
		$this->assertSame( '9780201379624', $variation_record['payload']['global_unique_id'] );

		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );
		$sku_fields = $this->barcode_fields();

		$this->assertSame( array( 'sku' ), $sku_fields['products'] );
		$this->assertSame( array( 'sku' ), $sku_fields['variations'] );
	}

	/**
	 * A custom meta mapping advertises a meta_data selector for both collections.
	 */
	public function test_custom_meta_mapping_emits_a_barcode_fields_envelope(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_barcode' ) );
		$underscore_fields = $this->barcode_fields();

		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => 'custom_ean' ) );
		$plain_fields = $this->barcode_fields();

		$this->assertSame( array( 'meta_data:_barcode' ), $underscore_fields['products'] );
		$this->assertSame( array( 'meta_data:_barcode' ), $underscore_fields['variations'] );
		$this->assertSame( array( 'meta_data:custom_ean' ), $plain_fields['products'] );
		$this->assertSame( array( 'meta_data:custom_ean' ), $plain_fields['variations'] );
	}

	/**
	 * Internal product meta is not serialized in wc/v3 meta_data.
	 */
	public function test_internal_meta_mapping_withholds_barcode_fields_envelope(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_price' ) );
		$price_fields = $this->barcode_fields();

		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_stock' ) );
		$stock_fields = $this->barcode_fields();

		$this->assertSame( array(), $price_fields['products'] );
		$this->assertSame( array(), $price_fields['variations'] );
		$this->assertSame( array(), $stock_fields['products'] );
		$this->assertSame( array(), $stock_fields['variations'] );
	}

	/**
	 * Product barcode meta survives a v2 push and is immediately readable.
	 */
	public function test_product_push_updates_custom_barcode_read_and_resolution(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_barcode' ) );
		$record_id = wp_generate_uuid4();
		$product   = ProductHelper::create_simple_product();
		$product->update_meta_data( Pos_Uuid::META_KEY, $record_id );
		$product->update_meta_data( '_barcode', 'PRODUCT-OLD-1372' );
		$product->save();

		$response = $this->push_barcode_update(
			'products',
			$record_id,
			$this->product_revision( $product->get_id() ),
			'PRODUCT-NEW-1372'
		);
		$after    = $this->read_product( $product->get_id() );
		$resolved = $this->resolve_barcode( 'PRODUCT-NEW-1372' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'PRODUCT-NEW-1372', $this->meta_data( $after )['_barcode'] );
		$this->assertTrue( $resolved->get_data()['found'] );
		$this->assertSame( $product->get_id(), $resolved->get_data()['match']['id'] );
		$this->assertSame( 'product', $resolved->get_data()['match']['type'] );
	}

	/**
	 * A GTIN barcode push persists through WooCommerce's global_unique_id field.
	 */
	public function test_product_push_updates_gtin_barcode_and_resolution(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_global_unique_id' ) );
		$record_id = wp_generate_uuid4();
		$product   = ProductHelper::create_simple_product();
		$product->update_meta_data( Pos_Uuid::META_KEY, $record_id );
		$product->set_global_unique_id( '4006381333931' );
		$product->save();

		$response = $this->push_barcode_update(
			'products',
			$record_id,
			$this->product_revision( $product->get_id() ),
			'9780201379624',
			'global_unique_id'
		);
		$after    = $this->read_product( $product->get_id() );
		$resolved = $this->resolve_barcode( '9780201379624' );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '9780201379624', $after['global_unique_id'] );
		$this->assertSame( '9780201379624', wc_get_product( $product->get_id() )->get_global_unique_id() );
		$this->assertTrue( $resolved->get_data()['found'] );
		$this->assertSame( $product->get_id(), $resolved->get_data()['match']['id'] );
		$this->assertSame( 'product', $resolved->get_data()['match']['type'] );
	}

	/**
	 * Variation barcode meta survives a v2 push and is immediately readable.
	 */
	public function test_variation_push_updates_custom_barcode_read_and_resolution(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_barcode' ) );
		$record_id = wp_generate_uuid4();
		$variation = $this->create_variation();
		$variation->update_meta_data( Pos_Uuid::META_KEY, $record_id );
		$variation->update_meta_data( '_barcode', 'VARIATION-OLD-1372' );
		$variation->save();
		$before = $this->read_variation( $variation->get_id() );

		$response = $this->push_barcode_update(
			'variations',
			$record_id,
			$before['payload']['date_modified_gmt'],
			'VARIATION-NEW-1372'
		);
		$after    = $this->read_variation( $variation->get_id() );
		$resolved = $this->resolve_barcode( 'VARIATION-NEW-1372' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'VARIATION-NEW-1372', $this->meta_data( $after['payload'] )['_barcode'] );
		$this->assertTrue( $resolved->get_data()['found'] );
		$this->assertSame( $variation->get_id(), $resolved->get_data()['match']['id'] );
		$this->assertSame( 'variation', $resolved->get_data()['match']['type'] );
	}

	/**
	 * A GTIN barcode push persists on a VARIATION through global_unique_id.
	 *
	 * The product-level twin of this case is covered by
	 * {@see self::test_product_push_updates_gtin_barcode_and_resolution()}, and the
	 * variation-level CUSTOM-META case by
	 * {@see self::test_variation_push_updates_custom_barcode_read_and_resolution()},
	 * but the variation + native GTIN combination had no coverage on either lane:
	 * v1 only ever exercised its own `barcode` request alias
	 * (API/V1/Product_Variations_Controller::wcpos_insert_product_object), which
	 * the v2 push lane does not have — it forwards the native field to wc/v3's
	 * NESTED variations resource. `_global_unique_id` is the DEFAULT barcode field
	 * (Barcode_Field::DEFAULT_FIELD), so this is the stock configuration.
	 */
	public function test_variation_push_updates_gtin_barcode_and_resolution(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_global_unique_id' ) );
		$record_id = wp_generate_uuid4();
		$variation = $this->create_variation();
		$variation->update_meta_data( Pos_Uuid::META_KEY, $record_id );
		$variation->set_global_unique_id( '4006381333931' );
		$variation->save();
		$before = $this->read_variation( $variation->get_id() );

		$response = $this->push_barcode_update(
			'variations',
			$record_id,
			$before['payload']['date_modified_gmt'],
			'9780201379624',
			'global_unique_id'
		);
		$after    = $this->read_variation( $variation->get_id() );
		$resolved = $this->resolve_barcode( '9780201379624' );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '9780201379624', $after['payload']['global_unique_id'] );
		$this->assertSame(
			'9780201379624',
			( new WC_Product_Variation( $variation->get_id() ) )->get_global_unique_id()
		);
		$this->assertTrue( $resolved->get_data()['found'] );
		$this->assertSame( $variation->get_id(), $resolved->get_data()['match']['id'] );
		$this->assertSame( 'variation', $resolved->get_data()['match']['type'] );
	}
}
