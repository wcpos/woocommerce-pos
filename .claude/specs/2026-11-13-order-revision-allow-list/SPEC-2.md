# Spec 2: visible order meta back in the revision, and a push never overwrites another plugin's hidden meta (POS-2VT)

## Goal

This builds on `f57288c8` (SPEC.md in this folder), which is already committed on this branch. The front desk reviewed it and found one design bug, proven by a probe on the server:

1. Hidden meta outside the revision hash does not move the revision.
2. The app does not refresh a pulled order whose revision is unchanged.
3. Every push echoes the order's meta and every line's `{id, meta_data}` back as the app holds them.

So a third party sets `_tracking_number = NEW`, and the revision stays the same. The cashier's next note edit then pushes the held document, answers 200, and puts `_tracking_number` back to `OLD`. This silently reverts another plugin's data on an ordinary edit.

The front desk's ruling, which this spec implements:

- **Hash:** the order revision hashes these, and nothing else is in CAS:
  - core fields;
  - line items;
  - the POS allow-list;
  - **all visible (non-`_`) order meta**;
  - on lines: visible line meta plus the allow-listed hidden keys, as now.
- **Visible meta** is what cashiers edit in the app's meta form, so a third-party change to it 409s again, as on 2.0.2. Hidden line meta off the allow-list stays out of the hash.
- **Push write rule, for order meta and line meta alike:** a pushed hidden (`_`-prefixed) key that is not on the allow-list never UPDATES or DELETES a value that already exists on the server for that order or line.
  - It is still written when the key does not exist there yet. App-created lines and new orders lose nothing.
  - `_woocommerce_pos_uuid` is exempt outright.
  - A pushed payload that omits such a key must not delete it either.

## Stakes

This is core plugin code: order sync and the order push write path.

- If the write rule drops too much, the POS loses data it owns: line identity, tax data, the till's assertions.
- If it drops too little, the silent revert stays.
- If the hash changes wrongly, sales either park on a 409 or lose conflict detection.

## In scope (the only files you may edit)

- `includes/Sync/Order_Serializer.php`
- `includes/Sync/Order_Write_Payload.php`: one new private step, called from `for_update()` only
- `tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php`
- Any other file under `tests/includes/`, on one condition only: an existing test there fails because a visible order meta key now moves the revision, or because the write rule now drops a hidden key it relied on. List every such file, test and change in your report. Never weaken or delete an assertion; if a fix would need that, STOP and report.

## Out of scope

- `Write_Controller.php`, `Command_Log.php`, `Order_Writer.php`, `Revision.php`, the V1 controllers (`for_create()` and `for_partial_update()` stay as they are), and every other `includes/` file.
- Docs, ADRs, `README.md`, the changelog and the version header.

## The change

### 1. `includes/Sync/Order_Serializer.php`

**a) Visible order meta back in the hash.** In `form()`, the order meta filter currently keeps a key only when `null === $allowed || in_array( $key, $allowed, true )`. Change it to the same rule the line filter in `strip_item_identity_meta()` uses: keep the key when it is null-allowed, has no leading `_`, or is on the allow-list. The trash-meta exclusion and the #2663 `$excluded_meta` rule are unchanged, and excluded still wins.

**b) Comments.**
- Update the `REVISION_META_KEYS` comment and the `wcpos_sync_order_revision_meta_keys` filter docblock. They should say that the revision hashes every visible meta key, on the order and on lines, plus these hidden keys. Another plugin's hidden meta sits outside CAS, and the push never overwrites it once it exists (see `Order_Write_Payload`).
- Keep `@since 2.0.3`.

**c) One public reader for the allow-list.** Move the filter application into
```php
public static function revision_meta_keys(): array
```
It returns `array_map( 'strval', (array) apply_filters( 'wcpos_sync_order_revision_meta_keys', self::REVISION_META_KEYS ) )`, with the existing filter docblock moved onto it. `form()` calls it when `$allow_list` is true. Behaviour is unchanged.

**d) Removal reference.** In the docblock of `stale()`, change "Transition only: remove in 2.2 at the earliest." to "Transition only: remove in 2.2 at the earliest (#2665)." Do the same on `legacy_revision()` if its docblock mentions removal.

### 2. `includes/Sync/Order_Write_Payload.php`

