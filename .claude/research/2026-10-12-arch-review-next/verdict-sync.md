# Verdict — sync (Free `837e985f` = origin/next, Pro `05f06c4`)

`git rev-list --left-right --count origin/main...origin/next` = `0 458`, so `next` is live. Defects D2 and D4 (below) also exist on `origin/main`: `Collection_Rules_Plan.php` differs between the trunks by one line (`:572`), and v1 `Orders_Controller` has the same `add_filter` topology there (main `:932-934`, `:1097`, `:1108`). Per CLAUDE.md, fixes for the released version target `main`. The orchestrator picks the lane; the specs name methods first and line numbers second, so they apply on either trunk.

WooCommerce line refs are from WC 10.4.3 (`~/.wp-env/wp-env-arch-runner-next-0522a9ca/woocommerce`). Pro grep, used for every section: `grep -rn --include='*.php' <name> /Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next/{includes,tests}`.

---

## 1. Search has one declaration but five executors — VERDICT: DO-RESHAPED (a small dedup, not a module)
Strength (explorer): Strong · Risk tier: Medium (search)

**Why**
- **The five executors are five dialects, not drift.** They are HPOS orders (`Sync/Order_Search.php:43-80`), posts orders (`:88-110`), `wp_users` (`Customer_Search.php:30-86`), product `posts_search` (`Product_Search.php:23`), and variation `meta_query`/`wp_terms`. One executor cannot serve them all.
- **The explorer overstated the customers bypass.** Customers bypassing the Plan is a recorded ruling: "SORT NAMES ONLY — deliberately no clause bodies … needs a third storage dialect" (`Collection_Rules.php:424-438`).
- **The term-cap split is ruled, not drift.** "Product phrases collapse over-cap terms; orders slice; v2 variations reject" (`Collection_Rules.php:84-89`). The ruling just sits in a loose docblock.
- **`carriers` is read by nothing in `includes/`**, but commit 29199c8a calls it "the human-readable list a test pins". I am leaving it alone.
- **The real duplicate is the phone-fragment predicate**, `'' !== $digits && 0 === preg_match('/\p{L}/u', $term)`, copied at `Order_Search.php:61-62`, `:101-102` and `Customer_Search.php:62-63`. The client applies the same rule, so this is a real drift risk.
- **The posts-orders phone keys are hard-coded** at `Order_Search.php:103`, while customers read the declared `phone_meta`. Moving them into the declaration would touch `normalize_search_declaration` (`Collection_Rules.php:490`). I am deferring that; it is not worth it tonight.

**Scope:** one predicate, `Order_Search::phone_digits( string $term ): string`, replacing three call sites. About +8 net lines; total diff ≤ 60 including lint.
**Pro impact:** none. `Order_Search`, `Customer_Search` and `Product_Search` have no hits in Pro.
**Tests:**
- Pinned today by `Catalog_Proxy_Order_Search_Tests::test_order_search_matches_phone_digits` (both storages) and `Test_Customers_Lane_Parity` / `Customers_Lane_Parity_Tests`.
- Add `Test_Collection_Rules_Order_Search::test_phone_digits_*`, which tests the pure function.
- Delete nothing.

**ADR / CONTEXT.md:** no conflict. No new term.
**Sequencing:** independent of everything else. Fits in one PR, combined with item 5's part 1 (see `spec-sync-small-dedups.md`).
**Tonight? YES.** Medium tier, a pure-function extraction, and both storages' parity suites already pin the behaviour. The value is low; run it last.

## 2. Collection Rules have two installation modes, and the v1 one leaks — VERDICT: DO-RESHAPED
Strength (explorer): Strong · Risk tier: Medium (read lanes; the effect it fixes lands in order responses)

