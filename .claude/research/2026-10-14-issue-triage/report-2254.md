## 1. Claim check

**Observed:** read-only inspection; no tests, network calls, Docker, or edits. Runtime outcomes below are **Inferred**, not reproduced.

Paths default to Free `next`. `main:`, `Pro:`, `Client:`, and `Wiki:` identify the supplied alternative trees. Abbreviations: `V1` = `includes/API/V1`, `V2` = `includes/API/V2`, `S` = `includes/Services`, `T` = `includes/Templates`.

**Overall: WRONG that every item is a behavior-neutral dedup.** The duplicates mostly remain, but some encode deliberately different contracts. Unifying the decimal validators, IP resolution, counters, or approval flows would require preserving—or explicitly changing—those differences.

### Thermal emitters — HOLDS; shared logic plus intentional protocol differences

- `T/Thermal/Escpos_Thermal_Emitter.php:167-449` and `Starprnt_Thermal_Emitter.php:175-447` contain eight identical methods after ignoring whitespace and comment-only lines: dispatcher, inline text, line closing, text line, underline, alignment byte, row, and rule.
- **Measured:** 127 matching nonblank/noncomment lines including declarations/braces; 99 excluding declarations and standalone brace scaffolding. The issue’s “~98” is reasonable but lacks a defined counting method.
- Of those 127 lines, **53 are dispatch scaffolding; 74 are shared operations**. They are not predominantly literal data tables. The dispatcher is table-like routing, not substantial layout computation.
- `Epos_Xml_Thermal_Emitter.php:193-222` shares 30 dispatcher lines; `Star_Markup_Thermal_Emitter.php:97-117` shares 21. Their surrounding text/style/feed behavior differs.
- Intentional divergence: initialization (`Escpos:137-140`, `Starprnt:138-144`), magnification (`Escpos:329-343`, `Starprnt:337-355`), and barcode-height clamping (`Escpos:475-477`, `Starprnt:480-485`). Neither implementation is shown wrong.
- **Main:** same quoted duplicate bodies at the same lines. ESC/POS differs later in QR generation, outside this comparison.
- **History:** no changes since October 12 to these four emitters or `Thermal_Emitter_Support.php`. #2012, implementation `46d67cc0`, already extracted text metrics and shared support; it deliberately retained vendor-specific output/state.

### Free products/variations — HOLDS; identical bodies

- `V1/Products_Controller.php:135-191` ↔ `V1/Product_Variations_Controller.php:142-192`: **31 matching nonblank/noncomment lines**, including declarations/braces, across create/update validation and schema augmentation.
- Both call the existing shared sanitizer before their different WooCommerce parents; both add barcode and decimal-stock schema support. No divergent behavior found.
- **Main:** same blocks at `Products_Controller.php:133-189` and `Product_Variations_Controller.php:140-190`.
- **History:** `6372decd` changed collection-rule installation around these methods; it did not remove this duplication.

### Pro products/variations — HOLDS locally; whole methods intentionally differ

- `Pro:V1/Products_Controller.php:98-134` ↔ `Pro:V1/Product_Variations_Controller.php:131-167`: **29 matching nonblank/noncomment lines** for request-field removal, shared pricing-resolver delegation, tax-meta writes, and parent delegation.
- **Important difference:** variations first translate a lone `price` into `regular_price` (`Product_Variations_Controller.php:125-129`); products do not. Extracting the entire update method indiscriminately would change behavior.
- No changes to either Pro file since October 12.

### Customer query logic — HOLDS; equivalent-looking logic, different integration

- `V1/Customers_Controller.php:531-600,767-827` ↔ `V2/Proxy/Customers_Proxy_Behavior.php:112-165,201-220`.
- Shared responsibilities: five sort mappings, one four-field `last_update` clause, eight-role hierarchy, guarded JOIN, CASE ranking, and username tie-break.
- The role SQL occupies **31 versus 19 nonblank/noncomment lines**; formatting and variable names prevent treating it as one literal duplicate.
- **Intentional differences:** v1 merges meta queries through its helper; proxy nests an AND (`Proxy:153-165`). V1 removes its role callback (`Customers_Controller.php:755`); proxy owns scoped hook installation (`Proxy:85-102`).
- **Inferred:** the quoted query bodies preserve the same intended results; not runtime-verified.
- **Main:** same duplication at `V1/Customers_Controller.php:530-599,819-879` and `V2/Proxy/Customers_Proxy_Behavior.php:112-165,233-252`.
- Author intent matters: `includes/Sync/Collection_Rules.php:425-439` explicitly leaves customer clause bodies outside the order-storage rule engine. Do not expand that engine as a dedup.

