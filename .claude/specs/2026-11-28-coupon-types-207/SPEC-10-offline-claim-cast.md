# Codex job spec — coupon types 10: pin the offline claim's cast (PR #2864 review nit N1)

## Goal

The review of PR #2864 found that the `(bool)` cast on `$context['recorded_offline']` is not pinned: every data case sends a real boolean. A strict `true ===` check, or the raw uncast value, would both survive. This job adds one data case with an integer `1`, which must reach the callback as `true`, the same value the stored row gets.

## Stakes

Test only. If the cast is not pinned, a callback could be told something different from what the row stores, or get a non-bool against the docblock. No source change.

## In scope (the only files you may edit)

- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`

## Out of scope (do not edit)

- Every other file.

## Context

- `offline_claims()` in `tests/includes/API/V2/Test_Payment_Pre_Capture.php` returns three cases: `'true'`, `'false'` and `'absent'`. It feeds `test_pre_capture_record_context_carries_offline_claim( array $extra, bool $expected )`.

## Changes

1. Add a fourth case to `offline_claims()`, after `'false'`: `'one' => array( array( 'recorded_offline' => 1 ), true ),`. Keep the array alignment.

## Do not

- Change any other line.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the in-scope file.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 6

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php` exits 0.
- `grep -c "'one'" tests/includes/API/V2/Test_Payment_Pre_Capture.php` prints 1.
- `git diff --stat` lists only the in-scope file.

## Reviewer reruns (not for Codex)

- `Test_Payment_Pre_Capture.php` under wp-env, all passing.
- Mutations: `(bool) ( … )` becomes `true === ( … )`, and becomes the raw `( $payment['recorded_offline'] ?? false )`. The `one` case fails for both.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
