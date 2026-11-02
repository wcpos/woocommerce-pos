## 1. Claim check

**Evidence:** source/history inspection only; no tests, Docker, network or changes. Runtime outcomes below are **Inferred**, not reproduced.

Prefixes: `F` = Free `next` at `92b5d970`; `M` = Free `main` at `9b9f7613`; `P` = Pro `next` at `82cb93e`; `C` = supplied client `main` at `c5def3c6`; `W` = supplied wiki. The supplied client lacks the till implementation, so I also read its **locally available** `origin/next` at `38571324`, labelled `CN`—not evidence of a shipped build. Client paths abbreviated `RS/` mean `packages/core/src/services/register-session/`.

### D3

- **HOLDS — request-controlled session store.** `F/includes/API/V2/Sessions_Controller.php:162–193` validates register existence, takes `$request['store_id']`, applies `woocommerce_pos_session_create_fields`, and validates only numeric shape; `F/includes/Services/Register_Session_Store.php:133–146` inserts it without comparing it with the register.
- **HOLDS — downstream financial attribution trusts it.** Closure copies the session store at `F/includes/Services/Closure_Store.php:530–541`; refund provenance uses it at `F/includes/Services/Fiscal_Record_Writers.php:296–311`; report authorization checks it at `F/includes/Services/Report_Scope_Resolver.php:29–43`.
- **CHANGED — Store Reach now protects reads, but not these writes.** Session reads check reach at `Sessions_Controller.php:118–119,154`; opening still checks only register existence at `:163`. Pro installs the reach adapter at `P/includes/Registers.php:21–26,165–188`, but no adapter for `session_row` or `session_create_fields` exists in Pro `includes/`. Its pre-callback guard applies specifically to register PATCH at `:136–149`.
- **WRONG — the ordinary client sends `store_id` in the opening body.** `CN/RS/queue.ts:270–278` omits it. The shared HTTP wrapper supplies the selected store through the request preamble (`CN/packages/core/src/screens/main/hooks/use-rest-http-client/index.ts:254–263`), which adds a **query parameter for nonzero stores**, excluding numeric zero (`CN/packages/utils/src/request-preamble.ts:272–278`). It remains client-controlled; the transport detail matters.
- **HOLDS, qualified — NULL versus 0 can interrupt closure recovery.** Local opening uses `store.id` (`CN/RS/use-register-session.ts:366–373`), and stores it using `?? null` (`CN/RS/session-store.ts:80–83`). Server acknowledgement overwrites local fields (`CN/RS/queue.ts:99–105`); closure drafts copy that session store (`CN/RS/session-store.ts:305–309`). Recovery requires strict equality with `store.id ?? null` (`CN/RS/bridge.tsx:47–66`). For Free’s store ID 0, a reservation carrying NULL fails that comparison; one carrying 0 passes. This is a recovery-path problem, not proof that every close stalls.
- **CHANGED — unassigned reach is explicit, not “`<= 0` admits it.”** Free register schema/default creation uses NULL (`F/includes/Services/Register_Store.php:50,84,113–116`). Both NULL and 0 are treated as unassigned, admitted under restricted reach only with `unassigned: true` (`F/includes/Services/Store_Reach.php:98–105,163–164`). Pro grants that extra access only to `manage_options` holders (`P/includes/Registers.php:181–187`).

**Minimal failing D3 tests, proposed—not run:**

1. Add `Test_Sessions_Controller::test_session_opening_takes_the_store_from_its_register` to `F/tests/includes/API/V2/Test_Sessions_Controller.php`. Create register A with store 456; use the existing valid opening fixture and cash capability (`:43–72`); POST `/wcpos/v2/sessions` with body store 999. Assert 201 and persisted/returned store 456. Today the inferred result is 999. Repeat with omitted store and query-store mismatch.
2. Add an opening-reach case: restrict `Store_Reach::FILTER` to store A, create a register in B, POST a valid opening for B. Assert the same refusal as an unknown register and no inserted session. Today register existence passes.
3. Add a client bridge test with `store.id = 0` and an unapplied reservation carrying NULL. Assert recovery invokes the closure write. The strict comparison currently skips it.

### D4

