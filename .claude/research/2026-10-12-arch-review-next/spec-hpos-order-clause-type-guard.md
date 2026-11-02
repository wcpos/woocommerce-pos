# Spec — HPOS order clauses apply only to `shop_order` queries (D4)

## Goal
On HPOS, a `wcpos/v2/orders` read that claims `search`, `created_via` or an id set returns each order with `refunds: []`. This change fixes that: the scoped `woocommerce_orders_table_query_clauses` binding in `Collection_Rules_Plan::install()` gets the same query-type guard that the legacy `posts_where` binding already has.

## Stakes
- **Tier:** Medium. This is a read lane with no writes, but the symptom shows up in order responses. A refunded order that reaches the till without its refunds can look unrefunded.
- **What failure costs:** order browsing and search rows that are wrong or missing.
- **Accepted risk:** any other non-`shop_order` orders-table query that runs inside the forward, such as a third party's own `wc_get_orders` for a different type. After this change it simply stops inheriting POS clauses, which is the intended effect.

## Out of scope
Do not add any of the following:
- locking, retries, caching, new filters, feature flags, env vars, options or tables;
- changes to `filter()`, `apply_hpos_filters()`, `apply_read_rule()` or any clause body;
- changes to the legacy `posts_where` / `posts_orderby` bindings;
- changes to `wcpos/v1` (a separate spec converts v1 to `around()`; it inherits this guard).

## Exact change
**File:** `includes/Sync/Collection_Rules_Plan.php`
**Location:** method `install()`, the HPOS branch, which today reads:

```php
			$clauses_callback = function ( $clauses, $query = null, $args = array() ) {
				$clauses = $this->filter( self::HOOK_HPOS_FILTERS, $clauses, $query );

				return $this->filter( self::HOOK_HPOS_ORDERBY, $clauses, $query, $args );
			};
```
(on next this is at about `:492-496`)

Change the closure so that it returns `$clauses` unchanged when the query is not for `shop_order`. The test reads the type from the third filter argument (`OrdersTableQuery` passes its remapped args, so `post_type` has already become `type`):

```php
			$clauses_callback = function ( $clauses, $query = null, $args = array() ) {
				// Response preparation runs inside the forward, and `get_refunds()` is its own
				// orders-table query: order filters, search and id sets must not narrow it.
				// Mirrors the `shop_order` guard on the legacy `posts_where` binding below.
				$type = \is_array( $args ) ? ( $args['type'] ?? null ) : null;
				if ( null !== $type && ! \in_array( 'shop_order', (array) $type, true ) ) {
					return $clauses;
				}
				$clauses = $this->filter( self::HOOK_HPOS_FILTERS, $clauses, $query );

				return $this->filter( self::HOOK_HPOS_ORDERBY, $clauses, $query, $args );
			};
```

Also edit the class docblock paragraph "`around()` is the scoped read path…" (about `:34-36`). Add one sentence: "Both storages' bindings apply only to `shop_order` queries, so a refund lookup inside the forward is never narrowed." Nothing else changes.

## Rulings to preserve verbatim
- HPOS sort ownership and the WooCommerce-mapped `total` guard, pinned by `tests/includes/Sync/Test_Collection_Rules_Guard_HPOS.php`.
- The clause goldens, pinned by `Test_Collection_Rules_Clauses.php`; they call `filter()` directly and are untouched.
- v1↔v2 sort and filter parity, pinned by `Test_Collection_Rules_Parity_HPOS.php` (trait `Collection_Rules_Parity_Tests`).
- Every existing order-search case in `Catalog_Proxy_Order_Search_Tests`, on both `Test_Catalog_Proxy_Orders` and `Test_Catalog_Proxy_Orders_HPOS`.
- `Test_Collection_Rules_Leak`: the bindings still install and unwind exactly as before.

## Tests (failing-first; the orchestrator checks the red run)
Add two tests to the trait `tests/includes/API/V2/Catalog_Proxy_Order_Search_Tests.php`, so they run on both storages through `Test_Catalog_Proxy_Orders` and `Test_Catalog_Proxy_Orders_HPOS`. Follow these conventions:
- Arrange / Act / Assert;
- `$this->wp_rest_get_request( '/wcpos/v2/orders' )`;
- `assertSame( expected, actual )`.

1. **`test_order_search_keeps_the_matched_orders_refunds`**
   - Arrange:
     - Use `$this->target_order` from the existing fixtures. Billing last name is `QuillonProbe`.
     - Create a refund: `wc_create_refund( array( 'order_id' => $this->target_order->get_id(), 'amount' => 1, 'reason' => 'Refund probe' ) )`, then `assertNotWPError`.
     - Call `wp_cache_flush()`. WooCommerce caches `get_refunds()` per order, and a warm cache would hide the bug.
   - Act: dispatch `/wcpos/v2/orders` with `search=QuillonProbe`.
   - Assert:
     - one row, with `id` equal to the target id;
     - `wp_list_pluck( $row['refunds'], 'id' )` is `assertSame( array( $refund->get_id() ), … )`.
2. **`test_created_via_filter_keeps_the_matched_orders_refunds`**
   - Arrange:
     - On the target order: `set_created_via( 'wcpos-refund-probe' )`, then `save()`.
     - Create the same refund, then call `wp_cache_flush()`.
   - Act: dispatch with `created_via=wcpos-refund-probe`.
   - Assert: one row, the target; its `refunds` ids are `array( $refund->get_id() )`.

**Expected before the fix:** both tests fail in `Test_Catalog_Proxy_Orders_HPOS` (the refunds are `[]`) and pass in `Test_Catalog_Proxy_Orders`. Codex cannot run PHPUnit, so write the tests and the guard together. Before committing, the orchestrator runs the two new tests against a tree without the `includes/` change. If they pass on HPOS without it, the D4 trace in `verdict-sync.md` is wrong: keep the tests as pins and drop the guard.

Delete no tests.

## Budget
- **Non-test:** ≤ 15 lines changed in `includes/` (one file).
- **Total diff:** ≤ 120 lines, including tests and the lint budget.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification Codex can run (no Docker)
- `php -l includes/Sync/Collection_Rules_Plan.php tests/includes/API/V2/Catalog_Proxy_Order_Search_Tests.php`
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Sync/Collection_Rules_Plan.php tests/includes/API/V2/Catalog_Proxy_Order_Search_Tests.php`

Afterwards the orchestrator runs these in wp-env, one at a time:
- `Test_Catalog_Proxy_Orders_HPOS`
- `Test_Catalog_Proxy_Orders`
- `Test_Collection_Rules_Parity_HPOS`
- `Test_Collection_Rules_Guard_HPOS`
- `Test_Collection_Rules_Leak`
- `Test_Catalog_Proxy_Refunds`

## Fixed lines
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.
