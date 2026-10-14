## 1. Claim check

**Observed:** read-only inspection; no tests, Docker, network calls, or edits. Runtime conclusions below are **Inferred**, not reproduced.

Citation prefixes:
- **N**: supplied Free `next` tree, `92b5d970`.
- **M**: `/Users/kilbot/Projects/woocommerce-pos`, released `main`.
- **C**: supplied client tree, observed HEAD `c5def3c61`.
- **P**: supplied Pro `next` tree.
- **WC**: `/Users/kilbot/.wp-env/wp-env-arch-runner-next-0522a9ca/woocommerce`.
- **W**: `/Users/kilbot/Projects/wiki`.

**History:** `git log 837e985f..92b5d970` for the relevant implementation files found only `6372decd` (v1 read-hook refactor) and `bf82e408` (paid-date suppression refactor), both October 13. Neither resolves these rulings. The October 12 restart fixes, including `a41ea525`, remain present.

### Existing structure and missing rulings — HOLDS

- The issue names nonexistent `includes/Sync/Order_Writer.php` and `includes/Sync/Order_Write_Intent.php`. Actual owners are `includes/API/V2/Writers/Order_Writer.php` and `includes/Services/Order_Write_Intent.php`.
- The lane-rulings block still contains only coupons, omitted items and tax IDs: `N:includes/Sync/Order_Write_Payload.php:34-37`.
- Restart eligibility is centralized and deliberately evaluated twice: writer `:197-205`, `:340`, `:462`. The payment lock surrounds the forward; contention suppresses the restart, not the update (`:151-174`).
- Existing pins cover create-date parity, v1 callback priority and restart dates: `N:tests/includes/Sync/Test_Order_Write_Parity.php:460-480,647-677`; `N:tests/includes/Sync/Test_Rest_Dispatch_Cart_Restart.php:131-166`.
- No consolidation is warranted. Strictly, “pure function” is inaccurate: `cart_restart_gmt()` reads the payment ledger (`writer:201`).

### 1. V1 update ignores the date and strips provenance/cash without filling — HOLDS on next; only partly shared with main

**Date:** v1 registers its preservation callback only around create (`N:includes/API/V1/Orders_Controller.php:437-449`); the callback also rejects non-create use (`:467-476`). Update declares no `created_gmt` or restart context (`:514-523`).

WooCommerce excludes readonly properties during preparation; `date_created_gmt` is readonly (`WC:includes/rest-api/Controllers/Version2/class-wc-rest-orders-v2-controller.php:704-719,1208-1212`). Thus v1 update neither applies the date nor invokes WCPOS’s date validator.

**Submitted metadata:** update strips audit keys and protected row IDs before forwarding (`N:includes/API/V1/Orders_Controller.php:498-522`). The protected list includes cash and the eight sale-provenance keys (`N:includes/Services/Pos_Order_Audit.php:29-45,54,114-126`). No v2-style provenance/cash fill context is declared.

Qualification: this does **not** mean “v1 updates never write audit metadata.” Missing cashier attribution can still be filled (`N:includes/API/V1/Orders_Controller.php:922-932`); Pro independently stamps store metadata.

**Main comparison:**
- Same create-only date behavior and update cash stripping: `M:includes/API/V1/Orders_Controller.php:450-459,481-505`.
- **Different provenance behavior:** main’s audit lists contain neither `_wcpos_sale_time` nor the other new provenance keys (`M:includes/Services/Pos_Order_Audit.php:28-36`). They remain ordinary forwarded metadata, rather than protected provenance. Claiming identical provenance stripping on main would be **WRONG**.

### 2. V1 never stamps `_pos_payment_asserted`; ADR path 3 is v2-only — HOLDS

V1 strips client-supplied assertions through `Pos_Order_Audit`, but its create/update contexts and before-save callback never generate the marker (`N:includes/API/V1/Orders_Controller.php:437-442,514-520,907-932`; `N:includes/Services/Pos_Order_Audit.php:29,84-85,114-126`).

