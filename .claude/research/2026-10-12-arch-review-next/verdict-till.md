# Verdict — till cluster (registers / sessions / closures / fiscal / reports)

Read on Free `837e985f`, Pro `05f06c4` (origin/next; `git rev-list --left-right --count origin/main...origin/next` = `0 458`, so `next` is live). None of the till code exists on `origin/main` (`git ls-tree origin/main includes/API/V2/` has no Closures/Sessions/Registers/Records/Reports controllers), so every till filter is **unreleased**. Renaming or deleting one breaks only Pro, and Pro hooks three.

**Pro grep.** I grepped `woocommerce-pos-pro-worktrees/arch-review-next/includes` and `/tests` for every filter the till controllers apply, and ran `git log --all -S` on each:

| Free filter (applied at) | Pro hooks it? |
|---|---|
| `woocommerce_pos_registers_list_args` (`Registers_Controller.php:118,129`) | **yes**, `Registers.php:24`, `:156-170` |
| `woocommerce_pos_register_update_fields` (`:169`) | **yes**, `Registers.php:23` |
| `woocommerce_pos_register_upsert_fields` (`Register_Store.php:97`) | **yes**, `Registers.php:22` |
| `woocommerce_pos_closures_list_args` (`Closures_Controller.php:228,411`; `Fiscal_Record_Store.php:262`; `Report_Scope_Resolver.php:163`) | no (0 commits ever) |
| `woocommerce_pos_records_list_args` (`Records_Controller.php:122,143`) | no (0 commits) |
| `woocommerce_pos_session_row` (`Sessions_Controller.php:87,114`) | no (0 commits) |
| `woocommerce_pos_sessions_list_args` (`:151`), `woocommerce_pos_session_create_fields` (`:185`) | no (0 commits) |
| `woocommerce_pos_session_variance_threshold` (`Register_Session_Store.php:295`) | no (0 commits) |
| `woocommerce_pos_reports` (`Reports_Registry.php:116`), `woocommerce_pos_fiscal_record_failed` | no (0 commits) |

No Pro class subclasses a till controller. Pro calls `Register_Session_Store::get/list` (`Payments/Refund_Processor.php:510-523`), `Register_Store::list/create/exists` (`Registers.php:62-76,143`) and `Fiscal_Record_Store::find_refund` (Pro `tests/includes/API/Test_Order_Refunds_Session_Stamp.php:98`). It also reads Free's sessions table by name (`Registers.php:193-201`).

---

## 1. Store reach: one check for every till read — VERDICT: DO-RESHAPED
Strength (explorer): Strong · Risk tier: **High** (an authorization check). It changes nothing in production until an adapter exists.

Why:
- A half-built Store Reach module already exists: `Report_Scope_Resolver::allowed_store_ids()` and `row_in_scope()` (`Report_Scope_Resolver.php:149-229`, 81 lines). It reads the seam from an empty base, denies a non-array answer (`:168-179`), treats a missing `store_id` key as unrestricted (`:182-184`), validates before coercing (`:189-206`), and never admits a NULL-store row (`:227-229`). It is private, so it cannot be reused. The export has a second strict copy (`Closures_Controller.php:228-252, 280-296`). The documents read uses a lax `intval` (`Fiscal_Record_Store.php:262-263`), and so does the record read (`Records_Controller.php:143-144`).
- `Sync/Store_Scope.php` is **not** a reach module. It carries the store a till *claims* (`X-WCPOS-Store`) and its docblock says so: "It does not authorize the store" (`Store_Scope.php:45-48`). Its `normalize()` (`:233-249`) is the only shared store-id parser, and it is private.
- `Report_Scope_Gate.php` is the Free/Pro *tier* gate (a visible 403, `:53-55`). It is not reach. Keep the two apart, as `Report_Scope_Resolver.php:219-222` already says.
- Unchecked reads, all verified: closure GET, print and recount (`Closures_Controller.php:142-168`); session GET and movements (`Sessions_Controller.php:113-121`, behind a filter nobody hooks); register GET and PATCH (`Registers_Controller.php:146-175`). The lists apply four different `*_list_args` filters to the caller's arguments.
- **What the explorer and the house memory got wrong.** "Pro restricts a manager to its authorized stores through this filter" (`Closures_Controller.php:216-220`, `Report_Scope_Resolver.php:152-154`, the memory file `closures-list-args-is-an-authorization-boundary`) is false. Pro has never hooked `closures_list_args`. The design that names it as the seam is `.claude/specs/2026-09-21-reports-registration-seam/CONTRACT.md:90-92`. Pro's only scoping is `Registers::list_args`, which follows the till's claimed header (`Registers.php:156-170`). It returns the arguments unscoped when no header is sent (`:161-164`), so it is a browsing scope, not reach.
- The reshape: one `Store_Reach` module that reads `woocommerce_pos_closures_list_args` from an empty base. That filter is already the house seam and the reports contract names it for sessions too. Every **read** is routed through it. Writes are left out: the replay paths need a separate design (see Needs Paul).

