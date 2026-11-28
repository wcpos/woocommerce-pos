# Codex job spec — coupon types 7: a veto never refuses a capture whose money the provider already holds (R12)

## Goal

Card readers driven by a device SDK (Stripe Terminal, SumUp) take the money on the reader before the till posts `/capture`.
Today a `wcpos_payment_pre_capture` refusal at `capture` answers 409 and records nothing. The till treats a veto as final, so the customer is charged and the ledger has no row: the worst outcome in the payment contract.

The front desk's ruling (R12, 28 Nov 2026):
- The filter is called exactly as today.
- Only when it refuses a `capture`, core asks the payment's handler for its status with `Capture_Mode_Handler_Interface::status( $row )`.
- If the handler reports `captured`, or the status check fails (returns a `WP_Error` or throws), the refusal is ignored. It is logged at warning level with the callback's code, and the capture proceeds.
- Anything else answers the 409 exactly as today.
- No status check for the other four kinds. No status check when no callback is registered, or when the callback allows.
- `$context` gains no `settled` key: the status is asked only after the filter, so the callback cannot be told. The docblock says so.

## Stakes

High tier: a money route on the core plugin.

| Row | Situation | Required answer | Test |
|---|---|---|---|
| settled | callback refuses `capture`; handler status says `captured` | 200, row `captured`, handler `capture()` called once, warning logged with the callback's code | `test_pre_capture_settled_capture_ignores_refusal` |
| status fails | callback refuses `capture`; status returns `WP_Error` / throws | 200, row `captured`, warning logged | `test_pre_capture_status_failure_ignores_refusal` (data provider: `wp_error`, `throws`) |
| not settled | callback refuses `capture`; status says `authorized` / returns the pending row unchanged | 409 `wcpos_test_refused`, `vetoed: true`, row unchanged, `capture()` never called | `test_pre_capture_unsettled_capture_keeps_refusal` (data provider: `authorized`, `pending`) |
| no refusal | no callback / an allowing callback, on `capture` | 200, status never asked | `test_pre_capture_capture_without_refusal_never_asks_status` |
| other kinds | `record`, `intent`, `submit`, `close` refused | refusal returned, the settled check never called | `test_pre_capture_other_kinds_never_ask_status` (data provider over the four kinds) |

## In scope (the only files you may edit)

