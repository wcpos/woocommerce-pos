# Codex job spec — #2853 follow-up: measure the writer's stock call, not WooCommerce's save

## Goal

Two tests in `tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php` count `did_action( 'woocommerce_product_set_stock' )` to prove the push writer made no stock call. WooCommerce's own product save also fires that action whenever the stock prop changes. Turning `manage_stock` off makes the wc/v3 controller clear the stock to `''`, so `test_manage_stock_turned_off_in_same_push_writes_no_stock` fails (count 2, expected 1). It fails on base code and on the fix alike, so this is a test defect.

Count calls to the filter `woocommerce_update_product_stock_query` instead. In WooCommerce 11.3.1 it runs only inside the data store's SQL stock update (`class-wc-product-data-store-cpt.php:1923`, used by `wc_update_product_stock()`), and never in an ordinary save.

## Stakes

Test-only change. Production code is not touched.

## In scope (the only files you may edit)

- `tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php`

## Out of scope (do not edit)

- `includes/API/V2/Writers/Product_Writer.php`
- `includes/API/V2/Writers/Variation_Writer.php`
- `includes/API/V2/Writers/Collection_Writer_Resolver.php`
- Every other file

## Context

The previous job's output is committed on this branch. Leave the other files exactly as they are.

## Interfaces and constraints

- Add `private $stock_queries = 0;` with a one-line `@var` docblock, plus a public method `count_stock_query( $sql )` that does `++$this->stock_queries;` and `return $sql;`.
- `tearDown()` also does `remove_filter( 'woocommerce_update_product_stock_query', array( $this, 'count_stock_query' ) );`.
- In `test_zero_delta_writes_no_stock` and `test_manage_stock_turned_off_in_same_push_writes_no_stock`, replace the `$before = did_action( ... )` and `assertSame( $before, did_action( ... ) )` pair. Instead, `add_filter( 'woocommerce_update_product_stock_query', array( $this, 'count_stock_query' ) );` after the base revision is read, and assert `$this->assertSame( 0, $this->stock_queries );` after the push.
- In the manage-off test, also assert `$this->assertNull( $this->stock( $id ) );`. That is WooCommerce's own result of turning stock management off, unchanged from today.
- Keep the other assertions in both tests, and every other test, unchanged.

## Do not

- Edit any other test or file
- Commit or push
- Create plan, notes or scratch files

## Pre-authorised actions

- Edit the in-scope file
- Run the acceptance command

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 30

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

```sh
php -l tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php
```

## Reviewer reruns (not for Codex)

```sh
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php   # wp-env
```

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
