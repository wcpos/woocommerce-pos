# POS Discount Coupon Preservation - Implementation Plan

> **For Claude:** REQUIRED: Use /execute-plan to implement this plan task-by-task.

**Goal:** Preserve POS line item discounts when coupons are applied or removed, and respect `exclude_sale_items` for POS-discounted items.

**Architecture:** Hook into WooCommerce's coupon recalculation flow using `woocommerce_order_applied_coupon` (for `apply_coupon()`) and manual activation in `Form_Handler::coupon_action()` (for `remove_coupon()`) to temporarily filter `woocommerce_order_item_get_subtotal` on POS orders. The filter is deactivated by `woocommerce_order_after_calculate_totals` which fires at the end of `recalculate_coupons()`. Extend the existing `order_item_product` filter to mark POS-discounted products as "on sale" for `exclude_sale_items` support.

**Tech Stack:** PHP, WordPress Plugin API, WooCommerce hooks, PHPUnit via wp-env

**Design doc:** `docs/plans/2026-02-05-pos-discount-coupon-preservation-design.md`

---

## Background

WooCommerce's `recalculate_coupons()` (in `abstract-wc-order.php:1396`) does:
1. Resets each line item: `$item->set_total( $item->get_subtotal() )`
2. Creates `WC_Discounts` which reads `$order_item->get_subtotal()` as the coupon base price
3. Applies coupon discounts and sets `total = subtotal - discount`

The POS stores `subtotal` = original price ($18), `total` = POS-discounted price ($16). Step 1 wipes the POS discount. Step 2 uses the wrong base price.

`WC_Order_Item_Product::get_subtotal()` uses `get_prop('subtotal', 'view')` which fires the filter `woocommerce_order_item_get_subtotal`. We temporarily filter this to return the POS price during coupon recalculation.

### Key WooCommerce hooks (from `abstract-wc-order.php`):
- `woocommerce_order_applied_coupon` (line 1330) — fires **before** `recalculate_coupons()` in `apply_coupon()`. This is our entry point for activating the subtotal filter when coupons are added from ANY entry point (POS, admin, API).
- `woocommerce_order_after_calculate_totals` (line 2019) — fires at the **end** of `calculate_totals()`, which is the last thing `recalculate_coupons()` calls. This is our cleanup hook.
- `remove_coupon()` (line 1372) calls `recalculate_coupons()` directly with **no "before" hook**. We handle this by manually activating the filter in `Form_Handler::coupon_action()` before calling `$order->remove_coupon()`.

### Coverage analysis:
- **apply_coupon from POS checkout**: Covered by `woocommerce_order_applied_coupon` hook
- **apply_coupon from WP Admin**: Covered by `woocommerce_order_applied_coupon` hook
- **remove_coupon from POS checkout**: Covered by `Form_Handler::coupon_action()` wrapping
- **remove_coupon from WP Admin**: Known limitation in v1 (edge case: admin editing POS orders)

### Test infrastructure:
- **Framework:** PHPUnit via wp-env
- **Run tests:** `pnpm run test` (or target a single file: see Task 1 step 2)
- **Base class:** `WC_Unit_Test_Case`
- **Helpers:** `OrderHelper::create_order()`, `ProductHelper::create_simple_product()`, `CouponHelper::create_coupon()`
- **Existing tests:** `tests/includes/Test_Orders.php` — has `create_pos_order()` helper and patterns for POS order/item setup

---

### Task 1: Write failing tests for POS discount + coupon interaction

**Files:**
- Create: `tests/includes/Test_Orders_Coupon_Discount.php`

**Step 1: Write the test file**

This file contains all 5 tests from the design doc. They will all fail initially because the fix hasn't been implemented yet.

