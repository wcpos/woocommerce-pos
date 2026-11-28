# Codex job spec — a failed payment row takes a same-status annotation

## Goal

At the moment, `Ledger::confirm()` returns `null` for every `failed` row without writing anything. So when a provider reports a charge after a payment failed, the report cannot even be noted on the row, because `apply_result()` drops it. After this job, a failed row stores the `events` and `provider_refs` of a patch, provided the patch's status is `failed` or absent and its amount and currency equal the row's. Status, `failure_reason` and money never change, and a patch with any other status is still ignored.

## Stakes

This is the core plugin's money path. The failure this job prevents is a failed row whose status, reason or amount gets changed: a failed payment must never count as paid. The annotation is metadata only. Treat concurrency as covered by the existing write lock: add no locking.

Combination table, each row covered by a test in this spec:

| Case | Expected | Test |
|---|---|---|
| failed row, patch status `failed`, same money, new events + provider refs | events and refs stored; status, reason, amount unchanged | `test_failed_row_stores_a_same_status_annotation` (`status failed`) |
| failed row, patch with no status, same money | same as above | `test_failed_row_stores_a_same_status_annotation` (`status absent`) |
| patch also carries a different `failure_reason` | reason unchanged | both data sets above carry `failure_reason => 'other_reason'` |
| same event delivered twice | second delivery stores nothing new | `test_failed_row_annotation_is_stored_once_per_event` |
| failed row, patch status `captured` | `apply_result()` returns a WP_Error; row unchanged | `test_failed_row_refuses_a_captured_patch` |
| failed row, patch amount differs | annotation ignored; row unchanged | `test_failed_row_ignores_an_annotation_with_other_money` (`amount`) |
| failed row, patch currency differs | annotation ignored; row unchanged | `test_failed_row_ignores_an_annotation_with_other_money` (`currency`) |

Not covered, on purpose:
- Failed rows whose `failure_reason` is one of the four in `Ledger::refusal_error()` (`order_already_paid`, `amount_exceeds_balance`, `amount_mismatch`, `payment_in_flight`). `apply_result()` returns the refusal before it writes anything, and that stays as it is.
- A parked confirmation drained onto a failed row. It reaches the new branch through the same `confirm()`, but this spec does not test that path.

## In scope (the only files you may edit)

- `includes/Payments/Contract/Ledger.php`
- `tests/includes/Payments/Contract/Test_Ledger.php`

## Out of scope (do not edit)

- `includes/Payments/Contract/Settlement.php`
- `tests/includes/Payments/Contract/Test_Settlement.php`
- `tests/Helpers/Ledger_Seed.php`
- Every other file. Lockfiles, CI config, docs, the changelog.

## Context

- `Ledger::confirm()` is at `includes/Payments/Contract/Ledger.php:1197`. Today it opens with:
  ```php
  		$row   = $draft->row( $id );
  		$event = $patch['event_id'] ?? null;
  		if ( ! $row || 'failed' === $row['status'] ) {
  			return null;
  		}
  ```
- `apply_transition( array $row, array $new )` (Ledger.php:2249) copies listed fields and merges `provider_refs`: keys in the patch are written, others are kept, and nulls are ignored. A patch with no `status` keeps the row's status.
- `self::SEEN_EVENTS_MAX` caps `seen_events`. The existing non-failed path appends the event id at Ledger.php:1218-1221.
- `apply_result()` (Ledger.php:1022) already returns early for a seen `event_id`. For a failed row it refuses any status other than `failed` before the write, through `confirmed()` → `apply_transition()` → `invalid_transition()`.
- Test helpers in `Test_Ledger.php`: `create_pos_order()` (line ~4058) and `payment( $method_id, $amount, $extra )` (line ~4069). `Ledger_Seed::rows( $order, array( $row ) )` stores rows through the ledger's write.
- A pending cash row is recorded with `Ledger::instance()->record( $order, $this->payment( 'pos_cash', '20.00', array( 'status' => 'pending' ) ) )` (pattern at Test_Ledger.php:212). Moving pending → failed is allowed by the write's invariants.

## Interfaces and constraints

Replace the opening of `confirm()` quoted above with exactly this. Everything after it stays unchanged.

```php
		$row   = $draft->row( $id );
		$event = $patch['event_id'] ?? null;
		if ( ! $row ) {
			return null;
		}
		if ( 'failed' === $row['status'] ) {
			// A failed row takes only a same-status annotation, its events and provider refs: never a status, reason or money.
			if ( 'failed' === ( $patch['status'] ?? 'failed' ) && ( $patch['amount'] ?? $row['amount'] ) === $row['amount'] && ( $patch['currency'] ?? $row['currency'] ) === $row['currency'] ) {
				$annotated = $this->apply_transition( $row, array_intersect_key( $patch, array_flip( array( 'events', 'provider_refs' ) ) ) );
				if ( is_string( $event ) ) {
					$annotated['seen_events'] = array_slice( array_merge( $annotated['seen_events'] ?? array(), array( $event ) ), -self::SEEN_EVENTS_MAX );
				}
				$draft->put( $annotated );
			}
			return null;
		}
```

