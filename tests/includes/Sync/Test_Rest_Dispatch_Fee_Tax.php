<?php
/**
 * Route-dispatch pins for negative-fee tax semantics on the v2 push (issue #1403 row 2).
 *
 * WooCommerce routes negative fees through its discount tax path, disregarding the
 * fee's tax_status/tax_class. The v1 controller corrected this per-dispatch; the fix
 * moves the handler to the globally-registered Orders service (request-gated) so the
 * v2 push's inner wc/v3 forward shares it. These pins drive the REAL registered route
 * (`POST /wcpos/v2/push/orders`, real inner wc/v3 dispatch, real Mutation_Store).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact pin scenarios.

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\Sync\Api;
use WC_Tax;
use WP_REST_Request;

/**
 * @covers \WCPOS\WooCommercePOS\Orders::fee_after_calculate_taxes
 */
class Test_Rest_Dispatch_Fee_Tax extends Sync_REST_Store_Test_Case {
	private const REC = '6c9f2b4d-3e5a-4b7c-8d9e-2e3f4a5b6c7d';

	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		// The fee handler gates on wcpos_request(), which reads the real HTTP header.
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_default_country', 'US:AL' );
		WC_Tax::create_tax_class( 'Reduced rate pin' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'US10',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate'          => '5.0000',
				'tax_rate_name'     => 'US5',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => 'reduced-rate-pin',
			)
		);
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate-pin' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/** Push one order-create envelope through the real route; returns the REST response. */
	private function push_order_create( array $fee_lines, string $mutation ) {
		$product  = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$envelope = array(
			'mutationId'   => $mutation,
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => self::REC,
			'baseRevision' => null,
			'payload'      => array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'fee_lines'  => $fee_lines,
				'meta_data'  => array(
					array(
						'key'   => '_woocommerce_pos_uuid',
						'value' => self::REC,
					),
				),
			),
		);
		$request  = $this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/' . Api::ROUTE_PREFIX . 'push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	private function single_fee( int $order_id ): \WC_Order_Item_Fee {
		$fees = array_values( wc_get_order( $order_id )->get_items( 'fee' ) );
		$this->assertCount( 1, $fees );

		return $fees[0];
	}

	public function test_negative_fee_with_tax_status_none_carries_no_tax_through_v2_push(): void {
		$response = $this->push_order_create(
			array(
				array(
					'name'       => 'POS discount',
					'total'      => '-2.00',
					'tax_status' => 'none',
				),
			),
			'b2c3d4e5-2222-4333-8444-000000000001'
		);

		$this->assertSame( 201, $response->get_status() );
		$order_id = (int) $response->get_data()['document']['id'];
		$fee      = $this->single_fee( $order_id );
		$this->assertSame( 0.0, (float) $fee->get_total_tax() );
		$order = wc_get_order( $order_id );
		$this->assertSame( '9.00', $order->get_total() );
		$this->assertSame( '1.00', wc_format_decimal( $order->get_total_tax(), 2 ) );
	}

	public function test_negative_taxable_fee_uses_its_own_tax_class_through_v2_push(): void {
		$response = $this->push_order_create(
			array(
				array(
					'name'       => 'Class-aware discount',
					'total'      => '-1.00',
					'tax_status' => 'taxable',
					'tax_class'  => 'reduced-rate-pin',
				),
			),
			'b2c3d4e5-2222-4333-8444-000000000002'
		);

		$this->assertSame( 201, $response->get_status() );
		$fee = $this->single_fee( (int) $response->get_data()['document']['id'] );
		// 5% reduced class on -1.00 — NOT the proportional 10% line allocation (-0.10).
		$this->assertSame( '-0.05', wc_format_decimal( $fee->get_total_tax(), 2 ) );
	}

	public function test_positive_taxable_fee_keeps_stock_wc_taxes_through_v2_push(): void {
		$response = $this->push_order_create(
			array(
				array(
					'name'       => 'Surcharge',
					'total'      => '3.00',
					'tax_status' => 'taxable',
				),
			),
			'b2c3d4e5-2222-4333-8444-000000000003'
		);

		$this->assertSame( 201, $response->get_status() );
		$fee = $this->single_fee( (int) $response->get_data()['document']['id'] );
		$this->assertSame( '0.30', wc_format_decimal( $fee->get_total_tax(), 2 ) );
	}

	public function test_pos_marked_direct_wc_requests_get_pos_fee_semantics(): void {
		// Intentional scope: the gate is the POS request marker, not the v1 route
		// table — ANY POS-marked write that recalculates fees (including a direct
		// wc/v3 dispatch, which is exactly what the v2 push forward is) gets the
		// corrected semantics.
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_body_params(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'fee_lines'  => array(
					array(
						'name'       => 'Marked discount',
						'total'      => '-2.00',
						'tax_status' => 'none',
					),
				),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$fee = $this->single_fee( (int) $response->get_data()['id'] );
		$this->assertSame( 0.0, (float) $fee->get_total_tax() );
	}

	public function test_non_pos_wc_requests_keep_stock_negative_fee_behavior(): void {
		// The handler is request-gated: a plain wc/v3 write (no X-WCPOS header)
		// must keep WooCommerce's stock discount-tax allocation untouched.
		unset( $_SERVER['HTTP_X_WCPOS'] );
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$request->set_body_params(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'fee_lines'  => array(
					array(
						'name'       => 'Stock discount',
						'total'      => '-2.00',
						'tax_status' => 'none',
					),
				),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$fee = $this->single_fee( (int) $response->get_data()['id'] );
		$this->assertSame( '-0.20', wc_format_decimal( $fee->get_total_tax(), 2 ) );
	}

	public function test_v1_orders_route_still_zeroes_negative_none_fee(): void {
		// The v1 per-dispatch registration was removed; the global handler must
		// keep serving the v1 surface unchanged.
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
			)
		);
		$request = $this->wp_rest_post_request( '/wcpos/v1/orders' );
		$request->set_body_params(
			array(
				'status'     => 'processing',
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'fee_lines'  => array(
					array(
						'name'       => 'POS discount',
						'total'      => '-2.00',
						'tax_status' => 'none',
					),
				),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$fee = $this->single_fee( (int) $response->get_data()['id'] );
		$this->assertSame( 0.0, (float) $fee->get_total_tax() );
	}
}
