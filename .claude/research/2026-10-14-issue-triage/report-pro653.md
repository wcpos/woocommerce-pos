## 1. **Claim check**

**Evidence:** source inspection and local Git history only; no tests, network, Docker, or edits. Runtime equivalence is **unverified**.

Citation prefixes:
- `F:` Free `next`, supplied worktree.
- `P:` Pro `next`, supplied worktree.
- `FM:` Free `main`, supplied checkout.
- `PM:` Pro local `origin/main`.
- `C:` supplied client checkout.
- `W:` supplied wiki checkout.

**Checkout caveat:** Pro’s main working directory is at `241c3cb`, behind local `origin/main` `67ccce4`. I checked the latter with Git: among the relevant files, only the lock-release fix differs. Do not mistake the stale working copy for an unfixed main branch.

**#637 already landed — HOLDS.** `P:includes/Payments/Idempotency_Repository.php:150–158` now releases the same Pro lock that `reserve()` creates. Local `origin/main` has the same fix at the same lines. History identifies `d06499f` and merge `befca7b` (#637). Tests now exist at `P:tests/includes/Payments/Test_Idempotency_Repository.php:24–52` and `Test_Refund_Processor.php:124–136`; they were not run.

**(a), redundant implementation — HOLDS; “safe swap leaving Pro’s repository unused” — WRONG as written.**

The production chain remains:
`P:includes/Payments/Refund_Processor.php:105,131`
→ `Adapters/WordPress_Idempotency_Store.php:13,68`
→ Pro `Idempotency_Repository.php:96–141`.
Free exposes `claim()`, not `reserve()` (`F:includes/Payments/Idempotency_Repository.php:76`).

The state machines are similar, not equivalent:

| Situation | Pro `reserve()` | Free `claim()` |
|---|---|---|
| No lock or cached response | Creates Pro option; returns `true` (`P:96–108`). | Creates Free option; returns `true` (`F:92–100`). |
| Completed same-key request | Does not inspect response storage; may acquire a lock or return an in-flight error (`P:96–141`). | Returns cached response array (`F:77–89,117–128`). |
| Completed different-hash request | `reserve()` itself does not check cached responses. Processor separately checks them (`P:Refund_Processor.php:120–128`). | Returns conflict 409 (`F:79–86,118–125`). |
| Active same-hash reservation | In-progress 409 (`P:127–141`). | In-progress 409, unless a response appeared meanwhile (`F:117–145`). |
| Active different-hash reservation | Conflict 409 (`P:127–133`). | Conflict 409 (`F:131–137`). |
| Stale reservation | Reclaims after **more than** 300 seconds (`P:119–124,224–226`). | Reclaims at **300 seconds or more** (`F:102–114,207–214`). |
| Invalid non-array option | Deletes and attempts replacement (`P:111–116,224–226`). | Does not classify it as stale; a persistent scalar option produces in-progress 409 (`F:102–145,207–210`). |
| Exception | Neither repository catches exceptions. | Same. Processor’s `finally` releases only after reservation returned successfully (`P:Refund_Processor.php:131–137,176–177`). |

For ordinary duplicate requests, the processor’s initial lookup already replays the response. Its second lookup at `:137–139` covers a response appearing between the first lookup and reservation, **provided that response remains available**.

**Inferred counterexample to “array→true is safe”:** Free can return a cached array without acquiring a lock (`F:77–89`). If that transient disappears before the processor’s second lookup, converting the array to `true` discards the replay and lets execution proceed without a claim (`P:Refund_Processor.php:137–149`). The existing Pro reservation normally owns a lock at that point. This is a proposed-refactor risk, not a reproduced current defect.

There is also an overlooked public caller: `P:includes/API/V1/Order_Refunds_Controller.php:65–83` accepts Pro’s repository and passes it into `WordPress_Idempotency_Store`. Changing the adapter’s constructor type to Free’s repository (`Adapters/WordPress_Idempotency_Store.php:31`) breaks that injection. Pro’s repository is **not unused** merely because default construction changes.

Free’s repository is byte-identical on the supplied main and next trees; the adapter and legacy controller injection also exist on Pro main.

**(b), registry twins — HOLDS after renaming.**

