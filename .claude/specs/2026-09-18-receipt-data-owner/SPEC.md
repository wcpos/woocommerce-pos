# Receipt Sections: one owner for the receipt contract's row shapes

Candidate 9 of the 2026-09-17 architecture review, scoped down with Paul on 2026-09-18 after the survey in `INVESTIGATION.md` (the Codex report is summarised below; you do not need to redo it). Proceed without asking questions. Stakes tier: **Medium** for the shaping move (pure refactor, output must be value-identical), **High** for the one behaviour change (the preview filter), which is small and specified exactly.

## Why

`Receipt_Data_Builder` (live orders) and `Preview_Receipt_Builder` (sample data for the template editor and gallery) each declare the receipt contract's row shapes a second time: the `lines[]` row with its `<key>`/`<key>_incl`/`<key>_excl` triples, the `fees[]`, `shipping[]`, `discounts[]`, `payments[]` and `tax_summary[]` rows, the `totals` block with its savings-completeness rule, the tax-ID label attachment, and the variation-attribute pairs. Sixteen commits touched two or three of the builder/preview/schema files together; the preview hardcodes `total_saved_complete => true`, `net_total => 0.0`, `taxes => array()` and the like where the live builder derives them. The 411-line sync test exists partly to notice when the two builders disagree on keys.

The two builders do **different arithmetic by design** (live reads stored WooCommerce totals and recorded POS prices; preview manufactures a self-consistent sample order), so the seam is not "one builder". The seam is: **the contract's row shapes and aggregate rules are declared once**, and each builder supplies priced values.

## What to build

### 1. `includes/Services/Receipt_Sections.php` (new, final class, static methods, no state)

Every method is pure apart from the two that read WordPress/WooCommerce (labels, attributes). Each returns an array in the **live builder's current key order**; the preview builder's key order changes to match (key order is not part of the contract; the sync test compares sorted keys).

Basis pairs below are `array{incl: ?float, excl: ?float}`. `$display_incl` selects which basis fills the bare key.

```php
/** One lines[] row. $line carries the identity and the per-basis amounts. */
public static function line( array $line, bool $display_incl ): array
```
`$line` keys: `key` (string), `sku`, `name`, `qty` (float), `qty_refunded` (float), `total_refunded` (float), `taxes` (array), `meta` (array), `attributes` (array), and the basis pairs `unit_subtotal`, `unit_price`, `line_subtotal`, `discounts`, `line_total` (floats, never null), plus the price-convenience pairs `regular_price`, `selling_price`, `unit_savings`, `line_regular_total`, `line_selling_total`, `line_savings` (nullable per basis) and `savings_in_discounts` (bool). Output: exactly the keys the live builder emits today for a line, in its order: `key, sku, name, qty, qty_refunded, unit_subtotal, unit_subtotal_incl, unit_subtotal_excl, unit_price, …, line_total_excl, total_refunded, taxes, meta, attributes, regular_price, regular_price_incl, regular_price_excl, selling_price, …, line_savings_excl, savings_in_discounts`. Null stays null in the triple (the live builder emits null regular prices when no POS price data exists).

```php
public static function fee( string $label, array $total, array $taxes, array $meta, bool $display_incl ): array
public static function shipping( string $label, string $method_id, array $total, array $taxes, array $meta, bool $display_incl ): array
public static function discount( string $label, string $code, string $discount_type, array $total, bool $display_incl ): array
public static function payment( string $method_id, string $method_title, float $amount, string $transaction_id, float $tendered, float $change ): array
public static function tax_summary_entry( string $code, float $rate, string $label, bool $compound, ?float $taxable_excl, float $tax_amount ): array
```
`tax_summary_entry` owns the two rules both builders repeat: `rate` is `null` when not `> 0`; `taxable_amount_incl` is `taxable_excl + tax_amount`, or `null` when the base is unknown.

```php
/**
 * The totals block. $lines are shaped rows from line(); $amounts are the order-level figures.
 */
public static function totals( array $lines, array $amounts, bool $display_incl ): array
```
`$amounts` keys: `discount_total` (basis pair, floats), `tax_total`, `total` (tax-inclusive grand total), `paid_total`, `change_total`, `refund_total`. The method owns: `subtotal_*` as the sums of `line_subtotal_*`; `total_qty`/`line_count`; `total_excl = total − tax_total`; `net_total` (`refund_total > 0 ? max(0, total − refund_total) : 0.0`, keep the comment about the detailed-receipt section guard); and the whole savings-completeness rule currently at `Receipt_Data_Builder::build()` lines 166–217 (`sale_savings_total_*`, `total_saved_*`, `total_saved_complete`), moved verbatim with its comment about legacy POS lines. Output keys and order: exactly today's `totals` block.

