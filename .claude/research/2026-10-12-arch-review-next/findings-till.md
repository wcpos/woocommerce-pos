# Explorer findings — till cluster (registers / sessions / closures / fiscal / reports)

Read on Free `837e985f` and Pro `05f06c4` (origin/next, 2026-10-12). Read-only explorer output, verbatim. Store-id regex variants and Pro's direct reads of Free storage were re-verified by the orchestrator.

## A) Deepening candidates

### 1. Store reach: one scope check instead of four. Strength: Strong.
- Files: `Closures_Controller.php:142-168, 227-296, 388-412`; `Report_Scope_Resolver.php:162-229` (235 lines); `Fiscal_Record_Store.php:255-273`; `Sessions_Controller.php:86-87, 114, 151, 185-188`; `Registers_Controller.php:118, 129, 146-175`; Pro `Registers.php:156-170, 209-212`; Pro `Refund_Processor.php:524-528`.
- Friction: the output of `woocommerce_pos_closures_list_args` is read three different ways: `scope_store_ids_are_valid` (`Closures_Controller:280-296`) is strict; `allowed_store_ids` (`Report_Scope_Resolver:162-208`) is strict, with different wording; `resolve_document` (`Fiscal_Record_Store:262-263`) does `array_map('intval', (array) …)` with no checks; `list_args` (`Closures_Controller:411`) passes the output through untouched.
- Store ids are matched by five different regexes (verified): `/^\d+$/` (`Registers:112`, `Reports:182`), `/^\d{1,18}$/` (`Sessions:187`, `Closures:320`), `/^[1-9]\d{0,17}$/` (`Closures:290, 406`), `/^[1-9]\d*$/` (`Sessions:141`), `/^[1-9][0-9]*$/` (`Resolver:197`). Pro uses `ctype_digit`.
- Churn: 7 fix commits to `Report_Scope_Resolver` and 4 to the export, all on 2026-09-21 (8df60282, 91e26bdc, b241febf, 7cff61bd), all about scope values.
- Deletion test: there is no single module to delete; the rule is copied into each caller.
- Dependency: in-process (WP filters) plus local-substitutable (Pro).
- Tests: `Test_Closures_Controller` (export scope, 217-437), `Test_Reports_Controller`, `Test_Report_Scope_Resolver`. The tests' `add_filter` closures are the only adapter that exists. Nothing tests a single closure read, a single register read, or a session read under a scope.
- Free↔Pro: today Pro must find four filters and two per-row filters. It implements one (registers).
- Seam status: hypothetical seam (zero real adapters) whose docblocks claim a real one (`Closures_Controller:217-218`, `Report_Scope_Resolver:153-154`, `Sessions_Controller:86`).

### 2. Session figures: what is in the drawer. Strength: Strong.
- Files: `Register_Session_Store.php:389-539` (`expected`, `refund_tender_rows`, `session_refunds`, `captured_orders`; 540 lines); `Closure_Store.php:241-324` (`corrections_for`) and `:512-531`; `Receipt_Data_Builder.php:26-70` (X-report); `Sessions_Controller.php:118-128`; `Fiscal_Record_Writers.php:97-135, 309-339, 364-428`; `Register_Session_Store.php:293-307` (threshold check).
- Friction: `expected()` takes three optional preloaded arrays (a caching concern on the interface). It is called with a fake session `array('counted_float'=>'0')` just to sort rows by tender (`Closure_Store:257, 277`; `Fiscal_Record_Writers:314`). The sale/refund test `'refund'===kind || '-' prefix` is copied at `Closure_Store:261`, `Closure_Store:523`, `Receipt_Data_Builder:48`. The "every cash-kind gateway is the drawer" rule appears twice with the same comment (`Register_Session_Store:410-412`, `Fiscal_Record_Writers:112-113`). The session detail reads orders twice (`:124` and inside `expected()`) and movements twice (`:118`, `:416`).
- "Late" is decided three ways: late_sale by clock plus receipt id (`Fiscal_Record_Writers:101`); late_refund by the `_wcpos_closure` stamp (`:297-299`; commit 6dfff3c3 says "observers ask that stamp, not the clock", but late_sale still asks the clock); late_movement by whether a closure exists before insert (`Cash_Movement_Store:124`).
- The `expected_delta` saved at write time is cash-only (`Writers:110-117`); the read projection recomputes it across all tenders (`Closure_Store:257`).
- Churn: 9 fiscal fix commits on 2026-09-29 alone across these three files.
- Deletion test: logic would reappear in 6 callers; earns its keep once callers stop re-deriving.
- Tests: `Session_Expected_Tests` trait, `Test_Closure_Store`, `Test_Fiscal_Record_Writers` (450), `Test_Refund_Session_Binding` (669), `Test_Closure_Receipts`.
- Free↔Pro: Pro `Refund_Processor.php:497-555` picks the refund's session; the stamp it writes is the contract this module reads.

### 3. Write-once transaction with logging after rollback. Strength: Worth exploring.
- Files: `Closure_Store::create` at `383-730` (348 lines); `Cash_Movement_Store::create` at `109-226`; `Closure_Print_Counter::count_after` at `22-63`; `Register_Session_Store::transition` at `345-357, 373-374`.
- Friction: `$deferred`, `$replay`, `$rolled_back` flags hand-rolled per method; `transition()` infers whether it is inside the caller's transaction from `! isset($fields['closure_id'])`; 4 commits on 2026-09-16 just moved log lines past rollbacks (ca410ded, 729bc2fc, 2d6a7c74, c0b9ac56). `Closure_Store` writes other modules' tables directly: register (`:430-433`), fiscal (`:218-223, 243-247, 591-593`) although `Fiscal_Record_Store::list/count` already filter by `closure_id` (`:333`).
- Tests: `Test_Register_Logging` (756).

