# Codex job spec — coupon types 6: two follow-up tests for the payment veto

## Goal

The front desk approved PR #2859 (merged as `73c4b981`) and asked for two follow-up tests in a separate small PR:

1. A test row pinning `wcpos_order_unreadable` in `Payment_Veto::RESERVED_CODES`. Today removing it from the list fails no test.
2. A test that a completed command, replayed while a refusing callback is registered, returns the stored answer and never calls the callback.

For (2), `test_pre_capture_record_replay_skips_veto` already covers `record`, but it compares only the status and the payment id. `close` is not covered. Its stored entry lives in the command log, and `Command_Log::replay()` rebuilds its answer from that entry instead of calling the handler, so it is a separate path. This job tightens the record test to compare the whole answer, and adds the same test for `close`.

Tests only. No change under `includes/`.

## Stakes

High tier (a money route on the core plugin), but this job changes tests only.

| Row | Situation | Required answer | Test |
|---|---|---|---|
| unreadable reserved | The callback refuses `record` with `wcpos_order_unreadable` | 409 `wcpos_payment_vetoed`, the callback's message | new row `'core service unreadable'` in `reserved_codes()` |
| record replay | A recorded payment is resent with the same body after a refusing `record` callback is registered | 200, the whole answer identical to the first, callback never called | `test_pre_capture_record_replay_skips_veto` (tightened) |
| close replay | A closed sale is resent the same close body after a refusing `close` callback is registered | 200, the whole answer identical to the first, callback never called | new `test_pre_capture_close_replay_skips_veto` |

## In scope (the only files you may edit)

- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`

## Out of scope (do not edit)

- Everything else, including every file under `includes/`, every other test, the golden fixture and the CHANGELOG.

## Context

- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`: `reserved_codes()`, `test_pre_capture_record_replay_skips_veto()`, `test_pre_capture_close_refused_leaves_covered_sale_open()` (its arrange is the model for a closable sale), and the helpers `refuse()`, `command()`, `envelope()` (from `tests/Helpers/Money_Route_Envelope.php`; each call makes a fresh `command_id`), `captured_row()`, `create_pos_order()` and the `$calls` property.
- `includes/Payments/Contract/Command_Log.php`, `run()` and `replay()`: read only, to see that a stored command id returns before the gate runs.

## Interfaces and constraints

1. In `reserved_codes()`, add one row after `'core service stock'`:
   `'core service unreadable' => array( 'wcpos_order_unreadable' ),`
   Re-align the `=>` arrows of all seven rows to the longest key.
2. In `test_pre_capture_record_replay_skips_veto()`, replace the assertion on `['payment']['id']` with `$this->assertSame( $first->get_data(), $replay->get_data() );`. Keep the status assertion and the `$this->calls` assertion. Nothing else in that test changes.
3. Add `test_pre_capture_close_replay_skips_veto()` directly after `test_pre_capture_record_replay_skips_veto()`, with the one-line docblock `/** A replayed close answers from the command log and never asks for a veto. */`:
   - Arrange: `create_pos_order()`, then `Ledger_Seed::rows( $order, array( $this->captured_row( '92.95' ) ), false );` exactly as in the close-refused test. Build the body once: `$route = '/wcpos/v2/orders/' . $order->get_id() . '/close';` and `$body = $this->envelope( $order );`. Send it with `$first = $this->command( $route, $body );` and assert its status is 200. Then `$this->refuse( array( 'close' ) );`.
   - Act: `$replay = $this->command( $route, $body );` with the same `$body`.
   - Assert, with `assertSame` and `( expected, actual )`: status 200; `$first->get_data()` identical to `$replay->get_data()`; `array()` identical to `$this->calls`.

## Do not

- Edit anything outside the one test file, or any test other than the one named in constraint 2.
- Change a helper.
- Weaken an assertion so a test passes. If the record or close answers differ between the first call and the replay, STOP and report the differing keys instead of narrowing the assertion. That would be a finding, not a test to adjust.
- Add a test that something is absent.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the in-scope file.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 35

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php` exits 0.
- `grep -c "'wcpos_order_unreadable'" tests/includes/API/V2/Test_Payment_Pre_Capture.php` prints 1.
- `grep -c "function test_pre_capture_close_replay_skips_veto" tests/includes/API/V2/Test_Payment_Pre_Capture.php` prints 1.
- `git diff --stat -- includes` prints nothing.

## Reviewer reruns (not for Codex)

- Under wp-env: `Test_Payment_Pre_Capture.php` (31 tests).
- Mutations on `includes/` (applied and reverted by the reviewer):
  - remove `'wcpos_order_unreadable',` from `RESERVED_CODES`: the new row fails;
  - in `Command_Log::run()`, move the gate block above the `null !== $stored` replay branch: both replay tests fail.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
