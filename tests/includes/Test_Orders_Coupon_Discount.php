<?php
/**
 * Tests for POS discount preservation during coupon application.
 *
 * @see docs/plans/2026-02-05-pos-discount-coupon-preservation-design.md
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Order;
use WC_Order_Item_Product;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Orders;
use WCPOS\WooCommercePOS\Tests\Helpers\TaxHelper;

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
		CouponHelper::create_coupon(
			'test10pct',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

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
		CouponHelper::create_coupon(
			'test10remove',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

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
		CouponHelper::create_coupon(
			'nosale',
			'publish',
			array(
				'discount_type'      => 'percent',
				'coupon_amount'      => '10',
				'exclude_sale_items' => 'yes',
			)
		);

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
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price' => 18,
			)
		);
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		CouponHelper::create_coupon(
			'regular10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

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
		$product_a = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price' => 18,
			)
		);

		// Product B: $20, no POS discount.
		$product_b = ProductHelper::create_simple_product(
			array(
				'regular_price' => 20,
				'price' => 20,
			)
		);

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

		CouponHelper::create_coupon(
			'mixed10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

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
		CouponHelper::create_coupon(
			'cleanup10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'cleanup10' );

		// After apply_coupon completes, the temporary filters should be gone.
		$this->assertFalse(
			has_filter( 'woocommerce_order_item_get_subtotal', array( Orders::class, 'filter_pos_item_subtotal' ) ),
			'Subtotal filter should be removed after coupon recalculation'
		);
		$this->assertFalse(
			has_filter( 'woocommerce_order_item_get_subtotal_tax', array( Orders::class, 'filter_pos_item_subtotal_tax' ) ),
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
		CouponHelper::create_coupon(
			'seq10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);
		$pos_order->apply_coupon( 'seq10' );

		// Second: non-POS order gets a coupon — must be completely standard.
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 50,
				'price' => 50,
			)
		);
		$regular_order = wc_create_order();
		$regular_order->add_product( $product, 1 );
		$regular_order->calculate_totals();
		$regular_order->save();

		CouponHelper::create_coupon(
			'seq10reg',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);
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
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 25,
				'price' => 25,
			)
		);
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
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 30,
				'price'         => 30,
			)
		);
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
	// Bug reproduction: Issue #506
	// ======================================================================

	/**
	 * Reproduce the exact bug from issue #506.
	 *
	 * Setup: €447.00 product with 21% VAT (prices entered including tax).
	 * The POS stores the tax-inclusive price (447) in _woocommerce_pos_data.
	 * WooCommerce stores the tax-exclusive subtotal (369.42) internally.
	 *
	 * Bug: Applying a 10% coupon inflates the line item instead of discounting it.
	 * Expected: 10% off €447 incl-tax = €402.30 order total.
	 *
	 * @see https://github.com/wcpos/woocommerce-pos/issues/506
	 */
	public function test_issue_506_coupon_with_tax_inclusive_pricing(): void {
		// Set up 21% VAT with prices including tax.
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		update_option( 'woocommerce_default_country', 'NL' );
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

		$order = $this->create_pos_order_tax_inclusive( '447', '447', 'taxable' );

		CouponHelper::create_coupon(
			'issue506',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'issue506' );

		$items = $order->get_items();
		$item  = reset( $items );

		// The stored subtotal should be the tax-exclusive original price.
		$this->assertEquals( 369.42, round( (float) $item->get_subtotal( 'edit' ), 2 ), 'Stored subtotal should be tax-exclusive (€369.42)' );

		// 10% off €447.00 incl-tax: discount = €44.70 incl-tax = €36.94 ex-tax.
		// Line total = €369.42 - €36.94 = €332.48 ex-tax.
		$this->assertEquals( 332.48, round( (float) $item->get_total(), 2 ), 'Line total should be €332.48 ex-tax after 10% coupon' );

		// Tax on €332.48 = €69.82.
		$this->assertEquals( 69.82, round( (float) $item->get_total_tax(), 2 ), 'Line total tax should be €69.82' );

		// Order total = €332.48 + €69.82 = €402.30.
		$this->assertEquals( 402.30, round( (float) $order->get_total(), 2 ), 'Order total should be €402.30' );
	}

	// ======================================================================
	// Tax-aware coupon tests: prices include tax + taxable
	// ======================================================================

	/**
	 * Apply coupon to a POS order with tax-inclusive pricing and a POS discount.
	 *
	 * Product: €100 incl 20% VAT (ex-tax €83.33), POS discounted to €80 incl
	 * (ex-tax €66.67). 10% coupon should discount from €80 incl.
	 */
	public function test_coupon_with_tax_inclusive_and_pos_discount(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
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

		// POS price €80 incl, regular €100 incl.
		$order = $this->create_pos_order_tax_inclusive( '80', '100', 'taxable' );

		CouponHelper::create_coupon(
			'taxincl10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'taxincl10' );

		$items = $order->get_items();
		$item  = reset( $items );

		// €80 incl 20% VAT → ex-tax = €66.67. 10% coupon = €8.00 incl = €6.67 ex-tax.
		// Line total = €66.67 - €6.67 = €60.00.
		$this->assertEquals( 60.00, round( (float) $item->get_total(), 2 ), 'Line total should be €60.00 ex-tax' );

		// Tax on €60 = €12.00.
		$this->assertEquals( 12.00, round( (float) $item->get_total_tax(), 2 ), 'Line total tax should be €12.00' );

		// Order total = €60 + €12 = €72.00.
		$this->assertEquals( 72.00, round( (float) $order->get_total(), 2 ), 'Order total should be €72.00' );
	}

	/**
	 * Apply coupon to a POS order with tax-inclusive pricing, no POS discount.
	 *
	 * Product: €100 incl 20% VAT, no discount. The filter should still work
	 * correctly when price == regular_price.
	 */
	public function test_coupon_with_tax_inclusive_no_pos_discount(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
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

		// POS price €100 incl, no discount.
		$order = $this->create_pos_order_tax_inclusive( '100', '100', 'taxable' );

		CouponHelper::create_coupon(
			'taxnodiscount',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'taxnodiscount' );

		$items = $order->get_items();
		$item  = reset( $items );

		// €100 incl 20% → ex-tax = €83.33. 10% coupon = €10 incl = €8.33 ex-tax.
		// Line total = €83.33 - €8.33 = €75.00.
		$this->assertEquals( 75.00, round( (float) $item->get_total(), 2 ), 'Line total should be €75.00 ex-tax' );

		// Tax on €75 = €15.
		$this->assertEquals( 15.00, round( (float) $item->get_total_tax(), 2 ), 'Line total tax should be €15.00' );

		// Order total = €75 + €15 = €90.
		$this->assertEquals( 90.00, round( (float) $order->get_total(), 2 ), 'Order total should be €90.00' );
	}

	/**
	 * Remove coupon from a POS order with tax-inclusive pricing and POS discount.
	 */
	public function test_coupon_removal_with_tax_inclusive_pricing(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
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

		$order = $this->create_pos_order_tax_inclusive( '80', '100', 'taxable' );

		CouponHelper::create_coupon(
			'taxremove10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'taxremove10' );

		// Manually activate filter before remove (simulates Form_Handler).
		Orders::activate_pos_subtotal_filter();
		$order->remove_coupon( 'taxremove10' );

		$items = $order->get_items();
		$item  = reset( $items );

		// After removal, total should return to POS price (€80 incl = €66.67 ex-tax).
		$this->assertEquals( 66.67, round( (float) $item->get_total(), 2 ), 'After removal, total should be POS price ex-tax (€66.67)' );

		// Tax on €66.67 = €13.33.
		$this->assertEquals( 13.33, round( (float) $item->get_total_tax(), 2 ), 'After removal, tax should be €13.33' );

		// Order total = €66.67 + €13.33 = €80.00.
		$this->assertEquals( 80.00, round( (float) $order->get_total(), 2 ), 'After removal, order total should be €80.00' );
	}

	// ======================================================================
	// Tax-aware coupon tests: prices include tax + tax-exempt item
	// ======================================================================

	/**
	 * Apply coupon to a POS order with tax-inclusive pricing but tax-exempt item.
	 *
	 * Product: €100, tax_status = 'none'. Should work like the existing no-tax tests.
	 */
	public function test_coupon_with_tax_inclusive_but_exempt_item(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
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

		// POS price €80, regular €100, but tax_status = 'none'.
		$order = $this->create_pos_order_tax_inclusive( '80', '100', 'none' );

		CouponHelper::create_coupon(
			'taxexempt10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'taxexempt10' );

		$items = $order->get_items();
		$item  = reset( $items );

		// Tax-exempt: no VAT. 10% off €80 = €8, total = €72.
		$this->assertEquals( 72.00, round( (float) $item->get_total(), 2 ), 'Tax-exempt total should be €72.00' );
		$this->assertEquals( 0.00, round( (float) $item->get_total_tax(), 2 ), 'Tax-exempt item should have no tax' );
		$this->assertEquals( 72.00, round( (float) $order->get_total(), 2 ), 'Order total should be €72.00' );
	}

	// ======================================================================
	// Tax-aware coupon tests: prices exclude tax + taxable
	// ======================================================================

	/**
	 * Apply coupon to a POS order with tax-exclusive pricing and a POS discount.
	 *
	 * When prices_include_tax is false, the POS price IS tax-exclusive already.
	 */
	public function test_coupon_with_tax_exclusive_and_pos_discount(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
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

		// POS price is tax-exclusive when prices_include_tax = false.
		// Product €100 ex-tax, POS discounted to €80 ex-tax.
		$order = $this->create_pos_order_tax_exclusive( '80', '100', 'taxable' );

		CouponHelper::create_coupon(
			'taxexcl10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'taxexcl10' );

		$items = $order->get_items();
		$item  = reset( $items );

		// 10% off €80 ex-tax = €8, total = €72 ex-tax.
		$this->assertEquals( 72.00, round( (float) $item->get_total(), 2 ), 'Line total should be €72.00 ex-tax' );

		// Tax on €72 = €14.40.
		$this->assertEquals( 14.40, round( (float) $item->get_total_tax(), 2 ), 'Line total tax should be €14.40' );

		// Order total = €72 + €14.40 = €86.40.
		$this->assertEquals( 86.40, round( (float) $order->get_total(), 2 ), 'Order total should be €86.40' );
	}

	// ======================================================================
	// Mixed items with tax
	// ======================================================================

	/**
	 * POS order with one taxable item and one tax-exempt item, tax-inclusive pricing.
	 */
	public function test_mixed_taxable_and_exempt_items_tax_inclusive(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
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

		$product_a = ProductHelper::create_simple_product(
			array(
				'regular_price' => 100,
				'price' => 100,
			)
		);
		$product_b = ProductHelper::create_simple_product(
			array(
				'regular_price' => 50,
				'price' => 50,
			)
		);

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'base' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_prices_include_tax( true );

		// Item A: €120 incl VAT, taxable. Ex-tax = €100.
		$item_a = new WC_Order_Item_Product();
		$item_a->set_product( $product_a );
		$item_a->set_quantity( 1 );
		$item_a->set_subtotal( 100 );      // ex-tax.
		$item_a->set_subtotal_tax( 20 );   // 20% of 100.
		$item_a->set_total( 100 );
		$item_a->set_total_tax( 20 );
		$item_a->add_meta_data(
			'_woocommerce_pos_data',
			wp_json_encode(
				array(
					'price'         => '120',   // Tax-inclusive POS price.
					'regular_price' => '120',
					'tax_status'    => 'taxable',
				)
			)
		);
		$order->add_item( $item_a );

		// Item B: €50, tax-exempt.
		$item_b = new WC_Order_Item_Product();
		$item_b->set_product( $product_b );
		$item_b->set_quantity( 1 );
		$item_b->set_subtotal( 50 );
		$item_b->set_total( 50 );
		$item_b->add_meta_data(
			'_woocommerce_pos_data',
			wp_json_encode(
				array(
					'price'         => '50',
					'regular_price' => '50',
					'tax_status'    => 'none',
				)
			)
		);
		$order->add_item( $item_b );

		$order->calculate_totals( true );
		$order->save();

		CouponHelper::create_coupon(
			'mixed_tax10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'mixed_tax10' );

		$items = array_values( $order->get_items() );

		// Item A: 10% off €120 incl = €12 incl = €10 ex-tax discount.
		// Total = €100 - €10 = €90 ex-tax. Tax = €18.
		$this->assertEquals( 90.00, round( (float) $items[0]->get_total(), 2 ), 'Taxable item total should be €90.00 ex-tax' );
		$this->assertEquals( 18.00, round( (float) $items[0]->get_total_tax(), 2 ), 'Taxable item tax should be €18.00' );

		// Item B: 10% off €50 = €5, total = €45. No tax.
		$this->assertEquals( 45.00, round( (float) $items[1]->get_total(), 2 ), 'Tax-exempt item total should be €45.00' );
		$this->assertEquals( 0.00, round( (float) $items[1]->get_total_tax(), 2 ), 'Tax-exempt item should have no tax' );

		// Order total = (90 + 18) + 45 = €153.
		$this->assertEquals( 153.00, round( (float) $order->get_total(), 2 ), 'Order total should be €153.00' );
	}

	// ======================================================================
	// Additional edge-case coverage for coupon + stock-safe product handling
	// ======================================================================

	/**
	 * Variation line items should use POS sale context for exclude_sale_items checks.
	 */
	public function test_variation_exclude_sale_items_respects_pos_discount(): void {
		$variable_product = ProductHelper::create_variation_product();
		$variation_ids    = $variable_product->get_children();
		$variation        = wc_get_product( $variation_ids[0] );

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );

		$item = new WC_Order_Item_Product();
		$item->set_product( $variation );
		$item->set_quantity( 1 );
		$item->set_subtotal( 18 );
		$item->set_total( 16 );
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

		CouponHelper::create_coupon(
			'var_nosale',
			'publish',
			array(
				'discount_type'      => 'percent',
				'coupon_amount'      => '10',
				'exclude_sale_items' => 'yes',
			)
		);

		$order->apply_coupon( 'var_nosale' );

		$items = $order->get_items();
		$item  = reset( $items );
		$this->assertEquals( 16.00, round( (float) $item->get_total(), 2 ), 'Variation POS-discounted item should be treated as on-sale for exclude_sale_items.' );
	}

	/**
	 * Fixed-product coupons should apply against POS-discounted line prices.
	 */
	public function test_fixed_product_coupon_applies_to_pos_price(): void {
		$order = $this->create_pos_order_with_discount();
		CouponHelper::create_coupon(
			'fixedprod5',
			'publish',
			array(
				'discount_type' => 'fixed_product',
				'coupon_amount' => '5',
			)
		);

		$order->apply_coupon( 'fixedprod5' );

		$items = $order->get_items();
		$item  = reset( $items );
		$this->assertEquals( 11.00, round( (float) $item->get_total(), 2 ), 'Fixed-product coupon should reduce the POS price (16 - 5 = 11).' );
	}

	/**
	 * Stacked coupons should calculate consistently from POS-discounted prices.
	 */
	public function test_stacked_coupons_apply_consistently_to_pos_price(): void {
		$order = $this->create_pos_order_with_discount();
		CouponHelper::create_coupon(
			'stackpct10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);
		CouponHelper::create_coupon(
			'stackcart3',
			'publish',
			array(
				'discount_type' => 'fixed_cart',
				'coupon_amount' => '3',
			)
		);

		$order->apply_coupon( 'stackpct10' );
		$order->apply_coupon( 'stackcart3' );

		$items = $order->get_items();
		$item  = reset( $items );

		// 16.00 - 10% (=1.60) - 3.00 = 11.40.
		$this->assertEquals( 11.40, round( (float) $item->get_total(), 2 ), 'Stacked coupons should apply consistently to POS-discounted price.' );
	}

	/**
	 * Quantity/rounding edge case for POS-discounted coupon calculations.
	 */
	public function test_quantity_rounding_with_pos_discounted_coupon(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 24.99,
				'price'         => 24.99,
			)
		);

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 3 );
		$item->set_subtotal( 74.97 ); // 24.99 * 3 original.
		$item->set_total( 59.97 );    // 19.99 * 3 POS price.
		$item->add_meta_data(
			'_woocommerce_pos_data',
			wp_json_encode(
				array(
					'price'         => '19.99',
					'regular_price' => '24.99',
					'tax_status'    => 'none',
				)
			)
		);
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();

		CouponHelper::create_coupon(
			'qtyround10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'qtyround10' );

		$items = $order->get_items();
		$item  = reset( $items );

		$this->assertEquals( 74.97, round( (float) $item->get_subtotal( 'edit' ), 2 ), 'Stored subtotal should remain the original line subtotal.' );
		$this->assertEquals( 53.97, round( (float) $item->get_total(), 2 ), 'Coupon math should remain stable for quantity + decimal prices.' );
	}

	/**
	 * Invalid _woocommerce_pos_data on a real product should fall back safely.
	 */
	public function test_coupon_validation_falls_back_with_invalid_pos_json(): void {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 30,
				'price'         => 30,
			)
		);

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( 30 );
		$item->set_total( 30 );
		$item->add_meta_data( '_woocommerce_pos_data', '{invalid json' );
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();

		CouponHelper::create_coupon(
			'invalidjson10',
			'publish',
			array(
				'discount_type' => 'percent',
				'coupon_amount' => '10',
			)
		);

		$order->apply_coupon( 'invalidjson10' );

		$items = $order->get_items();
		$item  = reset( $items );
		$this->assertEquals( 27.00, round( (float) $item->get_total(), 2 ), 'Invalid POS meta should gracefully fall back to standard WooCommerce coupon behavior.' );
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
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => 18,
				'price' => 18,
			)
		);

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

	/**
	 * Create a POS order with tax-inclusive pricing.
	 *
	 * The POS stores the tax-INCLUSIVE price in _woocommerce_pos_data.
	 * WooCommerce stores the tax-EXCLUSIVE subtotal/total internally.
	 * This helper uses wc_get_price_excluding_tax() to derive the correct ex-tax values.
	 *
	 * @param string $pos_price         Tax-inclusive POS price.
	 * @param string $pos_regular_price Tax-inclusive POS regular price.
	 * @param string $tax_status        'taxable' or 'none'.
	 *
	 * @return WC_Order
	 */
	private function create_pos_order_tax_inclusive( string $pos_price, string $pos_regular_price, string $tax_status ): WC_Order {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => $pos_regular_price,
				'price'         => $pos_price,
				'tax_status'    => $tax_status,
			)
		);

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'base' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_prices_include_tax( true );

		// WC stores the tax-exclusive amount as subtotal.
		$subtotal_ex_tax = wc_get_price_excluding_tax( $product, array( 'price' => (float) $pos_regular_price ) );
		$total_ex_tax    = wc_get_price_excluding_tax( $product, array( 'price' => (float) $pos_price ) );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( $subtotal_ex_tax );
		$item->set_total( $total_ex_tax );
		$item->add_meta_data(
			'_woocommerce_pos_data',
			wp_json_encode(
				array(
					'price'         => $pos_price,
					'regular_price' => $pos_regular_price,
					'tax_status'    => $tax_status,
				)
			)
		);
		$order->add_item( $item );
		$order->calculate_totals( true );
		$order->save();

		return $order;
	}

	/**
	 * Create a POS order with tax-exclusive pricing.
	 *
	 * When prices_include_tax is false, the POS price IS the tax-exclusive price.
	 *
	 * @param string $pos_price         Tax-exclusive POS price.
	 * @param string $pos_regular_price Tax-exclusive POS regular price.
	 * @param string $tax_status        'taxable' or 'none'.
	 *
	 * @return WC_Order
	 */
	private function create_pos_order_tax_exclusive( string $pos_price, string $pos_regular_price, string $tax_status ): WC_Order {
		$product = ProductHelper::create_simple_product(
			array(
				'regular_price' => $pos_regular_price,
				'price'         => $pos_price,
				'tax_status'    => $tax_status,
			)
		);

		$order = wc_create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->update_meta_data( '_woocommerce_pos_tax_based_on', 'base' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( 'pos-open' );
		$order->set_prices_include_tax( false );

		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( (float) $pos_regular_price );
		$item->set_total( (float) $pos_price );
		$item->add_meta_data(
			'_woocommerce_pos_data',
			wp_json_encode(
				array(
					'price'         => $pos_price,
					'regular_price' => $pos_regular_price,
					'tax_status'    => $tax_status,
				)
			)
		);
		$order->add_item( $item );
		$order->calculate_totals( true );
		$order->save();

		return $order;
	}
}
