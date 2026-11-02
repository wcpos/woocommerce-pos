# Tests: stock on a reopened sale with decimal quantities and a Pending gateway status

Lane: `next` (2.0). Branch `test/reopen-stock-delta-decimal-pending`, worktree
`/Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/test-reopen-stock-delta`.
Tests and a changelog line only. **No production code changes.** If a test you write
fails against production code, stop and report the failure with the output; do not
change `includes/`.

## Why

A fabric shop (decimal quantities, POS Card gateway whose order status is set to
Pending) takes a card payment, later re-opens the sale from the orders screen, changes
a 6.3 m line to 5.8 m, adds a line, removes a line, and pays again. On `next`,
`Order_Writer::adjust_line_stock()` (`includes/API/V2/Writers/Order_Writer.php:521-550`,
commit 330aa7b2, ADR 0040 rule 4b) moves stock by the delta at edit time when the order
already carries `_order_stock_reduced`. The existing pins
(`tests/includes/Sync/Test_Rest_Dispatch_Money_Model.php:619-712`) use integer
quantities on a sale completed into `processing`. Nothing covers decimals, and nothing
covers a sale completed into a configured `pending` status, which takes a different
WooCommerce path: `wc_maybe_increase_stock_levels` is hooked on
`woocommerce_order_status_pending`, so the second `payment_complete()` restores every
line from `_reduced_stock` and reduces again at the current quantities.

## Where

Add the tests to `tests/includes/Sync/Test_Rest_Dispatch_Money_Model.php`, directly
after `test_line_change_on_an_unpaid_open_cart_leaves_stock_alone()` (line ~712). Reuse
the file's helpers: `stock_product()`, `stock_of()`, `paid_sale_of_two()`, `first_item()`,
`quantity_edit()`, `push_envelope()`, `order_revision()`, `push_status()`, `uuid_meta()`,
`money_command()`, `cash_payment()`, `make_order()`. Read lines 40-130, 352-380, 596-712
and 1134-1175 before writing anything; the last range is the template for a sale
completed into a configured status, reopened, edited and closed.

Decimal quantities: `WCPOS_REST_Unit_Test_Case::setup_decimal_quantity_tests()`
(`tests/includes/API/WCPOS_REST_Unit_Test_Case.php:303-314`) installs the
`woocommerce_pos_general_settings` filter and swaps `woocommerce_stock_amount` from
`intval` to `floatval`. It does not unwind. Follow
`tests/includes/API/WCPOS_REST_Decimal_Quantity_Unit_Test_Case.php:21-30` and restore
`intval` (and remove the settings filter) in a `finally` or in `tearDown()`, or every
later test in the class sees floats. Check that `Sync_REST_Store_Test_Case` reaches that
helper; if not, inline the two filter calls. `stock_of()` casts to int; add a
`decimal_stock_of()` returning `(float) wc_get_product(...)->get_stock_quantity()` and
compare with `assertEqualsWithDelta( expected, actual, 0.000001 )`. Compare
`_reduced_stock` with `assertSame( '5.8', (string) ... )`; if the stored string carries
trailing zeros, assert on `(float)` with a delta instead and say so in the PR body.

## Tests to write (names are binding; Arrange / Act / Assert; `( expected, actual )`)

All decimal tests start from a product stocked at 10 managed with `manage_stock`, a
sale of **6.3** units completed into `processing` via `payment_complete()` (the
`paid_order()` path), so after Arrange: stock 3.7, `_reduced_stock` 6.3,
`_order_stock_reduced` true. Then the push reopens (`'status' => 'pos-open'`) and edits
in one write, as the existing integer tests do.

1. `test_lowering_a_decimal_quantity_on_a_reopened_paid_order_returns_the_difference`
   6.3 → 5.8. After the push: stock 4.2, `_reduced_stock` 5.8, status `pos-open`.
   Then close the sale (`money_command( '/wcpos/v2/orders/{id}/close', $id )` after
   recording cash covering the total, as the template at 1134 does) and assert stock
   is still 4.2 and `_reduced_stock` still 5.8: the completion does not reduce again.
2. `test_raising_a_decimal_quantity_on_a_reopened_paid_order_reduces_the_difference`
   6.3 → 6.8. After the push: stock 3.2, `_reduced_stock` 6.8. After close: unchanged.
