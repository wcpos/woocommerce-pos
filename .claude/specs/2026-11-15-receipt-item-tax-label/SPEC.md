# Codex job spec — per-item receipt taxes print the label and rate the order was charged

## Goal

The receipt's per-item tax rows (`lines[].taxes`, `shipping[].taxes`, `fees[].taxes` and the same arrays in refunds) must show the tax label and rate percent **stored on the order**. Today they show the **current** rate row's values.

A tax rate row can be renamed in place after an order is placed, keeping its `tax_rate_id`. WooCommerce Tax 3.7.6 does this when TaxJar returns its components in a different order. An old order's receipt then prints the new jurisdiction's name and percent against the old amount, for example "City Tax 0%" for what was charged as county tax at 0.5%. The order-level tax summary (`get_tax_summary`, `includes/Services/Receipt_Data_Builder.php:1524-1545`) already reads the stored `WC_Order_Item_Tax` and is correct. This job makes the per-item rows match it.

## Stakes

Core plugin, receipt rendering. No money moves and nothing is written: the bug is a wrong label and percent on a printed or emailed receipt. The receipt data contract (key names and shapes) must not change. Treat performance as an accepted risk, because a receipt is built once per print.

## In scope (the only files you may edit)

- `includes/Services/Receipt_Data_Builder.php`
- `tests/includes/Services/Test_Receipt_Data_Builder.php`

## Out of scope (do not edit)

- `includes/Services/Receipt_Sections.php`, `includes/Templates/*`, `includes/Integrations/WooCommerce_Tax.php`
- Line 113 of `Receipt_Data_Builder.php` (the closure `breakdowns.tax_rates` name fallback): it stays as it is
- `get_tax_summary()` and `get_taxable_bases_by_rate_id()`
- Every other test file, `tests/includes/Helpers/*`, lockfiles, CI config, docs, the changelog

## Context

- `get_item_taxes( $item )` is at `includes/Services/Receipt_Data_Builder.php:1631-1680`. For each `$tax_rate_id => $tax_amount` in `$item->get_taxes()['total']` it currently:
  1. sets `$rate = null` and `$label = (string) $tax_rate_id`;
  2. if `\WC_Tax` exists, calls `\WC_Tax::_get_tax_rate( (int) $tax_rate_id, OBJECT )` inside a `try`, and takes `tax_rate` and `\WC_Tax::get_rate_label()` from that row;
  3. emits `array( 'code' => (string) $tax_rate_id, 'rate' => $rate, 'label' => $label, 'amount' => (float) $tax_amount )`.
- Its callers are line items (719, through `get_line_taxes`, 1617-1619), shipping (741), fees (758), refund line items (1224), refund fees (1247) and refund shipping (1271). The refund callers pass items of a `\WC_Order_Refund`.
- Every order item exposes `get_order()`, which returns its order (`\WC_Order` or `\WC_Order_Refund`) or `false`. A refund exposes `get_parent_id()`. An order exposes `get_items( 'tax' )`, which returns `\WC_Order_Item_Tax` items.
- `\WC_Order_Item_Tax` has `get_rate_id()` (int), `get_label( 'edit' )` (the raw stored label, `''` when empty; the `'view'` context substitutes "Tax", so do not use it) and `get_rate_percent( 'edit' )` (float, or `null` on tax items created before WooCommerce 3.7).
- **Why a refund looks at its parent first.** `wc_create_refund()` calls `$refund->update_taxes()` (WooCommerce `includes/wc-order-functions.php:670`). That creates the refund's own tax items through `WC_Order_Item_Tax::set_rate()`, which reads the **current** rate row. So a refund made after a rename carries the new label on its own tax items. The parent order's tax items hold what was charged.
- Test fixture: `create_taxed_order()` at `tests/includes/Services/Test_Receipt_Data_Builder.php:744-774` creates one rate ('US Tax', 10%, priority 1, shipping) and one $11 line, and returns the saved order. It does not return the rate ID; read it from the order's tax item: `array_values( $order->get_items( 'tax' ) )[0]->get_rate_id()`.
- Rename a rate row in place with `\WC_Tax::_update_tax_rate( $rate_id, array( 'tax_rate_name' => 'City Tax', 'tax_rate' => '0.0000' ) )`.
- Refund fixture: copy the `wc_create_refund()` call from `test_build_includes_refunds_block_with_line_items` (2866-2922). The refund's per-line taxes are at `$payload['refunds'][0]['lines'][0]['taxes']` after `$this->builder->build( wc_get_order( $order->get_id() ), 'live' )`.
- Existing test `test_build_line_taxes_resolve_label_and_rate` (around 2548-2560) asserts 'US Tax' and 10.0 for a fresh order. It must still pass unchanged.

## Interfaces and constraints

