# Verdict — payments (Free `837e985f`, Pro `05f06c4`)

Lanes: `origin/main...origin/next` = `0 458`, so `next` is live. Every file below is next-only except C1 (Pro `Idempotency_Repository.php` is the same on Pro `main`; Free has had `claim()`/`release()` and never `reserve()` since 96c1e079, v1.9.0), so C1 targets **Pro `main`**.

How I applied "Tonight?": payments is High tier. Refactors are NO. A one-file defect fix with a failing-first test (which stakes-tiers.mdc requires for High-tier bugs) is YES.

## D1. Pro refund locks never released — VERIFIED
Trace: `Refund_Processor.php:131` `reserve()` → `WordPress_Idempotency_Store.php:68` → Pro `Idempotency_Repository.php:97`. `method_exists( $delegate, 'reserve' )` is false because Free has `claim()` (`Idempotency_Repository.php:76`), so `:107` adds the option `wcpos_pro_idempotency_lock_<md5>` (`:203`). The `finally` at `Refund_Processor.php:177` → `:151` sees that Free has `release()`, delegates, and deletes the Free key `wcpos_idempotency_claim_*` (Free `:199`), which nobody wrote. The Pro lock stays behind.
Cost: one orphaned non-autoloaded `wp_options` row per refund request. A request that throws stores nothing (only `finally` runs), so its retry gets a 409 `wcpos_idempotency_in_progress` until `LOCK_TTL` (5 min, `:120`) runs out. Every Pro test injects `In_Memory_Idempotency_Store` (`Test_Refund_Processor.php:64…`), so the production chain has no test. Severity Medium (released since Pro refund orchestration, 2026-04-23).
Fix: one line, Pro `Idempotency_Repository.php:151`. `release()` now checks the same condition as `reserve()`: `method_exists( $this->delegate, 'reserve' )`. Test: new Pro `tests/includes/Payments/Test_Idempotency_Repository.php`. Arrange a repository. Act: `reserve('refund',$k,'a')`, `release('refund',$k)`. Assert the lock option is `false`, and that `reserve('refund',$k,'b')` returns `true` (today it returns a 409). → `spec-pro-idempotency-release.md`. Tonight YES.

## D2. "Original method" refunds fail on every webview-minted row — VERIFIED (worse than the explorer said)
Trace: `Webview_Passthrough::mint` stamps `capture_mode => 'webview'` on **every** row it mints (`Webview_Passthrough.php:176`): the hosted pay page, `payment_complete()`, and offline tenders, including `pos_card`/`cod`. The descriptor advertises `refunds.via` = `provider` or `manual` (`Webview_Handler.php:33`, `Manual_Handler.php:31`). Pro plans allocations on either value (`Refund_Processor.php:365-368` → `RefundPlan::ledger`, `api_refund` false). `Ledger::refund` resolves the handler from the row's **stored** mode (`Ledger.php:653`) and gets `Webview_Handler`, which has no `refund()`. The abstract one returns 501 (`Abstract_Capture_Mode_Handler.php:83-85`). The first leg failing returns a WP_Error (`Refund_Processor.php:453-454`) and WooCommerce deletes the refund. The explorer limited this to refund-capable gateways. `via: manual` rows fail the same way. No Free or Pro test refunds a `webview` row (grepped both `tests/`). Severity High, but not yet released.
Fix: candidate 4 (below), in one Free file. Tonight YES.

## D3. Async provider refunds never reach fiscal records — VERIFIED
Trace: `Register_Session_Store.php:432-448` attributes a refund only to **succeeded** allocations; the rest counts as cash. `Ledger::refund` fires `woocommerce_pos_refund_allocated` once, when it allocates (`Ledger.php:686`), and a provider refund can be `pending` at that point. Later, Pro `Refund_Sweep::settle_allocation` (`Refund_Sweep.php:228-264`) flips it to `succeeded` through raw `Ledger::save()` (`:261`) and fires nothing. So `Fiscal_Record_Writers::handle_allocation` (`:348`, which acts only on succeeded, `:364-373`) never writes the cash→card reallocation after closure. `Test_Refund_Session_Binding::test_refund_pending_allocation_after_closure_records_nothing` calls `handle_allocation` a second time by hand, which is the call production never makes. Severity High (wrong drawer variance on a closed session), not yet released.
Fix: belongs to candidate 1 (`Ledger::settle_refund()`). Needs two PRs (Free, then Pro). Failing test: Free `Test_Refund_Session_Binding::test_refund_pending_allocation_settled_after_closure_records_reallocation`. Arrange as the pinned test, then allocate `pending`. Act: `Ledger::settle_refund(..., 'succeeded', 'ref_1')`. Assert one `late_refund` record with `payload.kind = reallocation`.

