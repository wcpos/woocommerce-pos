# Spec — a leg's `updated_at_gmt` moves only when the leg changes (verdict D4)

**Repo / lane:** `woocommerce-pos` (Free), branch from **`origin/next`**. `Ledger.php` is next-only.

## Goal
`Ledger::save()` normalizes every row, and `normalize_row()` sets `updated_at_gmt = now` on all of them (`Ledger.php:1185`, `:1211`). A write to one leg therefore makes every other leg on the order look fresh. `Payments_Sweeper::run()` skips rows updated within its threshold (`Payments_Sweeper.php:61-62`), so a stuck leg is not polled, voided or expired while anything else on the order keeps writing. Make `save()` keep the stored `updated_at_gmt` for a row whose content did not change.

## Stakes
High tier (payments). If this goes wrong, a changed row could keep an old stamp (the sweeper polls it early, which is harmless: status polling is idempotent), or an unchanged row could get a new stamp (today's behaviour). Accepted risk, not handled: two `gmdate()` calls straddling a second boundary make an unchanged row count as changed, which is just today's behaviour.

## Out of scope
Do not touch `normalize_row()`'s other defaults, `Payments_Sweeper`, `replace_and_save()`, `to_wire()` or any caller. Add no new row field (no `last_polled_at`), no option, no env var, no filter. Do not change the sweeper threshold.

## Exact change
File: `includes/Payments/Contract/Ledger.php`, method `save()` (lines 960-994). Replace only the first loop (lines 961-964, `$normalized = array(); foreach … normalize_row …`) with:

```php
		// updated_at_gmt is when a leg last changed. normalize_row() stamps now, so a row whose
		// content is what is already stored keeps its stored stamp: a write to one leg must not
		// make every other leg on the order look fresh to Payments_Sweeper.
		$stored = array();
		foreach ( $this->read( $order ) as $existing ) {
			if ( is_array( $existing ) && isset( $existing['id'] ) ) {
				$stored[ strtolower( (string) $existing['id'] ) ] = $existing;
			}
		}
		$normalized = array();
		foreach ( $rows as $row ) {
			$row      = $this->normalize_row( $order, $row );
			$previous = $stored[ $row['id'] ] ?? null;
			$stamp    = null === $previous ? null : $this->valid_time( $previous['updated_at_gmt'] ?? null );
			if ( null !== $stamp && $this->same_content( $this->normalize_row( $order, $previous ), $row ) ) {
				$row['updated_at_gmt'] = $stamp;
			}
			$normalized[] = $row;
		}
```
Add one private helper next to `replace_and_save()`:

```php
	/**
	 * Whether two normalized rows hold the same content, ignoring when they were written.
	 *
	 * @param array $a Normalized row.
	 * @param array $b Normalized row.
	 */
	private function same_content( array $a, array $b ): bool {
		unset( $a['updated_at_gmt'], $b['updated_at_gmt'] );
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}
```
Check `valid_time()`'s signature (`private function valid_time( $value ): ?string`, near `:1284`) and use it as it is. The rest of `save()` (wire encoding, index meta, derive, `$order->save()`) is unchanged.

## Rulings to preserve
- A new row, or a row whose content changed, gets `updated_at_gmt = now`, exactly as today. Pinned by `tests/includes/Payments/Contract/Test_Payments_Sweeper.php::test_sweeper_stale_pending_is_polled_but_fresh_row_is_not` and `::test_sweeper_threshold_and_created_timestamp_fallback`.
- The sweeper polls each stale leg once per run and re-dispatches only unanswered legs. Pinned by all 25 tests in `Test_Payments_Sweeper.php`.
- A caller that passes an old `updated_at_gmt` for an otherwise unchanged row does **not** back-date it: the stored stamp wins. Pro's `Test_Device_Handler.php:358` saves a row with only `updated_at_gmt` changed and expects the sweeper to leave it alone. That still holds.
- The wire shape and every other field are unchanged. Pinned by `Test_Ledger.php` (64 tests).

## Tests (write first; each must fail before the change)
1. In `tests/includes/Payments/Contract/Test_Payments_Sweeper.php`, add `test_sweeper_polls_a_stale_leg_after_another_leg_on_the_order_was_written`:
   - Arrange: `list( $order, $id ) = $this->leg( 600 );` then write a second leg: `$rows = Ledger::instance()->read( $order ); $rows[] = array( 'id' => wp_generate_uuid4(), 'method_id' => 'pos_cash', 'capture_mode' => 'manual', 'amount' => '1.00', 'status' => 'captured' ); Ledger::instance()->save( $order, $rows );`
   - Act: `( new Payments_Sweeper() )->run();`
   - Assert: `assertSame( array( $id ), Sweep_Test_Handler::$calls )`.
2. In `tests/includes/Payments/Contract/Test_Ledger.php`, add `test_save_keeps_the_stamp_of_an_untouched_leg_and_restamps_a_changed_one`:
   - Arrange: `$order = $this->create_pos_order();` save two legs with `Ledger::instance()->save()` (manual `pos_cash`, captured, `'5.00'` each). Then back-date both by rewriting `Ledger::META_KEY` directly (read rows, set `updated_at_gmt` to `gmdate( 'c', time() - 3600 )`, `update_meta_data( Ledger::META_KEY, wp_json_encode( array( 'schema' => 1, 'payments' => $rows ) ) )`, `save()`), as `Test_Payments_Sweeper::leg()` does.
   - Act: read the rows, change only the second leg (`$rows[1]['receipt'] = array( 'number' => 'R-2' )`), and call `Ledger::instance()->save( $order, $rows )`.
   - Assert: the first leg's `updated_at_gmt` equals the back-dated value (`assertSame`); the second leg's is within the last minute (`assertGreaterThan( time() - 60, strtotime( … ) )`).

Delete no tests.

## Budget
Non-test: at most **35 added/changed lines** in `Ledger.php`. Total diff, including tests and lint docblocks: at most **170 lines**.
If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
- `php -l includes/Payments/Contract/Ledger.php tests/includes/Payments/Contract/Test_Ledger.php tests/includes/Payments/Contract/Test_Payments_Sweeper.php`
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Payments/Contract/Ledger.php`. The test files follow the existing file's style; `Test_Payments_Sweeper.php` already uses compact one-line helpers, so match it.
- The orchestrator runs afterwards in wp-env (one file per invocation): `Test_Payments_Sweeper.php`, `Test_Ledger.php`, `tests/includes/API/V2/Test_Gateway_Submission.php`, `tests/includes/API/V2/Test_Refund_Session_Binding.php`; then Pro `tests/includes/Payments/Device/Test_Device_Handler.php` and `tests/includes/Payments/Server/Test_Server_Handler.php` against this Free tree.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