```php
<?php
/**
 * Tests for POS discount preservation during coupon application.
 *
 * @see docs/plans/2026-02-05-pos-discount-coupon-preservation-design.md
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WC_Coupon;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
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
		$order  = $this->create_pos_order_with_discount();
		$coupon = CouponHelper::create_coupon( 'test10pct', 'publish', array(
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
		$order  = $this->create_pos_order_with_discount();
		$coupon = CouponHelper::create_coupon( 'test10remove', 'publish', array(
			'discount_type' => 'percent',
			'coupon_amount' => '10',
		) );

		$order->apply_coupon( 'test10remove' );
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
		$order  = $this->create_pos_order_with_discount();
		$coupon = CouponHelper::create_coupon( 'nosale', 'publish', array(
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
		$product = ProductHelper::create_simple_product( true, array( 'regular_price' => 18 ) );
		$order   = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$coupon = CouponHelper::create_coupon( 'regular10', 'publish', array(
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
		$product_a = ProductHelper::create_simple_product( true, array( 'regular_price' => 18 ) );

		// Product B: $20, no POS discount.
		$product_b = ProductHelper::create_simple_product( true, array( 'regular_price' => 20 ) );

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
			wp_json_encode( array(
				'price'         => '16',
				'regular_price' => '18',
				'tax_status'    => 'none',
			) )
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

		$coupon = CouponHelper::create_coupon( 'mixed10', 'publish', array(
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
		$product = ProductHelper::create_simple_product( true, array( 'regular_price' => 18 ) );

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
			wp_json_encode( array(
				'price'         => '16',
				'regular_price' => '18',
				'tax_status'    => 'none',
			) )
		);
		$order->add_item( $item );
		$order->calculate_totals( false );
		$order->save();

		return $order;
	}
}
```

**Step 2: Run tests to verify they fail**

Run: `pnpm run test:unit:php -- --filter Test_Orders_Coupon_Discount --verbose`

If that doesn't work with the filter arg, run the full suite: `pnpm run test`

Expected: All 5 tests FAIL. The key failures will be:
- `test_pos_discount_preserved_when_coupon_applied`: total will be $16.20 (10% off $18) instead of $14.40
- `test_coupon_removal_restores_pos_discount`: total will be $18 instead of $16
- `test_exclude_sale_items_respects_pos_discount`: total will show a discount instead of $16
- `test_mixed_items_coupon_calculates_correctly`: item A total will be wrong

**Step 3: Commit the failing tests**

```bash
git add tests/includes/Test_Orders_Coupon_Discount.php
git commit -m "test: add failing tests for POS discount + coupon interaction (#444)"
```

---

### Task 2: Implement subtotal filter for coupon recalculation

**Files:**
- Modify: `includes/Orders.php`
- Modify: `includes/Form_Handler.php`

**Step 1: Add the subtotal filter methods to Orders class**

In `includes/Orders.php`, add one new hook in the constructor and the new methods:

**Constructor change** — add this line after line 40 (`add_action( 'woocommerce_order_item_after_calculate_taxes'...`):

```php
add_action( 'woocommerce_order_applied_coupon', array( $this, 'before_coupon_recalculation' ), 10, 2 );
```

**New methods** — add after the `order_item_after_calculate_taxes` method (after line 237):