**Why**
- **"Pro subclasses these callbacks" is false; I checked it myself.** Pro's v1 controllers override only these methods:
  - `wcpos_dispatch_request`, `wcpos_pro_order_object_save` (Pro `includes/API/V1/Orders_Controller.php:43,67`)
  - `update_item`, plus prepare/price callbacks (Pro `Products_Controller.php:64-291`, `Product_Variations_Controller.php:63-188`)
  
  I grepped 21 names across Pro's `includes/` and `tests/` and got **zero hits**:
  - `wcpos_shop_order_query`, `wcpos_posts_where_order_include_exclude`, `wcpos_hpos_orders_table_query_clauses`, `wcpos_hpos_orderby_query`, `wcpos_legacy_order_status_orderby`
  - `prepare_objects_query`, `wcpos_collection_plan`
  - `wcpos_product_query`, `wcpos_posts_where_product_include_exclude`, `wcpos_product_variation_query`, `wcpos_posts_where_product_variation_include_exclude`
  - `wcpos_posts_clauses`, `wcpos_posts_search`, `wcpos_posts_join_to_products_search`, `wcpos_posts_groupby_product_search`, `wcpos_posts_where_product_exclude_online_only`
  - `WCPOS_COLLECTION_PARAM_MAP`, `WCPOS_SORT_PARAM_MAP`, `Collection_Rules`, `wcpos_include`, `wcpos_exclude`
  
  Pro's git history also has no `posts_where` and no `wcpos_shop_order_query` (`git log -S`).
- **Deleting `filter()` is not safe, and it is not the leak.** `filter()` installs nothing; it is the clause-body dispatcher.
  - `around()`'s own closures call it (`Collection_Rules_Plan.php:453,460,480,482,493,495,505,528`).
  - Non-installing transforms call it too (`V1/Product_Variations_Controller.php:490`, `V2/Variations_Controller.php:177`).
  - 26 clause-golden test calls go through it (`Test_Collection_Rules_Clauses.php` ×23, `Test_Collection_Rules_Plan.php` ×3).
  
  **The leak is the v1 controllers' own `add_filter($this, …)` with no removal.** That is in v1 orders (`V1/Orders_Controller.php:260,950,952,1122,1133`), v1 products (`V1/Products_Controller.php:98,433`), and v1 variations, which the explorer missed (`V1/Product_Variations_Controller.php:84,339`).
- **The reshaped candidate is: v1 order, product and variation reads install only through `around()`.**
  - v1 products and variations already wrap `get_items` in `around()` (`V1/Products_Controller.php:117-125`, `V1/Product_Variations_Controller.php:103-111`). Only their `wcpos_include`/`wcpos_exclude` side-channel stays outside it.
  - v1 orders gets the same wrapper.
  - The product and variation id sets become `id_set` rows. Their normaliser already reproduces v1's `array_map('intval',(array)$v)` verbatim (`Collection_Rules_Plan.php:674-694`).
- **Evidence that `around()` reproduces v1's order SQL:** `Collection_Rules_Parity_Tests` already holds v1 equal to the v2 proxy (which runs through `around()`) for every sort, in both directions, on both storages, plus the `pos_cashier`/`pos_store` filters (`:42-176`).
- Once v1 converts, `filter()` stays public, and its class docblock (`Collection_Rules_Plan.php:29-32`) and the "frozen behaviour" note (`:511-516`) are corrected.

**Scope (`wc -l` on next):**
- v1 Orders (1228 lines) deletes `:260` and `:938-958`, `:980-1003`, `:1068-1138` (about 117 lines) and adds `get_items` (about 14).
- v1 Products (724) deletes `:98`, `:422-438`, `:637-665` (47).
- v1 Variations deletes `:84`, `:327-344`, `:363-390` (47).
- `Collection_Rules` gains one shared id-set row helper (about +22).
- Net non-test is about **−160**. The total diff, including tests and lint, is ≤ 650.

**Pro impact:** none (grep above). Pro's v1 subclasses inherit the new `get_items`.

**Tests:**
- Pinned today:
  - `Collection_Rules_Parity_Tests` through `Test_Collection_Rules_Parity(_HPOS)`
  - `Test_Collection_Rules_Leak`, `Test_Collection_Rules_Install_Topology`, `Test_Collection_Rules_Guard_HPOS`
  - `Test_Collection_Rules_Search_Parity`, `Test_HPOS_Orders_Controller`, `Test_Orders_Controller`
- Add (detail in the spec):
  - `test_order_reads_on_both_lanes_leave_no_query_callbacks_installed`
  - `test_id_set_read_keeps_each_orders_refunds_on_both_lanes`
  - `test_product_id_set_reads_match_and_leave_no_query_callbacks`
  - `test_variation_id_set_reads_match_and_leave_no_query_callbacks`
- Replace `test_proxy_lane_is_not_contaminated_by_direct_lane_hooks` (`Collection_Rules_Parity_Tests.php:182-222`). Its `'/wcpos/v1/orders' => true` row asserts the persistent callback that this change removes.

