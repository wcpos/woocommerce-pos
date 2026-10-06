# Codex job spec — `order_create_v5` capability on `GET /wcpos/v2/status`

## Goal

`GET /wcpos/v2/status` → `capabilities` gains the name `order_create_v5`, appended after
`order_payments_list`. TallyUI's WooCommerce connector reads it to decide whether this store takes
TallyUI `order.create` v5 payloads on `POST /wcpos/v2/push/orders` (fee lines, shipping lines, custom
`product_id: 0` lines, idempotent on `mutationId`; pinned by
`tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php`, #2137). Today it has no signal.

## Stakes

Additive name in a public list; no behaviour change. The list is append-only by contract: never rename,
reorder or remove an existing name.

## In scope (the only files you may edit)

- `includes/API/V2/Status_Controller.php`
- `tests/includes/Sync/Test_Sync_Status.php`
- `CHANGELOG.md`

## Out of scope (do not edit)

- `includes/API/V2/Push_Controller.php`, `includes/Services/*`, every other file
- Lockfiles, CI config, docs

## Context

- `includes/API/V2/Status_Controller.php:34`:
  `private const CAPABILITIES = array( 'products_id_fast_path', 'order_payments_list' );`
  Its docblock (lines ~20–33) has one bullet per name, then "Append new names; never rename or remove one."
- `tests/includes/Sync/Test_Sync_Status.php` asserts the exact list at lines 165 and 187
  (`'capabilities'   => array( 'products_id_fast_path', 'order_payments_list' ),`), and has
  `test_sync_status_lists_the_order_payments_list_capability()` at line ~374 as the last method.
- `CHANGELOG.md` has a `## Unreleased` section; newest entry first.

## Interfaces and constraints

1. `Status_Controller::CAPABILITIES` becomes
   `array( 'products_id_fast_path', 'order_payments_list', 'order_create_v5' )`.
2. Add a docblock bullet after the `order_payments_list` bullet, same style:
   ```
    * - `order_create_v5`: `POST /wcpos/v2/push/orders` takes TallyUI order.create v5 creates mapped to the
    *   WooCommerce shape: `fee_lines`, `shipping_lines` (`method_id` `pos` allowed) and custom `product_id: 0`
    *   lines priced and taxed from `_woocommerce_pos_data`, idempotent on `mutationId` (#2137).
   ```
3. In `Test_Sync_Status.php`, change both exact-list assertions (lines 165 and 187) to
   `array( 'products_id_fast_path', 'order_payments_list', 'order_create_v5' )`.
4. Add a test method after `test_sync_status_lists_the_order_payments_list_capability()`, a copy of it
   with these differences only:
   - name `test_sync_status_lists_the_order_create_v5_capability`
   - docblock `Status advertises the v5 order-create format even while the store is unhealthy.`
   - final assertion `$this->assertContains( 'order_create_v5', $capabilities );`
5. `CHANGELOG.md`: add as the first bullet under `## Unreleased`:
   `- Added (developers): \`GET /wcpos/v2/status\` lists the \`order_create_v5\` capability, so a TallyUI till can tell that \`POST /wcpos/v2/push/orders\` takes its order.create v5 creates (fee, shipping and custom lines).`

## Do not

- Reorder or remove existing capability names
- Touch any other test, method or file
- Refactor or reformat code outside the change
- Create plan, notes or scratch files
- Commit or push

## Pre-authorised actions

- Edit the in-scope files listed above
- Run the acceptance commands below

## Budget

- Max non-test lines changed: 10
- Max total lines changed: 35

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target: if the work is about to exceed it,
stop and report the overrun and its reason under NOT DONE. Never strip
tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
php -l includes/API/V2/Status_Controller.php
php -l tests/includes/Sync/Test_Sync_Status.php
grep -c "array( 'products_id_fast_path', 'order_payments_list', 'order_create_v5' )" includes/API/V2/Status_Controller.php   # prints 1
grep -c "array( 'products_id_fast_path', 'order_payments_list', 'order_create_v5' )" tests/includes/Sync/Test_Sync_Status.php   # prints 2
grep -c "function test_sync_status_lists_the_order_create_v5_capability" tests/includes/Sync/Test_Sync_Status.php   # prints 1
```

### Reviewer reruns (not for Codex)

```sh
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Sync_Status.php   # in wp-env tests-cli
vendor/bin/phpcs includes/API/V2/Status_Controller.php
```

## Environment

- Network: no
- Writable outside the worktree: none
- No test suite runs in acceptance (pass `--no-test-slot`)

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES). If the spec is
ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
