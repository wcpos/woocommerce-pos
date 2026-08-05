<?php
/**
 * Tests for catalog sync read controllers.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\API\V2\Catalog_Proxy_Controller;
use WCPOS\WooCommercePOS\API\V2\Changes_Controller;
use WCPOS\WooCommercePOS\API\V2\Digests_Controller;
use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\API\V2\Resolve_Controller;
use WCPOS\WooCommercePOS\API\V2\Variations_Controller;
use WCPOS\WooCommercePOS\Sync\Change_Log;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Response_Telemetry;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;

/**
 * Read controller behavior tests.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Changes_Controller
 * @covers \WCPOS\WooCommercePOS\API\V2\Digests_Controller
 * @covers \WCPOS\WooCommercePOS\API\V2\Integrity_Controller
 * @covers \WCPOS\WooCommercePOS\API\V2\Resolve_Controller
 * @covers \WCPOS\WooCommercePOS\API\V2\Variations_Controller
 */
class Test_Sync_Read_Controllers extends Sync_REST_Store_Test_Case {
	/**
	 * Remove read settings after each test.
	 */
	public function tearDown(): void {
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		Meta_Normalizer::unregister_hooks();
		Revision::unregister_proxy_stamps();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
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
	 * Sequence-log embeds the standalone config fingerprint response data.
	 */
	public function test_sequence_log_contains_standalone_config_fingerprint_data(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );
		$controller = new Changes_Controller();

		$sequence_data   = $controller->sequence_log( $this->request() )->get_data();
		$standalone_data = $controller->config_fingerprint( $this->request() )->get_data();

		// Request timing is intentionally volatile; the remaining response data must match.
		unset( $sequence_data['config_fingerprint']['meta']['duration_ms'], $standalone_data['meta']['duration_ms'] );
		$this->assertSame( $standalone_data, $sequence_data['config_fingerprint'] );
	}

	/**
	 * A matching ETag at the current head returns no response body.
	 */
	public function test_sequence_log_matching_etag_at_head_returns_not_modified(): void {
		$controller = new Changes_Controller();
		$response   = $controller->sequence_log( $this->request( array( 'since' => 0 ) ) );
		$etag       = $response->get_headers()['ETag'];
		$request    = $this->request( array( 'since' => 0 ) );
		$request->set_header( 'If-None-Match', $etag );
		$request->set_route( '/wcpos/v2/changes/sequence-log' );
		Response_Telemetry::start_request( null, null, $request );

		$not_modified = Response_Telemetry::decorate_callback_response( $controller->sequence_log( $request ), null, $request );

		$this->assertSame( 304, $not_modified->get_status() );
		$this->assertNull( $not_modified->get_data() );
		$this->assertSame( $etag, $not_modified->get_headers()['ETag'] );
		$this->assertSame( 'no-store', $not_modified->get_headers()['Cache-Control'] );
	}

	/**
	 * RFC 9110 If-None-Match forms all match (parser from wcpos-bot's review).
	 *
	 * @dataProvider matching_if_none_match_header_provider
	 */
	public function test_sequence_log_matching_if_none_match_form_returns_not_modified( string $header_template ): void {
		$controller = new Changes_Controller();
		$response   = $controller->sequence_log( $this->request( array( 'since' => 0 ) ) );
		$etag       = $response->get_headers()['ETag'];
		$request    = $this->request( array( 'since' => 0 ) );
		$header     = false === strpos( $header_template, '%s' ) ? $header_template : sprintf( $header_template, $etag );
		$request->set_header( 'If-None-Match', $header );

		$not_modified = $controller->sequence_log( $request );

		$this->assertSame( 304, $not_modified->get_status() );
	}

	/**
	 * Matching If-None-Match header forms.
	 */
	public function matching_if_none_match_header_provider(): array {
		return array(
			'weak validator' => array( 'W/%s' ),
			'wildcard'       => array( '*' ),
			'validator list' => array( '"other,tag", W/%s, "another"' ),
		);
	}