V2 generates `offline` on asserted create and eligible update (`N:includes/API/V2/Writers/Order_Writer.php:82-85,472-473`). Existing tests explicitly distinguish asserted payment from an already gateway-paid order (`N:tests/includes/Sync/Test_Rest_Dispatch_Till_Meta.php:165-201`).

ADR 0035 establishes a **v2** contract before describing the assertion marker (`N:docs/adr/0035-hook-parity-contract.md:7-10,27-29`). It does not require retrofitting v1.

Main likewise has no v1 marker stamp (`M:includes/API/V1/Orders_Controller.php:497-505,890-918`).

### 3. A combined restart-and-checkout push takes the restart date — HOLDS, subject to existing guards

The writer checks the loaded order’s status, paid date, ledger and forward-moving timestamp—not `requested_status()` (`N:includes/API/V2/Writers/Order_Writer.php:197-205,337-342`).

WooCommerce deliberately postpones requested status changes until after pre-insert and total calculation (`WC:includes/rest-api/Controllers/Version2/class-wc-rest-orders-v2-controller.php:727-729,778,819-835`). Consequently, an eligible stored `pos-open` order can accept the restart date before that same request closes it.

`Order_Write_Intent::requested_status()` exists for consumers needing requested rather than persisted state; its existence does not make this gate wrong (`N:includes/Services/Order_Write_Intent.php:13-17,129-131`).

Main has no restart machinery: its update preparation and lifecycle simply forward without a restart callback (`M:includes/API/V2/Writers/Order_Writer.php:84-98,258-275`).

### 4. The zero-total explanation for avoiding `needs_payment()` — WRONG

The issue’s suspicion is confirmed:

1. WooCommerce initially computes false for total zero, then applies `woocommerce_order_needs_payment` (`WC:includes/class-wc-order.php:1804-1813`).
2. Free registers its callback at priority 10 (`N:includes/Orders.php:72`).
3. That callback explicitly returns **true** for zero-total `pos-open` and `pos-partial` orders (`:124-130`).
4. An existing test already asserts this result (`N:tests/includes/Test_Orders.php:1225-1233`).

The same override exists on main (`M:includes/Orders.php:72,120-126`).

Therefore `writer:187-188` gives the wrong justification. Keep the predicate: `needs_payment()` does not express “specifically open, no paid date, no ledger rows.” Correct the explanation, not the payment behavior.

### The three missing pins — HOLDS

The restart suite checks date preservation but not receipt-time preservation (`N:tests/includes/Sync/Test_Rest_Dispatch_Cart_Restart.php:131-142`). Existing v1 audit tests cover cashier forgery and cash-row renaming, not the new provenance tuple (`N:tests/includes/API/Test_Orders_Controller.php:1819,2025-2074`).

All three requested tests are worthwhile **characterization tests**, expected to pass on next:

- **Restart alone:** extend `Test_Rest_Dispatch_Cart_Restart`; create an old, unpaid `pos-open` order with no ledger rows; POST an update envelope to `/wcpos/v2/push/orders` with a later date and no changed provenance. Assert 200, new creation date, and `_wcpos_sale_received_gmt` still absent or exactly its previous value. Cover both initial receipt-time states. This would catch removal of the `a41ea525` guard (`writer:345-355`).
- **V1 date ignored:** in `Tests\API\Test_Orders_Controller`, create an order with a known date; PATCH `/wcpos/v1/orders/{id}` using JSON with a later date, then separate provider cases for malformed/future dates. Assert 200 and unchanged stored/response date. No new validation should occur.
- **V1 provenance stripped:** in that same class, use valid provenance values, one already stored and another absent; PATCH changed/new values plus a harmless custom key. Assert 200, stored provenance unchanged, absent provenance still absent, and custom metadata persisted. Include cash preservation without invoking a gateway.

Run equivalent v1 cases in `Test_HPOS_Orders_Controller`; restart cases already inherit into `Test_Rest_Dispatch_Cart_Restart_HPOS`.

No runtime defect requiring a failing-next test was established. The provenance characterization would instead be expected to **fail on main**, where those keys are not protected. Do not backport it as an unchanged-contract test.

## 2. Impact

