<?php
/**
 * Runtime hook-parity pins for v2 writes dispatched through wc/v3.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact parity scenarios keep the operation matrix readable.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use WC_Product_Variation;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Sync\Collections;
use WCPOS\WooCommercePOS\Sync\Meta_Normalizer;
use WCPOS\WooCommercePOS\Sync\Mutation_Store;
use WCPOS\WooCommercePOS\Sync\Order_Serializer;
use WCPOS\WooCommercePOS\Sync\Revision;
use WP_REST_Request;

/**
 * Compares hook-name sequences from stock wc/v3 and v2 against fresh fixtures.
 *
 * Expected divergences accepted by ADR 0035:
 * - order create: two extra v2 `woocommerce_update_order` fires persist the UUID
 *   and WCPOS audit augmentation after the forwarded wc/v3 create.
 * - order update: one extra v2 `woocommerce_update_order` fire reasserts the UUID.
 * - order coupon application: the same two post-create augmentation saves as an
 *   ordinary v2 order create.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 */
class Test_Hook_Parity extends Sync_REST_Store_Test_Case {
	public const COVERED_WRITE_COLLECTIONS = array(
		'products',
		'variations',
		'orders',
		'customers',
		'categories',
		'brands',
		'coupons',
	);

	private const PRODUCT_HOOKS = array(
		'woocommerce_new_product',
		'woocommerce_update_product',
		'save_post_product',
		'wp_trash_post',
		'before_delete_post',
	);

	private const VARIATION_HOOKS = array(
		'woocommerce_new_product_variation',
		'woocommerce_update_product_variation',
		'save_post_product_variation',
		'wp_trash_post',
		'before_delete_post',
	);

	private const CUSTOMER_HOOKS = array(
		'user_register',
		'woocommerce_created_customer',
		'profile_update',
		'woocommerce_update_customer',
		'delete_user',
		'deleted_user',
	);

	private const ORDER_HOOKS = array(
		'woocommerce_new_order',
		'woocommerce_update_order',
		'woocommerce_order_status_changed',
		'woocommerce_before_trash_order',
		'wp_trash_post',
	);

	private const CATEGORY_HOOKS = array( 'created_product_cat', 'edited_product_cat', 'delete_product_cat' );
	private const BRAND_HOOKS    = array( 'created_product_brand', 'edited_product_brand', 'delete_product_brand' );
	private const COUPON_HOOKS   = array(
		'woocommerce_new_coupon',
		'woocommerce_update_coupon',
		'save_post_shop_coupon',
		'wp_trash_post',
		'before_delete_post',
	);

	private const EXPECTED_DIVERGENCES = array(
		'order_create'             => array(
			array( 'woocommerce_update_order', 'ADR 0035 accepts the post-create UUID augmentation save.' ),
			array( 'woocommerce_update_order', 'ADR 0035 accepts the post-create audit-meta augmentation save.' ),
		),
		'order_update'             => array(
			array( 'woocommerce_update_order', 'ADR 0035 accepts the post-update UUID augmentation save.' ),
		),
		'order_coupon_application' => array(
			array( 'woocommerce_update_order', 'ADR 0035 accepts the post-create UUID augmentation save.' ),
			array( 'woocommerce_update_order', 'ADR 0035 accepts the post-create audit-meta augmentation save.' ),
		),
	);

	private $mutation_sequence = 0;
	private $record_sequence   = 0;
	private $previous_manage_stock;

	public function setUp(): void {
		$this->previous_manage_stock = get_option( 'woocommerce_manage_stock', 'no' );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
	}

	public function tearDown(): void {
		update_option( 'woocommerce_manage_stock', $this->previous_manage_stock );
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}