Update the `confirm()` docblock's first sentence only if phpcs requires it. Otherwise leave the docblock alone.

Tests: add the four tests named in the combination table to `Test_Ledger.php`, plus one private fixture helper and two data providers. Use Arrange / Act / Assert comments, `assertSame( expected, actual )`, and a one-line docblock on each test.

- Private helper `failed_row( \WC_Order $order ): array`:
  - Record a pending `pos_cash` row of `'20.00'`.
  - Set `status` to `'failed'`, `failure_reason` to `'declined_or_cancelled'`, `events` to `array( array( 'type' => 'declined' ) )` and `provider_refs` to `array( 'reader' => 'sn-1' )`.
  - Seed it with `Ledger_Seed::rows( $order, array( $row ) )`.
  - Return `Ledger::instance()->find( wc_get_order( $order->get_id() ), $row['id'] )`.
- `test_failed_row_stores_a_same_status_annotation( array $status )`, data provider `annotation_statuses()` with `'status failed' => array( array( 'status' => 'failed' ) )` and `'status absent' => array( array() )`.
  - Patch: `$status + array( 'amount' => $row['amount'], 'currency' => $row['currency'], 'failure_reason' => 'other_reason', 'events' => array_merge( $row['events'], array( array( 'type' => 'late_charge' ) ) ), 'provider_refs' => array( 'charge' => 'txn_1' ), 'event_id' => 'evt_late' )`.
  - Call `Ledger::instance()->apply_result( $order, $row['id'], $patch )`.
  - Re-read the stored row from a fresh `wc_get_order()`.
  - Assert the following, each with `assertSame`:
    - status `'failed'`;
    - failure_reason `'declined_or_cancelled'`;
    - amount `'20.00'`;
    - events `array( array( 'type' => 'declined' ), array( 'type' => 'late_charge' ) )`;
    - provider_refs `array( 'reader' => 'sn-1', 'charge' => 'txn_1' )`;
    - `'evt_late'` is in `seen_events` (`assertContains`).
- `test_failed_row_annotation_is_stored_once_per_event`:
  - Apply the `status failed` patch above.
  - Apply a second patch with the same `event_id` whose `events` add one more entry (`array( 'type' => 'again' )`) and whose `provider_refs` set `'charge' => 'txn_2'`.
  - Assert that the stored events still end with `late_charge` (count 2), and that `provider_refs['charge']` is still `'txn_1'`.
- `test_failed_row_refuses_a_captured_patch`:
  - Patch: `status => 'captured'`, the row's amount and currency, events with one added entry, `provider_refs => array( 'charge' => 'txn_1' )`.
  - Assert `apply_result()` returns a `WP_Error`.
  - Assert the stored row's status, failure_reason, amount, events and provider_refs all equal the fixture's.
- `test_failed_row_ignores_an_annotation_with_other_money( array $money )`, data provider `other_money()` with `'amount' => array( array( 'amount' => '21.00' ) )` and `'currency' => array( array( 'currency' => 'XTS' ) )`.
  - Patch: `$money + array( 'status' => 'failed', 'amount' => '20.00', 'currency' => <row currency>, 'events' => <fixture events plus one>, 'provider_refs' => array( 'charge' => 'txn_1' ) )`.
  - `$money` comes first, so its key wins.
  - Assert the stored events and provider_refs equal the fixture's, and that status and amount are unchanged.

## Do not

- Add locking, retries, fallbacks, caching or validation layers.
- Touch any other branch of `confirm()`, `apply_result()`, `confirmed()`, `apply_transition()` or `refusal_error()`.
- Add a helper method to `Ledger`.
- Add new dependencies, feature flags, compatibility shims, options, tables or migrations.
- Refactor or reformat code outside the change.
- Change public signatures.
- Create plan, notes or scratch files.
- Commit or push.

## Pre-authorised actions

- Edit the two in-scope files.
- Run the acceptance commands.

## Budget

- Max non-test lines changed: 16
- Max total lines changed: 170

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

- `php -l includes/Payments/Contract/Ledger.php` exits 0.
- `php -l tests/includes/Payments/Contract/Test_Ledger.php` exits 0.
- `grep -c "function test_failed_row_" tests/includes/Payments/Contract/Test_Ledger.php` prints 4.
- `git diff --stat` lists only the two in-scope files.

## Reviewer reruns (not for Codex)

- `Test_Ledger.php`, `Test_Ledger_Write.php`, `Test_Settlement.php` and `Test_Payments_Controller.php` under wp-env, all passing. phpcs on `Ledger.php`.
- The new tests fail against the base `confirm()`, which shows the fix needed them.
- Mutations, each of which must be killed:
  - drop the `array_intersect_key` (pass `$patch`);
  - drop the amount guard;
  - drop the currency guard;
  - drop the `seen_events` append.
- The status guard can only be reached on the parked path. A mutant of it surviving `apply_result()` tests is expected, and must be reported as such.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
