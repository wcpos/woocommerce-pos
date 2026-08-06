<?php
/**
 * REST-dispatched sync write contract tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions -- Ported lab suite preserves its fake-store vocabulary and compact scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;
use WP_REST_Response;

/** In-memory store used only to drive golden envelopes through real REST dispatch. */
final class Dispatch_Fake_Mutation_Store {
	public $resolve = 0;
	public $resolve_results = array();
	public $persisted = array();
	public $finalized = array();
	public $lookups = array();

	public function lookup( string $collection, string $mutation_id ): ?array {
		return $this->lookups[ $mutation_id ] ?? null;
	}
	public function reserve( string $collection, string $mutation_id, string $record_uuid, string $operation, string $fingerprint = '' ): bool {
		if ( isset( $this->lookups[ $mutation_id ] ) ) {
			return false;
		}
		$this->lookups[ $mutation_id ] = array(
			'collection' => $collection,
			'record_uuid' => $record_uuid,
			'operation' => $operation,
			'fingerprint' => $fingerprint,
			'status' => 'pending',
		);
		return true;
	}
	public function acquire_record_lock( string $collection, string $uuid ): bool {
		return true;
	}
	public function release_record_lock( string $collection, string $uuid ): void {}
	public function resolve_id_by_uuid( string $id_type, string $uuid, array $opts = array() ) {
		return array() !== $this->resolve_results ? array_shift( $this->resolve_results ) : $this->resolve;
	}
	public function persist_uuid( string $id_type, int $id, string $uuid ): bool {
		$this->persisted[] = compact( 'id_type', 'id', 'uuid' );
		$this->resolve     = $id;
		return true;
	}
	public function persist_order_audit_meta( int $id, array $meta, string $created_via = '' ): void {}
	public function mark_applied( string $mutation_id, int $remote_id, int $response_status ): bool {
		return true;
	}
	public function mark_poison( string $mutation_id, int $remote_id, int $response_status = 201 ): bool {
		return true;
	}
	public function mark_indeterminate( string $mutation_id, int $remote_id, int $response_status ): bool {
		return true;
	}
	public function finalize( string $mutation_id, int $remote_id ): bool {
		$this->finalized[ $mutation_id ] = $remote_id;
		return true;
	}
	public function finalize_poison( string $mutation_id, int $remote_id ): bool {
		$this->finalized[ $mutation_id ] = $remote_id;
		return true;
	}
	public function release( string $mutation_id ): void {
		if ( 'pending' === ( $this->lookups[ $mutation_id ]['status'] ?? '' ) ) {
			unset( $this->lookups[ $mutation_id ] );
		}
	}
	public function reclaim_stale( string $mutation_id, int $ttl ): bool {
		return false;
	}
	public function reservation_ttl(): int {
		return 900;
	}
}

/** Injects the per-test fake store while preserving production route wiring. */
final class Dispatch_Write_Controller extends Write_Controller {
	/** @var Dispatch_Fake_Mutation_Store */
	public static $store;

	public function __construct() {
		parent::__construct( self::$store );
	}
}

