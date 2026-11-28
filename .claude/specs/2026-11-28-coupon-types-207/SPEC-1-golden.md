# Codex job spec — coupon types 1 of 3: a golden test of today's payments answers

## Goal

Add one PHPUnit test file. It dispatches every payment command kind that a later change will gate (`record`, `intent`, `capture`, `submit`, `close`), with no extra filter registered. It normalises each answer and compares it with a JSON fixture.

The reviewer will generate the fixture from today's code. After that, the test proves that the payments answers stay byte-for-byte the same when no `wcpos_payment_pre_capture` callback is registered. That filter does not exist yet: spec 3 adds it. This file must not mention it.

## Stakes

High tier (the money routes), but this change is test-only. Rows of the money table this file covers: none directly. It is the regression floor for every row in spec 3.

## In scope (the only file you may create)

- `tests/includes/API/V2/Test_Payments_No_Veto_Golden.php` (new)

## Out of scope (do not edit or create)

- Everything under `includes/`
- Every other test file, including `tests/includes/API/V2/Test_Payments_Controller.php` (read it for patterns; do not change it)
- `tests/fixtures/payments-no-veto-golden.json`: do NOT create it. The reviewer generates it.
- `scripts/`, `tests/lane-coverage/`, `.github/`, CHANGELOG, readme

## Context (read these first)

- `tests/includes/API/V2/Test_Payments_Controller.php`. Patterns to copy:
  - `create_pos_order()` (line ~3038);
  - `payment()` (~3055);
  - `record()` (~3073);
  - `command()` (~1650);
  - `use_route_handler()` (~1639);
  - `intended()` (~1695);
  - `captured_row()` (~2969);
  - `close()`;
  - the `Route_Handler` class (~3106-3170);
  - `setUp()`/`tearDown()` with the `wcpos_logging` capture filter.
- `tests/includes/API/V2/Test_Gateway_Submission.php`, the `submit()` helper (~1722).
- `tests/Helpers/Money_Route_Envelope.php`: `envelope()` merges the body after `command_id` and `revision`, so a `command_id` key in the body wins.
- `tests/Helpers/Ledger_Seed.php`: `Ledger_Seed::rows( $order, array( $row ), false )`.

## Interfaces and constraints

1. Namespace `WCPOS\WooCommercePOS\Tests\API\V2`. The class `Test_Payments_No_Veto_Golden extends WCPOS_REST_Unit_Test_Case` uses `Money_Route_Envelope`.
   - setUp: copy `Test_Payments_Controller`'s setUp log capture (a `wcpos_logging` filter that returns false, plus `Logger::reset_dedup_state()`).
   - tearDown removes the filter. Nothing else.
2. Define your own capture-mode handler in this file, after the test class, with the same phpcs disable comment that `Test_Payments_Controller.php` uses above `Route_Handler`:
   - `class Golden_Route_Handler extends \WCPOS\WooCommercePOS\Payments\Contract\Manual_Handler`;
   - `describe()` sets `capture.mode` to `golden_route`;
   - `intent()` returns the row with `handoff => array( 'reader' => $context['reader'] )`;
   - `capture()` returns `array_merge( array( 'status' => 'captured' ), $context )`.

   No static counters or error queues are needed. Register it in a private helper the same way `use_route_handler()` does, with mode `golden_route`.
