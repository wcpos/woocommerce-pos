# POS Discount Preservation During Coupon Application

**Issue:** [#444](https://github.com/wcpos/woocommerce-pos/issues/444)
**Date:** 2026-02-05
**Status:** Approved

## Problem

When a POS discount is applied to a line item (e.g. $18 reduced to $16), and a coupon is later applied via `template/payment.php` or WP Admin, WooCommerce's `recalculate_coupons()` overwrites the POS discount. The line item reverts to the original product price before the coupon is calculated.

### Root Cause

WooCommerce uses the line item `subtotal` field as the base price for coupon calculations. The POS stores:

- `subtotal` = $18 (original price, used for display in the POS app)
- `total` = $16 (POS-discounted price)
- `_woocommerce_pos_data` meta = `{"price":"16","regular_price":"18",...}`

When `recalculate_coupons()` runs, it:

1. **Resets** `total` back to `subtotal` ($18) -- POS discount lost
2. **Calculates** coupon discount using `subtotal` ($18) as the base
3. Sets `total` = `subtotal` - coupon discount

The POS discount is wiped out in step 1.

### WooCommerce's Model (and Why It's Confusing)

WooCommerce overloads `subtotal` to mean "pre-coupon price." In their model:

- `subtotal` = price before any coupon discounts
- `total` = price after coupon discounts

There is no built-in concept of "line-level discount" or "original price before manual adjustment." When an admin edits a line item price in WP Admin, WooCommerce updates **both** `subtotal` and `total`, so the original price is lost there too.

The POS interpretation (subtotal = original price, total = adjusted price) is more intuitive but conflicts with WooCommerce's coupon recalculation.

## Solution

### Approach: Temporary Subtotal Filter

Filter `woocommerce_order_item_get_subtotal` during coupon recalculation on POS orders. The filter reads the POS-discounted price from `_woocommerce_pos_data` meta and returns it as the subtotal, so WooCommerce's coupon system uses the correct base price.

The actual stored subtotal ($18) is never modified -- only the getter is filtered temporarily. This preserves the original price for display in the POS app.

### How It Works

1. Detect when `apply_coupon()` or `remove_coupon()` is called on a POS order (`created_via === 'woocommerce-pos'`)
2. Add a filter on `woocommerce_order_item_get_subtotal` that returns the POS price from `_woocommerce_pos_data` meta
3. WooCommerce's coupon recalculation runs using the filtered subtotal:
   - Total reset: `set_total(get_subtotal())` -> gets $16 (filtered) instead of $18
   - Discount base: `WC_Discounts` reads `get_subtotal()` -> calculates coupon against $16
   - Final total: 10% coupon on $16 = $1.60 discount, total = $14.40
4. Remove the filter after recalculation completes
5. Stored subtotal remains $18 for display

### Exclude Sale Items

WooCommerce coupons have an `exclude_sale_items` property. The POS discount is analogous to a cashier putting an item on sale, so we respect this:

- Extend the existing `woocommerce_order_item_product` filter (currently only handles misc products with `product_id === 0`) to also set the sale price on real products with POS discounts
- This makes `WC_Product::is_on_sale()` return true naturally
- Coupons with `exclude_sale_items = true` will skip POS-discounted items

A filter `woocommerce_pos_item_is_on_sale` allows developers to override this behavior per-item, per-coupon.

### Scoping / Defensiveness

All changes are scoped to POS orders only:

- `created_via === 'woocommerce-pos'` check on the order
- `_woocommerce_pos_data` meta must exist on the line item with a valid `price` field
- Non-POS orders are completely unaffected (no-op)

## Tests (TDD -- Red Then Green)

### Test 1: POS discount preserved when coupon applied

- Create a POS order with a line item discounted from $18 to $16 (with `_woocommerce_pos_data` meta)
- Apply a 10% coupon
- Assert subtotal remains $18 (display value unchanged)
- Assert total = $14.40 ($16 - 10% of $16)
- Assert the POS discount is preserved

### Test 2: Coupon removal restores POS discount

- Same setup as Test 1
- Remove the coupon
- Assert total returns to $16 (POS price), not $18 (original)

### Test 3: exclude_sale_items respects POS discounts

- Create a POS order with a discounted line item
- Apply a coupon that has `exclude_sale_items = true`
- Assert the coupon discount is $0 for that item (skipped because "on sale")

### Test 4: Non-POS orders unaffected

- Create a regular WooCommerce order (not created via POS)
- Apply a coupon
- Assert standard WooCommerce behavior with no interference

### Test 5: Mixed items -- with and without POS discounts

- Create a POS order with two items: one with POS discount, one at regular price
- Apply a coupon
- Assert coupon calculates correctly against each item's appropriate base price

## Files to Modify

- `includes/Orders.php` -- Add subtotal filter logic, extend `order_item_product` for sale detection
- `includes/Form_Handler.php` -- May need adjustment depending on hook placement
- `tests/` -- New test file for coupon + POS discount interaction

## Post-Implementation

- Add documentation to docs.wcpos.com explaining:
  - How coupons interact with POS discounts
  - The `exclude_sale_items` behavior for POS-discounted items
  - Available filters for customization (`woocommerce_pos_item_is_on_sale`)

## Design Decisions Log

- **Why not modify the stored subtotal?** The POS app uses subtotal for display (showing original vs. discounted price). Changing the stored value would break that.
- **Why not scope to Form_Handler only?** Coupons can also be applied from WP Admin on POS orders. The filter approach covers all entry points.
- **Why not add a coupon-level POS option?** It would appear on every coupon for every WooCommerce store, confusing the majority who don't use POS. A global WCPOS setting is a better future path if needed.
- **Why respect exclude_sale_items?** The POS discount is semantically equivalent to a cashier putting an item on sale. This uses existing WooCommerce semantics rather than inventing new ones.