**ADR / CONTEXT.md:** no conflict. Sharpen the CONTEXT.md entry for **Read Lane**:
> **Read Lane**: a namespace's path from a collection request to WooCommerce's query. Every Read Lane installs its Collection Rules only through `Collection_Rules_Plan::around()`, so nothing a read adds survives the read. _Avoid_: v1 topology, legacy callbacks.

**Sequencing:** land D4 first (the HPOS guard), so the converted v1 lane inherits it. This item fixes D2 and records C1. One PR.
**Tonight? YES.** Medium tier, Pro proven clean, and an existing parity matrix pins the SQL. Spec: `spec-v1-reads-install-through-around.md`.

## 3. Client-asserted order state is decided in three places — VERDICT: DO-RESHAPED (rulings first)
Strength (explorer): Strong · Risk tier: **High** (orders)

**Why**
- **The restart is already one pure function**, `Order_Writer::cart_restart_gmt` (`Order_Writer.php:197-206`). It is called twice on purpose: at the pre-read (`:462`) and again at pre-insert under `Order_Lock` (`:340`), as its docblock says (`:190-192`). "Decided twice" is design, not drift.
- **The three `set_date_created` sites are each pinned:**
  - The v1 public callback at priority 10 is inherited by Pro and pinned by `Test_Order_Write_Parity::test_client_date_public_callback_registered_at_original_priority`.
  - v2 create goes through the intent at priority 1 (`Order_Write_Intent.php:47,72-74`).
  - The restart applies at pre-insert (`Order_Writer.php:342`).
  
  Merging them is a behaviour change.
- **What is actually missing is the rulings.** `Order_Write_Payload.php:34-37` lists only coupon, omitted-item and tax-ID lane splits. Restart, the client date on v1 update, and provenance are absent (grep "restart" in `includes/Sync`: empty).
- **Some behaviours have no pin.** a41ea525 (a restart alone writes no `_wcpos_sale_received_gmt`) has no test. Neither do v1 update ignoring `date_created_gmt`, nor v1 update stripping provenance and cash keys without filling them (`V1/Orders_Controller.php:499-507`).
- **The `cart_restart_gmt` docblock may be wrong.** It says `needs_payment()` "is the wrong gate: an emptied cart totals 0" (`Order_Writer.php:187-188`). But Free's `order_needs_payment` returns true for a 0-total `pos-open` order (`Orders.php:123-126`). The predicate may still be right (pre-insert totals are stale); the stated reason is doubtful.

**Scope:** the first slice is docs plus tests:
- the rulings block on `Order_Write_Payload`, about +25;
- correcting `Pos_Order_Audit.php:9-10`;
- one pin test for a41ea525.

Optional: move `cart_restart_gmt` onto `Order_Write_Payload` and leave a `@deprecated` delegate (about ±15).

**Pro impact:** none of `Order_Write_Payload`, `Order_Write_Intent`, `Order_Writer` or `cart_restart_gmt` appears in Pro. Pro's v1 `Orders_Controller` extends Free's and stamps `_pos_store` on every save (Pro `:53,67-70`).

**Tests:**
- Existing: `Test_Rest_Dispatch_Cart_Restart` (13 cases) and its `_HPOS` variant; `Test_Order_Write_Parity`; `Test_Write_Controller` (provenance and date cases); `Test_Orders_Controller` (v1 audit).
- Add `Test_Rest_Dispatch_Cart_Restart::test_a_restart_alone_leaves_the_sale_received_time_unchanged`.

**ADR / CONTEXT.md:** no conflict. The rulings belong on the module, per CONTEXT.md's **Write Payload shape**: "a rule that exists in one shape only is a ruling (recorded on the module), not a drift". Draft text is in the appendix.
**Sequencing:** Paul rules first (see Needs Paul). Separate from 2 and 4.
**Tonight? NO.** High tier, and three of the rulings are product decisions only Paul can make.

## 4. The Write_Controller↔Writer seam leaks collection knowledge — VERDICT: DEFER
Strength (explorer): Worth exploring · Risk tier: **High** (v2 push lane; the revision is the CAS key)

