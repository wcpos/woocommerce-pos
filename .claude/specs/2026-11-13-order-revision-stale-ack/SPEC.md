# Spec — order revision meta-key exclusion filter (Sentry POS-2VT)

## Goal

Some other plugin on a merchant's site can write an order or line-item meta row on every request, for example from an async job or a shutdown handler. When that happens, every order revision the push lane hands out is stale by the time the till uses it. The push then 409s, the app re-anchors on the 409's own `currentRevision`, and the re-push 409s again. Checkout then fails with `provenance_save_failed` (Sentry POS-2VT; the probe evidence is in `repro/probe-2.0.2.txt`).

This job adds a filter, `wcpos_sync_order_revision_excluded_meta_keys`. A site uses it to name bookkeeping meta keys that the order revision should not hash, on the order and on every line, so the compare-and-swap stops tripping on them. The default is an empty list, so the hash bytes are unchanged for every site that does not use the filter.

## Stakes

Order push lane and checkout (High tier, money and sync). The failure mode is a wrong revision: a false 409, or a missed conflict. The default (empty filter) must hash byte-identically to today. An existing test proves that, and it must stay green.

## In scope (the only files you may edit)

- `includes/Sync/Order_Serializer.php`
- `tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php` (add three tests and two small private helpers; edit nothing else in it)

## Out of scope (do not edit)

- `includes/Sync/Revision.php`
- `includes/API/V2/Write_Controller.php`
- `includes/API/V2/Writers/Order_Writer.php`
- `includes/Sync/Pos_Uuid.php`
- `tests/includes/Sync/Test_Order_Revision_Recipe.php`
- The changelog, readme, `package.json`, `composer.json`, lockfiles, plugin header version, docs, CI config

## Context

- `Order_Serializer::canonical_form()` is at `includes/Sync/Order_Serializer.php:330`. It is THE order revision recipe. Its docblock (from about line 303) states the coherence contract:
  - Every hash site runs this one function. These are the pull fallback, the CAS re-read in `Write_Controller::revision_for()`, and the proxy stamp.
  - A filter therefore changes all of them at once.
- Today `canonical_form()` already drops two kinds of meta row:
  - order `meta_data` rows whose key is one of three `_wp_trash_meta_*` keys (the `array_filter` at about line 362);
  - `_woocommerce_pos_uuid` rows from the `meta_data` of `line_items`, `shipping_lines`, `fee_lines` and `coupon_lines`, in `strip_item_identity_meta()` at about line 393.
- The new exclusion goes in those same two places. Keys are read with `Meta_Entry::key( $entry )`, as the existing code does.
- `tax_lines` are not in that item loop. Do not add them: tax lines are readonly and WooCommerce derives them.
- The existing filter `wcpos_sync_order_revision_fields` (at about line 353) shows the house docblock style for a filter: a description, `@since`, `@param`. Copy that style.
- Pushes go through that file's existing `push_order( $operation, $payload, $base_revision )` (real v2 route, unique mutation ids). The create payload is the file's existing `create_payload()`.
  - Each update push sends the previous response's `document` with `customer_note` set to a new string, so that the payload is a real edit.
- Repro probe (for reference only, do not copy its structure): `.claude/specs/2026-11-13-order-revision-stale-ack/repro/Probe_Pos2vt.php.txt`, scenario S2.

## Interfaces and constraints

- New filter, applied once per `canonical_form()` call, before any meta is stripped:

  ```php
  /**
   * Filters the meta keys the canonical order revision leaves out, on the order and on every line.
   * (docblock: say that an excluded key no longer 409s a stale push, so a full-document
   * push from a till can overwrite that key's value: last writer wins. Keep it to a few lines.)
   *
   * @since 2.0.3
   *
   * @param string[] $keys Meta keys left out of the hash. Default empty.
   */
  $excluded_meta = array_map( 'strval', (array) apply_filters( 'wcpos_sync_order_revision_excluded_meta_keys', array() ) );
  ```

- Matching is an exact key match (`in_array( $key, $excluded_meta, true )`). There is no prefix or regex matching.
- Order `meta_data`: drop rows whose key is a trash key OR in `$excluded_meta`. Extend the existing `array_filter`; do not add a second pass.
- Item `meta_data` in the four line types: drop `_woocommerce_pos_uuid` OR any key in `$excluded_meta`. Pass `$excluded_meta` into `strip_item_identity_meta()` as a second parameter. That method is private, so changing its signature is allowed. Update its docblock with one line.
- When the filter returns an empty array, the output of `canonical_form()` must be byte-identical to today's.
- There is no deprecated `woocommerce_pos_*` twin; this is a new hook (ADR 0041).

## Tests — added to `tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php`

