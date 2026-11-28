# Codex job spec — coupon types 3 of 3: the `wcpos_payment_pre_capture` veto

## Goal

Add one filter, `wcpos_payment_pre_capture`. It is the store's last word before money is taken or the sale closes.

It runs on five payment command kinds: `record`, `intent`, `submit`, `capture` and `close`. It runs inside the order lock, after the command log's replay and stale-revision checks, and before the command's handler does anything. A callback that returns a `WP_Error` refuses the command:
- core answers 409, in the same shape as `wcpos_revision_stale`, plus `vetoed: true`;
- nothing is stored.

With no callback registered, nothing changes: no extra reads and no changed answers. The golden test `tests/includes/API/V2/Test_Payments_No_Veto_Golden.php` proves this, and it must keep passing.

## Stakes

High tier: money routes on the core plugin. Money table rows this change must satisfy (each has a test below):

| Row | Situation | Required server answer |
|---|---|---|
| veto × 5 | A refusing callback on record, intent, submit, capture, close | 409, the callback's `wcpos_` code, `data.vetoed === true`, no ledger row, no status change, no `payment_complete`, no command-log entry |
| M16 | The callback lowers a credit (it raises the order total 42.95 → 72.95, saves, refuses) | 409; `data.current` and `data.order` carry total 72.95; `data.currentRevision` is the new revision |
| M17 | Veto on `/close` of a total-0 sale; the callback sets the total to 10.00 and saves | 409; the order stays open with balance 10.00 and is not paid |
| M18 / R7 | An intent, then a veto at `capture` | 409; the row stays `pending` (or `authorized`), the handler's capture never runs, and a later void succeeds |
| M24 | A resend after a veto | The same command id runs the veto again (nothing was stored). After the callback goes, the same body is accepted. A resend at a revision the veto moved gets `wcpos_revision_stale` |
| R5 | Two callbacks, the first refuses, the second returns what it received | The answer is the first error |
| R3 | A callback throws | The command is allowed, and the throw is logged |
| replay | A command already answered, resent identically while a refusing callback is registered | The stored answer replays; the callback is never called |
| stale | A command at an old revision | `wcpos_revision_stale`; the callback is never called |
| other kinds | void, cancel, correct, refund, status GET | The callback is never called |

## In scope (the only files you may edit or create)

- `includes/Payments/Contract/Payment_Veto.php` (new)
- `includes/Payments/Contract/Command_Log.php` (the `run()` signature, its docblock and one block)
- `includes/API/V2/Payments_Controller.php` (the route closure in `register_routes()` and one new private method)
- `tests/includes/API/V2/Test_Payment_Pre_Capture.php` (new)

## Out of scope (do not edit)

- Every other file. In particular:
  - `includes/Payments/Contract/Ledger.php` and `Gateway_Submission.php`;
  - every handler;
  - `Order_Serializer.php`;
  - every existing test file, including `Test_Payments_Controller.php` and `Test_Payments_No_Veto_Golden.php`;
  - `tests/fixtures/`, `scripts/`, `tests/lane-coverage/`, `.github/`;
  - CHANGELOG, readme, languages.
- Issue #2858 (`wcpos_order_already_paid` on a reopened zero-total sale). Do not fix it or work around it.

## Context (read these first)

- `includes/API/V2/Payments_Controller.php`:
  - `COMMANDS` (the kind map);
  - the closure in `register_routes()` (~lines 96-122);
  - `create_item()`, which reads `params['payment']`;
  - `provider_item()` (~362), where intent reads `params['payment']` and the uuid is `strtolower( $request['uuid'] )`;
  - `submit_item()`, where the gateway id is `$request['method']`;
  - `answer()` (~409).
- `includes/Payments/Contract/Command_Log.php`:
  - `run()` (~93-127);
  - `replay()`, which calls `$work` again for record and pending intent. That is why the veto must not live inside the handler;
  - `outdated()` (~265-284), the stale answer shape to copy.
