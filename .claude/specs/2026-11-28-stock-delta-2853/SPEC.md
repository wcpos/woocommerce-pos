# Codex job spec — product push writes stock as a delta (#2853)

## Goal

A `wcpos/v2/push/products` or `push/variations` update that carries `stock_quantity` must no longer overwrite a sale that lands while the push is applied. Today the forwarded `wc/v3` PUT sets `_stock` absolutely, so a sale between the push's revision compare and WooCommerce's save is lost (issue #2853: stock 10, push 12, sale of 3 in the window gives 12; it must give 9).

After this job, the push forwards its payload without `stock_quantity`. Then, last, it applies the difference between the pushed number and S0 through `wc_update_product_stock()`, WooCommerce's own relative, SQL-atomic stock path. S0 is the `stock_quantity` of the document the push compared its `baseRevision` against.

## Stakes

High tier: stock on the push lane. A wrong stock is lost inventory with no error. The failure classes that matter are a lost sale, a doubled delta, a stock write that the ack claims but that did not happen, and any change to pushes that carry no `stock_quantity`. Pushes without `stock_quantity` must behave byte-for-byte as today.

Combination table (each row names its test in the new test file):

| Case | Expected | Test |
|---|---|---|
| Product, stock 10, push 12, sale of 3 in the window | 200, stock 9 | `test_product_stock_push_with_sale_in_window_keeps_the_sale` |
| Variation, the same | 200, stock 9 | `test_variation_stock_push_with_sale_in_window_keeps_the_sale` |
| Sale before the push's read (control) | 409, stock 7 | `test_sale_before_read_is_refused_with_409` |
| Price-only push, sale in the window | 200, stock 7, price saved | `test_price_only_push_leaves_stock_alone` |
| Increase 10 → 12, no sale | 200, stock 12 | `test_stock_increase_is_applied` |
| Decrease 10 → 8, no sale | 200, stock 8 | `test_stock_decrease_is_applied` |
| Delta 0 (push 10 on 10, with a price change) | 200, no stock write (`woocommerce_product_set_stock` count unchanged), price saved | `test_zero_delta_writes_no_stock` |
| `manage_stock` turned on in the same push (was off) | 200, stock is the pushed number | `test_manage_stock_turned_on_in_same_push_sets_stock` |
| `manage_stock` turned off in the same push | 200, no stock write by the writer, product not managing stock | `test_manage_stock_turned_off_in_same_push_writes_no_stock` |
| Ack after a stock push | ack `document.stock_quantity` and `currentRevision` equal a fresh read | `test_ack_stock_and_revision_equal_a_fresh_read` |
| Stock call fails after the forward | error 500 `woo_rxdb_sync_stock_write_failed`, other fields saved, stock unchanged; a re-push on the old base gets 409 whose `current.stock_quantity` is the live stock | `test_stock_write_failure_returns_error_and_no_ack` |
| Decimal stock (the plugin's decimal quantity setting) | round-trips | existing `tests/includes/Sync/Test_Decimal_Quantity_Write_Contract.php` (must stay green, not edited) |

## In scope (the only files you may edit)

- `includes/API/V2/Writers/Product_Writer.php` (new)
- `includes/API/V2/Writers/Variation_Writer.php`
- `includes/API/V2/Writers/Collection_Writer_Resolver.php`
- `tests/includes/Sync/Writers/Test_Collection_Writer_Resolver.php` (line 20 only: expect `Product_Writer` for `post_type` `product`, plus its `use` line)
- `tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php` (new)

## Out of scope (do not edit)

- `includes/API/V2/Write_Controller.php`
- `includes/API/V2/Writers/Null_Writer.php`, `Order_Writer.php`, `Customer_Writer.php`
- `includes/Interfaces/Collection_Writer_Interface.php`
- `includes/Sync/*` (serializers, revisions, mutation store)
- Every existing test file other than the resolver test line named above. These existing suites assert stock pushes and must stay green unedited: `tests/includes/API/V2/Test_Variations_Bare_Records.php`, `tests/includes/Sync/Test_Decimal_Quantity_Write_Contract.php`, `tests/includes/Sync/Test_Rest_Dispatch_Stock_Validation.php`, `tests/includes/Sync/Test_Rest_Dispatch_Write_Contract.php`, `tests/includes/Sync/Writers/Test_Variation_Writer.php`, `tests/includes/Sync/Writers/Test_Null_Writer.php`.
- `readme.txt`, `CHANGELOG*`, docs, composer and pnpm files, CI config

## Context

Push update lifecycle, `includes/API/V2/Write_Controller.php::apply_update()` (lines 452-531). One `$writer` instance is used for the whole update:

1. `$prepared = $writer->prepare_update( $meta, $id, $m['payload'], ... )` returns `array( method, route, payload, context )`.
2. `$failure = $writer->with_write_lock( $id, $m['payload'], $work )`. Inside `$work`: the controller reads `$current` (the bare document), compares `baseRevision` (and returns a 409 response if stale), then `$writer->forward( $prepared, ... )` (WP_Error or a ≥400 response is returned as the failure). Then `$writer->after_update( $id, $payload, $current_bare, $response_data, $context )` runs (void), and `$work` returns `null`.
3. A non-null `$failure` is returned to the client. `push()` (lines 183-205) releases the mutation reservation for any WP_Error or ≥400 response, so the client may retry. The controller then does not persist, finalize or ack.
4. On `null`: `persist_uuid`, `checkpoint_and_finalize`, then the ack `envelope_document( document_for(...) )`, a fresh re-read. So a stock write done before `with_write_lock` returns is in the ack's document and `currentRevision`.

`$current_bare['stock_quantity']` is S0. For products, it is from a dispatched `wc/v3` GET. For variations, it is from `Product_Serializer::bare_for_revision()` (`Variation_Writer::document()`). Both hold `stock_quantity` (int, float or null) and `manage_stock`. `stock_quantity` is part of the revision hash, so a sale before the compare already gives a 409.

WooCommerce 11.3.1:

- `wc_update_product_stock( $product, $qty, $op = 'set', $updating = false )` (`includes/wc-stock-functions.php:30`) acts only when `$product->managing_stock()`. It fires `woocommerce_{product|variation}_before_set_stock`, runs the SQL relative update (`increase`/`decrease`) or set, re-reads, `save()`s, then fires `woocommerce_{product|variation}_set_stock`. It returns the new stock, or `false` when there is no product.
- The products controller sets stock only when the site option `woocommerce_manage_stock` is `yes` and the product manages stock. The variations controller sets it when `$variation->get_manage_stock()` (view context; `'parent'` is truthy for a parent-managed variation).

The test harness to copy from is the investigation probe, kept at `.claude/specs/2026-11-28-stock-delta-2853/probe.php.txt`. It extends `Sync_REST_Store_Test_Case` and reuses `Dispatch_Write_Controller` and `Dispatch_Fake_Mutation_Store` from `tests/includes/Sync/Test_Rest_Dispatch_Write_Contract.php` (`require_once`). It plays the concurrent sale from `woocommerce_rest_pre_insert_product_object` / `..._product_variation_object`. That filter runs after the compare and before WooCommerce saves.

## Interfaces and constraints

New `includes/API/V2/Writers/Product_Writer.php`, `class Product_Writer extends Null_Writer`, namespace `WCPOS\WooCommercePOS\API\V2\Writers`. It has the same file header and `phpcs:disable` line as `Null_Writer.php`.

- `private $stock = null;` holds the deferred write: `array( 'pushed' => <value> )`, and `'s0'` is added by `after_update`.
- `public function prepare_update( array $meta, int $id, array $payload, callable $validate_tax_ids )`: `return $this->defer_stock( $id, parent::prepare_update( $meta, $id, $payload, $validate_tax_ids ) );`
- `protected function defer_stock( int $id, array $prepared ): array`. It returns `$prepared` unchanged unless every one of these holds:
  1. `array_key_exists( 'stock_quantity', $prepared['payload'] )`;
  2. the pushed value is not `null` and not `''`, and `is_numeric()`;
  3. `wc_get_product( $id )` is a `WC_Product`, `->managing_stock()` is truthy, and `true === ->get_manage_stock( 'edit' )` (the record manages its own stock now; parent-managed variations and unmanaged products keep today's absolute forward);
  4. the payload either has no `manage_stock` key, or its `manage_stock` is strictly `true`.

  Exception: when 1 and 3 hold and the payload's `manage_stock` is strictly `false`, unset `stock_quantity` from `$prepared['payload']`, leave `$this->stock` null, and return it (turned off in the same push: no stock write). When all four hold: set `$this->stock = array( 'pushed' => <value> )`, unset `stock_quantity` from `$prepared['payload']`, and return it. Nothing else in `$prepared` changes.

  Why reading the product before the compare is safe: if the compare passes, the record has not changed since `baseRevision`, so `manage_stock` at this read equals `manage_stock` at the compare.
- `public function after_update( int $id, array $payload, array $current, array $response_data, array $context ): void`: when `null !== $this->stock`, set `$this->stock['s0'] = $current['stock_quantity'] ?? null;`. It must not read the product again: S0 must be the compared value.
- `public function with_write_lock( int $id, array $payload, callable $work )`: `$result = parent::with_write_lock( $id, $payload, $work );`. Then take `$pending = $this->stock;` and reset `$this->stock = null;`. If `null !== $result`, or `null === $pending`, or `! array_key_exists( 's0', $pending )`, return `$result`. Otherwise return `$this->write_stock( $id, $pending['pushed'], $pending['s0'] )`.
- `private function write_stock( int $id, $pushed, $s0 )`, which returns `null` or a `WP_Error`:
  - `$target = wc_stock_amount( $pushed );`
  - When `is_numeric( $s0 )`: `$delta = $target - wc_stock_amount( $s0 );`. If `0.0 === (float) $delta`, return `null` without any call. Otherwise call `wc_update_product_stock( $id, abs( $delta ), $delta > 0 ? 'increase' : 'decrease' )`.
  - Otherwise (S0 null, no meaning): `wc_update_product_stock( $id, $target, 'set' )`.
  - Wrap the call in `try { } catch ( \Throwable $e ) { }`. On a throw, call `Logger::warning( ... )` (`WCPOS\WooCommercePOS\Logger`, static, `( $message, $context = null )`) with the record id and the exception message, and treat it as failed. A return of `false` is also failed.
  - On failure, return `new WP_Error( 'woo_rxdb_sync_stock_write_failed', 'The record was saved but its stock was not written. Re-read the record before retrying.', array( 'status' => 500 ) )`. On success, return `null`.
- Short docblocks on each method, in the style of `Null_Writer.php`. One class comment says why: S0 is the compared value, the stock goes last, and the reason is #2853.

`Variation_Writer.php`: change `extends Null_Writer` to `extends Product_Writer`. In `prepare_update()`, wrap the returned array in `$this->defer_stock( $id, array( ... ) )`. Nothing else changes.

`Collection_Writer_Resolver.php::resolve()`: right after the `product_variation` check, add `if ( 'product' === ( $meta['post_type'] ?? '' ) ) { return new Product_Writer(); }`. Leave the switch unchanged.

What the client sees when the stock call fails: an error response, HTTP 500, `code` `woo_rxdb_sync_stock_write_failed`, and no ack document. The other pushed fields are saved and the stock is unchanged (S0 plus any concurrent sales). The reservation is released, so the client may retry. A retry on the old `baseRevision` gets a 409 `woo_rxdb_sync_conflict` whenever the forward changed anything, and that 409's `current.stock_quantity` is the live stock, never the pushed number. A stock-only push changes no revision, so its retry re-applies the delta from a fresh S0.

## New test file

`tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php`, class `Test_Rest_Dispatch_Stock_Delta extends Sync_REST_Store_Test_Case`, namespace `WCPOS\WooCommercePOS\Tests\Sync`.

Reuse the probe's `setUp`, `rest_api_init`, `tearDown`, `sale_in_window`, `push`, `held_revision` and `stock` helpers as they are, without the `fwrite` diagnostics and without the `// phpcs:disable` line. Use the repo's normal test docblocks instead. `tearDown` also removes any listener the tests add. Write the tests named in the combination table, in Arrange / Act / Assert order with `assertSame( expected, actual )`:

- **Sale in the window** (product and variation): like the probe's tests, but with the expected stock `9`, and assert `1 === $this->sales_fired`.
- **Control:** the probe's control test, with 409 and stock 7.
- **Price-only push:** stock 7 after the sale, and `regular_price` saved as `'11.00'`.
- **Increase and decrease:** no sale; push 12 gives 12; push 8 gives 8.
- **Zero delta:** push `array( 'stock_quantity' => 10, 'regular_price' => '11.00' )` on stock 10. Take `did_action( 'woocommerce_product_set_stock' )` before and after: same count. Price saved.
- **`manage_stock` turned on:** product created with `manage_stock` false; push `array( 'manage_stock' => true, 'stock_quantity' => 5 )`; stock 5.
- **`manage_stock` turned off:** product managed at 10; push `array( 'manage_stock' => false, 'stock_quantity' => 12 )`; 200; `did_action( 'woocommerce_product_set_stock' )` unchanged; `wc_get_product( $id )->get_manage_stock()` is false.
- **Ack:** push 12 on 10 with a sale of 3 in the window. The ack's `get_data()['document']['stock_quantity']` is `9`, and `get_data()['currentRevision']` equals `$this->held_revision( 'products', $id )` taken after the push.
- **Failure:** add a listener on `woocommerce_product_before_set_stock` that throws `\RuntimeException`. Push `array( 'regular_price' => '11.00', 'stock_quantity' => 12 )` on base B. Assert status 500, `get_data()['code']` is `'woo_rxdb_sync_stock_write_failed'`, stock 10, price `'11.00'`, and the response data has no `document` key. Remove the listener. Push the same payload on B again: 409, and `get_data()['current']['stock_quantity']` is `10`.

## Probe

The full probe source is `.claude/specs/2026-11-28-stock-delta-2853/probe.php.txt` in this worktree. Read it; do not edit it.

## Do not

- Edit `Write_Controller.php`, the interface, or `Null_Writer.php`
- Use `inventory_delta`, direct SQL, `update_post_meta`, `set_stock_quantity` or a transaction
- Touch create or delete paths, or the order writer
- Add locking, retries, fallbacks, caching, options, settings, filters or hooks
- Add a new dependency, a feature flag or a migration
- Refactor or reformat code outside the change
- Create plan, notes or scratch files
- Commit or push
- Read a GitHub issue, PR or comment

## Pre-authorised actions

- Edit and create the in-scope files listed above
- Read the probe file at `.claude/specs/2026-11-28-stock-delta-2853/probe.php.txt`
- Run the acceptance commands below (no install; there is no `vendor/` in this worktree and you must not create one)

## Budget

- Max non-test lines changed: 110
- Max total lines changed: 300

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target: if the work is about to exceed it,
stop and report the overrun and its reason under NOT DONE. Never strip
tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
php -l includes/API/V2/Writers/Product_Writer.php
php -l includes/API/V2/Writers/Variation_Writer.php
php -l includes/API/V2/Writers/Collection_Writer_Resolver.php
php -l tests/includes/Sync/Writers/Test_Collection_Writer_Resolver.php
php -l tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php
```

PHPUnit runs in Docker (wp-env), which this sandbox cannot reach. Do not try it, and do not stop for it. The reviewer runs the suites below.

## Reviewer reruns (not for Codex)

```sh
# wp-env stack, WooCommerce 11.3.1 (CI's Smoke Test version)
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Writers
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/API/V2/Test_Variations_Bare_Records.php
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Decimal_Quantity_Write_Contract.php
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Stock_Validation.php
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Write_Contract.php
vendor/bin/phpcs --standard=./.phpcs.xml.dist <changed files>
# failing-first: the new test file against base d4495ab7 production code (sale tests expect 9, get 12)
# mutations: S0 from a fresh read in after_update; drop the zero-delta guard; ignore a false return
```

## Environment

- Network: no
- Writable outside the worktree: none
- `php` is on PATH for `php -l`. There is no vendor dir and no wp-env from this sandbox.

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES). If the spec is
ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
A remark that did not stop the work goes under NOTES and STATUS stays done.