AMENDMENT (2026-11-13, after the first run stopped on the budget): the tests do NOT get a file of
their own. Add them to the existing repush file and REUSE its `push_order()`, `create_payload()`
(no coupon), `setUp`/`tearDown` and `RECORD_UUID`; do not copy any of them. That file's lines,
fee and shipping lines all count as lines for the line-meta writer. No teardown is needed:
`WP_UnitTestCase` backs up and restores hooks per test, so do not add removal code. Keep every
docblock phpcs requires; the budget below fits them.

The file defines a "third-party writer": a plugin that writes order meta at the end of every push request.
- Register it on `rest_request_after_callbacks`, with three arguments: `$response, $handler, $request`.
- When `$request->get_route()` is `/wcpos/v2/push/orders`, read the order id from `$response->get_data()['document']['id'] ?? $response->get_data()['current']['id'] ?? 0`. For a non-zero id, write the next value of a counter:
  - Order variant: `$order = wc_get_order( $id ); $order->update_meta_data( '_x_third_party_stamp', (string) $n ); $order->save_meta_data();`.
  - Line variant: `wc_update_order_item_meta( $item_id, '_x_third_party_line_stamp', (string) $n )` for each `$order->get_items()`.
- Return `$response` unchanged.
- Register the writers through one private helper, e.g. `add_third_party_writer( bool $lines )`; no unregistration (see the amendment above).

Exactly three tests:

1. `test_meta_written_after_every_push_409s_the_reanchored_repush_again`. This is the POS-2VT repro, a characterization that passes both before and after the fix.
   - Use the order variant with no exclusion filter.
   - Steps:
     - Create the order and assert 201.
     - Update with the create's `currentRevision` and assert 409.
     - Update again with that 409's `currentRevision` and assert 409.
   - For each 409, also assert that `code` is `woo_rxdb_sync_conflict`.
   - Pass a status-and-body message to each `assertSame`, using `wp_json_encode( $response->get_data() )`, as the repush file does.
2. `test_excluded_order_and_line_meta_keys_written_after_every_push_no_longer_409`. This is the cure, and it must FAIL before the fix.
   - Register both writers, the order variant and the line variant.
   - Add the filter returning `array( '_x_third_party_stamp', '_x_third_party_line_stamp' )`.
   - Steps: create (assert 201), then update, then update, then update, each with the previous response's `currentRevision`. Assert 200 for each update.
3. `test_meta_key_not_excluded_still_409s_a_stale_push`. This guards against the filter excluding more than it names.
   - Add the filter returning `array( '_x_third_party_stamp' )`.
   - Do not register any writer.
   - Steps:
     - Create and assert 201.
     - Update with the create's `currentRevision` and assert 200.
     - Write `_x_other_plugin_key` = `'moved'` on the order (`update_meta_data` + `save_meta_data`).
     - Update with the 200's `currentRevision` and assert 409.

Order of work (failing first, as the repo requires):
1. Write the test file.
2. Run it. Test 1 must pass, test 2 must fail (an update returns 409), and test 3 must pass. Save that output to `.claude/specs/2026-11-13-order-revision-stale-ack/repro/red.txt` (overwrite the earlier red run, which came from the withdrawn standalone file).
3. Implement.
4. Run again; all three must pass.

If test 1 does not show 201/409/409 before the fix, STOP and report it. Do not change the scenario to make it fail.

## Do not

- Touch `Write_Controller::respond()`, the post-hash uuid stamp, or the 409 body. Those are the other options, and they stay unbuilt for Paul.
- Add an option, a setting, a database table, a default exclusion list, or prefix matching.
- Change `Revision.php` or the generic `wcpos_sync_revision_excluded_fields`.
- Add locking, retries, caching or fallbacks.
- Refactor or reformat code outside the change.
- Create plan, notes or scratch files, other than `repro/red.txt`.
- Commit or push.
- Read GitHub issues, PRs or comments.

## Pre-authorised actions

- Edit and create the in-scope files, and write `repro/red.txt`.
- Run the acceptance commands below. wp-env is already running for this worktree, and `node_modules` and `vendor` are installed: do not install anything, and do not start or stop wp-env.

## Budget

- Max non-test lines changed: 35
- Max total lines changed: 145

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target. Never strip tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable, from the worktree root)

Use a Bash timeout of 700000 ms for each phpunit command.

```sh
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Order_Revision_Recipe.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Sync/Order_Serializer.php tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php
```

phpcs: report any errors on lines you added. Errors already on `main` stay.

## Environment

- Network: not needed (wp-env is already up).
- Wall-clock limit: three hours. At the limit, stop and report partial state.
- The Docker VM is shared: run one suite at a time, through `test-slot-run`.

## Output expected

Report:
- STATUS
- CHANGED, with files and line counts from `git diff --stat`
- ACCEPTANCE, with each command, its exit code and its last lines
- the red run (test 2 failing before the fix)
- NOT DONE
- QUESTIONS
- BEHAVIOUR CHANGES
- why you made these changes, in one paragraph

If the spec is ambiguous or wrong, stop and ask under QUESTIONS. Do not guess.
