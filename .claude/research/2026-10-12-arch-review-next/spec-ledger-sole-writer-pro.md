# Spec — Pro writes payment rows only through the Ledger's verbs (Pro half; verdict-payments candidate 1, defect D3)

**Repo / lane:** `woocommerce-pos-pro`. Branch from Pro **`origin/next`**, PR targets **`next`**. Every file below is next-only in Pro (`Refund_Sweep.php` is not on Pro `origin/main`).
**Read at:** Pro `808198b` (origin/next). Free `c42191d3` plus the Free PR from `spec-ledger-sole-writer-free.md`.
**Hard precondition:** the Free PR (`spec-ledger-sole-writer-free.md`) is **merged into Free `next`**, and the Free tree Pro's tests load (the sibling `woocommerce-pos` checkout that `vendor/wcpos/woocommerce-pos` symlinks to) contains `Ledger::adopt`, `::settle_allocation`, `::available_for_refund` and `::append_events`. Before editing, check: `grep -n "function adopt\|function settle_allocation\|function available_for_refund\|function append_events" <free tree>/includes/Payments/Contract/Ledger.php` must print four lines. If it does not, STOP: this spec's precondition is missing.

## Goal
Pro stops writing payment rows itself. `Legacy_Adoption`, `Refund_Sweep`, `Refund_Processor` and `Order_Pay_Gateway` call the Free Ledger verbs instead of the raw `Ledger::save()` and instead of editing row arrays (`Legacy_Adoption.php:60-62`, `Refund_Sweep.php:227-265`, `:296-309`) or re-summing allocations (`Refund_Processor.php:373-379`, `Order_Pay_Gateway.php:142-148`). With `settle_allocation()` firing `woocommerce_pos_refund_allocated` again, a provider refund that settles after the till's closure finally writes its fiscal reallocation (D3).

## Stakes
High tier (refunds, provider money, closed-session drawer). A failure here could (a) refund a leg beyond its capacity if the reservation sum changes, (b) drop a provider's final refund answer or its reference, (c) note staff twice or never, or (d) adopt a legacy terminal action twice, or adopt it without the lookup that tells the extension Pro owns it.
Accepted risk, not handled: `Event_Log::event()` writes its WooCommerce log line before the Ledger confirms the allocation was still pending. A settle that races a concurrent writer under the same lock (it cannot, because the sweep holds `Order_Lock`) would leave a log line with no row event.

## Out of scope (do not add)
- No change to any Free file. No change to `Refund_Sweep::sweep()`'s mark/hold logic, `read()`, `note()` wording, `order_ids()`, `mark()`, `allocated()`, or the historical-record branch (`:171-210`, which writes refund meta, not rows).
- Leave `Server_Handler::refund` (`Server_Handler.php:320`) and `Device_Handler` (`:200`) as they are. They return a row to `Ledger::refund()`/`apply_result()`, which is the verb path.
- No `method_exists( Ledger, … )` fallback (the Free verbs are a precondition). No locking changes, no retries, no new hook or filter, no option, no env var, no table, no feature flag. Leave the idempotency stack (verdict candidate 5) and `Refund_Orchestrator` alone.
- No plan or notes files.

## Exact changes

### `includes/Payments/OrderPay/Legacy_Adoption.php` (95 lines)
Lines 60-62 and 71 change. The row assembly (`:44-59`), the live-ref check (`:33-43`) and the lookup option write order all stay.
```php
		// Staged on the order so it rides the Ledger's single save of the new row.
		$order->update_meta_data( self::lookup_key( $adapter->provider(), $provider_ref ), $row['id'] );
		$adopted = $ledger->adopt( $order, $row, false );
		if ( is_wp_error( $adopted ) ) {
			return $adopted;
		}
		// …existing comment block (:63-69) unchanged…
		update_option( self::lookup_key( $adapter->provider(), $provider_ref ), $row['id'], false );
		return $adopted;
```
The order is binding. The order meta is staged **before** `adopt()`, so it lands in the same `$order->save()` as the row (Free pins "adopt writes the order once" in `Test_Ledger::test_adopt_writes_the_order_once_with_the_callers_staged_meta`). The option is written **after** `adopt()` returns.

