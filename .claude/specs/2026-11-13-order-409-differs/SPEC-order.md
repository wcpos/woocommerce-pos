# Codex job spec — `canonical_diff` names a list whose only difference is item order

## Goal

`Order_Serializer::canonical_diff( $client, $server )` names the paths where two canonical forms differ (the `differs` list on an order-update 409). For the list fields (`meta_data`, `line_items`, `shipping_lines`, `fee_lines`, `coupon_lines`), it calls `list_diff()`, which re-keys both lists by item id (`keyed()`) and compares per key. When the two lists hold the same items in a different order, the field's JSON differs, so the revision hashes differ, but every per-key comparison is equal. `list_diff()` then returns `[]` and the field is never named, so the 409 says nothing differs.

Fix: `list_diff()` is only ever called on two values whose JSON already differs. When its per-key walk finds no difference, it returns the bare `$path`.

## Stakes

This is diagnostics only: `differs` on an order-update 409. It does not affect the hash, the compare or the 409/200 decision.

## In scope (the only files you may edit)

- `includes/Sync/Order_Serializer.php` (only the final `return` of `list_diff()`, around line 429)
- `tests/includes/Sync/Test_Order_Serializer.php` (add one test method)

## Out of scope (do not edit)

- every other file, including `canonical_diff()`, `keyed()`, `form()` and `includes/API/V2/Write_Controller.php`

## Interfaces and constraints

- Change the last line of `list_diff()` from `return $out;` to return `array( $path )` when `$out` is empty, for example `return $out ? $out : array( $path );`. Nothing else in the method changes.
- PHP 7.4 compatible. WordPress coding standards (tabs, spaces inside parentheses), matching the surrounding code.
- Add this test method to `Test_Order_Serializer`, after the last existing test (`test_canonical_diff_names_a_field_only_the_server_carries`):

  ```php
  /** Two lists holding the same items in another order are still named. */
  public function test_canonical_diff_names_line_items_that_differ_only_in_order(): void {
  	$first  = array(
  		'id'       => 11,
  		'name'     => 'First',
  		'quantity' => 1,
  	);
  	$second = array(
  		'id'       => 12,
  		'name'     => 'Second',
  		'quantity' => 2,
  	);
  	$client = array( 'line_items' => array( $first, $second ) );
  	$server = array( 'line_items' => array( $second, $first ) );

  	$this->assertSame( array( 'line_items' ), Order_Serializer::canonical_diff( $client, $server ) );
  }
  ```

## Do not

- Change `canonical_diff()`, `keyed()`, `form()` or any comment.
- Rename or edit any existing test.
- Commit or push. Do not start or stop wp-env, and install nothing.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the two in-scope files and run the acceptance commands.

## Budget

- Max non-test lines changed: 2
- Max total lines changed: 22

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

Add the test first and run the first command: the new test must FAIL (`[]` instead of `['line_items']`). If it passes before the fix (for example because the canonical form sorts the items), STOP and report that under QUESTIONS; do not change the test. Then make the edit; all of these must pass:

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Order_Serializer.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Sync/Order_Serializer.php tests/includes/Sync/Test_Order_Serializer.php
```

## Environment

- Network: yes, to reach the local wp-env containers (Docker).
- Writable outside the worktree: `~/.wp-env/wp-env-order-revision-allow-list-3526dcd1` (wp-env's cache; granted with --writable).
- wp-env is running for this worktree, with dependencies installed.

## Output expected

End with the STATUS / CHANGED / ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES report, and quote the red run's failure line.