	private function record_hooks( array $watched, callable $op ): array {
		$sequence  = array();
		$listeners = array();
		foreach ( $watched as $hook ) {
			// Filter-safe: some watched names are filters (woocommerce_order_item_quantity),
			// and a void listener would replace their value with null mid-operation.
			$listeners[ $hook ] = static function ( ...$args ) use ( &$sequence, $hook ) {
				$sequence[] = $hook;
				return $args[0] ?? null;
			};
			add_action( $hook, $listeners[ $hook ], PHP_INT_MAX, PHP_INT_MAX );
		}
		try {
			$op();
		} finally {
			foreach ( $listeners as $hook => $listener ) {
				remove_action( $hook, $listener, PHP_INT_MAX );
			}
		}
		return $sequence;
	}

	private function assert_hook_parity( string $operation, array $watched, callable $baseline, callable $v2 ): void {
		$expected = $this->record_hooks( $watched, $baseline );
		$actual   = $this->record_hooks( $watched, $v2 );

		foreach ( self::EXPECTED_DIVERGENCES[ $operation ] ?? array() as $divergence ) {
			$this->assertNotEmpty( $divergence[1], $operation . ' divergence requires a reason.' );
			$expected[] = $divergence[0];
		}

		$this->assertSame( $expected, $actual, $operation . ' hook sequence differs.' );
	}

	private function wc_request( string $method, string $route, array $params, int $status ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		$this->assertSame( $status, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response;
	}

	private function wc_operation( string $method, string $route, array $params, int $status ): callable {
		return function () use ( $method, $route, $params, $status ): void {
			$this->wc_request( $method, $route, $params, $status );
		};
	}

	private function push( string $operation, string $collection, string $record_id, array $payload = array(), ?string $revision = null, ?bool $force = null ) {
		$envelope = array(
			'mutationId'   => sprintf( '10000000-0000-4000-8000-%012d', ++$this->mutation_sequence ),
			'operation'    => $operation,
			'collection'   => $collection,
			'recordId'     => $record_id,
			'baseRevision' => $revision,
		);
		if ( 'delete' !== $operation ) {
			$envelope['payload'] = $payload; // The write contract forbids payload on delete.
		}
		if ( null !== $force ) {
			$envelope['force'] = $force;
		}
		$request = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/' . $collection );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		if ( null !== $revision ) {
			$request->set_header( 'If-Match', '"' . $revision . '"' );
		}
		$request->set_body( (string) wp_json_encode( $envelope ) );
		return $this->server->dispatch( $request );
	}

	private function v2_operation( string $operation, string $collection, string $record_id, array $payload = array(), ?string $revision = null, ?bool $force = null ): callable {
		return function () use ( $operation, $collection, $record_id, $payload, $revision, $force ): void {
			$response = $this->push( $operation, $collection, $record_id, $payload, $revision, $force );
			$status   = 'create' === $operation ? 201 : 200;
			$this->assertSame( $status, $response->get_status(), wp_json_encode( $response->get_data() ) );
		};
	}

	private function next_uuid(): string {
		return sprintf( '20000000-0000-4000-8000-%012d', ++$this->record_sequence );
	}

	private function stamp_uuid( string $id_type, int $id, string $uuid ): void {
		$this->assertTrue( ( new Mutation_Store() )->persist_uuid( $id_type, $id, $uuid ) );
	}

	private function resource_revision( string $route, int $id ): string {
		$response = rest_do_request( new WP_REST_Request( 'GET', $route . '/' . $id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return Revision::compute( Meta_Normalizer::normalize( $response->get_data() ) );
	}

	private function order_revision( int $id ): string {
		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $id );
		$request->set_param( 'dp', '6' );
		$data = Meta_Normalizer::normalize( rest_do_request( $request )->get_data() );
		return Order_Serializer::canonical_revision( Order_Serializer::add_pos_links( $data, wc_get_order( $id ) ) );
	}

	private function variation_revision( int $parent_id, int $id ): string {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $parent_id . '/variations/' . $id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return (string) $response->get_data()['date_modified_gmt'];
	}

	private function variation_fixture(): WC_Product_Variation {
		$parent    = ProductHelper::create_variation_product();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '12.50' );
		$variation->save();
		return $variation;
	}

	private function customer_fixture( string $email ): int {
		return (int) $this->wc_request( 'POST', '/wc/v3/customers', array( 'email' => $email ), 201 )->get_data()['id'];
	}