- **HOLDS — Pro’s per-store threshold is not wired to server closing.** Free reads General and applies the threshold filter at `F/includes/Services/Register_Session_Store.php:265–268`; production searches found no subscriber. Pro persists `_variance_threshold`, reads it, and serves it at `P/includes/Abstracts/Store.php:66,285–295,431,485–489,675–676`. `P/includes/Registers.php:21–26` installs no threshold hook.
- **WRONG as an unresolved Free enforcement claim — General already gates closing.** `POST /wcpos/v2/sessions/{id}/status` calls `transition()` (`F/includes/API/V2/Sessions_Controller.php:243–271`); an unapproved absolute cash variance strictly above a numeric-string threshold returns **403 `wcpos_override_refused`** (`F/includes/Services/Register_Session_Store.php:265–301`). Creating a closure from a counting session also calls that transition (`Closure_Store.php:562–571`).
- **Observed existing coverage, not a test pass:** `F/tests/includes/API/V2/Test_Sessions_Controller.php:554–603` already tests General 5, expected 100, counted 80, refusal and manager approval; `:605–633` covers blank and boundaries. History traces the first test to `b0d2743c`, **2026-09-12**. The review missed evidence already present before October 12.
- **HOLDS — the proposed Pro failing test does not yet exist.** Inspection of `P/tests/includes/Test_Registers.php` found no `test_close_refused_above_the_session_stores_threshold`.
- **HOLDS, but the Free client-impact claim is incomplete.** Free’s store data lacks not just `variance_threshold` but also `register_sessions`: `F/includes/Abstracts/Store.php:40–90,172–197`; the stores API returns that data at `F/includes/API/V1/Stores.php:131–146`. The next client gates sessions on `!!store.register_sessions` (`CN/RS/use-register-session.ts:76–81`). Thus the stock Free path appears disabled, rather than routinely reaching an unexpected threshold refusal.

**Minimal failing D4 test:** add the named test to Pro `Test_Registers`. Create a published store with threshold `'5'`, General `''`, a register/session in that store, counted opening float `'0'`, no transactions, no approver; transition to counting, then POST status `closed` with cash `'10'`. Assert 403, `wcpos_override_refused`, session still counting. Current source predicts success. Add the reverse-policy case: store blank, General `'5'`, variance 10 must close without approval under the documented blank semantics.

### History and released line

**Observed changes since October 12:** Free `ed64585b` added Store Reach; `530685f1` added unassigned reach; `b411bbba` changed figure derivation to `Session_Figures`; subsequent closure/fiscal changes include `aae0337d`, `ac5b075b`, `29ee6211`. Pro `a6ae9a5` added authorized-store reach. None removes D3 or connects D4.

**Released-line finding:** the supplied `M` has neither these till controller/store files nor the General threshold setting. Its controller map has no sessions/registers/closures entries (`M/includes/API/Controller_Registry.php:101–148`; confirmed by file/content searches). These fixes belong on **next**, not main.

## 2. Impact

**D3 severity: High on the 2.0 lane.** It permits durable misattribution of cash/fiscal records across stores, undermining the read authorization that trusts that attribution. It is not an anonymous attack.

**Exact cashier trace, inferred from source:**

1. A valid authenticated user needs POS access and `manage_woocommerce_pos_cash` (`F/includes/API.php:406–417`; `Sessions_Controller.php:68–69`). Cashiers receive the latter by default (`F/includes/Activator.php:585–599`).
2. The cashier can use a known authorized register A but submit store B; no knowledge of B’s register UUID is necessary.
3. Opening accepts B. Subsequent POST status requests fetch the session through the unhooked `session_row` filter, without Store Reach (`Sessions_Controller.php:87–101`).
4. Counting and closing therefore remain reachable, subject to the existing transition/variance rules. Closure creation likewise retrieves the session without a reach check (`Closure_Store.php:363–377`) and freezes its supplied store.
5. GET subsequently checks the corrupted store, potentially hiding the cashier’s own session while placing its records in another store’s reports.

**Refunds have a partial check, not a complete protection.** Pro rejects the session stamp when a **nonzero resolved request store** disagrees with a **nonnull session store** (`P/includes/Payments/Refund_Processor.php:520–524`). Consequently, an ordinary refund explicitly scoped to authorized A will not bind to the forged B session.

However, an omitted or unauthorized request store leaves the resolved store null (`P/includes/API/V1/Order_Refunds_Controller.php:105–117,148–150`), skipping that comparison. Explicit matching register/session stamps can then bind, including a closed session (`Refund_Processor.php:506–524,544–547`), and Free records B as provenance. This requires ordinary refund/order permissions and a refundable order; it is **not proof of arbitrary gateway refunds**. A route-level regression can extend existing Pro `Test_Order_Refunds_Session_Stamp` (`tests/includes/API/Test_Order_Refunds_Session_Stamp.php:165–198`) with reach A, session B, omitted request store, and assertions that B is not stamped.

