<?php
/**
 * Route-dispatch tests for stock changes during a product or variation push (#2853).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;

require_once __DIR__ . '/Test_Rest_Dispatch_Write_Contract.php';

/** Exercises real WooCommerce stock writes on either side of the revision compare. */
class Test_Rest_Dispatch_Stock_Delta extends Sync_REST_Store_Test_Case {
	/** @var Dispatch_Fake_Mutation_Store Push identity store. */
	private $store;
	/** @var string Original site stock setting. */
	private $previous_manage_stock;
	/** @var int Quantity sold in the forward. */
	private $sale_qty = 0;
	/** @var int Record sold in the forward. */
	private $sale_target = 0;
	/** @var int Number of concurrent sales. */
	private $sales_fired = 0;
	/** @var int Mutation ID sequence. */
	private $seq = 0;
	/** @var callable|null Listener that interrupts a stock write. */
	private $stock_failure;
	/** @var int Number of stock update queries. */
	private $stock_queries = 0;

	/** Enable stock management and dispatch as an administrator. */
	public function setUp(): void {
		$this->previous_manage_stock      = get_option( 'woocommerce_manage_stock', 'no' );
		$this->store                      = new Dispatch_Fake_Mutation_Store();
		Dispatch_Write_Controller::$store = $this->store;
		parent::setUp();
		update_option( 'woocommerce_manage_stock', 'yes' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Register the push controller with the fixture store. */
	public function rest_api_init(): void {
		$inject = static function ( array $controllers ): array {
			$controllers['sync-write'] = Dispatch_Write_Controller::class;
			return $controllers;
		};
		add_filter( 'wcpos_rest_api_controllers', $inject, 99 );
		parent::rest_api_init();
		remove_filter( 'wcpos_rest_api_controllers', $inject, 99 );
	}

	/** Restore stock settings and remove listeners even after a failed assertion. */
	public function tearDown(): void {
		remove_filter( 'woocommerce_update_product_stock_query', array( $this, 'count_stock_query' ) );
		remove_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'sale_in_window' ), 10 );
		remove_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( $this, 'sale_in_window' ), 10 );
		if ( null !== $this->stock_failure ) {
			remove_action( 'woocommerce_product_before_set_stock', $this->stock_failure );
		}
		update_option( 'woocommerce_manage_stock', $this->previous_manage_stock );
		parent::tearDown();
	}

	/**
	 * Count stock update queries without modifying them.
	 *
	 * @param string $sql Stock update query.
	 * @return string
	 */
	public function count_stock_query( $sql ) {
		++$this->stock_queries;
		return $sql;
	}

	/**
	 * Apply the concurrent checkout's atomic decrement before WooCommerce saves.
	 *
	 * @param \WC_Product $product Product being saved.
	 * @return \WC_Product
	 */
	public function sale_in_window( $product ) {
		if ( $this->sale_qty > 0 && 0 === $this->sales_fired ) {
			++$this->sales_fired;
			wc_update_product_stock( $this->sale_target, $this->sale_qty, 'decrease' );
		}
		return $product;
	}

	/**
	 * Dispatch an update with a distinct mutation ID.
	 *
	 * @param string      $collection Collection name.
	 * @param int         $id         Product or variation ID.
	 * @param string|null $base       Client's base revision.
	 * @param array       $payload    Fields to update.
	 * @return \WP_REST_Response
	 */
	private function push( string $collection, int $id, ?string $base, array $payload ) {
		++$this->seq;
		$mid                  = sprintf( '30000000-0000-4000-8000-%012d', $this->seq );
		$rid                  = '40000000-0000-4000-8000-000000000001';
		$this->store->resolve = $id;
		$envelope             = array(
			'mutationId'   => $mid,
			'operation'    => 'update',
			'collection'   => $collection,
			'recordId'     => $rid,
			'baseRevision' => $base ?? 'sha256:deliberately-stale',
			'payload'      => $payload,
		);
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/' . $collection );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $mid );
		$request->set_header( 'If-Match', '"' . $envelope['baseRevision'] . '"' );
		$request->set_body( (string) wp_json_encode( $envelope ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * The revision a client holding the current row would send (read off a 409).
	 *
	 * @param string $collection Collection name.
	 * @param int    $id         Product or variation ID.
	 * @return string
	 */
	private function held_revision( string $collection, int $id ): string {
		$r = $this->push( $collection, $id, null, array( 'regular_price' => '1.00' ) );
		$this->assertSame( 409, $r->get_status() );
		return $r->get_data()['currentRevision'];
	}

	/**
	 * Read the saved stock without relying on the fixture's product object.
	 *
	 * @param int $id Product or variation ID.
	 * @return int|null
	 */
	private function stock( int $id ) {
		wp_cache_flush();
		return wc_get_product( $id )->get_stock_quantity();
	}

	/** A sale after the compare combines with the pushed product adjustment. */
	public function test_product_stock_push_with_sale_in_window_keeps_the_sale(): void {
		$id                = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10, 'regular_price' => 10 ) )->get_id();
		$base              = $this->held_revision( 'products', $id );
		$this->sale_qty    = 3;
		$this->sale_target = $id;
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'sale_in_window' ), 10 );

		$response = $this->push( 'products', $id, $base, array( 'stock_quantity' => 12 ) );
		$this->assertSame( 1, $this->sales_fired );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 9, $this->stock( $id ) );
	}

	/** An independently stocked variation keeps the sale on its nested update route. */
	public function test_variation_stock_push_with_sale_in_window_keeps_the_sale(): void {
		$id        = ProductHelper::create_variation_product()->get_children()[0];
		$variation = wc_get_product( $id );
		$variation->set_props( array( 'manage_stock' => true, 'stock_quantity' => 10 ) );
		$variation->save();
		$base              = $this->held_revision( 'variations', $id );
		$this->sale_qty    = 3;
		$this->sale_target = $id;
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( $this, 'sale_in_window' ), 10 );

		$response = $this->push( 'variations', $id, $base, array( 'stock_quantity' => 12 ) );
		$this->assertSame( 1, $this->sales_fired );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 9, $this->stock( $id ) );
	}

	/** An earlier sale invalidates the client's revision. */
	public function test_sale_before_read_is_refused_with_409(): void {
		$id   = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10 ) )->get_id();
		$base = $this->held_revision( 'products', $id );
		wc_update_product_stock( $id, 3, 'decrease' );

		$response = $this->push( 'products', $id, $base, array( 'stock_quantity' => 12 ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 7, $this->stock( $id ) );
	}

	/** A price-only forward never reverses a sale. */
	public function test_price_only_push_leaves_stock_alone(): void {
		$id                = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10, 'regular_price' => 10 ) )->get_id();
		$base              = $this->held_revision( 'products', $id );
		$this->sale_qty    = 3;
		$this->sale_target = $id;
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'sale_in_window' ), 10 );

		$response = $this->push( 'products', $id, $base, array( 'regular_price' => '11.00' ) );
		$this->assertSame( 1, $this->sales_fired );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7, $this->stock( $id ) );
		$this->assertSame( '11.00', wc_get_product( $id )->get_regular_price() );
	}

	/** A positive adjustment increases stock. */
	public function test_stock_increase_is_applied(): void {
		$id       = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10 ) )->get_id();
		$response = $this->push( 'products', $id, $this->held_revision( 'products', $id ), array( 'stock_quantity' => 12 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 12, $this->stock( $id ) );
	}

	/** A negative adjustment decreases stock. */
	public function test_stock_decrease_is_applied(): void {
		$id       = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10 ) )->get_id();
		$response = $this->push( 'products', $id, $this->held_revision( 'products', $id ), array( 'stock_quantity' => 8 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 8, $this->stock( $id ) );
	}

	/** An unchanged quantity does not fire a stock write when the price changes. */
	public function test_zero_delta_writes_no_stock(): void {
		$id     = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10, 'regular_price' => 10 ) )->get_id();
		$base   = $this->held_revision( 'products', $id );
		add_filter( 'woocommerce_update_product_stock_query', array( $this, 'count_stock_query' ) );

		$response = $this->push( 'products', $id, $base, array( 'stock_quantity' => 10, 'regular_price' => '11.00' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->stock_queries );
		$this->assertSame( '11.00', wc_get_product( $id )->get_regular_price() );
	}

	/** Turning stock management on leaves the initial quantity to WooCommerce. */
	public function test_manage_stock_turned_on_in_same_push_sets_stock(): void {
		$id       = ProductHelper::create_simple_product( array( 'manage_stock' => false ) )->get_id();
		$response = $this->push( 'products', $id, $this->held_revision( 'products', $id ), array( 'manage_stock' => true, 'stock_quantity' => 5 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 5, $this->stock( $id ) );
	}

	/** Turning stock management off does not apply the pushed quantity. */
	public function test_manage_stock_turned_off_in_same_push_writes_no_stock(): void {
		$id     = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10 ) )->get_id();
		$base   = $this->held_revision( 'products', $id );
		add_filter( 'woocommerce_update_product_stock_query', array( $this, 'count_stock_query' ) );

		$response = $this->push( 'products', $id, $base, array( 'manage_stock' => false, 'stock_quantity' => 12 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->stock_queries );
		$this->assertSame( false, wc_get_product( $id )->get_manage_stock() );
		$this->assertNull( $this->stock( $id ) );
	}

	/** The ack describes the post-sale, post-adjustment record. */
	public function test_ack_stock_and_revision_equal_a_fresh_read(): void {
		$id                = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10 ) )->get_id();
		$base              = $this->held_revision( 'products', $id );
		$this->sale_qty    = 3;
		$this->sale_target = $id;
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'sale_in_window' ), 10 );

		$response = $this->push( 'products', $id, $base, array( 'stock_quantity' => 12 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 9, $response->get_data()['document']['stock_quantity'] );
		$this->assertSame( $this->held_revision( 'products', $id ), $response->get_data()['currentRevision'] );
	}

	/** A failed stock write cannot acknowledge the saved price as a complete update. */
	public function test_stock_write_failure_returns_error_and_no_ack(): void {
		$id                  = ProductHelper::create_simple_product( array( 'manage_stock' => true, 'stock_quantity' => 10, 'regular_price' => 10 ) )->get_id();
		$base                = $this->held_revision( 'products', $id );
		$payload             = array( 'regular_price' => '11.00', 'stock_quantity' => 12 );
		$this->stock_failure = static function () {
			throw new \RuntimeException( 'Interrupted stock write.' );
		};
		add_action( 'woocommerce_product_before_set_stock', $this->stock_failure );

		$response = $this->push( 'products', $id, $base, $payload );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woo_rxdb_sync_stock_write_failed', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'document', $response->get_data() );
		$this->assertSame( 10, $this->stock( $id ) );
		$this->assertSame( '11.00', wc_get_product( $id )->get_regular_price() );

		remove_action( 'woocommerce_product_before_set_stock', $this->stock_failure );
		$retry = $this->push( 'products', $id, $base, $payload );
		$this->assertSame( 409, $retry->get_status() );
		$this->assertSame( 10, $retry->get_data()['current']['stock_quantity'] );
	}
}
