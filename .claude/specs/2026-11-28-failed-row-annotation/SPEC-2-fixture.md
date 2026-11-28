# Codex job spec — failed-row annotation 2: seed the fixture row directly

## Goal

The failed-row tests from spec 1 fail in the fixture, not in the code under test. `failed_row()` records a pending `pos_cash` row, `record()` answers a `WP_Error` on this order, and the helper then indexes into that error. The fix is to seed the failed row straight through `Ledger_Seed`: `normalize_row()` fills every other field. Also add the one-line description the spec asked for to the two data-provider tests.

## Stakes

This is a test fixture only, with no source change. The point is that the four new tests run at all.

## In scope (the only files you may edit)

- `tests/includes/Payments/Contract/Test_Ledger.php`

## Out of scope (do not edit)

- `includes/Payments/Contract/Ledger.php`
- `tests/Helpers/Ledger_Seed.php`
- Every other file.

## Context

- `private function failed_row( \WC_Order $order ): array` sits near the end of `Test_Ledger.php`, just before `create_pos_order()`.
- `payment( $method_id, $amount, $extra )` returns `id` (a uuid), `method_id` and `amount`, merged with `$extra`.

## Interfaces and constraints

1. Replace the body of `failed_row()` with:
   ```php
   		$row = $this->payment(
   			'pos_cash',
   			'20.00',
   			array(
   				'kind'           => 'cash',
   				'capture_mode'   => 'manual',
   				'status'         => 'failed',
   				'failure_reason' => 'declined_or_cancelled',
   				'events'         => array( array( 'type' => 'declined' ) ),
   				'provider_refs'  => array( 'reader' => 'sn-1' ),
   			)
   		);
   		Ledger_Seed::rows( $order, array( $row ) );
   		return Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] );
   ```
2. On `test_failed_row_stores_a_same_status_annotation`, turn the docblock `/** @dataProvider annotation_statuses */` into a multi-line docblock. Its first line is `A failed row stores a same-status annotation's events and provider refs, never its status, reason or money.`, followed by a blank docblock line and `@dataProvider annotation_statuses`.
3. On `test_failed_row_ignores_an_annotation_with_other_money`, do the same. The first line is `A failed row ignores an annotation whose amount or currency differs from its own.`, and the tag is `@dataProvider other_money`.

## Do not

- Change any test body, assertion or data provider.
- Commit or push.
- Create plan, notes or scratch files.

## Pre-authorised actions

- Edit the in-scope file.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 30

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l tests/includes/Payments/Contract/Test_Ledger.php` exits 0.
- `grep -c "Ledger::instance()->record( \$order, \$this->payment( 'pos_cash', '20.00', array( 'status' => 'pending' ) ) )" tests/includes/Payments/Contract/Test_Ledger.php` prints 0.
- `git diff --stat` lists only the in-scope file.

## Reviewer reruns (not for Codex)

- `Test_Ledger.php` under wp-env, all passing. The new tests fail against the base `confirm()`.
- Spec 1's mutations.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
