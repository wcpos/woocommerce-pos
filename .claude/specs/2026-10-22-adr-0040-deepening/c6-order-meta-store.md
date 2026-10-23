# c6 — one order-meta store owns every HPOS-or-posts decision

ADR 0040 deepening, candidate c6. Reads only; writes still go through the order object, `Ledger::write()` and `save_meta()`.

## Friction

Before this change, 22 places in 19 `includes/` files each decided "HPOS or posts?" for themselves. They used four detection idioms (`class_exists( OrderUtil::class ) && …`, `method_exists` + `call_user_func` on the class name string, `Collection_Rules::detect_storage( 'orders' )`, and a private `hpos()` / `orders_are_hpos()` / `is_hpos_enabled()`), and then each hand-picked `wc_orders_meta` vs `postmeta`, `order_id` vs `post_id`, `id` vs `meta_id`, and `status/type/date_updated_gmt` vs `post_status/post_type/post_modified_gmt`. Seven of them wrote the same query twice, once per storage.

## Interface (`includes/Services/Order_Meta_Store.php`)

- `hpos(): bool`: whether orders live in the HPOS tables. This is the only `custom_orders_table_usage_is_enabled()` call left in `includes/`. It is public because seven callers branch on storage for something other than a table name: a `wc_get_orders()` `field_query` vs a `posts_where` filter, the CPT-only date touch, an analytics report field, the Collection Rules dialect, Digest_Index's column-vs-meta digest, the HPOS-only operational-data count, and the reserved-stock query that is handed to WooCommerce's own filter. A table name cannot express any of those.
- `schema(): array`: the identifiers of the active order store: `orders`, `id`, `type`, `status`, `created`, `modified`, `meta`, `owner`, `meta_id`. It is for the bespoke joins that `order_ids()` does not cover, which can then write one query instead of two.
- `order_ids( string $key, array $match = array() ): ?int[]`: the ids of the records holding `$key`, matched as `$match` says. The match options are a closed set: `value`, `value_ci`, `contains`, `exact_key`, `type`, `exclude`, `order` (`modified` = least recently modified first; `newest` = highest id first) and `limit`. It returns `null` when the read fails, so each caller keeps its own failure behaviour: three throw, three treat the failure as "none".

`order_ids()` picks the query shape. An `order => modified` lookup is driven from the orders table with an `EXISTS` on the meta table, because `DISTINCT` cannot sort on an unselected column. Every other lookup is driven from the meta table, joining the orders table only when `type` asks for it. Values always go through `$wpdb->prepare`, and identifiers come only from the module's own constants.

## Sites

| # | Site | Asks | After |
|---|------|------|-------|
| 1 | `Payment_Index::order_id_for_payment` | exact key + value → one shop_order, trash included, auto-draft excluded | `order_ids` |
| 2 | `Payment_Index::order_ids_with_live_legs` | live shop_orders holding the live-leg key, oldest-modified first, capped | `order_ids` |
| 3 | `Payment_Index::hpos` | detection | deleted |
| 4 | `Pos_Uuid::get_order_ids_by_uuid` | key + value → at most two live shop_orders, unordered (#1725) | `order_ids` |
| 5 | `Session_Figures::legs` | key + value → any record, newest first, throws | `order_ids` |
| 6 | `Session_Figures::refund_records` | key + case-folded value → refunds, newest first, throws | `order_ids` |
| 7 | `Completion_History::orders_in_session` | key + contains → any record, throws | `order_ids` |
| 8 | `Completion_History::orders_standing_without_a_record` | self-join over the meta table and the fiscal record table | `schema` |
| 9 | `Fiscal_Record_Writers::orders_owed_a_sale` | parked-document scan | `schema` |
| 10 | `Ledger::stored` | the ledger row with its meta id, locking read optional | `schema` |
| 11 | `Tax_Id_Detector::count_populated_keys` | per-key populated counts over given ids | `schema` |
| 12 | `Provenance_Health::provenance_rows` | recent POS orders joined to their provenance meta | `schema` |
| 13 | `Uuid_Backfill_Controller::select_ids_order` | missing / colliding uuid scan, written once per storage | `schema`, one query per mode |
| 14 | `Order_Query::ids_after_modified_checkpoint` | compound-key page over the orders table | `schema`, one query |
| 15 | `WCPOS_REST_API::wcpos_preflight_meta_count` | meta row count for one order | `schema` |
| 16 | `WCPOS_REST_API::wcpos_get_essential_meta` | named meta rows for one order | `schema` |
| 17 | V1 `Orders_Controller` (constructor, `wcpos_dispatch_request`, `wcpos_get_all_posts`) | detection ×3; the bulk-id SQL keeps its two branches because the CPT branch orders by local `post_date` | `hpos` |
| 18 | `Sync_Journal` order backfill page | `field_query` vs `posts_where` | `hpos` |
| 19 | `Order_Modified_Date::touch` | CPT-only date touch | `hpos` |
| 20 | `Collection_Rules::detect_storage` | the dialect for the rules table | `hpos` |
| 21 | `Digest_Index::orders_are_hpos` | digest dialect | deleted; `hpos` |
| 22 | `Analytics_Profile::is_hpos_enabled` | report field | deleted; `hpos` |
| 23 | `Landing_Profile::get_pos_order_count` | operational-data count vs meta count | `hpos` |
| 24 | `Stock_Validator::reserved_stock_query` | WooCommerce's reserved-stock query, filtered by core's hook | `hpos` |

### Left as they are, and why

- `Receipt_Snapshot_Cleanup` deletes from both meta tables on every site by design, and it is a write. It never asks which storage is active.
- `Order_Search::hpos_where` is the HPOS half of a dialect its caller has already chosen. Its caller passes the table names in, and the `wc_orders_meta` fallback is a name, not a decision.

## Tests

- `Test_Order_Meta_Store`, plus its `_HPOS` twin (`HPOSToggleTrait`), covers `schema()` per storage and every `order_ids()` option: matches, limits, both orderings, trash and auto-draft exclusion, type filtering, exact key against `_ci` variants, `contains`, case-folded values, empty results, and the `null` on a failed read.
- The pin added before the move: `Test_Payment_Index::test_order_ids_with_live_legs_*` (and its HPOS twin) pins the sweep's selection: live only, oldest-modified first, the cap, and nothing below a cap of 1. It ran green on the old code and runs green on the new.
- Every moved caller's existing tests pass untouched. The one re-expression: the isolated-process fixture `tests/fixtures/order-proxy-without-order-util.php` requires the new file by hand, because it loads `Collection_Rules` without an autoloader.
