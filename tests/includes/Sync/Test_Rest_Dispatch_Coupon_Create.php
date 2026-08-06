<?php
/**
 * Route-dispatch pins for coupon calculations on v2 order creation (#1456).
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
use WCPOS\WooCommercePOS\Sync\Api;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;
use WP_REST_Response;

/**
 * Pins the v1 coupon matrix through the real v2 push and inner wc/v3 dispatch.
 *
 * @internal
 * @coversNothing
 */
class Test_Rest_Dispatch_Coupon_Create extends Sync_REST_Store_Test_Case {
	/**
	 * Sequence used to keep mutation and record UUIDs unique.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Enable the push route, decimal quantities, and the v1 tax fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		update_option( Api::OPTION_ENABLED, true );
		parent::setUp();
		$_SERVER['HTTP_X_WCPOS'] = '1';
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_default_country', 'US:CA' );
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
	}

	/**
	 * Restore request and settings-filter state.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_X_WCPOS'] );
		parent::tearDown();
		delete_option( Api::OPTION_ENABLED );
	}

	/**
	 * Create a catalog product used by a POS-price line.
	 *
	 * @param float  $price      Catalog price.
	 * @param string $tax_status Catalog tax status.
	 *
	 * @return WC_Product
	 */
	private function product( float $price, string $tax_status = 'taxable' ): WC_Product {
		return ProductHelper::create_simple_product(
			array(
				'regular_price' => $price,
				'price'         => $price,
				'tax_status'    => $tax_status,
				'tax_class'     => '',
			)
		);
	}

	/**
	 * Build a line whose stored totals and POS metadata use the client price.
	 *
	 * @param WC_Product $product       Catalog product.
	 * @param string     $price         POS unit price.
	 * @param string     $regular_price POS regular price.
	 * @param string     $tax_status    POS line tax status.
	 * @param float      $quantity      Line quantity.
	 *
	 * @return array
	 */
	private function pos_line( WC_Product $product, string $price, string $regular_price, string $tax_status = 'taxable', float $quantity = 1.0 ): array {
		$line_total = (float) $price * $quantity;
		return array(
			'product_id' => $product->get_id(),
			'quantity'   => $quantity,
			'subtotal'   => (string) $line_total,
			'total'      => (string) $line_total,
			'meta_data'  => array(
				array(
					'key'   => '_woocommerce_pos_data',
					'value' => wp_json_encode(
						array(
							'price'         => $price,
							'regular_price' => $regular_price,
							'tax_status'    => $tax_status,
						)
					),
				),
			),
		);
	}

	/**
	 * Create a coupon fixture.
	 *
	 * @param string $code   Coupon code.
	 * @param string $type   Coupon discount type.
	 * @param string $amount Coupon amount.
	 * @param array  $extra  Additional coupon properties.
	 *
	 * @return void
	 */
	private function coupon( string $code, string $type, string $amount, array $extra = array() ): void {
		CouponHelper::create_coupon(
			$code,
			'publish',
			array_merge(
				array(
					'discount_type' => $type,
					'coupon_amount' => $amount,
				),
				$extra
			)
		);
	}