Observed: direct `diff -u` reports only namespace/class names, interface names, comments, and server/device log text. In-memory normalization of those names produces identical files.

Both are 90 lines:
- `P:includes/Payments/Server/Server_Providers.php:15–90`
- `P:includes/Payments/Device/Device_Providers.php:15–90`

Their intentional distinctions are separate singleton state (`:21,36–40`), accepted provider interface (`:50`), typed `get()` result (`:75`), and diagnostic wording (`:51,55`). An interface constant alone does not preserve all four automatically.

The helper functions exist (`P:includes/wcpos-pro-functions.php:34–47`), but “class names are not exposed” is overstated: the classes and methods are public. Neither registry exists in Pro main’s tracked Payments tree.

**(c), default production wiring never injects the legacy resolver — HOLDS.**

`P:includes/API.php:92,110` selects controller classes; `F:includes/API/Controller_Registry.php:217–221` constructs them without arguments. The controller consequently takes `P:includes/API/V1/Order_Refunds_Controller.php:92`, creating a processor without a resolver. The branch requires injection (`P:includes/Payments/Refund_Processor.php:190–192`).

Caller census on the supplied next sources:
- **One conditional production forwarding site:** controller `:79–87`.
- **Zero production sites supplying an actual legacy resolver.**
- **Three test injection sites:** `P:tests/includes/Payments/Test_Refund_Processor.php:507,547,605`.
- **One mapper call:** `Refund_Processor.php:191`.
- **One factory call:** `Refund_Processor.php:290`.
- **Zero Free source/test references** to the legacy symbols.

I also searched PHP sources, including hidden/ignored source directories, under every supplied `*terminal-for-woocommerce*` and `wcpos-*` directory, excluding dependencies and Git metadata. **No extension integration injects this path in those sources.**

There is a literal on-disk exception: an older Pro copy under `wcpos-org-review-guidelines/wcpos__woocommerce-pos-pro/includes/API/Order_Refunds_Controller.php:80–88` constructs `Refund_Orchestrator` by default. That is not evidence of a terminal-extension integration, but “no caller anywhere on disk” would be false.

**(c), “public in released Pro” — HOLDS, with two corrections.**
- Main has `Refund_Orchestrator::resolve()` at `PM:includes/Payments/Refund_Orchestrator.php:19–28`, the processor’s fifth argument at `Refund_Processor.php:90`, and `RefundPlan::from_legacy_resolution()` at `RefundPlan.php:106`.
- `plan_from_legacy_resolution()` is **private**, not public (`P:Refund_Processor.php:249`; `PM:Refund_Processor.php:218`).
- They did not all originate on April 23: history adds the orchestrator in `3fe1546` (April 23), but `RefundPlan` in `eb5b114` (June 17). The wiki explicitly records released compatibility support (`W:architecture/plugin-pro/rest-namespaces-and-refunds.md:120–129`).

**Since the review — CHANGED outside the proposed cleanup.** Comparing `05f06c4..82cb93e`, only the repository and processor changed among the named targets: #637 fixed release; `35f422b` replaced the processor’s allocation-capacity loop with Free’s Ledger verb. The adapter, registries, legacy mapper/factory, and controller constructor are unchanged. Thus “only #637 landed” holds for this cleanup, not for the wider payments review.

**Reproduction status:** no remaining current production defect is established by this issue. A minimal proposed `Test_WordPress_Idempotency_Store` characterization test can falsify equivalence today: seed both repositories’ option keys with a scalar value, request reservation with identical scope/key/hash, and assert equal normalized results. Pro returns `true`; Free returns an error. This demonstrates a contract difference, not a merchant incident. No REST request is needed for that repository-level test.

## 2. **Impact**

**Current issue severity: Low — maintenance duplication, not an established outstanding refund failure. Implementation stakes: High — these changes control money movement.**

