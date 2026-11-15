# Codex job spec — make the refund tax-label test prove the parent-order lookup

## Goal

`test_build_refund_line_taxes_keep_charged_label_after_rate_renamed` in `tests/includes/Services/Test_Receipt_Data_Builder.php` must fail when the refund's **own** tax items are used instead of the parent order's. Today it passes either way.

The cause is in WooCommerce: `wc_get_container()->get( \Automattic\WooCommerce\Internal\Tax\TaxRateDataStore::class )` caches rate rows in a private `$rate_objects_cache` for the whole PHP process (`src/Internal/Tax/TaxRateDataStore.php:16-43`), and `\WC_Tax::_update_tax_rate()` does not clear that cache. The order was created earlier in the same test, so `wc_create_refund()` → `update_taxes()` gives the refund's tax item the **old** label ('US Tax', 10%). In production the refund is made in a later request, so it gets the renamed row ('City Tax', 0%). The fixture has to reproduce that.

## Stakes

Test-only change. No production code changes.

## In scope (the only files you may edit)

- `tests/includes/Services/Test_Receipt_Data_Builder.php`

## Out of scope (do not edit)

- `includes/Services/Receipt_Data_Builder.php` and every other file
- Every test other than `test_build_refund_line_taxes_keep_charged_label_after_rate_renamed`

## Context

- The test is in the block added just after `test_build_line_taxes_resolve_label_and_rate`. It creates a taxed order, renames the rate row to 'City Tax' at 0%, creates a refund with `wc_create_refund()`, builds the receipt, and asserts `refunds[0].lines[0].taxes[0]` has label 'US Tax' and rate 10.0.
- `wc_create_refund()` returns the `\WC_Order_Refund`.
- The refund's tax items: `$refund->get_items( 'tax' )` returns `\WC_Order_Item_Tax` items, with `set_label( string )`, `set_rate_percent( float )` and `save()`.

## Interfaces and constraints

- Keep the return value of `wc_create_refund( … )` in `$refund`.
- Directly after the refund is created, add a short comment and a loop:
  - The comment, in two or three lines, says: WooCommerce caches rate rows for the request, so in this test the refund's own tax item still has the old label. In production the refund is made in a later request and stores the renamed row, so the fixture writes that.
  - The loop goes over `$refund->get_items( 'tax' )`, calls `set_label( 'City Tax' )` and `set_rate_percent( 0.0 )` on each item, and saves it.
- Add one assertion before the build, in the Arrange section: the refund's first tax item, re-read with `wc_get_order( $refund->get_id() )`, has label 'City Tax' (`assertSame`). This proves the fixture took effect.
- Leave the Act and the two existing Assert lines unchanged.

## Do not

- Edit any production file, or any other test
- Use reflection on WooCommerce internals
- Create plan, notes or scratch files
- Commit or push
- Start, stop or destroy wp-env, or run local composer or phpunit

## Pre-authorised actions

- Edit the in-scope file
- Run the acceptance commands below (wp-env is already running from this worktree)

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 25

If you are about to exceed either, STOP and report why instead of continuing.
The budget is a stop rule, not a target.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

Run from the worktree root, foreground, one at a time:

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Receipt_Data_Builder.php --filter 'keep_charged_label_after_rate_renamed|fall_back_to_rate_row_without_stored_tax_item'
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Receipt_Data_Builder.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist tests/includes/Services/Test_Receipt_Data_Builder.php
```

- The first command runs 3 tests, all passing. The second runs 100 tests, all passing. phpcs reports no errors.
- The reviewer will then remove the parent-order lookup from `includes/` and expect this test to fail. You do not need to do that.

## Environment

- Network: yes, to reach the local wp-env containers (Docker)
- Writable outside the worktree: `~/.wp-env/wp-env-order-revision-allow-list-3526dcd1`
- wp-env is running for this worktree; do not start or stop it

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED / ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES). If the spec is ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
