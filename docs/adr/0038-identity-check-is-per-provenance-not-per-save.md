---
status: accepted
---

# The identity check is per-provenance, not per-save

Two plugin-owned queries scaled with a merchant's data (free#1805), measured on the dev
stores on 2026-08-30 with MariaDB's slow log at 50 ms:

- **Every product/variation save scanned every uuid row in `wp_postmeta`.** The
  before-save hook re-ran the uuid ownership check (`SELECT COUNT(*) FROM wp_postmeta …
  WHERE meta_key = '_woocommerce_pos_uuid' AND meta_value = ?`) on every save, to catch a
  clone that copied the meta. WordPress indexes `meta_key` but never `meta_value`, so the
  planner walks every uuid row: 30,251 rows and 0.46 s per save on a 30k-product store,
  114 times an hour — one per product save, and a stock change on a sale is a save.
- **The integrity scan digested a whole collection to read `MAX(id)`.** For customers,
  orders and the published product scope, `bucket_aggregates()` wrapped the un-windowed
  per-row digest SELECT (MD5 of every row plus a `GROUP_CONCAT` of its meta, materialised
  into an on-disk temp table) as a derived table just to take its max: 207,336 rows and
  0.41 s for 6,879 customers; 945,358 rows and 3.1 s per call for 30k products — once per
  scan page, per device, per walk.

## Decisions