## D4. Any ledger write makes every leg look freshly updated — VERIFIED (it delays the sweep, nothing is lost)
Trace: `save()` normalizes every row (`Ledger.php:962-964`), and `normalize_row` sets `updated_at_gmt = now` unconditionally (`:1185,:1211`). The sweeper skips rows updated within the threshold (`Payments_Sweeper.php:61-62`). Writing leg B (the till polling `status`, a refund allocation, a second intent) therefore postpones polling, voiding or expiring a stuck leg A by up to another 5 minutes per write. One sweeper run still handles both legs, because it iterates its snapshot. Severity Medium.
Fix: `save()` keeps the stored `updated_at_gmt` for a row whose normalized content is unchanged. One function. Failing test: `Test_Payments_Sweeper::test_sweeper_polls_a_stale_leg_after_another_leg_on_the_order_was_written`. → `spec-ledger-updated-at.md`. Tonight YES.

## D5 (speculative). `Orders.php:339-349` lacks the `! date_paid` guard — NOT A DEFECT today, latent
`offline_process_payment_order_status` arms the suppression. Core BACS/cheque/COD then call `update_status()` immediately and never `payment_complete()` after applying their filter. The arm is released in `woocommerce_after_order_object_save` (`:358,377-390`), which fires inside `save()` **before** `status_transition()`. So no transition handler runs while it is armed. The same hole is reachable in the `apply_unpaid_gateway_order_status` closure (`:547-549`, no status or date_paid guard): a status handler that calls `payment_complete()` on the **same** order gets `''` and falls to `pending`. That is the 9f413183 bug class. Both are closed by candidate 3's union predicate, with failing-first tests.

---

## 1. Ledger as the only writer of payment rows and of the refund-allocation lifecycle — VERDICT: DO-RESHAPED
Strength: Strong · Risk tier: High
- 21 public methods (counted, `Ledger.php:65-1131`). Callers outside Ledger (Free inc/tests, Pro inc/tests): `read` 12/54/10/29, `find` 2/35/3/87, `save` **1/26/3/11**, `refund` 1/8/2/9, `apply_result` 6/1/1/2, `balance` 2/0/2/0. 13 methods have no Pro caller at all.
- Raw `save()` from outside: `Webview_Passthrough.php:196`, Pro `Legacy_Adoption.php:62`, `Refund_Sweep.php:261,309`. That is the root of D3 and part of D4.
- The sum "pending + succeeded allocations reserve the row" appears in `Ledger.php:624-632`, Pro `Refund_Processor.php:372-377` and `Order_Pay_Gateway.php:142-147`. The fourth copy the explorer cited (`:666-677`) is a different post-handler re-check, so leave it.
- Making `save()` private would break 37 test seeds. Keep it public and mark it `@internal`; take production callers off it.
- Minimal interface. New verbs:
  - `public static function refund_reserved( array $row ): int` (minor units).
  - `public function settle_refund( WC_Order $order, string $id, int $refund_id, string $status, ?string $provider_ref, array $events = array() )` (pending allocation only). It recomputes `refunded_amount`, saves with `derive=false`, and re-fires `woocommerce_pos_refund_allocated` when the allocation becomes final.
  - `public function append( WC_Order $order, array $row, bool $derive = true )` (new id only; WP_Error on collision).
  - `public function append_events( WC_Order $order, string $id, array $events ): void` (`record_failure_events` becomes a two-line delegate).
  No method becomes private. `save` is marked `@internal`.