- Add one private method to `Receipt_Data_Builder`:

  ```php
  /**
   * Stored tax items by rate id for the order an item belongs to.
   * A refund's parent order wins, because a refund's own tax items are
   * re-read from the current rate table when the refund is created.
   *
   * @param object $item Order item.
   *
   * @return array<int, \WC_Order_Item_Tax>
   */
  private function get_charged_tax_items( $item ): array
  ```

  Behaviour:
  - If `$item` has no `get_order` method, or `get_order()` does not return a `\WC_Abstract_Order`, return `array()`.
  - Start with the order's own `get_items( 'tax' )`, keyed by `(int) get_rate_id()`.
  - If that order is a `\WC_Order_Refund` and `wc_get_order( $order->get_parent_id() )` returns a `\WC_Abstract_Order`, put the parent's tax items over the refund's, keyed the same way, so the parent wins for a rate ID both have.
  - Skip any item that is not a `\WC_Order_Item_Tax`.
- In `get_item_taxes()`:
  - Call `get_charged_tax_items( $item )` once, before the loop.
  - For each rate ID, if a stored tax item exists for `(int) $tax_rate_id`:
    - `label` is its `get_label( 'edit' )` when that is a non-empty string;
    - `rate` is `(float) get_rate_percent( 'edit' )` when that is not `null`.
  - Any field still unset falls back to today's rate-row lookup, then to today's fallback values (`(string) $tax_rate_id` and `null`). The result is per field: a stored label with a `null` stored percent takes the percent from the rate row.
  - Keep the output keys, their order and their types unchanged (`code`, `rate`, `label`, `amount`).
  - Update the docblock of `get_item_taxes()` to say that stored tax items come first, then `WC_Tax`, then the fallback.
- Keep the signatures of `get_item_taxes()` and `get_line_taxes()` unchanged.

## Do not

- Add caching, static properties or instance properties
- Change `get_tax_summary()`, line 113, or any receipt key name or shape
- Add a hook or filter
- Refactor or reformat code outside `get_item_taxes()` and the new method
- Change or delete existing tests
- Create plan, notes or scratch files
- Commit or push
- Start, stop or destroy wp-env, or run local composer or phpunit

## Pre-authorised actions

- Edit the two in-scope files
- Run the acceptance commands below (wp-env is already running from this worktree)

## Budget

- Max non-test lines changed: 40
- Max total lines changed: 160

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target: if the work is about to exceed it, stop and report the overrun and its reason under NOT DONE. Never strip tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Tests (write these first; run them on the unmodified `includes/` and keep the failing output)

Add to `Test_Receipt_Data_Builder`, with Arrange / Act / Assert, `assertSame` for strings, and `assertEqualsWithDelta( expected, actual, 0.001 )` for floats. Delete every rate you rename at the end of the test only if the existing tests in the file do so; otherwise rely on the suite's teardown, as `create_taxed_order()` callers do today.

1. `test_build_line_taxes_keep_charged_label_after_rate_renamed`
   - `create_taxed_order()`, read its rate ID, rename the rate row to 'City Tax' at 0%.
   - Build `wc_get_order( $order->get_id() )`.
   - Assert `lines[0].taxes[0].label` is `'US Tax'` and `rate` is `10.0`.
   - **Must fail on the base** (it prints 'City Tax' and 0.0).
2. `test_build_refund_line_taxes_keep_charged_label_after_rate_renamed`
   - `create_taxed_order()`, read its rate ID, rename the rate row to 'City Tax' at 0%, **then** create the refund as in `test_build_includes_refunds_block_with_line_items`.
   - Build `wc_get_order( $order->get_id() )`.
   - Assert `refunds[0].lines[0].taxes[0].label` is `'US Tax'` and `rate` is `10.0`.
   - **Must fail on the base.** It must also fail if the parent overlay is removed, because the refund's own tax item says 'City Tax'.
3. `test_build_line_taxes_fall_back_to_rate_row_without_stored_tax_item`
   - `create_taxed_order()`, read its rate ID, then `$order->remove_order_items( 'tax' )` and `$order->save()`, then rename the rate row to 'City Tax' at 0%.
   - Build `wc_get_order( $order->get_id() )`.
   - Assert `lines[0].taxes[0].label` is `'City Tax'` and `rate` is `0.0`.
   - This passes on the base. It pins that the rate-row fallback is kept.

## Acceptance criteria (runnable)

Run from the worktree root, foreground, one at a time:

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Receipt_Data_Builder.php --filter 'keep_charged_label_after_rate_renamed|fall_back_to_rate_row_without_stored_tax_item'
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Receipt_Data_Builder.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Services/Receipt_Data_Builder.php tests/includes/Services/Test_Receipt_Data_Builder.php
```

- The first command must run 3 tests, all passing.
- **Red first:** before editing `includes/`, run the first command and keep its output. Tests 1 and 2 must fail and test 3 must pass.
- phpcs must report no errors on the two files. If the test file already has warnings on the base, report them and do not fix lines you did not write.

## Environment

- Network: yes, to reach the local wp-env containers (Docker)
- Writable outside the worktree: `~/.wp-env/wp-env-order-revision-allow-list-3526dcd1`
- wp-env is running for this worktree; do not start or stop it

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED / ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES). Include the RED output (failure lines of tests 1 and 2) and the GREEN summaries. If the spec is ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
