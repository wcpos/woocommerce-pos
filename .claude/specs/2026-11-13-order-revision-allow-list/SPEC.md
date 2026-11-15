# Spec: the order revision hashes an allow-list of POS-owned meta (POS-2VT)

## Goal

Paul approved this on 2026-11-13. `Order_Serializer::canonical_form()` should hash only what the POS owns:
- WC's core order fields, as today;
- line items, with their POS-relevant meta;
- an allow-list of POS-owned order meta keys.

A third-party plugin writing order meta, or hidden line meta, must no longer change the order revision, so it can no longer 409 a till's push. POS-2VT is the live case: meta written after every push parks the order and fails the sale.

Upgrade: a till that still holds a revision acked by 2.0.3 must not get a 409 just because the recipe changed.

Findings this spec rests on: `~/agent/handoff/sentry-watch-2026-11-10/findings/revision-hash-scope.md`.

## Stakes

Core plugin, order sync and money CAS. Two failures matter here:
- a 409 that should not happen, which parks an order and fails a sale;
- a missing 409, which lets a stale till overwrite a real POS change.

Every compare change below must be strictly no more permissive than "the old recipe said equal, or the new recipe says equal".

## In scope (the only files you may edit)

- `includes/Sync/Order_Serializer.php`
- `includes/API/V2/Write_Controller.php`, at the two compare sites only
- `includes/Payments/Contract/Command_Log.php`, at the one compare site only
- `tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php`
- `tests/includes/Sync/Test_Order_Revision_Recipe.php`
- `tests/includes/Payments/Contract/Test_Command_Log.php`
- Any other file under `tests/includes/`, under one condition only: an existing test there fails because it used a non-allow-listed order meta key, or a hidden line meta key, to move the revision. Change only that key, to `_pos_store` (order) or `_woocommerce_pos_data` (line), and list every such file and test in your report. Never weaken or delete an assertion.

## Out of scope

- `includes/Sync/Revision.php`, `Order_Writer.php`, `Order_Pull_Planner.php` and every other `includes/` file.
- Docs, ADRs, `README.md`, the changelog and the version header.

## The change

### 1. `includes/Sync/Order_Serializer.php`

**a) The allow-list constant.** Add `private const REVISION_META_KEYS`, with a comment saying these are the order meta keys the till asserts or the server's money contract owns. The comment must also say this list applies to `_`-prefixed line meta too: line keys without a leading `_` are always hashed. In this order:

- **Till-asserted:**
  - `_pos_user`, `_pos_store`, `_wcpos_register`, `_wcpos_till`
  - `_wcpos_sale_time`, `_wcpos_sale_tz`, `_wcpos_sale_counter`, `_wcpos_session`, `_wcpos_app_version`, `_wcpos_app_build`
  - `_pos_cash_amount_tendered`, `_pos_cash_change`, `_pos_card_cashback`
  - `_wcpos_split`, `_wcpos_paid_units`, `_wcpos_quick_discount`, `_woocommerce_pos_tax_based_on`, `_wcpos_money_owner_hint`, `is_vat_exempt`
  - `_wcpos_tax_ids`, `_wcpos_tax_ids_owned_keys`, `_wcpos_tax_ids_verified`
- **Server money:**
  - `_wcpos_payments`, `_wcpos_commands`, `_wcpos_completions`, `_wcpos_completed_at`
  - `_wcpos_payment_live`, `_wcpos_gateway_attempts`, `_wcpos_awaiting_customer`, `_wcpos_checkout_state`
- **POS line meta:** `_woocommerce_pos_data`, `_woocommerce_pos_tax_status`, `coupon_info`, `_wcpos_tip_payment_id`

**b) The new filter.** Inside the form computation, filter the list with a new hook. It returns what is kept, so a site can also remove a key. Use the WordPress docblock style of the neighbouring filters:

```php
/**
 * Filters the meta keys the canonical order revision hashes.
 *
 * The revision hashes these order meta keys and nothing else, so another plugin's
 * order meta never 409s a till. On every line it hashes these `_`-prefixed keys
 * plus every key without a leading `_` (attributes and other displayed line meta).
 * Add a key to bring site-local meta into CAS. Read the coherence contract above first.
 *
 * @since 2.0.3
 *
 * @param string[] $keys Meta keys inside the hash.
 */
$allowed = array_map( 'strval', (array) apply_filters( 'wcpos_sync_order_revision_meta_keys', self::REVISION_META_KEYS ) );
```