**Why**
- The interface already records the fix. `Collection_Writer_Interface.php:15-20` is `@internal`: "push those defaults into Null_Writer". All four writers already extend `Null_Writer`.
- **The explorer undercounted the callbacks.** There are six callable-taking methods, not four (`Write_Controller.php:383,405,466,503,563,736,763`). The `context_factory`-after-CAS lines are now `:497-501`.
- The collection forks are real and conflict with ADR 0037's "capabilities are registry data, never code forks":
  - `revision_for` (`:803-811`)
  - product augmentation (`:745-753`)
  - the post-type allowlist (`:439-450`)
- **A byte change in the revision is a 409 storm.** Pro recomputes the product revision byte for byte (Pro `tests/.../Test_Store_Pricing_V2.php:572`).

**Scope:** about 150-200 changed lines across about 9 files (new `Product_Writer`, a `revision()` writer method, `document()` without callables).
**Pro impact:** no implementer or subclass. Grepped `Write_Controller`, `Collection_Writer`, `Writers\\` and each writer class; the only hits are comments at `Store_Pricing.php:32,98`.
**Tests:**
- Five private methods are reached by reflection: `Test_Variation_Revision_Shape_Independence.php:24`, `Test_Sync_Hook_Isolation.php:372,384`, `Test_Rest_Dispatch_Write_Contract.php:738`, `Test_Write_Contract_Fixture.php:44,47`. They would move to public writer methods.
- `wcpos_check_permissions` (`:717-720`) is used only by `Test_Write_Controller.php:826-846`.

**ADR:** this is ADR 0037 work.
**Sequencing:** after 3, because `context_factory` ordering is tied to the cart-restart re-decision.
**Tonight? NO.** High tier, the CAS bytes must stay identical, and the Pro pricing suite must gate it.

## 5. Product id-space for integrity is defined outside the registry, with two scopes — VERDICT: DO-RESHAPED (split)
Strength (explorer): Worth exploring · Risk tier: Medium

**Why**
- **The two scopes are deliberate.** Commit 3cb1e785: "status=publish restricts BOTH scan aggregate sides… Parameterless scan is byte-for-byte unchanged". `Test_Sync_Read_Controllers.php:790` pins range-checksum membership to the unfiltered listing.
- **The constants are copied three times, not twice.**
  - `PRODUCT_POST_TYPES_SQL` is at `Changes_Controller.php:47`, `Digest_Index.php:101` and `Resolve_Controller.php:39`.
  - `EXCLUDED_POST_STATUSES_SQL` is at `Changes_Controller.php:48` and `Digest_Index.php:102`.
- **The registry contradicts the route.** It says `tax_rates` has no drill-down (`Collections.php:333-338`), yet `/changes/range-checksum?bucket=` serves one (`Changes_Controller.php:437-465`). ADR 0036 forbids unmodelled capability.
- **The fast path is undocumented.** It skips `woocommerce_rest_product_object_query` and serves publish-only rows to a request with no `status` (`Products_Proxy_Behavior.php:134-165`), but ADR 0035's exception list names only `/orders/pull` and `/resolve/barcode`.

**Scope:**
- Part 1: make Digest_Index's two constants `public` and delete the three copies. Net −3 lines; total diff ≤ 30.
- Part 2 is rulings: an ADR 0035 exception, and the hash lanes as registry data.

**Pro impact:** none. Grepped `Changes_Controller`, `Digest_Index`, `PRODUCT_POST_TYPES`, `range-checksum` and `Products_Proxy_Behavior`.
**Tests:** `Test_Sync_Read_Controllers`, `Test_Catalog_Proxy_Products_Fast_Path`, `Test_Changes_Unsupported_Collection`. Part 1 adds no tests; it is a pure constant move.
**Tonight? YES for part 1** (in `spec-sync-small-dedups.md`). **NO for part 2**, which needs Paul.

---

## D1 (C1). v1 order search does not use POS search — VERIFIED, NOT A DEFECT (a frozen-lane gap; record it as a ruling)
- **Trace:**
  1. The `search` key is absent from the narrowing map (`V1/Orders_Controller.php:72-80`).
  2. So `request_key()` returns null (`Collection_Rules_Plan.php:710-723`), and the plan never claims `search` (`:205-220`).
  3. WooCommerce then runs its own search: the CRUD controller sets `$args['s']` (WC `class-wc-rest-crud-controller.php:299`), and legacy storage runs `wc_order_search` (WC `class-wc-rest-orders-v2-controller.php:674-680`).
