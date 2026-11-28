# Codex job spec — coupon types 3b: M16 test reads the six-decimal document

## Goal

Correct one assertion in `test_pre_capture_credit_reduced_returns_new_balance_and_revision` so it expects the precision the veto's `current` document actually has.

## Stakes

Test-only. The veto's `data.current` is `Order_Serializer::current_state()`, which reads the order with `dp => 6` on purpose (ADR 0040 rule 6), so a total saved as `72.95` comes back as `'72.950000'`. The reviewer's wp-env run failed:

```
Test_Payment_Pre_Capture::test_pre_capture_credit_reduced_returns_new_balance_and_revision
Failed asserting that two strings are identical.
-'72.95'
+'72.950000'
tests/includes/API/V2/Test_Payment_Pre_Capture.php:193
```

## In scope (the only files you may edit)

- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`, line 193 only.

## Out of scope (do not edit)

- Everything else, including every file under `includes/` and every other line of the test file.

## Context

- `includes/Sync/Order_Serializer.php`: `compared_read()` and `current_state()`.

## Interfaces and constraints

- Change line 193 from `$this->assertSame( '72.95', $data['current']['total'] );` to `$this->assertSame( '72.950000', $data['current']['total'] );`.
- Leave lines 194 and 195 (`$data['order']['total']` and `$data['order']['balance']`, which come from the ledger summary at two decimals) as they are.

## Do not

- Edit any other line or file.
- Loosen the assertion (no `assertEquals`, no float cast).
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the in-scope line
- Run the acceptance commands

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 2

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php` exits 0.

## Reviewer reruns (not for Codex)

- `Test_Payment_Pre_Capture.php` under wp-env: all tests pass.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