**c) Split the form.** Move today's body of `canonical_form()` into `private static function form( array $payload, bool $allow_list ): array`.
- Keep `canonical_form( array $payload ): array` public, with the same signature, returning `self::form( $payload, true )`.
- In `form()`, when `$allow_list` is true:
  - **Order meta:** a row is kept only when its key is in `$allowed`, AND it is not in the #2663 excluded keys, AND it is not one of the three `_wp_trash_meta_*` keys. The order uuid strip stays as is.
  - **Line meta**, in `strip_item_identity_meta()`: pass `$allowed` as a third parameter, `?array $allowed`, which is `null` for the legacy form. A row is kept only when its key is not `_woocommerce_pos_uuid`, AND it is not excluded, AND (`null === $allowed`, OR the key does not start with `_`, OR the key is in `$allowed`).
- When `$allow_list` is false, `form()` must produce exactly today's array, byte for byte. That is the 2.0.3 recipe, #2663 exclusions included.
- **Excluded wins:** a key that both lists name is left out.

**d) The legacy revision.** Add `private static function legacy_revision( array $payload ): string`, which returns `'sha256:' . hash( 'sha256', (string) wp_json_encode( self::form( $payload, false ) ) )`.

**e) The compare.** Add:

```php
/**
 * The stale-write facts for an order, or null when the claimed revision is current.
 *
 * A claimed revision is also current when it equals the 2.0.3 recipe (every meta row
 * hashed) of the same document: a till that acked an order before the allow-list
 * shipped then gets no 409 for the recipe change alone. The old form is the new form
 * plus meta rows, so an equal old hash implies an equal new one; this never accepts a
 * write the old compare refused. Transition only: remove in 2.2 at the earliest.
 *
 * @param string $claimed          The revision the caller built its write against.
 * @param string $current_revision The order's current revision, from canonical_revision().
 * @param array  $document         The document $current_revision was hashed from.
 * @return null|array{currentRevision: string, current: array}
 */
public static function stale( string $claimed, string $current_revision, array $document ): ?array {
	$facts = Revision::stale( $claimed, $current_revision, $document );
	if ( null === $facts || ! hash_equals( self::legacy_revision( $document ), $claimed ) ) {
		return $facts;
	}
	return null;
}
```

Import `Revision` if the file does not already do so.

**f) Docblocks.**
- Update `canonical_form()`'s docblock in place. Its meta paragraph must say that order meta is hashed from the allow-list only, and that line meta is hashed except hidden keys outside the list.
- Keep the coherence contract paragraph.
- Change nothing else in the file.

### 2. `includes/API/V2/Write_Controller.php`

At lines 492 and 564, replace

```php
Revision::stale( $m['baseRevision'], $this->revision_for( $meta, $id, $current_bare ), $current_bare )
```

with

```php
$this->stale_for( $meta, $id, $m['baseRevision'], $current_bare )
```

Add, next to `revision_for()`:

```php
/** The stale-write facts for a record; orders accept a 2.0.3-recipe base too ({@see Order_Serializer::stale()}). */
private function stale_for( array $meta, int $id, string $claimed, array $bare ): ?array {
	$current = $this->revision_for( $meta, $id, $bare );
	if ( 'product_variation' !== ( $meta['post_type'] ?? '' ) && 'order' === ( $meta['id_type'] ?? '' ) && $id > 0 ) {
		return Order_Serializer::stale( $claimed, $current, $bare );
	}
	return Revision::stale( $claimed, $current, $bare );
}
```

- The condition must mirror `revision_for()`'s order branch exactly.
- No other change in this file.

### 3. `includes/Payments/Contract/Command_Log.php`

- At line 261, replace `Revision::stale(` with `Order_Serializer::stale(`. The arguments stay the same.
- Keep the `use … Revision;` import, because line 49 still uses `Revision::canonicalize`.

## Tests

Every new or rewritten test follows the Arrange / Act / Assert layout, uses `assertSame( expected, actual )`, and has a one-line docblock.

### `tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php`

Reuse the file's helpers: `push_order()`, `create_payload()`, `edited_document()` and `add_third_party_writer()`.

1. **Rename and invert** `test_meta_written_after_every_push_409s_the_reanchored_repush_again` to `test_meta_written_after_every_push_no_longer_409s_the_next_push`. This is the POS-2VT acceptance.
   - Register `add_third_party_writer( false )` and `add_third_party_writer( true )`.
   - The create is 201.
   - Three updates follow, each anchored on the previous ack's `currentRevision`, and each is 200.
2. **Rewrite** `test_meta_key_not_excluded_still_409s_a_stale_push` as `test_pos_owned_change_still_409s_a_stale_push`. After a 201 create and a 200 update, change each of these in turn, out of band. Each time, assert that an update anchored on the last good ack is 409 `woo_rxdb_sync_conflict`, then re-anchor on that 409's `currentRevision` for the next case:
   - (a) order meta `_pos_store`;
   - (b) line meta `_woocommerce_pos_data`;
   - (c) a visible line meta key `Size`;
   - (d) a core field: `set_customer_note( 'moved' )` plus `save()`;
   - (e) a line item quantity.