- **Cashiers/merchants:** every POS refund reaches the idempotency implementation. The supplied client posts `orders/{id}/refunds` with a newly generated key (`C:packages/core/src/screens/main/orders/refund/use-refund-mutation.ts:66–67,100–103`), using the v2 service base (`C:packages/core/src/screens/main/hooks/use-rest-http-client/index.ts:238–255`). Pro’s v2 controller inherits v1 implementation (`P:includes/API/V2/Order_Refunds_Controller.php:15–21`).
- **Frequency:** normal refunds exercise the stack; concurrent same-key retries and exceptions exercise its less common branches. Disk evidence gives no incident rate. Each new user-initiated mutation generates a fresh key, so this refactor does not prevent repeated user actions from creating separate refund requests.
- **Legacy injection:** no shipped-source client/admin path was found that supplies a resolver. HTTP payload fields cannot supply a PHP constructor dependency; default controller construction demonstrably omits it. This is narrower than claiming no external integration could use the public constructor.
- **Provider registries:** active infrastructure, not dead code. They determine capture mode (`P:includes/Init.php:107–115`), dispatch server/device operations (`Server/Server_Handler.php:25,69`; `Device/Device_Handler.php:25,219`), and feed support bundles (`Payments/Support_Bundle.php:43,100`). A bad shared singleton could mix providers; keeping duplication currently causes no demonstrated cashier failure.
- **Developers:** fewer duplicate implementations would reduce maintenance, but deleting released injection APIs affects integration authors. No attacker-facing defect is established.

## 3. **Cost and risk**

Estimates below count changed non-test lines, including deletions—not merely net savings. These are alternatives, not one approved bundle.

| Piece | Likely scope | Tests / coupling | Lane |
|---|---|---|---|
| Direct Free-backed store, retaining Pro repository for existing injection | Adapter plus explicit handling of the controller’s old repository argument; roughly 30–70 lines before replay-contract changes. Does not remove the duplicate repository. | New real WordPress-store tests; existing controller injection must remain accepted. | `next`: refactor, not an outstanding main fix. |
| Remove Pro repository completely | 228-line deletion plus adapter/controller changes: roughly 250–300 lines. Larger if replay arrays become part of the store contract. | Released constructor types and repository methods disappear; both v1 and v2 controllers are affected. | `next`, explicit API-break approval. |
| Registry base extraction | Two 90-line files plus a shared base; roughly 180–240 changed lines. Net saving depends on retaining typed wrappers and separate singleton ownership; −55 is not established. | Existing server/device handler tests; add isolation, invalid-interface rejection, lazy construction, and replacement tests. | `next` only. |
| Legacy resolver removal only | Delete 131-line orchestrator, processor property/argument/branch/50-line mapper, 13-line factory, and controller forwarding references: roughly 230 lines. | Remove three obsolete injection tests; retain refund behavior tests. Do **not** remove audit/repository injection accidentally. | `next` only. |

Relevant existing tests:
- `P:tests/includes/Payments/Test_Idempotency_Repository.php`
- `P:tests/includes/Payments/Test_Refund_Processor.php`
- `P:tests/includes/API/Test_Order_Refunds_Controller.php`
- `P:tests/includes/API/Test_Order_Refunds_Orchestration.php`
- `P:tests/includes/API/Test_Order_Refunds_Session_Stamp.php`
- `P:tests/includes/Payments/Server/Test_Server_Handler.php`
- `P:tests/includes/Payments/Device/Test_Device_Handler.php`

Controller/orchestration tests predominantly dispatch v1; the session-stamp test dispatches v2 (`:267`). Any later implementation needs v2 refund coverage, not just legacy-route green tests.

**Pro coupling:** all proposed edits are in Pro. Free is bundled (`P:woocommerce-pos-pro.php:43`, `composer.json:25`); no Free API addition is required. Preserve provider-registration functions, their interface checks, and controller wire responses.

**Behavior changes / regressions**
- No changes were made during this research.
- `reserve`→`claim` changes lock names, stale-boundary handling, malformed-option handling, cached-response behavior, and in-progress error wording/translation domain.
- Changing the adapter constructor type rejects existing Pro repository objects unless the controller/public injection contract is addressed.
- Removing the legacy resolver removes released PHP customization behavior, even though no current extension caller was found.
- Deleting resolver injection must not delete legacy request-body support for `api_refund`, which is separate and still used (`P:Refund_Processor.php:299–308`; client mutation `:46–47`).

## 4. **Options**

**(a) Consolidate idempotency**

1. **Keep the fixed implementation for now.**  
   Pro: no money-path behavior change; #637 already removes the demonstrated defect. Con: retains 228 lines of duplicate/fallback logic.