	private function coupon_fixture( string $code ): void {
		CouponHelper::create_coupon( $code );
	}

	private function line_product( bool $managed_stock = false ) {
		$args = array(
			'regular_price' => 10,
			'price'         => 10,
		);
		if ( $managed_stock ) {
			$args['manage_stock']   = true;
			$args['stock_quantity'] = 10;
		}
		return ProductHelper::create_simple_product( $args );
	}

	private function order_line_payload( int $product_id, int $quantity, string $status = '', string $coupon = '' ): array {
		$payload = array(
			'line_items' => array(
				array(
					'product_id' => $product_id,
					'quantity' => $quantity,
				),
			),
		);
		if ( '' !== $status ) {
			$payload['status'] = $status;
		}
		if ( '' !== $coupon ) {
			$payload['coupon_lines'] = array( array( 'code' => $coupon ) );
		}
		return $payload;
	}

	private function passthrough_payload( string $collection, string $suffix ): array {
		if ( 'coupons' === $collection ) {
			return array( 'code' => 'hook-parity-' . $suffix );
		}
		return array( 'name' => 'Hook parity ' . $suffix );
	}

	private function assert_passthrough_parity( string $name, string $collection, string $route, string $id_type, string $operation, array $hooks ): void {
		$baseline_payload = $this->passthrough_payload( $collection, $name . '-baseline-' . $operation );
		$v2_payload       = $this->passthrough_payload( $collection, $name . '-v2-' . $operation );
		if ( 'create' === $operation ) {
			$this->assert_hook_parity(
				$name . '_create',
				$hooks,
				$this->wc_operation( 'POST', $route, $baseline_payload, 201 ),
				$this->v2_operation( 'create', $collection, $this->next_uuid(), $v2_payload )
			);
			return;
		}

		$baseline_id = (int) $this->wc_request( 'POST', $route, $baseline_payload, 201 )->get_data()['id'];
		$v2_id       = (int) $this->wc_request( 'POST', $route, $v2_payload, 201 )->get_data()['id'];
		$uuid        = $this->next_uuid();
		$this->stamp_uuid( $id_type, $v2_id, $uuid );
		$revision = $this->resource_revision( $route, $v2_id );
		$params   = 'update' === $operation ? array( 'description' => 'Updated by parity' ) : array( 'force' => true );
		$this->assert_hook_parity(
			$name . '_' . $operation,
			$hooks,
			$this->wc_operation( 'update' === $operation ? 'PUT' : 'DELETE', $route . '/' . $baseline_id, $params, 200 ),
			$this->v2_operation( $operation, $collection, $uuid, 'update' === $operation ? $params : array(), $revision, 'delete' === $operation ? true : null )
		);
	}

	public function test_every_writable_collection_has_a_parity_pin(): void {
		$writable = array_keys(
			array_filter(
				Collections::with( 'write' ),
				static function ( array $row ): bool {
					return isset( $row['identity'] );
				}
			)
		);
		$this->assertSame( self::COVERED_WRITE_COLLECTIONS, $writable );
	}

	public function test_parity_product_create(): void {
		$payload = array(
			'name'          => 'Parity product',
			'type'          => 'simple',
			'regular_price' => '10.00',
		);
		$this->assert_hook_parity(
			'product_create',
			self::PRODUCT_HOOKS,
			$this->wc_operation( 'POST', '/wc/v3/products', $payload, 201 ),
			$this->v2_operation( 'create', 'products', $this->next_uuid(), $payload )
		);
	}

	public function test_parity_product_update(): void {
		$baseline = ProductHelper::create_simple_product();
		$v2       = ProductHelper::create_simple_product();
		$uuid     = $this->next_uuid();
		$this->stamp_uuid( 'post', $v2->get_id(), $uuid );
		$revision = $this->resource_revision( '/wc/v3/products', $v2->get_id() );
		$this->assert_hook_parity(
			'product_update',
			self::PRODUCT_HOOKS,
			$this->wc_operation( 'PUT', '/wc/v3/products/' . $baseline->get_id(), array( 'name' => 'Updated parity product' ), 200 ),
			$this->v2_operation( 'update', 'products', $uuid, array( 'name' => 'Updated parity product' ), $revision )
		);
	}