**Severity: Low for this issue as scoped; underlying order/payment area remains High stakes.** The verified defect is misleading documentation plus missing contract pins—not demonstrated lost money, corrupted orders or a broken shipped checkout.

**Important correction to the review’s client assumptions:**
- The supplied current client writes through v2: namespace construction at `C:packages/core/src/screens/auth/hooks/use-api-discovery.ts:368-376`; mutation endpoint at `C:packages/sync-core/src/recordPushAdapter.ts:30-43`.
- No production v1 order writer was found in supplied `apps/` and `packages/`. V1 order references found there were test/probe code. This does **not** prove older released 1.x clients abandoned v1; the brief explicitly says they use it.
- **No existing-cart restart producer was found in this client tip.** New-order creation sets `date_created_gmt` (`C:packages/core/src/screens/main/pos/hooks/use-add-item-to-order.ts:144,173-184`), but adding to an existing order patches only cart lines (`:283-294`).
- No production producer of `_pos_payment_asserted` or the `_wcpos_sale_*` tuple was found. Identity preparation adds cashier/store/tax basis, not sale provenance (`C:packages/core/src/screens/main/pos/hooks/utils.ts:93-108`).
- Checkout pushes the resident payload unchanged, then opens payment UI (`C:packages/core/src/screens/main/contexts/use-push-document.ts:60-77`; `C:packages/core/src/screens/main/pos/cart/buttons/pay.tsx:54-74`). Native checkout separately posts to `orders/{id}/checkout` (`C:packages/core/src/screens/main/pos/checkout/hooks/use-checkout-session.ts:232-237`); the webview uses the payment link (`checkout/checkout.tsx:95-99,143`).

Thus the supplied client does not substantiate a shipped combined restart/`set_paid` flow or newly generated sale provenance. It may echo metadata already received from the server.

**Who and how often:**
- **Developers:** encounter misleading guidance whenever modifying the shared write contract.
- **Cashiers/merchants:** no demonstrated current failure. Future restart clients or custom integrations would observe creation-date changes in order headers/report grouping (`C:packages/core/src/screens/main/orders/view/sections/header.tsx:28`; `reports/chart/utils.ts:101-102`).
- **Operators:** a receipt-time regression could create misleading clock-skew diagnostics; the service compares sale and receipt timestamps (`N:includes/Services/Provenance_Health.php:209-229`).
- **Attackers:** no new exploit established. Retaining audit-key and metadata-ID stripping preserves existing protections.

Frequency and installed-client distribution cannot be established from disk.

## 3. Cost and risk

**Smallest useful PR: approximately 30 changed non-test lines, all documentation/comments; roughly 120–180 test lines.**

Files:
- `includes/Sync/Order_Write_Payload.php`: record the lane matrix.
- `includes/API/V2/Writers/Order_Writer.php`: correct restart explanation.
- `docs/adr/0035-hook-parity-contract.md`: explicitly qualify the assertion marker as v2.
- Existing restart and v1 controller test files; reuse existing storage fixtures.
- `CONTEXT.md` need not change: it already requires lane-specific rulings on the module (`N:CONTEXT.md:45-46`).

**Tests:** the three characterizations above, one combined restart/checkout request, and a small v1 create/update marker-absence pin. Retain existing priority, payment-race, lock-contention and paid-order tests. Execute later through Docker/wp-env only; none were run here.

**Pro coupling:** Pro requires Free `dev-next` (`P:composer.json:25`) and subclasses Free’s v1 controller (`P:includes/API/V1/Orders_Controller.php:13,23`). Its dispatch hook stamps `_pos_store` independently (`:43-55,67-70`). No direct references to the writer, intent or payload classes were found in Pro’s own `includes/` or `tests/`. Documentation-only changes need no Pro implementation.

**Back-compat:** no public methods, filter priorities, REST shapes or persistence rules need change. Moving the restart function or introducing a shared policy abstraction would be over-scoped.

**Lane:** all proposed work belongs on **next**. Main shares date-ignore and cash-protection behavior, but lacks the restart and protected sale-provenance contract. No released-user defect justifying a main patch was demonstrated.

