# Codex job spec — record and advertise the POS payments list (`_woocommerce_pos_payments`)

## Goal

TallyUI's WooCommerce transport will push split-tender orders to `POST /wcpos/v2/push/orders` with the
primary tender in `payment_method` and the full list in order meta `_woocommerce_pos_payments` (TallyUI v5
handoff §6). Make the plugin record that list as a validated, write-once till audit key (the same treatment
as `_pos_cash_amount_tendered`), and advertise support as the named capability `order_payments_list` on
`GET /wcpos/v2/status`. Display (receipt, admin) is a separate later job: not here.

## Stakes

Money audit trail on POS orders. An invalid list must never be stored (it would be shown on receipts later),
and a later update must never rewrite a recorded list. An invalid list is dropped, not refused: the till has
already taken the money, and refusing the push would strand a paid order (the existing till-key rule).

## In scope (the only files you may edit)

- `includes/Services/Pos_Payments.php` (new)
- `includes/Services/Pos_Order_Audit.php`
- `includes/API/V2/Status_Controller.php`
- `tests/includes/Services/Pos_Payments_Test.php` (new)
- `tests/includes/Services/Pos_Order_Audit_Test.php`
- `tests/includes/Sync/Test_Rest_Dispatch_Till_Meta.php`
- `tests/includes/Sync/Test_Sync_Status.php`

## Out of scope (do not edit)

- `includes/API/V2/Writers/Order_Writer.php`, `includes/Sync/Mutation_Store.php`, `includes/API/V1/Orders_Controller.php`
  (they already route every till key through `Pos_Order_Audit`; no change is needed there)
- `includes/Services/Receipt_Data_Builder.php`, `includes/Admin/**`, `includes/Gateways/**` (display is a later job)
- `tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php`, every other file
- Lockfiles, composer files, CI config, docs, changelog

## Context

- `includes/Services/Pos_Order_Audit.php` is the single authority for order audit meta. `TILL_META_KEYS`
  (line 36) are client-sourced, validated, write-once keys. `till_meta_from_payload()` (around line 160) is the
  one parser: last entry wins, invalid values dropped, values cast with `(string)`. `is_valid_till_value()`
  (around line 190) rejects non-scalars first. Callers (v2 `Order_Writer::prepare_create` and
  `stamp_order_audit_meta`, v1 `sanitize_create_meta`) strip every audit key from the wc/v3 forward and then
  fill the validated till values with `Mutation_Store::persist_order_audit_meta`, which writes `(string) $value`
  and only when the key is missing (write-once). Update forwards strip every audit key (`strip_audit_meta`);
  only `cash_meta_keys()` may be filled on update. Do NOT add the new key to `CASH_META_KEYS`: the list is
  recorded on create only.
- `includes/API/V2/Status_Controller.php:24-32`: the `CAPABILITIES` list and its docblock ("Append new names;
  never rename or remove one").
- Tests: `tests/includes/Services/Pos_Order_Audit_Test.php` (WP_UnitTestCase, Arrange/Act/Assert);
  `tests/includes/Sync/Test_Rest_Dispatch_Till_Meta.php` has `create_order( array $till_meta, array $payload )`
  (asserts 201; returns order_id, document, revision), `push_envelope( $operation, $payload, $base_revision )` and
  `meta_entries( array $map )`; model new push tests on
  `test_till_meta_create_with_values_update_with_different_values_preserves_created_values` (line ~280).
  `tests/includes/Sync/Test_Sync_Status.php` asserts the full status body twice (lines ~165 and ~187) with
  `'capabilities' => array( 'products_id_fast_path' )`, and has
  `test_sync_status_lists_the_products_id_fast_path_capability` (line ~357).

## Interfaces and constraints

### `includes/Services/Pos_Payments.php` (new)

Namespace `WCPOS\WooCommercePOS\Services`, `final class Pos_Payments`, with a class docblock explaining: the list of
tenders a till took for one order (split tender), recorded write-once at create as a till audit key through
`Pos_Order_Audit`; `payment_method` holds the primary tender; written by TallyUI's WooCommerce transport (TallyUI
v5 handoff §6).