- **It was never a regression:**
  - `git log -S "Order_Search"` and `-S "'search'"` on `includes/API/V1/Orders_Controller.php` are both empty.
  - `origin/main` has no search wiring either.
  - POS order search was introduced for `wcpos/v2` only (e3030369: "for wcpos/v2 order search").
- **The `next` client does not call `wcpos/v1/orders`.** `tests/lane-coverage/README.md`: "`wcpos/v1` frozen. Exactly one route is still live: `wcpos/v1/products/variations`". Client grep confirms it (`monorepo-v2/packages/query/src/engine-adapter/collection-map.ts:132`).
- **It is not one row.** Adding `'search' => 'search'` alone does nothing:
  - the v1 lane installs the WHERE callbacks only when `claims_id_sets()` (`V1/Orders_Controller.php:947`);
  - WooCommerce's native `s` would still intersect the POS clause. On HPOS it searches its own way; on posts, `post__in` comes from `wc_order_search`.
  
  It needs item 2's `around()` first, then the search key stripped from the request before `parent::get_items`.
- **Fix:** none tonight. Record the ruling next to `created_via` in the map docblock (in the item 2 spec): "`search` is deliberately absent: wcpos/v1 order search has always been WooCommerce's native search; POS order search is a wcpos/v2 feature."
- **Severity:** none for the current client. For 1.x clients it is a long-standing behaviour.

## D2 (C2). The v1 `posts_where` / HPOS clause hooks stay installed for the whole request — LIKELY (live for orders, latent for products); NEEDS REPRO
- **Orders trace:**
  1. `GET /wcpos/v1/orders?wcpos_include[]=N` → `wcpos_shop_order_query` → `add_filter('posts_where'|'woocommerce_orders_table_query_clauses', …)` with no removal (`V1/Orders_Controller.php:947-953`).
  2. Response preparation runs `$order->get_refunds()` (WC `class-wc-rest-orders-v2-controller.php:436-438`) → `wc_get_orders(['type'=>'shop_order_refund','parent'=>N])` (WC `class-wc-order.php:2161-2167`).
     - On legacy storage, `prime_refund_caches_for_order` runs an unsuppressed `WP_Query` (WC `class-wc-order-data-store-cpt.php:1251-1257`, `:1159`). The unguarded callback appends `AND ID IN (N)` (`Collection_Rules_Plan.php:825-840`).
     - On HPOS, the refund query fires the clauses filter (WC `OrdersTableQuery.php:887`), and `apply_hpos_filters` appends `orders.id IN (N)` (`Collection_Rules_Plan.php:985-987`).
  3. Either way the refund rows are filtered out, so `refunds: []`. On legacy storage the empty array is also cached for the request (`class-wc-order-data-store-cpt.php:1273-1278`).
- **Products** (`V1/Products_Controller.php:433`) and **variations** (`V1/Product_Variations_Controller.php:339`) have the same shape. I found no victim query: WooCommerce's children reads use `get_posts`, which suppresses filters.
- **This has existed since f4914961 (2024-04-13)** and is present on `origin/main`.
- **Failing test:** `Collection_Rules_Parity_Tests::test_id_set_read_keeps_each_orders_refunds_on_both_lanes`.
  - Arrange: an order with one `wc_create_refund`, then `wp_cache_flush()`.
  - Act: dispatch `/wcpos/v1/orders` with `wcpos_include=[id]`, and `/wcpos/v2/orders` with `include=id&search=<billing last name>`.
  - Assert: each row's `refunds` has exactly the refund id.
- **Severity:** Medium. 1.x clients and mini-apps (`ALLOWED_REST_PREFIXES` includes `/wcpos/v1/`) show refunded orders without their refunds.
- **Fix:** belongs to item 2.

## D3. Checksum scope mismatch — NOT A DEFECT
- **Trace:**
  - range-checksum (`Changes_Controller.php:467-478`, `:506-514`) and revision-hash (`:368-379`) count live products. The explorer's `:456-466` and `:500-510` are the tax_rates branches.
  - The client calls range-checksum only for tax_rates (`hybridChangeSignal.ts:487`, `sweepTaxRates: false` at `:361`).
  - revision-hash is called only for ids flagged by the parameterless scan, which compares stored digests with current ones, not with the till.
  - The till-comparable lane sends `status=publish` on both sides (`Digest_Index.php:547`).
