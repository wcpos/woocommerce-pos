# Handoff: `cart_tax_display` presentation hint

**Status:** Not started — research + plan needed before implementation.
**Prerequisite:** PR `fix/receipt-template-tax-display` (the neutral-field swap) must land first. This doc assumes that PR's context.

## Why this exists

WCPOS receipt gallery templates need to render correctly for both tax-inclusive
stores (EU/UK/AU — prices shown with tax) and tax-exclusive stores (US/CA — tax
added at the register). Which one a store wants is set once via WooCommerce's
`woocommerce_tax_display_cart` option (`incl` / `excl`).

The prerequisite PR (`fix/receipt-template-tax-display`) already made the
**numbers** correct: the 9 "adaptive" gallery templates now use the neutral
money fields (`line_total`, `totals.subtotal`, fee/shipping/discount `total`,
etc.), which `Receipt_Data_Builder` resolves to incl or excl per the store
setting. An inclusive store sees inclusive figures; an exclusive store sees
exclusive figures; the grand total stays gross in both.

What's still missing is the **wording / layout** signal. With tax-exclusive
display, the tax line is conventionally an *additive* line (`Subtotal / Tax /
Total`). With tax-inclusive display, it's conventionally an *informational*
memo (`Total — includes $X VAT`). Templates currently can't tell which mode
they're in, so they use one neutral phrasing for both. This follow-up exposes
that signal as a presentation hint so templates can adapt the phrasing.

## The task

Add a new `presentation_hints` key — proposed name **`cart_tax_display`** with
value `'incl'` or `'excl'` — that surfaces the store's
`woocommerce_tax_display_cart` resolution to receipt templates.

This is **polish, not correctness** — the prerequisite PR already made the
figures right. Keep the change small and mechanical; the template-side wording
changes can be a separate step (see "Phase 2" below).

## Key facts (verified against `main` @ 7c0fbd44)

1. **`presentation_hints` is the single hints bag** — 12 keys today. It is
   produced by **two builders that must stay in sync**:
   - `includes/Services/Receipt_Data_Builder.php` → `build_presentation_hints()`
     (~line 543) — for real orders.
   - `includes/Services/Preview_Receipt_Builder.php` → `build_presentation_hints()`
     (~line 1006) — for template-editor previews.
   If a hint is added to one but not the other, printed receipts and editor
   previews silently diverge.

2. **The incl/excl value is already computed but thrown away.**
   `Receipt_Data_Builder::build()` resolves `$display_incl` (~line 81) from
   `woocommerce_tax_display_cart`, honouring a per-POS-store
   `get_tax_display_cart()` override. It's used to pick the neutral money-field
   values, then discarded — never surfaced as a hint. Exposing it is a
   one-liner there: `$display_incl ? 'incl' : 'excl'`.

3. **`Preview_Receipt_Builder` needs the equivalent resolution.** Its
   `build_presentation_hints()` already receives `$prices_include_tax` but does
   **not** appear to resolve `woocommerce_tax_display_cart`. Confirm whether the
   preview builder resolves a `$display_incl` equivalent anywhere for its own
   line-item math — if it doesn't, that may be a pre-existing preview/production
   divergence worth flagging separately.

4. **Name it distinctly from `display_tax`.** There is already a `display_tax`
   hint, but it is `'hidden' | 'single' | 'itemized'` from
   `woocommerce_tax_total_display` — a *different* WooCommerce setting (tax
   breakdown granularity, not incl/excl). Do not overload it. `cart_tax_display`
   keeps the WooCommerce option name recognisable.

5. **`presentation_hints` is an internal section.** It is excluded from
   `Receipt_Data_Schema::get_field_tree()` and its `properties` are unset from
   the JSON schema (`Receipt_Data_Schema.php` ~line 1186). A new hint will work
   in templates (`{{presentation_hints.cart_tax_display}}`) without any schema
   change — but it will not appear in the template editor's field picker unless
   you also decide to surface it.

## Scope — Phase 1 (this PR)

- Add `cart_tax_display` to **both** `build_presentation_hints()` methods.
- `Receipt_Data_Builder`: expose the existing `$display_incl` (note: `$display_incl`
  is computed *after* `build_presentation_hints()` is currently called — either
  move the resolution earlier or compute it inside the hint builder).
- `Preview_Receipt_Builder`: add the matching `woocommerce_tax_display_cart`
  resolution (with the same per-store override precedence the real builder uses).
- Add a **sync test** asserting both builders return the same `presentation_hints`
  key set, so future hints can't be added to only one. Existing test files:
  `tests/includes/Services/Test_Receipt_Data_Builder.php`,
  `tests/includes/Services/Test_Preview_Receipt_Builder.php`.
- Add coverage that `cart_tax_display` is `'incl'` / `'excl'` for the two store
  configurations (mirror the `create_taxed_order()` helper pattern in
  `Test_Receipt_Data_Builder.php`).

## Scope — Phase 2 (template wording — optional follow-up, consider /brainstorm)

Once the hint exists, the adaptive gallery templates can switch the tax line's
*wording* with a single mustache conditional, e.g.:

- `{{#presentation_hints…}}` exclusive → `Tax` as an additive totals line.
- inclusive → an "includes {{i18n.tax}} {{totals.tax_total_display}}" memo.

This is a template-design decision, not a mechanical change:
- It needs new i18n label(s) (e.g. an "includes {tax}" phrasing) — see the
  `feedback_localize_dates_and_strings` memory rule.
- It must respect the receipt B&W-printing rules (`feedback_receipts_print_bw`).
- Mustache is logicless — the conditional can only switch between two static
  blocks; keep the data/phrasing decisions in the hint + i18n layer.
- Worth running `/brainstorm` before touching templates.

## Open questions to resolve during research

- Does `Preview_Receipt_Builder` resolve `woocommerce_tax_display_cart` at all
  today? If its line-item math already diverges from `Receipt_Data_Builder`,
  flag that as a separate bug rather than papering over it.
- Should `cart_tax_display` be surfaced in `get_field_tree()` so template
  authors see it in the editor field picker? Tradeoff: discoverability vs.
  `presentation_hints` being deliberately internal. Default: leave internal
  for Phase 1; revisit if Phase 2 ships template-facing conditionals.
- Per-POS-store override: confirm the store object's `get_tax_display_cart()`
  getter exists and the fallback chain (store → `woocommerce_tax_display_cart`
  option → `'excl'` default) matches what `Receipt_Data_Builder::build()` line
  ~81 already does.

## Reference: the prerequisite PR

`fix/receipt-template-tax-display` changed only gallery template files (plus one
new test file). Relevant context it established:

- The 9 adaptive templates (standard, standard-rtl, minimal, narrow, invoice,
  quote, thermal-simple-58mm/80mm/80mm-rtl) now use neutral `_display` money
  fields for line items / subtotal / fees / shipping / discounts.
- **Grand-total footgun:** the neutral `totals.total` resolves to `total_excl`
  (pre-tax) for an exclusive store — it is *not* a valid headline total.
  Templates must use `totals.total_incl` for the grand total. All adaptive
  templates already do.
- The detailed family (`detailed-receipt.html`, `thermal-detailed-58mm/80mm`)
  is a formal tax invoice — it always itemises tax (tax-exclusive line items,
  explicit tax breakdown). It does **not** use the neutral fields and should
  **not** consume `cart_tax_display`.