Add a private step, `without_foreign_hidden_meta( $order, array $payload ): array`. Call it from `for_update()` directly after `without_internal_item_meta()`, and nowhere else.

**What it does:**
- **No order:** when `$order` is false, it returns `$payload` unchanged.
- **Foreign hidden key:** a key is foreign hidden when all three hold:
  - it is a scalar string starting with `_`;
  - it is not `_woocommerce_pos_uuid`;
  - it is not in `Order_Serializer::revision_meta_keys()`.

  Compute the allow-list once per call.
- **Order meta:** from `$payload['meta_data']`, when it is an array, drop every entry that is either of these:
  - a foreign hidden key that names a key already present in `$order->get_meta_data()`;
  - an entry whose `id` is numeric and equals the id of a stored order meta row whose key is foreign hidden.

  Use `Meta_Entry::key( $entry )` for the key, and read `id` the way `without_internal_item_meta()` does, for array and object entries. Re-index with `array_values`.
- **Line meta:** loop over `self::ITEM_CLASSES` types. For each line whose `id` is numeric and resolves through `$order->get_item( (int) $line['id'] )`, drop that line's `meta_data` entries by the same two rules, against that item's `get_meta_data()`. A line without an id, or whose id does not resolve, is left untouched (a new line keeps all its meta).
- **Omitted keys:** never add or remove anything else. A key the payload omits is not deleted. wc/v3 deletes no meta it is not sent, so no code is needed for that; a test proves it.
- **Docblock:** a few lines on why. It should cover:
  - the app echoes the meta it last pulled;
  - hidden third-party meta is outside the revision, so a stale echo would silently revert another plugin's write;
  - the POS never owns such a key, so the push may create it but never overwrite it;
  - `_woocommerce_pos_uuid` is the POS's identity and is exempt.

  Cite POS-2VT.

## Tests

Every new test follows the Arrange / Act / Assert layout, uses `assertSame( expected, actual )`, and has a one-line docblock. All go in `tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php`. Reuse the file's helpers: `push_order()`, `create_payload()` and `edited_document()`.

### Common arrange

