<?php
/**
 * Tests that the sync REST surface does not bleed into wc/v3 requests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Activator;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Proxy_Uuid_Stamper;
use WCPOS\WooCommercePOS\Sync\Revision;
use WCPOS\WooCommercePOS\Sync\Variable_Prices;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WP_REST_Request;

/**
 * Sync hook isolation tests.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Api
 * @covers \WCPOS\WooCommercePOS\API\V2\Status_Controller
 */
class Test_Sync_Hook_Isolation extends WCPOS_REST_Unit_Test_Case {
	/**
	 * Install the sync schema and register route hooks.
	 */
	public function setUp(): void {
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
	 * Restore sync schema and hook state after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		// setUp committed the schema latch before the transaction started; delete
		// it after the rollback or the rollback restores the committed row.
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
		$this->assertArrayHasKey( '/wcpos/v2/status', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/products', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/changes/sequence-log', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/changes/revision-hash', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/changes/range-checksum', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/changes/config-fingerprint', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/digests', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/integrity/scan', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/integrity/bucket', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/integrity/rebuild', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/uuid/backfill', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/variations', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/resolve/barcode', $routes );
		$this->assertArrayNotHasKey( '/wcpos/v2/changes/date-modified', $routes );
		$this->assertArrayNotHasKey( '/wcpos/v2/changes/audit-list', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/sync', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/sync/status', $routes );
	}

	/**
	 * Enabled order proxy hooks neither rewrite plain wc/v3 reads nor leak after a forward.
	 */
	public function test_enabled_sync_surface_does_not_modify_wc_v3_orders_or_routes(): void {
		$cashier_id       = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$other_cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$first_order      = OrderHelper::create_order( array( 'status' => 'pending' ) );
		$second_order     = OrderHelper::create_order( array( 'status' => 'completed' ) );

		$first_order->set_billing_first_name( 'IsolationSearch' );
		$first_order->update_meta_data( '_pos_user', $cashier_id );
		$first_order->save();
		$second_order->set_billing_first_name( 'IsolationSearch' );
		$second_order->update_meta_data( '_pos_user', $other_cashier_id );
		$second_order->save();

		$request = new WP_REST_Request( 'GET', '/wc/v3/orders' );
		$request->set_param( 'status', array( 'any' ) );
		$request->set_param( 'search', 'IsolationSearch' );
		$request->set_param( 'pos_cashier', $cashier_id );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertEqualsCanonicalizing(
			array( $first_order->get_id(), $second_order->get_id() ),
			array_map( 'intval', wp_list_pluck( $data, 'id' ) ),
			'A plain wc/v3 request must ignore WCPOS-only cashier filtering.'
		);
		foreach ( $data as $order ) {
			$this->assertArrayNotHasKey( 'links', $order, 'wc/v3 must not receive WCPOS order links.' );
			$this->assertArrayHasKey( '_links', $order );
			$this->assertArrayNotHasKey( 'payment', $order['_links'] );
			$this->assertArrayNotHasKey( 'receipt', $order['_links'] );
			$this->assertSame(
				'',
				Pos_Uuid::read_valid_uuid_from_meta( $order['meta_data'] ?? array() ),
				'An order WCPOS never proxied must not be UUID-stamped in a wc/v3 response.'
			);
		}
		$this->assertSame( '', wc_get_order( $first_order->get_id() )->get_meta( Pos_Uuid::META_KEY ) );
		$this->assertSame( '', wc_get_order( $second_order->get_id() )->get_meta( Pos_Uuid::META_KEY ) );

		$proxied_order = OrderHelper::create_order();
		$proxied_order->update_meta_data( '_pos_user', $cashier_id );
		$proxied_order->save();
		$proxy_request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$proxy_request->set_param( 'include', array( $proxied_order->get_id() ) );
		$proxy_request->set_param( 'pos_cashier', $cashier_id );
		$proxy_response = $this->server->dispatch( $proxy_request );

		$this->assertSame( 200, $proxy_response->get_status(), wp_json_encode( $proxy_response->get_data() ) );
		$this->assertSame( array( $proxied_order->get_id() ), array_map( 'intval', wp_list_pluck( $proxy_response->get_data(), 'id' ) ) );

		$captured_args = null;
		$collector     = static function ( array $args ) use ( &$captured_args ): array {
			$captured_args = $args;
			return $args;
		};
		add_filter( 'woocommerce_rest_shop_order_object_query', $collector, 999, 2 );

		$leak_request = new WP_REST_Request( 'GET', '/wc/v3/orders' );
		$leak_request->set_param( 'status', array( 'any' ) );
		$leak_request->set_param( 'search', 'IsolationSearch' );
		$leak_request->set_param( 'pos_cashier', $cashier_id );
		try {
			$leak_response = $this->server->dispatch( $leak_request );
			$leak_data     = $leak_response->get_data();
		} finally {
			remove_filter( 'woocommerce_rest_shop_order_object_query', $collector, 999 );
		}

		$this->assertSame( 200, $leak_response->get_status(), wp_json_encode( $leak_data ) );
		$this->assertEqualsCanonicalizing(
			array( $first_order->get_id(), $second_order->get_id() ),
			array_map( 'intval', wp_list_pluck( $leak_data, 'id' ) ),
			'The proxy cashier filter must be removed before the next wc/v3 request.'
		);
		$this->assertIsArray( $captured_args );
		foreach ( $captured_args['meta_query'] ?? array() as $clause ) {
			$this->assertNotSame( '_pos_user', $clause['key'] ?? null, 'The proxy cashier meta query leaked into wc/v3.' );
		}

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v2/orders', $routes );
		$this->assertArrayNotHasKey( '/wc/v3/sync', $routes );
	}

