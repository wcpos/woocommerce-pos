<?php
/**
 * Tests for POS discount preservation during coupon application.
 *
 * @see docs/plans/2026-02-05-pos-discount-coupon-preservation-design.md
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Order_Item_Product;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Orders;

/**
 * Test_Orders_Coupon_Discount class.
 *
 * Verifies that POS line item discounts (stored in _woocommerce_pos_data meta)
 * are preserved when WooCommerce coupons are applied or removed.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Orders_Coupon_Discount extends WC_Unit_Test_Case {
	/**
	 * The Orders instance (registers all hooks).
	 *
	 * @var Orders
	 */
	private $orders;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->orders = new Orders();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	// ======================================================================
	// Test 1: POS discount preserved when coupon applied
	// ======================================================================

	/**
	 * When a 10% coupon is applied to a POS order with a discounted line item
	 * ($18 -> $16), the coupon should calculate against $16 (the POS price),
	 * not $18 (the original price).
	 *
	 * Expected: subtotal stays $18, total = $16 - 10% of $16 = $14.40
	 */
	public function test_pos_discount_preserved_when_coupon_applied(): void {
		$order = $this->create_pos_order_with_discount();
		CouponHelper::create_coupon( 'test10pct', 'publish', array(
			'discount_type' => 'percent',
			'coupon_amount' => '10',
		) );

		$order->apply_coupon( 'test10pct' );

		$items = $order->get_items();
		$item  = reset( $items );

		// Subtotal should remain $18 (the original/display price) — read raw value.
		$this->assertEquals( 18, (float) $item->get_subtotal( 'edit' ), 'Stored subtotal should remain at original price ($18)' );

		// Total should be $16 - 10% of $16 = $14.40.
		$this->assertEquals( 14.40, (float) $item->get_total(), 'Total should be POS price minus coupon discount ($14.40)' );
	}

	// ======================================================================
	// Test 2: Coupon removal restores POS discount
	// ======================================================================

	/**
	 * After removing a coupon from a POS order, the line item total should
	 * return to the POS-discounted price ($16), not the original price ($18).
	 */
	public function test_coupon_removal_restores_pos_discount(): void {
		$order = $this->create_pos_order_with_discount();
		CouponHelper::create_coupon( 'test10remove', 'publish', array(
			'discount_type' => 'percent',
			'coupon_amount' => '10',
		) );

		$order->apply_coupon( 'test10remove' );

		// Manually activate filter before remove_coupon() — in production,
		// Form_Handler::coupon_action() does this. WC's remove_coupon() has
		// no "before" hook so we must activate the filter externally.
		$this->orders->activate_pos_subtotal_filter();

		$order->remove_coupon( 'test10remove' );

		$items = $order->get_items();
		$item  = reset( $items );

		// After removal, total should be $16 (POS price), NOT $18 (original).
		$this->assertEquals( 16, (float) $item->get_total(), 'After coupon removal, total should return to POS price ($16)' );

		// Subtotal should remain $18.
		$this->assertEquals( 18, (float) $item->get_subtotal( 'edit' ), 'Stored subtotal should remain at original price ($18)' );
	}

	// ======================================================================
	// Test 3: exclude_sale_items respects POS discounts
	// ======================================================================

	/**
	 * A coupon with exclude_sale_items=yes should NOT apply a discount to a
	 * POS-discounted item, because the POS discount is equivalent to putting
	 * the item "on sale."
	 */
	public function test_exclude_sale_items_respects_pos_discount(): void {
		$order = $this->create_pos_order_with_discount();
		CouponHelper::create_coupon( 'nosale', 'publish', array(
			'discount_type'      => 'percent',
			'coupon_amount'      => '10',
			'exclude_sale_items' => 'yes',
		) );

		$order->apply_coupon( 'nosale' );

		$items = $order->get_items();
		$item  = reset( $items );

		// Total should remain $16 (POS price) because the coupon skips sale items.
		$this->assertEquals( 16, (float) $item->get_total(), 'Coupon with exclude_sale_items should not discount POS sale items' );
	}

	// ======================================================================
	// Test 4: Non-POS orders unaffected
	// ======================================================================

	/**
	 * Applying a coupon to a regular (non-POS) order should use standard
	 * WooCommerce behavior with no interference from WCPOS hooks.
	 */
	public function test_non_pos_order_coupon_unaffected(): void {
		// Create a regular WooCommerce product and order (not POS).
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		CouponHelper::create_coupon( 'regular10', 'publish', array(
			'discount_type' => 'percent',
			'coupon_amount' => '10',
		) );

		$order->apply_coupon( 'regular10' );

		$items = $order->get_items();
		$item  = reset( $items );

		// Standard behavior: 10% off $18 = $1.80 discount, total = $16.20.
		$this->assertEquals( 18, (float) $item->get_subtotal(), 'Non-POS subtotal should be original price' );
		$this->assertEquals( 16.20, (float) $item->get_total(), 'Non-POS total should reflect standard coupon discount' );
	}

	// ======================================================================
	// Test 5: Mixed items — with and without POS discounts
	// ======================================================================

	/**
	 * A POS order with two items: one with a POS discount and one without.
	 * The coupon should calculate against the POS price for the discounted item
	 * and the regular price for the non-discounted item.
	 */
	public function test_mixed_items_coupon_calculates_correctly(): void {
		// Product A: $18, POS discounted to $16.
		$product_a = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );

		// Product B: $20, no POS discount.
		$product_b = ProductHelper::create_simple_product( array( 'regular_price' => 20, 'price' => 20 ) );

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );

		// Add product A with POS discount.
		$item_a = new WC_Order_Item_Product();
		$item_a->set_product( $product_a );
		$item_a->set_quantity( 1 );
		$item_a->set_subtotal( 18 );  // Original price.
		$item_a->set_total( 16 );     // POS-discounted price.
		$item_a->add_meta_data(
			'_woocommerce_pos_data',
			wp_json_encode(
				array(
					'price'         => '16',
					'regular_price' => '18',
					'tax_status'    => 'none',
				)
			)
		);
		$order->add_item( $item_a );

		// Add product B with no POS discount.
		$item_b = new WC_Order_Item_Product();
		$item_b->set_product( $product_b );
		$item_b->set_quantity( 1 );
		$item_b->set_subtotal( 20 );
		$item_b->set_total( 20 );
		$order->add_item( $item_b );

		$order->calculate_totals( false );
		$order->save();

		CouponHelper::create_coupon( 'mixed10', 'publish', array(
			'discount_type' => 'percent',
			'coupon_amount' => '10',
		) );

		$order->apply_coupon( 'mixed10' );

		$items = array_values( $order->get_items() );

		// Item A: 10% off $16 = $1.60 discount, total = $14.40.
		$this->assertEquals( 14.40, (float) $items[0]->get_total(), 'POS-discounted item: coupon should apply to POS price ($14.40)' );
		$this->assertEquals( 18, (float) $items[0]->get_subtotal( 'edit' ), 'POS-discounted item: stored subtotal unchanged ($18)' );

		// Item B: 10% off $20 = $2.00 discount, total = $18.00.
		$this->assertEquals( 18, (float) $items[1]->get_total(), 'Regular item: coupon should apply to full price ($18)' );
		$this->assertEquals( 20, (float) $items[1]->get_subtotal(), 'Regular item: subtotal unchanged ($20)' );
	}

	// ======================================================================
	// Isolation tests: verify POS hooks don't leak into normal orders
	// ======================================================================

	/**
	 * After applying a coupon to a POS order, the temporary subtotal filters
	 * must be removed. If they linger, every subsequent order operation in the
	 * same request would use POS subtotals.
	 */
	public function test_subtotal_filters_removed_after_pos_coupon(): void {
		$order = $this->create_pos_order_with_discount();
		CouponHelper::create_coupon( 'cleanup10', 'publish', array(
			'discount_type' => 'percent',
			'coupon_amount' => '10',
		) );

		$order->apply_coupon( 'cleanup10' );

		// After apply_coupon completes, the temporary filters should be gone.
		$this->assertFalse(
			has_filter( 'woocommerce_order_item_get_subtotal', array( $this->orders, 'filter_pos_item_subtotal' ) ),
			'Subtotal filter should be removed after coupon recalculation'
		);
		$this->assertFalse(
			has_filter( 'woocommerce_order_item_get_subtotal_tax', array( $this->orders, 'filter_pos_item_subtotal_tax' ) ),
			'Subtotal tax filter should be removed after coupon recalculation'
		);
	}

	/**
	 * Process a POS order coupon, then immediately process a non-POS order
	 * coupon in the same request lifecycle. The non-POS order must produce
	 * standard WooCommerce results with no POS interference.
	 */
	public function test_non_pos_order_clean_after_pos_coupon(): void {
		// First: POS order gets a coupon.
		$pos_order = $this->create_pos_order_with_discount();
		CouponHelper::create_coupon( 'seq10', 'publish', array(
			'discount_type' => 'percent',
			'coupon_amount' => '10',
		) );
		$pos_order->apply_coupon( 'seq10' );

		// Second: non-POS order gets a coupon — must be completely standard.
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 50, 'price' => 50 ) );
		$regular_order = wc_create_order();
		$regular_order->add_product( $product, 1 );
		$regular_order->calculate_totals();
		$regular_order->save();

		CouponHelper::create_coupon( 'seq10reg', 'publish', array(
			'discount_type' => 'percent',
			'coupon_amount' => '10',
		) );
		$regular_order->apply_coupon( 'seq10reg' );

		$items = $regular_order->get_items();
		$item  = reset( $items );

		// Standard: 10% off $50 = $5 discount, total = $45.
		$this->assertEquals( 50, (float) $item->get_subtotal(), 'Sequential non-POS subtotal should be $50' );
		$this->assertEquals( 45, (float) $item->get_total(), 'Sequential non-POS total should be $45' );
	}

	/**
	 * The order_item_product filter modifies an in-memory product object.
	 * Verify it does NOT write those changes back to the database, which
	 * would corrupt the product catalog.
	 */
	public function test_product_db_not_mutated_by_order_item_filter(): void {
		$order = $this->create_pos_order_with_discount();

		// Trigger the order_item_product filter by reading items.
		$items = $order->get_items();
		$item  = reset( $items );
		$item->get_product(); // Fires woocommerce_order_item_product filter.

		// Reload the product fresh from the database.
		$db_product = wc_get_product( $item->get_product_id() );

		$this->assertEquals( '18', $db_product->get_regular_price(), 'DB regular_price should be unchanged' );
		$this->assertEquals( '18', $db_product->get_price(), 'DB price should be unchanged' );
		$this->assertEquals( '', $db_product->get_sale_price(), 'DB sale_price should remain empty' );
	}

	/**
	 * With all POS Orders hooks active, calculate_totals() on a regular
	 * (non-POS) order should produce standard WooCommerce results.
	 */
	public function test_non_pos_calculate_totals_unaffected(): void {
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 25, 'price' => 25 ) );
		$order   = wc_create_order();
		$order->add_product( $product, 2 );
		$order->calculate_totals( false );
		$order->save();

		$items = $order->get_items();
		$item  = reset( $items );

		// Standard: 2 x $25 = $50 subtotal and $50 total (no tax).
		$this->assertEquals( 50, (float) $item->get_subtotal(), 'Non-POS subtotal should be 2 x $25' );
		$this->assertEquals( 50, (float) $item->get_total(), 'Non-POS total should be 2 x $25' );
		$this->assertEquals( 50, (float) $order->get_total(), 'Non-POS order total should be $50' );
	}

	/**
	 * The order_item_product filter should pass through products unchanged
	 * when the line item has no _woocommerce_pos_data meta.
	 */
	public function test_order_item_product_passthrough_without_pos_data(): void {
		$product = ProductHelper::create_simple_product( array(
			'regular_price' => 30,
			'price'         => 30,
		) );
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->save();

		$items = $order->get_items();
		$item  = reset( $items );

		// Get the product via the filter chain.
		$filtered_product = $item->get_product();

		// Should match the original product exactly.
		$this->assertEquals( '30', $filtered_product->get_price(), 'Price should be unchanged without POS data' );
		$this->assertEquals( '30', $filtered_product->get_regular_price(), 'Regular price should be unchanged without POS data' );
		$this->assertFalse( $filtered_product->is_on_sale(), 'Product should not be on sale without POS data' );
	}

	// ======================================================================
	// Helper methods
	// ======================================================================

	/**
	 * Create a POS order with a single line item that has a POS discount.
	 *
	 * Product: regular_price = $18, POS discounted to $16.
	 * Line item: subtotal = $18 (original), total = $16 (POS price).
	 * Meta: _woocommerce_pos_data = {"price":"16","regular_price":"18","tax_status":"none"}
	 *
	 * @return WC_Order
	 */
	private function create_pos_order_with_discount(): WC_Order {
		$product = ProductHelper::create_simple_product( array( 'regular_price' => 18, 'price' => 18 ) );

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( 18 );  // Original price (for display).
		$item->set_total( 16 );     // POS-discounted price.
		$item->add_meta_data(
			'_woocommerce_pos_data',
			wp_json_encode(
				array(
					'price'         => '16',
					'regular_price' => '18',
					'tax_status'    => 'none',
				)
			)
		);
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();

		return $order;
	}
}
