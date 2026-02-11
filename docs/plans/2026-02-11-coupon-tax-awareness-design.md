# Coupon Tax-Awareness Fix

**Issue**: https://github.com/wcpos/woocommerce-pos/issues/506
**Date**: 2026-02-11

## Problem

When applying a percentage coupon to a POS order with tax-inclusive pricing (e.g., 21% VAT), the line item price inflates instead of being discounted. A 10% coupon on a €447.00 product (incl 21% VAT) produces:

| | Before coupon | After coupon (bug) | Expected |
|---|---|---|---|
| Line price | €447.00 | €497.51 | €447.00 |
| Discount | — | -€9.10 | -€44.70 |
| Total | €447.00 | €437.90 | €402.30 |
| VAT 21% | €77.58 | €84.77 | €69.82 |

## Root Cause

Two issues in `Orders.php` subtotal filters:

### 1. `filter_pos_item_subtotal()` returns tax-inclusive price as tax-exclusive

The POS app stores the customer-facing (tax-inclusive) price in `_woocommerce_pos_data.price` (e.g., €447.00). The filter returns this directly as `get_subtotal()`, but WooCommerce always treats `get_subtotal()` as **tax-exclusive**.

When `WC_Discounts::set_items_from_order()` builds the discount base for tax-inclusive orders, it adds `get_subtotal() + get_subtotal_tax()` — double-counting the tax:

```
Discount base = €447.00 (incl-tax, used as ex-tax) + €77.58 (original tax) = €524.58
```

### 2. `filter_pos_item_subtotal_tax()` doesn't recalculate tax

For taxable items, it returns the original `subtotal_tax` (€77.58) — the tax on the original subtotal, not the POS price. When the subtotal filter changes the base, the tax becomes inconsistent.

### Why existing tests don't catch this

Every test in `Test_Orders_Coupon_Discount.php` uses `tax_status: 'none'` and `calculate_totals(false)`. Zero test cases with actual tax rates.

## Fix

### Core: Tax-aware subtotal filters

Add a private static helper `get_pos_price_components($item)` that:

1. Reads `_woocommerce_pos_data` meta (price, tax_status)
2. If `tax_status === 'none'` or `!order->get_prices_include_tax()`: returns price as-is for subtotal, 0 or original for tax
3. If prices include tax AND item is taxable: uses `WC_Tax::calc_tax($pos_price, $rates, true)` to extract tax, returns ex-tax portion as subtotal and tax portion as subtotal_tax

Tax rates come from `WC_Tax::find_rates()` using `$order->get_tax_location()` (which already respects `_woocommerce_pos_tax_based_on` order meta) and `$item->get_tax_class()`.

### Updated filter methods

**`filter_pos_item_subtotal()`**: Calls helper, returns the ex-tax subtotal component.

**`filter_pos_item_subtotal_tax()`**: Calls helper, returns the calculated tax component. Returns '0' for tax_status='none'.

### Scenario matrix

| POS price | prices_incl_tax | tax_status | Subtotal returned | Subtotal tax returned |
|---|---|---|---|---|
| 16 | false | none | 16 | 0 |
| 447 | true | taxable | 369.42 | 77.58 |
| 447 | true | none | 447 | 0 |
| 369.42 | false | taxable | 369.42 | (original) |
| 400 (discounted) | true | taxable | 330.58 | 69.42 |

## Test Plan

### 1. Bug reproduction test

Recreate the exact scenario from issue #506:
- 21% VAT, prices include tax
- Product €447.00 incl tax
- POS order, no POS discount
- Apply 10% coupon
- Assert correct totals matching expected column from bug report

### 2. Comprehensive tax permutation tests

For each of these tax configs:
- Prices include tax + taxable
- Prices include tax + tax-exempt
- Prices exclude tax + taxable
- Prices exclude tax + tax-exempt

Test with:
- No POS discount (price == regular_price)
- POS discount (price < regular_price)
- Apply coupon
- Remove coupon

### 3. Mixed items test

One taxable + one exempt item in same POS order, coupon applied, verify each item calculated independently.

### 4. Audit existing tests

Add `prices_include_tax = true` variants to `Test_Order_Taxes.php` where relevant.

## Files Changed

- `includes/Orders.php` — fix filter methods, add helper
- `tests/includes/Test_Orders_Coupon_Discount.php` — add tax-aware tests
- `tests/includes/API/Test_Order_Taxes.php` — add tax-inclusive variants (if needed)