	/**
	 * Dispatch a create envelope through the registered v2 order push route.
	 *
	 * @param array $payload Complete order payload.
	 *
	 * @return WP_REST_Response
	 */
	private function push_order( array $payload ): WP_REST_Response {
		$sequence = ++$this->sequence;
		$envelope = array(
			'mutationId'   => sprintf( '51000000-0000-4000-8000-%012d', $sequence ),
			'operation'    => 'create',
			'collection'   => 'orders',
			'recordId'     => sprintf( '52000000-0000-4000-8000-%012d', $sequence ),
			'baseRevision' => null,
			'payload'      => $payload,
		);
		$request  = $this->wp_rest_post_request( '/wcpos/v2/push/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $envelope ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * Assert a successful create and return its persisted order.
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
	 * Assert the persisted monetary values for one product line.
	 *
	 * @param WC_Order_Item_Product $line     Persisted line.
	 * @param float                 $subtotal Expected subtotal.
	 * @param float                 $total    Expected discounted total.
	 * @param float                 $tax      Expected total tax.
	 *
	 * @return void
	 */
	private function assert_line_amounts( WC_Order_Item_Product $line, float $subtotal, float $total, float $tax ): void {
		$this->assertEquals( $subtotal, round( (float) $line->get_subtotal( 'edit' ), 2 ) );
		$this->assertEquals( $total, round( (float) $line->get_total(), 2 ) );
		$this->assertEquals( $tax, round( (float) $line->get_total_tax(), 2 ) );
	}

	/**
	 * A percent coupon uses the $8 POS price, not the $10 catalog price.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_pos_discount_preserved_when_coupon_applied()
	 *
	 * @return void
	 */
	public function test_percent_coupon_price_override_uses_pos_price_and_taxes_discounted_total(): void {
		// Arrange.
		$this->coupon( 'push-percent-10', 'percent', '10' );
		$line = $this->pos_line( $this->product( 10 ), '8', '10' );
		// Act.
		$order = $this->created_order( $this->push_order( array( 'line_items' => array( $line ), 'coupon_lines' => array( array( 'code' => 'push-percent-10' ) ) ) ) );
		// Assert.
		$item = array_values( $order->get_items( 'line_item' ) )[0];
		$this->assert_line_amounts( $item, 8.00, 7.20, 0.72 );
		$this->assertEquals( 0.80, round( (float) $order->get_discount_total(), 2 ) );
		$this->assertEquals( 7.92, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * A fixed-cart coupon subtracts from the acknowledged POS line value.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_stacked_coupons_apply_consistently_to_pos_price()
	 *
	 * @return void
	 */
	public function test_fixed_cart_coupon_price_override_reduces_pos_price_and_recalculates_tax(): void {
		// Arrange.
		$this->coupon( 'push-fixed-cart-3', 'fixed_cart', '3' );
		$line = $this->pos_line( $this->product( 18 ), '16', '18' );
		// Act.
		$order = $this->created_order( $this->push_order( array( 'line_items' => array( $line ), 'coupon_lines' => array( array( 'code' => 'push-fixed-cart-3' ) ) ) ) );
		// Assert.
		$this->assert_line_amounts( array_values( $order->get_items( 'line_item' ) )[0], 16.00, 13.00, 1.30 );
		$this->assertEquals( 3.00, round( (float) $order->get_discount_total(), 2 ) );
		$this->assertEquals( 14.30, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * A fixed-product coupon is capped at the $16 POS price, not the $18 catalog price.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_fixed_product_coupon_applies_to_pos_price()
	 *
	 * @return void
	 */
	public function test_fixed_product_coupon_price_override_is_bounded_by_pos_line_price(): void {
		// Arrange.
		$this->coupon( 'push-fixed-product-17', 'fixed_product', '17' );
		$line = $this->pos_line( $this->product( 18 ), '16', '18' );
		// Act.
		$order = $this->created_order( $this->push_order( array( 'line_items' => array( $line ), 'coupon_lines' => array( array( 'code' => 'push-fixed-product-17' ) ) ) ) );
		// Assert.
		$this->assert_line_amounts( array_values( $order->get_items( 'line_item' ) )[0], 16.00, 0.00, 0.00 );
		$this->assertEquals( 16.00, round( (float) $order->get_discount_total(), 2 ) );
		$this->assertEquals( 0.00, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * Coupons excluding sale items skip a POS sale line but discount the equal-price control.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_exclude_sale_items_respects_pos_discount()
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_pos_discount_preserved_when_coupon_applied()
	 *
	 * @return void
	 */
	public function test_exclude_sale_items_pos_override_skips_sale_line_and_discounts_control_line(): void {
		// Arrange.
		$this->coupon( 'push-no-sale', 'percent', '10', array( 'exclude_sale_items' => 'yes' ) );
		$product = $this->product( 18 );
		$lines   = array( $this->pos_line( $product, '16', '18' ), $this->pos_line( $product, '16', '16' ) );
		// Act.
		$order = $this->created_order( $this->push_order( array( 'line_items' => $lines, 'coupon_lines' => array( array( 'code' => 'push-no-sale' ) ) ) ) );
		// Assert.
		$items = array_values( $order->get_items( 'line_item' ) );
		$this->assert_line_amounts( $items[0], 16.00, 16.00, 1.60 );
		$this->assert_line_amounts( $items[1], 16.00, 14.40, 1.44 );
		$this->assertEquals( 1.60, round( (float) $order->get_discount_total(), 2 ) );
		$this->assertEquals( 33.44, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * Percent and fixed-cart coupons stack from the POS-discounted amount.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_stacked_coupons_apply_consistently_to_pos_price()
	 *
	 * @return void
	 */
	public function test_stacked_percent_and_fixed_cart_coupons_use_pos_price_and_final_tax(): void {
		// Arrange.
		$this->coupon( 'push-stack-percent', 'percent', '10' );
		$this->coupon( 'push-stack-cart', 'fixed_cart', '3' );
		$line = $this->pos_line( $this->product( 18 ), '16', '18' );
		// Act.
		$order = $this->created_order(
			$this->push_order(
				array(
					'line_items'   => array( $line ),
					'coupon_lines' => array( array( 'code' => 'push-stack-percent' ), array( 'code' => 'push-stack-cart' ) ),
				)
			)
		);
		// Assert.
		$this->assert_line_amounts( array_values( $order->get_items( 'line_item' ) )[0], 16.00, 11.40, 1.14 );
		$this->assertEquals( 4.60, round( (float) $order->get_discount_total(), 2 ) );
		$this->assertEquals( 12.54, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * Per-line POS tax status keeps the exempt line untaxed during coupon calculation.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_coupon_with_tax_exclusive_and_pos_discount()
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_mixed_taxable_and_exempt_items_tax_inclusive()
	 *
	 * @return void
	 */
	public function test_percent_coupon_mixed_taxable_and_exempt_pos_lines_preserves_per_line_tax_status(): void {
		// Arrange.
		$this->coupon( 'push-mixed-tax', 'percent', '10' );
		$lines = array(
			$this->pos_line( $this->product( 100 ), '80', '100' ),
			$this->pos_line( $this->product( 50 ), '50', '50', 'none' ),
		);
		// Act.
		$order = $this->created_order(
			$this->push_order(
				array(
					'line_items'   => $lines,
					'coupon_lines' => array( array( 'code' => 'push-mixed-tax' ) ),
					'billing'      => array( 'country' => 'GB' ),
					'meta_data'    => array( array( 'key' => '_woocommerce_pos_tax_based_on', 'value' => 'billing' ) ),
				)
			)
		);
		// Assert.
		$items = array_values( $order->get_items( 'line_item' ) );
		$this->assert_line_amounts( $items[0], 80.00, 72.00, 14.40 );
		$this->assert_line_amounts( $items[1], 50.00, 45.00, 0.00 );
		$this->assertEquals( 131.40, round( (float) $order->get_total(), 2 ) );
	}

	/**
	 * A category-restricted coupon recognizes categories carried by a misc POS line.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_coupon_product_categories_applies_to_misc_product()
	 *
	 * @return void
	 */
	public function test_category_coupon_misc_product_with_matching_pos_category_applies_discount(): void {
		// Arrange.
		$category = wp_insert_term( 'Push POS Clothing', 'product_cat' );
		$this->coupon( 'push-misc-category', 'percent', '50', array( 'product_categories' => array( $category['term_id'] ) ) );
		$pos_data = array(
			'price'         => '20',
			'regular_price' => '20',
			'tax_status'    => 'taxable',
			'categories'    => array( array( 'id' => $category['term_id'], 'name' => 'Push POS Clothing' ) ),
		);
		$line     = array(
			'product_id' => 0,
			'name'       => 'Misc Clothing Item',
			'quantity'   => 1,
			'subtotal'   => '20',
			'total'      => '20',
			'meta_data'  => array( array( 'key' => '_woocommerce_pos_data', 'value' => wp_json_encode( $pos_data ) ) ),
		);
		// Act.
		$order = $this->created_order( $this->push_order( array( 'line_items' => array( $line ), 'coupon_lines' => array( array( 'code' => 'push-misc-category' ) ) ) ) );
		// Assert.
		$item = array_values( $order->get_items( 'line_item' ) )[0];
		$this->assertEquals( 0, $item->get_product_id() );
		$this->assertEquals( 10.00, round( (float) $item->get_total(), 2 ) );
		$this->assertEquals( 10.00, round( (float) $order->get_discount_total(), 2 ) );
	}

	/**
	 * Fractional quantity coupon math remains stable at currency precision.
	 *
	 * @see \WCPOS\WooCommercePOS\Tests\Test_Orders_Coupon_Discount::test_quantity_rounding_with_pos_discounted_coupon()
	 *
	 * @return void
	 */
	public function test_percent_coupon_decimal_quantity_pos_line_rounds_discount_and_tax_consistently(): void {
		// Arrange: the shared helper enables the setting AND swaps the
		// woocommerce_stock_amount filter to floatval — the Products bootstrap
		// gate ran before this test could flip the setting, so the swap must be
		// applied here exactly as production applies it at plugin init.
		$this->setup_decimal_quantity_tests();
		$this->coupon( 'push-decimal-quantity', 'percent', '10' );
		$line = $this->pos_line( $this->product( 10 ), '10', '10', 'taxable', 0.5 );
		// Act.
		$order = $this->created_order( $this->push_order( array( 'line_items' => array( $line ), 'coupon_lines' => array( array( 'code' => 'push-decimal-quantity' ) ) ) ) );
		// Assert.
		$item = array_values( $order->get_items( 'line_item' ) )[0];
		$this->assertEquals( 0.5, (float) $item->get_quantity() );
		$this->assert_line_amounts( $item, 5.00, 4.50, 0.45 );
		$this->assertEquals( 4.95, round( (float) $order->get_total(), 2 ) );
	}
}