### Print counters — HOLDS as parallel responsibilities; WRONG as interchangeable copies

- `S/Receipt_Print_Counter.php:44-55,69-102` uses an order named lock, renders with an integer count, and saves WooCommerce order metadata after nonempty output.
- `S/Closure_Print_Counter.php:22-62` updates a closure within a transaction, renders a marked payload, and rolls back unsuccessful output.
- The genuinely shared business expression is essentially **two reprint-field assignments** (`Receipt:70-71`, `Closure:36-37`), not a shared persistence algorithm.
- Empty-result contracts also differ: receipt supports an explicit predicate; closure tests strictly against `''`.
- **Main:** neither counter exists. No counter implementation changes since October 12.

### Paper width — HOLDS; same parsing, deliberately different units

- `T/Renderers/Thermal_Html_Renderer.php:100-109` ↔ `S/Template_Pdf_Service.php:96-105`.
- Same leading-number parsing, 25–250 mm bounds, and 80 mm fallback: **five substantive shared parsing/guard lines**. Conversion is intentionally 96 dpi versus 72 points/inch.
- **Main:** same HTML method; PDF method at `:89-98`.
- No disagreement in accepted width or fallback found.

### Engine → language — HOLDS for supported engines

- `includes/Admin/Templates/Single_Template.php:25-29,412-420` ↔ `includes/Templates.php:1395-1406`: three mappings, thermal→XML, logicless→HTML, legacy-PHP→PHP.
- Admin validates the engine before indexing; import defaults unrecognized engines to PHP. These are different entry-point policies, not evidence that a supported mapping is wrong.
- **Main:** same map at `Single_Template.php:25-29`; import mapping at `Templates.php:1332-1339`.

### Closure-print capability — HOLDS; one repeated predicate

- `V1/Receipts_Controller.php:394-395` ↔ `T/Receipt.php:527-528`: closure + print intent requires `manage_woocommerce_pos_cash`.
- Predicate is equivalent; error delivery deliberately differs: REST `WP_Error` versus page `wp_die`.
- **Main:** no corresponding closure-print path.
- Surrounding receipt code changed since October 12, including `452c2dda`’s missing-closure-template refusal; the predicate remains duplicated.

### Decimal validators — HOLDS; genuinely divergent contracts and one precision concern

- Sessions: `V2/Sessions_Controller.php:346-352` accepts unsigned decimal strings, permits extra fractional digits, ignores leading zeroes for the integer-width check, and normalizes through SQL.
- Closures: `V2/Closures_Controller.php:327-331` accepts at most 15 integer/four fractional digits, optionally permits negatives, and uses exact SQL arithmetic through `S/Closure_Store.php:322-330`.
- Registers: `V2/Registers_Controller.php:200-209` permits nullable unsigned decimals and normalizes with `wc_format_decimal(..., 4)`.
- **Measured scope:** roughly 7, 5, and 10 substantive lines respectively—not three copies of one algorithm.
- **Main:** all three controllers are absent. Their validation bodies are unchanged since October 12.

**Inferred input differences:**

| Input | Sessions | Closures, unsigned | Register default |
|---|---|---|---|
| `'1.23456'` | `'1.2346'` | Rejected | `'1.2346'` |
| `'0000000000000001'` | `'1.0000'` | Rejected: 16 integer characters | `'1.0000'` |
| `'-1'` | Rejected | Rejected; accepted for signed expectations | Rejected |
| `'100000000000000.0001'` | Exact value retained | Exact value retained | Fraction lost through float conversion |

The last row is a **precision defect candidate, not merely dedup**. On-disk WooCommerce implements `wc_format_decimal` with `number_format(floatval(...))`: `/Users/kilbot/.wp-env/wp-env-arch-runner-next-0522a9ca/woocommerce/includes/wc-formatting-functions.php:306-308`. Register storage supports that exact value (`S/Register_Store.php:51`).

**Minimal failing PHPUnit test, not run:** add a proposed precision-preservation test to `tests/includes/API/V2/Test_Registers_Controller.php`. Use its installed register table and manager fixture; POST `/wcpos/v2/registers` with `name: Front`, `default_float: '100000000000000.0001'`; assert 201 and that exact returned/stored string. Repeat through PATCH. Existing request helpers are at `:26-29,91-95`. This should fail on the inspected WooCommerce implementation.