- `includes/Payments/Contract/Ledger.php`: `instance()->find( WC_Order, string $id ): ?array` (row keys `amount` as a decimal string, `method_id`, `status`), and `instance()->summary( WC_Order )`.
- `includes/Payments/Contract/Money.php`: `Money::minor( $value ): int`.
- `includes/Sync/Order_Serializer.php`: `current_state( int $order_id )` returns `array( 'document' => array, 'revision' => string )` or a `WP_Error`.
- Tests to copy patterns from:
  - `tests/includes/API/V2/Test_Payments_Controller.php`: setUp/tearDown log capture; `create_pos_order()`; `payment()`; `record()`; `command()`; `use_route_handler()`; `intended()`; `captured_row()`; `stored_meta()`; `zero_total_open_order()` (~1034); `close()`; `void()`; `payment_path()`; the `Route_Handler` class (~3106);
  - `tests/includes/API/V2/Test_Gateway_Submission.php`: `submit()` (~1722) and `cancel()`;
  - `tests/Helpers/Money_Route_Envelope.php` and `tests/Helpers/Ledger_Seed.php`.
- PHP floor is 7.4: no `str_starts_with`, no nullsafe operator, no union types, no `match`.

## Interfaces and constraints

1. **`Command_Log::run( WC_Order $order, Money_Command $command, callable $work, ?callable $gate = null )`.**
   - Add the `@param callable|null $gate` line: "fn( Money_Command ): ?WP_Error, run after the replay and stale checks and before the work; a WP_Error it returns is the answer and nothing is stored."
   - Directly after the `if ( null !== $stale ) { return $stale; }` block and before `$answer = $work( $command );`, add:
     ```php
     if ( null !== $gate ) {
     	$veto = $gate( $command );
     	if ( is_wp_error( $veto ) ) {
     		return $veto;
     	}
     }
     ```
   - Change nothing else in that file.
2. **`final class Payment_Veto`** in namespace `WCPOS\WooCommercePOS\Payments\Contract`, with `\defined( 'ABSPATH' ) || die;`:
   - `public const KINDS = array( 'record', 'intent', 'submit', 'capture', 'close' );`
   - `public static function check( WC_Order $order, string $kind, array $context ): ?WP_Error`:
     1. If `$kind` is not in `KINDS`, or `! has_filter( 'wcpos_payment_pre_capture' )`, return `null` at once (no reads).
     2. Call the filter inside `try { … } catch ( \Throwable $error ) { … }`, with exactly this docblock above it:
        ```php
        /**
         * Last word before money is taken or the sale closes. Return a WP_Error to refuse.
         * Runs under the order lock; do not take it again (Order_Lock is a lease, not reentrant).
         * The callback may change and save the order (for example, recompute a coupon) only when it is about to refuse;
         * a callback that allows must never change the order.
         * A callback that receives a WP_Error returns it unchanged.
         *
         * Core answers a refusal with 409 in the shape of `wcpos_revision_stale` plus `vetoed: true`: the code is the
         * callback's when it starts with `wcpos_`, else `wcpos_payment_vetoed`; the message is the callback's, translated
         * and readable by a cashier; `data.detail` is passed through from the error's data. Nothing is stored. A throw
         * or any other return value allows the command, and a throw is logged.
         *
         * @since 2.0.7
         *
         * @param null|WP_Error $veto    Null to allow.
         * @param WC_Order      $order   The order, read under the lock.
         * @param string        $kind    record|intent|submit|capture|close.
         * @param array         $context ['amount' => minor units or null, 'method' => id or null, 'cashier_id' => int].
         */
        $veto = apply_filters( 'wcpos_payment_pre_capture', null, $order, $kind, $context );
        ```
     3. On a throw: `Logger::log( 'WCPOS payment veto: a wcpos_payment_pre_capture callback threw, so the command is allowed.', array( 'kind' => $kind, 'order_id' => $order->get_id(), 'error' => get_class( $error ) . ': ' . $error->getMessage() ) )` and return `null`.
     4. If `$veto` is not a `WP_Error`, return `null`.
     5. Otherwise, build the refusal:
        - `$code` is the callback's code when `0 === strpos( (string) $veto->get_error_code(), 'wcpos_' )`, else `'wcpos_payment_vetoed'`;
        - `$message` is `$veto->get_error_message()`; when it is `''`, use `__( 'The store refused this payment. Check the order and try again.', 'woocommerce-pos' )`;
        - `$state = Order_Serializer::current_state( $order->get_id() )`; if it is a `WP_Error`, return `$state`;
        - `$fresh = wc_get_order( $order->get_id() )`. The callback may have saved, so the summary reads `$fresh` when it is a `WC_Order`, else `$order`;
        - `$data = array( 'status' => 409, 'currentRevision' => $state['revision'], 'current' => $state['document'], 'order' => Ledger::instance()->summary( … ), 'vetoed' => true )`, keys in that order;
        - if the callback's `get_error_data()` is an array with the key `detail`, set `$data['detail']` to that value, untouched;
        - return `new WP_Error( $code, $message, $data )`.
   - Class docblock: one or two lines saying what it is (the store's pre-capture veto on the money commands, run by `Command_Log::run()` through the controller's gate).