```php
/** Attach display labels to TaxId[] rows. Precedence: explicit label → `<scope>_tax_id_label_<type>` → `<scope>_tax_id_label_other`. */
public static function label_tax_ids( array $tax_ids, string $scope, string $locale = '' ): array
/** Attribute pairs for a variation: one {key,value} per non-empty variation attribute, taxonomy label and term name resolved, tags stripped. */
public static function variation_attribute_pairs( \WC_Product_Variation $variation ): array
```

### 2. `Receipt_Data_Builder` uses it

- `build()`: the line row, fee/shipping/discount/payment rows, `$tax_summary` entries and the `$totals` block are produced through `Receipt_Sections`. The builder keeps all of its reading and arithmetic (unit price division, `get_line_price_convenience_fields()`, `get_taxable_bases_by_rate_id()`, refunds, coupons, meta pairs). `get_line_price_convenience_fields()` now returns the six basis pairs plus `savings_in_discounts` rather than the 19 flat keys; the flat keys are `Receipt_Sections::line()`'s job.
- `with_customer_tax_id_labels()` / `with_tax_id_labels()` are removed; `Tax_Id_Reader` callers use `Receipt_Sections::label_tax_ids( $tax_ids, 'customer', $locale )`. Check whether `Receipt_Store_Resolver` or anything else attaches store-scope labels the same way (grep `_tax_id_label_`); if so, route it through the same method.
- `get_product_attribute_pairs()`: the variation branch calls `Receipt_Sections::variation_attribute_pairs()`; the non-variation branch stays where it is.
- Fix the `$mode` docblock on `build()`: it is not "reserved"; it is passed to the `woocommerce_pos_receipt_data` filter unchanged. Values in use: `live`, `fiscal`, `preview`. Fix the filter docblock's `@param string $mode` the same way and add the sentence in §4.

### 3. `Preview_Receipt_Builder` uses it

- Compute the per-product bases first (unit incl/excl, regular, savings, then the proportional discount distribution with its remainder correction, then line totals and unit prices), **on plain arrays of basis pairs**, then shape each row with `Receipt_Sections::line()`. The distribution loop must not reach into shaped rows any more.
- Fee, shipping, discount and payment rows, the tax-summary entry and the totals block come from `Receipt_Sections`. The preview's `$amounts` for `totals()`: its computed discount pair, `total_tax`, `total_incl`, `paid_total = total_incl`, `change_total`, `refund_total = 0.0`. This makes the preview's `total_saved_*`, `total_saved_complete`, `net_total`, `subtotal_*`, `total_qty` and `line_count` derived rather than hardcoded. They must come out with the **same values as today** (they do: every preview line carries numeric savings, `savings_in_discounts` false and `line_subtotal_* == line_selling_total_*`, so the completeness rule yields `discount + Σ line_savings` and `true`).
- `sample_tax_ids_for_country()`: keep the sample table; replace its label loop with `Receipt_Sections::label_tax_ids( $tax_ids, 'customer', $locale )`.
- `get_products()`: the variation attribute loop becomes `Receipt_Sections::variation_attribute_pairs( $variation )`.
- Sample content (customers, fallback products, labels, timestamps, fiscal sample, tendered rounding) is untouched.

### 4. Behaviour change: sample previews pass through `woocommerce_pos_receipt_data`

Today only the live builder applies the filter, so a key a plugin adds (the ADR 0039 extension path) never shows in the template editor's sample preview or the gallery. Paul decided on 2026-09-18 that it should.