Scope from the tree: new `includes/Services/Store_Reach.php` (about 120 lines with docblocks). Edits: `Report_Scope_Resolver.php` (235 lines; deletes `:149-229`); `Closures_Controller.php` (422; export and validator `:214-296` are 83 lines and shrink to about 25, plus `list_args` `:383-412`); `Sessions_Controller.php` read `:106-152` (47); `Registers_Controller.php` `:100-175` (76); `Records_Controller.php` `:121-148` (28); `Fiscal_Record_Store.php:262-263`; array `store_id` in `Register_Store::list` (`:165-167`) and `Register_Session_Store::list` (`:96-101`). Net non-test change is about +30. Total diff about 650–900 lines, including about 350 of tests and +50..+150 for lint.

Pro impact: none. The changed methods are all private, or `get_item`/`update_item` with unchanged signatures. Pro's `registers_list_args` keeps running and reach narrows its output afterwards. The follow-up is Pro's adapter: hook `closures_list_args` and answer from `Stores::get_stores()` (authorized users, `Services/Stores.php:132-165, 232-254`), about 25 lines. That is a product call.

Tests: these pin behaviour today. `Test_Reports_Controller` covers the deny, malformed, unreadable, empty, NULL-store, register-moved and name-leak cases (`:314-610`). `Test_Closures_Controller` covers the export at `:217-437` and the list scope at `:1243-1247`. Also `Test_Closure_Receipts.php:446-458`, `Test_Records_Controller.php:37-44`, `Test_Registers_Controller.php:186-244` and `Test_Report_Scope_Resolver`. **Add** `tests/includes/Services/Test_Store_Reach.php` for the contract matrix, plus one test per route asserting that out-of-reach looks the same as absent (see the spec). **Shrink** the matrix in `test_report_unreadable_store_restriction_denies` and `test_export_refuses_a_malformed_store_scope` to three wiring cases each, because the full matrix moves to `Test_Store_Reach`.