3. Every id the test sends is a fixed literal, never `wp_generate_uuid4()`: payment row ids, command ids and attempt ids. Use distinct lowercase v4-shaped UUID literals, declared as class constants. Pass `command_id` inside the body given to `envelope()`.
4. Scenarios. Each builds its own order(s) and records one answer under a fixture key:
   - `record_partial`: `create_pos_order()` (total 92.95), record `pos_cash` `50.00`.
   - `record_full`: a new order, record `pos_cash` `92.95`.
   - `record_replay`: a new order. Record `pos_cash` `50.00` with command id X, then send the identical body (same command id, same revision string as the first send). The second answer is the recorded one.
   - `intent`: register the golden handler. A new order, POST `/wcpos/v2/orders/{id}/payments/{uuid}/intent` with `payment` (`pos_cash`, `92.95`, fixed uuid) and `context => array( 'reader' => 'reader-1' )`.
   - `capture`: register the golden handler. A new order, intent as above (assert 200), then POST `.../capture` with `context => array( 'amount' => '92.95' )` at the order's current revision.
   - `submit`: a new order, POST `/wcpos/v2/orders/{id}/payment-methods/pos_cash/submit` with a fixed `attempt_id` and `values => array()`. Record whatever status and data come back, even an error.
   - `close`: a new order. Seed one captured row (`captured_row()` shape, with a fixed id) with `Ledger_Seed::rows( $order, array( $row ), false )`, then POST `/wcpos/v2/orders/{id}/close` with `envelope()`.

   Each route literal must contain `wcpos/v2`, for example `'/wcpos/v2/orders/' . $order->get_id() . '/close'`. CI's lane-coverage scanner credits only literals that contain `wcpos/v2`.
5. One test method per scenario, named `test_no_callback_<key>_answer_matches_the_golden_fixture`.
   - Each builds `array( 'status' => $response->get_status(), 'data' => $this->normalise( $response->get_data() ) )` and calls `$this->golden( '<key>', $answer )`.
   - For an error response, `get_data()` already has `code`, `message`, `data`; keep it as it is.
6. `normalise( $value, string $key = '' )` is recursive. In order:
   - an array recurses, keeping its key order;
   - a key `revision` or `currentRevision` with a string value becomes `'<revision>'`;
   - an int or numeric-string value under a key matching `/(^|_)id$/` becomes `'<int>'`;
   - an int under a key matching `/(_at|_time|_date|^time|^date)$/` becomes `'<time>'`;
   - a key `order_key` becomes `'<order_key>'`;
   - a string matching `/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/` becomes `'<date>'`;
   - a string that is a UUID (`/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i`) and is not one of the class constants becomes `'<uuid>'`;
   - anything else is unchanged.

   An object (`stdClass`, as in an empty handoff) is cast to an array first, and its fixture value is then the array. Keep that rule.
7. `golden( string $key, array $answer )`:
   - Fixture path: `dirname( __DIR__, 3 ) . '/fixtures/payments-no-veto-golden.json'`.
   - When `getenv( 'WCPOS_GOLDEN_WRITE' ) === '1'`:
     - read the existing fixture, or start from `array()`;
     - set `$all[ $key ] = $answer`, then `ksort( $all )`;
     - write `wp_json_encode( $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"` with `file_put_contents` (add a `// phpcs:ignore` naming the sniff);
     - then `$this->assertTrue( true )`.
   - Otherwise:
     - fail with `'Golden fixture missing: run once with WCPOS_GOLDEN_WRITE=1 on the base commit.'` when the file or the key is absent;
     - else `$this->assertSame( wp_json_encode( $expected_entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), wp_json_encode( $answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )`.
8. Docblocks on the class, every method and every property, in the style of the neighbouring tests. Tabs for indentation.
   - The class docblock says in one line: the payments answers with no veto callback registered, compared with a fixture generated on the base commit.

## Do not

- Edit or create any file except the one in scope. In particular, no fixture file.
- Mention `wcpos_payment_pre_capture`, coupons or vetoes in the file.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Create the in-scope file
- Run the acceptance commands

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 300

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

```sh
php -l tests/includes/API/V2/Test_Payments_No_Veto_Golden.php
php scripts/lane-coverage.php --compare=/Users/claude/.claude/jobs/a1d68413/tmp/lane-base-coupons/base.json   # must exit 0
```

## Reviewer reruns (not for Codex)

These run under wp-env:
- run with `WCPOS_GOLDEN_WRITE=1` on this commit (includes/ is the base d4495ab7), then run twice without it; both runs must pass (the normalisation is stable);
- phpcs on the file shows 0 errors.

## Environment

- Network: no
- Writable outside the worktree: none (base.json is read-only input)

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