**D4 severity: High for financial-control integrity.** Every unapproved over-store-threshold close can bypass approval when General is blank or looser. Conversely, a stricter General setting can unnecessarily block the cashier. Frequency depends on enabled sessions, differing settings and actual variances; no merchant incidence data is available.

The next client does enforce its received store threshold, but this is not a security boundary: `CN/packages/core/src/screens/main/pos/cart/register-count.tsx:99–101,285–302`. Blank disables its check (`register-count.helpers.ts:9–10`). Server refusals are recoverable: the queue restores counting and sets `approval_required` (`CN/RS/queue.ts:177–197`).

**Shipped-client distinction:** no till session implementation/caller was found in supplied client `main`; its mounted bridges contain no register-session bridge (`C/apps/main/app/(app)/_layout.tsx:178–198`, corroborated by searches across `apps/` and `packages/`). The actual till caller exists in local client **next**, described above. Do not present this as a demonstrated released-user incident.

Admin configuration is real on next: Free’s General screen edits the threshold (`F/packages/settings/src/screens/general/index.tsx:119–128`); Pro’s store editor edits the per-store field (`P/packages/store-edit/src/components/register-section.tsx:16–22`).

## 3. Cost and risk

- **Free D3:** approximately **25 non-test changed lines**, principally `includes/API/V2/Sessions_Controller.php`: load the register once, check its reach, derive the session store from it, and prevent the creation filter from replacing that identity. Derivation alone does not prevent opening on a known out-of-reach register.
- **Client companion:** approximately **3–5 non-test lines** in next’s `RS/bridge.tsx`, treating NULL and 0 as equivalent unassigned identities for recovery. Add bridge coverage for both representations and unequal positive IDs.
- **Pro D4:** approximately **20–30 non-test lines** in `includes/Registers.php`, including hook registration/documentation and store lookup. Return a real store’s threshold verbatim—including blank. Preserve General for genuinely unassigned/default-store sessions; blindly resolving store 0 to Pro’s blank default would suppress General.
- **Tests:** extend Free `Test_Sessions_Controller`; update its existing creation-filter test (`:735–745`); add Pro `Test_Registers` threshold cases; exercise the closure route as well as status closing, using existing `F/tests/includes/Services/Test_Closure_Store.php:266–277` coverage as prior art. Cover matching, omitted and spoofed stores; unreachable registers; blank/zero/equality/over-threshold; and approval.
- **Pro coupling:** Pro uses Free’s register/session stores directly (`P/includes/Registers.php:62–76,212–219`; `Payments/Refund_Processor.php:504–518`) and extends Free’s Store. No controller/store method signature change is needed. No Pro production consumer of `session_create_fields` was found.
- **Back-compat exposure:** `woocommerce_pos_session_create_fields` currently has a tested store-override contract. Making the register authoritative changes that contract even if the hook remains. Keep this explicit; do not remove unrelated hooks. The REST store field already supports integer or NULL (`F/includes/Services/Register_Session_Store.php:82–85`).
- **Lane:** all implementation pieces target **next**, including the client companion. No main backport is supported.

### Behavior changes / regressions

- Submitted store IDs stop controlling new-session attribution; unreachable-register openings become refusals.
- Canonical NULL for unassigned sessions requires the small client recovery correction; a server-only change must not be advertised as compatible.
- Pro’s store policy replaces General for actual stores: some formerly accepted closes require approval, while blank-store policies stop inheriting unintended General restrictions.
- Existing incorrectly attributed rows are **not repaired** by these changes. Do not rewrite immutable closures as an incidental migration.
- This scope does **not** make every existing-session write/replay/refund reach-safe. Those paths remain a distinct authorization follow-up.

## 4. Options

### Ruling: register or client owns the session store?

1. **Derive from a reachable register — favoured.** Matches the register/session model and immutable closure attribution (`C/CONTEXT.md:321–347`); removes both accidental mismatch and spoofing. Requires a small creation-filter contract change.
2. **Accept client store only when it equals the register store.** Also prevents mismatch, but rejects stale/offline requests unnecessarily and needs explicit NULL/0 normalization; omission still needs server derivation.
3. **Authorize the client’s chosen store without matching the register.** Smaller policy change, but permits inconsistent register/store attribution for users authorized for multiple stores. Contradicts the documented model.

