# Spec — `wcpos/v1` order, product and variation reads install Collection Rules only through `around()`

## Goal
Delete the `wcpos/v1` controllers' persistent `add_filter( …, array( $this, … ) )` query topology. Each v1 collection read then installs its Collection Rules only through `Collection_Rules_Plan::around()`, which is scoped to the read and unwound in a `finally`. This fixes the v1 leak where an id-set read leaves `posts_where` / `woocommerce_orders_table_query_clauses` callbacks installed for the rest of the request, which empties each order's `refunds` (verdict D2).

## Stakes
- **Tier:** Medium. These are read lanes; no write path is touched.
- **What failure costs:** v1 order, product or variation lists that are wrong or mis-sorted for released 1.x clients and mini-apps.
- **Accepted risk:** a query that WooCommerce or a third party runs *inside* the read, for the same post type, still sees the scoped clauses. This is identical to the v2 proxy lane today.

**Lands after** `spec-hpos-order-clause-type-guard.md`. That spec's `shop_order` guard in the HPOS binding is what keeps v1's HPOS refunds intact once v1 runs through `around()`. If that guard is not in the tree, STOP and report.

## Out of scope
Do not do any of the following:
- add `search` (or `created_via`) to the v1 orders narrowing map; a ruling records why below;
- change any clause body (`apply_*` methods), `filter()`'s signature or visibility, or `Bulk_ID_Fast_Path`;
- delete the `@deprecated` product search callbacks (`wcpos_posts_search`, `wcpos_posts_clauses`, `wcpos_posts_join_to_products_search`, `wcpos_posts_groupby_product_search`, `wcpos_posts_where_product_exclude_online_only`, `wcpos_posts_where_product_variation_exclude_online_only`), even though they are unhooked;
- add new filters, options, env vars, feature flags, retries, or plan files;
- touch Pro.

## Facts this spec relies on (verified on next `837e985f`)
- **Pro is clean.** Pro's v1 subclasses override only `wcpos_dispatch_request`, `update_item` and their own `wcpos_pro_*` callbacks. Grepping Pro's `includes/` and `tests/` for every method deleted below gives zero hits.
- **No Free test references the deleted methods by name.** I grepped `tests/` for each name.
- **`Collection_Rules_Parity_Tests` already pins v1 = v2-proxy** for every order sort, both directions, both storages, and the `pos_cashier`/`pos_store` filters. The proxy runs through `around()`, so this matrix is the regression gate for the order conversion.

## Exact changes