### 4. Table lifecycle. Strength: Worth exploring. (Folded into the lifecycle area — candidate 12.)
- Five copies of `table_name/schema_sql/install/ensure_installed` (`Register_Store:28-81`, `Register_Session_Store:25-76`, `Cash_Movement_Store:22-65`, `Closure_Store:35-103`, `Fiscal_Record_Store:35-114`); `Activator.php:226-231` and `:722-727` (same 6-line install list). `install()` does nothing once a table exists, so every schema change needs its own migration (`Activator::upgrade_business_days` `295-326`; the ALTER in `Fiscal_Record_Store::install` `86-100` can run on each request's first use). Pro reads Free's sessions table by hardcoded name (Pro `Registers.php:193-201`, verified).

### 5. Server report pipeline with no producers. Strength: Speculative.
- `Reports_Controller::get_item` (`112-159`), `Report_Scope_Resolver` (235), `Report_Scope_Gate` (56), `Report_Document_Validator` (59). Every built-in report is computed on the device (`Reports_Registry:183`, `Reports_Controller:20`). Pro registers no `woocommerce_pos_reports` entry. Only adapter: `Test_Reports_Controller` (827). Public hook `@since 2.0.0` — product decision, not ADR.

## B) Code smells
- `Closure_Store.php:383-730`: one 348-line method.
- Decimal maths as SQL queries: `Closure_Store.php:350-359`, `Register_Session_Store.php:421-428`, `:163, 299-307`, `Sessions_Controller:348`, `Closures_Controller:380` (one round-trip per tender amount checked).
- `Register_Session_Store.php:389` docblock "without floating-point arithmetic" but `refund_tender_rows` (`:444-465`) goes through `Money::minor` → `round((float)…)`.
- Three decimal validators that disagree: `Sessions_Controller:343-350` (any decimals, rounded), `Closures_Controller:376-381` (≤4), `Registers_Controller:199-208` (`wc_format_decimal`).
- `Reports_Controller.php:175`: own UUID regex, looser than `Pos_Uuid::is_uuid`.
- `Pos_Order_Audit::is_valid_till_value('_wcpos_sale_time')` reused for register timestamps at `Sessions_Controller:163, 235, 283`, `Closures_Controller:330, 396`.
- `Closure_Store.php:28-33`: request validator (`is_business_day`) on a storage class, used by 3 controllers.
- Business-day derivation duplicated: `Activator.php:308`, `Report_Scope_Resolver.php:105`.
- `Closures_Controller.php:307-312` validates a `business_day` that `Closure_Store.php:575` always overwrites.
- Manager password approval duplicated at `Sessions_Controller:204-210` and `Closures_Controller:128-139`, plus a JWT variant at `Sessions_Controller:259-266`.
- `Sessions_Controller.php:85-87`: the `woocommerce_pos_session_row` filter also receives cash movement rows.
- Two print-count paths: `Closures_Controller:147-156` counts without rendering, breaking the rule at `Closure_Print_Counter.php:12`.
- `Closures_Controller.php:304, 315`: client `breakdowns` JSON stored in the immutable row with only `is_array`, no size limit.
- `Register_Store.php:273`: every `get()`/`exists()` also runs a closure query.
- `Register_Store.php:22`: "installed" flag is a single bool; other stores key it by table (multisite skip).
- Pro `Registers.php:200` treats a session as open when status in `('open','counting')`; Free's `not_closed` (`Register_Session_Store:102-104`) is a different predicate.
- Naming collision: "session" = till session AND auth refresh-token session. `CONTEXT.md` has no entries for Register, Session, Closure, Business day, Correction.
- No `error_log`, no TODOs, no skipped tests in this cluster.

## C) Possible live defects
1. Single-register reads and edits skip the store filter Pro does hook (`Registers_Controller.php:146-175`): a cashier scoped to store A can GET store B's register by UUID (sales/refund totals); a manager can PATCH another store's register.
2. Pro has no store scoping for sessions, closures or reports: no adapter for `closures_list_args`, `session_row`, `sessions_list_args`, `session_create_fields` (`git log --all -S` on Pro empty). Any reports-capable user lists/exports every store's closures (`Closures_Controller:228, 411`) and reads any session (`Sessions_Controller:114, 151`). Single closure read/print/recount/id-replay (`Closures_Controller:142-168, 197-200`) have no scope check at all.
3. A session's store id comes from the client (`Sessions_Controller.php:179, 185-188`), never from the register; flows into the closure (`Closure_Store:574`), refund provenance (`Fiscal_Record_Writers:288`), report authorization (`Report_Scope_Resolver:38`).
4. Medium confidence: Pro's per-store variance threshold is not enforced server-side (`Register_Session_Store.php:294-295` reads the general setting plus a filter Pro doesn't hook; Pro stores it at `Abstracts/Store.php:360-370`).
5. Latent: `Fiscal_Record_Store.php:263` turns the scope into integers without validating — the fail-open `Report_Scope_Resolver.php:185-188` warns about.
6. Speculative race: `Cash_Movement_Store.php:124-129` decides "late" before inserting without the register lock `Closure_Store:433` holds.
