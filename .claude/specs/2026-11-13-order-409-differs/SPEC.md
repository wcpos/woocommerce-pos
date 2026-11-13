# Codex job spec — the order push 409 names what differs (POS-2VT diagnosis)

## Goal

On stores hit by Sentry POS-2VT, the till's order push 409s again and again. #2663 and #2664 cover the known cause: another plugin moving its own meta. When a 409 still repeats, nobody can see WHICH field moved, because the 409 carries only `current` (the whole order) and `currentRevision` (a hash).

This job adds one field, `differs`, to the push lane's order-update 409. It lists the paths where the pushed order and the server copy disagree, inside exactly what the order revision hashes (the allow-list canonical form, `Order_Serializer::canonical_form()`). It holds names only, never values.

The server keeps no copy of the client's base document, only its hash. So the diff compares the pushed document with the server copy, and the client's own edits show up too. The docblock says so.

The job also fixes some comments: the legacy recipe is 2.0.2's, not 2.0.3's (see In scope).

## Stakes

This touches the order push lane (High tier, sync). The response shape gains a field on the 409 only:
- the hash, the compare and the 200/201 path are untouched;
- `current` and `currentRevision` are unchanged;
- a 2.0.0 app that ignores unknown keys is unaffected.

The 409 path must not throw: a malformed payload yields an empty or partial list, never an error.

## In scope (the only files you may edit)

- `includes/Sync/Order_Serializer.php`:
  - Add ONE public static method, `canonical_diff()`, and at most one private helper for it.
  - Comments only: the legacy recipe hashes every meta row, which is how 2.0.2 hashed; the allow-list ships in 2.0.3. Change "2.0.3 recipe" to "2.0.2 recipe" and "2.0.3 form" to "2.0.2 form" in the comments at lines 387, 427, 433 and 520.
  - Leave the two `@since 2.0.3` tags (lines 420 and 476) as they are: they are correct.
- `includes/API/V2/Write_Controller.php`:
  - Pass the diff into `stale_conflict()` on the update path.
  - Comment at line 838: change "2.0.3-recipe" to "2.0.2-recipe".
- `tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php`:
  - Add ONE test (below).
  - In the comments at lines 526, 528, 544 and 554, change "2.0.3" (the legacy recipe) to "2.0.2".
  - Change the docblock "A till's 2.0.3 ack" to "A till's 2.0.2 ack".
  - Rename `test_a_2_0_3_ack_is_accepted_after_the_upgrade` to `test_a_2_0_2_ack_is_accepted_after_the_upgrade`.
  - Rename `test_a_2_0_3_ack_after_a_pos_owned_change_still_409s` to `test_a_2_0_2_ack_after_a_pos_owned_change_still_409s`.
- `tests/includes/Payments/Contract/Test_Command_Log.php`:
  - Comment at line 237: change "2.0.3-recipe" to "2.0.2-recipe".
  - Rename `test_run_built_on_a_2_0_3_revision_is_not_stale` to `test_run_built_on_a_2_0_2_revision_is_not_stale`.
  - Nothing else in this file.

## Out of scope (do not edit)

- `includes/Sync/Revision.php`
- `includes/Sync/Order_Writer.php`
- `includes/Sync/Order_Write_Payload.php`
- the bodies of `canonical_form()`, `form()`, `canonical_revision()`, `stale()` and `legacy_revision()` in `includes/Sync/Order_Serializer.php`
- `tests/includes/Sync/Test_Order_Revision_Recipe.php`
- Other surfaces: the proxy (wc/v3) lane's 412/409, product/variation/coupon 409s, the delete lane's `stale_conflict()` call (Write_Controller ~line 566).
- Changelog, `readme.txt`, version, docs, CI.

## Context

- `Order_Serializer::canonical_form( array $payload ): array` (line 380) is exactly the array the revision hashes. It:
  - scopes to WooCommerce's order schema keys;
  - drops hidden (`_`-prefixed) meta outside `revision_meta_keys()`, on the order and on lines;
  - drops `wcpos_sync_order_revision_excluded_meta_keys` keys;
  - strips `_woocommerce_pos_uuid`;
  - recursively sorts keys with `Revision::canonicalize()`.

  In that form, `meta_data` (at both depths) is a sorted list of `{key, value}` arrays, and line items keep their `id`.
- `canonical_revision()` hashes `wp_json_encode( canonical_form( ... ) )`.
- `Write_Controller::apply_update()` (line 452): inside the locked closure, `$current_bare` is the server document and `$m['payload']` is the pushed document. `stale_for()` returns null or the facts array, and then `return $this->stale_conflict( $stale );` (line 494).
- `Write_Controller::stale_conflict( array $facts )` is at line 812.
- Test helpers already in `Test_Rest_Dispatch_Repush_Idempotency.php`:
  - `push_order( $operation, $payload, $base_revision )` (line 92)
  - `create_payload()` (line 114)
  - `edited_document( $response, $note )` (line 397)
  - `set_order_meta( $id, $key, $value )` (line 598)

## Interfaces and constraints

### `Order_Serializer::canonical_diff( array $client, array $server ): array`

Returns `string[]`, de-duplicated and sorted with `sort( $paths, SORT_STRING )`.

