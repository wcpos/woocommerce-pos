# The v2 sync engine at 10,000 feet

Research for [#1732](https://github.com/wcpos/woocommerce-pos/issues/1732) (part of the
[#1731](https://github.com/wcpos/woocommerce-pos/issues/1731) trust-audit map).
Investigation only — no production code touched.

Ground truth: `main` at `866ab2f6` (1.10.2). Every file/line citation below is against that commit.

Sources read: `includes/Sync/*.php` (all 39), `includes/API/V2/**` (all controllers, `Proxy/`,
`Writers/`), `includes/API/V2/README.md`, `includes/Init.php` hook-wiring table, and the wiki pages
`architecture/client/change-signal.md`, `client/existence-audit-politeness.md`,
`client/reference-collections.md`, `plugin-free/v2-change-log-and-integrity.md`,
`plugin-free/v2-targeted-reads.md` for the client-side counterpart. ADRs 0003/0005/0006/0009/0014/
0015/0021/0029 are cited by docblock only — they live in the retired replication-lab repo, not here.

---

## 1. Lane map

The engine is **eight lanes**, not one pipeline. A record's journey through them is not uniform, and
which lanes a collection rides is the subject of §2.

### Lane 0 — Registration and the two-tier gate

`Sync\Api` (`includes/Sync/Api.php`) registers ten controllers into the `wcpos/v2` namespace, all
behind `Sync\Endpoint_Permissions`: a **client tier** (`access_woocommerce_pos` + the F13 health
gate) and an **admin-ops tier** (`manage_woocommerce` + the same gate) used by exactly three routes —
`/uuid/backfill`, `/orders/index/backfill`, `/integrity/rebuild` (`Api::ADMIN_OP_PATHS`, line 26).
The full v2 route list is 21 hand-registered routes plus 8 proxy routes projected from the registry.

`Sync\Response_Envelope` optionally wraps any successful WCPOS response as `{ data, _wcpos }` when
`?_wcpos_envelope=1` is present, mirroring `X-WP-Total` / `ETag` / pressure headers into the body for
proxies that strip headers. `/push/*`, `/ping`, 304s and 4xx/5xx are exempt
(`Response_Envelope.php:34-55`).

### Lane 1 — Proxy list reads (the greedy/window pull)

`API\V2\Catalog_Proxy_Controller` registers one route per registry row carrying a `proxy` group and
forwards it to its `wc/v3` counterpart via `rest_do_request`, preserving status and pagination
headers. Eight routes: `/products`, `/orders`, `/customers`, `/products/categories`,
`/products/brands`, `/products/tags`, `/coupons`, `/taxes`.

Per-resource variation is confined to a `Proxy\Proxy_Behavior` implementation with three phases —
`forwarded_params()`, `around()` (install hooks / run / unwind in a `finally`), `post_process()`.
`Proxy\Scoped_Proxy_Behavior` supplies the discipline; six concrete behaviors implement it
(`Products_`, `Orders_`, `Customers_`, `Coupons_`, `Terms_`, `Taxes_`), plus a `Null_` fallback.

The forwarded response then passes through one public filter,
`woocommerce_pos_sync_proxy_response`, which is where every WCPOS augmentation lands. Priorities are
**order-critical** and documented in `Init.php:57-75`:

| pri | who | what |
|---|---|---|
| 5 | `Meta_Normalizer` | normalize `meta_data` (also serves the order lane, so it keeps its own registrar) |
| 9 | `Revision::stamp_proxy_revisions` | top-level `_rxdb_revision` — the canonical content hash |
| 10 | `Proxy_Uuid_Stamper::stamp_proxy_generic` | re-inject `_woocommerce_pos_uuid` (wc/v3 strips it) |
| 10 | `Integrity_Digest::stamp_digests` | top-level `_rxdb_digest` |
| 10 | `Augmentation_Pipeline` projections | `Variable_Prices`, `Variable_Children`, `Product_Images` |

`Sync\Augmentation_Pipeline` is the single declaration point; the two public filter names
(`..._proxy_response` batch lane, `..._serialized_product` per-object lane) survive as *projections*
of it, so an augmentation is declared once and runs on both lanes at the priority it always did.

**Record flow:** client GETs `wcpos/v2/products?modified_after=…&page=2` → behavior claims/rewrites
params → `Store_Scope::stamp()` puts the till's store on the inner request →
`Store_Scope::in_v2_lane()` marks the dispatch → `rest_do_request('/wc/v3/products')` →
`woocommerce_pos_sync_proxy_response` runs the five-stage stamp → response returned with wc/v3's own
`X-WP-Total`.

Incrementality on this lane is **date-based**: the client polls `?modified_after=<cursor>`, which
`Catalog_Proxy_Controller` forwards untouched to wc/v3 (documented at
`Sync/Coupon_Modified_Date.php:13-31`). That is why `Coupon_Modified_Date` exists at all — WC's
coupon CPT store skips `wp_update_post()` on meta-only edits, so without an unconditional
`woocommerce_update_coupon` touch a changed coupon is invisible to this lane forever.

### Lane 2 — The change signal (`/changes/*` + the journal)

`Sync\Sync_Journal` is an append-only table (`sequence` BIGINT AUTO_INCREMENT, `object_type`,
`object_id`, `deleted`, `revision`, `modified_gmt`, `origin`, `created_gmt`) fed by **32 WordPress /
WooCommerce hooks** (`Sync_Journal.php:211-244`). `Sync\Visibility_Observer` appends tombstones when
a record leaves the POS-servable set; `Sync\Sync_Journal_Purge` compacts superseded rows and lossily
prunes expired tombstones, publishing a per-object-type **prune watermark** before deleting anything.

`API\V2\Changes_Controller` exposes five GETs:

- **`/changes/sequence-log`** — tier 1. Pages the journal from a cursor. Returns
  `{ collection, checkpoint:{since,head,horizon,epoch}, changes[], complete, meta,
  config_fingerprint }`. Strong `ETag` over `head + fingerprints + epoch + horizon`; a bodyless 304
  when `since === head` and `If-None-Match` matches (RFC 9110 matching, incl. weak/wildcard/lists).
  `head` and `horizon` are **stream-scoped** — orders and catalogue share one AUTO_INCREMENT space,
  so a global head would kill the idle 304 path for whichever lane is quiet
  (`Changes_Controller.php:182-189`, `Sync_Journal.php:30-41`).
- **`/changes/tick`** — the idle probe. Same validator, same checkpoint, `changes: []`, no page read.
- **`/changes/config-fingerprint`** — ADR 0006 representation-config signal, recomputed live on every
  call so it self-heals against hook-bypassing settings writes.
- **`/changes/revision-hash`** — tier 3 repair. Full filtered REST serialization per record, md5'd.
- **`/changes/range-checksum`** — tier 2. `MD5(GROUP_CONCAT(id|post_modified_gmt))` per id bucket.

**Record flow:** a product save fires `woocommerce_update_product` → `record_catalogue_object()`
inserts a row with `revision = date_modified` → the till's next `/changes/tick` sees a moved head →
it drains `/changes/sequence-log?collection=all` → each row is `{sequence, id, deleted, revision,
modified_gmt, collection}` → the client issues a targeted proxy pull for the changed ids.

Two fail-closed guards live in the drain loop: a row whose `object_type` is unknown to
`Collections::collection_for_object_type()` is dropped and logged rather than mislabelled
(`Changes_Controller.php:250-260`), and an *update* row for a POS-hidden catalogue id is dropped
while its *tombstone* is always kept (lines 225-242).

### Lane 3 — Digests and integrity

`Sync\Integrity_Digest` (write half) maintains a `(object_type, object_id) → 64-bit digest` table
from 21 hooks — a strict subset of the journal's 32, per the wiring golden
(`tests/includes/Test_Init_Hook_Wiring.php:35-39`); `Sync\Digest_Index` (read half) owns every query and the canonical digest SQL that
both halves must share byte-for-byte. Three id-spaces: `products` (holding `product` + `variation`),
`customers` (wp_users), `orders` (HPOS or CPT).

- **`GET /digests?include=…&collection=…`** — stored digests for a list of ids, no payload. Seeds the
  client's existence manifest for records resident *before* Leg 3 shipped.
- **`GET /integrity/scan`** — `BIT_XOR` of current raw-row digests vs stored hook-time digests, per
  id-range bucket, entirely in SQL. `?bucket=n` drills into one bucket.
- **`GET /integrity/bucket`** — the authoritative live `{id, digest, object_type}` for one bucket;
  the client set-differences its manifest against this to prune.
- **`POST /integrity/rebuild`** — admin tier; re-digests everything and re-stamps the formula
  fingerprint.

Self-heal: `maybe_schedule_stale_digest_rebuild()` counts consecutive `changed` drill-downs per
bucket and schedules a guarded rebuild at 3 — deliberately above the client's escalation threshold of
2, so a rebaseline can only fire after the client has already pulled and still sees drift
(`Integrity_Controller.php:435-460`).

### Lane 4 — Write / push

`API\V2\Write_Controller` is **one route for every collection**:
`POST /push/{collection}` with a JSON envelope
`{ mutationId, operation, collection, recordId, baseRevision, payload | force }`. It dispatches on
`operation`, never on the HTTP verb.

The pipeline, in order:

1. Content-Type gate (415), collection lookup against the registry write-map projection (400).
2. Envelope validation — unknown properties rejected, `mutationId`/`recordId` must be uuids,
   `payload` forbidden on delete, `force` allowed only on delete, and a payload uuid that disagrees
   with `recordId` is a 422 (never re-key).
3. `Header_Mirror::assert()` — `Idempotency-Key` / `If-Match` cross-check against the body; 422 on
   divergence.
4. Idempotent replay via `Sync\Mutation_Store`: an applied `mutationId` replays its canonical result;
   a reused id for a different envelope fingerprint is a 422; a `poison` row re-stamps identity.
5. Atomic `reserve()` of the `mutationId` before the non-idempotent forward, with TTL reclaim.
6. `acquire_record_lock(collection, recordId)` — serializes two *distinct* mutations on one record so
   the loser re-reads and gets a real 409 instead of a silent lost update.
7. Apply: `resolve_id_by_uuid` → capability pre-check → collection writer prepares → CAS on
   `baseRevision` (with the versioned grace comparer) → `rest_do_request` to `wc/v3` under a scoped
   `woocommerce_rest_check_permissions` grant → `persist_uuid` → `mark_applied` → `finalize`.
8. Ack: `{ document, currentRevision }`.

Per-collection variation is confined to `Writers\Collection_Writer_Resolver`, which returns
`Variation_Writer` (post_type override), `Order_Writer` (id_type `order`), `Customer_Writer`
(id_type `user`) or `Null_Writer` (everything else).

### Lane 5 — The order pull lane

`API\V2\Orders_Controller::pull_orders` (`GET /orders/pull`) is a *second, parallel* change-consumer
over the same journal table. It reads order rows by cursor (`Sync\Order_Query`), plans the page
(`Sync\Order_Pull_Planner` — checkpoint hold-back, latest-sequence coalescing, tombstone split, uuid
stop), serializes each order (`Sync\Order_Serializer`) and emits a document envelope
(`Sync\Order_Document::build`). Response:
`{ documents[], deletes[], checkpoint, hasMore, epoch, head, horizon }`.

`POST /orders/index/backfill` (admin tier) walks existing orders into the journal so a
sequence-zero pull is journal-authoritative; until it reports `complete`, `Order_Query` falls back to
a verified modified-date scan (`Order_Query.php:31-49`).

Orders also ride Lane 1 — `/orders` proxies wc/v3 for browser-window, targeted and query-total reads
(`Catalog_Proxy_Controller.php:104-107`). Per the client wiki, orders are **not** a hybrid
change-signal collection: their freshness comes from this cursor lane plus an `order-window-seed`
lane, not from `/changes/sequence-log`.

### Lane 6 — On-demand hydration

- **`GET /variations`** (`API\V2\Variations_Controller`, extends
  `WC_REST_Product_Variations_Controller`) — the flat cross-parent variation route. Three modes:
  `include=` hydration, `sku`/`search` discovery over barcode carriers, and a bare collection page
  (added because refusing it forced the client to keep one frozen `wcpos/v1` call for the variation
  census, `Variations_Controller.php:250-260`). Returns `{ documents[], meta }`.
- **`GET /resolve/barcode`** (`API\V2\Resolve_Controller`) — one round trip that answers product *or*
  variation for a scan. Discovery is raw SQL over ids only (ADR 0003), active-barcode-field first
  with hard-coded keys as fallback, filtered through
  `woocommerce_pos_sync_resolve_barcode_matches`; the winning id is hydrated through
  `Product_Serializer`. Always 200 — "not found" is a result, not an error.

Both hydrate through `Sync\Product_Serializer`, THE product assembly line: filtered WC REST
representation via `prepare_object_for_response` + `response_to_data`, then the
`woocommerce_pos_sync_serialized_product` filter. Since #1710 it routes variations to
`WC_REST_Product_Variations_Controller` and products to `WC_REST_Products_Controller`, with a
pre-WC-8.3 backfill for `name`/`parent_id`.

### Lane 7 — uuid identity and backfill

`_woocommerce_pos_uuid` (`Api::UUID_META_KEY`) is the client's primary key for every collection with
an `identity` group. Four id-spaces: post meta, user meta, term meta, HPOS order meta — each with its
own ownership detector, bulk reader and loader, all named as *method names* in the registry so the
rows stay pure data.

- **Read-time stamping**: `Sync\Proxy_Uuid_Stamper::stamp_proxy_generic` — one bulk meta read per
  page, re-inject into each payload; unstamped or in-response-colliding records are loaded and
  minted/re-keyed via `Pos_Uuid::ensure_uuid`. Orders use **payload mode** (`bulk_reader: null`):
  HPOS exposes the meta on reads, so the uuid comes off the served `meta_data` and a stamped, unique
  record passes through untouched.
- **Write-time**: `Mutation_Store::persist_uuid` / `resolve_id_by_uuid`, verified round-trip after
  every create.
- **`POST /uuid/backfill?collection=&mode=missing|collisions`** (admin tier) — paginated, idempotent
  scan by numeric id, dispatching on the registry's `backfill.kind`.

---

## 2. Per-collection uniformity matrix

Columns are the lanes plus the two cross-cutting contracts. `✔` conforms, `✘` diverges (how),
`—` not applicable (why).

Registry row source: `includes/Sync/Collections.php:62-273`.

| collection | L1 proxy list | L2 change signal | L2 tier2/3 repair | L3 digest | L4 push | L5 order pull | L6 hydration | L7 uuid | revision paradigm | envelope |
|---|---|---|---|---|---|---|---|---|---|---|
| **products** | ✔ `/products`, visibility + stable sort | ✔ `product` rows, `revision` = `date_modified` | ✔ the only fully covered collection | ✔ owns the `products` id-space (also holds `variation`) | ✔ `Null_Writer` → `/wc/v3/products` | — | ✔ `/resolve/barcode` | ✔ post meta, bulk reader | `Revision::compute` sha256, stamped `_rxdb_revision` | flat wc/v3 |
| **variations** | ✘ `proxy: null` — no list lane (**#1734**) | ✔ `variation` rows + a parent `product` row per write | ✘ folded into products' bucket walk; no standalone tier | ✘ `digest: null` — folded into products id-space (**#1734**) | ✔ `Variation_Writer` → nested `/products/{parent}/variations` | — | ✔ `/variations` (include / search / page) | ✘ `bulk_reader: null`, stamped via the serialized lane (**#1734**) | ✘ **`date_modified_gmt`**, not a hash; no `_rxdb_revision` (**#1734**) | ✘ `{id,parent_id,payload,_rxdb_digest}` (**#1734**) |
| **orders** | ✔ `/orders` for window/targeted/total | ✔ `order` rows, but consumed only by L5 | — no tier2/3 route accepts `orders` | ✔ owns `orders` id-space; ✘ no drill-down, no self-heal (**new, D3**) | ✔ `Order_Writer`, honours `force` | ✔ owns `/orders/pull` (**#1733**) | — | ✘ payload mode, `bulk_reader: null` (**#1733**) | ✘ `Order_Serializer::canonical_revision`, stored in the journal at hook time (**#1733**) | ✘ `{id:uuid,payload,sync{},local{}}` (**#1733**) |
| **customers** | ✔ `/customers`, heaviest behavior (search, roles, `modified_after`, 5 sorts) | ✔ `customer` rows, `revision` = `date_modified`, per-request dedup | ✘ **no route accepts `customers`** (D2) | ✔ owns `customers` id-space; ✘ no drill-down, no self-heal, no formula fingerprint (D3) | ✔ `Customer_Writer` (tax_ids + walk-in email) | — | — | ✔ user meta, bulk reader | ✔ `Revision::compute` | flat wc/v3 |
| **categories** | ✔ `/products/categories`, term-id tiebreak | ✔ `category` rows — but `revision` is **`''`** (D1) | ✘ no route accepts it (D2) | ✘ `digest: null` — **no tier-2 backstop at all** (D3) | ✔ `Null_Writer` | — | — | ✔ term meta, bulk reader | ✔ `Revision::compute` | flat wc/v3 |
| **tags** | ✔ same behavior class | ✔ `tag` rows, `revision` = `''` (D1) | ✘ no route accepts it (D2) | ✘ none (D3) | ✘ **`write: null`** — read-only, unlike its two siblings (D5) | — | — | ✔ term meta | ✔ (moot — no push) | flat wc/v3 |
| **brands** | ✔ same behavior class | ✔ `brand` rows, `revision` = `''` (D1) | ✘ no route accepts it (D2) | ✘ none (D3) | ✔ `Null_Writer` | — | — | ✔ term meta | ✔ `Revision::compute` | flat wc/v3 |
| **coupons** | ✔ `/coupons` + POS read grant + post-id tiebreak | ✔ `coupon` rows, `revision` = `''` (D1) | ✘ no route accepts it (D2) | ✘ none (D3) | ✔ `Null_Writer` | — | — | ✔ post meta, bulk reader | ✔ `Revision::compute` | flat wc/v3 |
| **tax_rates** | ✔ `/taxes` + include/exclude SQL narrowing | ✔ `tax_rate` rows, `revision` = `''` (D1) | ✔ **the only non-product collection with tier 2/3** | — no meta store to key a digest by | — `write: null`, principled (ADR 0009) | — | — | — `identity: null`, principled (ADR 0009) | — no write path; tier-3 hashes the raw row | flat wc/v3 |

Cross-cutting columns not in the table:

| collection | `Config_Fingerprint` | `Collection_Rules` | POS visibility | uuid backfill scan |
|---|---|---|---|---|
| products | ✔ barcode | ✘ none (behavior class instead) | ✔ `CATALOG` | `product` + `product_variation` |
| variations | ✔ barcode + `payload_contract: 2` | ✘ | ✔ `VARIATIONS` | same two post types (duplicate of products) |
| orders | ✘ `fingerprint: null` | ✔ 4 sorts + 5 filters, 2 storages | — | HPOS/CPT |
| customers | ✘ `fingerprint: null` | ✔ sort *names* only, bodiless | — | **every WP user** |
| categories/tags/brands | ✘ `fingerprint: null` | ✘ | — | per-taxonomy |
| coupons | ✘ `fingerprint: null` | ✘ | — | `shop_coupon` |
| tax_rates | ✔ `barcode: false` (membership only) | ✘ | — | `backfill: null` |

---

## 3. Divergence inventory

Divergences already owned by a ticket are marked; everything else is a **candidate new verdict
ticket**.

### Already ticketed

- **V1–V5 · variations** → [#1734](https://github.com/wcpos/woocommerce-pos/issues/1734).
  `proxy: null`, `digest: null`, `bulk_reader: null` (`Collections.php:91-113`); the
  `{id,parent_id,payload}` wrapper (`Variations_Controller.php:310-319`,
  `Variation_Writer::document`); the `date_modified_gmt` revision
  (`Write_Controller.php:958-985`). *Stated reasons exist for all five and are recorded in
  docblocks.* One consequence worth handing to #1734: because `Revision::register_proxy_stamps()`
  hooks only `woocommerce_pos_sync_proxy_response` (`Revision.php:43-45`), the serialized lane never
  emits `_rxdb_revision` — so the variations revision special case and its grace branch
  (`Write_Controller.php:470-479`) are *load-bearing*, not vestigial, and `revision_for()` carries a
  25-line comment (lines 960-980) about a silent lost-update class it already had to defend against.
- **O1–O4 · orders** → [#1733](https://github.com/wcpos/woocommerce-pos/issues/1733).
  Content-hash revision, own pull lane, own document envelope, payload-mode uuid identity.
  One correction for #1733's framing: server-side, `Order_Serializer::canonical_revision()` **is**
  `Revision::compute()` with an order-specific identity strip (`Order_Serializer.php:307-328`), and
  the docblock at lines 258-263 says the unification was deliberate (#423 step 1). The genuine order
  divergences are (a) the hash is **computed and stored inside the write hook** on every
  `woocommerce_update_order` (`Sync_Journal.php:469-497`) — the perf issue already in flight — and
  (b) the **four-recipe versioned grace list** (`Order_Serializer.php:268-304`) that no other
  collection needs.

### Candidate new verdict tickets

**D1 — The journal's `revision` column means three different things, and is empty for five of nine
collections.**
`Sync_Journal::record()` defaults `$revision = ''` (line 514). Coupons (312, 316), all three term
collections (345) and tax rates (368, 372, 376) never pass one. Products/variations/customers pass
`object_revision()` — a `date_modified` string (509-512). Orders pass a full canonical content hash
(469-497). The sequence-log envelope advertises `revision` on **every** row unconditionally
(`Changes_Controller.php:222`), so a client reading the unified `all` stream gets a date for 3
collections, a sha256 for orders, and an empty string for 5. Nothing in the code or the wiki states
this. *No docblock records a reason.* Risk: any client logic that treats `changes[].revision` as
comparable across the stream is silently wrong for the majority of rows.

**D2 — Tier 2 and tier 3 silently substitute products for any collection but `tax_rates`.**
`Changes_Controller::collection_for_request()` (lines 727-734): *"NB this intentionally collapses
everything except tax_rates to products."* So `GET /changes/revision-hash?collection=customers`
returns **product** rows labelled `collection: "products"`, HTTP 200. Same for
`/changes/range-checksum`. This is the *exact* bug class the registry docblock says the registry
exists to kill — "The default→products collapse … (a missing case pulls a PRODUCT with another
record's id) is the bug class `by_object_type()` exists to kill" (`Collections.php:54-57`) — and it
survives, by explicit comment, in a sibling file. There is no `repair` capability group in the
registry, so the fail-closed discipline never reaches these two routes. Contrast
`/integrity/scan` and `/integrity/bucket`, which 400 on an unsupported collection
(`Integrity_Controller.php:190-196, 253-259`), and `/digests`, which 200s with an explanatory note
(`Digests_Controller.php:92-100`) — **three different answers to the same question in one lane
family** (that inconsistency is D2b).

**D3 — Tier-2 integrity covers 3 of 9 collections, and the repair half covers 1 of 3.**
Only products, customers and orders carry a `digest` group. Consequences:
  - Coupons, categories, tags, brands and tax rates have **no hash backstop of any kind**. A
    hookless write to a coupon (importer, WP-CLI, direct SQL, a plugin using `$wpdb`) produces no
    journal row and no digest mismatch, so it is invisible to every till indefinitely. This is
    precisely the failure mode the digest lane was built for
    (`Integrity_Controller.php:437-447` records it happening live on dev-pro 2026-08-19 for
    products, 138 records).
  - Customers and orders have stored digests and a scan aggregate, but the **drill-down 400s** for
    anything but products (`Integrity_Controller.php:261-268`), so
    `maybe_schedule_stale_digest_rebuild()` — the drift self-heal — can never fire for them.
  - `maybe_schedule_digest_rebuild()` consults only `needs_product_rebuild()` and the *product*
    formula fingerprint (`Integrity_Controller.php:417-433`, `Digest_Index.php:814-822`). There is no
    fingerprint for `CUSTOMER_DIGESTED_META_KEYS` or `ORDER_DIGESTED_META_KEYS`, so **changing either
    constant would silently invalidate every stored digest in that id-space with no rebuild
    trigger** — a store-wide false "records need attention" that only a manual `/integrity/rebuild`
    clears.
  - `POST /integrity/rebuild` reports `'collection' => 'products'` (line 159) while
    `Integrity_Digest::rebuild()` actually rebuilds all three id-spaces and sums their totals
    (lines 568-628). The response label is wrong.

**D4 — Terms have no incremental read lane and no backstop, so a term edit has exactly one detection
path.**
WordPress terms carry no modified date, so the `?modified_after=` narrowing that drives incremental
catalogue replication on Lane 1 (documented at `Coupon_Modified_Date.php:13-31`) cannot apply to
`/products/categories`, `/tags` or `/brands`. Combined with D3 (no digest) and D1 (empty journal
revision), the **only** signal that a category changed is the journal row from
`created_term`/`edited_term`/`delete_term`/`*_term_meta`. Per the client wiki
(`architecture/client/reference-collections.md`), these are demand-on-open collections that refresh
whole; a store that has never opened the Categories screen generates zero requests, and a collection
that is legitimately empty server-side gets no change signal at all. The design may be defensible —
term data is small and reference-shaped — but nothing records that it was *decided*.

**D5 — `tags` is read-only; `categories` and `brands` are not.**
`Collections.php:225`: `'write' => null, // read-only: no client push path exists`. That is a
statement about the absence of a *consumer*, not a design property — unlike tax_rates, whose
read-only is grounded in ADR 0009 (no meta store, keyed by Woo id). All three term collections share
one proxy behavior, one loader, one detector, one backfill kind and one `Null_Writer`; the push route
would work today. The asymmetry is unexplained.

**D6 — The generic delete is an unconditional permanent delete, and the envelope's `force` flag is
accepted then ignored.**
`Write_Controller::validate_envelope()` accepts and type-checks `force` on a delete
(lines 345-351) and `apply_delete()` hands the whole envelope to the writer (line 623). But
`Null_Writer::delete()` hard-codes `true`:
```php
return $dispatch( $this->delete_request( $meta['route'] . '/' . $id, $id, true ) );
```
(`Null_Writer.php:52-53`). `Variation_Writer::delete()` does the same. Only `Order_Writer::delete()`
reads it (`Order_Writer.php:127`) and trashes when false. So a client pushing
`{operation:'delete', force:false}` for a **product, coupon, category or brand** gets a permanent
`wc/v3` delete and a `200` — trash is bypassed, and the merchant's Woo trash-based undo does not
exist for POS-originated catalogue deletes. This is a data-loss-adjacent contract mismatch, and the
comment above the method (`/** Permanently delete a generic collection record. */`) documents the
behaviour without acknowledging that the envelope asked otherwise.

**D7 — `Config_Fingerprint` covers 3 of 9 collections, so six have no lever to force a re-pull after
a payload shape change.**
Only products, variations and tax_rates carry a `fingerprint` group. The
`PAYLOAD_CONTRACT_VERSION` mechanism (`Config_Fingerprint.php:109-155`) is documented as *the only*
signal that can repair a shape change — "tier 1 writes no journal row … tier 2's digest is derived
from the raw DB row and does not move … Without a signal here, a client that synced a record under
the old shape keeps it INDEFINITELY." It was added precisely because 1.10.0 shipped a wrong variation
shape. **If a future release changes the customer, coupon, order or term payload shape, the same
failure recurs with no available lever.** This is the generalisation of #1710 that the fix did not
generalise.

**D8 — Three read envelope shapes and a fourth on the write ack, with no registry group governing
them.**
Flat wc/v3 + `_rxdb_*` stamps (Lane 1); `{id,parent_id,payload,_rxdb_digest}` inside
`{documents[],meta}` (Lane 6 variations); `{id:uuid,payload,sync{},local{}}` inside
`{documents[],deletes[],checkpoint,…}` (Lane 5 orders); `{document,currentRevision}` on the write ack,
where `document` is flat for most collections and the wrapper for variations
(`Write_Controller::respond`, `Variation_Writer::build_response_document`). The registry has
`object_type`, `identity`, `proxy`, `write`, `journal`, `digest`, `fingerprint` and `backfill`
groups — but **no `envelope` group**, so nothing structurally prevents a fifth shape. #1731 already
flags "envelope contract tests" as not-yet-specified; this is the registry-shaped version of the same
gap.

**D9 — Two change-detection hook registries answer "what changed", and neither is a registry
projection.**
`Sync_Journal::register_hooks()` (32 hooks, lines 211-244) and
`Integrity_Digest::register_hooks()` (21, lines 127-163) both answer "what changed". The digest set
is a strict *subset* of the journal's — verified by the wiring golden
(`tests/includes/Test_Init_Hook_Wiring.php:35-39`) — so they are not drifting apart today. Two things
are still worth a verdict. First, the eleven-hook gap **is** D3: every hook the digest lane lacks is a
term, tax-rate or coupon hook, and `Integrity_Digest.php:424` restricts the shared post-delete handler
to `product`/`product_variation`. Second, both lists are hand-maintained literals — unlike
`Proxy_Uuid_Stamper::register_proxy_stampers()` and
`Integrity_Digest::register_proxy_digest_stampers()`, which are registry projections. Adding a
collection is one registry row for the stamping lanes and two hand edits for the detection lanes, and
the golden pins the *set*, not the *correspondence between the two*.

**D10 — `Collection_Rules` is at 2-of-9 adoption; the other seven keep their query behaviour in
proxy behavior classes.**
`Collection_Rules::rules()` (`Collection_Rules.php:216-317`) has rows for `orders` (4 sorts, 5
filters, 2 storage dialects) and `customers` (5 sort *names*, deliberately bodiless — WP_User_Query
is a third storage the table does not speak). Products, coupons and the three term collections put
their sort/filter behaviour in `Stable_Sort` calls inside their behavior classes instead. The class
docblock frames the empty plan as the adoption mechanism, so this is incomplete-by-design rather than
broken — but it means the stated invariant ("a lane cannot have a behaviour the other lacks") holds
for two collections, and the v1↔v2 parity risk it was built to remove is still open for the rest.

**D11 — The customers id-space is "every WordPress user", stated in one place and implied in three.**
`Integrity_Digest.php:299-301` says it outright ("every WordPress user is a POS customer");
`Uuid_Backfill_Controller::select_ids_user()` (lines 374-382) scans `wp_users` with no role filter;
`Sync_Journal::append_customer_updates_for_all_users()` (lines 149-161) journals a row per user; and
`Customers_Proxy_Behavior::forwarded_params()` defaults `role => all` (lines 36-38). These four
agree, which is good — but the decision is nowhere in the registry, the README or the wiki, so the
next person who "fixes" one of them to filter by the `customer` role breaks the other three. Also
worth recording: `products` and `variations` share `scan_post_types` (`Collections.php:88, 111`), so
backfilling either collection scans both and running both doubles the work (idempotent, but a wasted
admin pass).

**D12 — The sequence-log row shape differs between the `all` stream and the narrowed streams, pinned
by test convenience.**
`Changes_Controller.php:246-265`: the per-row `collection` tag is emitted only for `collection=all`
— *"The single-collection `products` / `tax_rates` endpoints keep their original row shape so their
checked-in tests stay valid."* A wire shape held in place by a test rather than by a contract. Low
live risk (the client uses `all`, per `client/change-signal.md`), but it is an explicit statement
that the contract is not the authority.

---

## 4. Honest assessment

### Principled

- **The registry itself.** `Collections.php` is the strongest thing in the engine: nullable capability
  *groups* rather than booleans, fail-closed lookups, and eight consumers written as projections of
  it (`Catalog_Proxy_Controller::resources()`, `Write_Controller::collections()`,
  `Proxy_Uuid_Stamper::register_proxy_stampers()`,
  `Integrity_Digest::register_proxy_digest_stampers()`, `Config_Fingerprint::collections()`,
  `Sync_Journal::catalogue_object_types()`, `Sync_Journal::term_taxonomy_object_types()`,
  `Digests_Controller`/`Integrity_Controller` supported-collection gates). Adding a collection really
  is one row for those eight sites.
- **tax_rates having no uuid and no write path** (`Collections.php:260, 267`). ADR 0009 is cited, the
  reason is structural (no native meta store), and every consumer handles the null group explicitly.
  This is what a good divergence looks like.
- **Stream-scoped heads and horizons** (`Sync_Journal.php:30-41, 663-694`). Orders and catalogue share
  one AUTO_INCREMENT space; a global head would break the idle 304 for whichever lane is quiet, and a
  global watermark would make every catalogue cursor rebaseline forever. The reasoning is written
  down and the invariant is enforced in three places.
- **Publishing prune watermarks before deleting** (`Sync_Journal.php:830-840`) — a failed watermark
  write aborts the whole batch. Correct ordering for a lossy operation.
- **The write pipeline's concurrency layering** — mutationId reservation *and* a per-record lock, with
  the distinction spelled out at `Write_Controller.php:173-176`. The second lock exists to close a
  silent lost update that idempotency alone does not cover. That is real engineering, not accretion.
- **Proxy behaviors as a three-phase contract with `finally`-unwind** (`Scoped_Proxy_Behavior`). The
  `Taxes_Proxy_Behavior` docblock (lines 66-82) records a live bug caused by v1's self-removing filter
  and explains why this one does not self-remove. Divergence with a receipt.
- **Variations extending WC's own variations controller** post-#1710. The docblock is candid about the
  prior mistake and its cost; the remaining divergences are consequences of the deferred-hydration
  shape, not of the incident.
- **The 3-vs-2 rebuild threshold** (`Integrity_Controller.php:48-53, 435-460`) — a server constant
  deliberately pinned above a client constant, with the reasoning and the live incident recorded.

### Accretion

- **D2 is the clearest.** A comment reading *"NB this intentionally collapses everything except
  tax_rates to products"* sits 400 lines from a registry whose stated purpose is killing that exact
  collapse. It is not a decision; it is the shape of the code before the registry existed, preserved
  by a comment that makes it look decided.
- **D1** — an `revision` column that is a date for three collections, a hash for one, and empty for
  five, served under one field name on one wire contract, is not a design. It is what you get when
  three hook families were added at different times and the column defaulted to `''`.
- **D3** — the digest lane was built for products (ADR 0014), then customers and orders were bolted
  on for Leg 3 phase 7 (ADR 0015) with the *read* half generalised and the *repair* half left
  products-only. The 400 on a non-product drill-down (lines 261-268) is the seam showing. The missing
  formula fingerprint for the two added id-spaces is a latent store-wide-false-drift bug, not a
  stylistic gap.
- **D6** — the `force` flag is validated on the way in and discarded on the way out for four
  collections. Either the envelope should not accept it or `Null_Writer` should honour it; accepting
  and ignoring a destructive flag is the worst of the three options, and no docblock acknowledges the
  mismatch.
- **D5** — `tags` read-only "because no client push path exists" is a snapshot of the client, frozen
  into the server registry.
- **D7** — the payload-contract-version mechanism was created in response to #1710 and scoped to the
  collection that caused it. The docblock argues persuasively for *why the mechanism is necessary*;
  none of that argument is products-and-variations-specific.
- **D12** — "so their checked-in tests stay valid" is accretion stated in the first person.

### The pattern

Every divergence that is principled has the same three properties: a named decision record, an
explicit null in the registry, and consumers that handle the null rather than defaulting. Every
divergence that is accretion has the same three: a defaulted value (`''`, `true`, `'products'`), a
capability that never became a registry group (repair tiers, envelopes, delete semantics), and a
consumer that decides for itself instead of reading a row.

That suggests the cheapest structural remedy the verdict tickets could reach for is **more registry
groups, not more code**: a `repair` group (which tier-2/3 routes accept this collection), an
`envelope` group (which document shape this collection is served in), and a `delete` group
(force-permanent vs client-controlled). Each converts one of the accretion divergences into either a
principled null or a bug that fails closed.

---

## Appendix — file index

| area | files |
|---|---|
| registry | `includes/Sync/Collections.php` |
| journal | `Sync/Sync_Journal.php`, `Sync_Journal_Purge.php`, `Visibility_Observer.php` |
| change signal | `API/V2/Changes_Controller.php`, `Sync/Config_Fingerprint.php` |
| digests | `Sync/Integrity_Digest.php`, `Sync/Digest_Index.php`, `API/V2/Digests_Controller.php`, `API/V2/Integrity_Controller.php` |
| proxy | `API/V2/Catalog_Proxy_Controller.php`, `API/V2/Proxy/*.php`, `Sync/Collection_Rules.php`, `Collection_Rules_Plan.php`, `Store_Scope.php` |
| augmentation | `Sync/Augmentation_Pipeline.php`, `Revision.php`, `Proxy_Uuid_Stamper.php`, `Meta_Normalizer.php`, `Variable_Prices.php`, `Variable_Children.php`, `Product_Images.php` |
| write | `API/V2/Write_Controller.php`, `API/V2/Writers/*.php`, `Sync/Mutation_Store.php`, `Header_Mirror.php`, `Order_Write_Payload.php`, `Coupon_Modified_Date.php` |
| orders | `API/V2/Orders_Controller.php`, `Sync/Order_Query.php`, `Order_Pull_Planner.php`, `Order_Serializer.php`, `Order_Document.php`, `Order_Uuid_Exception.php` |
| hydration | `API/V2/Variations_Controller.php`, `API/V2/Resolve_Controller.php`, `Sync/Product_Serializer.php` |
| identity | `Sync/Pos_Uuid.php`, `Term_Meta_Adapter.php`, `API/V2/Uuid_Backfill_Controller.php` |
| cross-cutting | `Sync/Api.php`, `Endpoint_Permissions.php`, `Health.php`, `Response_Envelope.php`, `Response_Telemetry.php`, `Retry_After_Mirror.php`, `Pos_Visibility.php`, `Cors.php` |