- Pro rewrite per file:
  - `Refund_Processor.php:372-379`: `$reserved = Ledger::refund_reserved( $row )`.
  - `Order_Pay_Gateway.php:142-147`: same.
  - `Refund_Sweep.php:228-264`: `settle_allocation` → `settle_refund( …, Event_Log::event(…) )`. The local `refunded_amount` loop is deleted; the note stays in Pro.
  - `Refund_Sweep.php:295-309`: `given_up` → `append_events` per row.
  - `Legacy_Adoption.php:58-62`: `append( $order, $row, false )`.
  - `Server/Event_Log.php`: add `event()` (builds the redacted event); `MAX_EVENTS` → `Ledger::EVENTS_MAX`.
  - Free `Webview_Passthrough.php:194-197`: `append()`.
- Candidate 2's dedup belongs here (see 2).

Scope: Ledger +≈110/−15. Pro −≈35. Total ≈650 lines including tests and lint, in **two PRs** (Free next, then Pro next once Pro vendors it).
Pro impact (grepped Pro `includes/`, `tests/`): the 5 files above. No subclass of Ledger exists.
Tests: pinned today by `Test_Ledger.php` (64), `Test_Refund_Session_Binding.php`, Pro `Test_Refund_Sweep.php` (24), `Test_Refund_Processor.php`, `OrderPay/Test_Legacy_Adoption.php`. Add `Test_Ledger::test_settle_refund_final_answer_updates_refunded_amount_and_fires_allocated`, `…_pending_answer_records_a_missing_reference_only`, `…_append_refuses_an_existing_id`, and the D3 test. Pro test assertions are unchanged; they now exercise the verbs.
CONTEXT.md: adds **Leg**, **Allocation**, **Ledger** (drafts at the end).
Sequencing: after D4 (same `save()`). Unblocks D3. Two PRs.
Tonight? NO. It is a new public Free API that Pro depends on, the hook meaning changes (it now fires on settle), and the work spans two repos.

## 2. One place for the leg lifecycle (sweeper vs Ledger) — VERDICT: DEFER (fold its dedup into 1)
Strength: Worth exploring · High
- The sweeper is policy (when to poll, void or capture, `Payments_Sweeper.php:56-157`). Ledger is the store. Moving the loop in would add no depth.
- The real duplicate is "a determinate refusal ends an unanswered row": `Ledger.php:362-373` ≡ `Payments_Sweeper.php:98-108`. Make it a 12-line `Ledger::end_unanswered( $order, $row, WP_Error $e, bool $derive )` inside PR 1.
- The tip carve-out in `apply_result` (`:563-591`): its three fixes changed content, not location. Extracting a private method is cosmetic. DROP that part.
Tests: `Test_Payments_Sweeper.php` (25) pins it as is. Tonight? NO.

## 3. One reader for a gateway's POS Order Status, one date_paid suppression — VERDICT: DO-RESHAPED (a 4-site dedup, not a module; "one reader" DROPPED)
Strength: Strong · High
- **The readers answer three different questions, each pinned, so they stay separate.** *Applied*: `Orders.php:775-790`, the settings view; un-enabled gateways get defaults (`wc-completed`, bacs/cheque `wc-on-hold`, `Payment_Gateways_Section.php:189`); this is what `payment_complete()` lands on. *Stored*: `Gateway_Contract.php:182-208`, the raw option, enabled-gated, legacy fallback; this is merchant intent. *Settled*: `:140-162`, stored minus `NEVER_SETTLED`. `Descriptor_Builder.php:181-182` is the applied value with fallback `completed` and no registered-status check. That is a wire field, so it is out of scope (noted).
- **The suppression is one predicate written four times.** Guards per site:
  - (A) `Orders.php:250-256`, priority 10: `get_status() === configured` ∧ `!date_paid` ∧ configured ∉ paid.
  - (B) `:339-349`, PHP_INT_MAX, armed only for configured ∉ paid: id ∧ (order null **or** `get_status() === configured`). No date_paid guard.
  - (C) `:547-549`, PHP_INT_MAX, **2 args**, armed even for **paid** statuses (`test_unpaid_gateway_success_does_not_mint_webview_ledger_row` pins `completed` with null date_paid): id only.
  - (D) `Gateway_Submission.php:152-154`, armed only for settled ≠ '' ∧ ∉ paid: id ∧ instance ∧ `has_status(settled)` ∧ `!date_paid`.
  - Union: id ∧ instance ∧ `get_status() === status` ∧ `!date_paid`. Arming conditions stay with each caller. Under the union, B loses "null order → ''" and C gains the status and date_paid guards. Both changes only let `payment_complete()` through; neither is reachable from core gateways (D5).