- `includes/Payments/Contract/Payment_Veto.php`
- `includes/API/V2/Payments_Controller.php` (the gate closure in `register_routes()` only)
- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`

## Out of scope (do not edit)

- Everything else, including `Ledger.php`, `Command_Log.php`, every handler under `includes/Payments/Contract/`, every other test, the golden fixture, readme.txt and the CHANGELOG.
- The 409 refusal shape, the reserved codes and the code mapping.

## Context

- `includes/Payments/Contract/Payment_Veto.php`, `check()`: the filter call, the throw handling, the `is_wp_error( $veto )` test, then the code mapping and the 409.
- `includes/API/V2/Payments_Controller.php`, about line 116: `$gate = ... ? fn() => Payment_Veto::check( $order, $kind, $this->veto_context( $kind, $request, $order ) ) : null;`. The payment id of a capture is `strtolower( (string) $request['uuid'] )`, which `veto_context()` already uses.
- `Ledger::instance()->find( $order, $id )` returns the stored row or null. `Capture_Mode_Registry::instance()->resolve( $row['capture_mode'], $row['provider'] ?? null )` returns the handler or null, as `Ledger::capture()` uses it.
- `includes/Logger.php`: `Logger::log( $message, $context )` writes at `Logger::$log_level`, which is a public static; `Logger::warning( $message, $context )` logs one line at `warning` and restores the level.
- Tests: `use_route_handler()`, `intended()`, `refuse()`, `command()`, `payment_path()`, `envelope()`, `stored_row()`, `assert_veto()`, `assert_call()`, `$this->calls`, `$this->logged_messages`, the `setUp()` log filter, and the `Veto_Route_Handler` class at the end of the file. Model new capture tests on `test_pre_capture_pending_capture_refused_leaves_row_voidable()`.

## Interfaces and constraints

1. `Payment_Veto::check()` gains a fourth parameter, `?callable $settled = null`, documented as: "For a capture only: answers why the provider already holds the money ('captured' or 'status_failed'), or null. Asked only after the callback refused."
   - Immediately after the `if ( ! is_wp_error( $veto ) ) { return null; }` block, and before the code mapping:

     ```php
     $held = 'capture' === $kind && null !== $settled ? $settled() : null;
     if ( null !== $held ) {
         // log at warning (constraint 3), then:
         return null;
     }
     ```

   - Nothing else in `check()` changes. In particular, the settled callable is never called before `apply_filters()`.
2. New `public static function settled( WC_Order $order, string $id ): ?string` in `Payment_Veto`:
   - Find the row with `Ledger::instance()->find( $order, $id )`; if there is none, return null.
   - Resolve the handler as `Ledger::capture()` does; if there is none, return null.
   - Inside `try`: `$status = $handler->status( $row );`.
     - A `WP_Error` returns `'status_failed'`.
     - An array whose `status` is `'captured'` returns `'captured'`.
     - Anything else returns null.
   - `catch ( \Throwable $error )` returns `'status_failed'`.
   - It writes nothing: no ledger write, no order save.
   - Docblock: one line saying it is the read-only provider check behind R12, and that a status that cannot be read counts as held so a charged card is never refused.
3. The warning uses the existing helper (it sets and restores the level itself):
   - `Logger::warning( 'WCPOS payment veto: a refusal of a capture whose money the provider already holds was ignored.', array( 'reason' => $held, 'code' => $veto->get_error_code(), 'order_id' => $order->get_id() ) );`.
   - `code` is the callback's own code, before any mapping.
4. In `Payments_Controller`, the gate closure passes the fourth argument only for a capture:

   ```php
   'capture' === $kind ? fn() => Payment_Veto::settled( $order, strtolower( (string) $request['uuid'] ) ) : null
   ```

   Re-wrap the line to fit phpcs. No other change in that file.
5. Filter docblock in `check()`: add a paragraph after the "Core answers a refusal with 409 …" paragraph:

   > For `capture`, a refusal is not applied when the provider already holds the money (a card reader that charged before the till posted the capture): after the callback refuses, core asks the payment's handler for its status, and when it reports `captured`, or the status cannot be read, the capture is recorded and the refusal is logged at warning level with its code. `$context` carries no `settled` key, because that status is asked only after the callback has answered. Refuse such payments at `intent`, or at `close` once the balance changed.

6. Tests, in `Test_Payment_Pre_Capture.php`:
   - `Veto_Route_Handler` gains:
     - `public static $status = null;` (null, a status string, a `WP_Error` or a `\Throwable`);
     - `public static $status_calls = 0;`;
     - `status( array $row )`, which does `++self::$status_calls`. Then: a `\Throwable` is thrown; a `WP_Error` is returned; a string returns `array_merge( $row, array( 'status' => self::$status ) )`; null returns `$row`.
     - Docblocks in the class's existing style.
   - `setUp()` resets both statics (`$status = null`, `$status_calls = 0`) next to the existing `Veto_Route_Handler::$captures = 0;`.
   - The log filter also records the level: add a `private array $logged_levels = array();` property, reset in `setUp()`, and in the filter `$this->logged_levels[] = Logger::$log_level;` next to the message.
   - The five tests from the Stakes table go after `test_pre_capture_authorized_capture_refused_leaves_row_voidable()`. Every assertion is `assertSame` with `( expected, actual )`.
     - **settled:** `use_route_handler()`, `intended( $order, '92.95' )`, `Veto_Route_Handler::$status = 'captured'`, `refuse( array( 'capture' ) )`, POST the capture.
       - Assert status 200; stored row status `'captured'`; `1 === Veto_Route_Handler::$captures`; `1 === Veto_Route_Handler::$status_calls`; `assert_call( $order, 'capture', 9295, 'pos_cash' )`.
       - Assert a logged message containing both `'already holds'` and `'wcpos_test_refused'`, logged when the level was `'warning'`. Pair `$this->logged_messages` and `$this->logged_levels` by index.
       - Assert `Logger::$log_level` after the request equals its value before.
     - **status fails:** the same arrange, with `Veto_Route_Handler::$status` from the data provider `status_failures()`: `'wp_error' => array( new WP_Error( 'wcpos_test_status', 'down' ) )`, `'throws' => array( new \RuntimeException( 'status down' ) )`.
       - Assert 200; row `'captured'`; a warning message containing `'status_failed'`.
     - **not settled:** data provider `unsettled_statuses()`: `'authorized' => array( 'authorized' )`, `'pending' => array( null )`.
       - Assert `assert_veto( $response )`; row status `'pending'`; `0 === Veto_Route_Handler::$captures`; `1 === Veto_Route_Handler::$status_calls`.
     - **no refusal:** two orders. On the first, no callback. On the second, `add_filter( 'wcpos_payment_pre_capture', static fn() => null );`. Both with `$status = 'captured'`.
       - Assert each capture 200 and `0 === Veto_Route_Handler::$status_calls` at the end.
       - `remove_all_filters( 'wcpos_payment_pre_capture' )` at the end.
     - **other kinds:** data provider `non_capture_kinds()`, the four kinds keyed by name.
       - Call `Payment_Veto::check( $order, $kind, array( 'amount' => null, 'method' => null, 'cashier_id' => 0 ), $settled )` directly. `$order` is `create_pos_order()`, `refuse( array( $kind ) )`, and `$settled` is a closure that counts its calls in a local by reference and returns `'captured'`.
       - Assert the result is a `WP_Error` (`assertInstanceOf`) with code `'wcpos_test_refused'`, and the count is 0.
       - Add `use WCPOS\WooCommercePOS\Payments\Contract\Payment_Veto;` if not already imported.

## Do not

- Change the 409 shape, `RESERVED_CODES`, the code mapping, or anything about the four other kinds.
- Call `status()` before the filter, or for a kind other than `capture`.
- Add a `settled` key to `$context`.
- Write to the ledger or the order from `settled()`.
- Edit a helper other than `setUp()`'s log filter and the `Veto_Route_Handler` additions above.
- Weaken an existing assertion. If an existing test fails, STOP and report it.
- Add a test that something is absent.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the in-scope files.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 60
- Max total lines changed: 230

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l includes/Payments/Contract/Payment_Veto.php` exits 0.
- `php -l includes/API/V2/Payments_Controller.php` exits 0.
- `php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php` exits 0.
- `grep -c "public static function settled" includes/Payments/Contract/Payment_Veto.php` prints 1.
- `grep -c "Payment_Veto::settled" includes/API/V2/Payments_Controller.php` prints 1.
- `git diff --stat` lists only the three in-scope files.

## Reviewer reruns (not for Codex)

- Under wp-env: `Test_Payment_Pre_Capture.php`, every test passing. Then the Payments V2 suite (`tests/includes/API/V2/`).
- phpcs on the two source files.
- Mutations (applied and reverted by the reviewer):
  - `settled()` always returns null: the settled and status-failure tests fail;
  - `status_failed` paths return null: the status-failure test fails;
  - `'captured' === ` becomes `in_array( …, array( 'captured', 'authorized' ) )`: the not-settled `authorized` case fails;
  - drop `'capture' === $kind &&` in `check()`: the other-kinds test fails;
  - call `$settled` before `apply_filters()`: the no-refusal test fails;
  - `Logger::warning(` becomes `Logger::log(`: the settled test's level assertion fails.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
