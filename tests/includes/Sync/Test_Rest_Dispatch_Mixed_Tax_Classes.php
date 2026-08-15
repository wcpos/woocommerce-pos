<?php
/**
 * Route-dispatch pins for mixed tax classes on v2 order pushes (#1456).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WP_REST_Response;

/**
 * Pins standard, reduced, and zero-rate lines through the real v2 push route.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Rest_Dispatch_Mixed_Tax_Classes extends Sync_REST_Store_Test_Case {
	/**
	 * Sequence used for unique mutation envelopes.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Enable tax calculation and seed the v1 GB tax-class fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'GB' );

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
				'country'  => 'GB',
				'rate'     => '5.000',
				'name'     => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
				'class'    => 'reduced-rate',
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country'  => 'GB',
				'rate'     => '0.000',
				'name'     => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
				'class'    => 'zero-rate',
			)
		);
	}

	/**
	 * Restore request state.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
	}

	/**
	 * Create a taxable product in one tax class.
	 *
	 * @param string $tax_class Tax class slug.
	 *
	 * @return WC_Product
	 */
	private function product( string $tax_class ): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'regular_price' => 10,
				'price'         => 10,
				'tax_status'    => 'taxable',
				'tax_class'     => $tax_class,
			)
		);
	}

	/**
	 * Build a $10 POS line for a product tax class.
	 *
	 * @param WC_Product $product Catalog product.
	 *
	 * @return array
	 */
	private function line( WC_Product $product ): array {
		return array(
			'product_id' => $product->get_id(),
			'quantity'   => 1,
			'subtotal'   => '10',
			'total'      => '10',
			'meta_data'  => array(
				array(
					'key'   => '_woocommerce_pos_data',
					'value' => wp_json_encode(
						array(
							'price'         => '10',
							'regular_price' => '10',
							'tax_status'    => 'taxable',
						)
					),
				),
			),
		);
	}

	/**
	 * Dispatch a mixed-class create through the registered v2 push route.
	 *
	 * @param array $coupon_lines Optional coupon lines.
	 *
	 * @return WP_REST_Response
	 */
	private function push_order( array $coupon_lines = array() ): WP_REST_Response {
		$sequence = ++$this->sequence;
		$payload  = array(
			'line_items' => array(
				$this->line( $this->product( '' ) ),
				$this->line( $this->product( 'reduced-rate' ) ),
				$this->line( $this->product( 'zero-rate' ) ),
			),
		);
		if ( $coupon_lines ) {
			$payload['coupon_lines'] = $coupon_lines;
		}
		$envelope = array(
			'mutationId'   => sprintf( '55000000-0000-4000-8000-%012d', $sequence ),
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => sprintf( '56000000-0000-4000-8000-%012d', $sequence ),
			'baseRevision' => null,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Return a successful create's persisted order.
	 *
	 * @param WP_REST_Response $response Push response.
	 *
	 * @return WC_Order
	 */
	private function created_order( WP_REST_Response $response ): WC_Order {
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	/**
	 * Index persisted order tax totals by rate percent.
	 *
	 * @param WC_Order $order Persisted order.
	 *
	 * @return array
	 */
	private function tax_totals_by_rate( WC_Order $order ): array {
		$totals = array();
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			$totals[ wc_format_decimal( $tax->get_rate_percent(), 2 ) ] = round( (float) $tax->get_tax_total(), 2 );
		}
		return $totals;
	}

	/**
	 * Assert each persisted line's discounted total and tax.
	 *
	 * @param WC_Order $order  Persisted order.
	 * @param float    $total  Expected total for each line.
	 * @param array    $taxes  Expected per-line taxes in class order.
	 *
	 * @return void
	 */
	private function assert_line_amounts( WC_Order $order, float $total, array $taxes ): void {
		$lines = array_values( $order->get_items( 'line_item' ) );
		$this->assertCount( 3, $lines );
		foreach ( $lines as $index => $line ) {
			$this->assertInstanceOf( WC_Order_Item_Product::class, $line );
			$this->assertEquals( $total, round( (float) $line->get_total(), 2 ) );
			$this->assertEquals( $taxes[ $index ], round( (float) $line->get_total_tax(), 2 ) );
		}
	}

	/**
	 * Standard, reduced, and zero-rate lines persist their independent tax totals.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\API\Test_Order_Taxes::setUp()
	 *
	 * @return void
	 */
	public function test_mixed_tax_classes_without_coupon_persist_per_line_tax_order_tax_lines_and_total(): void {
		// Arrange and Act.
		$order = $this->created_order( $this->push_order() );
		// Assert.
		$this->assert_line_amounts( $order, 10.00, array( 2.00, 0.50, 0.00 ) );
		$tax_totals = $this->tax_totals_by_rate( $order );
		$this->assertCount( 3, $tax_totals );
		$this->assertEquals( 2.00, $tax_totals['20.00'] );
		$this->assertEquals( 0.50, $tax_totals['5.00'] );
		$this->assertEquals( 0.00, $tax_totals['0.00'] );
		$this->assertEquals( 2.50, round( (float) $order->get_total_tax(), 2 ) );
		$this->assertEquals( 32.50, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * A percent coupon discounts every mixed-class line before its own rate is applied.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_coupon_with_tax_exclusive_and_pos_discount()
	 * @see \WCPOS\WooCommercePOS\Tests\API\Test_Order_Taxes::setUp()
	 *
	 * @return void
	 */
	public function test_percent_coupon_across_mixed_tax_classes_discounts_each_line_and_recalculates_class_tax(): void {
		// Arrange.
		CouponHelper::create_coupon(
			'push-mixed-classes-10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);
		// Act.
		$order = $this->created_order( $this->push_order( array( array( 'code' => 'push-mixed-classes-10' ) ) ) );
		// Assert.
		$this->assert_line_amounts( $order, 9.00, array( 1.80, 0.45, 0.00 ) );
		$tax_totals = $this->tax_totals_by_rate( $order );
		$this->assertCount( 3, $tax_totals );
		$this->assertEquals( 1.80, $tax_totals['20.00'] );
		$this->assertEquals( 0.45, $tax_totals['5.00'] );
		$this->assertEquals( 0.00, $tax_totals['0.00'] );
		$this->assertEquals( 2.25, round( (float) $order->get_total_tax(), 2 ) );
		$this->assertEquals( 29.25, round( (float) $order->get_total(), 2 ) );
	}
}
