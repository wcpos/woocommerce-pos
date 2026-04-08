<!-- DEPRECATED: This file is no longer maintained. See .wiki/ for current content. -->
# Understanding `_woocommerce_pos_data`

## What is it?

`_woocommerce_pos_data` is a JSON metadata field stored on WooCommerce order line items created by the POS. It holds the POS-specific pricing context that WooCommerce doesn't natively track.

```json
{
  "price": "16",
  "regular_price": "18",
  "tax_status": "taxable"
}
```

It is set on every line item added from the POS — even when the cashier hasn't changed the price.

## Why does it exist?

WooCommerce's line item model has two price fields:

| Field | WooCommerce meaning |
|---|---|
| `subtotal` | Price **before coupon discounts** |
| `total` | Price **after coupon discounts** |

There is no built-in concept of "original price before a manual adjustment." When a WP Admin user edits a line item price, WooCommerce overwrites **both** subtotal and total — the original price is gone.

The POS needs something different. When a cashier changes a price from $18 to $16, the POS wants to show "was $18, now $16" in the cart and on receipts. To support this, the POS uses a different convention:

| Field | POS meaning |
|---|---|
| `subtotal` | Original/regular price (the "was" price) |
| `total` | Current price (the cashier-set price, before coupons) |

This conflict is the reason `_woocommerce_pos_data` exists — it stores the "source of truth" for what the cashier intended, independent of how WooCommerce manipulates subtotal/total during coupon recalculation.

## The three fields

### `price`

The customer-facing price per unit. This is the price the cashier sees and can edit in the POS cart.

- Starts as the product's current price (which may already be a WooCommerce sale price)
- Changes when the cashier manually adjusts the price
- If prices include tax in WooCommerce settings, this is the **tax-inclusive** amount
- Used as the base for coupon calculations (via the subtotal filter — see below)

### `regular_price`

The original/reference price per unit, taken from `product.regular_price` at the time the item was added to the cart.

- Never changes after the item is added (immutable reference point)
- Used to calculate the `subtotal` on the line item: `subtotal = regular_price * quantity`
- Enables the "was $18" display in the POS UI
- Used to determine if an item is "on sale" for `exclude_sale_items` coupon logic: `price < regular_price`

### `tax_status`

The tax status for the line item. It can be `"taxable"`, `"none"`, or `"shipping"`.

- Taken from `product.tax_status` at the time the item is added
- Needed because the WC REST API **does not include tax_status on line items**, only on products
- The POS calculates taxes client-side and needs this value
- Can be overridden by the cashier (e.g., marking an item as tax-exempt)
- On the server, `Orders.php` uses this to override the product's tax status during tax calculation

## Where it's set

### Client-side (monorepo-v2)

When adding a product to the cart (`utils.ts`):
```ts
const price = sanitizePrice(product.price);           // current/sale price
const regular_price = sanitizePrice(product.regular_price);

meta_data.push({
  key: '_woocommerce_pos_data',
  value: JSON.stringify({ price, regular_price, tax_status }),
});
```

When the cashier edits a price (`use-update-line-item.ts`):
```ts
updatedItem = updatePosDataMeta(updatedItem, {
  price: newPrice,                        // updated
  regular_price: prevData.regular_price,  // unchanged
  tax_status: prevData.tax_status,        // unchanged
});
```

### Server-side (woocommerce-pos)

The server **reads** the meta but never **writes** it. It's set by the client and sent via the REST API as part of the line item's `meta_data` array.

## How it interacts with coupons

This is the tricky part. WooCommerce's coupon system uses `get_subtotal()` as the base price for all coupon discount calculations. The flow:

1. `recalculate_coupons()` resets `total = subtotal` for every line item
2. `WC_Discounts::set_items_from_order()` reads `get_subtotal()` as the discount base
3. Each coupon calculates its discount against this base
4. `total` is set to `subtotal - coupon_discount`

The problem: POS sets `subtotal = regular_price * qty` (e.g., $18), but the cashier intended the price to be $16. Without intervention, WC would calculate coupons against $18, ignoring the cashier's price change.

### The subtotal filter solution

`Orders.php` hooks into `woocommerce_order_item_get_subtotal` during coupon recalculation. When WC reads `get_subtotal()`, the filter intercepts and returns the POS price from `_woocommerce_pos_data` instead:

```text
Without filter: get_subtotal() -> $18 (stored subtotal)
With filter:    get_subtotal() -> $16 (from pos_data.price)
```

The filter is:
- **Activated** when `apply_coupon()` fires on a POS order
- **Scoped** to POS orders only (`created_via === 'woocommerce-pos'`)
- **Temporary** — removed after `calculate_totals()` completes
- **Tax-aware** — extracts tax from the POS price when prices include tax

The stored `subtotal` ($18) is never modified. Only the getter is filtered during coupon calculation. This preserves the "was $18" display.

### Visual summary

```text
Product: regular_price=$18, no WC sale

Cashier sets price to $16:
  _woocommerce_pos_data = { price: "16", regular_price: "18", tax_status: "taxable" }
  Line item: subtotal=$18, total=$16

Apply 10% coupon:
  WC reads get_subtotal() -> filter returns $16 (from pos_data)
  WC calculates: 10% of $16 = $1.60
  WC sets: total = $16 - $1.60 = $14.40
  Line item: subtotal=$18, total=$14.40

  Coupon line: discount=$1.60
  Order: discount_total = $18 - $14.40 = $3.60 (includes $2 POS discount + $1.60 coupon)

Remove coupon:
  Filter returns $16 again, no coupons to apply
  total resets to $16
  Line item: subtotal=$18, total=$16
```

## FAQ

**Q: Why not just set subtotal=$16 and total=$16 like WP Admin does?**
Because we'd lose the original price. The POS UI shows "was $18, now $16" which requires knowing both values. WP Admin doesn't have this feature.

**Q: Why store this in meta instead of using WooCommerce's native fields?**
WooCommerce overloads `subtotal` to mean "pre-coupon price" and `total` to mean "post-coupon price." There's no native field for "pre-cashier-adjustment price." The meta is the only place to store the POS-specific context without conflicting with WC's coupon system.

**Q: What if `_woocommerce_pos_data` is missing or invalid?**
The code falls through gracefully — the subtotal filter returns the original stored subtotal, and WC behaves normally. This is tested in `test_coupon_validation_falls_back_with_invalid_pos_json`.

**Q: Does this affect non-POS orders?**
No. Every filter checks `woocommerce_pos_is_pos_order()` and the presence of `_woocommerce_pos_data` meta. Non-POS orders are completely unaffected.

**Q: What about the client-side calculations — do they match?**
Not perfectly yet. There are known divergences between the client-side (React Native) coupon calculations and the server-side (PHP) calculations. The server-side is authoritative; client-side differences only affect what the cashier sees before the order is saved.
