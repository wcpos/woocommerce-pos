<?php
/**
 * Route-dispatch pins for the POS order-tax behaviors on the v2 push (#1402).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Admin_Settings;
use WC_Product;
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WP_REST_Response;

/**
 * Drives POST /wcpos/v2/push/orders through the REAL registered route and
 * pins the Test_Order_Taxes matrix on the v2 lane: the POS tax-based-on
 * meta (store base vs billing vs shipping via Orders::get_tax_location),
 * the per-line _woocommerce_pos_data tax_status override, and the
 * negative-fee tax quirk. The hooks are global but gated per-order on
 * wcpos_is_pos_order(), which the push satisfies by injecting
 * created_via=woocommerce-pos into the forwarded wc/v3 create.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Rest_Dispatch_Order_Taxes extends Sync_REST_Store_Test_Case {
	/**
	 * Enable the v2 sync routes, mark the request as POS, and seed the
	 * same GB/US tax table the v1 matrix uses.
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );

		TaxHelper::create_tax_rate(
			array(
				'country'  => 'GB',
				'rate'     => '20.000',
				'name'     => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'rate'     => '10.000',
				'name'     => 'US',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country'  => 'US',
				'state'    => 'AL',
				'postcode' => '12345; 123456',
				'rate'     => '2.000',
				'name'     => 'US AL',
				'priority' => 2,
				'compound' => true,
				'shipping' => true,
			)
		);
	}

	/**
	 * Restore request state.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Create a plain taxable product.
	 *
	 * @param float $price Product price.
	 */
	private function create_taxable_product( float $price = 10 ): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'regular_price' => $price,
				'price'         => $price,
				'tax_status'    => 'taxable',
				'tax_class'     => '',
			)
		);
	}

	/**
	 * Dispatch one order-create envelope through the registered v2 route.
	 *
	 * @param int   $sequence Unique per-test envelope sequence number.
	 * @param array $payload  Order payload to forward.
	 */
	private function push_order_create( int $sequence, array $payload ): WP_REST_Response {
		$envelope = array(
			'mutationId'   => sprintf( '10000000-0000-4000-8000-%012d', $sequence ),
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => sprintf( '20000000-0000-4000-8000-%012d', $sequence ),
			'baseRevision' => null,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $envelope['mutationId'] );
		$request->set_body( (string) wp_json_encode( $envelope ) );

		return $this->server->dispatch( $request );
	}

	/**
	 * Assert the created document carries exactly one tax-based-on meta.
	 *
	 * @param array  $document Served order document.
	 * @param string $expected Expected meta value.
	 */
	private function assert_tax_based_on_meta( array $document, string $expected ): void {
		$values = array();
		foreach ( $document['meta_data'] as $meta ) {
			$meta = $meta instanceof \WC_Meta_Data ? $meta->get_data() : (array) $meta;
			if ( '_woocommerce_pos_tax_based_on' === $meta['key'] ) {
				$values[] = $meta['value'];
			}
		}

		$this->assertSame( array( $expected ), $values, 'There should be exactly one _woocommerce_pos_tax_based_on meta.' );
	}

	/**
	 * The store-base default: US:CA store applies the US 10% rate, and the
	 * forwarded create is marked as a POS order (created_via) — the gate
	 * every one of these tax behaviors hangs off.
	 */
	public function test_v2_push_order_taxes_use_store_base_location(): void {
		$this->assertEquals( 'base', WC_Admin_Settings::get_option( 'woocommerce_tax_based_on' ) );
		$this->assertEquals( 'US:CA', WC_Admin_Settings::get_option( 'woocommerce_default_country' ) );
		$product = $this->create_taxable_product();

		$response = $this->push_order_create(
			201,
			array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$this->assertSame( 'woocommerce-pos', $order->get_created_via(), 'The v2 push must mark the order as a POS order.' );
		$tax_lines = array_values( $order->get_items( 'tax' ) );
		$this->assertCount( 1, $tax_lines );
		$this->assertSame( 'US', $tax_lines[0]->get_label() );
		$this->assertSame( 1.0, (float) $order->get_total_tax() );
		$this->assertSame( 11.0, (float) $order->get_total() );
	}

	/**
	 * Billing tax-based-on: the GB billing address wins over the US store base.
	 */
	public function test_v2_push_order_taxes_use_billing_location(): void {
		$product = $this->create_taxable_product();

		$response = $this->push_order_create(
			211,
			array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'billing'    => array(
					'country' => 'GB',
				),
				'meta_data'  => array(
					array(
						'key'   => '_woocommerce_pos_tax_based_on',
						'value' => 'billing',
					),
				),
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assert_tax_based_on_meta( $response->get_data()['document'], 'billing' );
		$order     = wc_get_order( (int) $response->get_data()['document']['id'] );
		$tax_lines = array_values( $order->get_items( 'tax' ) );
		$this->assertCount( 1, $tax_lines );
		$this->assertSame( 'VAT', $tax_lines[0]->get_label() );
		$this->assertSame( 2.0, (float) $order->get_total_tax() );
		$this->assertSame( 12.0, (float) $order->get_total() );
	}

	/**
	 * Shipping tax-based-on: the US AL shipping address stacks both US rates.
	 */
	public function test_v2_push_order_taxes_use_shipping_location(): void {
		$product = $this->create_taxable_product();

		$response = $this->push_order_create(
			221,
			array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'shipping'   => array(
					'country'  => 'US',
					'state'    => 'AL',
					'postcode' => '12345',
				),
				'meta_data'  => array(
					array(
						'key'   => '_woocommerce_pos_tax_based_on',
						'value' => 'shipping',
					),
				),
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assert_tax_based_on_meta( $response->get_data()['document'], 'shipping' );
		$order     = wc_get_order( (int) $response->get_data()['document']['id'] );
		$tax_lines = array_values( $order->get_items( 'tax' ) );
		$this->assertCount( 2, $tax_lines );
		$this->assertSame( array( 'US', 'US AL' ), array( $tax_lines[0]->get_label(), $tax_lines[1]->get_label() ) );
		$this->assertSame( 1.0, (float) $tax_lines[0]->get_tax_total() );
		$this->assertSame( 0.22, (float) $tax_lines[1]->get_tax_total() );
		$this->assertSame( 1.22, (float) $order->get_total_tax() );
		$this->assertSame( 11.22, (float) $order->get_total() );
	}

	/**
	 * The per-line _woocommerce_pos_data tax_status override zeroes the
	 * line's taxes (Orders::order_item_after_calculate_taxes).
	 */
	public function test_v2_push_line_item_pos_data_tax_status_none_zeroes_line_tax(): void {
		$product = $this->create_taxable_product();

		$response = $this->push_order_create(
			231,
			array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
						'meta_data'  => array(
							array(
								'key'   => '_woocommerce_pos_data',
								'value' => '{"tax_status":"none"}',
							),
						),
					),
				),
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$this->assertSame( 0.0, (float) $order->get_total_tax() );
		$this->assertSame( 10.0, (float) $order->get_total() );
	}

	/**
	 * The negative-fee quirk v1 pinned: WooCommerce bypasses tax_status for
	 * negative fees; the POS handler restores it so a tax_status=none fee
	 * carries no tax.
	 *
	 * PROBED RED on the v2 lane (2026-07-30, evidence on #1402): the handler
	 * (V1\Orders_Controller::wcpos_order_item_fee_after_calculate_taxes) is
	 * registered inside wcpos_dispatch_request(), which API::rest_dispatch_request
	 * only invokes for mapped /wcpos/v1/* routes — the push's inner wc/v3
	 * dispatch never registers it, so the fee is taxed -1.00 instead of 0.00.
	 * Escalated per the issue contract instead of pinned; the production fix
	 * should delete the markTestIncomplete line below and inherit this pin.
	 */
	public function test_v2_push_negative_fee_respects_tax_status_none(): void {
		$this->markTestIncomplete( 'Negative-fee tax_status does not reach the v2 forward — escalated on #1402, fix pending.' );
		$product = $this->create_taxable_product( 20 );

		$response = $this->push_order_create(
			241,
			array(
				'line_items' => array(
					array(
						'product_id' => $product->get_id(),
						'quantity'   => 1,
					),
				),
				'fee_lines'  => array(
					array(
						'name'       => 'Fee',
						'total'      => '-10',
						'tax_status' => 'none',
					),
				),
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order     = wc_get_order( (int) $response->get_data()['document']['id'] );
		$fee_lines = array_values( $order->get_items( 'fee' ) );
		$this->assertCount( 1, $fee_lines );
		$this->assertSame( -10.0, (float) $fee_lines[0]->get_total() );
		$this->assertSame( 0.0, (float) $fee_lines[0]->get_total_tax() );
		$this->assertSame( 2.0, (float) $order->get_total_tax() );
		$this->assertSame( 12.0, (float) $order->get_total() );
	}
}