### `includes/Payments/Server/Event_Log.php` (52 lines)
- Delete `public const MAX_EVENTS = 100;` (`:14-15`). Its only use is `:38` (grepped Pro `includes/` and `tests/`). Free's `Ledger::EVENTS_MAX` (`Ledger.php:35`) is the cap, and its comment says Free owns it.
- Add `public static function event( string $payment_id, string $level, string $message, array $context = array() ): array`. It holds the current body of `append()` from the level clamp through the `wc_get_logger()->log(…)` call, with `'payment_id' => $payment_id`, and returns `$event`. Docblock: "One redacted cashier event, mirrored to the WooCommerce log. The caller hands it to a Ledger verb."
- `append()` keeps its signature and becomes:
```php
		$row['events'][] = self::event( (string) ( $row['id'] ?? '' ), $level, $message, $context );
		$row['events']   = array_slice( $row['events'], -Ledger::EVENTS_MAX );
		return $row;
```
  Add `use WCPOS\WooCommercePOS\Payments\Contract\Ledger;`. Keep the class docblock "Returns row changes only; Free owns persistence."

### `includes/Payments/Server/Refund_Sweep.php` (412 lines)
- **`settle_allocation()`** (`:219-265`). Keep the name, the signature and `private`. Replace the body (`:228-264`) with a read, the verb, and the note:
```php
		$order->read_meta_data( true );
		$ledger  = Ledger::instance();
		$current = $ledger->find( $order, $row_id );
		$pending = null;
		foreach ( $current['refunds'] ?? array() as $allocation ) {
			if ( (int) $allocation['id'] === $refund_id && 'pending' === ( $allocation['status'] ?? '' ) ) {
				$pending = $allocation;
			}
		}
		if ( null === $pending ) {
			return;
		}
		$answered = is_string( $read['provider_ref'] ?? null ) && '' !== $read['provider_ref'] ? $read['provider_ref'] : null;
		$final    = 'pending' !== $read['status'];
		$events   = $final ? array( Event_Log::event( $row_id, 'failed' === $read['status'] ? 'error' : 'info', "Refund {$refund_id} {$read['status']}: {$pending['amount']} {$current['currency']}" ) ) : array();
		$settled  = $ledger->settle_allocation( $order, $row_id, $refund_id, $read['status'], $answered, $events );
		if ( $final && ! is_wp_error( $settled ) ) {
			$this->note( $order, (string) $pending['amount'] . ' ' . $current['currency'], $current['method_id'], array_merge( $read, array( 'provider_ref' => $answered ?? $pending['provider_ref'] ) ) );
		}
```
  The pending-answer rule ("a reference the record did not have is worth keeping; one it has is not replaced") now lives in `Ledger::settle_allocation()`. Move the comment at `:242-244` to the docblock as one line: "The Ledger keeps a pending answer's reference only when the allocation has none." The local `refunded_amount` loop (`:253-257`) is deleted. Drop the `use …\Money;` import only if nothing else in the file uses `Money` (`given_up()` still does, at `:304`/`:311`/`:314`, so it stays).
- **`given_up()`** (`:296-312`). Replace the row loop and the raw save with `append_events()` per row:
```php
		if ( $row ) {
			$order->read_meta_data( true );
			$ledger = Ledger::instance();
			foreach ( $ledger->read( $order ) as $current ) {
				foreach ( $current['refunds'] ?? array() as $part ) {
					if ( (int) $part['id'] === $refund->get_id() && 'pending' === ( $part['status'] ?? '' ) ) {
						$unconfirmed += Money::minor( $part['amount'] );
						$ledger->append_events( $order, $current['id'], array( Event_Log::event( $current['id'], 'error', "Refund {$refund->get_id()} unconfirmed after " . self::GIVE_UP_AFTER / DAY_IN_SECONDS . ' days: ' . $part['amount'] . ' ' . $current['currency'] ) ) );
					}
				}
			}
		} else {
```
  Iterating a snapshot from `read()` while `append_events()` saves is safe: each call re-reads the order's meta and touches only its own row.