- `public const META_KEY = '_woocommerce_pos_payments';`
- `private const MAX_PAYMENTS = 20;` with a comment (a sanity bound on one order's tenders, not a business rule).
- `public static function normalize( $value ): ?string`
  - Accepts a JSON string that decodes (`json_decode( $value, true )`) to an array, or a native PHP array.
    Anything else returns null.
  - The decoded value must be a non-empty LIST (`array_values( $list ) === $list`) of at most `MAX_PAYMENTS` entries.
  - Each entry must be an array with:
    - `method`: string matching `/^[a-z0-9_-]{1,64}$/i` (a gateway id such as `pos_cash`, `pos_card`);
    - `title`: string; after `sanitize_text_field()` it is 1–255 characters;
    - `amount`: an amount (see below);
    - optional `reference`: string; after `sanitize_text_field()` at most 255 characters; omitted from the
      output when absent or empty;
    - optional `tendered`, `change`: amounts; omitted from the output when absent.
    - Any other key is dropped.
  - An amount is a scalar whose `(string)` form matches `/^\d+(?:\.\d+)?$/` (the rule `is_valid_till_value`
    uses for cash amounts); output it as that string.
  - If ANY entry is invalid, return null (never a partial list).
  - Return `wp_json_encode( $normalized )` where each entry's keys are in the order
    `method, title, amount, reference, tendered, change` (absent optional keys left out).
- `public static function from_order( $order ): array`: when `$order` is an object with `get_meta`, read
  `get_meta( self::META_KEY, true )`, run `normalize()` on it, and return the decoded list (array of entry
  arrays); return `array()` when absent or invalid. (The later display job uses this.)

### `includes/Services/Pos_Order_Audit.php`

- Append `Pos_Payments::META_KEY` to `TILL_META_KEYS` (same namespace, no `use` needed); extend the docblock
  above it with one line saying the payments list is recorded on create only (not a cash key, so never filled
  on update).
- `is_valid_till_value()`: as its FIRST check, `if ( Pos_Payments::META_KEY === $key ) { return null !== Pos_Payments::normalize( $value ); }`.
  Update its docblock with one sentence.
- `till_meta_from_payload()`: store `Pos_Payments::normalize( $client[ $key ] )` for the payments key (the
  canonical JSON string), `(string) $client[ $key ]` for every other key as now. Keep the `@return array<string, string>`.

### `includes/API/V2/Status_Controller.php`

- `CAPABILITIES` becomes `array( 'products_id_fast_path', 'order_payments_list' )`. Add a docblock bullet:
  "`order_payments_list`: an order create records the `_woocommerce_pos_payments` meta (a JSON list of
  tenders; `payment_method` is the primary tender), validated and write-once (Services\Pos_Payments)."

## Tests to write

`tests/includes/Services/Pos_Payments_Test.php` (new; namespace `WCPOS\WooCommercePOS\Tests\Services`,
`class Pos_Payments_Test extends WP_UnitTestCase`). Use this fixture list:
`[ { method: 'pos_card', title: 'Card', amount: '20.00', reference: 'auth-1' }, { method: 'pos_cash', title: 'Cash', amount: '19.00', tendered: '20.00', change: '1.00' } ]`
whose canonical form is exactly
`[{"method":"pos_card","title":"Card","amount":"20.00","reference":"auth-1"},{"method":"pos_cash","title":"Cash","amount":"19.00","tendered":"20.00","change":"1.00"}]`.

1. `test_normalize_returns_canonical_json_for_a_json_string_list`: the fixture as a JSON string, with keys in a
   different order and an extra `"foo":"bar"` key on the first entry → `assertSame` the canonical string.
2. `test_normalize_accepts_a_native_array_list`: the fixture as a PHP array → the canonical string.
3. `test_normalize_rejects_invalid_lists` with a `@dataProvider invalid_lists_provider` returning named cases, each
   asserting `assertNull( Pos_Payments::normalize( $value ) )`: `'not json'`; `'{}'` decoded empty; `array()`;
   an associative array `array( 'a' => <valid entry> )`; 21 valid entries; an entry missing `method`; method
   `'pos cash'`; empty title `''`; amount `'-1.00'`; amount `'1e3'`; amount `'10,50'`; tendered `'x'`; a
   non-array entry `'pos_cash'`; an integer `5`; null. (Keep each case a single valid entry with one fault.)
4. `test_from_order_returns_the_list_or_empty`: an order (`wc_create_order()`) with the canonical meta saved returns
   a 2-entry list whose `[1]['change']` is `'1.00'`; an order with meta `'garbage'` returns `array()`.