### Business day — HOLDS; one identical expression

- `includes/Activator.php:322` ↔ `S/Report_Scope_Resolver.php:103-105`: UTC opening timestamp → site timezone → date.
- This is **one expression**, not a meaningful duplicated subsystem.
- Resolver documentation explicitly requires matching the migration rather than the store timezone (`:96-102`). No defect found.
- **Main:** neither this migration nor resolver exists.

### IP resolution — HOLDS, but disagreement is larger than the issue says

- `S/Session_Context.php:123-154` chooses the **first nonempty header**, then validates; invalid means `''`.
- `T/Auth.php:551-575` chooses the **first valid header**, continuing after invalid values; none means `'unknown'`.
- Bodies contain 24 versus 21 nonblank/noncomment lines; 12 match textually after trimming.

**Inferred examples:**
- No headers: `''` versus `'unknown'`.
- `HTTP_CF_CONNECTING_IP=not-an-ip`, `REMOTE_ADDR=203.0.113.4`: `''` versus `'203.0.113.4'`.
- Valid `X-Forwarded-For=203.0.113.5, 198.51.100.1`: both return `'203.0.113.5'`.

**Neither side is established wrong.** Session behavior is explicitly characterized by `tests/includes/Services/Test_Session_Context.php:199-211`; auth uses its answer for rate-limit keys (`T/Auth.php:455-473`). Replacing auth with `Session_Context` plus an `'unknown'` fallback would change rate-limit grouping, not merely presentation.

**Main:** both files are byte-identical to next. Auth-page lifecycle changed in `2830d8cf`; neither resolver changed.

### Manager approval — HOLDS as repeated authentication pattern; deliberately divergent policy

- Session password approval: `V2/Sessions_Controller.php:203-215`.
- Session JWT approval: `:262-268`.
- Closure recount password approval: `V2/Closures_Controller.php:128-140`.
- Shared nucleus: credential validation followed by `manage_woocommerce_pos_closures`; roughly **6–11 lines per branch**, not identical end-to-end operations.
- Sessions require another user and a counting session. Recount allows a manager directly; its password branch runs only when the current user lacks the capability, and distinguishes invalid credentials from insufficient capability.
- The missing explicit self-check in recount is not an observed bypass: that same user already lacks the required capability.
- **Main:** absent. Approval bodies unchanged since October 12.

## 2. Impact

| Area | Actual reach and frequency | Severity of established issue |
|---|---|---|
| Thermal/layout/width | Cashiers’ server-rendered cloud jobs: `Client:packages/core/src/screens/main/hooks/use-cloud-enqueue.ts:20-33` → `S/Print_Job_Service.php:317-368`. PDF download reaches width conversion (`Client:…/receipt/hooks/use-download-receipt-pdf.ts:41-43`). | **Low for this chore**: no malfunction established. A layout regression would be Medium—bad receipts or interrupted printing. |
| Products/customers | Customer proxy serves normal browse/backfill (`Client:packages/sync-engine/src/maintenance/customer-trickle.ts:229-242`). V1 remains registered (`includes/API/Controller_Registry.php:119-123`); current client selects v2 (`Client:…/hooks/use-rest-http-client/index.ts:246`). | **Low**: developer maintenance burden; no demonstrated user-facing divergence in the quoted bodies. |
| Decimal/default float | Next’s admin screen POSTs/PATCHes the entered string (`packages/settings/src/screens/registers/index.tsx:49-69,90-94`). Extreme valid amounts can lose fractional precision. | **Low currently**: real numerical concern, but demonstrated input is implausibly large for a drawer default; no released-user exposure. |
| IP | Login page rate limiting and session metadata; current web client navigates to that login flow (`Client:packages/core/src/hooks/use-wcpos-auth/index.web.ts:171-179`). Session UI displays missing IP as `N/A` (`packages/settings/src/screens/sessions/session-card.tsx:135`). | **Low observed impact**: inconsistent diagnostics under malformed headers. Changing the auth policy has High-stakes consequences; no exploit attributable to this divergence was established. |
| Counters, closure permission, business day, approvals | Next-only till/fiscal surfaces. Supplied client `apps/` and `packages/` contain no `counted_float`, `approver_token`, or closure-document callers. Its receipt requests send neither closure selector nor print intent (`Client:…/use-receipt-data.ts:95-96`, `…/use-download-receipt-pdf.ts:41-43`). | **Low for the duplication itself**; no present defect established. Authorization/fiscal-write refactors remain High stakes. |
| Engine map | Admin saves and template imports, not every sale (`Single_Template.php:412-420`; `Templates.php:1395-1406`). | **Low**: supported mappings agree. |