- The split is deliberate (3cb1e785) and pinned (`Test_Sync_Read_Controllers.php:790`). At most it causes bounded extra pulls; a rebuild runs after `DRIFT_REBUILD_THRESHOLD = 3` (`Integrity_Controller.php:53`).
- **Optional lock-in test** (it passes today): `Test_Sync_Read_Controllers::test_changes_lanes_count_drafts_while_publish_scope_excludes_them`.

## D4 (new). The HPOS `around()` closure applies order clauses to every orders-table query in the forward, refunds included — LIKELY; NEEDS REPRO
- **Trace:**
  1. `Catalog_Proxy_Controller.php:95` → `around()` wraps `rest_do_request`, so response preparation runs inside the scope.
  2. On HPOS, `install()` binds `$clauses_callback` with **no query-type guard** (`Collection_Rules_Plan.php:492-498`). The posts branch has one (`:518-526`).
  3. `get_refunds()` → `OrdersTableQuery` → `apply_read_rule` appends the order-search WHERE (`:565-575`), and `apply_hpos_filters` appends the `created_via` `hpos_data` subquery and the id sets (`:972-987`).
  4. Refund rows have no billing address and no `created_via` match, so `refunds: []`.
- **Which requests hit it:** `/wcpos/v2/orders?search=…` or `?created_via=…` on HPOS. Legacy storage is unaffected, because of the posts guard.
- **Failing tests** go in `Catalog_Proxy_Order_Search_Tests`, so they run on both storages:
  - `test_order_search_keeps_the_matched_orders_refunds`
  - `test_created_via_filter_keeps_the_matched_orders_refunds`
  
  Both fail on HPOS only.
- **Severity:** potentially High. If the client merges proxy search rows into its local orders, a refunded order shows as unrefunded. That is unverified client-side.
- **Fix:** about a 6-line guard in the closure (`spec-hpos-order-clause-type-guard.md`). **Tonight? YES**, failing-first.

---

## Appendix — draft rulings for `Order_Write_Payload` (item 3, for Paul)
> **Deliberately lane-specific (proposed 2026-10-12, pending Paul):**
> - Client creation date: `validate_client_created_gmt` is the only validator. A create on either lane stores the validated instant. v1 applies it in its public `woocommerce_rest_pre_insert_shop_order_object` callback at priority 10 (Pro inherits this). v2 applies it through `Order_Write_Intent` at priority 1. **v1 update ignores `date_created_gmt` and does not validate it**: 1.x tills never restart a cart, and validating would 400 on an echoed field.
> - Cart restart (v2 update only): `cart_restart_gmt( $order, $client_gmt )` writes the client instant only while the order is `pos-open`, has no `date_paid`, has no ledger rows, and the instant is later than the stored one. It is decided at the pre-read and re-decided at pre-insert under `Order_Lock`; a held lock drops the restart, never the push. A restart alone writes no provenance and no `_wcpos_sale_received_gmt` (a41ea525). The gate is the order's PERSISTED status at pre-insert, so a push that both restarts and checks out takes the restart date.
> - Provenance: till values are accepted at create on both lanes. On a v2 update they are filled while the stored order `needs_payment()` and frozen once it is paid; a differing value is refused with one order note and re-decided at pre-insert. **v1 update strips them and never fills them.**

---

## Recommended tonight order
1. `spec-hpos-order-clause-type-guard.md` (D4). Small, failing-first, on the current lane.
2. `spec-v1-reads-install-through-around.md` (item 2 + D2 + the C1 ruling). Run it after 1.
3. `spec-sync-small-dedups.md` (the item 1 phone predicate + item 5 part 1). Optional, lowest value.

## Needs Paul
- Should D4 and D2 ship as a `main` patch, given that both are released defects and `origin/main` has the same code?
- Item 3: is it deliberate that v1 update ignores the client `date_created_gmt` and strips without filling provenance and cash keys? Record it as a ruling, or give v1 the v2 rule?
- Item 3: should v1 stamp `_pos_payment_asserted`? Today it never does (ADR 0035 path 3 is v2-only).
- Item 3: should a push that both restarts a cart and checks out take the restart date? The gate reads the persisted status, not `Order_Write_Intent::requested_status()`.
- Item 5 part 2: add the products fast path to ADR 0035's exception list, and model the `/changes` hash lanes and the tax-rate drill-down as registry capability?
- Item 4: schedule it as daylight ADR 0037 work, gated on Pro's `Test_Store_Pricing_V2`?