ADR / CONTEXT.md: no ADR conflict. ADR 0037 (record uniformity) is unaffected. Add a `### Till` section to `CONTEXT.md` with the drafts below; the spec carries the same text. The terms align with the client glossary that is already ruled (`monorepo-v2` `CONTEXT.md` "Language — Cashiers & till", roadmap#202/#214).

> **Register**:
> The server record of one cash drawer: sessions open on it and its closures number in one gap-free sequence. It belongs to at most one store (`store_id` is NULL on Free and for an unassigned register). Only an administrator moves it between stores, and only while no session is open. A move carries no history, because every closure records the store its register belonged to when it was written. A register is the drawer, not the device: several devices may point at one register.
> _Avoid_: till (in code or UI), terminal, device, station
>
> **Till session**:
> One register's opened-to-closed period, `open` → `counting` → `closed`. It opens with a counted float and closes with a count. It is keyed on the register, never the cashier. The code says `session` in `Register_Session_Store`, `Sessions_Controller` and `/wcpos/v2/sessions`. That is not the auth session (a refresh-token family in `Session_Registry`), so say "till session" wherever the two could meet.
> _Avoid_: shift, drawer session, cash session; bare "session" in auth code
>
> **Closure**:
> The immutable, numbered document `Closure_Store::create()` writes once when a till session closes. It holds expected, counted and variance per payment method, the period and perpetual grand totals, and the breakdowns as recorded. It is never edited: a later change is a Correction that points at it. Its print is the Z-report. The X-report is a live print of an open session and is never stored.
> _Avoid_: Z-report (for the record), end of day, shift report
>
> **Business day**:
> The trading day a till session, and so its closure, belongs to: the date in the store's timezone on which the session opened. It is stamped on the session at opening and copied onto the closure. The closure never takes it from its own request. A session that runs past midnight is one day's trade. Legacy unstamped rows derive it from `opened_at_gmt` in `wp_timezone()`, the way `Activator::upgrade_business_days()` back-fills them.
> _Avoid_: calendar day, day start, cutoff time
>
> **Correction**:
> A write-once fiscal record with a `closure_id`. It changes what a closure settles to without touching the closure. The types are `recount`, `late_sale`, `late_refund` (including a post-closure `reallocation` between tenders) and `late_movement`. A closure reads as recorded plus its corrections. `Closure_Store::corrections_for()` is the one projection.
> _Avoid_: adjustment, amendment, edit, re-close
>
> **Store reach**:
> The stores whose registers, till sessions, closures, fiscal records and reports one caller may read. The Store Reach module resolves it once per request from `woocommerce_pos_closures_list_args` applied to an EMPTY base. No `store_id` key means unrestricted. A well-formed list, even an empty one, restricts. Anything unreadable denies everything. A NULL-store row is never within a restricted reach. A named record out of reach answers exactly as an absent one (404, never 403). A collection is narrowed in its SQL. This is not the Store scope, which is the store a till *claims* (`X-WCPOS-Store`), and not the Report Scope Gate, which is the tier boundary (a visible 403).
> _Avoid_: store scope (for this), authorized-stores filter, store permission

Sequencing: nothing has to land first. It unblocks the Pro adapter (D2) and makes D3 matter more. Fits one PR.

Tonight? **YES.** The area is High tier, but the change is inert until something hooks the seam: every existing test runs without a filter and pins the unrestricted path. The judgement calls (seam name, write paths, Pro's reach policy) are deferred. → `spec-store-reach.md`

## 2. Session figures: what is in the drawer — VERDICT: DO-RESHAPED (not tonight)
Strength (explorer): Strong · Risk tier: **High** (money, fiscal)

Why. The callers that re-derive drawer figures, and what each needs:
1. `Register_Session_Store::transition` `:304`: cash expected only, for the threshold. It still reads every order, refund and movement.
2. `Sessions_Controller::read` `:118,124,128`: movements, `sales_count`, and expected across all tenders. It reads orders twice (`sales_count()` then `expected()`) and movements twice (`:118` and `:416`).
3. `Closure_Store::create` `:513-544`: expected, a sale/refund split of the period totals (copy 1 of the sale/refund test, `:523`), and the refund list to stamp.
4. `Receipt_Data_Builder::build_closure_document` (X-report) `:28-71`: expected, variance, movements, transaction count, refund count (copy 2, `:48`) and cashiers.
5. `Closure_Store::corrections_for` `:257,261,276-285`: per-tender deltas of late rows through a **fake session** `array('counted_float'=>'0')`, plus copy 3 of the sale/refund test.
6. `Fiscal_Record_Writers::record_sale` `:110-117` (a second copy of the "cash-kind is the drawer" rule), `record_late_arrival` `:313-314` (fake session), and `record_reallocation` `:399-407` (rows built by hand). All three freeze a **cash-only** `expected_delta`, while the read-side projection recomputes every tender (`Closure_Store.php:257,277`). The client glossary defines Expected per method, so the frozen payload loses information. It is not wrong for cash.
- The right shape has two parts. (a) A pure tender-totals unit: the classification rule (`Register_Session_Store.php:410-413`) and the sale/refund split, written once. It needs no session, so it replaces the fake-session calls. (b) A per-session figures object that loads orders, refunds and movements once, replacing the three optional preload parameters on `expected()` (`:397`).
- **Can "late" be unified without changing Pro's stamp contract?** The Pro contract is safe either way, but the unification itself is not cheap. Pro writes only *membership*: `_wcpos_session` and `_wcpos_register` on the refund (`Refund_Processor.php:549-554`). Lateness is decided only by Free: the `_wcpos_closure` stamp for refunds (`Closure_Store.php:532-544` → `Fiscal_Record_Writers.php:297-299`); receipt id plus snapshot clock for sales (`:101`); closure existence before insert for movements (`Cash_Movement_Store.php:124`). A per-order sale stamp would be **wrong**, because one order's payment rows bind to different sessions ("Session on the sale", client glossary). A true unification needs the closure to record what it counted, per ledger row or as an order-and-row set. That is a new column or a ledger write. The memory rule says a new column is Paul's call.
- Explorer correction: the late-refund projection reading the live ledger (`Closure_Store.php:272-276`) is deliberate (`Fiscal_Record_Writers.php:391-392`), not drift.

Scope from the tree: `Register_Session_Store.php:389-539` (151 lines), `Closure_Store.php:234-324` (91) and `:512-531`, `Receipt_Data_Builder.php:21-72` (52), `Fiscal_Record_Writers.php:97-135, 309-339, 364-428`, `Sessions_Controller.php:111-131`. New module about 150 lines; net about +40 non-test; total diff about 700 including lint.
Pro impact: none. Pro never calls `expected()`, `captured_orders()` or `session_refunds()`. The `_wcpos_session` and `_wcpos_register` stamp is untouched.
Tests: the `Session_Expected_Tests` trait (132), `Test_Closure_Store` (307), `Test_Fiscal_Record_Writers` (450), `Test_Refund_Session_Binding` (669), `Test_Closure_Receipts` (910). Add `Test_Tender_Totals` (classification, split, cash-kind extension tender). Re-point `Session_Expected_Tests` to the new interface rather than adding a layer.
ADR / CONTEXT: sharpen **Expected** and **Late sale** on top of the till terms above.
Sequencing: after spec-store-reach. Fits one PR; the lateness work is a second PR.
Tonight? **NO.** High-tier money derivation, and whether lateness should become "the closure records what it counted" needs Paul.

## 3. Write-once transaction with logging after rollback — VERDICT: DEFER
Strength (explorer): Worth exploring · Risk tier: High
- Verified: three hand-rolled transactions. `Closure_Store::create` has 15 `$deferred` mentions and 10 assignments (`:419-690`). The others are `Cash_Movement_Store::create` `:129-211` and `Closure_Print_Counter::count_after` `:22-63`. `transition()` infers whether it is running inside a caller's transaction from `! isset($fields['closure_id'])` (`Register_Session_Store.php:348,374`).
- The fix is a cross-cutting `Logger` hold buffer, because `Logger` is global. Leverage is about 60 removed lines across three callers. The current code is correct and pinned by `Test_Register_Logging` (756 lines). No defect argues for it, and D6 fixes the one real race by locking instead.
- Minor: `Closure_Store::record_print` logs inside the print counter's transaction (`:817`). The failure is restated after the rollback (`Closure_Print_Counter.php:57-60`), so nothing is lost.
Pro impact: none. Tonight? **NO.** Low leverage for a change to a global logger.

## 4. Table lifecycle — skipped (owned by the lifecycle area)

## 5. Server report pipeline with no producers — VERDICT: DROP
Strength (explorer): Speculative · Risk tier: Medium
- This is a ruled extension point, not dead code. The client glossary defines **Registered report**: a report "a plugin adds through the `woocommerce_pos_reports` filter … computed on the server". The catalogue half (`Reports_Registry.php:116`, built-ins `:183`) is live. Only the document half (`Reports_Controller.php:112-159`) has no producer.
- Candidate 1 removes 81 lines from `Report_Scope_Resolver`, which shrinks this pipeline anyway.
Tonight? **NO.** Nothing to do.

---

## D1. Register GET/PATCH skip the store filter — VERIFIED (low severity; not an escalation)
Trace: `Registers_Controller.php:147` → `Register_Store::get()` `:145-152` (unscoped); `update_item` `:160` → `:169` (the store filter is never consulted). The explorer overstated it. Pro's only scope (`Registers.php:156-170`) returns the arguments untouched when no `X-WCPOS-Store` header is sent (`:161-164`), and for a manager with `context=edit` (`:157-159`). A cashier can therefore list every register by leaving out the header, and a single read reveals nothing extra. PATCH needs `manage_woocommerce_pos`, which Pro's scoping exempts anyway.
Failing test (with the candidate 1 seam): `Test_Registers_Controller::test_register_item_outside_store_reach_reads_as_absent`. Arrange: two registers in stores 1 and 2, and reach `[1]`. Act: GET and PATCH the store-2 register, and GET a random UUID. Assert: status and code are the same as for the absent id, and the name is unchanged.
Fix: belongs to candidate 1 (`spec-store-reach.md`).

## D2. Pro scopes no session, closure, record or report read — VERIFIED
Trace: see the grep table. Only `registers_list_args` has an adapter. Any holder of `view_woocommerce_pos_reports` can list and export every store's closures (`Closures_Controller.php:228,411`), read any session (`Sessions_Controller.php:114,151`), and read, print or recount any closure by id (`:142-168`). Five docblocks claim otherwise (`Closures_Controller.php:216-220`, `Report_Scope_Resolver.php:152-154`, `Sessions_Controller.php:86`, `Records_Controller.php:121,142`).
Severity: High, once Paul rules that reach means the stores a user is authorized for (Pro `authorized_users`, `Stores.php:232-254`). Today it is a missing feature behind docblocks that say it exists.
Fix: Free half is candidate 1. Pro half is a roughly 25-line adapter on `closures_list_args`, with a failing test in Pro `tests/includes/API/`: a manager authorized for store A gets 404 on a store-B closure.

## D3. A till session's store comes from the client — VERIFIED
Trace: `Sessions_Controller.php:179` `'store_id' => $request['store_id']` → `:185-186` (filter, no adapter, so the client's value is kept) → `Register_Session_Store::create` → copied onto the immutable closure (`Closure_Store.php:574`) → refund provenance (`Fiscal_Record_Writers.php:288`) → report scope and authorization (`Report_Scope_Resolver.php:38,70`). Free registers have `store_id` NULL (`Register_Store.php:104`). Pro sets it at registration (`Registers.php:91-105`).
Failing test: `Test_Sessions_Controller::test_session_opening_takes_the_store_from_its_register`. Arrange: a register with `store_id` 456. Act: POST `sessions` with `store_id` 999. Assert: `assertSame( 456, $data['store_id'] )`.
Fix: one file, `Sessions_Controller::opening_fields` `:160,179-189`. Read the register row, take its `store_id`, and drop the client parameter and the `session_create_fields` filter. **Not tonight.** The client sends `store_id: store.id`, which is `0` on Free (`monorepo-v2` `session-store.ts:83`, `use-register-session.ts:355`), and compares `row.store_id === (store.id ?? null)` (`bridge.tsx:53`). A server that answers NULL where the client wrote 0 could stall the closure reservation. Paul has to decide how Free's single store is represented, and the client needs checking.

## D4. Per-store variance threshold not enforced server-side — VERIFIED (Pro half); NEEDS REPRO (Free half)
Trace: `Register_Session_Store.php:294-295` reads the General setting, plus a filter nobody hooks. Pro stores a per-store threshold (`Abstracts/Store.php:360-370`) and sends it to the till (`:519`). The client enforces `store.variance_threshold` (`use-register-session.ts:64`, `register-count.tsx:101`), and its glossary rules "Variance threshold: a store setting". So in Pro the server enforces General while the till enforces the store's value. On Free, no include sets `variance_threshold` on the default store (grep of `includes/`), so the till probably never warns and the cashier meets the server's 403 at close.
Failing test (Pro): `Test_Registers::test_close_refused_above_the_session_stores_threshold`. Arrange: store threshold 5, General blank, a session in that store, a variance of 10. Assert 403 `wcpos_override_refused`.
Fix: Pro hooks `woocommerce_pos_session_variance_threshold` with `wcpos_get_store( session store )->get_variance_threshold()`, about 12 lines. It depends on D3 for a trustworthy session store. Whether a blank store threshold inherits General is Paul's call.

## D5. Documents and records read the reach with `intval` — VERIFIED (latent)
Trace: `Fiscal_Record_Store.php:262-263`: `array_map('intval', (array) $scope['store_id'])` turns `'x'` or `false` into 0, and `(int) $row['store_id']` turns NULL into 0, so the read admits unassigned closures and sessions. The same happens at `Records_Controller.php:144`. This is the fail-open that `Report_Scope_Resolver.php:185-188` warns about. It is latent because there are no adapters.
Failing test: `Test_Closure_Receipts::test_document_unreadable_store_restriction_denies_an_unassigned_closure`. Arrange: set the closure's `store_id` to NULL; the filter returns `store_id => array('invalid')`. Act: GET the document `closure:{id}`. Assert: the same status and code as `closure:{random uuid}`.
Fix: belongs to candidate 1.

## D6. A late movement can slip between closure and correction — LIKELY
Trace: `Cash_Movement_Store::create` checks `for_session()` (`:124`) without the register lock and inserts in autocommit when there is no closure (`:129`). `Closure_Store::create` holds the register row lock (`:433`) and reads movements inside its REPEATABLE READ snapshot (`:515` → `Register_Session_Store.php:416`). A movement that commits after the closure's snapshot (first taken at `:443`) but checks before its COMMIT (`:656`) is counted by neither the closure nor a `late_movement` correction. `accepts()` admits exactly these offline pre-count rows (`:249`), and the glossary allows two devices on one register.
Severity: High (cash silently missing from a closure). There is no failing test: single-connection PHPUnit inside the test transaction cannot interleave two requests.
Fix: one file, `Cash_Movement_Store::create` `:120-140`, about 10 lines. Always open the transaction, take `SELECT id FROM {registers} WHERE id = %s FOR UPDATE` using the session's `register_id`, then call `for_session()`. Needs Paul's waiver of the failing-test-first rule.

---

## Recommended tonight order
1. `spec-store-reach.md`: the Store Reach module, every till read routed through it (closes D1 and D5, Free half of D2), and the `### Till` terms in `CONTEXT.md`.

## Needs Paul
1. **D2:** Should Pro's reach be `Stores::get_stores()` (authorized users) for everyone, or are `manage_woocommerce_pos` holders unrestricted?
2. **Seam name:** Rename `woocommerce_pos_closures_list_args` to `woocommerce_pos_store_reach` before 2.0 ships, and delete the hooks nobody uses (`session_row`, `sessions_list_args`, `records_list_args`, `session_create_fields`)?
3. **Write paths:** Should the closure, session and movement POST replays (`Closures_Controller.php:197-200`, `Sessions_Controller.php:87,289`) also answer "absent" outside reach, given that `create()` returns the existing row on a replay?
4. **D3:** Should a till session take its store from its register, and should Free's single store then be NULL or 0, given that the client compares `store_id === store.id`?
5. **D4:** Is the per-store variance threshold enforced server-side, and does a blank store threshold mean "none" (client glossary) or "inherit General"?
6. **D6:** Can I land the register-lock fix for the movement/closure race without a failing test, which PHPUnit cannot interleave?
7. **Candidate 2:** Keep a separate lateness rule per row type, or have the closure record what it counted (a new column or a ledger write)?