3. `test_adding_a_decimal_line_to_a_reopened_paid_order_reduces_that_product`
   Second product stocked at 5; add a line of 1.5. After the push: first product 3.7
   (untouched), second 3.5, the new line's `_reduced_stock` 1.5. After close: unchanged.
4. `test_removing_a_decimal_line_from_a_reopened_paid_order_restores_its_stock`
   Remove the 6.3 line (`'product_id' => null` on its id, as test 649 does). After the
   push: stock 10, no line items. Do not close an empty order; end the test there.
5. `test_a_sale_completed_into_a_configured_pending_status_reopened_edited_and_closed_ends_at_the_final_quantities`
   Integer quantities are fine here. Arrange exactly as the on-hold template at 1134,
   but `'order_status' => 'wc-pending'` for `pos_cash`, product stocked at 10, sale of
   2: assert status `pending`, stock 8, `_reduced_stock` 2, `_order_stock_reduced`
   true (`wc_get_order( $id )->get_data_store()->get_stock_reduced( $id )`). Act:
   `push_status( $id, 'pos-open' )`, then `quantity_edit( $id, -1 )` (2 → 1), then add
   a second product line of 1 in the same or a following push, then record cash for the
   new total and close. Assert after the edit: first product 9, `_reduced_stock` 1,
   second product 4, its `_reduced_stock` 1. Assert after close: status `pending`
   (the configured status), first product 9, second 4, both `_reduced_stock` values
   unchanged, and `get_stock_reduced` true. If the close answers something other than
   200, print the body in the assertion message as the file does.
6. `test_an_unpaid_open_cart_edited_then_closed_reduces_once_at_the_final_quantities`
   `make_order( 'pos-open', [[product, 2]] )` on a product stocked at 10, no payment.
   Push `quantity_edit( $id, 1 )` (2 → 3): stock still 10, no `_reduced_stock`. Record
   cash for the total and close: stock 7, `_reduced_stock` 3, `get_stock_reduced` true.

A "record cash" step is the `/wcpos/v2/orders/{id}/payments` POST at lines 1150-1153
with `cash_payment( <order total as string> )`; read the order's total after the edit
(`wc_get_order( $id )->get_total()`) rather than hard-coding it. If the close completes
the sale on its own once rows cover the total, follow what the template does.

## Changelog line (exact text, add it yourself)

In `CHANGELOG.md`, under `## Unreleased`, add as a new bullet after the existing
"Changed: POS sales are recorded once..." bullet:

```
- Changed: on a re-opened sale that had already reduced stock, every line change made in the cart moves stock straight away: adding a line or raising a quantity reduces the product's stock, removing a line or lowering a quantity returns it, with decimal quantities included. Completing the sale again does not reduce stock a second time. Previously a re-opened sale kept its original deduction until the next payment, and a line added while it was open was never deducted if the sale was completed by a status change. (ADR 0040 rule 4b)
```

## Running the tests

The worktree's wp-env stack is already up (ports 8894/8895, plugin mapped to
`wp-content/plugins/woocommerce-pos`). Always `cd` into the worktree in the same
command, invoke the local binary, and run ONE phpunit at a time, never in parallel:

```
cd /Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/test-reopen-stock-delta && ./node_modules/.bin/wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --filter 'Test_Rest_Dispatch_Money_Model::test_(lowering_a_decimal|raising_a_decimal|adding_a_decimal|removing_a_decimal|a_sale_completed_into_a_configured_pending|an_unpaid_open_cart_edited)'
```

Then run the whole class once (`--filter Test_Rest_Dispatch_Money_Model`) to prove the
decimal filter did not leak. Do not run `pnpm run test:unit:php` (blocked by the worker-cap
hook) and never host `composer`/`phpunit`. `.phpunit.xml.dist` ignores a bare file path,
so `--filter` is the only way to subset.

Lint the test file: `cd <worktree> && ./node_modules/.bin/wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=.phpcs.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Money_Model.php` (if `vendor/bin/phpcs` is missing in the copied vendor, say so and skip; CI runs it).

## Deliverable

Commit on the branch (tests in one commit, changelog in a second), do not push, do not
open a PR. Report: each test's name and result with the phpunit summary line, the
whole-class run's summary line, phpcs result, and anything a test revealed about the
production code (a failing assertion is a finding, not something to paper over).
