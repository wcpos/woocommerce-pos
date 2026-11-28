# Codex job spec — coupon types 9: the till's offline claim in the `record` veto context (R13)

## Goal

Front-desk ruling R13 (28 Nov 2026): a `record` the till replays from its offline journal arrives exactly like a live one, and core has no sound way to tell them apart. Core must not treat the claim as settled. Instead, the `wcpos_payment_pre_capture` filter's `$context` gains `recorded_offline` (bool) for `record` only. It is read from the request's already-parsed `payment` array, so the callback can decide for itself: it may allow such a payment and refuse at `close` instead.

## Stakes

This is the money path on the core plugin, but the change is purely informational: one more key in a filter's context, and core's behaviour is unchanged. A wrong value misleads a callback. A key added to another kind changes a documented context shape. There is no REST shape change and nothing new is stored. `recorded_offline` is already an accepted, stored payment field (`Ledger::RECORD_INPUT_KEYS`, cast with `(bool)` in the row normaliser).

| Case | Expected `$context['recorded_offline']` | Test |
|---|---|---|
| `record`, body `recorded_offline: true` | `true` | `test_pre_capture_record_context_carries_offline_claim` (`true`) |
| `record`, body `recorded_offline: false` | `false` | same (`false`) |
| `record`, key absent | `false` | same (`absent`), plus the existing `test_pre_capture_record_refused_returns_current_state_without_writing` |
| `intent`, `submit`, `capture`, `close` | key not present | the existing exact-context `assert_call()` checks in `test_pre_capture_intent_refused_never_calls_provider`, `test_pre_capture_submit_refused_leaves_storage_unchanged`, the two capture tests and the close test (`assertSame` on the whole context) |

Split legs, voids, refunds and flags do not apply: the change only adds a context key.

## In scope (the only files you may edit)

- `includes/API/V2/Payments_Controller.php`
- `includes/Payments/Contract/Payment_Veto.php`
- `tests/includes/API/V2/Test_Payment_Pre_Capture.php`

Existing tests that assert the full context: every `assert_call()` call in `tests/includes/API/V2/Test_Payment_Pre_Capture.php`. No other test file asserts this filter's context.

## Out of scope (do not edit)

- Every other file.
- `Ledger.php`, `Command_Log.php`, `Money_Command.php`.
- The 409 refusal shape, `Payment_Veto::check()`'s logic, `Payment_Veto::settled()`.

## Context

- `Payments_Controller::veto_context( string $kind, WP_REST_Request $request, WC_Order $order ): array`:
  - For `record` and `intent` it sets `$payment = $params['payment'] ?? null` from `get_json_params()`, falling back to `get_body_params()`.
  - It returns `array( 'amount' => …, 'method' => …, 'cashier_id' => get_current_user_id() )`.
- The filter docblock in `Payment_Veto::check()` documents `$context` as `['amount' => minor units or null, 'method' => id or null, 'cashier_id' => int]`.
- Test helpers in `Test_Payment_Pre_Capture.php`:
  - `refuse( array $kinds, … )` records each call in `$this->calls` (`kind`, `context`, `order_id`) and refuses only the listed kinds. `refuse( array() )` observes and allows everything.
  - `assert_call( $order, $kind, $amount, $method )` asserts `$this->calls` is exactly one call with the context `array( 'amount' => $amount, 'method' => $method, 'cashier_id' => get_current_user_id() )`.
  - `payment( $method_id, $amount, $extra = array() )` builds a payment body without `recorded_offline`. `record( $order, $payment )` POSTs it.
  - `create_pos_order()`. `test_pre_capture_without_filter_allows_record()` shows a 200 record of `payment( 'pos_cash', '50.00' )`.

## Changes

1. `Payments_Controller::veto_context()`: build the return array into `$context`. When `'record' === $kind`, add `$context['recorded_offline'] = is_array( $payment ) && (bool) ( $payment['recorded_offline'] ?? false );`, with a one-line comment: the till's own claim, cast as the stored row casts it. Return `$context`. Other kinds get no key. Add a line to the method docblock naming the `record`-only key.

2. `Payment_Veto::check()` filter docblock:
   - The `@param array $context` line becomes `['amount' => minor units or null, 'method' => id or null, 'cashier_id' => int, 'recorded_offline' => bool, record only]`. Keep the column alignment.
   - Add this paragraph before `@since 2.0.7`, after the paragraph that ends "…so the refusal at `close` sees it.", separated by a blank ` *` line:

     > For `record`, `$context['recorded_offline']` is the till's own claim that the sale was finished while the store was unreachable; a callback may choose to allow such a payment and refuse at `close` instead. Core does not check the claim and never applies a refusal differently because of it.

   - Wrap at the paragraph's existing width.

3. Tests in `Test_Payment_Pre_Capture.php`:
   - `assert_call()` gains a last parameter `bool $recorded_offline = false` (docblock `@param` too). When `'record' === $kind`, the expected context also has `'recorded_offline' => $recorded_offline` as its last key. Other kinds are unchanged. No existing call site changes.
   - A new test, `test_pre_capture_record_context_carries_offline_claim( array $extra, bool $expected )`, with a data provider `offline_claims()` and three cases: `'true' => array( array( 'recorded_offline' => true ), true )`, `'false' => array( array( 'recorded_offline' => false ), false )`, `'absent' => array( array(), false )`. Place it right after `test_pre_capture_without_filter_allows_record()`.
     - Arrange: `$order = $this->create_pos_order();` then `$this->refuse( array() );`.
     - Act: `$response = $this->record( $order, $this->payment( 'pos_cash', '50.00', $extra ) );`.
     - Assert: `assertSame( 200, $response->get_status() )`, then `$this->assert_call( $order, 'record', 5000, 'pos_cash', $expected );`.
   - If the `true` case does not answer 200, STOP and report the status and body. Do not change the payment, the order or any helper to work around it.

## Do not

- Add the key for any kind other than `record`.
- Fetch anything (the order, the ledger, the row) to build the key.
- Change any existing assertion or existing `assert_call()` call site.
- Add a test that a key is absent. The existing exact-context assertions cover that.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the in-scope files.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 16
- Max total lines changed: 60

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l includes/API/V2/Payments_Controller.php` exits 0.
- `php -l includes/Payments/Contract/Payment_Veto.php` exits 0.
- `php -l tests/includes/API/V2/Test_Payment_Pre_Capture.php` exits 0.
- `grep -c "recorded_offline" includes/API/V2/Payments_Controller.php` prints at least 1.
- `grep -c "function offline_claims" tests/includes/API/V2/Test_Payment_Pre_Capture.php` prints 1.
- `git diff --stat` lists only the three in-scope files.

## Reviewer reruns (not for Codex)

- `Test_Payment_Pre_Capture.php` and `Test_Payments_Controller.php` under wp-env, all passing. phpcs on both source files.
- Mutations:
  - Drop the `'record' === $kind` condition, so every kind gets the key: the intent, submit, capture and close `assert_call()` checks fail.
  - The key is always `false`: the `true` case fails.
  - The key is always `true`: the `false` and `absent` cases, and the existing record refusal test, fail.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