### `includes/Payments/Refund_Processor.php` (687 lines)
In `ledger_allocations()`, replace `:373-379` (the `$reserved` loop and `$capacity`) and the condition at `:381` with:
```php
			$capacity = Ledger::available_for_refund( $row );
			// A `partial: false` method takes only a full, first allocation of its row.
			if ( $capacity <= 0 || ( empty( $refunds['partial'] ) && ( $capacity < Money::minor( $row['amount'] ) || $remaining < $capacity ) ) ) {
```
`$reserved > 0` was the same as `$capacity < amount` while the reservation never exceeds the amount, and when it does, `$capacity <= 0` refuses first, as it did before.

### `includes/Payments/OrderPay/Order_Pay_Gateway.php` (202 lines)
In `refund_ledger_rows()`, replace `:142-148` (`$allocated` loop and `$part`) with:
```php
				$part    = min( Ledger::available_for_refund( $row ), $remaining );
```

## Rulings to preserve (each pinned by the named test, which must stay green unmodified)
`tests/includes/Payments/OrderPay/Test_Legacy_Adoption.php`:
- `test_adopt_creates_pending_row_and_resolves_action`: the row shape (pending, source `webview`, mode `server`, kind `card`, provider, `_pos_user` cashier, `{action}` ref, 5-minute expiry), no provider call, order not paid, and order meta written.
- `test_lookup_is_written_only_after_the_row_is_saved`: at every save of the order the option lookup is still unset, and the last save carries the row plus the order meta.
- `test_lookup_survives_an_object_cache_flush`, `test_adopting_same_live_ref_returns_unchanged_row`, `test_unknown_gateway_cannot_be_adopted`, `test_concurrent_adoptions_on_two_orders_both_resolve`, `test_sweeper_polls_and_captures_the_adopted_attempt`.

`tests/includes/Payments/Server/Test_Refund_Sweep.php`, all 26 tests, in particular:
- `test_pending_allocation_marks_the_order_and_free_sweep_confirms_it`: the exact allocation array `{id, amount, status, provider_ref}`, `refunded_amount`, the event message `"Refund {id} succeeded: 5.00 EUR"`, the mark dropped, the confirmation note.
- `test_rejected_refund_keeps_the_record_and_tells_staff_not_to_refund_again`: failed, `refunded_amount` `0.00`, last event level `error`, the record stands, the rejection note.
- `test_still_pending_unanswered_and_malformed_answers_keep_the_order_marked`: five non-final answers leave the allocation pending, no `Refund of` note.
- `test_a_ledger_allocation_without_a_reference_learns_it_and_settles_later`, `test_a_pending_answer_never_replaces_a_reference_the_record_has`: the pending-reference rule.
- `test_a_refund_pending_for_a_month_is_noted_once_and_not_asked_again`, `test_a_month_old_refund_spanning_two_rows_is_noted_for_the_whole_unconfirmed_amount`, `test_a_month_old_refund_with_one_part_confirmed_is_noted_for_the_pending_part_only`: give-up events per row and one note.
- `test_a_till_refund_during_the_sweep_is_kept`, `test_a_till_refund_on_an_order_loaded_before_the_sweep_keeps_the_settlement`, `test_a_mark_written_while_the_sweep_holds_the_order_survives_the_sweep_dropping_its_copy`: the fresh read under the lock and no lost writes.

`tests/includes/Payments/Test_Refund_Processor.php`: `test_refund_processor_original_method_on_a_ledger_order_runs_the_handler_refund`, `test_refund_processor_ledger_allocation_waits_for_the_order_lock`, `test_refund_processor_ledger_allocation_reads_the_ledger_fresh_under_the_lock`, `test_refund_processor_ledger_leg_failure_rolls_the_refund_back`, `test_refund_processor_ledger_leg_reported_failed_rolls_the_refund_back`, `test_refund_processor_ledger_manual_rows_are_allocated_without_a_provider`, plus the other 12 in the file.