2. **Use Free directly but retain Pro’s repository for released injection.**  
   Pro: removes one hop from default production wiring. Con: does not achieve the claimed deletion, needs explicit compatibility handling, and still requires testing the state-machine differences.

3. **Retire repository injection and delete Pro’s repository during the 2.0 API cleanup.**  
   Pro: genuinely removes duplication; no known extension caller needs migration. Con: deliberate released PHP API break; must handle cached replay without assuming a later lookup always succeeds and explicitly accept the behavior differences.

**Favoured:** option 1 now; option 3 only as an approved 2.0 cleanup. The proposed mechanical swap is not ready. This is partly a compatibility decision, not just a rename.

**(b) Merge provider registries**

1. **Keep the two small registries.**  
   Pro: preserves strongly typed interfaces and independent instances without new structure. Con: fixes must be mirrored.
2. **Extract a shared base while retaining concrete singleton ownership, typed `get()` methods, and log labels.**  
   Pro: centralizes registration/cache behavior. Con: adds inheritance and changes active payment dispatch for a modest maintenance saving.

**Favoured:** option 1 until a concrete registry behavior change otherwise needs duplicate edits. The source equivalence is settled; urgency is not. There is no product ruling needed here.

**(c) Remove released legacy refund injection**

1. **Keep the deprecated path.**  
   Pro: honors the explicitly documented released contract (`W:architecture/plugin-pro/rest-namespaces-and-refunds.md:120–129`). Con: retains roughly 230 removable lines and three compatibility tests.
2. **Remove it in 2.0, with a developer-facing breaking-change note.**  
   Pro: no current production/extension source caller found; removes an alternate resolution path and its maintenance. Con: unknown private integrations may still subclass the released resolver; this is not a free deletion.
3. **Retain it through 2.0 and remove at a later announced major.**  
   Pro: gives external authors notice. Con: prolongs support without evidence of a known consumer.

**Favoured:** option 2 if Paul wants this public PHP extension point retired; otherwise option 1. The caller scan settles known integration migration work—none was found—but does not erase the released contract.

No blanket “2.0 may delete public APIs” policy was found in Pro’s changelogs; there is no root README/readme. `P:CHANGELOG.md:342` records specific earlier breaking changes, not blanket permission. The refund design requires versioned REST breaking changes (`P:docs/superpowers/specs/2026-04-23-pos-payment-gateways-checkout-refunds-design.md:115–123`); these PHP removals still need their own explicit decision.

## 5. **Recommendation**

**PURSUE LATER — trigger: the 2.0 public-PHP-API cleanup, with explicit approval to retire legacy refund injection.**

Do not implement “(a) and (b) are safe together” as filed. The production defect is already fixed; (a) overlooks an actual controller dependency and non-equivalent reservation semantics; (b) has no demonstrated operational benefit.

The strongest bounded cleanup is (c): the caller search found no active extension integration, and its removal can stand alone without changing idempotency or provider dispatch.

**PR 1 on `next`: remove legacy `Refund_Orchestrator` injection, its processor mapper and `RefundPlan` factory, including controller forwarding references; retain audit/repository injection and `api_refund` request handling; document the PHP API removal (~230 non-test lines, tests: remove the three legacy-injection tests; verify remaining processor/refund-controller tests and v2 refund creation/replay).**

That scope is conditional on Paul approving option (c)(2). Do not bundle repository deletion or registry extraction into it. No new main-lane PR is justified by this issue.

## 6. **Open questions**

- **May 2.0 retire the documented resolver extension point?** Best guess: yes, given no active caller in the searched sources. Paul’s explicit approval settles the policy; an undisclosed integration would change the migration cost.
- **Does anyone outside the supplied source trees inject Pro’s repository or resolver?** Best guess: uncommon, but unknown. Private integration inventories or extension-author confirmation would settle it; the archived Pro copy is not such confirmation.
- **Will a Free-backed adapter preserve required replay behavior under cache disappearance and exceptions?** Unverified. Before pursuing (a), run comparative WordPress-store tests for cached success/error replay, conflicting hashes, active/stale/malformed reservations, thrown processing, and a response disappearing between claim and lookup. No equivalence claim is supported by execution in this report.