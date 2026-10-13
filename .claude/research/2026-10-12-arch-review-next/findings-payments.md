# Explorer findings — payments contract (Free Payments/Contract + Pro Payments/**)

Read on Free `837e985f` and Pro `05f06c4`. Verbatim explorer output. The Pro idempotency `reserve`/`claim` mismatch (D5) and the three inline `date_paid` suppression closures were re-verified by the orchestrator. `CONTEXT.md` defines no payments terms (leg, row, intent, settlement, allocation, capture mode). The capture-mode handler seam is real: five adapters (Free manual/webview/gateway; Pro server/device at Pro `Init.php:107-108`).

## A) Deepening candidates

### 1. Make the Ledger the only writer of payment rows and of the refund-allocation lifecycle. Strong.
- Files: Free `includes/Payments/Contract/Ledger.php` (1321 lines, 21 public methods). Pro `Payments/Refund_Processor.php:348-405`, `OrderPay/Order_Pay_Gateway.php:134-185`, `Server/Refund_Sweep.php:228-310`, `OrderPay/Legacy_Adoption.php:28-72`. Free `Contract/Webview_Passthrough.php:132-198`.
- Churn: 32 commits on `next`, 18 fixes. Six (2026-09-28/29: 49ff4365, de7f48a5, 33f8069d …) patch the caps inside `Ledger::refund()`.
- Raw writes: `save()` is public and takes raw rows, so the transition table, caps and hooks can be skipped. Called from outside at `Webview_Passthrough.php:196`, Pro `Legacy_Adoption.php:62`, Pro `Refund_Sweep.php:261,309`.
- Four row-assembly sites: `Ledger::record`/`intent`, `Webview_Passthrough::mint` (:162-194), Pro `Legacy_Adoption::adopt` (:43-58).
- Duplicated sum: "succeeded or pending allocations reserve the row" computed four times — `Ledger.php:624-632`, `Ledger.php:666-677`, Pro `Refund_Processor.php:372-377`, Pro `Order_Pay_Gateway.php:142-146` (`in_array( $allocation['status'], array( 'pending', 'succeeded' ) ) … $allocated += Money::minor(…)`).
- Pro `Refund_Sweep.php:249-253` recomputes `refunded_amount` itself.
- Tests: `Test_Ledger.php` 64 tests, only 4 named for refunds/allocation; setup seeds via `save()` plus raw meta (`Test_Payments_Sweeper.php:406-410`). Pro: `Test_Refund_Processor.php`, `Test_Refund_Sweep.php`.
- Pro coupling: reads `Ledger::LIVE_STATUSES`/`COUNTING_STATUSES`, edits row arrays, calls `save()`.

### 2. One place for the leg lifecycle: resume, unanswered-failure, expiry, capture. Worth exploring.
- `Payments_Sweeper.php:41-165` (one ~120-line closure) and `Ledger.php:345-386`.
- "A row the provider never answered fails on a determinate error" written twice: `Ledger.php:365-373` and `Payments_Sweeper.php:98-108`.
- Tip fee and tax carve-out inside `Ledger::apply_result` (`:563-591`) — order mutation inside the persistence path; three fixes (e12d7178, bfd2ee94, c0b9ac50).
- Tests: `Test_Payments_Sweeper.php` 25 tests.

### 3. One reader for a gateway's POS Order Status and one date_paid suppression. Strong. (ADR 0035 paths 1 and 2.)
- Four readers: `Gateway_Contract.php:182-212` (raw option, enabled-gated, legacy fallback); `Gateway_Contract.php:140-165` (filters never-settled statuses); `Orders.php:775-790` (settings view, fallback `wc-completed`); `Descriptor_Builder.php:174-176` (settings view, fallback `completed`).
- Closures answering `''` on `woocommerce_payment_complete_order_status` with different guards: `Orders.php:77` (the per-gateway filter), `Orders.php:246-261`, `Orders.php:339-357`, `Orders.php:547-551`, `Gateway_Submission.php:150-152/205-220`. (Verified: add_filter at Orders.php:77, :357, :551 and Gateway_Submission.php:205.)
- Churn: d5c0878b, 9f413183, d9fc15b2, 59c2fd6a all 2026-10-12; d5c0878b's message calls the new code "an inline twin of `Orders::arm_paid_date_suppression()`".
- Tests: `Test_Gateway_Submission.php` (+ HPOS), `Test_Gateway_Settled_Order_Status.php`.