	public function test_parity_product_delete(): void {
		$baseline = ProductHelper::create_simple_product();
		$v2       = ProductHelper::create_simple_product();
		$uuid     = $this->next_uuid();
		$this->stamp_uuid( 'post', $v2->get_id(), $uuid );
		$revision = $this->resource_revision( '/wc/v3/products', $v2->get_id() );
		$this->assert_hook_parity(
			'product_delete',
			self::PRODUCT_HOOKS,
			$this->wc_operation( 'DELETE', '/wc/v3/products/' . $baseline->get_id(), array( 'force' => true ), 200 ),
			$this->v2_operation( 'delete', 'products', $uuid, array(), $revision, true )
		);
	}

	public function test_parity_variation_create(): void {
		$baseline_parent = ProductHelper::create_variation_product();
		$v2_parent       = ProductHelper::create_variation_product();
		$this->assert_hook_parity(
			'variation_create',
			self::VARIATION_HOOKS,
			$this->wc_operation( 'POST', '/wc/v3/products/' . $baseline_parent->get_id() . '/variations', array( 'regular_price' => '12.50' ), 201 ),
			$this->v2_operation(
				'create',
				'variations',
				$this->next_uuid(),
				array(
					'parent_id'     => $v2_parent->get_id(),
					'regular_price' => '12.50',
				)
			)
		);
	}

	public function test_parity_variation_update(): void {
		$baseline = $this->variation_fixture();
		$v2       = $this->variation_fixture();
		$uuid     = $this->next_uuid();
		$this->stamp_uuid( 'post', $v2->get_id(), $uuid );
		$revision = $this->variation_revision( $v2->get_parent_id(), $v2->get_id() );
		$this->assert_hook_parity(
			'variation_update',
			self::VARIATION_HOOKS,
			$this->wc_operation( 'PUT', '/wc/v3/products/' . $baseline->get_parent_id() . '/variations/' . $baseline->get_id(), array( 'regular_price' => '15.00' ), 200 ),
			$this->v2_operation( 'update', 'variations', $uuid, array( 'regular_price' => '15.00' ), $revision )
		);
	}

	public function test_parity_variation_delete(): void {
		$baseline = $this->variation_fixture();
		$v2       = $this->variation_fixture();
		$uuid     = $this->next_uuid();
		$this->stamp_uuid( 'post', $v2->get_id(), $uuid );
		$revision = $this->variation_revision( $v2->get_parent_id(), $v2->get_id() );
		$this->assert_hook_parity(
			'variation_delete',
			self::VARIATION_HOOKS,
			$this->wc_operation( 'DELETE', '/wc/v3/products/' . $baseline->get_parent_id() . '/variations/' . $baseline->get_id(), array( 'force' => true ), 200 ),
			$this->v2_operation( 'delete', 'variations', $uuid, array(), $revision, true )
		);
	}

