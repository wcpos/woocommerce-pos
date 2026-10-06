<?php
/**
 * Route-dispatch pins for TallyUI order.create v5.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact pin scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Tax;

/**
 * Pins the ADR-075 (TallyUI order.create v5) Woo mapping through the real v2 push
 * route: fee_lines, shipping_lines and a product_id 0 custom line.
 *
 * @coversNothing
 */
class Test_Rest_Dispatch_Tally_Order_Create_V5 extends Sync_REST_Store_Test_Case {
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_shipping_tax_class', '' );
		WC_Tax::create_tax_class( 'Tally V5 reduced' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'VAT20',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => '',
			)
		);
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '5.0000',
				'tax_rate_name'     => 'VAT5',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => 'tally-v5-reduced',
			)
		);
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		WC_Tax::delete_tax_class_by( 'slug', 'tally-v5-reduced' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		parent::tearDown();
	}

	private function fee_golden_envelope(): array {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
				'tax_status'    => 'taxable',
				'tax_class'     => '',
			)
		);

		return array(
			'mutationId'   => '0199b0a0-0000-7000-8000-000000000001',
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => '0199b0a0-0000-7000-8000-0000000000a1',
			'baseRevision' => null,
			'payload'      => array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
						'meta_data'  => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000b1',
							),
						),
					),
				),
				'fee_lines'  => array(
					array(
						'name'       => 'Bag',
						'total'      => '0.20',
						'tax_status' => 'taxable',
						'tax_class'  => '',
						'meta_data'  => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000f1',
							),
						),
					),
				),
				'meta_data'  => array(
					array(
						'key' => '_woocommerce_pos_uuid',
						'value' => '0199b0a0-0000-7000-8000-0000000000a1',
					),
				),
			),
		);
	}

	private function push_order_create( array $envelope ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	public function test_v5_fee_golden_pair_records_bag_fee_with_tax(): void {
		// Arrange.
		$envelope = $this->fee_golden_envelope();

		// Act.
		$response = $this->push_order_create( $envelope );

		// Assert.
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$fees  = array_values( $order->get_items( 'fee' ) );
		$this->assertCount( 1, $fees );
		$this->assertSame( 'Bag', $fees[0]->get_name() );
		$this->assertSame( '0.20', wc_format_decimal( $fees[0]->get_total(), 2 ) );
		$this->assertSame( '0.04', wc_format_decimal( $fees[0]->get_total_tax(), 2 ) );
		$this->assertSame( '0199b0a0-0000-7000-8000-0000000000f1', $fees[0]->get_meta( '_woocommerce_pos_uuid', true ) );
		$this->assertSame( '2.04', wc_format_decimal( $order->get_total_tax(), 2 ) );
		$this->assertSame( '12.24', wc_format_decimal( $order->get_total(), 2 ) );
	}

	public function test_v5_shipping_and_custom_line_golden_pair_records_both_charged(): void {
		// Arrange.
		$product  = ProductHelper::create_simple_product(
			array(
				'regular_price' => 25,
				'price'         => 25,
				'tax_status'    => 'taxable',
				'tax_class'     => '',
			)
		);
		$envelope = array(
			'mutationId'   => '0199b0a0-0000-7000-8000-000000000002',
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => '0199b0a0-0000-7000-8000-0000000000a2',
			'baseRevision' => null,
			'payload'      => array(
				'line_items'     => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
						'meta_data'  => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000b2',
							),
						),
					),
					array(
						'product_id' => 0,
						'name'       => 'Gift wrap',
						'quantity'   => 1,
						'subtotal'   => '3.00',
						'total'      => '3.00',
						'tax_class'  => '',
						'sku'        => 'GW-1',
						'meta_data'  => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000b3',
							),
							array(
								'key'   => '_woocommerce_pos_data',
								'value' => wp_json_encode(
									array(
										'price' => '3.00',
										'regular_price' => '3.00',
										'tax_status' => 'none',
									)
								),
							),
						),
					),
				),
				'shipping_lines' => array(
					array(
						'method_id'    => 'flat_rate',
						'method_title' => 'Local delivery',
						'total'        => '5.00',
						'meta_data'    => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000d1',
							),
						),
					),
				),
				'meta_data'      => array(
					array(
						'key' => '_woocommerce_pos_uuid',
						'value' => '0199b0a0-0000-7000-8000-0000000000a2',
					),
				),
			),
		);

		// Act.
		$response = $this->push_order_create( $envelope );

		// Assert.
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order        = wc_get_order( (int) $response->get_data()['document']['id'] );
		$custom_items = array_values(
			array_filter(
				$order->get_items( 'line_item' ),
				static function ( $item ) {
					return 0 === $item->get_product_id();
				}
			)
		);
		$this->assertCount( 1, $custom_items );
		$this->assertSame( 'Gift wrap', $custom_items[0]->get_name() );
		$this->assertSame( '3.00', wc_format_decimal( $custom_items[0]->get_total(), 2 ) );
		$this->assertSame( '0.00', wc_format_decimal( $custom_items[0]->get_total_tax(), 2 ) );
		$this->assertSame( 'GW-1', $custom_items[0]->get_meta( '_sku', true ) );
		$this->assertSame( '0199b0a0-0000-7000-8000-0000000000b3', $custom_items[0]->get_meta( '_woocommerce_pos_uuid', true ) );
		$shipping = array_values( $order->get_items( 'shipping' ) );
		$this->assertCount( 1, $shipping );
		$this->assertSame( 'flat_rate', $shipping[0]->get_method_id() );
		$this->assertSame( 'Local delivery', $shipping[0]->get_method_title() );
		$this->assertSame( '5.00', wc_format_decimal( $shipping[0]->get_total(), 2 ) );
		$this->assertSame( '1.00', wc_format_decimal( $shipping[0]->get_total_tax(), 2 ) );
		$this->assertSame( '0199b0a0-0000-7000-8000-0000000000d1', $shipping[0]->get_meta( '_woocommerce_pos_uuid', true ) );
		$this->assertSame( '6.00', wc_format_decimal( $order->get_total_tax(), 2 ) );
		$this->assertSame( '39.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	public function test_v5_shipping_without_method_id_records_pos_method(): void {
		// Arrange.
		$product  = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
				'tax_status'    => 'taxable',
				'tax_class'     => '',
			)
		);
		$envelope = array(
			'mutationId'   => '0199b0a0-0000-7000-8000-000000000003',
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => '0199b0a0-0000-7000-8000-0000000000a3',
			'baseRevision' => null,
			'payload'      => array(
				'line_items'     => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
						'meta_data'  => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000b4',
							),
						),
					),
				),
				'shipping_lines' => array(
					array(
						'method_id'    => 'pos',
						'method_title' => 'Courier',
						'total'        => '5.00',
						'meta_data'    => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000d2',
							),
						),
					),
				),
				'meta_data'      => array(
					array(
						'key' => '_woocommerce_pos_uuid',
						'value' => '0199b0a0-0000-7000-8000-0000000000a3',
					),
				),
			),
		);

		// Act.
		$response = $this->push_order_create( $envelope );

		// Assert.
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order    = wc_get_order( (int) $response->get_data()['document']['id'] );
		$shipping = array_values( $order->get_items( 'shipping' ) );
		$this->assertCount( 1, $shipping );
		$this->assertSame( 'pos', $shipping[0]->get_method_id() );
		$this->assertSame( 'Courier', $shipping[0]->get_method_title() );
		$this->assertSame( '5.00', wc_format_decimal( $shipping[0]->get_total(), 2 ) );
		$this->assertSame( '1.00', wc_format_decimal( $shipping[0]->get_total_tax(), 2 ) );
		$this->assertSame( '18.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	public function test_v5_custom_line_and_fee_honour_tax_class_and_status(): void {
		// Arrange.
		$envelope = array(
			'mutationId'   => '0199b0a0-0000-7000-8000-000000000004',
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => '0199b0a0-0000-7000-8000-0000000000a4',
			'baseRevision' => null,
			'payload'      => array(
				'line_items' => array(
					array(
						'product_id' => 0,
						'name'       => 'Engraving',
						'quantity'   => 2,
						'subtotal'   => '20.00',
						'total'      => '20.00',
						'tax_class'  => 'tally-v5-reduced',
						'meta_data'  => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000b5',
							),
							array(
								'key'   => '_woocommerce_pos_data',
								'value' => wp_json_encode(
									array(
										'price' => '10.00',
										'regular_price' => '10.00',
										'tax_status' => 'taxable',
									)
								),
							),
						),
					),
				),
				'fee_lines'  => array(
					array(
						'name'       => 'Service',
						'total'      => '4.00',
						'tax_status' => 'taxable',
						'tax_class'  => 'tally-v5-reduced',
						'meta_data'  => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000f2',
							),
						),
					),
					array(
						'name'       => 'Deposit',
						'total'      => '1.00',
						'tax_status' => 'none',
						'tax_class'  => '',
						'meta_data'  => array(
							array(
								'key' => '_woocommerce_pos_uuid',
								'value' => '0199b0a0-0000-7000-8000-0000000000f3',
							),
						),
					),
				),
				'meta_data'  => array(
					array(
						'key' => '_woocommerce_pos_uuid',
						'value' => '0199b0a0-0000-7000-8000-0000000000a4',
					),
				),
			),
		);

		// Act.
		$response = $this->push_order_create( $envelope );

		// Assert.
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$lines = array_values( $order->get_items( 'line_item' ) );
		$this->assertCount( 1, $lines );
		$this->assertSame( '1.00', wc_format_decimal( $lines[0]->get_total_tax(), 2 ) );
		$fees = array();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fees[ $fee->get_name() ] = $fee;
		}
		$this->assertArrayHasKey( 'Service', $fees );
		$this->assertArrayHasKey( 'Deposit', $fees );
		$this->assertSame( '0.20', wc_format_decimal( $fees['Service']->get_total_tax(), 2 ) );
		$this->assertSame( '0.00', wc_format_decimal( $fees['Deposit']->get_total_tax(), 2 ) );
		$this->assertSame( '1.20', wc_format_decimal( $order->get_total_tax(), 2 ) );
		$this->assertSame( '26.20', wc_format_decimal( $order->get_total(), 2 ) );
	}

	public function test_v5_fee_golden_pair_replay_returns_same_order_without_duplicates(): void {
		// Arrange.
		$envelope = $this->fee_golden_envelope();

		// Act.
		$first = $this->push_order_create( $envelope );

		// Assert.
		$this->assertSame( 201, $first->get_status(), wp_json_encode( $first->get_data() ) );

		// Act.
		$second = $this->push_order_create( $envelope );

		// Assert.
		$this->assertContains( $second->get_status(), array( 200, 201 ), wp_json_encode( $second->get_data() ) );
		$this->assertSame( $first->get_data()['document']['id'], $second->get_data()['document']['id'] );
		$order = wc_get_order( (int) $first->get_data()['document']['id'] );
		$this->assertCount( 1, $order->get_items( 'fee' ) );
		$this->assertCount( 1, $order->get_items( 'line_item' ) );
		$this->assertCount(
			1,
			wc_get_orders(
				array(
					'limit' => -1,
					'return' => 'ids',
					'created_via' => 'woocommerce-pos',
				)
			)
		);
	}
}
