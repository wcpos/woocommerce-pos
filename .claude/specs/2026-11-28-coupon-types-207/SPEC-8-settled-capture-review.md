# Codex job spec — coupon types 8: pin the settled-capture review gaps (PR #2862)

## Goal

The independent review of PR #2862 found three survived mutations and one missing docblock sentence. The fix is three tests and one sentence; no logic changes.

1. **S1:** no test uses a payment row with a `provider`. If `Payment_Veto::settled()` looked up the handler without the row's provider, a charged reader payment would get the 409 again.
2. **S2:** no test proves the warning logs the callback's own code before mapping. The test code `wcpos_test_refused` maps to itself.
3. **N1:** `status()` is only made to throw an `Exception`. Narrowing the catch to `\Exception` survived, so a PHP `\Error` would become a 500.
4. **N3:** the filter docblock does not say that an order change a refusing callback saved still stands when its capture refusal is not applied.

## In scope (the only files you may edit)

- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`
- `includes/Payments/Contract/Payment_Veto.php`: the filter docblock only, one sentence.

## Out of scope (do not edit)

- Every other file.
- Any executable line of `Payment_Veto.php`.

## Context

- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`:
  - `test_pre_capture_settled_capture_ignores_refusal()`, `test_pre_capture_status_failure_ignores_refusal()` with `status_failures()`, and `test_pre_capture_authorized_capture_refused_leaves_row_voidable()`. The last one seeds a row with `Ledger_Seed::rows( $order, array( $row ), false )`.
  - `use_route_handler()`, `refuse( array $kinds, string $code = 'wcpos_test_refused', ... )`, `stored_row()`, `$this->logged_messages` / `$this->logged_levels`.
  - The `Veto_Route_Handler` class at the end of the file, with statics `$captures`, `$status`, `$status_calls`.
- `Capture_Mode_Registry::instance()->register( $mode, $class )`. `resolve( $mode, $provider )` prefers the key `"$mode:$provider"` when it is registered.
- `Logger::log()` JSON-encodes the context onto the message line (`| Context: {...}`). The logged line therefore contains `"code":"<the code>"`.
- `Payment_Veto::check()` maps a code that does not start with `wcpos_` to `wcpos_payment_vetoed`.

## Changes

1. **N1:** add a third case to `status_failures()`: `'error' => array( new \Error( 'status fatal' ) )`. Update that test's `@param` type to `WP_Error|\Throwable` if needed; it already accepts any `\Throwable`.

2. **S2:** a new test, `test_pre_capture_settled_capture_logs_callback_code()`, placed right after `test_pre_capture_settled_capture_ignores_refusal()`.
   - Arrange it like that test: `use_route_handler()`, `create_pos_order()`, `intended( $order, '92.95' )`, `Veto_Route_Handler::$status = 'captured'`, then `refuse( array( 'capture' ), 'store_refused' )`.
   - POST the capture.
   - Assert 200.
   - Assert exactly one logged message contains both `'already holds'` and `'"code":"store_refused"'`. Count it with `array_filter` and `assertSame( 1, count( ... ) )`.

3. **S1:** a provider-scoped handler and a test.
   - At the end of the file, next to `Veto_Route_Handler`, add `class Veto_Provider_Handler extends Veto_Route_Handler`:
     - a static `$provider_status_calls = 0`;
     - `status( array $row )` that does `++self::$provider_status_calls;` and returns `array_merge( $row, array( 'status' => 'captured' ) )`;
     - docblocks in the file's style. It must not call the parent's `status()`.
   - `setUp()` resets `Veto_Provider_Handler::$provider_status_calls = 0;` next to the other resets.
   - New test `test_pre_capture_settled_capture_uses_provider_handler()`, placed after the S2 test:
     - `use_route_handler()`, then `Capture_Mode_Registry::instance()->register( 'veto_route:acme', Veto_Provider_Handler::class );`.
     - Leave `Veto_Route_Handler::$status = null`, so the mode-only handler reports the row unchanged (pending).
     - Seed one row with `Ledger_Seed::rows( $order, array( $row ), false )`: `id` `'b4f666a5-b955-4529-92a6-b6ee71f4ad5d'`, `method_id` `'pos_cash'`, `kind` `'cash'`, `capture_mode` `'veto_route'`, `provider` `'acme'`, `amount` `'92.95'`, `status` `'pending'`.
     - `refuse( array( 'capture' ) )`, then POST `payment_path( $order, $row['id'] ) . '/capture'` with `envelope( $order )`.
     - Assert 200; the stored row's status `'captured'`; `1 === Veto_Provider_Handler::$provider_status_calls`; `0 === Veto_Route_Handler::$status_calls`.
     - If the capture route itself rejects a seeded pending row with a provider (any non-200 that is not the veto 409), STOP and report the response code and body. Do not change the seed or any helper to work around it.

4. **N3:** in `Payment_Veto::check()`'s filter docblock, append to the end of the paragraph that begins "For `capture`, a refusal is not applied …", before the blank ` *` line:

   > An order change the callback saved before refusing still stands when that refusal is not applied, so the refusal at `close` sees it.

   Re-wrap so lines stay under the file's existing width. No other docblock change.

## Do not

- Change any executable line in `includes/`.
- Weaken or edit an existing assertion.
- Add a test that something is absent.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the in-scope files.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 4
- Max total lines changed: 90

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php` exits 0.
- `php -l includes/Payments/Contract/Payment_Veto.php` exits 0.
- `grep -c "class Veto_Provider_Handler extends Veto_Route_Handler" tests/includes/API/V2/Test_Payment_Pre_Capture.php` prints 1.
- `grep -c "store_refused" tests/includes/API/V2/Test_Payment_Pre_Capture.php` prints at least 2.
- `git diff --stat` lists only the two in-scope files.

## Reviewer reruns (not for Codex)

- `Test_Payment_Pre_Capture.php` under wp-env, all passing.
- Mutations:
  - `resolve( $row['capture_mode'], $row['provider'] ?? null )` becomes `resolve( $row['capture_mode'] )` in `settled()`: the S1 test fails.
  - The warning's `'code' => $veto->get_error_code()` becomes the mapped code (move the warning below the mapping and log `$code`): the S2 test fails.
  - `catch ( \Throwable $error )` becomes `catch ( \Exception $error )`: the `error` case fails.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