3. **`Payments_Controller`** route closure:
   - Replace the line
     `$result = null === $command ? $handler( null ) : Command_Log::run( $order, $command, $handler );`
     with a gate built only for veto kinds:
     ```php
     $gate   = in_array( $kind, Payment_Veto::KINDS, true )
     	? fn() => Payment_Veto::check( $order, $kind, $this->veto_context( $kind, $request, $order ) )
     	: null;
     $result = null === $command ? $handler( null ) : Command_Log::run( $order, $command, $handler, $gate );
     ```
     Keep the existing comment above that line, add `use WCPOS\WooCommercePOS\Payments\Contract\Payment_Veto;` (and `Money` if it is not imported), and change nothing else in the closure.
     A `record` with no command (no row id) stays ungated: its handler answers 400 before anything is written.
   - New `private function veto_context( string $kind, WP_REST_Request $request, WC_Order $order ): array`, with a docblock. It returns `array( 'amount' => int|null, 'method' => string|null, 'cashier_id' => get_current_user_id() )`:
     - `record`, `intent`: read params the way `create_item()` does (JSON params, else body params). `$payment = $params['payment'] ?? null`. The amount is `Money::minor( $payment['amount'] )` when `$payment` is an array and `$payment['amount']` is scalar and `is_numeric()`, else `null`. The method is `$payment['method_id']` when it is a non-empty string, else `null`;
     - `capture`: `$row = Ledger::instance()->find( $order, strtolower( (string) $request['uuid'] ) )`. The amount is `Money::minor( $row['amount'] )` when `$row` is an array with a numeric `amount`, else `null`. The method is the row's `method_id` when it is a non-empty string, else `null`;
     - `submit`: amount `null`; method `(string) $request['method']`;
     - `close`: amount `null`, method `null`.