/**
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Rest_Dispatch_Write_Contract extends Sync_REST_Store_Test_Case {
	/** @var Dispatch_Fake_Mutation_Store */
	private $store;
	private $previous_manage_stock_option;
	private $previous_general_settings;

	public function setUp(): void {
		$this->previous_manage_stock_option = get_option( 'woocommerce_manage_stock', 'no' );
		$this->previous_general_settings    = get_option( 'woocommerce_pos_settings_general', null );
		$this->store = new Dispatch_Fake_Mutation_Store();
		Dispatch_Write_Controller::$store = $this->store;
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		add_filter( 'rest_pre_dispatch', array( $this, 'intercept_wc_request' ), 1, 3 );
	}

	public function rest_api_init(): void {
		$inject = static function ( array $controllers ): array {
			$controllers['sync-write'] = Dispatch_Write_Controller::class;

			return $controllers;
		};
		add_filter( 'woocommerce_pos_rest_api_controllers', $inject, 99 );
		parent::rest_api_init();
		remove_filter( 'woocommerce_pos_rest_api_controllers', $inject, 99 );
	}

	public function tearDown(): void {
		remove_filter( 'rest_pre_dispatch', array( $this, 'intercept_wc_request' ), 1 );
		remove_filter( 'woocommerce_pos_restore_stock_on_delete', '__return_true' );
		update_option( 'woocommerce_manage_stock', $this->previous_manage_stock_option );
		if ( null === $this->previous_general_settings ) {
			delete_option( 'woocommerce_pos_settings_general' );
		} else {
			update_option( 'woocommerce_pos_settings_general', $this->previous_general_settings );
		}
		unset( $GLOBALS['wcpos_sync_contract_responses'], $GLOBALS['wcpos_sync_contract_calls'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	public function intercept_wc_request( $result, $server, WP_REST_Request $request ) {
		if ( 0 !== strpos( $request->get_route(), '/wc/v3/' ) ) {
			return $result;
		}
		$GLOBALS['wcpos_sync_contract_calls'][] = $request;
		if ( ! empty( $GLOBALS['wcpos_sync_contract_responses'] ) ) {
			return array_shift( $GLOBALS['wcpos_sync_contract_responses'] );
		}

		return $result;
	}

	private function fixtures( string $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/write-contract/fixtures/' . $name . '.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function fixture( string $name ): array {
		return array_column( $this->fixtures( 'valid-envelopes' ), null, 'name' )[ $name ];
	}

	private function request( string $collection, array $envelope, array $headers = array() ): WP_REST_Request {
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'push/' . $collection );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $request;
	}

	private function stock_order_delete_request( bool $restore_stock = true ): array {
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_pos_settings_general', array( 'restore_stock_on_delete' => $restore_stock ) );
		$product = ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => 10,
				'regular_price'  => 10,
			)
		);
		$order = OrderHelper::create_order( array( 'product' => $product ) );
		$order->set_status( 'completed' );
		$order->save();
		wc_maybe_reduce_stock_levels( $order->get_id() );
		$this->assertEquals( 6, wc_get_product( $product->get_id() )->get_stock_quantity() );

		// The client's held revision is computed over a WCPOS-lane document,
		// and every WCPOS order surface serializes money at six decimals.
		$current_request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() );
		$current_request->set_param( 'dp', '6' );
		$current = rest_do_request( $current_request )->get_data();
		$current = Meta_Normalizer::normalize( $current );
		$current = Order_Serializer::add_payment_link( $current, $order );
		$revision = Order_Serializer::canonical_revision( $current );
		$envelope = array(
			'mutationId'   => '10000000-0000-4000-8000-000000000071',
			'operation'    => 'delete',
			'collection'   => 'orders',
			'recordId'     => '20000000-0000-4000-8000-000000000071',
			'baseRevision' => $revision,
		);
		$headers = array(
			'Idempotency-Key' => $envelope['mutationId'],
			'If-Match'        => '"' . $revision . '"',
		);
		$this->store->resolve = $order->get_id();
		$GLOBALS['wcpos_sync_contract_calls'] = array();

		return array( $product->get_id(), $order->get_id(), $this->request( 'orders', $envelope, $headers ) );
	}

	public function test_order_force_delete_restores_stock_when_enabled(): void {
		list( $product_id, $order_id, $request ) = $this->stock_order_delete_request();

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( wc_get_order( $order_id ) );
		$this->assertEquals( 10, wc_get_product( $product_id )->get_stock_quantity() );
	}

	public function test_order_force_delete_does_not_restore_stock_when_disabled(): void {
		list( $product_id, $order_id, $request ) = $this->stock_order_delete_request( false );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( wc_get_order( $order_id ) );
		$this->assertEquals( 6, wc_get_product( $product_id )->get_stock_quantity() );
	}

	public function test_order_force_delete_filter_overrides_disabled_setting(): void {
		add_filter( 'woocommerce_pos_restore_stock_on_delete', '__return_true' );
		list( $product_id, $order_id, $request ) = $this->stock_order_delete_request( false );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( wc_get_order( $order_id ) );
		$this->assertEquals( 10, wc_get_product( $product_id )->get_stock_quantity() );
	}

	public function test_failed_order_force_delete_rolls_back_stock_restore(): void {
		list( $product_id, $order_id, $request ) = $this->stock_order_delete_request();
		$fail_delete = static function ( $result, $server, WP_REST_Request $forwarded ) {
			if ( 'DELETE' === $forwarded->get_method() && 0 === strpos( $forwarded->get_route(), '/wc/v3/orders/' ) ) {
				return new WP_REST_Response( array( 'code' => 'delete_failed' ), 500 );
			}

			return $result;
		};
		add_filter( 'rest_pre_dispatch', $fail_delete, 2, 3 );
		try {
			$response = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'rest_pre_dispatch', $fail_delete, 2 );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $order_id ) );
		$this->assertEquals( 6, wc_get_product( $product_id )->get_stock_quantity() );
	}

	public function test_dispatch_fake_reservation_retains_envelope_and_rejects_reuse(): void {
		$this->assertTrue( $this->store->reserve( 'products', 'mutation-1', 'record-1', 'create', 'fingerprint-1' ) );
		$this->assertSame(
			array(
				'collection' => 'products',
				'record_uuid' => 'record-1',
				'operation' => 'create',
				'fingerprint' => 'fingerprint-1',
				'status' => 'pending',
			),
			$this->store->lookup( 'products', 'mutation-1' )
		);
		$this->assertFalse( $this->store->reserve( 'products', 'mutation-1', 'record-1', 'create', 'fingerprint-1' ) );
		$this->assertFalse( $this->store->reserve( 'orders', 'mutation-1', 'record-2', 'delete', 'fingerprint-2' ) );
		$this->store->release( 'mutation-1' );
		$this->assertTrue( $this->store->reserve( 'products', 'mutation-1', 'record-1', 'create', 'fingerprint-1' ) );
	}

	public function test_valid_golden_create_round_trips_through_rest_dispatch(): void {
		$fixture              = $this->fixture( 'product-create' );
		$this->store->resolve = 41;
		$GLOBALS['wcpos_sync_contract_responses'] = array(
			new WP_REST_Response(
				array(
					'id' => 41,
					'name' => 'Fixture product',
					'meta_data' => $fixture['envelope']['payload']['meta_data'],
				),
				200
			),
		);

		$response = $this->server->dispatch( $this->request( 'products', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 41, $response->get_data()['document']['id'] );
	}

	public function test_create_response_document_emits_typed_meta_and_plain_uuid(): void {
		$fixture = $this->fixture( 'product-create' );
		$bare    = array(
			'id' => 501,
			'name' => 'Typed product',
			'meta_data' => array(
				array( 'key' => 'typed_meta_fixture', 'value' => '{"source":"create"}' ),
				array( 'key' => 'php_array_fixture', 'value' => array( 'already' => 'typed' ) ),
			),
		);
		$this->store->resolve_results             = array( 0, 501 );
		$GLOBALS['wcpos_sync_contract_responses'] = array(
			new WP_REST_Response( array( 'id' => 501 ), 201 ),
			new WP_REST_Response( $bare, 200 ),
		);

		$response = $this->server->dispatch( $this->request( 'products', $fixture['envelope'], $fixture['headers'] ) );
		$data     = $response->get_data();
		$meta     = array_column( $data['document']['meta_data'], 'value', 'key' );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '{"source":"create"}', wp_json_encode( $meta['typed_meta_fixture'] ) );
		$this->assertSame( array( 'already' => 'typed' ), $meta['php_array_fixture'] );
		$this->assertSame( $fixture['envelope']['recordId'], $meta[ Pos_Uuid::META_KEY ] );
		$this->assertIsString( $meta[ Pos_Uuid::META_KEY ] );
		$this->assertSame( Revision::compute( Meta_Normalizer::normalize( $bare ) ), $data['currentRevision'] );
	}

	public function test_update_response_document_emits_typed_meta_and_plain_uuid(): void {
		$current = array(
			'id' => 601,
			'code' => 'save10',
			'amount' => '10.00',
			'meta_data' => array(
				array( 'key' => 'typed_meta_fixture', 'value' => '{"source":"before"}' ),
			),
		);
		$updated = array(
			'id' => 601,
			'code' => 'save10',
			'amount' => '12.00',
			'meta_data' => array(
				array( 'key' => 'typed_meta_fixture', 'value' => '["after"]' ),
			),
		);
		$revision            = Revision::compute( Meta_Normalizer::normalize( $current ) );
		$this->store->resolve = 601;
		$GLOBALS['wcpos_sync_contract_responses'] = array(
			new WP_REST_Response( $current, 200 ),
			new WP_REST_Response( array( 'id' => 601 ), 200 ),
			new WP_REST_Response( $updated, 200 ),
		);
		$fixture                             = $this->fixture( 'coupon-update' );
		$fixture['envelope']['baseRevision'] = $revision;
		$fixture['headers']['If-Match']      = '"' . $revision . '"';

		$response = $this->server->dispatch( $this->request( 'coupons', $fixture['envelope'], $fixture['headers'] ) );
		$data     = $response->get_data();
		$meta     = array_column( $data['document']['meta_data'], 'value', 'key' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'after' ), $meta['typed_meta_fixture'] );
		$this->assertSame( $fixture['envelope']['recordId'], $meta[ Pos_Uuid::META_KEY ] );
		$this->assertIsString( $meta[ Pos_Uuid::META_KEY ] );
		$this->assertSame( Revision::compute( Meta_Normalizer::normalize( $updated ) ), $data['currentRevision'] );
	}

	public function test_push_rejects_a_body_collection_that_disagrees_with_the_route(): void {
		$fixture  = $this->fixture( 'product-create' );
		$response = $this->server->dispatch( $this->request( 'orders', $fixture['envelope'] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_bad_collection', $response->get_data()['code'] );
	}

	public function test_invalid_golden_envelopes_are_rejected_through_rest_dispatch(): void {
		foreach ( $this->fixtures( 'invalid-envelopes' ) as $fixture ) {
			$collection = $fixture['pathCollection'] ?? ( $fixture['envelope']['collection'] ?? 'products' );
			$response   = $this->server->dispatch( $this->request( $collection, $fixture['envelope'] ) );

			$this->assertSame( 400, $response->get_status(), $fixture['name'] );
			$this->assertSame( $fixture['errorCode'], $response->get_data()['code'], $fixture['name'] );
		}
	}

	public function test_push_requires_application_json(): void {
		$fixture = $this->fixture( 'product-create' );
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'push/products' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( $fixture['envelope'] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 415, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_json_required', $response->get_data()['code'] );
	}

	public function test_variation_create_requires_a_live_variable_parent(): void {
		$fixture = $this->fixture( 'variation-parent-precondition-428' );

		$response = $this->server->dispatch( $this->request( 'variations', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 428, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_parent_required', $response->get_data()['code'] );
	}

	public function test_variation_create_forwards_to_the_live_parent_route_without_filtering_payload(): void {
		$fixture   = $this->fixture( 'variation-create-with-parent' );
		$parent    = ProductHelper::create_variation_product();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '12.50' );
		$variation->save();
		$fixture['envelope']['payload']['parent_id'] = $parent->get_id();
		$this->store->resolve_results                = array( 0, $variation->get_id() );
		$GLOBALS['wcpos_sync_contract_responses']    = array( new WP_REST_Response( array( 'id' => $variation->get_id() ), 201 ) );

		$response = $this->server->dispatch( $this->request( 'variations', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 201, $response->get_status() );
		$calls = $GLOBALS['wcpos_sync_contract_calls'];
		$this->assertSame( '/wc/v3/products/' . $parent->get_id() . '/variations', $calls[0]->get_route() );
		$this->assertSame( $fixture['envelope']['payload'], $calls[0]->get_body_params() );
		$this->assertSame( 'Ocean', $calls[0]->get_body_params()['meta_data'][1]['value'] );
	}

	public function test_variation_update_rejects_a_contradicting_parent(): void {
		$parent    = ProductHelper::create_variation_product();
		$other     = ProductHelper::create_variation_product();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10' );
		$variation->update_meta_data( Pos_Uuid::META_KEY, '20000000-0000-4000-8000-000000000026' );
		$variation->save();
		$fixture = $this->fixture( 'variation-parent-mismatch-409' );
		$fixture['envelope']['payload']['parent_id'] = $other->get_id();
		$this->store->resolve = $variation->get_id();

		$response = $this->server->dispatch( $this->request( 'variations', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_parent_mismatch', $response->get_data()['code'] );
	}


	public function test_variation_update_and_delete_use_the_stored_parent_route(): void {
		$parent    = ProductHelper::create_variation_product();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10.00' );
		$variation->save();
		$this->store->resolve = $variation->get_id();

		$controller = new Write_Controller( $this->store );
		$document   = new \ReflectionMethod( $controller, 'document_for' );
		$document->setAccessible( true );
		$meta     = array(
			'route' => '/wc/v3/products',
			'id_type' => 'post',
			'post_type' => 'product_variation',
		);
		$current  = $document->invoke( $controller, $meta, $variation->get_id() )->get_data();
		$revision = (string) ( $current['payload']['date_modified_gmt'] ?? $variation->get_id() );

		$fixture                                  = $this->fixture( 'variation-update' );
		$fixture['envelope']['baseRevision']      = $revision;
		$fixture['headers']['If-Match']           = '"' . $revision . '"';
		$GLOBALS['wcpos_sync_contract_responses'] = array( new WP_REST_Response( array( 'id' => $variation->get_id() ), 200 ) );
		$response = $this->server->dispatch( $this->request( 'variations', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'PUT', $GLOBALS['wcpos_sync_contract_calls'][0]->get_method() );
		$this->assertSame( '/wc/v3/products/' . $parent->get_id() . '/variations/' . $variation->get_id(), $GLOBALS['wcpos_sync_contract_calls'][0]->get_route() );

		$GLOBALS['wcpos_sync_contract_calls']     = array();
		$GLOBALS['wcpos_sync_contract_responses'] = array( new WP_REST_Response( array( 'id' => $variation->get_id() ), 200 ) );
		$fixture                                  = $this->fixture( 'variation-delete' );
		$fixture['envelope']['baseRevision']      = $revision;
		$fixture['headers']['If-Match']           = '"' . $revision . '"';
		$response = $this->server->dispatch( $this->request( 'variations', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '{}', wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'DELETE', $GLOBALS['wcpos_sync_contract_calls'][0]->get_method() );
		$this->assertSame( '/wc/v3/products/' . $parent->get_id() . '/variations/' . $variation->get_id(), $GLOBALS['wcpos_sync_contract_calls'][0]->get_route() );
	}

	public function test_coupon_create_uses_wc_route_and_returns_authoritative_reread(): void {
		$fixture = $this->fixture( 'coupon-create' );
		$this->store->resolve_results             = array( 0, 3001 );
		$GLOBALS['wcpos_sync_contract_responses'] = array(
			new WP_REST_Response( array( 'id' => 3001 ), 201 ),
			new WP_REST_Response( array( 'id' => 3001 ) + $fixture['envelope']['payload'], 200 ),
		);

		$response = $this->server->dispatch( $this->request( 'coupons', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			array( '/wc/v3/coupons', '/wc/v3/coupons/3001' ),
			array_map(
				static function ( $request ) {
					return $request->get_route();
				},
				$GLOBALS['wcpos_sync_contract_calls']
			)
		);
		$this->assertContains(
			array(
				'key' => 'campaign',
				'value' => 'summer',
			),
			$response->get_data()['document']['meta_data']
		);
	}


	public function test_coupon_forward_errors_do_not_persist_or_finalize_identity(): void {
		foreach ( array( 'woocommerce_rest_coupon_code_invalid', 'woocommerce_rest_coupon_code_already_exists' ) as $error_code ) {
			$this->store->persisted = array();
			$this->store->finalized = array();
			$GLOBALS['wcpos_sync_contract_calls']     = array();
			$GLOBALS['wcpos_sync_contract_responses'] = array( new WP_REST_Response( array( 'code' => $error_code ), 400 ) );
			$fixture                                  = $this->fixture( 'coupon-create' );
			$fixture['envelope']['mutationId']        = '10000000-0000-4000-8000-' . ( 'woocommerce_rest_coupon_code_invalid' === $error_code ? '000000000041' : '000000000042' );
			$fixture['headers']['Idempotency-Key']    = $fixture['envelope']['mutationId'];

			$response = $this->server->dispatch( $this->request( 'coupons', $fixture['envelope'], $fixture['headers'] ) );

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( $error_code, $response->get_data()['code'] );
			$this->assertSame( array(), $this->store->persisted );
			$this->assertSame( array(), $this->store->finalized );
		}
	}

	public function test_coupon_update_and_delete_use_wc_routes_and_cas(): void {
		$current  = array(
			'id' => 301,
			'code' => 'save10',
			'amount' => '10.00',
		);
		$updated  = array(
			'id' => 301,
			'code' => 'save10',
			'amount' => '12.00',
		);
		$revision = Revision::compute( $current );
		$this->store->resolve = 301;
		$GLOBALS['wcpos_sync_contract_responses'] = array(
			new WP_REST_Response( $current, 200 ),
			new WP_REST_Response( array( 'id' => 301 ), 200 ),
			new WP_REST_Response( $updated, 200 ),
		);
		$fixture                             = $this->fixture( 'coupon-update' );
		$fixture['envelope']['baseRevision'] = $revision;
		$fixture['headers']['If-Match']      = '"' . $revision . '"';
		$response = $this->server->dispatch( $this->request( 'coupons', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'GET', 'PUT', 'GET' ),
			array_map(
				static function ( $request ) {
					return $request->get_method();
				},
				$GLOBALS['wcpos_sync_contract_calls']
			)
		);
		$this->assertSame( '12.00', $response->get_data()['document']['amount'] );

		$GLOBALS['wcpos_sync_contract_calls']     = array();
		$GLOBALS['wcpos_sync_contract_responses'] = array(
			new WP_REST_Response( $current, 200 ),
			new WP_REST_Response( array( 'id' => 301 ), 200 ),
		);
		$fixture                             = $this->fixture( 'coupon-delete' );
		$fixture['envelope']['baseRevision'] = $revision;
		$fixture['headers']['If-Match']      = '"' . $revision . '"';
		$response = $this->server->dispatch( $this->request( 'coupons', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'GET', 'DELETE' ),
			array_map(
				static function ( $request ) {
					return $request->get_method();
				},
				$GLOBALS['wcpos_sync_contract_calls']
			)
		);
		$this->assertTrue( $GLOBALS['wcpos_sync_contract_calls'][1]->get_param( 'force' ) );
	}

	public function test_dispatched_delete_428_and_conflict_match_golden_shapes(): void {
		$fixture              = $this->fixture( 'customer-delete' );
		$fixture['envelope']['baseRevision'] = null;
		unset( $fixture['headers']['If-Match'] );
		$this->store->resolve = 42;
		$response             = $this->server->dispatch( $this->request( 'customers', $fixture['envelope'], $fixture['headers'] ) );
		$this->assertSame( 428, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_precondition_required', $response->get_data()['code'] );

		$this->store->resolve = 42;
		$current = array(
			'id' => 42,
			'email' => 'changed@example.test',
		);
		$GLOBALS['wcpos_sync_contract_responses'] = array( new WP_REST_Response( $current, 200 ) );
		$fixture = $this->fixture( 'customer-delete' );
		$response = $this->server->dispatch( $this->request( 'customers', $fixture['envelope'], $fixture['headers'] ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( array( 'code', 'message', 'current', 'currentRevision' ), array_keys( $response->get_data() ) );
		$this->assertSame( Revision::compute( $current ), $response->get_data()['currentRevision'] );
	}
}