The absence finding is limited to the **supplied client main**, not every historical or development build. Wiki explicitly places register sessions on client `next` (`Wiki:architecture/client/register-sessions.md:10`).

## 3. Cost and risk

These are alternative future scopes, **not a proposed cleanup programme**. Estimates count additions and deletions, not net shrinkage.

| Candidate | Files / rough non-test changed lines | Characterization needed | Coupling / lane |
|---|---|---|---|
| Register precision fix only | One controller, ~10–15 | POST/PATCH exact value; ordinary rounding, null, invalid syntax, overflow | Pro consumes validated fields via `Pro:includes/Registers.php:24,116-124`; retain filter and response shapes. **Next**. |
| Free product bodies | Two controllers + existing trait, ~100–130 | Both schemas; malformed metadata; parent dispatch | Pro subclasses both Free controllers (`Pro:V1/Products_Controller.php:13,30`; variation counterpart). Preserve public overrides. **Next**, not a patch-only main chore. |
| Pro update fragment | Two controllers + shared location, ~80–110 | Product versus variation `price`-only behavior; store prices/taxes | Existing pricing resolver already owns the calculation. Preserve variation preprocessing. **Pro next**. |
| Customer SQL only | Two consumers + helper, ~100–150 | Both routes: roles, tie-break, modified cutoff, existing OR meta clauses, hook isolation | Public callbacks/filter timing exposed. Existing parity coverage: `tests/includes/Sync/Customers_Lane_Parity_Tests.php:16-29`. **Next**. |
| Thermal row/rule only | Two emitters + existing trait, ~120–150 | Existing byte goldens plus any missing affected case | No direct Pro emitter references found; indirect cloud-print use. **Next**. |
| Width / language | ~20–35 / ~20–30, each across 2–3 files | Width boundaries/units; supported engines and entry-point defaults | Metadata/printed dimensions must remain unchanged. **Next**. |
| Closure capability | ~15–25 across callers/shared owner | REST/page allow/deny and distinct error envelopes | Auth-sensitive despite tiny predicate; no direct Pro override found. **Next**. |
| Business day | ~10–20 across 2–3 files | Midnight/timezone and stamped-versus-legacy rows | Migration/report coupling for one expression. **Next**. |
| IP | ~40–70 across 2–3 files if both policies retained | Invalid-first/valid-later headers, IPv6, chains, sentinel, rate-limit buckets | Public `Session_Context` method and auth grouping exposed. Pure cleanup **next**; a proven released defect would target **main**. |
| Manager approval / counters | No defensible shared scope yet | Existing authorization matrices / output-failure and count persistence | Different policies/storage; generic abstractions would expand scope. **Next only**. |

For thermal work, existing exact-output assertions are at `tests/includes/Templates/Thermal/Thermal_Emitter_Golden_Test.php:56-83`. Run unchanged old and new implementations against the same fixtures; do not regenerate goldens to bless drift. Byte neutrality remains **Unverified** here.

## 4. Options

### Decisions by item