**Setup**
- `$a = self::canonical_form( $client )` and `$b = self::canonical_form( $server )`.
- Values are equal when `wp_json_encode( $x ) === wp_json_encode( $y )`.
- Walk only the top-level keys present in `$a`: a field the client did not send is not a difference.

**Paths it emits**
- **`meta_data`:** emit `meta_data.<key>` for every key whose rows differ.
  - Group each side's rows by `key` into a list of encoded values.
  - Compare the union of both sides' keys, so a key on one side only is listed.
- **`line_items`, `shipping_lines`, `fee_lines`, `coupon_lines`:**
  - Index each side's items by `(int) id`. A client item without a positive id is keyed `new<index>` (its list index).
  - An item on one side only: emit `<field>.<id>`.
  - A matched item: walk the union of its keys.
    - `meta_data` → the same per-key rule, with prefix `<field>.<id>.meta_data`.
    - Any other differing key → `<field>.<id>.<key>`.
- **Any other differing top-level key:** emit `<field>`.
- **Malformed input:** where a list, item or meta row is not an array, or a row has no `key`, emit the parent path and do not descend. Never throw.

**Docblock:** diagnostics only; names only, never values; the client's own edits appear alongside another writer's moves.

### `Write_Controller`

- **`stale_conflict( array $facts, ?array $differs = null ): WP_REST_Response`:**
  - When `$differs` is not null, add `'differs' => $differs` AFTER `currentRevision` in the wire key order.
  - Add one docblock `@param` line: path names where the pushed order and the server copy differ in the revision's canonical form; order updates only.
- **Update path (line 494):**
  - For an order (`'order' === ( $meta['id_type'] ?? '' )`), call `$this->stale_conflict( $stale, Order_Serializer::canonical_diff( (array) $m['payload'], $current_bare ) )`.
  - Otherwise make the existing call.
  - `$m` is already in the closure's `use` list.

## Test (one, added after `test_pos_owned_change_still_409s_a_stale_push`)

`test_stale_order_push_409_names_what_moved_inside_the_revision`

1. **Arrange:**
   - Create the order with `push_order( 'create', $this->create_payload() )` and assert 201.
   - Read `$id` from the response's `document.id`, and `$line_id` from its `document.line_items[0].id`.
   - Out of band, as another plugin would:
     - `$this->set_order_meta( $id, 'x_audit', 'moved' )` (visible order meta);
     - `wc_update_order_item_meta( $line_id, 'x_line_audit', 'moved' )` (visible line meta);
     - `$this->set_order_meta( $id, '_x_hidden_stamp', 'moved' )` (hidden, not allow-listed, outside the hash).
2. **Act:** `push_order( 'update', $this->edited_document( $created, 'first edit' ), <create's currentRevision> )`.
3. **Assert:**
   - Status 409, code `woo_rxdb_sync_conflict`.
   - `assertSame( array( 'customer_note', 'line_items.' . $line_id . '.meta_data.x_line_audit', 'meta_data.x_audit' ), $stale->get_data()['differs'] ?? null, wp_json_encode( $stale->get_data() ) )`.
   - The hidden key must NOT appear: that proves the diff uses the same allow-list form as the hash.

**Order of work**
1. Write the test.
2. Run it and confirm it fails (no `differs` key). Save the PHPUnit output to `.claude/specs/2026-11-13-order-409-differs/red.txt`.
3. Implement.
4. Run it again; it must pass.

If the expected list is wrong only because another hashed field also differs (for example a total WooCommerce recomputes), STOP and report it. Do not widen the test.

## Do not

- Include values, meta row ids, or anything but names in `differs`.
- Add a filter, an option, a log line, storage of base documents, or a cache.
- Change the hash, `canonical_form()`, `stale()`, or the other lanes' refusals.
- Refactor or reformat code outside the change.
- Create plan or notes files beyond `red.txt`.
- Commit or push.
- Read a GitHub issue, PR or comment.

## Pre-authorised actions

- Edit the in-scope files and write `red.txt`.
- Run the acceptance commands. The setup is done for you:
  - wp-env is running for this worktree;
  - node_modules and vendor are installed.

  Do not install anything, and do not start or stop wp-env.

## Budget

- Max non-test lines changed: 75
- Max total lines changed: 118

If you are about to exceed either, STOP and report why instead of continuing.
The budget is a stop rule, not a target. Never strip tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Order_Revision_Recipe.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Payments/Contract/Test_Command_Log.php
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs --standard=./.phpcs.xml.dist includes/Sync/Order_Serializer.php includes/API/V2/Write_Controller.php tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php tests/includes/Payments/Contract/Test_Command_Log.php
```

phpcs:
- Errors on lines you added or changed must be fixed.
- Errors already on main stay.

## Environment

- Network: yes, to reach the local wp-env containers (Docker).
- Writable outside the worktree: none.
- wp-env is running for this worktree, with dependencies installed.
- Wall-clock limit: three hours. At the limit, stop and report partial state.

## Output expected

End with the report format from ~/.codex/AGENTS.md:
- STATUS
- CHANGED
- ACCEPTANCE: each command, its exit code and its last lines
- the red run
- NOT DONE
- QUESTIONS
- BEHAVIOUR CHANGES
- one paragraph on why you made these changes

If the spec is ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