- All four commits (d5c0878b "an inline twin", 9f413183, d9fc15b2, and 59c2fd6a, which introduced D) rediscovered the same two-caller fact. It belongs in **one** docblock. That is the reason to do this. Lines saved are close to zero.
- B's arm/release state machine (`:326-421`) stays in Orders. It is single-use.

Scope: new `includes/Payments/Paid_Date_Suppression.php` (≈60 incl. docblock). `Orders.php` −≈30, `Gateway_Submission.php` −≈12. Total ≈400 including tests. Free only. Pro grep: no reference to `payment_complete_order_status`, `date_paid` or `Gateway_Contract`.
Tests: pinned by `Test_Orders.php` (52 tests at `:296-3121`, listed in the spec), `Test_Gateway_Submission.php` (35), `Test_Gateway_Submission_HPOS.php`, `Test_Gateway_Settled_Order_Status.php` (10). Add the two D5 failing-first tests and `Test_Paid_Date_Suppression.php`. Delete none.
CONTEXT.md: adds **POS Order Status**.
Sequencing: A, B and C live on `main` too (#2172/#2176). Main is touched by every 1.10.x patch train, so doing the dedup on `next` only will conflict on every sync.
Tonight? NO. Spec ready (`spec-paid-date-suppression.md`). It is High tier, in a hot area that took six commits today, and it has a lane question for Paul.

## 4. Capture-mode handler twins (Webview_Handler vs Gateway_Handler) — VERDICT: DO (= the D2 fix)
Strength: Worth exploring · High
- `describe()` is identical except `capture.mode` (`Webview_Handler.php:19-41` vs `Gateway_Handler.php:19-41`), and `void()` is identical. `Gateway_Handler::refund` (`:54-80`) is exactly the behaviour the webview descriptor promises.
- `class Webview_Handler extends Gateway_Handler`, overriding `describe()` to set the mode. That file goes from 59 to ≈25 lines and fixes D2.
- "Two sources of truth" (Pro plans from the current descriptor; Ledger dispatches on the row's mode) only bites when a gateway's mode changes after a sale. With the twins merged, webview and gateway rows refund identically, so the common case is closed. The rest belongs to 1.

Scope: 1 file. Total ≈190 including the new test file. Pro: none (grepped; no subclass, no reference).
Tests: `Test_Gateway_Handler.php` unchanged. Add `tests/includes/Payments/Contract/Test_Webview_Handler.php` (2 tests).
Tonight? YES. → `spec-webview-handler-refunds.md`.

## 5. Collapse Pro's idempotency and registry stacks — VERDICT: DO-RESHAPED (two safe parts, one for Paul)
Strength: Strong · High
- (a) `WordPress_Idempotency_Store` should call Free's `Idempotency_Repository` directly (`reserve`→`claim()`, array→`true`, which is safe because `Refund_Processor.php:137` re-reads `find()`). Pro `Idempotency_Repository` (226 lines) is then left unused. On `next` this replaces the D1 one-liner structurally.
- (b) `Server_Providers.php` ≡ `Device_Providers.php` after the renames (`diff` is empty). Extract an abstract `Provider_Registry` with an interface constant; both class names stay, so ≈−55 lines. Third parties register through `wcpos_pro_register_{server,device}_provider()` (`wcpos-pro-functions.php:34,46`), so the names are not exposed anyway.
- (c) The legacy refund path (`Refund_Orchestrator`, 131 lines; the 5th constructor argument of `Refund_Processor`; `plan_from_legacy_resolution` `:249-291`; `RefundPlan::from_legacy_resolution`) is reachable only by injection. `Test_Refund_Processor.php:468-590` pins subclass overrides as supported. **BC question for Paul**: all of these are public in released Pro (on `origin/main` since 2026-04-23).

Tests: `Test_Refund_Processor.php`, `Test_Order_Refunds_*`. (a) adds `Test_WordPress_Idempotency_Store`; (c) would delete the 3 legacy tests.
Tonight? NO. (a) and (b) are safe but low leverage once D1 is fixed; bundle them with Paul's answer on (c).

## Draft CONTEXT.md terms (`### Payments`)
**Leg**:
One attempt to take money for an order through one payment method. It is stored as one row of the order's Ledger (`_wcpos_payments`) and keyed by a client-minted UUID, which doubles as the attempt id. A leg moves `pending → authorized → captured` or ends `failed`/`voided`, only through the Ledger's transition table. Authorized and captured legs *count* toward paid. Pending, authorized and captured legs are *live* and block a second leg for the same balance. "Row" is the stored shape; the wire calls it a `payment`.
_Avoid_: tender, transaction, payment attempt

**Intent**:
Opening a leg before money moves (`Ledger::intent`). It checks the amount against the balance minus pending reservations, writes a pending row, and asks the capture-mode handler for the provider object. Repeating it for the same id resumes the same provider object. Contrast *record*, which asserts money already taken and is allowed only for manual and device methods.
_Avoid_: payment intent (that is Stripe's object, kept as `provider_refs.payment_intent`), checkout

**Settlement**:
A provider's later answer applied to a leg by payment id (the Settlement module), parked until the row exists when the webhook beats the offline replay. Distinct from a gateway submission's `settled` outcome, which is a sale closed on its POS Order Status with no money moved.
_Avoid_: confirmation, reconciliation (for this)

**Allocation**:
One part of a WooCommerce refund assigned to one counting leg: `refunds[] = {id, amount, status pending|succeeded|failed, provider_ref}`. Pending and succeeded allocations reserve the leg's refundable amount. Succeeded ones sum to `refunded_amount`. The allocations of one refund across legs never exceed the refund. Written only by the Ledger.
_Avoid_: refund line, refund leg

**Capture mode**:
How a method takes money, together with the handler registered for it: `manual`, `webview`, `gateway` (Free) and `server`, `device` (Pro), resolved per gateway by `Descriptor_Builder::resolve_mode()` and the `wcpos_payment_method_capture_mode` filter. It is stamped on each leg when the leg is created and governs that leg for life, even if the gateway's mode changes later.
_Avoid_: payment type, flow, path

**POS Order Status**:
The per-gateway status setting, read three ways: *applied* (the settings view, with defaults; what `payment_complete()` lands a POS order on), *stored* (an explicit choice on a POS-enabled gateway, or the legacy global), *settled* (stored, minus statuses that are never a finished sale). Because it answers `woocommerce_payment_complete_order_status`, landing on it would stamp `date_paid`. One predicate (`Paid_Date_Suppression`) decides when that stamp is withheld.
_Avoid_: checkout status, paid status (unqualified)

## Other finding
`Ledger.php:240` `record()` merges the raw client input. `refunds` and `refunded_amount` are overwritten, but `tip`, `events`, `provider_refs`, `receipt`, `void_requested_at`, the `*_at_gmt` stamps and arbitrary keys survive. Status: NEEDS REPRO (does a client-set `tip` reach receipts or closures?).

## Recommended tonight order
1. D2 and candidate 4: `spec-webview-handler-refunds.md` (Free next).
2. D4: `spec-ledger-updated-at.md` (Free next, separate PR).
3. D1: `spec-pro-idempotency-release.md` (Pro **main**).

## Needs Paul
- Candidate 3: go-ahead for the `Paid_Date_Suppression` dedup, and its lane. Should it land on `main` (where A, B and C live) and sync, or on `next` only and absorb conflicts on every patch-train sync?
- Candidate 1: accept four new public Free Ledger verbs that Pro depends on, and `woocommerce_pos_refund_allocated` re-firing when a pending allocation settles (this fixes D3)?
- Candidate 5(c): may Pro 2.0 delete `Refund_Orchestrator`, `Refund_Processor`'s 5th constructor argument, `RefundPlan::from_legacy_resolution` and Pro `Idempotency_Repository`? All are public in released Pro.
