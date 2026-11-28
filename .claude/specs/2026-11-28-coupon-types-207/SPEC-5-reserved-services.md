# Codex job spec — coupon types 5: reserve the two service codes SPEC-4 missed

## Goal

SPEC-4 added `Payment_Veto::RESERVED_CODES` under the front desk's ruling: "a veto may never answer with a code core itself emits on the payments routes". The independent re-derive at `3dd26a5b` found two codes that core emits on those routes but that come from services outside `includes/Payments`:

- `wcpos_insufficient_stock`, from `includes/Services/Stock_Validator.php:382`. Record and close return it through `Ledger.php`, and submit returns it through `Gateway_Submission.php`. `Test_Payments_Controller.php:1021` asserts `/close` answers 400 `wcpos_insufficient_stock`.
- `wcpos_order_unreadable`, from `includes/Sync/Order_Serializer.php:332` (`current_state()`). It reaches the routes through `Command_Log::outdated`, `Payments_Controller` and `Payment_Veto::check` itself.

Add both to the list, make the group comment true, add one test row for the new class, and close up one docblock line that the SPEC-4 re-wrap left short.

## Stakes

High tier: a money route on the core plugin. The change only widens which callback codes become `wcpos_payment_vetoed`. With no callback registered, nothing changes, and `Test_Payments_No_Veto_Golden` must keep passing.

| Row | Situation | Required answer | Test |
|---|---|---|---|
| core service code | The callback refuses with `wcpos_insufficient_stock` | 409 `wcpos_payment_vetoed`, the callback's message | new row `'core service stock'` in `reserved_codes()` |
| existing rows | the five SPEC-4 rows and the free-code test | unchanged | existing |
| no callback | Nothing registered | Unchanged | `Test_Payments_No_Veto_Golden` (existing, unchanged) |

## In scope (the only files you may edit)

- `includes/Payments/Contract/Payment_Veto.php`
- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`

## Out of scope (do not edit)

- Everything else, including every other file under `includes/`, the golden test and its fixture, and the CHANGELOG.

## Context

- `includes/Payments/Contract/Payment_Veto.php`: `RESERVED_CODES` and the filter docblock in `check()`.
- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`: `reserved_codes()`.

## Interfaces and constraints

1. In `RESERVED_CODES`, group 2, which starts at the comment `// Codes core itself answers on the payments routes (includes/Payments, Payments_Controller).`:
   - Insert `'wcpos_insufficient_stock',` and `'wcpos_order_unreadable',` in alphabetical order within the group. `wcpos_insufficient_stock` goes after `wcpos_idempotency_in_progress`; `wcpos_order_unreadable` goes after `wcpos_order_not_found`.
   - Replace that comment line with exactly:
     `// Codes core itself answers on its money routes (includes/Payments, Payments_Controller, the V1 checkout) and from the services they call (Stock_Validator, Order_Serializer).`
   - Change nothing else in the constant: no removals, no reordering of other entries, and no edit to group 1 or its comment.
2. In the filter docblock in `check()`, the line ` * the message is the callback's, translated` is followed by ` * and readable by a cashier; ...`. Re-wrap only those docblock lines, from the line that starts with `callback's when it starts with` to the line that ends with `and a throw is logged.`, so that none of them is a short orphan. Keep every word and its order exactly as it is now, and keep each line under 120 characters.
3. In `reserved_codes()`, add one row after `'core conflict'`:
   `'core service stock'    => array( 'wcpos_insufficient_stock' ),`
   Align the `=>` arrows of all six rows.
4. Give `test_pre_capture_reserved_code_uses_default_code_and_keeps_message` a docblock with a one-line short description, then a blank `*` line, then `@dataProvider reserved_codes` and `@param string $code Refusal code.`, replacing its current single-line `/** @dataProvider reserved_codes */`.

## Do not

- Change the code rule, the default message, `detail`, the 409 status, `vetoed`, `KINDS`, or anything on the allow path.
- Add `wcpos_money_in_motion` or any code not named above.
- Add a test that scans the source for codes, or any test that something is absent.
- Edit any other test or file.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the two in-scope files.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 14
- Max total lines changed: 26

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l includes/Payments/Contract/Payment_Veto.php` exits 0.
- `php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php` exits 0.
- `grep -c "'wcpos_insufficient_stock'," includes/Payments/Contract/Payment_Veto.php` prints 1.
- `grep -c "'wcpos_order_unreadable'," includes/Payments/Contract/Payment_Veto.php` prints 1.

## Reviewer reruns (not for Codex)

- Under wp-env: `Test_Payment_Pre_Capture.php` (29 tests), `Test_Payments_No_Veto_Golden.php`, and `Test_Payments_Controller.php`.
- Mutation: remove `'wcpos_insufficient_stock',` from the list; the `'core service stock'` row must fail.
- phpcs on `Payment_Veto.php`.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