| Item | Options and concrete tradeoffs | Evidence favours |
|---|---|---|
| Thermal | **Leave:** zero output risk, repeated edits remain. **Existing-trait extraction:** shares row/rule logic without inheritance changes, but moves ~120–150 lines. **Base class/universal dispatcher:** removes more switches but requires reconciling private state and differing text/feed behavior. | **Close standalone chore.** Existing `Thermal_Emitter_Support` is the appropriate seam if a future layout change needs both copies. No new template logic. |
| Free products | **Leave:** preserves straightforward WooCommerce overrides. **Move bodies into existing trait:** removes identical code but adds indirection and touches Pro inheritance. | **Close** absent an upcoming schema/metadata change. |
| Pro products | **Leave:** existing resolver already owns complex pricing. **Extract request mutation only:** modest saving, must leave variation’s `price` translation outside. | **Close** standalone; combine with a concrete store-pricing change if needed. |
| Customers | **Leave with existing parity tests:** duplication remains but contracts are covered. **Extract narrow SQL builder:** one role ladder/CASE, no new storage dialect. **Extend Collection Rules:** larger redesign contrary to documented scope. | **Close** standalone; narrow extraction only when changing customer sorting/filtering. |
| Counters | **Keep storage-specific classes:** preserves lock/transaction behavior. **Share marking only:** saves two assignments. **Unify persistence:** adds strategy callbacks and risks counting/rollback semantics. | **Close.** Not a meaningful product decision; different storage explains the split. |
| Paper width | **Leave:** five duplicated parsing lines. **Share millimetre parser:** one bounds/default owner, conversions remain local. | **Close** until width rules change. Never unify the units. |
| Engine map | **Leave:** three stable mappings. **Central lookup:** worthwhile when adding an engine, but must preserve admin validation versus import fallback. | **Close** until an engine is added. |
| Closure capability | **Leave at both boundaries:** explicit, different transport errors. **Share predicate:** one policy owner, more indirection for one condition. | **Close** unless closure-print permissions change. |
| Decimals | **Keep contracts, fix float precision only:** smallest defect-focused change. **One configurable parser:** could preserve policies, but introduces modes for scale/sign/null/leading-zero differences. **One uniform policy:** changes accepted REST input and requires a client contract decision. | **Precision-only follow-up.** Exact storage-supported values should not pass through floats; that part is engineering, not a product call. Uniform acceptance is not justified by this issue. |
| Business day | **Leave expression:** directly documents migration equivalence. **Shared date helper:** prevents theoretical drift but couples migration/runtime for one line. | **Close.** Existing comments already settle site-timezone behavior. |
| IP | **Preserve both:** no rate-limit change. **Share sanitizer/header list but retain selection policies:** limited saving. **Choose one policy:** changes either diagnostics or rate-limit grouping. | **Preserve both.** Do not silently change auth to make metadata agree. A behavior change needs a concrete operational problem. |
| Manager approval | **Leave:** policies/errors visible locally. **Share credential-to-user lookup only:** little saving. **Unified approval service:** needs policy switches for self-approval, state, credential kind, and error codes. | **Close** standalone. No authentication subsystem is warranted. |

### Rank: duplication-related bug risk × ease of a useful small change

1. **Decimal precision:** only concrete numerical defect candidate; easy one-file fix, though current practical impact is low.
2. **Customer SQL:** meaningful cross-lane drift risk; bounded extraction possible, existing parity tests reduce urgency.
3. **Paper width:** easy shared policy, possible HTML/PDF drift; only five lines.
4. **Free product schema/validation:** identical and already has shared traits; little immediate leverage.
5. **Engine language map:** easy, but only three stable entries.
6. **Pro request mutation:** bounded, but variation-specific semantics increase risk.
7. **Thermal row/rule:** genuine shared logic, offset by byte-output verification and move size.
8. **Closure capability:** consequential policy, but a one-predicate extraction provides little benefit.
9. **IP:** divergence is real; behavior-neutral consolidation is not simple enough to justify itself.
10. **Manager approval:** different policies dominate the similarity.
11. **Business day:** trivial identical expression, already explicitly linked.
12. **Counters:** least suitable dedup; persistence differences are fundamental.

**No pure dedup here earns its own PR on current evidence.**

## 5. Recommendation

**PURSUE LATER — trigger: the register-session feature’s pre-release validation before shipping 2.0.**

Retain only the register precision concern as a narrowly defined follow-up; close the remaining standalone dedup candidates. The issue’s “each is behavior-neutral” premise is unsafe, and no quoted duplicate currently demonstrates a normal cashier-facing failure.

**PR 1 on next: preserve exact `default_float` normalization without changing existing null, syntax, rounding, or overflow policy (~15 non-test lines; tests: POST/PATCH precision regression plus ordinary-value, rounding, null, invalid-input, and overflow characterization).**

Do not include a three-controller decimal abstraction, IP-policy change, emitter base class, or generic counter/approval service.

### Behavior changes / regressions

- **This research:** no code or behavior changed.
- **Proposed precision fix:** valid large decimal defaults would retain fractional digits currently lost through float conversion. That is an intentional behavior correction, not a behavior-neutral dedup.
- Compatibility, byte neutrality, and runtime test results were **not evaluated**.

## 6. Open questions

- **Does the client next normalize closure values to four decimals before submission?** Best guess: yes, but supplied client main cannot establish it. Inspect the development client’s session/closure serializers before standardizing decimal acceptance.
- **Is extreme default-float precision materially relevant to supported deployments?** Best guess: rarely. The proposed PHPUnit case would establish the defect; a practical amount/currency report would justify moving it ahead of pre-release work.
- **Should malformed higher-priority proxy headers fall through for session metadata?** Current tests deliberately say no; auth deliberately does fall through. Keep both unless a hosting/logging incident or explicit maintainer ruling establishes the desired shared policy.