Each test that needs a held document uses the same steps:
1. Create; it answers 201.
2. Write the third-party value `OLD` out of band.
3. Push an update (`edited_document( $created, 'first edit' )`, anchored on the create's `currentRevision`). It answers 200, and its response is the document the till now holds: `$held = $first`, `$anchor = $first->get_data()['currentRevision']`.
4. Assert the held document carries `OLD`. This proves the echo really sends it.

Out-of-band writes use the patterns already in this file:
- order meta: `wc_get_order( $id )->update_meta_data(...)` then `save_meta_data()`;
- line meta: `wc_update_order_item_meta( $line_id, ... )`.

### The tests

1. **`test_a_stale_echo_does_not_revert_hidden_third_party_order_meta`.**
   - Arrange: the common arrange with order meta `_tracking_number = 'OLD'`. Then set it to `'NEW'` out of band.
   - Act: push `edited_document( $held, 'second edit' )` anchored on `$anchor`.
   - Assert:
     - the push is 200;
     - `wc_get_order( $id )->get_meta( '_tracking_number' )` is `'NEW'`;
     - there is exactly one `_tracking_number` row on the order.
2. **`test_a_stale_echo_does_not_revert_hidden_third_party_line_meta`.** The same as 1, on the first line item's meta `_tracking_number`. Assert `wc_get_order_item_meta( $line_id, '_tracking_number', true )` is `'NEW'`, and the push is 200.
3. **`test_a_new_line_pushed_with_a_hidden_third_party_key_keeps_it`.**
   - Arrange: create 201.
   - Act: push an update whose document adds one new product line, with no `id` and the product id the create payload's first line uses. The new line carries meta `_x_addon = 'gift-wrap'`.
   - Assert:
     - the push is 200;
     - the order has the new line;
     - that line's `_x_addon` is `'gift-wrap'`.
4. **`test_a_hidden_third_party_key_new_to_the_order_is_written`.**
   - Arrange: create 201.
   - Act: push an update whose order `meta_data` adds `_x_new_key = 'v1'`, a key the order does not have.
   - Assert: the push is 200 and the order's `_x_new_key` is `'v1'`.
5. **`test_a_push_that_omits_a_hidden_third_party_key_does_not_delete_it`.**
   - Arrange: create 201. Out of band, set order meta `_tracking_number = 'KEEP'` and first-line meta `_tracking_number = 'KEEP'`.
   - Act: push `edited_document( $created, 'edit' )` anchored on the create's ack. That document predates the write, so it omits both keys.
   - Assert: the push is 200 and both values are still `'KEEP'`.
6. **`test_pushed_allow_listed_and_visible_meta_is_still_written`.**
   - Arrange: create 201.
   - Act: push one update, anchored on the create's ack, whose document changes all three of these from the create's values:
     - (a) the first line's `_woocommerce_pos_data` (allow-listed, hidden, existing): set a new JSON value, keeping the same entry `id` when the document carries one;
     - (b) a visible order meta key `gift_message` (new: `'hello'`);
     - (c) an order meta key `_x_site_key` that a `wcpos_sync_order_revision_meta_keys` filter adds to the allow-list, and that exists before the push (written out of band before the create's ack is read). Its pushed value differs.
   - Assert: the push is 200 and all three stored values equal the pushed ones. If (a) runs into money-line rules, use the hidden line key `_woocommerce_pos_tax_status` instead and say so in the report.
7. **`test_a_visible_third_party_order_meta_change_still_409s`.**
   - Arrange: the common arrange with visible order meta `gift_message = 'OLD'`. Then set it to `'NEW'` out of band.
   - Act: push `edited_document( $held, 'second edit' )` anchored on `$anchor`.
   - Assert: 409 `woo_rxdb_sync_conflict`. This is the 2.0.2 behaviour for visible meta.
8. **`test_pos_uuid_round_trips_on_create_replay_and_update`.**
   - Arrange: build the create envelope once. You may add an optional `?string $mutation_id = null` parameter to `push_order()` to replay it.
   - Act:
     - create, which is 201;
     - replay the identical envelope (same mutationId);
     - push an update `edited_document( $created, 'edit' )` anchored on the create's ack.
   - Assert:
     - the replay answers the same record id as the create, and its status is whatever this file's existing replay behaviour returns; read the controller and pin it;
     - after each step, the order's `_woocommerce_pos_uuid` is `self::RECORD_UUID` with exactly one row;
     - every line that the create payload sent with a `_woocommerce_pos_uuid` still holds that same value, with exactly one row.
9. **Keep green and unchanged:** `test_meta_written_after_every_push_no_longer_409s_the_next_push` (the POS-2VT acceptance; hidden third-party keys, 201 then 200s), `test_pos_owned_change_still_409s_a_stale_push`, the allow-list and excluded tests, and the 2.0.3 legacy tests.

## Do not

- Touch the hash recipe beyond 1a.
- Touch the legacy compare logic, `Write_Controller`, `Command_Log`, the pull, the proxy stamp or the 409 body.
- Add a hook, option, table, column, migration or recipe marker. The `revision_meta_keys()` method is the only new public symbol.
- Apply the write rule on create, in `for_partial_update()`, or to visible keys.
- Refactor or reformat code outside the change.
- Commit or push.

## Budget

- Max non-test lines changed: 80
- Max total lines changed: 420

If you are about to exceed either, STOP and report why. Never strip tests or comments to fit.

## Acceptance (from the worktree root; Bash timeout 700000 ms; foreground, one at a time)

wp-env is running from this worktree. Do not start or stop it. Do not use local composer or phpunit.

```sh
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --test-suffix .php tests/includes/Sync
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --test-suffix .php tests/includes/Payments
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --test-suffix .php tests/includes/API
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs includes/Sync/Order_Serializer.php includes/Sync/Order_Write_Payload.php
```

- **Baselines** at `f57288c8`: Sync is 1525 tests (1 skipped), Payments 770, API 2230.
- **Red first:** before any `includes/` edit, add the tests and run the file. Tests 1, 2 and 7 must fail; keep the failing lines. Then implement.

## Limits

- Wall clock: three hours. At the limit, stop and report partial state.
- One test command at a time, in the foreground. Never `run_in_background`.
- Never leave a file half-edited.

## Report

- STATUS
- CHANGED: files, with `git diff --stat`
- RED and GREEN outputs, trimmed to the summary and failure lines
- Every other test file you touched, and why
- The replay status you pinned in test 8, and where in the controller it comes from
- phpcs exit code
- NOT DONE
- QUESTIONS
