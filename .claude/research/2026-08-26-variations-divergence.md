# The variations divergence — envelope, controller, lanes

Research for [#1734](https://github.com/wcpos/woocommerce-pos/issues/1734) (part of the
[#1731](https://github.com/wcpos/woocommerce-pos/issues/1731) trust-audit map).
Investigation only — no production code touched, no tests run.

Ground truth: plugin `main` at `866ab2f6` (1.10.2); client monorepo `monorepo-v2` at `380a276bb1`
(app 1.10.2, tag `v1.10.2`). Every file/line citation is against those commits.

Sources read: `includes/API/V2/Variations_Controller.php`, `Resolve_Controller.php`,
`Write_Controller.php`, `Writers/Variation_Writer.php`, `Digests_Controller.php`,
`Integrity_Controller.php`, `includes/Sync/{Collections,Product_Serializer,Revision,
Augmentation_Pipeline,Variable_Children,Variable_Prices,Product_Images,Pos_Uuid,
Config_Fingerprint}.php`, `includes/API/V2/README.md`, `tests/includes/API/V2/
Test_Variations_Search.php`; the birth commit `2acacc39` and the fix PR
[#1713](https://github.com/wcpos/woocommerce-pos/pull/1713) for
[#1710](https://github.com/wcpos/woocommerce-pos/issues/1710); the retired lab's
`docs/pos-replication-model.md` and `docs/adr/0007`, `0014`; and — decisively — the client's
`packages/sync-engine/src/collections/collection-descriptors.ts`,
`materialization/record-materialization.ts`, `write-path/adopt-stamped-revision.ts`,
`local-coverage/reconcile-port.ts`, `scheduler/rx-scheduler-variation-fetcher.ts`.

Incorporated from the sibling streams, not re-derived:

- **#1732 survey** — `Revision::register_proxy_stamps()` hooks only the proxy filter
  (`Revision.php:43-45`), so the serialized lane never emits `_rxdb_revision`; the variations
  `date_modified_gmt` revision special case and its grace branch are therefore load-bearing
  *today*, not vestigial. Divergences V1–V5 are this ticket's subject; D7 (payload-contract
  coverage) and D8 (four envelope shapes, no `envelope` registry group) are its neighbours.
- **#1735 hook audit** — `variations?include=` runs no query at all
  (`Variations_Controller.php:262-268`), so `woocommerce_rest_product_variation_object_query`
  never fires on that lane; the collection/search lanes DO fire it via
  `parent::prepare_objects_query()` (`:117`). All three lanes serialize through
  `Product_Serializer`, so `woocommerce_rest_prepare_product_variation_object` and
  `register_rest_field()` get-callbacks run on every lane (with a synthetic bare request).

---

## 0. What #1710 actually changed, and what it did not

PR #1713 changed the **species of the payload** and nothing else about the shape around it:

| | before #1713 | after #1713 (today) |
|---|---|---|
| route class | `extends WP_REST_Controller`, ~90 lines of hand-rolled postmeta SQL | `extends WC_REST_Product_Variations_Controller` (`Variations_Controller.php:50`) |
| serializing controller | `WC_REST_Products_Controller` | `WC_REST_Product_Variations_Controller` (`Product_Serializer.php:109`) |
| payload fields | `images[]`, `categories`, `related_ids`, `price_html`, …25 product-only fields | `image` (singular), `wc_get_formatted_variation()` name, no product-only fields |
| **envelope** | `documents[].{id,parent_id,payload,_rxdb_digest}` | **unchanged** |
| **registry row** | `proxy:null`, `digest:null`, `bulk_reader:null` | **unchanged** |
| **revision** | `date_modified_gmt` | **unchanged** |

`Config_Fingerprint::PAYLOAD_CONTRACT_VERSION['variations'] = 2` (`Config_Fingerprint.php:150-154`)
is the forced-re-pull lever that shipped with the fix — it exists *because* nothing else in the
engine can signal a payload shape change (the survey's D7).

One stale artefact the fix left behind: `Product_Serializer`'s class docblock still states rule 2 as
*"Variations are serialized through the SAME products controller as products"*
(`Product_Serializer.php:33-35`), which the property docblock 20 lines below
(`:49-62`) and the code at `:109` now contradict. Documentation only, but it is the exact sentence
whose earlier truth caused #1710.

---

## 1. The envelope

### 1.1 Where each collection's envelope is built

| lane | shape | built at |
|---|---|---|
| proxy list (products, orders, customers, terms, coupons, taxes) | **flat wc/v3 array**, `_rxdb_revision` / `_rxdb_digest` / `_woocommerce_pos_uuid` stamped onto each record | `Catalog_Proxy_Controller` forwards `rest_do_request` and returns wc/v3's own body; the stamps ride `woocommerce_pos_sync_proxy_response` (`Augmentation_Pipeline.php:105-135`) |
| variations (`GET /variations`) | `{ documents[].{id,parent_id,payload,_rxdb_digest?}, meta{} }` | `Variations_Controller.php:309-335` |
| resolve (`GET /resolve/barcode`) | `{ id, type, parent_id, payload }` — **for products too** | `Resolve_Controller.php:111-118` |
| orders pull (`GET /orders/pull`) | `{ documents[].{id:uuid,payload,sync{},local{}}, deletes[], checkpoint, … }` | `Sync\Order_Document::build` |
| write ack (all collections) | `{ document, currentRevision }`; `document` is flat for most, the variations wrapper for variations | `Write_Controller::respond` (`:915-931`), `Variation_Writer::document` (`:75-93`) + `build_response_document` (`:96-100`) |

So the variations wrapper is not unique — `/resolve/barcode` wraps **products** in a near-identical
`{id,type,parent_id,payload}`. What is unique is that variations are the only *collection pull* lane
that wraps.

### 1.2 How different are they really?

The wrapper carries exactly three things beyond the payload:

1. `id` — also `payload.id`, always.
2. `parent_id` — also `payload.parent_id` on WooCommerce ≥ 8.3, and the plugin **backfills it below
   8.3** (`Product_Serializer::backfill_pre_wc83_variation_fields`, `:143-154`). So it is present in
   the payload on every supported WooCommerce.
3. `_rxdb_digest` — the Leg-3 existence digest, which on the proxy lane is stamped **on the record
   itself**, top level (`Integrity_Digest::stamp_digests`). Transport metadata either way.

### 1.3 Is it load-bearing for the client's deferred-hydration flow?

**No — and the client has already said so in code.** The shipped client's parser
(`collection-descriptors.ts:136-170`) accepts *both* shapes, and its docblock states the design
intent verbatim:

> the wrapper exists to supply `id` and `parent_id`, and WooCommerce has carried BOTH in the
> variation payload itself since WC 8.3 … so it adds nothing the payload does not already have.
> Tolerance ships FIRST and on its own. The server cannot drop the wrapper until every deployed
> client can read both shapes … the tolerance is removed only once the plugin's minimum supported
> version is past the release that changed it.

Mechanically, the client immediately **flattens the wrapper away** — `{...wrapper.payload, id,
parent_id, _rxdb_digest?}` — and everything downstream consumes the flat record: `parentRemoteId`
comes from `identified.payload.parent_id` (`record-materialization.ts:127`), and the manifest row
comes from `payload._rxdb_digest` (`:60-65`). The push ack has to be flattened by the *same*
function before the projection can key it (`flattenVariationAckDocument`,
`collection-descriptors.ts:592-603`) — the wrapper is a cost on that path, not a benefit.

Deferred hydration itself needs none of it: the flow is "change-signal yields bare variation ids →
`GET /variations?include=<ids>` → upsert". The parent is resolved server-side off the loaded object
(`Variations_Controller.php:312`), which was the lab's stated reason for the wrapper
(`woo-rxdb-replication-lab/docs/pos-replication-model.md:213-219`: *"resolves the parent
server-side … returning `{id, parent_id, payload}`"*) — but that reason is satisfied by the payload's
own `parent_id` today.

**Call: accident of the lab port, kept for wire compatibility.** The wrapper was principled in the
lab (2026-05, before the WC-8.3 `parent_id` guarantee and the backfill existed); it is now redundant
and both sides know it.

### 1.4 What normalizing it would cost

- **Server:** delete the wrapper build at `Variations_Controller.php:309-319` and
  `Variation_Writer::document` `:81-91`; move `_rxdb_digest` to a top-level key on the record (the
  proxy lane's own convention); keep or drop `{documents[],meta}` independently — `meta.requested` /
  `meta.returned` is the shortfall signal the client's prune reads, and is *not* the wrapper.
- **Interlock — the write path breaks silently if the wrapper goes alone.** Both
  `revision_for()` (`Write_Controller.php:958-985`) and `revision_matches_with_grace()`
  (`:470-479`) read `date_modified_gmt` from *either* position specifically so the wrapper can be
  dropped; the 25-line comment at `:962-982` documents the lost-update class that a wrapper-only
  read would produce. Those two reads are the guard, and they already exist. Nothing else in the
  plugin reads the wrapper.
- **Tests:** `tests/includes/API/V2/Test_Variations_Search.php` asserts `['documents']` in ~25
  places and `['documents'][0]['parent_id']` at `:618-620`. Mechanical, but it is the file that
  pins the whole route.
- **Wire compatibility (`includes/API/V2/README.md`):** the "1.10.0 is a hard cutover" stance
  **expired at 1.10.0's release** (README:35-39) — from the first shipped client onwards, a shape
  change needs *"a dual-emit window, or a client-minimum gate"* in its PR. Concretely: client
  tolerance landed in `5458c606e9` and shipped in **v1.10.2**; **v1.10.0 and v1.10.1 are tagged
  releases whose parser throws** on a bare array. There is no client-version gate on the server
  today (no `client_version` / minimum handling anywhere in `includes/Sync` or `includes/API/V2`),
  and the only version signal available is the `User-Agent: WCPOS/<version>` header — stamped by
  native and Electron, **empty on web** (`packages/utils/src/app-info/index.ts:39-44`,
  `index.web.ts:40`). So the realistic options are (a) wait until the plugin's minimum supported
  client is ≥ 1.10.2, (b) gate on an explicit opt-in request param the new client sends, or (c)
  leave it. A `payload_contract` bump is *not* a substitute — it forces a re-pull, it does not
  negotiate a shape.

---

## 2. The lanes

Registry row: `includes/Sync/Collections.php:91-113`.

### 2.1 `proxy: null` (`Collections.php:102`)

*"hydrated via the per-id /variations controller"*.

**What it is:** the variations row registers no `Catalog_Proxy_Controller` route, so `/variations`
is a hand-registered route (`Variations_Controller::register_routes`, `:60-84`) rather than a
registry projection.

**Reason, reconstructed:** the proxy lane's contract is *forward to a wc/v3 counterpart*
(`'wc_route' => '/wc/v3/products'` etc.). WooCommerce registers variations **only** under
`products/<parent>/variations` — there is no flat cross-parent wc/v3 route to forward to. That is
structural, and it is the same fact the controller docblock opens with (`:29-31`).

**Load-bearing or accident: principled — with one real consequence.** Because `/variations` is not a
proxy row, it never passes through `woocommerce_pos_sync_proxy_response`, and therefore misses all
three batch stampers: `Revision::stamp_proxy_revisions` (pri 9),
`Proxy_Uuid_Stamper::stamp_proxy_generic` (pri 10) and `Integrity_Digest::stamp_digests` (pri 10).
The route replaces two of them by hand — the uuid via the serialized-lane augmenter
(`Pos_Uuid::stamp_serialized_record`, wired at `Augmentation_Pipeline.php:120-125`) and the digest
via an explicit bulk read (`Variations_Controller.php:284-286`) — and simply does without the third
(§2.4). Note that the post-#1710 route *does* answer a bare collection page (`:250-261`), so it is
functionally a list lane that is not registered as one.

**Cost of normalizing:** a proxy row would need a `wc_route` that does not exist. The alternative
(project the three stampers onto the serialized lane instead) is §2.4's fix and is cheaper.

### 2.2 `digest: null` (`Collections.php:107`)

*"folded into the products id-space (owner row carries it)"*.

**What it is:** the `products` row owns `id_space: 'products'` with
`object_types: ['product','variation']` (`Collections.php:80-84`). Variation digests are stored and
read under `products`.

**Load-bearing or accident: principled, and mutually consistent on both sides.** The server reads
variation digests with `read_digests('products', $ids)` (`Variations_Controller.php:284-285`) — with
a `::class` note explaining why the bare class-name probe was wrong — and both digest routes gate on
`Collections::with('digest')` so `?collection=variations` fails explicitly rather than silently
serving products (`Digests_Controller.php:90-100`, `Integrity_Controller.php:186-196`). The client
matches exactly: `reconcile-port.ts:144-150` uses one manifest for the `products` id-space and
scans **both** local collections (`sourceCollections = ['products','variations']`) while sending
`&status=publish` and no `collection` param; the returned `object_type` splits the two
(`:190-200`). Products and variations genuinely share one `wp_posts` id-space (ADR 0014), so a
second id-space would be a fiction.

**Cost of normalizing:** none worth paying — a `digest` group for variations would either duplicate
the products id-space or create a second manifest for the same ids. The only artefact is
cosmetic: `/digests?collection=variations` answers "collection has no digest id-space", which is a
correct-but-surprising answer for a collection that *does* have digests.

### 2.3 `bulk_reader: null` (`Collections.php:96-99`)

*"No bulk reader: variations are stamped through the serialized-product filter, not a proxy list
page."*

**What it is:** the uuid identity group keeps `id_type: post`, `post_type: product_variation`,
`detector: uuid_owned_by_other`, `loader: product` — everything except the bulk reader, which the
proxy stamper uses to read a whole page of uuids in one query.

**Load-bearing or accident: a principled consequence of `proxy: null`, but not the same kind of null
as orders'.** Orders set `bulk_reader: null` for a *positive* reason (payload mode — HPOS serves the
meta, so the uuid comes off the response). Variations set it because **there is no list page to bulk
read**: each record is uuid-stamped one at a time by `Pos_Uuid::stamp_serialized_record`
(`Pos_Uuid.php:372-382`), which calls `ensure_uuid()` per record — mint-on-read, collision-checked
per record. Pinned by `Test_Variations_Search.php:94-101`.

**Cost of normalizing:** the reader it would name (`bulk_read_post_uuids`) already exists and already
handles `product_variation` post ids — the products row's uuid backfill scans both post types
(`Collections.php:88`, `:111`). Making the `/variations` route bulk-read uuids for its include set
would be a small change and would remove N per-record meta reads from a 100-id hydration page. It
is a performance/consistency item, not a correctness one — nothing today is wrong, it is just the
one identity lane whose work is O(records) instead of O(pages).

### 2.4 No `_rxdb_revision` on the serialized lane

**What it is:** `Revision::register_proxy_stamps()` adds the stamper to
`woocommerce_pos_sync_proxy_response` **only** (`Revision.php:43-45`). `Augmentation_Pipeline`
declares the three product augmentations on both lanes, but the three *batch stampers* (revision,
uuid, digest) keep their own registrars and only the uuid one has a serialized-lane twin
(`Augmentation_Pipeline.php:110-125`). So no record served through
`woocommerce_pos_sync_serialized_product` — i.e. every variation, every `/resolve/barcode` hit —
carries `_rxdb_revision`.

**Client consequence, confirmed:** `adoptStampedRevision()` is generic and would adopt the stamp if
present (`write-path/adopt-stamped-revision.ts`); with no stamp it falls back to the synthesized
value, which for variations is `date_modified_gmt` and, failing that, the remote id
(`record-materialization.ts:88-100`). That fallback is described in the client's own descriptor
docblock as *"the transitional fallback for proxies that predate the stamp"*
(`collection-descriptors.ts:28-32`) — i.e. the client considers the date path legacy, and the
server is the reason it is still live for one collection.

**Load-bearing or accident: accident of registrar placement, load-bearing by consequence.** Nothing
decided that variations should be excluded; the revision stamper was written for the batch lane
(#423 step 1b) and no one projected it onto the per-object lane. But because it *is* excluded,
§2.5's date revision is now the only thing that makes the write path work.

### 2.5 The `date_modified_gmt` revision special case

**Where:** `Write_Controller::revision_for()` `:958-985` (the variation branch) and
`revision_matches_with_grace()` `:470-479` (the date branch), both reading the date from either the
wrapper's `payload` or the top level.

**Recorded reason** (`:960-967`): *"A variation's revision is its `date_modified_gmt`, deliberately:
the client's targeted pull synthesizes exactly that as `sync.revision`, so both sides agree without
the variations lane needing a stamped `_rxdb_revision`."* Verified true on the client
(`record-materialization.ts:96-100`).

**Load-bearing: yes, today — it is the only thing holding the variation write path together.** It is
also the divergence with the most residual risk, for three reasons:

1. **Two writes inside one second produce the same revision.** `date_modified_gmt` has 1-second
   resolution, so a second edit within the same second is invisible to the CAS. For products the
   revision is a content hash, so any content change moves it. The per-record lock
   (`Write_Controller.php:173-176`) serializes concurrent mutations but does not help a
   second-granularity comparison.
2. **A meta-only edit that does not call `save()` does not move the revision.** The hash-based
   collections do not have this hole.
3. **There is no bare/augmented separation on this path.** For products, `default_document_for()`
   (`:885-901`) returns the *bare* wc/v3 body, the revision is hashed over those bytes, and
   augmentation happens afterwards in `default_response_document()` (`:904-912`). For variations,
   `Variation_Writer::document()` (`:75-93`) returns a **fully augmented, uuid-stamped** payload
   inside the wrapper, and that is what `apply_update()` hands to `revision_for()`
   (`:544-546`). Harmless only because the date branch ignores the bytes. Anyone switching
   variations to `Revision::compute()` must also give this path a bare re-read, or the write-side
   hash will never equal a proxy-side stamp — exactly the invariant `Revision`'s class docblock
   (`Revision.php:25-30`) and the pipeline's priority-9 comment
   (`Augmentation_Pipeline.php:111-113`) exist to protect.

**Cost of normalizing:** stamp `_rxdb_revision` on the serialized lane (one registrar line), give
the variation write path a bare document to hash, and let `revision_matches_with_grace()`'s existing
date branch bridge deployed clients holding date revisions — that grace path is already written,
already versioned behind `woocommerce_pos_sync_legacy_revision_grace`, and already handles exactly
this migration. No client change is required: `adoptStampedRevision` prefers the stamp the moment it
appears.

---

## 3. The remaining risk surface

### 3.1 Every path that produces a variation record today

`Product_Serializer` is the *only* variation serializer in the plugin (grep for its callers:
`Variations_Controller.php:289`, `Changes_Controller.php:378`, `Resolve_Controller.php:111`,
`Write_Controller.php:908`, `Variation_Writer.php:80`), and since #1713 it dispatches on the object
type (`Product_Serializer.php:99-114`). So the **payload species is uniform**: every v2 route that
emits a variation emits a `WC_REST_Product_Variations_Controller` representation.

| path | controller used | envelope | notes |
|---|---|---|---|
| `GET /wcpos/v2/variations` (include / search / bare page) | variations | `documents[].{id,parent_id,payload,_rxdb_digest?}` | uuid + augmentations via the serialized filter; no `_rxdb_revision` |
| `GET /wcpos/v2/resolve/barcode` | variations | `{id,type,parent_id,payload}` | same wrapper family; **wraps products too** (`Resolve_Controller.php:111-118`) |
| `GET /wcpos/v2/changes/revision-hash` | variations | hash only, no payload emitted | tier-3 repair |
| `POST /wcpos/v2/push/variations` (ack) | variations | `{document:{id,parent_id,payload,_rxdb_digest?}, currentRevision}` | `Variation_Writer::document` + `build_response_document` |
| `GET /wcpos/v2/products` (proxy) | products | flat wc/v3 | emits variation **ids** only, via `Variable_Children`; never a variation record |
| `GET /wcpos/v1/products/variations` (frozen census lane) | variations (`V1\Product_Variations_Controller`) | flat wc/v3 array + v1 tweaks | still used by the client for the variation count |
| digest / integrity lanes | none | ids + digests | raw SQL over `wp_posts`; no REST representation |

The three product-shaped augmentations are correctly inert or variation-aware on a variation
payload: `Variable_Prices` returns early unless `type === 'variable'` (`Variable_Prices.php:43-46`);
`Variable_Children` returns early unless the payload has a `variations` array
(`Variable_Children.php:75-77`); `Product_Images` carries an explicit singular-`image` branch added
by #1710 (`Product_Images.php:36-46`).

### 3.2 Would an extension built on the variations API see WC-REST-shaped records everywhere?

**The record inside is WC-REST-shaped everywhere; the container around it is not.**

- **Payload species: uniform.** After #1713 there is no route left that hands an extension a
  variation serialized through the products controller. A `register_rest_field()` callback or a
  `woocommerce_rest_prepare_product_variation_object` filter fires on **every** v2 variation path
  (via `Product_Serializer`), which is the #1735 finding.
- **Container: not uniform, and not discoverable.** An extension reading `/wcpos/v2/variations` gets
  `documents[].payload`; reading `/wcpos/v2/resolve/barcode` gets `{id,type,parent_id,payload}`;
  reading a push ack gets `{document:{…payload}}`; reading `/wcpos/v1/products/variations` gets a
  bare array. The registry has no `envelope` group to describe this (the survey's D8), so the shape
  is a per-controller fact.
- **Two caveats that survive #1710:**
  1. The serialization request is a synthetic bare `GET /` (`Product_Serializer.php:216-221`), so a
     filter that reads `$request['context']`, route or query params sees nothing on any per-object
     lane. `product_id` is set explicitly for variations so `prepare_links()` does not claim parent 0
     (`:101-105`); no other param is.
  2. On the `include=` lane there is **no `WP_Query`**, so
     `woocommerce_rest_product_variation_object_query` — an extension's normal seam for narrowing
     what the POS may see — never runs. The collection and search lanes do run it.
- **Route registration:** `parent::register_routes()` is deliberately not called
  (`Variations_Controller.php:61-70`), so wc/v3's CRUD routes are not duplicated into `wcpos/v2`;
  writes ride `Write_Controller` → WooCommerce's nested routes, where all the standard
  `woocommerce_rest_{pre_insert,insert}_product_variation_object` hooks fire normally (#1735 §2.8).

### 3.3 Documentation drift found in passing (not code risk)

- `Product_Serializer.php:33-35` — rule 2 still says variations go through the products controller.
  Contradicted at `:49-62` and `:109`.
- `collection-descriptors.ts:130-134` (client) — says the wrapper-level `_rxdb_digest` is
  *"deliberately dropped"*; the code six lines below hoists it into the flattened payload
  (`:166-169`), and `record-materialization.ts:60-65` depends on it being there.
- `Collections.php:102` — *"hydrated via the per-id /variations controller"* predates the bare
  collection page added post-#1710 (`Variations_Controller.php:250-261`); the route is no longer
  per-id only.

---

## 4. Summary table

| divergence | where | recorded / reconstructed reason | load-bearing? | cost to normalize |
|---|---|---|---|---|
| **Wrapper envelope** `documents[].{id,parent_id,payload,_rxdb_digest}` | `Variations_Controller.php:309-335`; `Variation_Writer.php:81-91` | Lab port: parent resolved server-side so the client needs no parent→child dance (`pos-replication-model.md:213-219`) | **No.** Client flattens it immediately, already tolerates a bare array (client `v1.10.2`, `5458c606e9`), and calls it *"adds nothing the payload does not already have"* | Small server edit; ~25 test assertions; **wire gate**: clients v1.10.0/v1.10.1 throw on a bare array and there is no server-side client-version gate (README:35-39 requires dual-emit or a minimum gate) |
| **`proxy: null`** | `Collections.php:102` | No flat cross-parent variations route exists in wc/v3 to forward to | **Principled** | Not normalizable as a proxy row; the real consequence is the three missing batch stampers |
| **`digest: null`** | `Collections.php:107` | Products/variations share one `wp_posts` id-space (ADR 0014); the `products` row owns both object types | **Principled**, and matched byte-for-byte by the client's single products manifest (`reconcile-port.ts:144-150`) | Nothing worth changing; only `/digests?collection=variations` reads oddly |
| **`bulk_reader: null`** | `Collections.php:96-99` | No list page to bulk-read; uuids stamped per record on the serialized lane | **Consequence of `proxy: null`** — principled, but unlike orders it is an absence, not a mode | Small: `bulk_read_post_uuids` already handles `product_variation`; a per-page bulk read on `/variations` would remove N meta reads |
| **No `_rxdb_revision` on the serialized lane** | `Revision.php:43-45`; `Augmentation_Pipeline.php:110-125` | None recorded — the stamper was written for the batch lane (#423 step 1b) and never projected | **Accident**, but load-bearing by consequence: it is why §2.5 exists | One registrar line, plus a bare document on the variation write path; client adopts the stamp automatically |
| **`date_modified_gmt` revision** | `Write_Controller.php:958-985`, `:470-479` | Recorded: matches what the client synthesizes, so no stamp is needed | **Yes, today.** Verified against `record-materialization.ts:96-100` | Covered by the existing versioned grace comparer; but carries 1-second resolution, no meta-only-edit detection, and no bare/augmented separation on the write path |

---

## Appendix — file index

| area | files |
|---|---|
| route | `includes/API/V2/Variations_Controller.php` |
| serialization | `includes/Sync/Product_Serializer.php`, `Augmentation_Pipeline.php`, `Variable_Prices.php`, `Variable_Children.php`, `Product_Images.php`, `Pos_Uuid.php` |
| registry | `includes/Sync/Collections.php:91-113` |
| revision | `includes/Sync/Revision.php`, `includes/API/V2/Write_Controller.php:452-484, 880-985` |
| write | `includes/API/V2/Writers/Variation_Writer.php` |
| digests | `includes/Sync/Digest_Index.php`, `includes/API/V2/Digests_Controller.php`, `Integrity_Controller.php` |
| neighbouring envelopes | `includes/API/V2/Resolve_Controller.php`, `includes/Sync/Order_Document.php` |
| contract | `includes/API/V2/README.md`, `includes/Sync/Config_Fingerprint.php:120-208` |
| tests | `tests/includes/API/V2/Test_Variations_Search.php` |
| client (monorepo-v2 @ `380a276bb1`) | `packages/sync-engine/src/collections/collection-descriptors.ts`, `materialization/record-materialization.ts`, `write-path/adopt-stamped-revision.ts`, `local-coverage/reconcile-port.ts`, `scheduler/rx-scheduler-variation-fetcher.ts` |