	/**
	 * Enabled term/tax proxy hooks do not augment or filter plain wc/v3 reads.
	 */
	public function test_enabled_sync_surface_does_not_modify_wc_v3_terms_or_taxes(): void {
		$category = wp_insert_term( 'Sync Isolation Category', 'product_cat' );
		$this->assertNotWPError( $category );
		$category_id = (int) $category['term_id'];

		$category_request = new WP_REST_Request( 'GET', '/wc/v3/products/categories' );
		$category_request->set_param( 'include', array( $category_id ) );
		$category_response = $this->server->dispatch( $category_request );
		$category_data     = $category_response->get_data();

		$this->assertSame( 200, $category_response->get_status(), wp_json_encode( $category_data ) );
		$this->assertCount( 1, $category_data );
		$this->assertSame( $category_id, (int) $category_data[0]['id'] );
		$this->assertArrayNotHasKey( 'uuid', $category_data[0] );
		$this->assertArrayNotHasKey( 'meta_data', $category_data[0], 'wc/v3 categories must not receive sync UUID metadata.' );
		$this->assertSame( '', get_term_meta( $category_id, Pos_Uuid::META_KEY, true ) );

		$included_tax_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'CA',
				'rate'    => '7.250',
				'name'    => 'Isolation Included Tax',
			)
		);
		$excluded_tax_id = TaxHelper::create_tax_rate(
			array(
				'country' => 'US',
				'state'   => 'NY',
				'rate'    => '8.875',
				'name'    => 'Isolation Excluded Tax',
			)
		);

		$tax_request = new WP_REST_Request( 'GET', '/wc/v3/taxes' );
		$tax_request->set_param( 'include', array( $included_tax_id ) );
		$tax_request->set_param( 'exclude', array( $excluded_tax_id ) );
		$tax_response = $this->server->dispatch( $tax_request );
		$tax_data     = $tax_response->get_data();
		$tax_ids      = array_map( 'intval', wp_list_pluck( $tax_data, 'id' ) );

		$this->assertSame( 200, $tax_response->get_status(), wp_json_encode( $tax_data ) );
		$this->assertContains( $included_tax_id, $tax_ids );
		$this->assertContains( $excluded_tax_id, $tax_ids, 'wc/v3 taxes must ignore WCPOS-only include/exclude filtering.' );
		foreach ( $tax_data as $tax ) {
			$this->assertArrayNotHasKey( 'uuid', $tax );
			$this->assertArrayNotHasKey( 'meta_data', $tax );
			$this->assertArrayNotHasKey( '_rxdb_revision', $tax );
			$this->assertArrayNotHasKey( '_integrity_digest', $tax );
		}

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wcpos/v2/products/categories', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/taxes', $routes );
	}

	/**
	 * The catalog proxy stamps sync transport metadata without changing wc/v3.
	 */
	public function test_sync_product_proxy_stamps_uuid_and_revision(): void {
		$product = ProductHelper::create_simple_product();
		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
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
	 * The v2 list proxy replaces a simple product's stale parent price after conversion.
	 */
	public function test_sync_product_proxy_uses_current_variation_price_after_conversion(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => '102',
				'price'         => '102',
			)
		);
		$attribute_data = ProductHelper::create_attribute( 'size', array( 'large' ) );
		$attribute      = new \WC_Product_Attribute();
		$attribute->set_id( $attribute_data['attribute_id'] );
		$attribute->set_name( $attribute_data['attribute_taxonomy'] );
		$attribute->set_options( $attribute_data['term_ids'] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$variable_product = new \WC_Product_Variable( $product->get_id() );
		$variable_product->set_attributes( array( $attribute ) );
		$variable_product->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $variable_product->get_id() );
		$variation->set_regular_price( '114' );
		$variation->set_attributes( array( 'pa_size' => 'large' ) );
		$variation->save();

		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_param( 'include', array( $variable_product->get_id() ) );
		$request->set_param( 'dp', 2 );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data()[0];

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( '114.00', $data['price'] );
		$this->assertSame( '', $data['regular_price'] );
		$this->assertSame( '', $data['sale_price'] );
	}

	/**
	 * Product mutation acknowledgements use the same variable-price serializer as reads.
	 */
	public function test_product_mutation_reread_uses_current_variation_price(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => '102',
				'price'         => '102',
			)
		);
		$attribute_data = ProductHelper::create_attribute( 'format', array( 'large' ) );
		$attribute      = new \WC_Product_Attribute();
		$attribute->set_id( $attribute_data['attribute_id'] );
		$attribute->set_name( $attribute_data['attribute_taxonomy'] );
		$attribute->set_options( $attribute_data['term_ids'] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$variable_product = new \WC_Product_Variable( $product->get_id() );
		$variable_product->set_attributes( array( $attribute ) );
		$variable_product->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $variable_product->get_id() );
		$variation->set_regular_price( '114' );
		$variation->set_attributes( array( 'pa_format' => 'large' ) );
		$variation->save();

		$controller = new Write_Controller();
		$document   = new \ReflectionMethod( $controller, 'document_for' );
		$document->setAccessible( true );
		$meta = array(
			'route'     => '/wc/v3/products',
			'id_type'   => 'post',
			'post_type' => 'product',
		);
		$raw_document = $document->invoke(
			$controller,
			$meta,
			$variable_product->get_id()
		);
		$envelope = new \ReflectionMethod( $controller, 'envelope_document' );
		$envelope->setAccessible( true );
		$response = $envelope->invoke(
			$controller,
			$raw_document,
			'20000000-0000-4000-8000-000000000099',
			$meta,
			$variable_product->get_id()
		);
		$data = $response->get_data();

		$request = $this->wp_rest_get_request( '/wcpos/v2/products' );
		$request->set_param( 'include', array( $variable_product->get_id() ) );
		$catalog_response = $this->server->dispatch( $request );
		$catalog_product  = $catalog_response->get_data()[0];

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( '114.00', $data['document']['price'] );
		$this->assertSame( '', $data['document']['regular_price'] );
		$this->assertSame( '', $data['document']['sale_price'] );
		$this->assertSame( $catalog_product['_rxdb_revision'], $data['currentRevision'] );
	}

	/**
	 * Revision stamping stays ahead of UUID/digest augmentation.
	 */
	public function test_proxy_stamp_priority_keeps_revision_first(): void {
		$this->assertSame( 9, has_filter( 'woocommerce_pos_sync_proxy_response', array( Revision::class, 'stamp_proxy_revisions' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_pos_sync_proxy_response', array( Integrity_Digest::class, 'stamp_digests' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_pos_sync_proxy_response', array( Variable_Prices::class, 'stamp_proxy_variable_prices' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_pos_sync_serialized_product', array( Pos_Uuid::class, 'stamp_serialized_record' ) ) );
	}
}
