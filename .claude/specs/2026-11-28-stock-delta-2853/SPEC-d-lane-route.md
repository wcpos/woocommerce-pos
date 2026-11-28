# Codex job spec — #2853 follow-up D: literal wcpos/v2 routes, parent-managed test, neutral failure message

## Goal

Three small changes on PR #2854 (head ccdc1c8f):

1. CI's "REST lane coverage" gate (`.github/workflows/lane-coverage.yml`, step "v1-only coverage must not grow") fails on all eleven cases in `tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php`. The `push()` helper builds its route at runtime (`'/' . Api::ROUTE_NAMESPACE . '/push/' . $collection`), so `scripts/lane-coverage.php` reports each case as "unresolved". The scanner credits only string literals that contain `wcpos/v2`, and it credits a literal in a class helper to every case in the class.
2. Review finding: no test covers the `true !== $product->get_manage_stock( 'edit' )` guard in `Product_Writer::defer_stock()`. If that guard is removed, a push to a parent-managed variation moves the parent's stock. Add one test.
3. Review finding: a throw inside `wc_update_product_stock()` can come after its SQL write, so "its stock was not written" can be false. Make the message neutral.

## Stakes

High tier (stock). The production change is one string; the rest is tests.

## In scope (the only files you may edit)

- `tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php`
- `includes/API/V2/Writers/Product_Writer.php` (the single message string only)

## Out of scope (do not edit)

- Every other file, including `scripts/lane-coverage.php`, `tests/lane-coverage/annotations.json` and the CI workflows

## Interfaces and constraints

1. In `push()` in the test file, replace the runtime route with a literal chosen by collection:
   `$route = 'variations' === $collection ? '/wcpos/v2/push/variations' : '/wcpos/v2/push/products';`
   then `$request = $this->wp_rest_post_request( $route );`.
   If `Api` is no longer referenced anywhere in the file, remove its `use` line.
2. Add this test method to the same class, after `test_variation_stock_push_with_sale_in_window_keeps_the_sale()`. Reproduce it exactly, with tab indentation like its neighbours:

   ```php
   /** A variation whose stock the parent manages never moves the parent's stock through the delta. */
   public function test_parent_managed_variation_push_leaves_parent_stock_alone(): void {
   	$parent = ProductHelper::create_variation_product();
   	$parent->set_manage_stock( true );
   	$parent->set_stock_quantity( 10 );
   	$parent->save();
   	$id        = $parent->get_children()[0];
   	$variation = wc_get_product( $id );
   	$variation->set_manage_stock( false );
   	$variation->save();
   	$base = $this->held_revision( 'variations', $id );
   	add_filter( 'woocommerce_update_product_stock_query', array( $this, 'count_stock_query' ) );

   	$response = $this->push( 'variations', $id, $base, array( 'stock_quantity' => 12 ) );

   	$this->assertSame( 200, $response->get_status() );
   	$this->assertSame( 0, $this->stock_queries );
   	$this->assertSame( 10, $this->stock( $parent->get_id() ) );
   }
   ```

   If `tearDown()` already removes the `count_stock_query` filter, add nothing. If it does not, add nothing either: the zero-delta and manage-off tests use the same filter the same way.
3. In `includes/API/V2/Writers/Product_Writer.php`, change the `WP_Error` message from
   `'The record was saved but its stock was not written. Re-read the record before retrying.'`
   to
   `'The record was saved but its stock may not have been written. Re-read the record before retrying.'`
   Keep the code `woo_rxdb_sync_stock_write_failed` and the status 500. Change nothing else in that file.
   If a test asserts the old message text, update that assertion to the new text. That is the only other edit allowed.

## Do not

- Edit any other file or any other line
- Commit or push
- Create plan, notes or scratch files

## Pre-authorised actions

- Edit the in-scope files
- Run the acceptance commands

## Budget

- Max non-test lines changed: 2
- Max total lines changed: 30

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

```sh
php -l tests/includes/Sync/Test_Rest_Dispatch_Stock_Delta.php
php -l includes/API/V2/Writers/Product_Writer.php
php scripts/lane-coverage.php --compare=/Users/claude/.claude/jobs/a1d68413/tmp/lane-base/base.json   # must exit 0
```

## Reviewer reruns (not for Codex)

These run under wp-env:
- the full Stock_Delta file, which must show 12 tests passing;
- mutation M4, the parent guard removed, which the new test must catch;
- the Writers/* files, which must still pass.

## Environment

- Network: no
- Writable outside the worktree: none (the base.json path is read-only input)

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