## 4. Options

### Ruling 1 — V1 update date/provenance/cash

**A. Record current behavior and pin it — favoured.**
- Pros: preserves released v1 update tolerance and existing audit protections; matches the already intentional partial/full-document split.
- Cons: v1 cannot complete newly introduced provenance on an existing order or request a restart.

**B. Give v1 the v2 rules.**
- Pros: permits those capabilities through legacy integrations.
- Cons: introduces date-validation failures, date movement and new metadata writes into a legacy update contract; requires adapting v2’s late rechecks rather than copying a few assignments.

**Evidence favours A.** No supplied client requires B. This is primarily recording the current contract, not a necessary product change. Do not justify it with the unverified assertion “all 1.x tills never restart.”

### Ruling 2 — V1 payment assertion marker

**A. Keep v1 unstamped; explicitly scope ADR path 3 to v2 — favoured.**
- Pros: matches implementation and ADR scope; avoids treating absent historical markers as proof of gateway payment.
- Cons: consumers cannot distinguish v1 asserted payments by this marker.

**B. Add server-generated markers to v1 create and eligible update.**
- Pros: richer attribution for actual v1 `set_paid` callers.
- Cons: changes metadata consumers can observe and needs create/update/replay/gateway-paid cases; does not repair historical attribution.

**Evidence favours A now.** Reconsider B when an identified v1 integration or audit consumer needs it. Never accept the client’s marker directly.

### Ruling 3 — Combined restart and checkout

**A. Keep persisted-state eligibility; accept the restart date — favoured.**
- Pros: a combined request has the same date outcome as restart followed by checkout; existing paid-date, ledger and lock guards remain authoritative.
- Cons: requested terminal status does not itself suppress date movement.

**B. Require requested status to remain `pos-open`.**
- Pros: separates restart from checkout requests.
- Cons: makes outcome depend on whether edits reach the server separately or together; forces a client sequencing requirement absent from the current contract.

**Evidence favours A.** Record and pin existing behavior. Proposed pin: old empty `pos-open` order, later timestamp, product line and checkout intent in one v2 update; assert accepted date and resulting status/payment behavior separately. The current client does not exercise this combination.

### Ruling 4 — `needs_payment()` explanation

**A. Correct the docblock, retain explicit eligibility — favoured.**
- Pros: accurate and zero behavioral risk; existing zero-total test supports it.
- Cons: none material.

**B. Replace eligibility with `needs_payment()`.**
- Pros: shorter-looking predicate.
- Cons: it admits statuses such as `pos-partial` and does not check ledger rows or paid dates; it is not equivalent.

**Not a product call:** A is a factual documentation correction.

## 5. Recommendation

**PURSUE NOW**, narrowly as documentation and characterization tests—not a High-severity runtime fix.

The decisive evidence is that the implementations already express coherent lane-specific contracts, the zero-total explanation is demonstrably wrong, and the proposed v1 parity work has no identified client requirement. The review’s “rulings first” need not become a redesign.

**PR 1 on next:** record the existing v1/v2 date, provenance, cash and assertion-marker boundaries; correct the restart docblock; pin the three missing behaviors and combined checkout eligibility **(~30 non-test lines, tests: existing restart suite plus v1 controller cases on posts and HPOS).**

**Behavior changes / regressions:** none proposed. Existing next-versus-main differences must remain explicit: next protects the new provenance tuple and supports v2 restart dates; main does neither. No compatibility or test-pass claim is made from this read-only inspection.

## 6. Open questions

- **Where is the intended restart/provenance-producing client implementation?** Best guess: companion work not merged into the supplied client main. Its branch/commit or packaged payload capture would settle reachability; current main source does not.
- **Do deployed 1.x or third-party v1 callers require asserted-payment attribution or provenance completion on update?** Best guess: not enough demand to alter the legacy contract. An identified integration and representative requests would settle it.
- **Do the proposed characterization tests pass under real REST dispatch on both storage backends?** Source tracing predicts yes on next; the provenance-stripping test predicts a different result on main. Targeted Docker/wp-env execution must confirm this before merging.