	/**
	 * Malformed conditional headers never match — full 200 response.
	 */
	public function test_sequence_log_malformed_if_none_match_returns_full_response(): void {
		$controller = new Changes_Controller();
		$controller->sequence_log( $this->request( array( 'since' => 0 ) ) );
		$request = $this->request( array( 'since' => 0 ) );
		$request->set_header( 'If-None-Match', 'not-a-quoted-tag' );

		$response = $controller->sequence_log( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The embedded fingerprint always reports every collection, even when the
	 * stream itself is narrowed (products serves variation rows too).
	 */
	public function test_sequence_log_embedded_fingerprint_ignores_collection_narrowing(): void {
		$controller = new Changes_Controller();

		$sequence_data = $controller->sequence_log( $this->request( array( 'collection' => 'products' ) ) )->get_data();

		$this->assertSame(
			array_values( \WCPOS\WooCommercePOS\Sync\Config_Fingerprint::collections() ),
			array_keys( $sequence_data['config_fingerprint']['fingerprints'] )
		);
	}

	/**
	 * A matching ETag cannot hide rows after a behind cursor.
	 */
	public function test_sequence_log_matching_etag_with_since_behind_head_returns_page(): void {
		$log = new Change_Log();
		$log->record( 'product', 123, 'update', 'test', false );
		$controller = new Changes_Controller( $log );
		$latest     = $controller->sequence_log( $this->request( array( 'since' => 0 ) ) );
		$head       = $latest->get_data()['checkpoint']['head'];
		$current    = $controller->sequence_log( $this->request( array( 'since' => $head ) ) );
		$request    = $this->request( array( 'since' => 0 ) );
		$request->set_header( 'If-None-Match', $current->get_headers()['ETag'] );

		$response = $controller->sequence_log( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 123, $response->get_data()['changes'][0]['id'] );
	}

	/**
	 * A representation config change invalidates a sequence-log ETag.
	 */
	public function test_sequence_log_config_change_invalidates_etag(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_sku' ) );
		$controller = new Changes_Controller();
		$before     = $controller->sequence_log( $this->request( array( 'since' => 0 ) ) );
		$request    = $this->request( array( 'since' => 0 ) );
		$request->set_header( 'If-None-Match', $before->get_headers()['ETag'] );
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_global_unique_id' ) );

		$after = $controller->sequence_log( $request );

		$this->assertSame( 200, $after->get_status() );
		$this->assertNotSame( $before->get_headers()['ETag'], $after->get_headers()['ETag'] );
		$this->assertSame( 'no-store', $after->get_headers()['Cache-Control'] );
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
	 * Active-field-first (finding 1): a value on an inactive hard-coded key must NOT
	 * beat a match on the merchant's configured active field for the same code.
	 */
	public function test_resolve_barcode_active_field_beats_inactive_key_collision(): void {
		// Active-field match lives on the merchant's configured custom key.
		$active = ProductHelper::create_simple_product();
		$active->update_meta_data( '_alg_ean', 'COLLIDE-1' );
		$active->save_meta_data();

		// A stale value on the inactive hard-coded key (_sku) shares the code.
		$inactive = ProductHelper::create_simple_product();
		$inactive->set_sku( 'COLLIDE-1' );
		$inactive->save();

		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_alg_ean' ) );

		$response = ( new Resolve_Controller() )->resolve_barcode(
			$this->request( array( 'code' => 'COLLIDE-1' ) )
		);
		$data = $response->get_data();

		$this->assertTrue( $data['found'] );
		$this->assertSame( $active->get_id(), $data['match']['id'] );
		// The inactive-key product is not even a candidate — the active field matched.
		$this->assertSame( array(), $data['ambiguous'] );
	}

	/**
	 * Fallback (finding 1): with no active-field match, the hard-coded keys still resolve.
	 */
	public function test_resolve_barcode_falls_back_to_hardcoded_keys(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_sku( 'FALLBACK-1' );
		$product->save();

		// Active field is a custom key nothing carries → fall back to _sku.
		update_option( 'woocommerce_pos_settings_general', array( 'barcode_field' => '_alg_ean' ) );

		$response = ( new Resolve_Controller() )->resolve_barcode(
			$this->request( array( 'code' => 'FALLBACK-1' ) )
		);
		$data = $response->get_data();

		$this->assertTrue( $data['found'] );
		$this->assertSame( $product->get_id(), $data['match']['id'] );
	}

	/**
	 * Variation hydration attaches the stored existence digest (finding 3): the
	 * class_exists() guard previously checked a global class name and never fired.
	 */
	public function test_variations_attach_stored_digests(): void {
		$product       = ProductHelper::create_variation_product();
		$variation_ids = array_map( 'intval', $product->get_children() );
		$digest        = new Integrity_Digest();
		foreach ( $variation_ids as $variation_id ) {
			$digest->upsert_digest( $variation_id );
		}

		$response = ( new Variations_Controller() )->get_variations(
			$this->request( array( 'include' => $variation_ids ) )
		);
		$documents = $response->get_data()['documents'];

		$this->assertNotEmpty( $documents );
		foreach ( $documents as $document ) {
			$this->assertArrayHasKey( '_rxdb_digest', $document );
			$this->assertNotSame( '', $document['_rxdb_digest'] );
		}
	}

	/**
	 * Integrity bucket fails closed for an unsupported collection (finding 7):
	 * an unknown collection must 400 by name, not fall into the products id-space.
	 */
	public function test_integrity_bucket_rejects_unsupported_collection(): void {
		$result = ( new Integrity_Controller() )->bucket_list(
			$this->request( array( 'collection' => 'bogus_collection' ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'bogus_collection', $result->get_error_message() );
	}

	/**
	 * Catalog proxy targeted pull cannot leak a hidden id (finding 8): include=
	 * maps to post__in, which WP_Query cannot combine with post__not_in, so the
	 * hidden id must be subtracted from the include list itself.
	 */
	public function test_catalog_proxy_targeted_pull_of_hidden_id_returns_empty(): void {
		$visible = ProductHelper::create_simple_product();
		$hidden  = ProductHelper::create_simple_product();
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $hidden->get_id() ) ),
					),
				),
			)
		);