`tests/includes/Payments/OrderPay/Test_Order_Pay_Gateway.php`: `test_refund_captured_server_row_allocates_through_ledger`, `test_refund_spans_captured_rows_newest_first`, `test_refund_above_combined_capacity_allocates_nothing`, `test_refund_keeps_the_record_when_a_later_leg_fails_after_one_paid_out`, `test_refund_capacity_counts_allocations_once_and_skips_full_rows`, `test_woocommerce_refund_payment_uses_bootstrap_hook_and_allocates_ledger`.

`tests/includes/Payments/Server/Test_Redactor.php`: `test_secrets_are_redacted_in_events_and_the_wc_log_line` and the rest of the file (`Event_Log::append()` behaviour is unchanged).

## Tests (write first; each new one must fail before the change)
In `tests/includes/Payments/Server/Test_Refund_Sweep.php`:
1. `test_a_settled_refund_fires_refund_allocated_again_with_the_final_row`. Arrange: `list( $order, $row, $refund ) = $this->pending_refund();`. Attach a 4-arg collector on `woocommerce_pos_refund_allocated` **after** `pending_refund()`, so the allocation-time fire is not counted. Set `$this->adapter->refund_reads['refund_q'] = array( 'status' => 'succeeded', 'provider_ref' => 'refund_q' );`. Act: `( new Refund_Sweep() )->run();`. Assert: one call, its row's `refunds[0]['status']` is `'succeeded'`, the 4th argument is `'5.00'`, and the 3rd argument's `get_id()` is the refund id. A second `run()` adds no call. Before the change this fails: the raw `save()` fires nothing.
2. `test_a_still_pending_refund_does_not_fire_refund_allocated`. Same arrangement, with `refund_reads['refund_q'] = array( 'status' => 'pending', 'provider_ref' => 'refund_q' )`. After `run()`, zero calls. (This passes before and after. It pins that only a final answer re-fires.)

In `tests/includes/Payments/Server/Test_Redactor.php`:
3. `test_event_is_redacted_and_logged_without_a_row`. With the `Server_Log_Spy` filter as in the first test, `Event_Log::event( 'payment_1', 'warning', 'Bearer abc.def', array( 'access_token' => 'sk_live_' . str_repeat( 'x', 24 ) ) )` returns an array with keys `t, level, message, context`. `level` is `'warning'`, the message is redacted, the spy logged one line with `payment_id` `'payment_1'`, and an invalid level (`'loud'`) becomes `'info'`.
4. `test_append_keeps_the_newest_ledger_events_max`. `Event_Log::append()` on a row already holding `Ledger::EVENTS_MAX` events leaves `Ledger::EVENTS_MAX` events, the newest last. (Add the `use` for `Ledger`.)