- At the end of `Preview_Receipt_Builder::build()`, after `Receipt_Payload_Assembler::assemble()`, apply `woocommerce_pos_receipt_data` with the same three arguments as the live builder: the data, an order, and the mode `'preview'`. The order argument is an **unsaved** `new \WC_Order()` (id 0, nothing persisted): plugins written against the live filter call order methods and must not fatal on a null. Cast the result `(array)` as the live builder does.
- Do not apply it in `Receipt_Preview_Fixture_Loader`: the loader builds on the preview builder's output, so the filter has already run once, and gallery overrides land on top of whatever plugins added.
- Add one sentence to the live builder's hook docblock (which is where `@hook` documentation lives, so the developer docs pick it up): "Sample previews for the template editor and gallery also run through this filter, with mode `preview` and an unsaved order whose id is 0; a plugin that needs a persisted order should return `$data` unchanged when `$order->get_id()` is 0."
- The three sample-preview consumers (`Single_Template::get_sample_receipt_data()`, `Templates_Controller::preview_item()`, the fixture loader) need no change.

### 5. Delete the dead mock

`Receipt_Data_Schema::get_mock_receipt_data()` (line 1599 to end of method) is called only by its own test; its docblock's claim that it feeds the template editor preview is false (the editor uses `Preview_Receipt_Builder`). Delete the method and `Test_Receipt_Data_Schema::test_get_mock_receipt_data_includes_new_store_fields()`. If a `use` or helper becomes unused as a result, remove it. Nothing in Pro or the app calls it (checked).

## Tests

- New `tests/includes/Services/Test_Receipt_Sections.php` (extends `WC_Unit_Test_Case` or `WP_UnitTestCase`, no REST): about ten cases. `line()` fills bare keys from the incl basis when `$display_incl` and from excl otherwise, keeps null regular prices null, and emits the live key order (assert `array_keys` against the literal list); `totals()` sums subtotals and quantities, sets `total_saved_*` null and `total_saved_complete` false when any line lacks numeric `line_savings_*`, excludes `savings_in_discounts` lines from `total_saved` but not from `sale_savings_total`, treats a line whose subtotal differs from its selling total as incomplete, computes `net_total` only after a refund; `tax_summary_entry()` nulls a zero rate and a missing base; `label_tax_ids()` precedence (explicit label, typed key, other fallback); `variation_attribute_pairs()` on a real variation with one taxonomy and one custom attribute. `assertSame`, expected first.
- `Test_Preview_Receipt_Builder`: add `test_build_applies_receipt_data_filter_with_preview_mode` mirroring the live builder's filter test at `Test_Receipt_Data_Builder.php` ~1860–1895: the filter sees mode `preview` and an order with id `0`, and a key it adds to `discounts[0]` survives in the payload. Existing preview tests must pass unchanged (values, not key order).
- `Test_Receipt_Data_Builder`, `Test_Receipt_Builders_Contract_Sync`, `Test_Receipt_Data_Schema` (minus the deleted case), `Test_Receipt_Preview_Fixture_Loader`, `Test_Receipt_Template_Tax_Display`, `Test_Receipt_Renderers`, `Test_Templates`, `Test_Templates_Controller`: unchanged and green. Do not loosen any assertion. If a test compares key order of a preview row against a literal, that literal changes to the live order; say so in the report.
- The sync test stays. Do not touch the field tree, the JSON schema, the gallery fixtures or `packages/receipt-schema`.

## Constraints

- Budget: the new class ≤ 330 lines including docblocks; `Receipt_Data_Builder` and `Preview_Receipt_Builder` together lose at least 300 lines. Net production change between −60 and −160. If you find yourself above +0 net, stop and report why rather than trimming docblocks.
- No new options, env vars, filters or constants beyond the class. No `Logger` calls (nothing can fail here). No change to any public method signature on the two builders, `Receipt_Payload_Assembler`, `Receipt_Data_Schema` (other than the deleted mock) or `Receipt_I18n_Labels`.
- Preserve every existing code comment that explains a rule (savings overlap, net_total guard, precision tolerance) at its new home.
- CONTEXT.md gains the term (already written by the orchestrator; do not edit it).
- phpcs: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <changed files>` from this worktree must be clean. You cannot run PHPUnit (no Docker in the sandbox); the orchestrator runs the suites and a value-level golden diff of both payloads. Reason carefully about numeric identity instead: the preview must produce the same floats it does today, so keep its rounding calls where they are and only move the key mapping.
- Commit on this branch in two commits: (1) `refactor(receipts): declare the receipt contract's row shapes once in Receipt_Sections` with everything in §1–3 and §5 and the new tests; (2) `feat(receipts): run sample previews through woocommerce_pos_receipt_data` for §4 and its test. Include this spec directory in the first commit.
- Report: files changed with line deltas, the list of tests added/changed, anything you could not make value-identical, and the phpcs result.
