<?php
/**
 * Tests that the sync REST surface does not bleed into wc/v3 requests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Revision;
use WCPOS\WooCommercePOS\Sync\Variable_Prices;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Sync hook isolation tests.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Api
 * @covers \WCPOS\WooCommercePOS\Sync\Status_Controller
 */
class Test_Sync_Hook_Isolation extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Enable the sync feature flag before routes are registered.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		( new Activator() )->install_sync_schema();
		add_filter( 'woocommerce_pos_sync_serialized_product', array( Pos_Uuid::class, 'stamp_serialized_record' ), 10, 3 );
		add_filter( 'woocommerce_pos_sync_serialized_product', array( Variable_Prices::class, 'stamp_serialized_variable_prices' ), 10, 3 );
		add_filter( 'woocommerce_pos_sync_serialized_order', array( Pos_Uuid::class, 'stamp_serialized_record' ), 10, 3 );
		Revision::register_proxy_stamps();
		Proxy_Uuid_Stamper::register_proxy_stampers();
		Integrity_Digest::register_proxy_digest_stampers();
		add_filter( 'woocommerce_pos_sync_proxy_response', array( Variable_Prices::class, 'stamp_proxy_variable_prices' ), 10, 3 );
		Pos_Uuid::register_hooks();

		parent::setUp();
	}

	/**
	 * Remove the sync feature flag after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		// setUp committed the flag before the transaction started; delete it
		// after the rollback or the rollback restores the committed row.
		delete_option( Api::OPTION_ENABLED );
		delete_option( Api::SCHEMA_OPTION );
		remove_all_filters( 'woocommerce_pos_sync_proxy_response' );
		remove_all_filters( 'woocommerce_pos_sync_serialized_product' );
		remove_all_filters( 'woocommerce_pos_sync_serialized_order' );
		remove_action( 'woocommerce_before_product_object_save', array( Pos_Uuid::class, 'stamp_on_save' ), 10 );
		remove_action( 'woocommerce_before_product_variation_object_save', array( Pos_Uuid::class, 'stamp_on_save' ), 10 );
	}

	/**
	 * The enabled sync surface does not alter wc/v3 products or routes.
	 */
	public function test_enabled_sync_surface_does_not_modify_wc_v3_products_or_routes(): void {
		$product = ProductHelper::create_simple_product();

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product->get_id() );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$routes   = $this->server->get_routes();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'healthy', $data );
		$this->assertArrayNotHasKey( 'missing_tables', $data );
		$this->assertArrayNotHasKey( 'schema_version', $data );
		$this->assertArrayHasKey( '/wcpos/v1/sync/status', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/products', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/changes/sequence-log', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/changes/revision-hash', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/changes/range-checksum', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/changes/config-fingerprint', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/digests', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/integrity/scan', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/integrity/bucket', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/variations', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/sync/resolve/barcode', $routes );
		$this->assertArrayNotHasKey( '/wcpos/v1/sync/changes/date-modified', $routes );
		$this->assertArrayNotHasKey( '/wcpos/v1/sync/changes/audit-list', $routes );
		$this->assertArrayNotHasKey( '/wcpos/v1/sync/integrity/rebuild', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/sync', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/sync/status', $routes );
	}

	/**
	 * The catalog proxy stamps sync transport metadata without changing wc/v3.
	 */
	public function test_sync_product_proxy_stamps_uuid_and_revision(): void {
		$product = ProductHelper::create_simple_product();
		$request = $this->wp_rest_get_request( '/wcpos/v1/sync/products' );
		$request->set_param( 'include', array( $product->get_id() ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertArrayHasKey( '_rxdb_revision', $data[0] );
		$this->assertStringStartsWith( 'sha256:', $data[0]['_rxdb_revision'] );
		$this->assertTrue( Pos_Uuid::is_uuid( Pos_Uuid::read_valid_uuid_from_meta( $data[0]['meta_data'] ) ) );
	}

	/**
	 * Revision stamping stays ahead of UUID/digest augmentation.
	 */
	public function test_proxy_stamp_priority_keeps_revision_first(): void {
		$this->assertSame( 9, has_filter( 'woocommerce_pos_sync_proxy_response', array( Revision::class, 'stamp_proxy_revisions' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_pos_sync_proxy_response', array( Integrity_Digest::class, 'stamp_proxy_product_digests' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_pos_sync_proxy_response', array( Variable_Prices::class, 'stamp_proxy_variable_prices' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_pos_sync_serialized_product', array( Pos_Uuid::class, 'stamp_serialized_record' ) ) );
	}
}