New file `tests/includes/Payments/Test_Ledger_Writes_Through_Verbs.php` (extends `\WP_UnitTestCase`, `@coversNothing`):
5. `test_pro_never_saves_ledger_rows_or_edits_allocations_directly`. Iterate Pro `includes/**/*.php` (`dirname( __DIR__, 3 ) . '/includes'`). Collect `relative/path:line` for lines matching any of:
   - `/(?:\$ledger|Ledger::instance\(\s*\))\s*->\s*save\s*\(/` (a raw save),
   - `/\[\s*'refunded_amount'\s*\]\s*=(?!=)/` (recomputing the rollup),
   - `/\[\s*'refunds'\s*\]\s*\[\s*\$/` (editing an existing allocation by index; the handlers' `['refunds'][] =` appends are the verb path and do not match).
   Assert `assertSame( array(), $hits )`. Before the change it must report `Legacy_Adoption.php:62`, `Refund_Sweep.php:248`, `:250`, `:251`, `:257`, `:261` and `:309`.

Delete no tests. Tests that seed rows through `Ledger::instance()->save()` (for example `Test_Refund_Sweep.php`, `Test_Device_Handler.php`, `Provider_Conformance_Test_Case.php`) stay: `save()` is `@internal`, and seeding is what it is kept public for.

**New files are untracked.** List `tests/includes/Payments/Test_Ledger_Writes_Through_Verbs.php` in the final report so the orchestrator `git add`s it.

## Budget (from `wc -l`: `Legacy_Adoption.php` 95, `Refund_Sweep.php` 412, `Refund_Processor.php` 687, `Order_Pay_Gateway.php` 202, `Event_Log.php` 52; tests `Test_Refund_Sweep.php` 616, `Test_Redactor.php` 189)
- Non-test: at most **90 added lines** across `includes/` (`git diff --numstat` added column). The net non-test change must be **≤ 0**: the expected shape is about −35 in `Refund_Sweep`, −8 in `Refund_Processor`/`Order_Pay_Gateway`, and +20 in `Event_Log`.
- Total diff (added + deleted, including tests): at most **380 lines**.
If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
- `php -l` on each changed or new file.
- `/Users/kilbot/Projects/woocommerce-pos-pro/vendor/bin/phpcs --standard=.phpcs.xml.dist` on the five changed `includes/` files (run from the Pro tree root).
- `grep -rn -- "->save(" includes/Payments | grep -i ledger` prints nothing.
- The orchestrator runs these afterwards in Pro's wp-env against a Free sibling at the merged Free `next`, **one file per invocation**: `tests/includes/Payments/Server/Test_Refund_Sweep.php`, `tests/includes/Payments/Server/Test_Redactor.php`, `tests/includes/Payments/Test_Ledger_Writes_Through_Verbs.php`, `tests/includes/Payments/OrderPay/Test_Legacy_Adoption.php`, `tests/includes/Payments/OrderPay/Test_Order_Pay_Gateway.php`, `tests/includes/Payments/Test_Refund_Processor.php`, `tests/includes/Payments/Server/Test_Server_Handler.php`, `tests/includes/Payments/Device/Test_Device_Handler.php`, `tests/includes/Conformance/` (the server-provider conformance suite). Then Free's `tests/includes/API/V2/Test_Refund_Session_Binding.php` once more, unchanged.

## Sequencing and version skew
1. The Free PR (`spec-ledger-sole-writer-free.md`) merges into Free `next` **first**. Pro `next` requires `wcpos/woocommerce-pos: dev-next` (`composer.json:25`; lane enforced by `.github/workflows/tests-php.yml:53-55`) and resolves it on every CI run with no lock file. So this PR's CI goes red with `Call to undefined method …Ledger::settle_allocation()` until the Free merge is on `next`. Do not open this PR before then. Do not "fix" that red with a fallback.
2. This PR merges into Pro `next`. Only then is D3 fixed in a Pro build. The Free PR alone changes no Pro behaviour: Pro still calls raw `save()`, and only `settle_allocation()` re-fires the hook.
3. The window between the two merges is safe both ways. Old Pro on new Free keeps working (additive Free). New Pro on old Free cannot be built (CI) and cannot be released, because the release pins Free (`release.yml:139`, `dev-main#${FREE_PIN}`): when `next` moves to `main` for the release, `FREE_PIN` must be at or after the Free merge commit.
4. Runtime skew on merchant sites: Pro is included before `woocommerce-pos/` and declares Free's classes from its vendored copy, so the Ledger in memory normally has the verbs. A site whose filtered plugin order loads an older standalone Free `Ledger` first would fatal on the first sweep or refund. That is the same accepted exposure every new Free method Pro calls carries. Do not add `method_exists` guards: they would keep the raw writes this PR removes.
5. dev servers: Pro `next` deploys go to dev-next, which picks up both halves only after this PR merges and its deploy runs. Free merging alone does not reach any Pro environment.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (plus the new file you list).
