<?php
/**
 * Order serialization precision tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Order_Item_Product;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WP_REST_Request;

/**
 * Pins six-decimal money serialization to the WCPOS v2 order lanes.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Orders_Controller
 * @covers \WCPOS\WooCommercePOS\API\V2\Write_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Order_Serializer
 */
class Test_Order_Serialization_Precision extends Sync_REST_Store_Test_Case {
	/**
	 * Previous store price-decimals setting.
	 *
	 * @var mixed
	 */
	private $previous_price_decimals;

	/**
	 * Enable v2 sync and pin the store display precision to two decimals.
	 */
	public function setUp(): void {
		$this->previous_price_decimals = get_option( 'woocommerce_price_num_decimals', null );
		update_option( 'woocommerce_price_num_decimals', 2 );
		parent::setUp();
	}

	/**
	 * Restore the store settings changed by the test.
	 */
	public function tearDown(): void {
		if ( null === $this->previous_price_decimals ) {
			delete_option( 'woocommerce_price_num_decimals' );
		} else {
			update_option( 'woocommerce_price_num_decimals', $this->previous_price_decimals );
		}
		parent::tearDown();
	}

	/**
	 * Create an order whose line totals retain meaningful sub-cent precision.
	 *
	 * @return WC_Order Persisted order.
	 */
	private function create_sub_cent_order(): WC_Order {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => '9.999',
				'price'         => '9.999',
			)
		);
		$order   = new WC_Order();
		$order->set_status( 'pending' );
		$order->set_currency( 'USD' );
		$order->save();

		$item = new WC_Order_Item_Product();
		$item->set_props(
			array(
				'product'  => $product,
				'quantity' => 3,
				'subtotal' => '29.997',
				'total'    => '29.997',
			)
		);
		$order->add_item( $item );
		$order->set_total( '29.997' );
		$order->save();
		Pos_Uuid::ensure_uuid( $order );

		return $order;
	}

	/**
	 * A v2 push acknowledgement preserves six-decimal line totals.
	 */
	public function test_v2_push_ack_with_sub_cent_line_returns_six_decimal_money(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => '9.999',
				'price'         => '9.999',
			)
		);
		$envelope = array(
			'mutationId'   => '10000000-0000-4000-8000-000000000601',
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => '20000000-0000-4000-8000-000000000601',
			'baseRevision' => null,
			'payload'      => array(
				'status'     => 'pending',
				'currency'   => 'USD',
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 3,
						'subtotal'   => '29.997',
						'total'      => '29.997',
						'meta_data'  => array(
							array(
								'key'   => '_woocommerce_pos_uuid',
								'value' => '30000000-0000-4000-8000-000000000601',
							),
						),
					),
				),
				'meta_data'  => array(
					array(
						'key'   => '_woocommerce_pos_uuid',
						'value' => '20000000-0000-4000-8000-000000000601',
					),
				),
			),
		);
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		// Act.
		$response = $this->server->dispatch( $request );
		$line     = $response->get_data()['document']['line_items'][0];

		// Assert.
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '29.997000', $line['subtotal'] );
		$this->assertEquals( '29.997000', $line['total'] );
	}

	/**
	 * The v2 orders pull preserves six-decimal line totals.
	 */
	public function test_v2_orders_pull_with_sub_cent_line_returns_six_decimal_money(): void {
		// Arrange.
		$order = $this->create_sub_cent_order();
		( new Sync_Journal() )->record_order_change( $order->get_id(), 'test:precision-pull', false );
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders/pull' );
		$request->set_query_params(
			array(
				'limit'          => 100,
				'updated_at_gmt' => '1970-01-01T00:00:00.000Z',
				'order_id'       => 0,
				'sequence'       => 0,
			)
		);

		// Act.
		$response = $this->server->dispatch( $request );
		$line     = $response->get_data()['documents'][0]['payload']['line_items'][0];

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( '29.997000', $line['subtotal'] );
		$this->assertEquals( '29.997000', $line['total'] );
	}

	/**
	 * The proxy orders lane serves six decimals so revisions agree across lanes.
	 */
	public function test_v2_orders_proxy_with_sub_cent_line_returns_six_decimal_money(): void {
		// Arrange.
		$order   = $this->create_sub_cent_order();
		$request = $this->wp_rest_get_request( '/wcpos/v2/orders' );
		$request->set_query_params( array( 'include' => (string) $order->get_id() ) );

		// Act.
		$response = $this->server->dispatch( $request );
		$line     = $response->get_data()[0]['line_items'][0];

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( '29.997000', $line['subtotal'] );
		$this->assertEquals( '29.997000', $line['total'] );
	}

	/**
	 * Plain wc/v3 order reads continue using the store's two-decimal precision.
	 */
	public function test_plain_wc_v3_order_read_without_pos_marker_returns_store_decimal_money(): void {
		// Arrange.
		$order   = $this->create_sub_cent_order();
		$request = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() );

		// Act.
		$response = $this->server->dispatch( $request );
		$line     = $response->get_data()['line_items'][0];

		// Assert.
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( '30.00', $line['subtotal'] );
		$this->assertEquals( '30.00', $line['total'] );
	}
}