		$proxy = new Catalog_Proxy_Controller();

		// Targeted pull of ONLY the hidden id returns empty.
		$hidden_only = $proxy->proxy(
			$this->request( array( 'include' => (string) $hidden->get_id() ) ),
			'/wc/v3/products',
			'products'
		);
		$this->assertSame( array(), array_column( (array) $hidden_only->get_data(), 'id' ) );

		// A mixed targeted pull returns the visible id and drops the hidden one.
		$mixed = $proxy->proxy(
			$this->request( array( 'include' => $visible->get_id() . ',' . $hidden->get_id() ) ),
			'/wc/v3/products',
			'products'
		);
		$ids = array_column( (array) $mixed->get_data(), 'id' );
		$this->assertContains( $visible->get_id(), $ids );
		$this->assertNotContains( $hidden->get_id(), $ids );
	}

	/**
	 * The catalog wire normalizes structured meta before revision and identity stamps.
	 */
	public function test_catalog_proxy_emits_typed_meta_with_a_revision_of_the_normalized_record(): void {
		Meta_Normalizer::register_hooks();
		Revision::register_proxy_stamps();
		Proxy_Uuid_Stamper::register_proxy_stampers();

		$product = ProductHelper::create_simple_product();
		$uuid    = Pos_Uuid::ensure_uuid( $product );
		$product->update_meta_data( 'typed_meta_fixture', '{"source":"catalog"}' );
		$product->update_meta_data( 'php_array_fixture', array( 'already' => 'typed' ) );
		$product->save_meta_data();

		$response = ( new Catalog_Proxy_Controller() )->proxy(
			$this->request( array( 'include' => (string) $product->get_id() ) ),
			'/wc/v3/products',
			'products'
		);
		$served = $response->get_data()[0];
		$meta   = array_column( $served['meta_data'], 'value', 'key' );

		$this->assertSame( '{"source":"catalog"}', wp_json_encode( $meta['typed_meta_fixture'] ) );
		$this->assertSame( array( 'already' => 'typed' ), $meta['php_array_fixture'] );
		$this->assertSame( $uuid, $meta[ Pos_Uuid::META_KEY ] );
		$this->assertIsString( $meta[ Pos_Uuid::META_KEY ] );

		// The revision is stamped at priority 9 over the BARE normalized payload —
		// before the uuid stamper rebuilds presentation meta at 10. The write path's
		// CAS recompute (document_for: bare wc/v3 read + normalize) must therefore
		// reproduce it byte-for-byte; assert exactly that parity.
		$revision = $served['_rxdb_revision'];
		$request  = new WP_REST_Request( 'GET', '/' );
		$bare     = rest_get_server()->response_to_data(
			rest_ensure_response(
				( new \WC_REST_Products_Controller() )->prepare_object_for_response(
					wc_get_product( $product->get_id() ),
					$request
				)
			),
			false
		);
		$bare = Meta_Normalizer::normalize( $bare );

		$this->assertSame( Revision::compute( $bare ), $revision );

		// And the revision genuinely covers the normalized bytes: the historical
		// stringified form hashes differently.
		$historical = $bare;
		foreach ( $historical['meta_data'] as $index => $entry ) {
			$key = $entry instanceof \WC_Meta_Data ? $entry->key : $entry['key'];
			if ( 'typed_meta_fixture' === $key ) {
				$historical['meta_data'][ $index ] = array(
					'id'    => $entry instanceof \WC_Meta_Data ? $entry->id : $entry['id'],
					'key'   => $key,
					'value' => '{"source":"catalog"}',
				);
			}
		}
		$this->assertNotSame( Revision::compute( $historical ), $revision );
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
	 * Barcode resolution honours an extension filter that hides a product.
	 *
	 * The v2 lanes read visibility through Sync\Pos_Visibility, which reads through
	 * Services\Settings — so the public `woocommerce_pos_online_only_product_visibility_settings`
	 * filter applies here exactly as it always has on the v1 lanes.
	 */
	public function test_resolve_barcode_excludes_product_hidden_by_extension_filter(): void {
		$product = ProductHelper::create_simple_product();
		$product->set_sku( 'SYNC-FILTER-HIDDEN-PRODUCT' );
		$product->save();
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ) );
		$filter = static function ( $settings ) use ( $product ) {
			$settings['ids'] = array( $product->get_id() );

			return $settings;
		};
		add_filter( 'woocommerce_pos_online_only_product_visibility_settings', $filter );

		try {
			$response = ( new Resolve_Controller() )->resolve_barcode(
				$this->request( array( 'code' => 'SYNC-FILTER-HIDDEN-PRODUCT' ) )
			);
		} finally {
			remove_filter( 'woocommerce_pos_online_only_product_visibility_settings', $filter );
		}
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
