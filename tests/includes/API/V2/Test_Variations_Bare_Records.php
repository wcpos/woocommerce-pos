<?php
/**
 * Bare variation records and schema-scoped CAS revisions (#1869).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\V2\Writers\Variation_Writer;
use WCPOS\WooCommercePOS\Sync\Augmentation_Pipeline;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Meta_Entry;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Product_Serializer;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Revision;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Pins the same bytes on collection, write acknowledgement, resolve, and CAS. */
class Test_Variations_Bare_Records extends WCPOS_REST_Unit_Test_Case {
	/** Install stores outside the per-test transaction. */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		( new Sync_Journal() )->install();
		( new Integrity_Digest() )->install();
		( new Mutation_Store() )->install();
	}

	/** Exercise the production augmentation pipeline. */
	public function setUp(): void {
		parent::setUp();
		Augmentation_Pipeline::install();
		Meta_Normalizer::register_hooks();
	}

	/** Remove all installed stamps and custom revision fields. */
	public function tearDown(): void {
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'no' );
		Augmentation_Pipeline::reset();
		Meta_Normalizer::unregister_hooks();
		Revision::unregister_proxy_stamps();
		Proxy_Uuid_Stamper::unregister_proxy_stampers();
		remove_filter( 'woocommerce_pos_sync_proxy_response', array( Integrity_Digest::class, 'stamp_digests' ), 10 );
		remove_filter( 'woocommerce_pos_sync_order_pull_payloads', array( Integrity_Digest::class, 'stamp_digests' ), 10 );
		remove_all_filters( 'woocommerce_pos_sync_variation_revision_fields' );
		// WordPress has no unregister_rest_field(); drop the entry from the registry directly.
		unset( $GLOBALS['wp_rest_additional_fields']['product_variation']['revision_extra'] );
		parent::tearDown();
	}

	/** Create an identified, digest-indexed variation with a unique barcode. */
	private function variation(): \WC_Product_Variation {
		$parent = ProductHelper::create_variation_product();
		$variation = new \WC_Product_Variation( current( $parent->get_children() ) );
		$variation->set_sku( wp_generate_uuid4() );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 10 );
		$variation->save();
		Pos_Uuid::ensure_uuid( $variation );
		( new Integrity_Digest() )->upsert_digest( $variation->get_id() );
		return $variation;
	}

	/** Read a public route with query parameters. */
	private function read( string $route, array $params ): array {
		$request = $this->wp_rest_get_request( '/wcpos/v2/' . $route );
		$request->set_query_params( $params );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return json_decode( wp_json_encode( $response->get_data() ), true );
	}

	/** Read the flat lane's client-visible record. */
	private function flat( $variation ): array {
		return $this->read( 'variations', array( 'include' => array( $variation->get_id() ) ) )[0];
	}

	/** Dispatch a real CAS update, with a new mutation id on every attempt. */
	private function push( $variation, string $revision, array $payload, string $collection = 'variations' ): \WP_REST_Response {
		$id = wp_generate_uuid4();
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/' . $collection );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $id );
		$request->set_body( wp_json_encode( array(
			'mutationId' => $id,
			'operation' => 'update',
			'collection' => $collection,
			'recordId' => get_post_meta( $variation->get_id(), Pos_Uuid::META_KEY, true ),
			'baseRevision' => $revision,
			'payload' => $payload,
		) ) );
		return $this->server->dispatch( $request );
	}

	/** Pin schema fields, known WooCommerce schema omissions, and only transport deltas. */
	private function assert_shape( array $record ): void {
		$fields = array_keys( ( new \WC_REST_Product_Variations_Controller() )->get_item_schema()['properties'] );
		$expected = array_unique( array_merge( $fields, array( 'date_created_gmt', 'date_modified_gmt', 'name', '_links', '_rxdb_revision', '_rxdb_digest' ) ) );
		sort( $expected );
		$actual = array_keys( $record );
		sort( $actual );
		$this->assertSame( $expected, $actual );
		$uuids = array_values( array_filter( $record['meta_data'], static fn( $entry ) => Pos_Uuid::META_KEY === Meta_Entry::key( $entry ) ) );
		$this->assertCount( 1, $uuids );
		$this->assertTrue( Pos_Uuid::is_uuid( $uuids[0]['value'] ) );
		$this->assertNotSame( '', $record['_rxdb_digest'] );
		$this->assertArrayNotHasKey( 'payload', $record );
	}

	/** REST extension noise is excluded until a site explicitly opts into hashing it. */
	public function test_revision_registered_field_is_ignored_until_allowlisted_on_all_lanes(): void {
		// Arrange.
		$variation = $this->variation();
		$extra = 'first-value';
		register_rest_field( 'product_variation', 'revision_extra', array(
			'get_callback' => static function () use ( &$extra ) {
				return $extra;
			},
			'schema' => array( 'type' => 'string', 'context' => array( 'view', 'edit' ), 'readonly' => true ),
		) );
		// Act: registration precedes the first hash, as on a real WordPress request.
		$before = $this->flat( $variation );
		$extra = 'site-value';
		$noise = $this->flat( $variation );
		add_filter( 'woocommerce_pos_sync_variation_revision_fields', static fn( $fields ) => array_merge( $fields, array( 'revision_extra' ) ) );
		$scoped = $this->flat( $variation );
		$stale = $this->push( $variation, $before['_rxdb_revision'], array( 'stock_quantity' => 9 ) );
		$ack = $this->push( $variation, $scoped['_rxdb_revision'], array( 'stock_quantity' => 9 ) );
		// Assert.
		$this->assertSame( 'site-value', $noise['revision_extra'] );
		$this->assertSame( $before['_rxdb_revision'], $noise['_rxdb_revision'] );
		$this->assertNotSame( $noise['_rxdb_revision'], $scoped['_rxdb_revision'] );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 200, $ack->get_status(), wp_json_encode( $ack->get_data() ) );
		$this->assert_parity( $variation, $ack->get_data() );
	}

	/** Inherited tax class changes representation, not the CAS revision. */
	public function test_revision_is_context_independent_for_inherited_tax_class(): void {
		// Arrange.
		$variation = $this->variation();
		if ( ! in_array( 'reduced-rate', \WC_Tax::get_tax_class_slugs(), true ) ) {
			\WC_Tax::create_tax_class( 'Reduced rate', 'reduced-rate' );
		}
		$parent = wc_get_product( $variation->get_parent_id() );
		$parent->set_tax_class( 'reduced-rate' );
		$parent->save();
		$variation->set_tax_class( 'parent' ); // Empty string means Standard, not inheritance.
		$variation->save();
		// Act.
		$params = array( 'include' => array( $variation->get_id() ) );
		$params['context'] = 'edit';
		$edit = $this->read( 'variations', $params )[0];
		$view = $this->flat( $variation );
		$ack = $this->push( $variation, $edit['_rxdb_revision'], array( 'stock_quantity' => 9 ) );
		// Assert.
		$this->assertSame( 'parent', $edit['tax_class'] );
		$this->assertSame( 'reduced-rate', $view['tax_class'] );
		$this->assertNotSame( $edit['tax_class'], $view['tax_class'] );
		$this->assertSame( $view['_rxdb_revision'], $edit['_rxdb_revision'] );
		$this->assertSame( 200, $ack->get_status(), wp_json_encode( $ack->get_data() ) );
	}

	/** Prepare filters can vary the response without poisoning the CAS revision. */
	public function test_revision_ignores_request_sensitive_prepare_filter(): void {
		// Arrange.
		$variation = $this->variation();
		$filter = static function ( $response, $object, $request ) {
			if ( 1 === (int) $request->get_param( 'marker' ) ) {
				$data = $response->get_data();
				$data['description'] = 'marker=' . $request->get_param( 'marker' );
				$response->set_data( $data );
			}
			return $response;
		};
		add_filter( 'woocommerce_rest_prepare_product_variation_object', $filter, 10, 3 );
		try {
			// Act: a no-op write keeps the canonical record unchanged.
			$params = array( 'include' => array( $variation->get_id() ) );
			$params['marker'] = 1;
			$marked = $this->read( 'variations', $params )[0];
			$plain = $this->flat( $variation );
			$ack = $this->push( $variation, $marked['_rxdb_revision'], array( 'stock_quantity' => 10 ) );
			// Assert.
			$this->assertSame( 'marker=1', $marked['description'] );
			$this->assertNotSame( $plain['description'], $marked['description'] );
			$this->assertSame( $plain['_rxdb_revision'], $marked['_rxdb_revision'] );
			$this->assertSame( 200, $ack->get_status(), wp_json_encode( $ack->get_data() ) );
			$this->assertSame( $marked['_rxdb_revision'], $ack->get_data()['currentRevision'] );
		} finally {
			remove_filter( 'woocommerce_rest_prepare_product_variation_object', $filter, 10 );
		}
	}

	/** The flat row and real write acknowledgement have exactly the same field delta. */
	public function test_bare_records_flat_and_write_ack_preserve_schema_and_stamps(): void {
		// Arrange.
		$variation = $this->variation();
		// Act.
		$flat = $this->flat( $variation );
		$ack = $this->push( $variation, $flat['_rxdb_revision'], array( 'stock_quantity' => 9 ) );
		// Assert.
		$this->assert_shape( $flat );
		$this->assertSame( 200, $ack->get_status(), wp_json_encode( $ack->get_data() ) );
		$this->assert_shape( $ack->get_data()['document'] );
		$this->assertSame( $ack->get_data()['currentRevision'], $ack->get_data()['document']['_rxdb_revision'] );
		$this->assert_parity( $variation, $ack->get_data() );
		$stale = $this->push( $variation, $flat['_rxdb_revision'], array( 'stock_quantity' => 8 ) );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 9, wc_get_product( $variation->get_id() )->get_stock_quantity() );
	}

	/** All read lanes agree with the acknowledgement for settled state. */
	private function assert_parity( $variation, array $ack ): void {
		$flat = $this->flat( $variation );
		$match = $this->read( 'resolve/barcode', array( 'code' => $variation->get_sku() ) )['match'];
		$this->assertSame( $ack['currentRevision'], $flat['_rxdb_revision'] );
		$this->assertSame( $flat['_rxdb_revision'], $match['_rxdb_revision'] );
		$this->assertSame( $flat, $match );
		$this->assertSame( $variation->get_parent_id(), $match['parent_id'] );
		$this->assertArrayNotHasKey( 'payload', $match );
	}

	/** Product barcode matches preserve the product's schema type, not the old discriminator. */
	public function test_barcode_product_match_is_bare_and_matches_collection(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$product->set_sku( wp_generate_uuid4() );
		$product->update_meta_data( 'structured_note', '{"note":"normalized before hashing"}' );
		$product->save();
		( new Integrity_Digest() )->upsert_digest( $product->get_id() );
		// Act.
		$row = $this->read( 'products', array( 'include' => array( $product->get_id() ) ) )[0];
		$match = $this->read( 'resolve/barcode', array( 'code' => $product->get_sku() ) )['match'];
		// Assert.
		// WooCommerce shuffles related_ids on every read; it is outside the revision recipe too.
		unset( $row['related_ids'], $match['related_ids'] );
		ksort( $row );
		ksort( $match );
		$this->assertSame( $row, $match );
		$this->assertSame( 'simple', $match['type'] );
		$this->assertArrayNotHasKey( 'payload', $match );
	}

	/** Product barcode revisions must use the dispatched read that product CAS hashes. */
	public function test_product_barcode_revision_matches_cas_with_request_sensitive_filter(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$product->set_sku( wp_generate_uuid4() );
		$product->save();
		Pos_Uuid::ensure_uuid( $product );
		$filter = static function ( $response, $object, $request ) {
			if ( '/' === $request->get_route() ) {
				$data = $response->get_data();
				$data['description'] = 'Undispatched product representation';
				$response->set_data( $data );
			}
			return $response;
		};
		add_filter( 'woocommerce_rest_prepare_product_object', $filter, 10, 3 );
		try {
			// Act.
			$match = $this->read( 'resolve/barcode', array( 'code' => $product->get_sku() ) )['match'];
			$ack = $this->push( $product, $match['_rxdb_revision'], array( 'name' => 'Barcode CAS update' ), 'products' );
			// Assert.
			$this->assertSame( 200, $ack->get_status(), wp_json_encode( $ack->get_data() ) );
			$stale = $this->push( $product, $match['_rxdb_revision'], array( 'name' => 'Stale update' ), 'products' );
			$this->assertSame( 409, $stale->get_status() );
		} finally {
			remove_filter( 'woocommerce_rest_prepare_product_object', $filter, 10 );
		}
	}

	/** Enabling COGS in one process must not reuse schema keys captured while disabled. */
	public function test_variation_revision_tracks_cogs_enabled_in_one_process(): void {
		// Arrange.
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'no' );
		$variation = $this->variation();
		$serializer = new Product_Serializer();
		$before = Revision::compute_variation( $serializer->bare_for_revision( $variation ) );
		// Act: do not save, so timestamps and metadata cannot mask a COGS-only change.
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'yes' );
		$variation->set_cogs_value( 12.0 );
		$bare = $serializer->bare_for_revision( $variation );
		$after = Revision::compute_variation( $bare );
		// Assert.
		$this->assertArrayHasKey( 'cost_of_goods_sold', $bare );
		$this->assertNotSame( $before, $after );
	}

	/** Bare writer bytes do not contain transport stamps; stamping cannot change the hash. */
	public function test_revision_transport_stamping_leaves_bare_hash_unchanged(): void {
		// Arrange.
		$variation = $this->variation();
		$variation->update_meta_data( 'structured_note', '{"note":"normalized before hashing"}' );
		$variation->save_meta_data();
		$writer = new Variation_Writer();
		// Act.
		$bare = $writer->document( array(), $variation->get_id(), static function () {} )->get_data();
		$stamped = $this->flat( $variation );
		// Assert.
		$this->assertArrayNotHasKey( '_rxdb_digest', $bare );
		$this->assertArrayNotHasKey( '_rxdb_revision', $bare );
		// The stored uuid meta may ride the bare bytes; the recipe strips it, which the parity below proves.
		$this->assertSame( Revision::compute_variation( $bare ), Revision::compute_variation( $stamped ) );
	}

	/** Meta-only and same-second edits must not collapse to a date revision. */
	public function test_revision_meta_only_and_same_second_edits_change_hash(): void {
		// Arrange.
		$variation = $this->variation();
		$before = $this->flat( $variation );
		// Act: save only meta so both edits have exactly the same modified date.
		$variation->update_meta_data( 'till_note', 'first' );
		$variation->save_meta_data();
		$first = $this->flat( $variation );
		$variation->update_meta_data( 'till_note', 'second' );
		$variation->save_meta_data();
		$second = $this->flat( $variation );
		// Assert.
		$this->assertSame( $before['date_modified_gmt'], $first['date_modified_gmt'] );
		$this->assertSame( $first['date_modified_gmt'], $second['date_modified_gmt'] );
		$this->assertNotSame( $before['_rxdb_revision'], $first['_rxdb_revision'] );
		$this->assertNotSame( $first['_rxdb_revision'], $second['_rxdb_revision'] );
	}

	/** Two stock edits with one stored modified second still produce distinct revisions. */
	public function test_revision_same_second_stock_edits_change_hash(): void {
		// Arrange.
		$variation = $this->variation();
		// Act: direct stock meta updates keep the exact same post_modified timestamp.
		update_post_meta( $variation->get_id(), '_stock', 9 );
		$first = $this->flat( $variation );
		update_post_meta( $variation->get_id(), '_stock', 8 );
		$second = $this->flat( $variation );
		// Assert.
		$this->assertSame( $first['date_modified_gmt'], $second['date_modified_gmt'] );
		$this->assertNotSame( $first['_rxdb_revision'], $second['_rxdb_revision'] );
	}

}
