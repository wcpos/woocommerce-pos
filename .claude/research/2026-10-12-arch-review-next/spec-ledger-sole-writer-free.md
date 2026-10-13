# Spec — the Ledger is the only writer of payment rows (Free half; verdict-payments candidate 1, defect D3)

**Repo / lane:** `woocommerce-pos` (Free). Branch from **`origin/next`**, PR targets **`next`**. `includes/Payments/Contract/Ledger.php` and `Webview_Passthrough.php` are next-only (not on `origin/main`), so there is no main-lane twin and no sync conflict.
**Read at:** Free `c42191d3` (origin/next, includes #2186 ledger `updated_at` and #2187 webview refunds). Pro `808198b` (origin/next).
**Paul's ruling (2026-10-13):** the Ledger becomes the only writer of payment rows through four new public verbs that Pro calls; `save()` becomes `@internal` (stays public: tests seed through it); `woocommerce_pos_refund_allocated` fires again when a pending allocation settles, so the fiscal writers see the settlement.
**Companion:** `spec-ledger-sole-writer-pro.md` moves Pro onto these verbs. It lands only **after this PR merges** (see Sequencing).

## Goal
Every production write of a payment row goes through a Ledger verb that enforces the row's rules and fires its hooks. Today `Webview_Passthrough::mint` (`Webview_Passthrough.php:196`), Pro `Legacy_Adoption.php:62` and Pro `Refund_Sweep.php:261,309` call the raw `save()`. The raw write at `Refund_Sweep.php:261` is why a provider refund that settles after the till's closure never reaches the fiscal records (D3). The "pending + succeeded allocations reserve the row" sum is written at `Ledger.php:624-632` and `:665-678`, and again in Pro at `Refund_Processor.php:373-378` and `Order_Pay_Gateway.php:142-147`. It becomes one method.

## Stakes
High tier (payments, refunds, drawer variance on a closed session). A failure here could do three things. (a) A settle that fires the hook twice could double a fiscal reallocation. The fiscal store refuses that through `UNIQUE KEY type_source (type, source_id)` (`Fiscal_Record_Store.php:73`) with a deterministic per-(refund,row) `source_id` (`Fiscal_Record_Writers.php:410-411`), so the double is absorbed, but the test still pins "fires once". (b) An adopted row could be refused and lose a payment WooCommerce already took. (c) A changed reservation sum could over-refund a leg.
Accepted risk, not handled: a third-party listener on `woocommerce_pos_refund_allocated` that assumes the hook fires only at allocation time. Grepped Free `includes/` and `tests/` and Pro `includes/` and `tests/`. The only listeners are `Fiscal_Record_Writers::handle_allocation` (`Fiscal_Record_Writers.php:35`), which acts only on `succeeded` (`:364-373`), and Pro `Refund_Sweep::allocated` (`Refund_Sweep.php:47`, `:59-66`), which acts only on `pending`.

## Out of scope (do not add)
- No change to `save()`'s visibility or signature. No change to `record()`, `intent()`, `capture()`, `apply_result()`, `void()`, `status()` or `derive()`.
- No `end_unanswered()` dedup (verdict candidate 2), no tip carve-out move, no `record()` allowlist (verdict "Other finding").
- No locking inside the verbs. Callers already hold `Order_Lock` (Pro `Refund_Sweep.php:110`, `Refund_Processor.php` `allocate_refund`). No retries, no new hook, no new filter, no new endpoint, no option, no table, no env var, no feature flag.
- No `method_exists` shims for Pro and no change to any Pro file. That is the Pro spec.
- No plan or notes files.

## The interface (four verbs, plus `@internal` on `save()`)
Naming against the ruling: Paul listed `adopt()`, `settle_allocation()`, `available_for_refund()` "and a row-minting verb". In the code, adopting a legacy action (Pro `Legacy_Adoption`) and minting a webview row (`Webview_Passthrough::mint`) are the same operation: append a row the Ledger did not open, with no balance check and no handler call, refusing an id that already exists. So they are **one** verb, `adopt()`. The fourth verb is `append_events()`. Pro's month-old-refund path (`Refund_Sweep::given_up`, `:296-309`) needs it, and so does Free's own `record_failure_events` (`Ledger.php:509-522`). Without it a raw `save()` would remain. Record this mapping in the PR body.

All four go in `includes/Payments/Contract/Ledger.php`, in this order, after `refund()` (ends `:688`):

1. **`public function adopt( WC_Order $order, array $row, bool $derive = true )`** returns `array|WP_Error`.
   Docblock: "Append a leg the Ledger did not open: money a WooCommerce gateway already took (`payment_complete()`, the order-pay page), or a provider attempt started outside the POS. It does no balance check and no handler call. The caller decides that the payment is not already represented. One `$order->save()`, so order meta the caller staged rides the same write."
   Body:
   - `$id = strtolower( (string) ( $row['id'] ?? '' ) )`. If `! Pos_Uuid::is_uuid( $id )`, return `$this->invalid( __( 'Payment id must be a UUID.', 'woocommerce-pos' ) )`.
   - `$rows = $this->read( $order )`. If `$this->find_in_rows( $rows, $id )` finds a stored row, return `new WP_Error( 'wcpos_payment_conflict', __( 'Payment id conflicts with an existing payment.', 'woocommerce-pos' ), array( 'status' => 409, 'payment' => self::to_wire( $stored ) ) )`. Same code and message as `replay()` (`:762-770`).
   - `$row['id'] = $id; $rows[] = $row; $this->save( $order, $rows, $derive ); return $this->find( $order, $id );`
   - No hook. Neither caller fires one today.
2. **`public function settle_allocation( WC_Order $order, string $id, int $refund_id, string $status, ?string $provider_ref = null, array $events = array() )`** returns `array|WP_Error`.
   Docblock: "Apply a provider's later answer to a **pending** refund allocation. A final answer (`succeeded`, `failed`) sets the allocation's status and reference, recomputes `refunded_amount`, and fires `woocommerce_pos_refund_allocated` again. A `pending` answer only records a reference the allocation does not have yet. An allocation that is no longer pending, or does not exist, is returned unchanged. The caller holds the order lock and has re-read the order's meta."
   Body, in order:
   - If `$status` is not in `array( 'pending', 'succeeded', 'failed' )`, return `$this->invalid( __( 'Refund status is invalid.', 'woocommerce-pos' ) )`.
   - `$rows = $this->read( $order ); $row = $this->find_in_rows( $rows, strtolower( $id ) );` If there is no row, return `$this->not_found()`.
   - `$answered = null !== $provider_ref && '' !== $provider_ref ? $provider_ref : null;`
   - Loop `$row['refunds'] ?? array()` as `$i => $allocation`, skipping entries where `(int) $allocation['id'] !== $refund_id` or `'pending' !== ( $allocation['status'] ?? '' )`. On the match:
     - `pending`: if `null === $answered` or the allocation already has a non-empty `provider_ref` (`! in_array( $allocation['provider_ref'] ?? null, array( null, '' ), true )`), `return $row;` (no write). Otherwise set `$row['refunds'][ $i ]['provider_ref'] = $answered`.
     - final: set `$row['refunds'][ $i ]['status'] = $status` and `$row['refunds'][ $i ]['provider_ref'] = $answered ?? ( $allocation['provider_ref'] ?? null )`, then `$row['refunded_amount'] = Money::format( self::refunded_total( $row ) )`.
     - Modify the allocation **in place**. Add no keys, keep the key order (`id, amount, status, provider_ref`). Pro's `Test_Refund_Sweep::test_pending_allocation_marks_the_order_and_free_sweep_confirms_it` asserts the exact array.
     - `if ( $events ) { $row['events'] = array_merge( $row['events'] ?? array(), $events ); }` (`normalize_row()` caps at `EVENTS_MAX`).
     - `$row = $this->normalize_row( $order, $row ); $this->replace_and_save( $order, $rows, $row, false );`
     - If the status is final: `$refund = wc_get_order( $refund_id );`. Fire **only if** `$refund instanceof \WC_Order_Refund && $refund->get_parent_id() === $order->get_id()`: `do_action( 'woocommerce_pos_refund_allocated', $order, $row, $refund, Money::format( Money::minor( $allocation['amount'] ) ) );`. Load the refund fresh, because the fiscal writer needs the closure stamp written after allocation (see the comment at `Test_Refund_Session_Binding.php:369`).
     - `return $row;`
   - After the loop (no pending match): `return $row;`
3. **`public static function available_for_refund( array $row ): int`**. Minor units the leg can still return: `max( 0, Money::minor( $row['amount'] ?? 0 ) - self::reserved_total( $row ) )`. Docblock: "Pending and succeeded allocations reserve the leg. A failed one releases its amount. Minor units, never negative." It is static like `is_unanswered()` and `to_wire()`, because Pro calls it on rows it has already read.
4. **`public function append_events( WC_Order $order, string $id, array $events ): void`**. If `$events` is empty or no row has `strtolower( $id )`, return without writing. Otherwise `$row['events'] = array_merge( $row['events'] ?? array(), $events )`, then `replace_and_save( $order, $rows, $this->normalize_row( $order, $row ), false )`. Docblock: "Append cashier-log events to one leg (capped at `EVENTS_MAX`, newest kept). Events are already redacted by the caller."

Two private static helpers next to `pending_reserved()` (`:1142`). They are the only places an allocation sum is written:
- **`private static function reserved_total( array $row ): int`**: the **unclamped** sum of `pending` + `succeeded` allocation amounts, in minor units.
- **`private static function refunded_total( array $row ): int`**: the sum of `succeeded` allocation amounts, in minor units.

### Edits to existing code in `Ledger.php`
- **`record_failure_events()`** (`:509-522`). Keep the signature. The body becomes: read `$events` as today (`:510`). If it is a non-empty array, call `$this->append_events( $order, $id, $events )`.
- **`refund()`** (`:615-688`) uses the one sum:
  - `:624-632` becomes a replay check only (`foreach … if ( (int) $refund['id'] === $refund_id ) { return $row; }`) followed by `$available = self::available_for_refund( $row );`.
  - In the cap at `:652`, replace `$allocated + $requested > Money::minor( $row['amount'] )` with `$requested > $available`. Leave the refund-wide cap (`$refund_allocated + $requested > $refund_amount`) and its loop at `:643-650` alone. That is a per-refund sum across rows, a different question.
  - Post-handler re-check `:664-681`: `$refunded = self::refunded_total( $row )` and `$reserved = self::reserved_total( $row )`. These are the raw sums. Do **not** derive `$reserved` from `available_for_refund()`: its clamp at 0 would let an over-reserving handler result through. `$saved` keeps its own small loop (allocations with this `refund_id`, status pending or succeeded). The condition at `:679` is unchanged: `$saved <= 0 || $reserved > Money::minor( $row['amount'] ) || $refund_allocated + $saved > $refund_amount`.
  - Replace the one-line docblock at `:685` with a real hook docblock: "Fires after an allocation is saved, and again when a pending allocation settles (succeeded or failed) through `settle_allocation()`. `$amount` is the allocation's amount on this row. Listeners read the allocation's status from `$row['refunds']`." `@param` lines for `WC_Order $order`, `array $row`, `WC_Order_Refund $refund`, `string $amount`; `@hook woocommerce_pos_refund_allocated` (same style as `:281-289` in `record()`).
- **`save()`** docblock (`:965-971`). Add: "`@internal` The persistence step behind the Ledger's verbs, and the seeding seam for tests. Code outside this class writes rows through a verb (`record`, `intent`, `capture`, `apply_result`, `refund`, `settle_allocation`, `adopt`, `append_events`, `void`). A raw `save()` skips the transition table, the refund caps and the hooks." The signature and body are unchanged.

### `includes/Payments/Contract/Webview_Passthrough.php`
`mint()` lines 194-197 (`$rows[] = $row; $ledger->save( $order, $rows ); return $ledger->find( … );`) become:
```php
		$adopted = $ledger->adopt( $order, $row );
		return is_wp_error( $adopted ) ? null : $adopted;
```
Keep the live-row guard (`:139-145`) and the row assembly (`:162-193`) as they are. That decision is the caller's. `derive` stays `true` (the default), as today.

### `CONTEXT.md`
Add a `### Payments` section after `### Till` with two terms, verbatim from `verdict-payments.md` "Draft CONTEXT.md terms": **Leg** and **Allocation**. In **Allocation**, change "Written only by the Ledger." to "Written only by the Ledger: allocated by `refund()`, settled by `settle_allocation()`."

## Rulings to preserve (each pinned by the named test, which must stay green unmodified)
- A refund replay with the same refund id returns the stored row and adds no second allocation: `Test_Ledger::test_refund_replay_and_rollup_preserve_one_allocation`.
- Pending allocations reserve the leg, and an exact-fit allocation is accepted: `Test_Ledger::test_refund_pending_allocations_reserve_balance`.
- The refund-wide cap across rows: `Test_Ledger::test_refund_allocations_across_rows_cannot_exceed_the_refund_amount`.
- Foreign-parent refunds are refused: `Test_Ledger::test_refund_rejects_foreign_parent`.
- A succeeded allocation after closure writes one reallocation, through the real route and hook: `Test_Refund_Session_Binding::test_refund_allocation_after_closure_records_reallocation` (both storage modes).
- Cash allocations and pending allocations write nothing: `Test_Refund_Session_Binding::test_refund_cash_allocation_after_closure_records_nothing`, `::test_refund_pending_allocation_after_closure_records_nothing`.
- Recovery paths: `Test_Refund_Session_Binding::test_refund_allocation_recovers_a_missing_late_arrival_record`, `::test_refund_allocation_recovers_a_missing_refund_record`, `::test_refund_later_allocation_projects_live_tenders`.
- An untouched leg keeps its `updated_at_gmt` (D4): `Test_Ledger::test_save_keeps_the_stamp_of_an_untouched_leg_and_restamps_a_changed_one`, `Test_Payments_Sweeper::test_sweeper_polls_a_stale_leg_after_another_leg_on_the_order_was_written`.
- Capture failure events land on the row: every test in `Test_Payments_Sweeper.php` (the capture path at `Payments_Sweeper.php:143`), especially `::test_sweeper_refused_capture_leaves_the_authorization_standing` and `::test_sweeper_capture_uses_capture_verification`.
- The webview mint: one row, `source`/`capture_mode` `webview`, cashier from `_pos_user`, transaction ref, index meta; no duplicate after a ledger record; non-POS ignored; only paid statuses; nothing after a gateway result. Pinned by all 7 tests in `Test_Webview_Passthrough.php` and `Test_Orders::test_unpaid_gateway_success_does_not_mint_webview_ledger_row`.
- The gateway submission records once with the attempt id as the row id, `capture_mode` `gateway`, `source` `app`, and replays byte-identically: `Test_Gateway_Submission::test_submit_paid_gateway_records_once_and_replays`, `::test_submit_paid_status_without_payment_complete_records`, `::test_submit_paid_then_throwing_gateway_still_records`, `::test_submit_money_observed_wins_over_stored_status`, `::test_submit_payment_complete_with_stored_unpaid_status_still_stamps_date_paid`.
- Webview legs refund through the gateway: `Test_Webview_Handler::test_webview_leg_refund_runs_through_the_gateway`.
- `Test_Ledger.php`'s 65 tests and `Test_Payments_Sweeper.php`'s 27 stay green with no edit except additions.

## Tests (write first; each new one must fail before the change)
In `tests/includes/Payments/Contract/Test_Ledger.php`, use the file's helpers (`create_pos_order()`, `payment()`) and Arrange / Act / Assert comments:
1. `test_available_for_refund_counts_pending_and_succeeded_allocations`. A row with `amount` `'20.00'` and allocations pending `'5.00'`, succeeded `'3.00'`, failed `'4.00'`: `assertSame( 1200, Ledger::available_for_refund( $row ) )`. With pending `'15.00'` + succeeded `'10.00'`: `assertSame( 0, … )` (clamped). With no `refunds` key: `assertSame( 2000, … )`.
2. `test_settle_allocation_final_answer_updates_refunded_amount_and_fires_allocated`. Arrange: record `pos_cash` `'20.00'`, create a `WC_Order_Refund` (parent = order, amount `'20.00'`), seed `refunds = [ { id, '15.00', 'pending', null } ]` with `save()`, and attach a closure on `woocommerce_pos_refund_allocated` (4 args) that collects its arguments. Act: `settle_allocation( $order, $id, $refund_id, 'succeeded', 'ref_1', array( array( 't' => gmdate( 'c' ), 'level' => 'info', 'message' => 'settled' ) ) )`. Assert, on `Ledger::instance()->find( wc_get_order( … ), $id )`: `assertSame( array( 'id' => $refund_id, 'amount' => '15.00', 'status' => 'succeeded', 'provider_ref' => 'ref_1' ), $saved['refunds'][0] )`, `assertSame( '15.00', $saved['refunded_amount'] )`, `assertSame( 'settled', end( $saved['events'] )['message'] )`. The hook fired once, with `'15.00'` as the 4th argument and `'succeeded'` in the row's allocation.
3. `test_settle_allocation_failed_answer_releases_the_reservation_and_fires_allocated`. Same arrangement, with `'failed'` and `null` ref. Assert: allocation status `failed`, `provider_ref` kept from the seed, `refunded_amount` `'0.00'`, `available_for_refund()` of the stored row `2000`, hook fired once.
4. `test_settle_allocation_pending_answer_records_a_missing_reference_only`. Seed a pending allocation with no ref. (a) `settle_allocation( …, 'pending', null )`: unchanged (`assertSame` of the row before and after) and no hook. (b) `'pending', 'ref_late'`: `provider_ref` becomes `'ref_late'`, status `pending`, no hook. (c) `'pending', 'ref_other'`: `provider_ref` stays `'ref_late'`.
5. `test_settle_allocation_on_a_settled_or_unknown_allocation_changes_nothing`. A succeeded allocation and `settle_allocation( …, 'failed', 'x' )` give the row unchanged and no hook. An unknown `refund_id` gives the row unchanged. An unknown payment id gives `wcpos_payment_not_found`. Status `'refunded'` gives `rest_invalid_param`.
6. `test_adopt_appends_a_row_and_refuses_an_existing_id`. Arrange an order of `92.95`. Act: `adopt( $order, array( 'id' => strtoupper( $uuid ), 'method_id' => 'pos_card', 'capture_mode' => 'server', 'amount' => '92.95', 'status' => 'pending' ), false )`. Assert: the returned row's `id` is lowercase, `read()` count 1, the id is in `LIVE_LEG_META_KEY` meta, and the order status is unchanged (no derive). Then `adopt()` again with the same id gives `wcpos_payment_conflict` with status 409 and `read()` count 1. `'not-a-uuid'` gives `rest_invalid_param`.
7. `test_adopt_writes_the_order_once_with_the_callers_staged_meta`. Stage `$order->update_meta_data( '_wcpos_test_staged', 'yes' )`. Count `woocommerce_before_order_object_save` calls for this order id around `adopt()`. Assert the count is 1, and that `wc_get_order( … )->get_meta( '_wcpos_test_staged' )` is `'yes'`. This pins the contract Pro `Legacy_Adoption` relies on (`Test_Legacy_Adoption::test_lookup_is_written_only_after_the_row_is_saved`).
8. `test_append_events_appends_to_one_leg_and_keeps_the_newest`. Two recorded legs, both back-dated as in `test_save_keeps_the_stamp_of_an_untouched_leg_and_restamps_a_changed_one`. Act: `append_events( $order, $first, $events )` with `Ledger::EVENTS_MAX + 5` events whose messages are `'e0'…`. Assert: the first leg has `EVENTS_MAX` events and the last message is `'e' . ( EVENTS_MAX + 4 )`. The second leg's `updated_at_gmt` is still back-dated. `append_events( $order, wp_generate_uuid4(), $events )` and `append_events( $order, $first, array() )` change nothing (compare `read()` before and after).

In `tests/includes/API/V2/Test_Refund_Session_Binding.php` (the D3 failing test):
9. `test_refund_pending_allocation_settled_after_closure_records_reallocation`. Arrange as `test_refund_pending_allocation_after_closure_records_nothing`: session, `pos_card` sale, `refund()`. Then **before closing**, seed the pending allocation on the payment row through `Ledger::instance()->save()` on a fresh `wc_get_order()` (`refunds = [ { id: refund id, amount '10.00', status 'pending', provider_ref null } ]`, `derive` false). Then transition to counting and `close()`, and assert `$closure['expected']` is `cash 90.0000 / card 50.0000` (a pending allocation counts as cash). Act: `Ledger::instance()->settle_allocation( wc_get_order( $order->get_id() ), $payment, $refund->get_id(), 'succeeded', 'ref_1' )`. Assert: `records( type late_refund, closure_id )` has count 1, `payload.kind` is `'reallocation'`, and `payload.expected_delta.cash` is `'10.0000'`. Then call `settle_allocation()` again with the same arguments: the count is still 1 (no longer pending, so no re-fire). This test does not call `handle_allocation` by hand. The hook registered in `setUp()` (`:77`) is the production path.

New file `tests/includes/Payments/Contract/Test_Ledger_Sole_Writer.php` (extends `WP_UnitTestCase`):
10. `test_no_production_code_outside_the_ledger_calls_save_on_it`. Iterate `includes/**/*.php` (from `dirname( __DIR__, 4 ) . '/includes'`, `RecursiveDirectoryIterator`) except `Payments/Contract/Ledger.php`. Collect `file:line` for every line matching `/(?:\$ledger|Ledger::instance\(\s*\))\s*->\s*save\s*\(/`. Assert `assertSame( array(), $hits )`. A failure message that names the hits is enough. Run it against today's tree first: it must report `Webview_Passthrough.php:196`.

Delete no tests. Existing tests that seed through `save()` stay as they are (`save()` is `@internal`, not private).

**New files are untracked.** List `tests/includes/Payments/Contract/Test_Ledger_Sole_Writer.php` in the final report so the orchestrator `git add`s it before `git add -u`.

## Budget (from `wc -l`: `Ledger.php` 1359, `Webview_Passthrough.php` 199, `Test_Ledger.php` 1419, `Test_Refund_Session_Binding.php` 669)
- Non-test: at most **240 added lines** in `includes/` (`git diff --numstat` added column), which is the Ledger verbs ≈ +165 including docblocks and the WPCS multi-line arrays, plus `Webview_Passthrough` ≈ +3, and a net change in `Ledger.php` of at most **+175** lines. `CONTEXT.md` at most +20.
- Total diff (added + deleted, all files including tests and `CONTEXT.md`): at most **700 lines**.
If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
- `php -l` on `includes/Payments/Contract/Ledger.php`, `includes/Payments/Contract/Webview_Passthrough.php` and each changed or new test file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Payments/Contract/Ledger.php includes/Payments/Contract/Webview_Passthrough.php`.
- Pro grep (read-only), to confirm Pro still compiles against this tree: `grep -rn "Ledger::instance()->\|\$ledger->" /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next/includes`. Every method named must still exist with the same signature. Nothing is renamed or removed.
- The orchestrator runs these afterwards in wp-env, **one file per invocation** (several files in one call runs only one): `tests/includes/Payments/Contract/Test_Ledger.php`, `Test_Ledger_Sole_Writer.php`, `Test_Payments_Sweeper.php`, `Test_Webview_Passthrough.php`, `Test_Webview_Handler.php`, `tests/includes/API/V2/Test_Refund_Session_Binding.php`, `tests/includes/API/V2/Test_Gateway_Submission.php`, `tests/includes/API/V2/Test_Payments_Controller.php`, `tests/includes/Services/Test_Fiscal_Record_Writers.php`, `tests/includes/Test_Orders.php --filter webview`. Then Pro **unchanged** at `origin/next` against this Free tree: `tests/includes/Payments/Server/Test_Refund_Sweep.php`, `tests/includes/Payments/Test_Refund_Processor.php`, `tests/includes/Payments/OrderPay/Test_Legacy_Adoption.php`, `tests/includes/Payments/OrderPay/Test_Order_Pay_Gateway.php`. That proves the Free PR is additive for Pro.

## Sequencing and version skew
1. **This PR first**, into Free `next`. It is additive: four new public methods, `save()` unchanged except its docblock, and no rename. Pro `next` (which still calls raw `save()`) keeps passing against it. The re-fired hook only fires from `settle_allocation()`, and Pro does not call that until its PR. So D3 stays open in production until the Pro PR merges, and nothing regresses in between.
2. **Then the Pro PR** (`spec-ledger-sole-writer-pro.md`), branched from Pro `origin/next` **after** this merge. Pro `next` requires `wcpos/woocommerce-pos: dev-next` (Pro `composer.json:25`; CI enforces the lane at `.github/workflows/tests-php.yml:53-55`). Its CI resolves Free `next` at run time, so a Pro PR opened before this merge fails CI with `Call to undefined method …Ledger::settle_allocation()`.
3. Free `next` and Pro `next` ship together at the next minor/major. Pro's release build pins Free (`release.yml:139`, `dev-main#${FREE_PIN}`). That pin must be at or after this PR's merge commit once `next` has moved to `main`, or the release bundles a Ledger without the verbs.
Runtime skew: Pro bundles Free at build and is included first (it sorts before `woocommerce-pos/` in `active_plugins`), so on a normal site the Ledger in memory is Pro's vendored copy, which always has the verbs. The exposure is a site whose filtered plugin order loads an older standalone Free's `Ledger` first. There the new Pro calls fatal with an undefined method. That is the same exposure every new Free method Pro calls already carries. Accepted. Add no `method_exists` fallback, because a fallback would keep the raw writes alive.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (plus the new file you list).
