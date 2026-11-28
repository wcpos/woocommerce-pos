# Codex job spec — coupon types 4: a veto never answers with a code core emits

## Goal

The front desk has ruled (28 Nov 2026, on PR #2859): a `wcpos_payment_pre_capture` refusal may never answer with an error code that core itself emits on the payments routes. The till re-sends some of those codes (`wcpos_revision_stale`, `wcpos_payment_locked`, …), so a veto carrying one of them would be resent instead of shown to the cashier.

Today `Payment_Veto::check()` keeps any callback code that starts with `wcpos_`. After this job, a callback code that is in a new constant of reserved codes becomes `wcpos_payment_vetoed`, exactly like a code without the `wcpos_` prefix. The callback's message, `detail` and every other part of the answer stay as they are.

The same job also corrects one docblock sentence about the order lock.

## Stakes

High tier: a money route on the core plugin. The change is limited to which code a refusal carries. Nothing else in the refusal or in the allow path changes. With no callback registered, nothing changes, and the golden test `tests/includes/API/V2/Test_Payments_No_Veto_Golden.php` must keep passing.

| Row | Situation | Required answer | Test |
|---|---|---|---|
| resendable, prefixed | The callback refuses with `wcpos_revision_stale` or `wcpos_payment_locked` | 409 `wcpos_payment_vetoed`, `vetoed: true`, the callback's message | the new data-provider test |
| resendable, unprefixed | The callback refuses with `woo_rxdb_sync_record_locked` | 409 `wcpos_payment_vetoed` | the new data-provider test |
| core-emitted, not resendable | The callback refuses with `wcpos_order_already_paid` or `wcpos_payment_conflict` | 409 `wcpos_payment_vetoed` | the new data-provider test |
| free extension code | The callback refuses with `wcpos_store_credit_insufficient` | 409 `wcpos_store_credit_insufficient` | the new pass-through test |
| no callback | Nothing registered | Unchanged answers | `Test_Payments_No_Veto_Golden` (existing, unchanged) |

## In scope (the only files you may edit)

- `includes/Payments/Contract/Payment_Veto.php`
- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`

## Out of scope (do not edit)

- Everything else. In particular `includes/Payments/Contract/Command_Log.php`, `includes/API/V2/Payments_Controller.php`, every other file under `includes/`, `tests/fixtures/payments-no-veto-golden.json`, `tests/includes/API/V2/Test_Payments_No_Veto_Golden.php`, and the CHANGELOG.

## Context

- `includes/Payments/Contract/Payment_Veto.php`: `KINDS`, the filter docblock inside `check()`, and the line that builds `$code`.
- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`: the helpers `refuse()`, `record()`, `payment()` and `assert_veto()`; the existing test `test_pre_capture_unprefixed_code_uses_default_code_and_keeps_message` is the model to copy; `non_error_values()` is the model for a data provider.
- The till's list, which must be copied literally: `packages/sync-core/src/woo/moneyCommandAnswer.ts` on `wcpos/monorepo` main at `a84fd1bfd99b16a554cba7166abb7700fcac978c`, constant `RESENDABLE_MONEY_CODES`:
  `'wcpos_payment_locked'`, `'wcpos_revision_stale'`, `'woo_rxdb_sync_record_locked'`.
  Do not import, read or fetch anything from that repository; the three strings above are the whole of it.

## Interfaces and constraints

1. Add a public constant to `Payment_Veto`, directly after `KINDS`:

   ```php
   public const RESERVED_CODES = array( … );
   ```

   It holds exactly these strings, in two groups, each sorted alphabetically, with a comment above each group:

   - Group 1, comment: `// The till re-sends these: monorepo packages/sync-core/src/woo/moneyCommandAnswer.ts RESENDABLE_MONEY_CODES (main a84fd1bf, 28 Nov 2026). Keep in step with that list.`
     `'wcpos_payment_locked'`, `'wcpos_revision_stale'`, `'woo_rxdb_sync_record_locked'`.
   - Group 2, comment: `// Codes core itself answers on the payments routes (includes/Payments, Payments_Controller).`
     `'wcpos_amount_exceeds_balance'`, `'wcpos_amount_mismatch'`, `'wcpos_balance_outstanding'`, `'wcpos_capture_mode_conflict'`, `'wcpos_capture_mode_not_allowed'`, `'wcpos_capture_mode_unsupported'`, `'wcpos_command_conflict'`, `'wcpos_fields_invalid'`, `'wcpos_idempotency_conflict'`, `'wcpos_idempotency_in_progress'`, `'wcpos_invalid_transition'`, `'wcpos_method_not_recordable'`, `'wcpos_order_already_paid'`, `'wcpos_order_not_found'`, `'wcpos_payment_conflict'`, `'wcpos_payment_in_flight'`, `'wcpos_payment_method_disabled'`, `'wcpos_payment_method_not_found'`, `'wcpos_payment_not_correctable'`, `'wcpos_payment_not_found'`, `'wcpos_provider_error'`, `'wcpos_refund_not_allocatable'`, `'wcpos_rest_cannot_edit_order'`, `'wcpos_revision_required'`, `'wcpos_settlement_not_saved'`, `'wcpos_tender_correction_unavailable'`.

   Give the constant a one-line docblock: `/** Codes a refusal may never carry: the till's re-send list and core's own payment answers. Such a code becomes wcpos_payment_vetoed. */`

2. Replace the `$code` line in `check()` so that a code is kept only when it starts with `wcpos_` AND is not in `self::RESERVED_CODES` (strict `in_array`). Otherwise the code is `'wcpos_payment_vetoed'`. Keep `0 === strpos(...)`, since the PHP floor is 7.4.

3. In the filter docblock inside `check()`, change exactly two sentences:
   - Replace the line `Runs under the order lock; do not take it again (Order_Lock is a lease, not reentrant).` with `Runs under the order lock, so the callback has no need to take it.`
   - Replace `the code is the callback's when it starts with `wcpos_`, else `wcpos_payment_vetoed`;` with `the code is the callback's when it starts with `wcpos_` and is not one of RESERVED_CODES, else `wcpos_payment_vetoed`;`. Re-wrap the comment lines only as far as the line length needs.

4. In `tests/includes/API/V2/Test_Payment_Pre_Capture.php`, add two tests directly after `test_pre_capture_unprefixed_code_uses_default_code_and_keeps_message`, plus one static data provider:

   - `test_pre_capture_reserved_code_uses_default_code_and_keeps_message( string $code )`, with `@dataProvider reserved_codes`. Arrange: `create_pos_order()`, then `refuse( array( 'record' ), $code, 'Credit changed.' )`. Act: `record( $order, payment( 'pos_cash', '50.00' ) )`. Assert: `assert_veto( $response, 'wcpos_payment_vetoed' )`, and the message is `'Credit changed.'`.
   - `reserved_codes()` returns one row per code class, keyed by name:
     - `'resendable stale' => array( 'wcpos_revision_stale' )`
     - `'resendable locked' => array( 'wcpos_payment_locked' )`
     - `'resendable unprefixed' => array( 'woo_rxdb_sync_record_locked' )`
     - `'core already paid' => array( 'wcpos_order_already_paid' )`
     - `'core conflict' => array( 'wcpos_payment_conflict' )`
   - `test_pre_capture_free_wcpos_code_passes_through()`: the same arrange and act with code `'wcpos_store_credit_insufficient'`. Assert `assert_veto( $response, 'wcpos_store_credit_insufficient' )` and the message.

   Each new test gets a one-line docblock, as the neighbouring tests have. Assertions are `( expected, actual )` and use `assertSame`.

## Do not

- Change the prefix rule for codes outside the list, the default message, `detail`, the 409 status, the `vetoed` flag, `KINDS`, or anything on the allow path.
- Add a test that scans the source for codes, or any test that something is absent.
- Edit any other test or the golden fixture.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the two in-scope files.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 45
- Max total lines changed: 110

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l includes/Payments/Contract/Payment_Veto.php` exits 0.
- `php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php` exits 0.
- `grep -c "Order_Lock is a lease" includes/Payments/Contract/Payment_Veto.php` prints 0.

## Reviewer reruns (not for Codex)

- Under wp-env: `Test_Payment_Pre_Capture.php` (all pass, 22 + 6 new cases), `Test_Payments_No_Veto_Golden.php` (unchanged, passes).
- Mutations: drop the `RESERVED_CODES` term (the four prefixed reserved rows fail; `woo_rxdb_sync_record_locked` is still rewritten by the prefix rule); drop group 1 only (the two prefixed resendable rows fail); invert to rewrite every code (the free-code test fails). These are for the reviewer; Codex does not run them.
- phpcs on `Payment_Veto.php`.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