### 4. A handler's declared capabilities must match what it does. Worth exploring.
- `Webview_Handler.php:21-58`, `Gateway_Handler.php:19-98`: same `describe()` block (only `mode` differs), identical `void()`; `Gateway_Handler` has the `refund()` the webview twin lacks.
- Two sources of truth: Pro `Refund_Processor.php:365` reads capabilities from the current descriptor; `Ledger::refund` dispatches on the row's stored `capture_mode` (`Ledger.php:653`).
- No test references `Webview_Handler`.

### 5. Collapse Pro's idempotency and registry stacks. Strong.
- Refund idempotency passes through four hops: `Refund_Processor` → `Idempotency_Store` interface → `WordPress_Idempotency_Store` (pure pass-through) → Pro `Idempotency_Repository` (226 lines, near-copy of Free's delegating by `method_exists`, drifted — D5) → Free's `Idempotency_Repository`.
- `Server_Providers.php` and `Device_Providers.php` (90 lines each) identical once names swapped (checked with diff). Free's `Capture_Mode_Registry` has the same shape.
- Legacy refund path: `Refund_Orchestrator` (131), `Refund_Processor::plan_from_legacy_resolution`, `RefundPlan::from_legacy_resolution` reachable only by constructor injection; Pro `API.php:92,110` registers by class name, so prod builds `new Refund_Processor()` (`Order_Refunds_Controller.php:96`).
- `In_Memory_Idempotency_Store` is a second adapter, so that seam is real; the Pro repository in the middle is not.
- Nothing checks that a reservation is released. Removing legacy classes may break third-party subclasses — Paul's call.

## B) Code smells
- `Gateway_Submission.php:49-323` — `submit()` ~275 lines. `Ledger.php:327-470` `intent()` ~145, `:189-316` `record()` ~128.
- "capture mode unsupported" `WP_Error` built 8×: `Ledger.php:1313`, `Abstract_Capture_Mode_Handler.php:88`, `Gateway_Submission.php:51`, `Payment_Methods_Controller.php:114`, Pro `Server_Handler.php:331`, `Device_Handler.php:228`, `Abstract_Provider_Adapter.php:123`, `Abstract_Device_Provider_Adapter.php:68`.
- Pro `Server/Event_Log.php` `MAX_EVENTS = 100` duplicates `Ledger::EVENTS_MAX` (`Ledger.php:34`), whose comment says Free owns that cap.
- `Ledger.php:240` — `record()` merges the whole client payload into the row (`array_merge( $input, … )`) with no allowlist (client can set `tip`, `events`, `provider_refs`, unbounded keys). `Settlement.php:55` uses an allowlist for the same input.
- `Ledger.php:278` — result of `Settlement::apply_parked()` ignored on the refused path.
- Two hook prefixes in one contract: `wcpos_payment_*` filters (`Ledger.php:578`) and `woocommerce_pos_payment_*` actions (`Ledger.php:289,314,897`).
- `Templates/Payment.php:188` and `:504` — stale `@TODO`s.
- Device providers have no conformance suite (Pro `tests/includes/Conformance/` covers server providers only).
- No `error_log`. Pro `Support_Bundle.php:259` `print_r` is intentional.

## C) Possible live defects (by reading, not reproduced)
- C1 (VERIFIED by orchestrator grep) — Pro refund locks never released: Free's repository has `claim()` not `reserve()`; Pro's `method_exists(…'reserve')` at `Idempotency_Repository.php:97` always fails, Pro takes a local option lock `wcpos_pro_idempotency_lock_*` (`:203`); `release()` delegates (`:151`) but Free deletes `wcpos_idempotency_claim_*` (Free `Idempotency_Repository.php:198-199`). One orphaned `wp_options` row per refund request. No test.
- C2 — "Original method" refunds fail on orders paid through the hosted pay page: `Descriptor_Builder.php:126` defaults non-manual gateways to `webview`; `Webview_Handler.php:33` advertises `refunds.via = provider` when the gateway supports refunds, but inherits the 501 `refund()` (`Abstract_Capture_Mode_Handler.php:83`); Pro `Refund_Processor.php:365-391` allocates, `Ledger::refund` returns 501, WooCommerce rolls back. Untested.
- C3 — Async provider refunds never reach fiscal records: Pro `Refund_Sweep::settle_allocation` (`:228-264`) writes with raw `save()`, so `woocommerce_pos_refund_allocated` never re-fires; `Fiscal_Record_Writers.php:364-374` only acts on an already-succeeded allocation.
- C4 — `Ledger::normalize_row` stamps `updated_at_gmt` now on every row on every save (`Ledger.php:1185,1211`); `Payments_Sweeper.php:61` uses it for staleness, so any write to another leg postpones sweeping a stuck leg.
- Speculative — `Orders.php:339-355` suppression closure lacks the `! get_date_paid('edit')` guard 9f413183 added to its `Gateway_Submission` twin.