	public function test_parity_category_create(): void {
		$this->assert_passthrough_parity( 'category', 'categories', '/wc/v3/products/categories', 'term', 'create', self::CATEGORY_HOOKS );
	}
	public function test_parity_category_update(): void {
		$this->assert_passthrough_parity( 'category', 'categories', '/wc/v3/products/categories', 'term', 'update', self::CATEGORY_HOOKS );
	}
	public function test_parity_category_delete(): void {
		$this->assert_passthrough_parity( 'category', 'categories', '/wc/v3/products/categories', 'term', 'delete', self::CATEGORY_HOOKS );
	}
	public function test_parity_brand_create(): void {
		$this->assert_passthrough_parity( 'brand', 'brands', '/wc/v3/products/brands', 'term', 'create', self::BRAND_HOOKS );
	}
	public function test_parity_brand_update(): void {
		$this->assert_passthrough_parity( 'brand', 'brands', '/wc/v3/products/brands', 'term', 'update', self::BRAND_HOOKS );
	}
	public function test_parity_brand_delete(): void {
		$this->assert_passthrough_parity( 'brand', 'brands', '/wc/v3/products/brands', 'term', 'delete', self::BRAND_HOOKS );
	}
	public function test_parity_coupon_create(): void {
		$this->assert_passthrough_parity( 'coupon', 'coupons', '/wc/v3/coupons', 'post', 'create', self::COUPON_HOOKS );
	}
	public function test_parity_coupon_update(): void {
		$this->assert_passthrough_parity( 'coupon', 'coupons', '/wc/v3/coupons', 'post', 'update', self::COUPON_HOOKS );
	}
	public function test_parity_coupon_delete(): void {
		$this->assert_passthrough_parity( 'coupon', 'coupons', '/wc/v3/coupons', 'post', 'delete', self::COUPON_HOOKS );
	}
	public function test_parity_customer_create(): void {
		$this->assert_hook_parity(
			'customer_create',
			self::CUSTOMER_HOOKS,
			$this->wc_operation( 'POST', '/wc/v3/customers', array( 'email' => 'hook-parity-baseline@example.test' ), 201 ),
			$this->v2_operation( 'create', 'customers', $this->next_uuid(), array( 'email' => 'hook-parity-v2@example.test' ) )
		);
	}

	public function test_parity_customer_update(): void {
		$baseline = $this->customer_fixture( 'hook-parity-update-baseline@example.test' );
		$v2       = $this->customer_fixture( 'hook-parity-update-v2@example.test' );
		$uuid     = $this->next_uuid();
		$this->stamp_uuid( 'user', $v2, $uuid );
		$revision = $this->resource_revision( '/wc/v3/customers', $v2 );
		$this->assert_hook_parity(
			'customer_update',
			self::CUSTOMER_HOOKS,
			$this->wc_operation( 'PUT', '/wc/v3/customers/' . $baseline, array( 'first_name' => 'Parity' ), 200 ),
			$this->v2_operation( 'update', 'customers', $uuid, array( 'first_name' => 'Parity' ), $revision )
		);
	}

	public function test_parity_customer_delete(): void {
		$baseline = $this->customer_fixture( 'hook-parity-delete-baseline@example.test' );
		$v2       = $this->customer_fixture( 'hook-parity-delete-v2@example.test' );
		$uuid     = $this->next_uuid();
		$this->stamp_uuid( 'user', $v2, $uuid );
		$revision = $this->resource_revision( '/wc/v3/customers', $v2 );
		$this->assert_hook_parity(
			'customer_delete',
			self::CUSTOMER_HOOKS,
			$this->wc_operation( 'DELETE', '/wc/v3/customers/' . $baseline, array( 'force' => true ), 200 ),
			$this->v2_operation( 'delete', 'customers', $uuid, array(), $revision, true )
		);
	}

	public function test_parity_order_create(): void {
		$this->assert_hook_parity(
			'order_create',
			self::ORDER_HOOKS,
			$this->wc_operation( 'POST', '/wc/v3/orders', array( 'status' => 'pending' ), 201 ),
			$this->v2_operation( 'create', 'orders', $this->next_uuid(), array( 'status' => 'pending' ) )
		);
	}

	public function test_parity_order_update(): void {
		$baseline = OrderHelper::create_order();
		$v2       = OrderHelper::create_order();
		$uuid     = $this->next_uuid();
		$this->stamp_uuid( 'order', $v2->get_id(), $uuid );
		$revision = $this->order_revision( $v2->get_id() );
		$this->assert_hook_parity(
			'order_update',
			self::ORDER_HOOKS,
			$this->wc_operation( 'PUT', '/wc/v3/orders/' . $baseline->get_id(), array( 'status' => 'processing' ), 200 ),
			$this->v2_operation( 'update', 'orders', $uuid, array( 'status' => 'processing' ), $revision )
		);
	}