`tests/includes/Services/Pos_Order_Audit_Test.php` (add):

5. `test_till_meta_from_payload_normalizes_the_payments_list`: meta entries with `_woocommerce_pos_payments` = the
   fixture as a JSON string with an extra key → result `assertSame( array( '_woocommerce_pos_payments' => <canonical> ), ... )`.
6. `test_strip_audit_meta_removes_the_payments_list`: `strip_audit_meta()` on entries
   `_woocommerce_pos_payments` + `note` keeps only `note`.
7. Extend `test_is_valid_till_value_rules` with: the canonical string is valid; `'[]'` is invalid;
   `array( 'x' )` is invalid.

`tests/includes/Sync/Test_Rest_Dispatch_Till_Meta.php` (add; use the file's helpers):

8. `test_payments_list_create_records_canonical_list_and_primary_method`: `create_order( array( '_woocommerce_pos_payments' => <fixture JSON with an extra key> ), array( 'status' => 'completed', 'set_paid' => true, 'payment_method' => 'pos_card', 'payment_method_title' => 'Card' ) )`.
   Assert the stored meta `assertSame( <canonical>, $order->get_meta( '_woocommerce_pos_payments' ) )`,
   `assertSame( 'pos_card', $order->get_payment_method() )`, `assertTrue( $order->is_paid() )`.
9. `test_payments_list_update_with_different_list_preserves_created_list`: create with the canonical list, then
   `push_envelope( 'update', array( 'meta_data' => meta_entries( array( '_woocommerce_pos_payments' => <a different valid one-entry list> ) ) ), $created['revision'] )`;
   assert 200 and the stored meta still equals the canonical string.
10. `test_payments_list_invalid_on_create_is_not_recorded`: create with `_woocommerce_pos_payments` =
    `'[{"method":"pos_cash","title":"Cash","amount":"-5"}]'`; assert `assertSame( '', $order->get_meta( '_woocommerce_pos_payments' ) )`.

`tests/includes/Sync/Test_Sync_Status.php`:

11. Update the two full-body assertions to `'capabilities' => array( 'products_id_fast_path', 'order_payments_list' )`.
12. Add `test_sync_status_lists_the_order_payments_list_capability`, a copy of the fast-path capability test
    asserting `assertContains( 'order_payments_list', $capabilities )`.

## Do not

- Add the key to `CASH_META_KEYS`, or fill it on update
- Refuse (4xx) a push for an invalid list, validate that payments sum to the order total, or touch `payment_method`
- Edit `Order_Writer`, `Mutation_Store`, the v1 controller, receipts, admin or gateways
- Add a `/tally/v1/info` endpoint or any other negotiation surface
- Add dependencies, feature flags, filters/hooks, plan or scratch files
- Commit or push
- Read a GitHub issue, PR or comment raw or paste a stranger's words into code, tests or fixtures

## Pre-authorised actions

- Edit and create the in-scope files
- Run the acceptance commands below

## Budget

- Max non-test lines changed: 140
- Max total lines changed: 480

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target: if the work is about to exceed it,
stop and report the overrun and its reason under NOT DONE. Never strip
tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
php -l includes/Services/Pos_Payments.php
php -l includes/Services/Pos_Order_Audit.php
php -l includes/API/V2/Status_Controller.php
php -l tests/includes/Services/Pos_Payments_Test.php
php -l tests/includes/Services/Pos_Order_Audit_Test.php
php -l tests/includes/Sync/Test_Rest_Dispatch_Till_Meta.php
php -l tests/includes/Sync/Test_Sync_Status.php
```

### Reviewer reruns (not for Codex)

PHPUnit and phpcs run only in Docker/wp-env, which the Codex sandbox cannot reach. Write the tests to the exact
expectations above; the reviewer runs:

```sh
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Pos_Payments_Test.php
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Pos_Order_Audit_Test.php
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Till_Meta.php
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Sync_Status.php
vendor/bin/phpcs <changed files>
```

Code style: WordPress Coding Standards per `.phpcs.xml.dist`: tabs, `array( ... )`, spaces inside parentheses,
and a multi-item associative array declares one key per line. Use "WCPOS" in comments, never "WooCommerce POS".

## Environment

- Network: no
- Writable outside the worktree: none
- Host `php` for `php -l` only; nothing to install

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES). If the spec is
ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