### 1. `includes/API/V1/Orders_Controller.php`
- **`wcpos_dispatch_request()`:** delete the line `add_filter( 'woocommerce_rest_shop_order_object_query', array( $this, 'wcpos_shop_order_query' ), 10, 2 );`.
- **Delete these methods entirely**, with their docblocks:
  - `wcpos_shop_order_query`
  - `wcpos_posts_where_order_include_exclude`
  - `wcpos_hpos_orders_table_query_clauses`
  - `wcpos_hpos_orderby_query`
  - `wcpos_legacy_order_status_orderby`
  - the `prepare_objects_query` override (WooCommerce's parent then runs unmodified)
- **Add `get_items()`**, mirroring `V1/Products_Controller::get_items()`:
  ```php
  /**
   * Apply the collection's declared rules for the duration of a direct read.
   *
   * @param WP_REST_Request $request Full details about the request.
   * @return WP_Error|WP_REST_Response
   */
  public function get_items( $request ) {
  	return $this->wcpos_collection_plan( $request )->around(
  		function () use ( $request ) {
  			return parent::get_items( $request );
  		}
  	);
  }
  ```
- **`wcpos_collection_plan()`:**
  - Make `$request` a required `WP_REST_Request` and drop the `$this->wcpos_request` fallback.
  - Its docblock currently says "this lane keeps its own `add_filter` topology (Pro subclasses these callbacks)". Replace that with: "The direct lane's plan; `get_items()` runs the read inside `around()`."
- **`WCPOS_COLLECTION_PARAM_MAP` docblock:** after the `created_via` sentence, add this ruling:
  > `search` is deliberately absent too: `wcpos/v1` order search has always been WooCommerce's native search (no POS search was ever wired on this lane, here or on `main`); POS order search is a `wcpos/v2` feature. Claiming it here would be a wire change on a frozen lane, and would also need WooCommerce's own `s` handling stripped from the request.
- **Imports:** remove `use WP_Query;` only if nothing else in the file references `WP_Query` (phpcs will flag it if it is unused). Keep `Collection_Rules_Plan` (it is the return type).

### 2. `includes/API/V1/Products_Controller.php`
- **`WCPOS_SORT_PARAM_MAP`:** add `'include' => 'wcpos_include', 'exclude' => 'wcpos_exclude'`. Update its docblock to "Request keys the product Collection Rules plan reads on this lane (sort, search, and the `wcpos_include`/`wcpos_exclude` id sets)."
- **`wcpos_dispatch_request()`:** delete `add_filter( 'woocommerce_rest_product_object_query', array( $this, 'wcpos_product_query' ), 10, 2 );`.
- **Delete** `wcpos_product_query` and `wcpos_posts_where_product_include_exclude`, with their docblocks.

### 3. `includes/API/V1/Product_Variations_Controller.php`
Make the same three changes:
- add the map rows;
- delete the `add_filter( 'woocommerce_rest_product_variation_object_query', … 'wcpos_product_variation_query' … )` line;
- delete `wcpos_product_variation_query` and `wcpos_posts_where_product_variation_include_exclude`.

### 4. `includes/Sync/Collection_Rules.php`
- **Add a private static helper**, placed beside `catalog_meta_sorts()`:
  ```php
  /**
   * The catalog collections' raw id sets — `wcpos/v1`'s `wcpos_include` / `wcpos_exclude`.
   *
   * Claimed only where a lane's narrowing map exposes them (today: the v1 product and
   * variation controllers), and applied by the plan's guarded, scoped `posts_where`
   * binding instead of a request-long callback. The default id-set parse reproduces
   * v1's historic `array_map( 'intval', (array) $value )` cast verbatim.
   *
   * @return array<string, array>
   */
  private static function catalog_id_sets(): array {
  	return array(
  		'include' => array( 'id_set' => array( 'operator' => 'IN' ) ),
  		'exclude' => array( 'id_set' => array( 'operator' => 'NOT IN' ) ),
  	);
  }
  ```
- In `rules()`, add `'filters' => self::catalog_id_sets(),` to the `products` row and to the `variations` row.
- **Class docblock "# Surface" bullet for `filter()`** (about `:32-34`). Replace it with: "`Collection_Rules_Plan::filter()` — the clause-body dispatcher. Type-preserving; it NEVER touches global filter state. `around()`'s bindings and non-installing argument transforms call it; nothing hooks it directly."

### 5. `includes/Sync/Collection_Rules_Plan.php`
Comments only; no code changes beyond what the HPOS-guard spec already did.
- **Class docblock "# Two application modes"** (about `:29-32`). Replace the `filter()` paragraph with: "`filter()` applies one clause body and installs nothing; `around()` is the only path that installs anything." Remove "(Pro subclasses them)", which is false.
- **The comment above the `posts_where` binding in `install()`** (about `:511-517`). Replace "`wcpos/v1` leaves its callback installed … (frozen behaviour…)" with: "`posts_where` fires for EVERY WP_Query, so the binding is scoped to this read AND guarded to `shop_order`: no unrelated query inside the read — a refund lookup included — picks up an order id set or search."

### 6. `CONTEXT.md`
At the end of the **Read Lane** paragraph (before `_Avoid_`), append: "Every Read Lane installs its Collection Rules only through the plan's scoped install (`around()`), so nothing a read adds outlives the read."

## Rulings to preserve verbatim
- **Every v1↔v2 order sort/filter parity case:** `Collection_Rules_Parity_Tests` via `Test_Collection_Rules_Parity` and `Test_Collection_Rules_Parity_HPOS`.
- **HPOS sort ownership:** `Test_Collection_Rules_Guard_HPOS`.
- **Clause goldens:** `Test_Collection_Rules_Clauses`, `Test_Collection_Rules_Plan`, `Test_Collection_Rules_Install_Topology`.
- **v1 id-set parse is the frozen `intval` cast**, so a comma-joined string collapses to its first id: `Collection_Rules_Plan::normalize()`, unchanged.
- **v1 orders search stays WooCommerce-native** (the ruling above). The existing v1 search cases in `Test_Orders_Controller` / `Test_HPOS_Orders_Controller` must stay green unchanged.
- **Product/variation search and visibility parity:** `Test_Collection_Rules_Search_Parity`.
- **Bulk id fast paths are untouched:** `Bulk_ID_Fast_Path` users (`wcpos_get_all_posts`).

## Tests
Conventions:
- `WCPOS_REST_Unit_Test_Case` helpers (`$this->wp_rest_get_request()`);
- Arrange/Act/Assert;
- `test_[feature]_[scenario]_[expected]`;
- `assertSame( expected, actual )`.

The lane-coverage ratchet rejects NEW v1-only cases. Every case below dispatches BOTH lanes inside its own body, or lives in a class whose helpers already dispatch both. Do not add a test file that touches only `wcpos/v1`.

**A. `tests/includes/Sync/Collection_Rules_Parity_Tests.php`** (the trait runs on both storages)
1. **Replace** `test_proxy_lane_is_not_contaminated_by_direct_lane_hooks`. Its `'/wcpos/v1/orders' => true` row asserts the very callback this change deletes. In its place add **`test_order_reads_on_both_lanes_leave_no_query_callbacks_installed`**:
   - Arrange:
     - `$this->create_sort_fixtures()`;
     - a private helper `query_hook_counts(): array` returning hook => callback count for `posts_where`, `posts_orderby`, `posts_clauses`, `woocommerce_orders_table_query_clauses`, `woocommerce_rest_shop_order_object_query` (count across all priorities, as `Test_Collection_Rules_Leak::hook_signature()` does).
   - Act: dispatch with `lane_order_ids()`, not the isolating helper, so nothing is restored:
     - `/wcpos/v1/orders` with `wcpos_include` = the three fixture ids, `orderby=status`, `order=asc`;
     - then `/wcpos/v2/orders` with `orderby=status`, `order=asc`, `pos_cashier=<any int>`.
   - Assert: `assertSame( $before, $this->query_hook_counts() )` after each dispatch.
2. **Add `test_id_set_read_keeps_each_orders_refunds_on_both_lanes`**
   - Arrange:
     - create one order with `OrderHelper::create_order()`;
     - set billing last name `RefundLaneProbe`, call `scrub_numeric_address_fields()`, save;
     - `$refund = wc_create_refund( array( 'order_id' => $id, 'amount' => 1, 'reason' => 'Refund lane probe' ) )`, then `assertNotWPError`;
     - `wp_cache_flush()` (WooCommerce caches `get_refunds()` per order; a warm cache hides the bug).
   - Act:
     - dispatch `/wcpos/v1/orders` with `wcpos_include => array( $id )`;
     - `wp_cache_flush()`;
     - dispatch `/wcpos/v2/orders` with `include => (string) $id, search => 'RefundLaneProbe'`.
     
     Add a private `lane_rows( string $route, array $query ): array` helper returning `get_data()` after asserting 200. Have `lane_order_ids()` use it.
   - Assert: for each lane, exactly one row whose `id` is `$id`, and `assertSame( array( $refund->get_id() ), array_map( 'intval', wp_list_pluck( $row['refunds'], 'id' ) ) )`.
   - Expected before this change: the v1 half fails on BOTH storages.

**B. `tests/includes/Sync/Test_Collection_Rules_Search_Parity.php`** (each case dispatches v1 and v2 in its body)

3. **`test_product_id_set_reads_match_and_leave_no_query_callbacks`**
   - Arrange:
     - three simple products a, b, c (`ProductHelper::create_simple_product()`);
     - snapshot counts for `posts_where`, `posts_clauses`, `posts_search`, `posts_join`, `posts_groupby`, `woocommerce_rest_product_object_query`.
   - Act:
     - `/wcpos/v1/products` with `wcpos_include => array( a, b ), wcpos_exclude => array( b )`;
     - `/wcpos/v2/products` with `include => "a,b", exclude => "b"`.
   - Assert: both return `array( a )`, and the counts are unchanged after each dispatch.
4. **`test_variation_id_set_reads_match_and_leave_no_query_callbacks`**
   - Arrange:
     - `ProductHelper::create_variation_product()`;
     - pick two child ids, v1 and v2;
     - snapshot the same hook counts, plus `woocommerce_rest_product_variation_object_query`.
   - Act:
     - `/wcpos/v1/products/variations` with `wcpos_include => array( v1, v2 ), wcpos_exclude => array( v2 )`;
     - `/wcpos/v2/variations` with `include => "v1,v2", exclude => "v2"`.
   - Assert: both return `array( v1 )`, and the counts are unchanged.

If the v2 variations route rejects `exclude`, drop it from both lanes in this case and record the choice.

Delete no other test.

## Budget
- **Non-test:** net ≤ −100 lines across `includes/` (deletions dominate: about 210 deleted, ≤ 80 added including docblocks).
- **Total diff:** ≤ 650 lines, including tests and the lint budget.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification Codex can run (no Docker)
- `php -l` on each changed PHP file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/API/V1/Orders_Controller.php includes/API/V1/Products_Controller.php includes/API/V1/Product_Variations_Controller.php includes/Sync/Collection_Rules.php includes/Sync/Collection_Rules_Plan.php tests/includes/Sync/Collection_Rules_Parity_Tests.php tests/includes/Sync/Test_Collection_Rules_Search_Parity.php`
- `grep -rn "wcpos_shop_order_query\|wcpos_posts_where_order_include_exclude\|wcpos_hpos_orders_table_query_clauses\|wcpos_hpos_orderby_query\|wcpos_legacy_order_status_orderby\|wcpos_product_query\|wcpos_posts_where_product_include_exclude\|wcpos_product_variation_query\|wcpos_posts_where_product_variation_include_exclude" includes tests` must print nothing.

Afterwards the orchestrator runs these in wp-env, ONE file per call:
- `Test_Collection_Rules_Parity`
- `Test_Collection_Rules_Parity_HPOS`
- `Test_Collection_Rules_Guard_HPOS`
- `Test_Collection_Rules_Leak`
- `Test_Collection_Rules_Search_Parity`
- `Test_Collection_Rules_Plan`
- `Test_Collection_Rules_Clauses`
- `Test_Orders_Controller`
- `Test_HPOS_Orders_Controller`
- `Test_Products_Controller`
- `Test_Product_Variations_Controller`
- `Test_Hook_Isolation`
- `Test_Catalog_Proxy_Orders_HPOS`

Then it runs the lane-coverage script: `php scripts/lane-coverage.php`.

## Fixed lines
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