	public function test_parity_order_delete(): void {
		$baseline = OrderHelper::create_order();
		$v2       = OrderHelper::create_order();
		$uuid     = $this->next_uuid();
		$this->stamp_uuid( 'order', $v2->get_id(), $uuid );
		$revision = $this->order_revision( $v2->get_id() );
		$this->assert_hook_parity(
			'order_delete',
			self::ORDER_HOOKS,
			$this->wc_operation( 'DELETE', '/wc/v3/orders/' . $baseline->get_id(), array( 'force' => false ), 200 ),
			$this->v2_operation( 'delete', 'orders', $uuid, array(), $revision, false )
		);
	}

	public function test_parity_order_coupon_application(): void {
		$this->coupon_fixture( 'hook-parity-baseline' );
		$this->coupon_fixture( 'hook-parity-v2' );
		$baseline_product = $this->line_product();
		$v2_product       = $this->line_product();
		$baseline_payload = $this->order_line_payload( $baseline_product->get_id(), 1, '', 'hook-parity-baseline' );
		$v2_payload       = $this->order_line_payload( $v2_product->get_id(), 1, '', 'hook-parity-v2' );
		$hooks            = array( 'woocommerce_order_applied_coupon', 'woocommerce_new_order', 'woocommerce_update_order' );
		$this->assert_hook_parity(
			'order_coupon_application',
			$hooks,
			$this->wc_operation( 'POST', '/wc/v3/orders', $baseline_payload, 201 ),
			$this->v2_operation( 'create', 'orders', $this->next_uuid(), $v2_payload )
		);
	}

	public function test_parity_order_stock_via_order(): void {
		update_option( 'woocommerce_manage_stock', 'yes' );
		$baseline_product = $this->line_product( true );
		$v2_product       = $this->line_product( true );
		$baseline_payload = $this->order_line_payload( $baseline_product->get_id(), 2, 'processing' );
		$v2_payload       = $this->order_line_payload( $v2_product->get_id(), 2, 'processing' );
		$hooks            = array( 'woocommerce_reduce_order_stock', 'woocommerce_order_item_quantity', 'woocommerce_new_order' );
		$this->assert_hook_parity(
			'order_stock_via_order',
			$hooks,
			$this->wc_operation( 'POST', '/wc/v3/orders', $baseline_payload, 201 ),
			$this->v2_operation( 'create', 'orders', $this->next_uuid(), $v2_payload )
		);
	}

	public function test_parity_order_set_paid_checkout_path(): void {
		$payload = array(
			'set_paid' => true,
			'fee_lines' => array(
				array(
					'name' => 'Offline sale',
					'total' => '10.00',
				),
			),
		);
		$hooks   = array( 'woocommerce_pre_payment_complete', 'woocommerce_payment_complete', 'woocommerce_order_status_changed', 'woocommerce_new_order' );
		$this->assert_hook_parity(
			'order_set_paid_checkout_path',
			$hooks,
			$this->wc_operation( 'POST', '/wc/v3/orders', $payload, 201 ),
			$this->v2_operation( 'create', 'orders', $this->next_uuid(), $payload )
		);
	}

	public function test_parity_customer_update_with_tax_ids_fires_update_once(): void {
		$customer_id = $this->customer_fixture( 'hook-parity-tax-id@example.test' );
		$uuid        = $this->next_uuid();
		$this->stamp_uuid( 'user', $customer_id, $uuid );
		$revision = $this->resource_revision( '/wc/v3/customers', $customer_id );
		$sequence = $this->record_hooks(
			array( 'woocommerce_update_customer' ),
			function () use ( $uuid, $revision ): void {
				$response = $this->push(
					'update',
					'customers',
					$uuid,
					array(
						'first_name' => 'Parity',
						'tax_ids' => array(
							array(
								'type' => 'gb_vat',
								'value' => 'GB123456789',
								'country' => 'GB',
							),
						),
					),
					$revision
				);
				$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			}
		);
		$this->assertSame( 1, count( $sequence ) );
	}
}
