<?php
/**
 * Route-dispatch pins for Stock_Validator on the v2 order push (#1402).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Product;
use WCPOS\WooCommercePOS\Sync\Api;
use WP_REST_Response;

/**
 * Drives POST /wcpos/v2/push/orders through the REAL registered route and
 * pins the overselling protection v1 pinned only at the filter level
 * (Test_Stock_Validator). The #1391 lesson: a surface bypass keeps
 * route-scoped tests green, so the pin must ride the route the client
 * actually calls — Stock_Validator hooks the global
 * woocommerce_rest_pre_insert_shop_order_object filter and gates on
 * wcpos_request(), which is true during the push's inner wc/v3 dispatch.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Rest_Dispatch_Stock_Validation extends Sync_REST_Store_Test_Case {
	/**
	 * Checkout settings option before this fixture overwrites it.
	 *
	 * @var array|false
	 */
	private $original_checkout_settings;

	/**
	 * Global manage-stock option before this fixture overwrites it.
	 *
	 * @var mixed
	 */
	private $original_manage_stock;

	/**
	 * Enable the v2 sync routes, mark the request as POS (wcpos_request()
	 * reads real request headers, not the WP_REST_Request object), and turn
	 * stock management on.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		$this->original_checkout_settings = get_option( 'woocommerce_pos_settings_checkout' );
		$this->original_manage_stock      = get_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_manage_stock', 'yes' );
	}

	/**
	 * Restore request state and options.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		if ( false === $this->original_checkout_settings ) {
			delete_option( 'woocommerce_pos_settings_checkout' );
		} else {
			update_option( 'woocommerce_pos_settings_checkout', $this->original_checkout_settings );
		}
		update_option( 'woocommerce_manage_stock', $this->original_manage_stock );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Toggle the POS overselling protection.
	 *
	 * @param bool $enabled Setting value.
	 */
	private function set_prevent_overselling( bool $enabled ): void {
		update_option( 'woocommerce_pos_settings_checkout', array( 'prevent_overselling' => $enabled ) );
	}

	/**
	 * Create a stock-managed simple product.
	 *
	 * @param int    $quantity   Available stock.
	 * @param string $backorders Backorder mode.
	 */
	private function create_stock_product( int $quantity, string $backorders = 'no' ): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'manage_stock'   => true,
				'stock_quantity' => $quantity,
				'backorders'     => $backorders,
				'regular_price'  => 10,
				'price'          => 10,
			)
		);
	}

	/**
	 * Dispatch one order mutation envelope through the registered v2 route.
	 *
	 * @param array $envelope Mutation envelope.
	 */
	private function push_order( array $envelope ): WP_REST_Response {
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		if ( null !== ( $envelope['baseRevision'] ?? null ) ) {
			$request->set_header( 'If-Match', '"' . $envelope['baseRevision'] . '"' );
		}
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Build a create envelope for one oversold line.
	 *
	 * @param int    $sequence Unique per-test envelope sequence number.
	 * @param string $status   Target order status.
	 * @param int    $product_id Product to order.
	 * @param int    $quantity Requested quantity.
	 */
	private function oversell_create_envelope( int $sequence, string $status, int $product_id, int $quantity = 2 ): array {
		return array(
			'mutationId'   => sprintf( '10000000-0000-4000-8000-%012d', $sequence ),
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => sprintf( '20000000-0000-4000-8000-%012d', $sequence ),
			'baseRevision' => null,
			'payload'      => array(
				'status'     => $status,
				'line_items' => array(
					array(
						'product_id' => $product_id,
						'quantity'   => $quantity,
					),
				),
			),
		);
	}

	/**
	 * Paid statuses: the v2 push must surface the insufficient-stock 400
	 * with the public error contract v1 pinned (code + per-line items).
	 *
	 * @dataProvider paid_status_provider
	 *
	 * @param string $status   Target paid status.
	 * @param int    $sequence Unique envelope sequence.
	 */
	public function test_v2_push_paid_status_insufficient_stock_returns_400( string $status, int $sequence ): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1 );

		$response = $this->push_order( $this->oversell_create_envelope( $sequence, $status, $product->get_id() ) );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'wcpos_insufficient_stock', $data['code'] );
		$this->assertSame( 'Cannot complete order: 1 item(s) exceed available stock.', $data['message'] );
		$this->assertEquals(
			array(
				array(
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
					'name'         => $product->get_name(),
					'requested'    => 2,
					'available'    => 1,
					'reason'       => 'insufficient_stock',
					'backorders'   => 'no',
				),
			),
			$data['data']['items']
		);
	}

	/**
	 * Paid statuses that must validate stock on the v2 push.
	 *
	 * @return array<string,array{string,int}>
	 */
	public function paid_status_provider(): array {
		return array(
			'processing' => array( 'processing', 101 ),
			'completed'  => array( 'completed', 102 ),
		);
	}

	/**
	 * Draft-land statuses stay exempt: the oversold order still syncs.
	 *
	 * @dataProvider exempt_status_provider
	 *
	 * @param string $status   Target exempt status.
	 * @param int    $sequence Unique envelope sequence.
	 */
	public function test_v2_push_exempt_status_syncs_despite_oversell( string $status, int $sequence ): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1 );

		$response = $this->push_order( $this->oversell_create_envelope( $sequence, $status, $product->get_id() ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$this->assertNotFalse( $order );
		$this->assertSame( $status, $order->get_status() );
	}

	/**
	 * Exempt statuses from the Stock_Validator gate.
	 *
	 * @return array<string,array{string,int}>
	 */
	public function exempt_status_provider(): array {
		return array(
			'pending'     => array( 'pending', 111 ),
			'pos-open'    => array( 'pos-open', 112 ),
			'pos-partial' => array( 'pos-partial', 113 ),
		);
	}

	/**
	 * With the setting off, paid oversold orders pass through untouched.
	 */
	public function test_v2_push_disabled_setting_does_not_validate_stock(): void {
		$this->set_prevent_overselling( false );
		$product = $this->create_stock_product( 1 );

		$response = $this->push_order( $this->oversell_create_envelope( 121, 'processing', $product->get_id() ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * The checkout transition itself: an open POS draft syncs oversold, but
	 * the v2 UPDATE that moves it to a paid status is rejected.
	 */
	public function test_v2_push_checkout_transition_update_validates_stock(): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1 );

		$created = $this->push_order( $this->oversell_create_envelope( 131, 'pos-open', $product->get_id() ) );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$order_id = (int) $created->get_data()['document']['id'];

		$updated = $this->push_order(
			array(
				'mutationId'   => '10000000-0000-4000-8000-000000000132',
				'operation'    => 'update',
				'collection'   => 'orders',
				'recordId'     => '20000000-0000-4000-8000-000000000131',
				'baseRevision' => $created->get_data()['currentRevision'],
				'payload'      => array( 'status' => 'processing' ),
			)
		);
		$data = $updated->get_data();

		$this->assertSame( 400, $updated->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'wcpos_insufficient_stock', $data['code'] );
		$order = wc_get_order( $order_id );
		$this->assertNotFalse( $order );
		$this->assertSame( 'pos-open', $order->get_status(), 'The rejected checkout must leave the draft untouched.' );
	}

	/**
	 * Unmanaged out-of-stock products block with their own reason code.
	 */
	public function test_v2_push_unmanaged_out_of_stock_product_blocks(): void {
		$this->set_prevent_overselling( true );
		$product = ProductHelper::create_simple_product(
			array(
				'stock_status'  => 'outofstock',
				'regular_price' => 10,
				'price'         => 10,
			)
		);

		$response = $this->push_order( $this->oversell_create_envelope( 141, 'processing', $product->get_id(), 1 ) );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'wcpos_insufficient_stock', $data['code'] );
		$this->assertSame( 'out_of_stock_status', $data['data']['items'][0]['reason'] );
		$this->assertNull( $data['data']['items'][0]['available'] );
	}

	/**
	 * Backorder-allowing products do not block paid statuses.
	 */
	public function test_v2_push_backorders_do_not_block(): void {
		$this->set_prevent_overselling( true );
		$product = $this->create_stock_product( 1, 'yes' );

		$response = $this->push_order( $this->oversell_create_envelope( 151, 'processing', $product->get_id() ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}
}