**1. A uuid the record loaded from its own row is trusted on the save and read paths.**
The ownership detector runs only for a uuid whose provenance is NOT this record's own
persisted meta row: a cloned object (WooCommerce's "Duplicate" clones the meta with its
ids cleared), an importer rewriting the value in memory, a record with no id yet, a record
returning from trash/auto-draft through WC CRUD (a pending status change). A record was
invisible to the detector while trashed, so another record may have adopted its uuid — and a
*native* restore (wp-admin's Restore, `wp_untrash_post()`, `WC_Order::untrash()`) persists
the status before any WC object save, so no save or read ever sees the transition. The
untrash hooks (`untrashed_post`; `woocommerce_untrash_order` for HPOS, armed on the first
live save because it fires before the restore) therefore re-prove ownership once per
restore and re-key the RESTORED record, never the one the tills already key on. An ordinary save
or read carries a `WC_Meta_Data` entry with an id and no changes, and skips the detector
entirely. The rule is `Pos_Uuid::is_own_persisted_uuid()`, applied inside `ensure_uuid()`
behind the opt-in `trust_persisted` option so there is one selection of the canonical
entry. Callers that pass it: `stamp_on_save` (the write hook) and `stamp_serialized_record`
(the v2 per-object product lane and the order pull lane — on the legacy CPT order store the
order detector is a full postmeta walk per served order). Callers that must NOT pass it,
because they exist to re-key a loaded duplicate: `Uuid_Backfill_Controller`
(`mode=collisions`) and `Proxy_Uuid_Stamper` (in-response duplicates). A trust rule inside
`ensure_uuid` by default would silently delete both repairs.

The V1 list lanes (`Uuid_Handler::maybe_add_post_uuid`, `ensure_user_uuid`) also keep the
detector, for a different reason: their contract that no two records in one response share
a uuid (`Test_Products_Controller::test_uuid_is_unique`,
`Test_Customers_Controller::test_customer_uuid_is_unique`) rests on the per-record detector
alone — V1 has no bulk-read in-response check the way v2's proxy stamper does. That is one
scan per served record on V1 list pages. Owner's ruling (2026-08-30): V1 performance is not
a target — a slower legacy API is acceptable and is a reason for merchants to move to the
current app. Do not spend effort here.

**2. Hookless copies are the collision backfill's job, not the save path's.** A uuid copied
by direct SQL or a migration tool now passes rule 1 on both records, so neither record's
next save re-keys it. That is deliberate. The per-save scan caught such a copy only when
one of the two records next saved, and re-keyed *whichever that was* — the original as
readily as the copy, which is the one outcome the never-re-key contract (ADR 0008 G1)
forbids. `/uuid/backfill?mode=collisions` walks the store once in bounded pages and
re-keys the later copy, never the owner; it is the correct repair and it was always the
cross-response repair. A re-key, wherever it happens, is now logged with the record, the
uuid it lost and the one it received.

**3. No core table is altered, ever.** An index on `wp_postmeta.meta_value` would make the
scan cheap and was ruled out even as an opt-in: `ALTER TABLE` on a merchant's postmeta is a
multi-minute, table-locking operation on large stores, runs under whatever privileges and
timeouts the host allows, can fail half-way, and is exactly the "plugin touched my
database" report this plugin must never generate. The by-uuid lookups that remain on the
legacy CPT order store — the write path's `resolve_id_by_uuid()` (the born-twice check on
a POS order create, and product creates from the till) and V1's order-create lookup —
keep the postmeta shape; the only O(1) answer for them is a plugin-owned uuid→id table
with a completeness latch and a batched backfill, which is a schema addition and a
separate decision. Their read-path twin (the order pull lane's detector) is covered by
decision 1. HPOS stores are unaffected — `wc_orders_meta` carries a `(meta_key,
meta_value)` index by WooCommerce's own design.

**4. The scan's completion id comes off the base table.** `max_id` is `MAX(id)` over
`wp_users`, the orders table, or `wp_posts` under the collection's own servable predicate —
the same predicate the windowed sides apply, on the post row instead of a digested row —
joined with the stored side's max exactly as before. The completion point does not move:
a trashed order or a draft product past the last live row still does not extend the walk,
and an orphaned stored digest past the last live id still does.

## Quantified

Rows examined (MariaDB `Handler_read%` deltas), pinned by budget tests that run at two
fixture sizes so that one ceiling cannot be met by a query that scales:

| operation | fixture | before | after | gate |
|---|---|---|---|---|
| before-save hook on a loaded product | 256 products | 258 | 0 | `Test_Product_Save_Query_Budget` |
| before-save hook on a loaded product | 1,024 products | 1,026 | 0 | `…_Large` |
| one customer scan page, 1-id window | 256 users | 2,331 | 7 | `Test_Integrity_Scan_Max_Id_Query_Budget` |
| one customer scan page, 1-id window | 1,024 users | 9,243 | 7 | `…_Large` |
| one order scan page (CPT), 1-id window | 256 orders | 2,638 | 265 | same |
| one order scan page (CPT), 1-id window | 1,024 orders | 9,550 | 1,033 | `…_Large` |
| one published-product scan page, 1-id window | 256 products + 256 variations | 7,760 | 1,033 | same |
| one published-product scan page, 1-id window | 1,024 + 1,024 | 30,032 | 4,105 | `…_Large` |

The completion queries for orders and products remain one index pass over the live rows
of that type — there is no index ending on `ID` under a status predicate — so their
budgets are expressed per live row (measured 1.0 and 2.0 after, 9.3–15 before; the ceiling
is 3); the customer budget is absolute because `MAX(ID)` off the users primary key reads
no row. Behavioural cases in `Test_Pos_Uuid` pin each provenance that still triggers the
scan (clone, duplicator end to end, rewritten value, unsaved record), the one that does not
(hookless copy → backfill), and the re-key log line. `Test_Digest_Index` pins the completion
id against orphans, drafts, trashed CPT and HPOS orders, and asserts the query no longer
contains a digest expression.

Not fixed here, by decision 3: the write-path by-uuid lookups on the legacy CPT order
store (`resolve_id_by_uuid` on a POS create; V1's order-create `get_order_ids_by_uuid`).
The read-path share of the 0.46 s × 8 order lookups in the capture is covered by decision
1. HPOS stores pay neither.

## Consequences

- Product save cost is flat in catalog size for the common case. The scan still runs for
  every provenance that can actually carry a copied uuid, and the tests enumerate them.
- A store that has been hookless-cloned in the past will keep both copies until the
  collision backfill runs. Before this decision it kept them until one happened to save,
  and then possibly re-keyed the wrong one.
- The stores this does NOT fix are named above (item 3) with the decision that would.