4. **Tests**: the new file `Test_Payment_Pre_Capture extends WCPOS_REST_Unit_Test_Case`, namespace `WCPOS\WooCommercePOS\Tests\API\V2`, `use Money_Route_Envelope;`.
   - setUp/tearDown copy `Test_Payments_Controller`'s log capture.
   - Copy the private helpers you need from `Test_Payments_Controller` and `Test_Gateway_Submission` into this class. Do not reference that class's helpers or its `Route_Handler`.
   - Define your own handler after the class (same phpcs disable comment as `Test_Payments_Controller`): `class Veto_Route_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler` with mode `veto_route`; public static `$intents` and `$captures` counters, reset in setUp; `intent()` returns the row with `handoff => array( 'reader' => $context['reader'] )`; `capture()` returns `array_merge( array( 'status' => 'captured' ), $context )`. Register it as `use_route_handler()` does.
   - Every route literal contains `wcpos/v2` (for example `'/wcpos/v2/orders/' . $order->get_id() . '/payments'`).
   - A refusing helper: `private function refuse( array $kinds, string $code = 'wcpos_test_refused', string $message = 'Refused for the test.', $data = array() )`. It adds a filter callback that:
     - records each call (`$this->calls[] = array( 'kind' => $kind, 'context' => $context, 'order_id' => $order->get_id() )`);
     - returns `$veto` unchanged when it is already a `WP_Error`;
     - returns the `WP_Error` for a kind in `$kinds`, and `null` otherwise.
   - "Nothing stored" helper: snapshot `stored_meta( $order_id, Ledger::META_KEY )`, `wc_get_order( $id )->get_status()`, `wc_get_order( $id )->is_paid()` and `did_action( 'woocommerce_payment_complete' )` before the command; assert all four unchanged after it. Ledger rows and the command-log entries both live in that meta, so an unchanged meta proves no row and no entry.
   - Tests, named `test_pre_capture_[scenario]_[expected_result]`, Arrange/Act/Assert, `assertSame( expected, actual )`:
     1. **record vetoed**:
        - 409, code `wcpos_test_refused`, message `Refused for the test.`;
        - `data.vetoed === true`;
        - `data.currentRevision === $this->current_revision( $order )`;
        - `data.current` is an array whose `id` is the order id;
        - `data.order === Ledger::instance()->summary( wc_get_order( $id ) )`;
        - no `detail` key;
        - nothing stored;
        - one call, with kind `record`, context `amount` 5000 for a `50.00` payment (assume 2 price decimals), method `pos_cash`, `cashier_id === get_current_user_id()`.
     2. **intent vetoed**: 409 vetoed; nothing stored; `Veto_Route_Handler::$intents === 0`; context amount 9295, method `pos_cash`.
     3. **submit vetoed**: on the `pos_cash` submit route (`/wcpos/v2/orders/{id}/payment-methods/pos_cash/submit`, fixed attempt id, `values => array()`): 409 vetoed; nothing stored; context `amount` null, `method` `pos_cash`.
     4. **capture vetoed (R7)**:
        - intent with no callback (200);
        - then `refuse( array( 'capture' ) )` and capture at the current revision: 409 vetoed; the stored row's status is still `pending`; `Veto_Route_Handler::$captures === 0`; context amount 9295, method `pos_cash`;
        - then `remove_all_filters( 'wcpos_payment_pre_capture' )` and void the row at the current revision: 200, and the stored row's status is `voided`.
     5. **capture vetoed on an authorized row (R7)**:
        - seed one row with `Ledger_Seed::rows( $order, array( $row ), false )`, where `$row` has a fixed id, `method_id` `pos_cash`, `capture_mode` `veto_route`, `amount` `92.95`, `status` `authorized` (mirror `captured_row()`'s keys);
        - refuse `capture`, then capture: 409 vetoed; the stored row's status is still `authorized`; `$captures === 0`;
        - remove the filter and void: 200, status `voided`.
     6. **close vetoed**: a covered order (one seeded captured row for 92.95, as `test_close_on_a_covered_open_order_completes_it` does): 409 vetoed; the order is still `pos-open` and not paid; nothing stored; context amount and method both null.
     7. **M16**:
        - an order with total 42.95 (`create_pos_order()`, then `set_total( '42.95' )` and save);
        - a callback on `record` that calls `$order->set_total( '72.95' ); $order->save();` and returns a `WP_Error( 'wcpos_test_credit_short', 'Store credit changed.' )`;
        - record `pos_cash` `42.95`: 409 with code `wcpos_test_credit_short`; `data.current['total'] === '72.95'`; `data.order['total'] === '72.95'` and `data.order['balance'] === '72.95'`; `data.currentRevision` differs from the revision the command was sent at and equals `current_revision()` now; no ledger rows;
        - then, with the filter removed, a new command at `data.currentRevision` recording `72.95`: 200.
     8. **M17**:
        - copy `zero_total_open_order()`;
        - a callback on `close` that sets the total to `10.00`, saves, and refuses;
        - close: 409 vetoed; `data.order['balance'] === '10.00'`; the order stays `pos-open` and is not paid;
        - the test docblock says: "If this ever answers `wcpos_order_already_paid`, that is woocommerce-pos#2858, not this veto."
     9. **M24, same command again**:
        - `refuse( array( 'record' ) )`;
        - send a record body, then the identical body again (same command id and revision): both 409 vetoed, two calls;
        - remove the filter and send the identical body a third time: 200 with the payment recorded (it is not a replay and not a `wcpos_command_conflict`).
     10. **M24, moved revision**:
         - a callback on `record` that changes the total, saves and refuses;
         - send at revision R: 409;
         - remove the filter and send the identical body (same command id, revision R): 409 `wcpos_revision_stale`.
     11. **Replay never vetoed**: record with no callback (200); then `refuse( array( 'record' ) )` and resend the identical body: 200, the same payment id, zero calls.
     12. **Stale never vetoed**: `refuse( array( 'record' ) )`; save a change to the order after reading its revision; send at the old revision: `wcpos_revision_stale`, zero calls.
     13. **Other kinds never call it**:
         - register a counting callback that returns `null`;
         - void a pending intent, GET its status, POST `.../correct` with `method_id` `pos_card`, POST `.../refund` with `amount` `1.00` and `refund_id` 1, and POST the `pos_cash` cancel route;
         - whatever each answers, no call has a kind outside `KINDS`, and only the intent that set up the row was counted. Assert the recorded kinds list exactly.
     14. **R5**:
         - the first callback (priority 10) refuses with `wcpos_test_first`;
         - a second (priority 20) records what it received and returns it unchanged;
         - answer code `wcpos_test_first`; the second callback received a `WP_Error` with that code.
     15. **Code prefix**: a callback returning code `store_credit_short` answers `wcpos_payment_vetoed`, with the callback's message kept.
     16. **Default message**: a callback returning `new WP_Error( 'wcpos_test_empty', '' )` answers the message `The store refused this payment. Check the order and try again.`.
     17. **Detail passthrough**: error data `array( 'detail' => array( 'store_credit' => array( 'code' => 'X', 'applied_before' => '50.00', 'applied_now' => '20.00' ) ), 'status' => 400 )`. Then `data.detail` is exactly that inner array, `data.status` is still 409, and `get_status()` is 409.
     18. **Throwing callback (R3)**: a callback that throws `new \RuntimeException( 'boom' )`. The record answers 200, the row is stored, and a captured log message contains `WCPOS payment veto` and `boom`.
     19. **Non-error return allows**: callbacks returning `true`, `'no'` and `array( 'refuse' )`. Each record answers 200.
     20. **No filter, no reads**: with no callback, `has_filter( 'wcpos_payment_pre_capture' )` is false and a record answers 200. (The golden file proves the answer shape; this one just exercises the fast path.)
   - Filters added in a test are removed by WordPress's hook reset between tests. Remove them explicitly only where the test itself needs them gone (items 4, 5, 7, 9, 10).

## Do not

- Edit any file outside the in-scope list, or any line of an in-scope file beyond what is described.
- Add an option, a table, a migration, a new route, a new response field on a non-vetoed answer, or any other hook.
- Run the veto for void, cancel, correct, refund or the status GET.
- Read anything or apply the filter when no callback is registered.
- Name any new hook with the `woocommerce_pos_` prefix.
- Touch `wcpos_order_already_paid` handling (#2858).
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit or create the in-scope files
- Run the acceptance commands

## Budget

- Max non-test lines changed: 200
- Max total lines changed: 1000

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

```sh
php -l includes/Payments/Contract/Payment_Veto.php
php -l includes/Payments/Contract/Command_Log.php
php -l includes/API/V2/Payments_Controller.php
php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php
php scripts/lane-coverage.php --compare=/Users/claude/.claude/jobs/a1d68413/tmp/lane-base-coupons/base.json   # must exit 0
```

## Reviewer reruns (not for Codex)

These run under wp-env:
- the new file, the golden file, `Test_Payments_Controller.php`, `Test_Gateway_Submission.php` and the Contract tests;
- mutations:
  - gate before the replay check;
  - gate before the stale check;
  - drop the `has_filter` fast path and make the controller always apply;
  - no prefix rule;
  - no default message;
  - no `detail` passthrough;
  - the summary read from the pre-callback `$order`;
  - a gate on void;
  - no try/catch;
- phpcs and phpstan on the changed files.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