3. **New** `test_allow_list_filter_brings_a_meta_key_into_the_revision`.
   - Filter `wcpos_sync_order_revision_meta_keys` to add `_x_third_party_stamp`.
   - Register `add_third_party_writer( false )`.
   - After the 201 create, the update is 409.
4. **New** `test_excluded_meta_key_wins_over_the_allow_list`.
   - Filter `wcpos_sync_order_revision_excluded_meta_keys` to return `array( '_pos_store' )`.
   - After a 201 create, move `_pos_store` out of band.
   - The update anchored on the create's ack is 200.
5. **Leave** `test_excluded_order_and_line_meta_keys_written_after_every_push_no_longer_409` unchanged.
6. **New** `test_a_2_0_3_ack_is_accepted_after_the_upgrade`. This is how old acks compare.
   - **Arrange.**
     - Create (201).
     - Out of band, add order meta `_wc_order_attribution_source_type = 'typein'` and hidden line meta `_x_hidden_line = '1'` on the first line item, then save.
     - Add a closure `$old` on `wcpos_sync_order_revision_meta_keys` that appends both keys. For this order, the new recipe plus `$old` hashes exactly what 2.0.3 hashed. That is the till's ack from before the upgrade.
     - Set `$legacy = Order_Serializer::current_state( $id )['revision']`, read while `$old` is active.
     - `remove_filter` `$old`. This is the upgrade.
     - Assert that `$legacy` is not the same as `Order_Serializer::current_state( $id )['revision']`. This proves the recipes differ, so the test is not vacuous.
   - **Act.** Push an update with a note edit (`edited_document()` on the create's response), anchored on `$legacy`.
   - **Assert.** It is 200.
7. **New** `test_a_2_0_3_ack_after_a_pos_owned_change_still_409s`.
   - Use the same arrange as 6, up to `$legacy` and the `remove_filter`.
   - Then move `_pos_store` out of band.
   - The update anchored on `$legacy` is 409 `woo_rxdb_sync_conflict`.
   - If 6 and 7 share their arrange through a private helper, that is fine.

### `tests/includes/Sync/Test_Order_Revision_Recipe.php`

- At line 371, change the order probe key `wcpos_probe` to `_pos_store`, so the resave test still covers order meta.
- Keep the line key `wcpos_item_probe`: a line key without `_` is still hashed.

### `tests/includes/Payments/Contract/Test_Command_Log.php`

Model the new test on `test_run_with_a_fresh_id_and_stale_revision_is_409_and_runs_nothing()` (line 220) and its helpers.

8. **New** `test_run_built_on_a_2_0_3_revision_is_not_stale`.
   - Add order meta `_wc_order_attribution_source_type`.
   - Compute `$legacy = $this->current_revision( $id )` while a filter adds that key to `wcpos_sync_order_revision_meta_keys`, then remove the filter.
   - Assert that `$legacy` is not the same as `$this->current_revision( $id )`.
   - Run a fresh command built on `$legacy`. Assert it is not a `wcpos_revision_stale` error, and that the work ran once.

## Do not

- Add a recipe version marker, an option, a migration, or a new table, column or option.
- Touch `Revision.php`, the pull, the proxy stamp, the journal, or the 409 body shape.
- Remove or rename `wcpos_sync_order_revision_excluded_meta_keys` or `wcpos_sync_order_revision_fields`.
- Add a `woocommerce_pos_` alias for the new filter. It is new, so it gets none.
- Refactor or reformat code outside the change.
- Commit or push.

## Budget

- Max non-test lines changed: 140
- Max total lines changed: 480

If you are about to exceed either, STOP and report why. Never strip tests or comments to fit.

## Acceptance (from the worktree root; Bash timeout 700000 ms; foreground, one at a time)

wp-env is running from this worktree. Do not start or stop it. Do not use local composer or phpunit.

```sh
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Repush_Idempotency.php
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Payments/Contract/Test_Command_Log.php
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --test-suffix .php tests/includes/Sync
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --test-suffix .php tests/includes/Payments
~/.claude/bin/test-slot-run --wait 600 pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist --test-suffix .php tests/includes/API
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs includes/Sync/Order_Serializer.php includes/API/V2/Write_Controller.php includes/Payments/Contract/Command_Log.php
```

- **Baseline** at the base commit: the Sync suite is `OK, Tests: 1521, Assertions: 13370, Skipped: 1`.
- **Red first:** before any `includes/` edit, run tests 1, 3, 4, 6 and 8 and keep the failing lines. Then implement.

## Limits

- Wall clock: three hours. At the limit, stop and report partial state.
- One test command at a time, in the foreground. Never `run_in_background`.
- Never leave a file half-edited.

## Report

- STATUS
- CHANGED: files, with `git diff --stat`
- RED and GREEN outputs, trimmed to the summary and failure lines
- Every other test file you touched, and why
- phpcs exit code
- NOT DONE
- QUESTIONS