**This is substantially settled by the domain contract, not a fresh product choice.** Derive from the register, and check the register’s reach before inserting.

### Ruling: Free’s session store NULL or 0?

1. **Persist the register’s NULL; normalize NULL/0 at the client recovery comparison — favoured.** Preserves the existing database model and handles historical 0 rows without migration. Costs a tiny coordinated client change.
2. **Persist/return 0 for unassigned sessions.** Fits the client’s synthetic default-store identity and avoids new NULL reservations. But register and session representations differ, and existing NULL reservations still need consideration.
3. **Change Free’s store object itself to NULL.** Makes the particular comparison agree but expands the change into the store identity contract; no evidence justifies that scope.

The Free register is currently NULL, not store 1. Store Reach already recognizes NULL and 0 as the same **unassigned category**, not real stores. The ambiguity is at the client comparison.

### Ruling: enforce the per-store threshold server-side?

1. **Use the existing filter in Pro — favoured.** Both closing routes already converge on `transition()`, so one adapter applies the store’s policy without duplicating monetary calculation.
2. **Keep client-only enforcement.** No implementation cost, but modified clients and blind-count paths can bypass the merchant’s intended approval requirement.

**Not meaningfully a product call:** an approval requirement cannot be enforced only by the requesting client.

### Ruling: blank store threshold means none or inherit General?

1. **Blank means no threshold — strongly favoured.** Explicit glossary definition: `C/CONTEXT.md:395–398`; next-client implementation agrees (`register-count.helpers.ts:9–10`). Free settings copy says “Leave blank for no threshold” (`F/packages/settings/src/translations/locales/en/wp-admin-settings.json:5`). Pro stores and serves blank without an inheritance marker.
2. **Blank inherits General.** Provides centralized defaults, but contradicts the documented meaning and client behavior. It would require an explicit inheritance choice or a separately represented “none,” effective-value delivery, and UI changes—not a twelve-line hook.

Pro’s editor supplies no inheritance explanation (`register-section.tsx:16–22`). The wiki places the policy on the store (`W/architecture/client/register-sessions/records-and-rules.md:19,49`). **The evidence already settles “none”; inheritance would be a new feature.**

## 5. Recommendation

**PURSUE NOW on next, before the 2.0 release.** D3 corrupts the identity trusted by authorization and fiscal attribution; D4 leaves a documented approval control unenforced. Neither was fixed by Store Reach. The fixes are small, but the issue’s “15 + 12 lines” estimate omits register authorization, the client comparison and hook-contract coverage.

**PR 1 on next, Free:** derive new-session store from a reachable register, retain NULL for unassigned rows, and eliminate creation-filter store reassignment (~25 non-test lines; tests: matching/omitted/spoofed store, unreachable register, unassigned identity and creation-filter contract).

**Companion client PR on next, land before PR 1:** normalize NULL/0 only in closure-reservation recovery (~3–5 non-test lines; tests: both unassigned forms recover, different positive stores do not).

**PR 2 on next, Pro, after PR 1:** hook `woocommerce_pos_session_variance_threshold` using the session’s real store; blank means none, unassigned retains General (~20–30 non-test lines; tests: store 5/General blank/variance 10 refusal, reverse-policy case, zero/equality, approvals, status and closure routes).

Separately retain two explicit pre-release follow-ups: existing-session write/replay authorization, and delivery of Free’s General till settings to its store payload. Neither requires redesigning these two fixes.

## 6. Open questions

- **Runtime verification:** source predicts the failures, but no requested regression was executed. Best guess: both D3 and Pro D4 reproduce directly. Settle with the targeted wp-env PHPUnit tests and client bridge tests, including removal-of-fix mutation checks.
- **Store removed before closing:** a positive session store can later be trashed/deleted; ordinary Pro lookup rejects unavailable stores (`P/includes/Services/Stores.php:215–222,264–274`). Best guess: preserve historical policy for a trashed store rather than silently falling back to blank General. Decide the permanently deleted-store behavior and test it before finalizing the adapter.
- **Existing affected 2.0 data/deployments:** disk establishes the released-line absence, not whether pilots have forged/mismatched sessions or pending NULL reservations. Best guess: avoid automatic historical repair. A read-only data audit and deployed-client inventory would determine whether a separate repair/support procedure is needed.