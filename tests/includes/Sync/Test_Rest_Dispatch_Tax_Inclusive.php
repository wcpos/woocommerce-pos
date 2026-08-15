<?php
/**
 * Route-dispatch pins for tax-inclusive v2 order calculations (#1456).
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact route payloads.
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WP_REST_Response;

/**
 * Pins v1 inclusive-price math through the real v2 push route.
 *
 * @coversNothing
 */
class Test_Rest_Dispatch_Tax_Inclusive extends Sync_REST_Store_Test_Case {
	/**
	 * Sequence used for unique mutation envelopes.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Apply the inclusive store options before REST routes capture their schemas.
	 *
	 * @return void
	 */
	public function setUp(): void {
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'GB' );
		TaxHelper::create_tax_rate(
			array(
				'country'  => 'GB',
				'rate'     => '20.0000',
				'name'     => 'VAT',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);
		TaxHelper::create_tax_rate(
			array(
				'country'  => 'NL',
				'rate'     => '21.0000',
				'name'     => 'BTW',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
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
		update_option( 'woocommerce_prices_include_tax', 'no' );
	}

	/**
	 * Create a taxable product for an inclusive-price scenario.
	 *
	 * @param string $catalog_price Catalog price including tax.
	 *
	 * @return WC_Product
	 */
	private function product( string $catalog_price ): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'regular_price' => $catalog_price,
				'price'         => $catalog_price,
				'tax_status'    => 'taxable',
				'tax_class'     => '',
			)
		);
	}

	/**
	 * Build the v1 shape: POS metadata is inclusive, stored line totals are exclusive.
	 *
	 * @param WC_Product $product       Catalog product.
	 * @param string     $price         POS price including tax.
	 * @param string     $regular_price POS regular price including tax.
	 *
	 * @return array
	 */
	private function inclusive_line( WC_Product $product, string $price, string $regular_price ): array {
		$exclusive = wc_get_price_excluding_tax( $product, array( 'price' => (float) $price ) );
		return array(
			'product_id' => $product->get_id(),
			'quantity'   => 1,
			'subtotal'   => wc_format_decimal( $exclusive, 6 ),
			'total'      => wc_format_decimal( $exclusive, 6 ),
			'meta_data'  => array(
				array(
					'key'   => '_woocommerce_pos_data',
					'value' => wp_json_encode(
						array(
							'price'         => $price,
							'regular_price' => $regular_price,
							'tax_status'    => 'taxable',
						)
					),
				),
			),
		);
	}

	/**
	 * Create a percentage coupon.
	 *
	 * @param string $code Coupon code.
	 *
	 * @return void
	 */
	private function percent_coupon( string $code ): void {
		CouponHelper::create_coupon(
			$code,
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);
	}

	/**
	 * Dispatch one create document through the real v2 push route.
	 *
	 * @param array $payload Order payload.
	 *
	 * @return WP_REST_Response
	 */
	private function push_order( array $payload ): WP_REST_Response {
		$sequence = ++$this->sequence;
		$envelope = array(
			'mutationId'   => sprintf( '53000000-0000-4000-8000-%012d', $sequence ),
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => sprintf( '54000000-0000-4000-8000-%012d', $sequence ),
			'baseRevision' => null,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Return the single persisted line from a successful order create.
	 *
	 * @param WP_REST_Response $response Push response.
	 * @param WC_Order|null    $order    Receives the persisted order.
	 *
	 * @return WC_Order_Item_Product
	 */
	private function created_line( WP_REST_Response $response, ?WC_Order &$order = null ): WC_Order_Item_Product {
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( (int) $response->get_data()['document']['id'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$lines = array_values( $order->get_items( 'line_item' ) );
		$this->assertCount( 1, $lines );
		return $lines[0];
	}

	/**
	 * Assert rounded line and order amounts copied from the v1 matrix.
	 *
	 * @param WC_Order_Item_Product $line        Persisted line.
	 * @param WC_Order              $order       Persisted order.
	 * @param float                 $line_total  Expected exclusive line total.
	 * @param float                 $line_tax    Expected line tax.
	 * @param float                 $order_total Expected inclusive order total.
	 *
	 * @return void
	 */
	private function assert_amounts( WC_Order_Item_Product $line, WC_Order $order, float $line_total, float $line_tax, float $order_total ): void {
		$this->assertEquals( $line_total, round( (float) $line->get_total(), 2 ) );
		$this->assertEquals( $line_tax, round( (float) $line->get_total_tax(), 2 ) );
		$this->assertEquals( $line_tax, round( (float) $order->get_total_tax(), 2 ) );
		$this->assertEquals( $order_total, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * An $80 inclusive POS override persists as $66.67 plus $13.33 VAT.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_coupon_removal_with_tax_inclusive_pricing()
	 *
	 * @return void
	 */
	public function test_inclusive_price_override_without_coupon_persists_exclusive_total_tax_and_inclusive_order_total(): void {
		// Arrange.
		$line = $this->inclusive_line( $this->product( '100' ), '80', '100' );
		// Act.
		$response = $this->push_order( array( 'line_items' => array( $line ) ) );
		// Assert.
		$item = $this->created_line( $response, $order );
		$this->assertEquals( 66.67, round( (float) $item->get_subtotal( 'edit' ), 2 ) );
		$this->assert_amounts( $item, $order, 66.67, 13.33, 80.00 );
	}

	/**
	 * Issue #506: 10% off 447 including 21% VAT produces 402.30.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_issue_506_coupon_with_tax_inclusive_pricing()
	 *
	 * @return void
	 */
	public function test_inclusive_percent_coupon_issue_506_preserves_exact_v1_totals(): void {
		// Arrange.
		update_option( 'woocommerce_default_country', 'NL' );
		$this->percent_coupon( 'push-issue-506' );
		$line = $this->inclusive_line( $this->product( '447' ), '447', '447' );
		// Act.
		$response = $this->push_order( array( 'line_items' => array( $line ), 'coupon_lines' => array( array( 'code' => 'push-issue-506' ) ) ) );
		// Assert.
		$item = $this->created_line( $response, $order );
		$this->assertEquals( 369.42, round( (float) $item->get_subtotal( 'edit' ), 2 ) );
		$this->assert_amounts( $item, $order, 332.48, 69.82, 402.30 );
	}

	/**
	 * A 10% coupon discounts the inclusive POS price of 80, not catalog 100.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_coupon_with_tax_inclusive_and_pos_discount()
	 *
	 * @return void
	 */
	public function test_inclusive_percent_coupon_pos_override_uses_v1_discount_and_tax_values(): void {
		// Arrange.
		$this->percent_coupon( 'push-inclusive-discount' );
		$line = $this->inclusive_line( $this->product( '100' ), '80', '100' );
		// Act.
		$response = $this->push_order( array( 'line_items' => array( $line ), 'coupon_lines' => array( array( 'code' => 'push-inclusive-discount' ) ) ) );
		// Assert.
		$item = $this->created_line( $response, $order );
		$this->assertEquals( 66.67, round( (float) $item->get_subtotal( 'edit' ), 2 ) );
		$this->assert_amounts( $item, $order, 60.00, 12.00, 72.00 );
	}
}