```php
/**
 * Activate the POS subtotal filter before coupon recalculation.
 *
 * WooCommerce's recalculate_coupons() uses get_subtotal() as the base
 * price for coupon calculations. The POS stores the original price in
 * subtotal and the discounted price in _woocommerce_pos_data meta.
 *
 * This hook fires from apply_coupon() BEFORE recalculate_coupons() runs,
 * so we add a filter to temporarily return the POS price as the subtotal.
 *
 * For remove_coupon(), there is no "before" hook in WooCommerce core.
 * The filter is activated manually in Form_Handler::coupon_action() before
 * calling $order->remove_coupon().
 *
 * @see abstract-wc-order.php::apply_coupon() line 1330
 * @see abstract-wc-order.php::recalculate_coupons() line 1396
 *
 * @param WC_Coupon         $coupon The coupon object.
 * @param WC_Abstract_Order $order  The order object.
 */
public function before_coupon_recalculation( $coupon, $order ): void {
	if ( ! woocommerce_pos_is_pos_order( $order ) ) {
		return;
	}

	$this->activate_pos_subtotal_filter();
}

/**
 * Add the subtotal filter if not already active, and schedule its removal.
 *
 * The filter is removed on the next calculate_totals() call via
 * woocommerce_order_after_calculate_totals, which fires at the end
 * of recalculate_coupons().
 */
public function activate_pos_subtotal_filter(): void {
	if ( has_filter( 'woocommerce_order_item_get_subtotal', array( $this, 'filter_pos_item_subtotal' ) ) ) {
		return; // Already active.
	}

	add_filter( 'woocommerce_order_item_get_subtotal', array( $this, 'filter_pos_item_subtotal' ), 10, 2 );
	add_filter( 'woocommerce_order_item_get_subtotal_tax', array( $this, 'filter_pos_item_subtotal_tax' ), 10, 2 );

	// Remove the filter after totals are calculated (end of recalculate_coupons).
	add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'deactivate_pos_subtotal_filter' ), 10, 2 );
}

/**
 * Filter the line item subtotal to return the POS-discounted price.
 *
 * Only affects items with _woocommerce_pos_data meta containing a 'price' field.
 * Items without POS data are returned unchanged.
 *
 * @param string                $subtotal The original subtotal.
 * @param WC_Order_Item_Product $item     The order item.
 *
 * @return string The POS price if available, otherwise the original subtotal.
 */
public function filter_pos_item_subtotal( $subtotal, $item ) {
	if ( ! $item instanceof \WC_Order_Item_Product ) {
		return $subtotal;
	}

	$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
	if ( empty( $pos_data_json ) ) {
		return $subtotal;
	}

	$pos_data = json_decode( $pos_data_json, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! \is_array( $pos_data ) || ! isset( $pos_data['price'] ) ) {
		return $subtotal;
	}

	// Return POS price * quantity as the subtotal.
	// WooCommerce stores subtotal as the total for all quantities.
	return (string) ( (float) $pos_data['price'] * $item->get_quantity() );
}

/**
 * Filter the line item subtotal tax during POS coupon recalculation.
 *
 * When the POS sets tax_status to 'none', subtotal tax should be 0.
 * Otherwise, return the original subtotal tax.
 *
 * @param string                $subtotal_tax The original subtotal tax.
 * @param WC_Order_Item_Product $item         The order item.
 *
 * @return string
 */
public function filter_pos_item_subtotal_tax( $subtotal_tax, $item ) {
	if ( ! $item instanceof \WC_Order_Item_Product ) {
		return $subtotal_tax;
	}

	$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
	if ( empty( $pos_data_json ) ) {
		return $subtotal_tax;
	}

	$pos_data = json_decode( $pos_data_json, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! \is_array( $pos_data ) ) {
		return $subtotal_tax;
	}

	if ( isset( $pos_data['tax_status'] ) && 'none' === $pos_data['tax_status'] ) {
		return '0';
	}

	return $subtotal_tax;
}

/**
 * Remove the temporary subtotal filter after coupon recalculation completes.
 *
 * @param bool                $and_taxes Whether taxes were calculated.
 * @param WC_Abstract_Order   $order     The order object.
 */
public function deactivate_pos_subtotal_filter( $and_taxes, $order ): void {
	remove_filter( 'woocommerce_order_item_get_subtotal', array( $this, 'filter_pos_item_subtotal' ), 10 );
	remove_filter( 'woocommerce_order_item_get_subtotal_tax', array( $this, 'filter_pos_item_subtotal_tax' ), 10 );
	remove_action( 'woocommerce_order_after_calculate_totals', array( $this, 'deactivate_pos_subtotal_filter' ), 10 );
}
```

**Step 2: Modify Form_Handler to activate filter before remove_coupon()**

In `includes/Form_Handler.php`, the `coupon_action()` method needs to activate the subtotal filter before calling `$order->remove_coupon()`. WooCommerce's `remove_coupon()` has no "before" hook, so we handle it here.

Replace the `coupon_action()` method body (lines 99-128) with:

```php
public function coupon_action() {
	global $wp;

	$is_coupon_request = isset( $_POST['pos_apply_coupon'] ) || isset( $_POST['pos_remove_coupon'] );
	if ( ! woocommerce_pos_request() || ! $is_coupon_request ) {
		return;
	}

	// Check for nonce.
	if ( ! isset( $_POST['pos_coupon_nonce'] ) || ! wp_verify_nonce( $_POST['pos_coupon_nonce'], 'pos_coupon_action' ) ) {
		return;
	}

	$order_id    = absint( $wp->query_vars['order-pay'] );
	$order       = wc_get_order( $order_id );

	if ( isset( $_POST['pos_apply_coupon'] ) ) {
		$coupon_code = isset( $_POST['pos_coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pos_coupon_code'] ) ) : '';
		// Note: apply_coupon() fires woocommerce_order_applied_coupon which
		// activates the subtotal filter via Orders::before_coupon_recalculation().
		$apply_result = $order->apply_coupon( $coupon_code );
		if ( is_wp_error( $apply_result ) ) {
			wc_add_notice( $apply_result->get_error_message(), 'error' );
		}
	} elseif ( isset( $_POST['pos_remove_coupon'] ) ) {
		$coupon_code = sanitize_text_field( wp_unslash( $_POST['pos_remove_coupon'] ) );

		// WooCommerce's remove_coupon() calls recalculate_coupons() with no
		// "before" hook, so we must activate the POS subtotal filter manually.
		// The filter is deactivated automatically by woocommerce_order_after_calculate_totals.
		if ( woocommerce_pos_is_pos_order( $order ) ) {
			$orders_instance = new \WCPOS\WooCommercePOS\Orders();
			$orders_instance->activate_pos_subtotal_filter();
		}

		$remove_result = $order->remove_coupon( $coupon_code );
		if ( ! $remove_result ) {
			wc_add_notice( __( 'Error', 'woocommerce' ) );
		}
	}
}
```

**Important note about the `Orders` instance:** The `Form_Handler` needs access to the same `Orders` instance to call `activate_pos_subtotal_filter()`. The current code creates a new instance, but since `activate_pos_subtotal_filter()` adds global WordPress filters (not instance-specific), this works fine. The filters are added to the global `$wp_filter` array and will be cleaned up by `deactivate_pos_subtotal_filter()` regardless of which instance removes them.

However, a cleaner approach would be to use a static method or pass the Orders instance to Form_Handler. The implementor should check how other parts of the codebase handle this and follow the existing pattern.

**Step 3: Verify `woocommerce_order_after_calculate_totals` exists**

This hook was confirmed to exist at `abstract-wc-order.php:2019`:
```php
do_action( 'woocommerce_order_after_calculate_totals', $and_taxes, $this );
```

It fires at the end of `calculate_totals()`, which is the last thing `recalculate_coupons()` calls. The cleanup is reliable.

**Step 4: Update the test for remove_coupon**

The `test_coupon_removal_restores_pos_discount` test calls `$order->remove_coupon()` directly (not through Form_Handler). For this test to pass, the subtotal filter must be active. Since the test calls `apply_coupon()` first (which activates the filter via `woocommerce_order_applied_coupon`), then the filter gets deactivated after `calculate_totals`. So before calling `remove_coupon()`, the test needs to manually activate the filter:

Update the test to:
```php
public function test_coupon_removal_restores_pos_discount(): void {
	$order  = $this->create_pos_order_with_discount();
	$coupon = CouponHelper::create_coupon( 'test10remove', 'publish', array(
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

	$this->assertEquals( 16, (float) $item->get_total(), 'After coupon removal, total should return to POS price ($16)' );
	$this->assertEquals( 18, (float) $item->get_subtotal( 'edit' ), 'Stored subtotal should remain at original price ($18)' );
}
```

**Step 5: Run the tests**

Run: `pnpm run test:unit:php -- --filter Test_Orders_Coupon_Discount --verbose`

Expected: Tests 1, 2, 4, and 5 should PASS. Test 3 (`exclude_sale_items`) may still fail (handled in Task 3).

**Step 6: Commit**

```bash
git add includes/Orders.php includes/Form_Handler.php tests/includes/Test_Orders_Coupon_Discount.php
git commit -m "feat: preserve POS discounts during coupon recalculation (#444)"
```

---

### Task 3: Extend order_item_product for exclude_sale_items support

**Files:**
- Modify: `includes/Orders.php:144-173` (the existing `order_item_product` method)

**Step 1: Modify the `order_item_product` method**

Replace the existing method to also handle real products with POS discounts (not just misc products). The key change: for products that exist AND have `_woocommerce_pos_data` with a `price` different from `regular_price`, set the sale price on the product object so `is_on_sale()` returns true.

Replace the method body with:

```php
public function order_item_product( $product, $item ) {
	// Handle misc products (product_id = 0) — existing behavior.
	if ( ! $product && 0 === $item->get_product_id() ) {
		$product = new WC_Product_Simple();

		$product->set_name( $item->get_name() );
		$sku = $item->get_meta( '_sku', true );
		$product->set_sku( $sku ? $sku : '' );
	}

	// Apply POS price data to the product (for both misc and real products).
	if ( $product ) {
		$pos_data_json = $item->get_meta( '_woocommerce_pos_data', true );
		if ( ! empty( $pos_data_json ) ) {
			$pos_data = json_decode( $pos_data_json, true );

			if ( JSON_ERROR_NONE === json_last_error() && \is_array( $pos_data ) ) {
				if ( isset( $pos_data['price'] ) ) {
					$product->set_price( $pos_data['price'] );

					/**
					 * Filter whether a POS-discounted item should be treated as "on sale."
					 *
					 * When true, the product's sale_price is set to the POS price, making
					 * is_on_sale() return true. This causes coupons with exclude_sale_items
					 * to skip this item.
					 *
					 * @param bool                 $is_on_sale Whether the item is on sale. Default: true if POS price < regular price.
					 * @param WC_Product           $product    The product object.
					 * @param WC_Order_Item_Product $item      The order line item.
					 * @param array                $pos_data   The decoded POS data.
					 */
					$is_on_sale = isset( $pos_data['regular_price'] )
						&& (float) $pos_data['price'] < (float) $pos_data['regular_price'];

					$is_on_sale = apply_filters(
						'woocommerce_pos_item_is_on_sale',
						$is_on_sale,
						$product,
						$item,
						$pos_data
					);

					if ( $is_on_sale ) {
						$product->set_sale_price( $pos_data['price'] );
					}
				}
				if ( isset( $pos_data['regular_price'] ) ) {
					$product->set_regular_price( $pos_data['regular_price'] );
				}
				if ( isset( $pos_data['tax_status'] ) ) {
					$product->set_tax_status( $pos_data['tax_status'] );
				}
			}
		}
	}

	return $product;
}
```

**Step 2: Run the tests**

Run: `pnpm run test:unit:php -- --filter Test_Orders_Coupon_Discount --verbose`

Expected: Test 3 (`exclude_sale_items`) should now PASS. All 5 tests should be green.

**Step 3: Also run the existing Orders tests for regressions**

Run: `pnpm run test:unit:php -- --filter Test_Orders --verbose`

Expected: All existing `Test_Orders` tests still pass, especially `test_order_item_product_creates_misc_product` and `test_direct_order_item_product_misc_item`.

**Step 4: Commit**

```bash
git add includes/Orders.php
git commit -m "feat: treat POS-discounted items as sale items for exclude_sale_items (#444)"
```

---

### Task 4: Run full test suite and lint

**Step 1: Run full test suite**

Run: `pnpm run test`

Expected: All tests pass, no regressions.

**Step 2: Run linter**

Run: `composer run lint`

Expected: No new lint errors. Fix any that appear.

**Step 3: Commit lint fixes (if any)**

```bash
git add -A
git commit -m "style: fix lint issues"
```

---

### Task 5: Add documentation to docs.wcpos.com

**Files:**
- To be determined: add a markdown page to the docs.wcpos.com repository (or note for manual addition)

**Step 1: Draft the documentation content**

Create a documentation section covering:

1. **How coupons interact with POS discounts** — when a cashier applies a POS discount and then a coupon is added, the coupon calculates against the POS-discounted price, not the original product price
2. **exclude_sale_items behavior** — POS-discounted items are treated as "sale items," so coupons with this flag will skip them
3. **Available filters:**
   - `woocommerce_pos_item_is_on_sale` — override whether a POS-discounted item is treated as on sale (params: `$is_on_sale`, `$product`, `$item`, `$pos_data`)
4. **Example:** A product at $18 discounted to $16 at the POS, with a 10% coupon, results in a final price of $14.40

**Step 2: Check if docs.wcpos.com repo is accessible locally**

Run: `ls ~/Projects/docs.wcpos.com 2>/dev/null || ls ~/Projects/docs-wcpos 2>/dev/null || echo "Docs repo not found locally — create a GitHub issue instead"`

If the docs repo is available, create the markdown file. If not, create a GitHub issue on the docs repo to track this task.

**Step 3: Commit docs (or issue)**

Commit whatever was created, or note that a docs issue was filed.
