# 80mm Detailed Thermal Receipt — Tax Summary Redesign

## Summary

Redesign the per-rate tax summary block on `thermal-detailed-80mm.xml` so it stops truncating and stops printing a meaningless internal id. The current block packs two label+value pairs into fixed-width columns that the thermal renderer truncates, and it prints the WooCommerce tax-rate database id as if it were a meaningful code. Replace it with a compact one-line-per-rate layout, and remove the stray rate-id from the two sibling templates that share the same defect.

## Problem

The tax summary in `thermal-detailed-80mm.xml` (lines 178-192) has two distinct defects, both visible on a normal receipt:

```text
Tax included
US (10%) 4                                4,52 €
  Taxable excl. 45,22 €    Taxable incl. 49…
```

**1. Truncation.** The second `<row>` places `Taxable excl. <amount>` in a `width="*"` column and `Taxable incl. <amount>` in a `width="18"` column. Every `<col>` is rendered by `packages/thermal-utils/src/thermal-renderer.ts` (`renderCol`, line 399) as `flex: 0 0 <width>ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap`. `Taxable incl. 49,74 €` is 21 characters, so an 18-char column truncates it to `Taxable incl. 49…`. The layout is inherently fragile: longer currency values or translated labels make it worse, and even the left column can overflow.

**2. Meaningless rate-id.** Line 185 renders `{{#code}} {{code}}{{/code}}`. In the `tax_summary[]` data structure, `code` is the WooCommerce tax-rate **database primary key** (`includes/Services/Receipt_Data_Builder.php:446`, `'code' => $rate_id`), not a human-meaningful code. So `US (10%) 4` prints a database row id onto a customer receipt. `label` + `rate` already identify the tax line; the id is noise.

The same rate-id leak exists in two sibling templates:

- `thermal-detailed-58mm.xml:181` — identical `{{#code}} {{code}}{{/code}}` on the rate line.
- `detailed-receipt.html:205` — renders `{{label}}{{#code}} · {{code}}{{/code}}` in the tax-summary table, e.g. `US · 4`.

Note `code` is overloaded across data structures: in `discounts[]` it is the coupon code (`Receipt_Data_Builder.php:295`, `$coupon_item->get_code()`), which is meaningful and is used correctly elsewhere in these templates. Only the `tax_summary[]` use of `code` is the defect.

## Goals

- The 80mm detailed tax summary never truncates per-rate information under realistic order data.
- The tax summary stops printing the internal tax-rate id.
- Each tax rate still shows its identity (label + percentage), the net amount it was applied to, and the tax charged.
- Remove the same rate-id leak from the sibling templates (`thermal-detailed-58mm.xml`, `detailed-receipt.html`).
- Keep the existing tax-summary heading behaviour (`Tax Summary` for additive display, `Tax included` for inclusive display).

## Non-Goals

- No changes to `Receipt_Data_Builder` or the receipt data contract — `code` stays in `tax_summary[]`; the templates simply stop printing it.
- No redesign of the 58mm stacked tax layout — it works correctly on 32 columns; it only loses the rate-id.
- No redesign of the `detailed-receipt.html` tax table beyond removing the rate-id cell content.
- No change to which taxable base is shown per display mode (the net/excl base is shown in both modes, as today).
- No new translatable strings.

## Proposed Approach

### 1. Compact one-line-per-rate layout for `thermal-detailed-80mm.xml`

Replace the tax summary rows (lines 183-192) with a single `<row>` per rate. The net taxable base moves inline after the rate, using `@` — already this template's symbol for a price reference (line 139, the line-item discount rows) and language-neutral, so no new i18n string is needed. The truncating second row is deleted, along with the `{{#code}} {{code}}{{/code}}` fragment.

Before:

```xml
{{#tax_summary}}
<row>
  <col width="*">{{label}}{{#rate}} ({{rate}}%){{/rate}}{{#code}} {{code}}{{/code}}</col>
  <col width="14" align="right">{{tax_amount_display}}</col>
</row>
<row>
  <col width="*">  {{i18n.taxable_excl_short}} {{taxable_amount_excl_display}}</col>
  <col width="18" align="right">{{i18n.taxable_incl_short}} {{taxable_amount_incl_display}}</col>
</row>
{{/tax_summary}}
```

After:

```xml
{{#tax_summary}}
<row>
  <col width="*">{{label}}{{#rate}} ({{rate}}%){{/rate}}{{#taxable_amount_excl_display}} @ {{taxable_amount_excl_display}}{{/taxable_amount_excl_display}}</col>
  <col width="14" align="right">{{tax_amount_display}}</col>
</row>
{{/tax_summary}}
```

Renders as:

```text
Tax Summary
US (10%) @ 45,22 €                        4,52 €
VAT (20%) @ 30,00 €                       6,00 €
```

The `{{#taxable_amount_excl_display}}` guard wraps the `@ <base>` fragment so it disappears cleanly when the data builder has no net base for a rate (`taxable_amount_excl` can be `null` — `Receipt_Data_Builder.php:442`), consistent with the template's "blocks disappear when data is empty" design.

The `{{#has_tax_summary}}` heading block (lines 179-182) is unchanged.

**Dropped figure:** the per-rate `taxable_amount_incl` (gross base) is no longer shown on the 80mm template. It is fully derivable (`incl = excl + tax`, both still on the line) and the Totals block immediately below already shows the tax-inclusive picture.

### 2. Remove the rate-id from `thermal-detailed-58mm.xml`

On line 181, drop `{{#code}} {{code}}{{/code}}`:

```xml
<col width="*">{{label}}{{#rate}} ({{rate}}%){{/rate}}</col>
```

The 58mm stacked three-row layout (rate line, then `Taxable excl.` and `Taxable incl.` rows) is otherwise unchanged — it does not truncate on 32 columns.

### 3. Remove the rate-id from `detailed-receipt.html`

On line 205, drop the `{{#code}} · {{code}}{{/code}}` fragment so the tax cell shows just the label:

```html
<td style="padding: 8px 6px; border-bottom: 1px solid #f3f4f6;">{{label}}</td>
```

### 4. Update the stale template header comments

- `thermal-detailed-80mm.xml` header comment (lines 26-27) — the "Tax summary" section description currently says "per-rate breakdown with net (taxable excl.) and gross (taxable incl.) under each rate." Update it to describe the one-line-per-rate layout (rate, the net base it was applied to, the tax charged).
- `thermal-detailed-58mm.xml` header comment (lines 22-24) — currently says "the 80mm sibling shows them side-by-side; 32 columns is too narrow for that." This is now stale. Update it to reflect that the 80mm sibling shows the net base inline on the rate line, while 58mm keeps the figures stacked.

## Files Expected To Change

- `templates/gallery/thermal-detailed-80mm.xml`
  - replace the tax summary rows with the compact one-line-per-rate layout; remove the rate-id; update the header comment
- `templates/gallery/thermal-detailed-58mm.xml`
  - remove the rate-id from the tax rate line; update the header comment
- `templates/gallery/detailed-receipt.html`
  - remove the rate-id from the tax summary table cell
- `packages/template-gallery/src/__tests__/thermal-detailed-80mm-render.test.ts` (new)
  - render test mirroring `thermal-detailed-58mm-render.test.ts`, asserting the compact tax line renders and the rate-id does not appear
- `tests/includes/Templates/Test_Receipt_Template_Tax_Display.php`
  - add content-level assertions that the tax summary no longer prints the rate-id: the `{{#rate}} ({{rate}}%){{/rate}}{{#code}}` adjacency in the two thermal templates, and the `{{#code}} ·` fragment in `detailed-receipt.html`

## Error Handling / Risk

Low risk — this is a presentational change to gallery template files, with no code or data-contract changes.

Considerations:

- A rate row with no net base: the `{{#taxable_amount_excl_display}}` guard collapses the inline `@ <base>` fragment cleanly, provided the money formatter yields an empty `taxable_amount_excl_display` for a `null` base — confirm this when building the render-test fixture.
- Pathologically long tax labels (e.g. a 30-plus-character rate name combined with a large currency value) can still ellipsis-truncate, because the rate line lives in a `width="*"` column (~34 chars on 48-CPL paper). This is a large improvement over the current 18-char hard cap and covers all realistic data; it is an accepted residual limit, not a regression.
- `Test_Receipt_Template_Tax_Display.php` already passes against the new markup: it asserts the `{{#tax.display_excl}}` / `{{#tax.display_incl}}{{i18n.included_tax}}` heading branches (kept intact) and the excl line-item fields (untouched). No existing assertion targets the tax summary row layout.
- `thermal-detailed-58mm-render.test.ts` continues to pass: it asserts `Taxable excl.` / `Taxable incl.` text (still present in the 58mm stacked layout) and does not assert on `code`.

## Testing Strategy

### Automated verification

- New `packages/template-gallery/src/__tests__/thermal-detailed-80mm-render.test.ts`: render `thermal-detailed-80mm.xml` with sample data (mirroring the 58mm render test fixture, including a multi-rate `tax_summary`), and assert:
  - the compact rate line renders (rate label, the inline net base, the tax amount)
  - the rendered output does not contain the tax-rate id from the fixture
  - the heading still branches between `Tax Summary` and `Tax included` by display mode
- Extend `tests/includes/Templates/Test_Receipt_Template_Tax_Display.php` with content assertions that the tax summary no longer prints the rate-id: for `thermal-detailed-58mm.xml` and `thermal-detailed-80mm.xml`, that the `{{#rate}} ({{rate}}%){{/rate}}` rate line is no longer immediately followed by `{{#code}}`; for `detailed-receipt.html`, that the `{{#code}} ·` fragment is gone.
- Run the existing template-gallery and PHP receipt-template suites to confirm no regression.

Commands:

- JS: `pnpm --filter @wcpos/template-gallery test`
- PHP: `pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Templates/Test_Receipt_Template_Tax_Display.php`

### Manual verification

In the template editor / receipt preview, render the 80mm detailed thermal template against an order with one or more tax rates and confirm:

- each rate shows on a single line as `<label> (<rate>%) @ <net base>` with the tax amount right-aligned
- nothing is truncated with an ellipsis
- no stray numeric id appears after the rate
- a tax-inclusive order still shows the `Tax included` heading
- the 58mm detailed template still shows its stacked `Taxable excl.` / `Taxable incl.` rows, now without the rate-id

## Acceptance Criteria

- `thermal-detailed-80mm.xml` shows one line per tax rate: label, percentage, inline net base, right-aligned tax amount.
- No tax summary content is ellipsis-truncated under realistic order data.
- No template prints the WooCommerce tax-rate database id (`thermal-detailed-80mm.xml`, `thermal-detailed-58mm.xml`, `detailed-receipt.html`).
- The 58mm detailed template keeps its stacked layout and full set of figures, minus the rate-id.
- The tax-summary heading still branches by display mode in all affected templates.
- Stale header comments in both detailed thermal templates are corrected.
- New 80mm render test and the extended PHP content assertion pass; existing template suites still pass.
