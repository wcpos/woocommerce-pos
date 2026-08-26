# Archaeology: why do orders use a content-hash revision?

Research for [#1733](https://github.com/wcpos/woocommerce-pos/issues/1733) (part of the v2 Sync
Engine Trust Audit, [#1731](https://github.com/wcpos/woocommerce-pos/issues/1731); feeds the verdict
ticket [#1737](https://github.com/wcpos/woocommerce-pos/issues/1737)). 2026-08-26.

**Investigation only.** No production code was changed and no tests were run. Every claim below cites
a file, a commit, or a ticket.

---

## 0. Correcting the premise first

The ticket asks why "orders compute their revision as a content hash … while catalogue objects and
customers revision by `date_modified` on the client". That framing is **half true, and the half that
is false matters for the verdict.**

What is actually true, today, on `main`:

| Surface | Orders | Products / coupons / customers / terms | Variations |
|---|---|---|---|
| Client's `sync.revision` (what becomes `baseRevision`) | server content hash | **server content hash** | `date_modified_gmt` |
| Server CAS recompute (`Write_Controller::revision_for`) | `Order_Serializer::canonical_revision` | `Revision::compute` (content hash) | `date_modified_gmt` |
| Journal row's stored `revision` column | **content hash of the full serialized order** | `date_modified` string (`Sync_Journal::object_revision`) | n/a |
| Where the client's revision comes from | the **stored journal row** served by `/orders/pull` | a **stamp computed at read time** (`_rxdb_revision`, `Revision::register_proxy_stamps`) | client-side synthesis |

Sources: `includes/API/V2/Write_Controller.php:958-990`; `includes/Sync/Revision.php:43-72`;
`includes/Sync/Sync_Journal.php:469-512`; `includes/Sync/Order_Pull_Planner.php:82-123`;
`includes/API/V2/Orders_Controller.php:114-163`. Client side confirmed in the monorepo at
`packages/sync-engine/src/write-path/adopt-stamped-revision.ts` and
`packages/sync-engine/src/materialization/record-materialization.ts:89-190` — every lane adopts the
server's `_rxdb_revision` stamp; the `date_modified` / `String(id)` syntheses survive **only as a
transitional fallback for servers that predate the stamp** (lab #423 step 1b), and the free plugin
has stamped since before 1.10.0, so no shipped server ever hits the fallback.

So **content hashing is the house paradigm, not an order-specific one.** Products, coupons,
customers and terms are all CAS-checked against `Revision::compute` — a sha256 over the
canonicalized wc/v3 payload. The genuinely divergent collection is **variations**, which are on a
date revision (that is #1732/#1736 territory, not this ticket).

The three things that *are* order-specific:

1. **A different recipe** — `Order_Serializer::canonical_revision()` rather than bare
   `Revision::compute()` (§2 explains exactly why, and it is not "orders are special", it is "the
   order read lanes augment and self-stamp").
2. **A different lifecycle** — an order's revision is **precomputed at write time and persisted** in
   the journal, because `/orders/pull` serves the stored row's revision. Every other collection's
   revision is **computed on demand at read time**. This, not the hash itself, is the performance
   story (§6).
3. **A versioned recipe list plus a legacy-grace comparer** (`Order_Serializer:268-408`,
   `Write_Controller::revision_matches_with_grace`) that no other collection carries at anything like
   the same size.

---

## 1. Question 1 — what does the content hash actually buy?

### 1a. Where it came from (the honest answer: nobody chose it)

The order content hash has **no design ticket and no ADR behind its origin**. It was the birth
default of the very first serializer in the lab:

```php
// woo-rxdb-replication-lab @ ae3bf35, 2026-05-19, "feat: add Woo sync plugin serializer boundary"
public function sync_metadata(array $payload, int $order_id, string $source, bool $partial, int $sequence): array {
    $revision_source = wp_json_encode($payload);
    return array( /* … */ 'revision' => 'sha256:' . hash('sha256', false === $revision_source ? '' : $revision_source), /* … */ );
}
```

That commit predates **push replication entirely** (`6c32f94`, 2026-05-20 — the next day). There was
no CAS, no `baseRevision`, no 409 when the hash was chosen. It was a change-marker for a pull lane.

The *interesting* moment is the day after. `6c32f94` ("feat: add Woo-compatible push replication")
introduced the `baseRevision` precondition **and in the same commit deleted a date-based revision**:

```diff
-            $payload = array('id' => $order_id, 'date_modified_gmt' => $modified);
-            $revision = 'sha256:' . hash('sha256', (string) wp_json_encode($payload));
+            $payload = (new Woo_RxDB_Sync_Order_Serializer())->serialize_order($order_id, new WP_REST_Request());
+            $sync_meta = (new Woo_RxDB_Sync_Order_Serializer())->sync_metadata($payload, $order_id, 'custom-pull', false, 0);
+            $revision = (string) $sync_meta['revision'];
```

The sync index had been hashing `{id, date_modified_gmt}`. It was replaced by the full-document hash
**not because dates were judged unsafe, but because the index and the pull lane disagreed** — the
wiki records the reason verbatim (`wcpos/wiki`, `architecture/replication-lab.md`, "Revision
consistency"): the index "now derives an order's revision from the *full serialized order document*
… so a revision a client pulled from the index is a valid `baseRevision` for a later push."

**That is a consistency fix between two revision sources, and it was resolved by promoting the
already-existing document hash rather than by demoting both sides to a date.** The date option was
never argued against; it was simply the one that lost by being on the smaller side of the diff.

### 1b. What it buys today, concretely

Read against `Revision.php`'s own docblock and the CAS pipeline:

- **A precondition that is invariant to no-op writes.** WooCommerce bumps
  `date_modified` on *every* `save()` — `OrdersTableDataStore::persist_updates()` does
  `$order->set_date_modified( current_time('mysql') )` whenever `date_modified` is not itself in the
  changeset. A checkout performs four order saves (measured, see §6), a status transition performs
  more, and Woo core, ATUM, and gateway plugins all save orders for reasons the POS did not cause. A
  content hash is **silent for any save that did not change the served representation**; a date is
  not. Under a date revision every unrelated save invalidates every till's precondition.
- **Volatile-field immunity.** `Revision.php:18-24` and the lab cutover doc both name the concrete
  bug this killed: `related_ids` and `_links` come back in random order per GET, so a refund
  appearing against a parent order churned the parent's revision and 409'd edits that conflicted with
  nothing. That is a property of *canonicalization*, not of hashing per se — but it only exists
  because the revision is derived from content, so it has somewhere to be canonicalized.
- **Sub-second collision safety.** `date_modified` has one-second precision (`current_time('mysql')`
  → `DATETIME`). Two tills that both read at T and write within the same second would both satisfy a
  date precondition. A content hash cannot collide on different content.
- **Nothing else.** It is *not* the change-detection mechanism — that is the change-signal's separate
  md5 (`Changes_Controller::revision_hash`, `:320-393`) and the digest lane
  (`Integrity_Digest`), both explicitly documented as different jobs. It is *not* the idempotency
  mechanism — that is `Mutation_Store` + `Idempotency-Key` (ADR 0011). It is *not* the pull cursor —
  that is the journal sequence. Strip CAS away and the hash has no remaining consumer.

### 1c. Why `Order_Serializer::canonical_revision` instead of `Revision::compute`

`canonical_revision` **is** `Revision::compute`, with five order-specific pre-strips
(`Order_Serializer.php:307-385`). Each exists because of one structural fact:

> The order pull/proxy/ack lanes serve an **augmented** document, while the write path's CAS
> recompute hashes a **bare wc/v3 re-read**. The two must hash identically or every edit is a false
> 409.

The five strips, and why each collection *other than orders* avoids needing them:

| Strip | Why orders need it | Why others don't |
|---|---|---|
| `_woocommerce_pos_uuid` from order meta | HPOS exposes the protected uuid meta on reads, and the uuid is stamped **lazily at read time**, so hashing it moves the revision the first time an order is seen | wc/v3 omits protected meta for products/customers; and the proxy stamp runs at priority 9, *before* the uuid stamper at 10 (`Revision.php:33-45`) |
| `_woocommerce_pos_uuid` from **item** meta | `stamp_item_uuids()` **persists** uuids on order items during a read/ack, so the next bare re-read differs from what was served; and the served form (`{key,value}`) differs from the persisted form (`{id,key,value,display_*}`) | no other collection has child rows with lazily-stamped identity |
| `tax_ids`, `links` | read-time decorations wc/v3 never carries (`Tax_Id_Reader`, POS checkout/receipt URLs) | products' augmentation happens *after* the stamp |
| `image.id` cast to int | the augmented lanes serve it typed for v1 parity; bare wc/v3 serves a string | n/a |
| `_wp_trash_meta_*` | HPOS removes these only *after* the restored order's save hooks run, so save and the completed restore would hash differently (`c3fe9383`) | n/a |

So the answer to "why did orders need their own recipe" is **not** "orders are a special kind of
record". It is: **the order lane is the only one that both augments its read document beyond wc/v3
and mutates the record while serving it.** Every one of the five strips is a patch for that.

That is also the empirical fragility record. Six shipped commits exist solely to keep the augmented
document and the bare re-read hashing equal: `461bf854`, `9b24859f`/`7627632e` (links),
`6fa92554` + `489c51b4` + `dcf06422` (tax_ids / item uuids / typed image ids), `0f9391a4`
(coupon-line uuids), `c3fe9383` (HPOS trash meta). Each one shipped as a fix, i.e. each was a live
false-409 or a near miss. On the non-order side the same class produced `30a3d3f9` / `ee5d2205`
(taxonomy term ordering) — so this is a **content-hash tax, not an order tax**; orders just pay it
five times over.

---

## 2. Question 2 — is the stated reason still true, or lab-era residue?

**Split verdict. The CAS property is live. The machinery around it is largely residue.**

### Live

- The CAS check itself is real and load-bearing: `Write_Controller::apply_update` /
  `apply_delete` 428 without a precondition and 409 on mismatch (`:530-554`, `:594-619`). Orders are
  the highest-stakes write surface in the product (money, stock, payment state), and two tills
  editing one open order is the actual POS use case, not a hypothetical.
- The no-op-write immunity is real and is *load-bearing for orders specifically*, because orders are
  saved four times per checkout by Woo itself (§6). A date revision would move on every one of those
  saves.

### Residue

- **The versioned recipe list protects nothing that shipped.** `Order_Serializer:268-303` declares
  four recipes and says none may be deleted "while the `woocommerce_pos_sync_legacy_revision_grace`
  option still exists". But `includes/API/V2` and `includes/Sync` **do not exist in `v1.9.17`** (tag
  verified). Every one of the three non-canonical recipes landed *before* `v1.10.0` shipped
  (`dcf06422`, `6fa92554`, `489c51b4`, `0f9391a4`, `c3fe9383` — all 2026-08-06 to 08-10; `v1.10.0`
  tagged 2026-08-25). **No released WCPOS client has ever held a revision computed by any recipe
  except `canonical_revision`.** The list documents lab and pre-release-dev wire shapes.
- **The grace option is unreachable machinery.** `woocommerce_pos_sync_legacy_revision_grace` is
  read in exactly one place and **written nowhere in the plugin** — no setting, no CLI, no migration
  (`grep` across `includes/`: one `get_option` at `Write_Controller.php:456`; the only writers are
  two tests). It defaults to `yes` forever. Its retirement gate (lab #423 step 4) required a
  client-side re-anchor sweep (`runRevisionReAnchorSweep`) that **does not exist in this plugin at
  all** — it was a lab-app artifact.
- The date/`String(id)` grace branches (`:470-482`) exist to drain queues from **pre-#423 lab
  clients**. No shipped WCPOS client can produce such a `baseRevision`.

So: the *reason* for a content hash on orders still holds if you accept content-hash CAS as the house
paradigm (which the other collections already are). The *lab-era* part is the four-recipe list, the
grace comparer, and the never-flipped option — roughly 120 lines of the order revision surface that
protect a fleet that does not exist.

### Could orders revision by `date_modified` (+ tiebreaker) without losing write-conflict safety?

Mechanically, yes — the precedent is in-repo: **variations already do exactly this**
(`Write_Controller::revision_for:958-985`), and the docblock there is the best statement of what it
costs to get wrong.

What a date-based order revision would need to survive:

1. **A tiebreaker for sub-second writes.** `date_modified_gmt` alone is second-granular. The obvious
   pairing is the journal sequence (`date_modified_gmt#<sequence>`) — the journal already assigns a
   monotonic per-change sequence, and the order pull already serves it in the checkpoint. That
   restores collision safety **and** is strictly cheaper than a hash.
2. **Acceptance of no-op churn.** Under a date+sequence revision, every Woo-internal order save moves
   the revision, so a till holding an order it did not just write gets a 409 the next time it edits.
   The client auto-recovers exactly once for orders (`autoRecoverConflict`, wired at
   `write-drain-lane.ts:427` — re-stamp from the 409's `currentRevision` and re-push **the same local
   intent**), which converts most false 409s into a silent extra round trip — but that same path is
   what makes a *real* conflict a lost update, so raising the 409 rate raises the lost-update rate
   proportionally. This is the single strongest technical objection to migrating.
3. **A story for changes that fire the hook without moving the date.** In practice `persist_updates`
   always sets `date_modified` when the changeset does not, so a save that fires
   `woocommerce_update_order` has moved the date. The residual risk is the `Coupon_Modified_Date`
   class of bug (`includes/Sync/Coupon_Modified_Date.php` — a meta-only write leaves `post_modified`
   stale), which for orders is largely closed by HPOS's `date_updated_gmt` handling but would need
   pinning with a test rather than assumed.

Everything the *five strips* exist for evaporates under a date revision, because a date is not
byte-sensitive. So does the versioned recipe list. So does the entire "augmented document and bare
re-read must hash identically" invariant, which is the single most expensive constraint in the order
lane.

---

## 3. Question 3 — what deployed 1.10.0 clients depend on, and the wire cost

### What a deployed client actually holds and does

Verified in the monorepo (`packages/sync-engine`, `packages/sync-core`):

- **It treats a revision as an opaque non-empty string.** There is no `sha256:` prefix assertion
  anywhere in client production code — every `sha256` hit is a test double or an e2e fixture. The
  drain lane checks only `typeof === 'string' && !== ''` (`write-drain-lane.ts:155-157`).
- **Where it gets an order's revision:** for the custom-pull lane, verbatim from the server envelope
  (`record-materialization.ts:188-193` — `envelope.sync.revision`); for the targeted/browse lane,
  from the `_rxdb_revision` stamp, falling back to `String(date_modified_gmt ?? '')`. **The
  date-string fallback already exists and already works** — it is the pre-#423 synthesis, still
  wired.
- **What it sends:** `{ …, baseRevision }` in the body plus `If-Match: "<baseRevision>"`
  (`recordPushAdapter.ts:168-194`); the server 422s on divergence between the two (ADR 0011).
- **What it does with the ack:** `revision: ack.currentRevision ?? sync.revision` — it re-anchors
  unconditionally.
- **What it does on 409:** orders get **one** automatic rebaseline (re-stamp from the 409's
  `currentRevision`, re-push the same local intent, do **not** adopt the server document — "the local
  cart is the cashier's live sale"). A second consecutive conflict parks the row as durable
  `conflicted` and blocks further edits to that record.
- **What it does on 428:** one targeted `refreshRevision` via `fetchOrderServerRevision`, re-stamp,
  re-push once; otherwise park as `needs-revision`.

**Nothing in the client parses, validates, or compares revisions semantically.** The only equality
comparison is the order-pull stall guard (`rx-scheduler-order-fetcher.ts:1025-1044`), and it ORs
revision equality with `orderId`/`sequence`/`updatedAtGmt`, so a second-granular revision cannot
stall it.

### The wire cost of migrating

The `includes/API/V2/README.md` stance is unambiguous and now binding: *"This stance expires at
1.10.0's release. From the first shipped client onwards, any change to a journal row's shape, to the
`head` / `horizon` / `epoch` checkpoint fields, or to their meaning needs an explicit compatibility
plan in its PR: a dual-emit window, or a client-minimum gate."* A revision is not literally in that
enumeration, but it is a wire field whose *meaning* would change — the stance's spirit applies, and
#1731 says verdicts that remediate must state a wire plan.

**What breaks on flip day.** Every deployed till holds a `sha256:…` `baseRevision` for every resident
order. The moment the server's `revision_for('order')` returns a date+sequence string, the first edit
of every resident order mismatches. Concretely:

- 409 with `currentRevision` = the new-form string;
- the client's order-only auto-rebaseline fires, re-stamps, and re-pushes the same intent — **it
  succeeds**;
- the row's `sync.revision` re-anchors to the new form and never mismatches again.

So the naive cost is **one extra round trip per resident order, once**, self-healing, no user-visible
conflict UI. That is markedly cheaper than the lab's #423 cutover, because the lab had no
auto-rebaseline. But the caveat is sharp: that same auto-rebaseline **discards the server's
concurrent edit if one exists**, so a flip day where a real conflict happens to coincide silently
loses that edit. A one-shot fleet-wide false-409 event is exactly the wrong time to lean on a
one-shot lost-update recovery path.

**What dual-emit would look like.** The server already has the shape of it — the grace comparer is
literally a dual-*accept* mechanism. A migration would invert and generalize it:

1. **Emit the new form, accept both.** `revision_for('order')` returns the date+sequence form;
   `revision_matches_with_grace` gains a branch: a `sha256:`-prefixed `baseRevision` is compared
   against `Order_Serializer::canonical_revision( $bare )` — i.e. the *current* code path, kept
   alive purely as a comparer. An unchanged order therefore passes with an old-form precondition, no
   409 at all, and the ack re-anchors it to the new form. This removes even the one-round-trip cost
   and, crucially, removes the false-409/lost-update coincidence risk.
2. **Retire on a measurable gate.** The existing pattern applies: keep the dual-accept behind an
   option, retire it when no `sha256:` preconditions have been seen for N days. The plugin has no
   telemetry for that today; the honest version is a time-based window (one or two minor releases),
   since the client fleet auto-updates and the ack re-anchors every record on its first write.
3. **What must *not* be dual-emitted:** the journal `revision` column. It is stored, not versioned;
   a migration either backfills it (expensive: full re-serialization of every order — the very cost
   being escaped) or, better, **stops storing a revision for orders at all** and lets the pull lane
   derive `date_modified_gmt#sequence` from columns the journal already has. That is the shape that
   makes the migration a net deletion rather than a net addition.

Cost that survives dual-emit: `Order_Serializer` keeps `canonical_revision` and its five strips alive
as comparer-only code for the window, so the fragility (§1c) does not disappear on day one — it
disappears at retirement.

---

## 4. The strongest case for KEEPING the content hash

Stated as strongly as it can honestly be stated:

1. **It is the house paradigm, not a divergence.** Products, coupons, customers and terms are all
   CAS'd on `Revision::compute`. Migrating orders to a date revision does not *reduce* divergence —
   it creates a second one, and puts the highest-stakes collection on the weaker mechanism while
   leaving the low-stakes ones on the stronger. The uniformity argument, read carefully, points at
   *keeping* orders on the hash and fixing variations, not the other way round.
2. **Orders are the collection where no-op churn is worst.** Woo saves an order four times per POS
   checkout and again on every status transition, gateway callback, email send, and third-party
   touch. A hash absorbs all of that silently. A date revision converts each into a precondition
   invalidation for every other till holding the order.
3. **The client's 409 recovery is a lost-update machine.** `autoRecoverConflict` re-pushes the local
   intent without adopting server truth. Every additional 409 the server generates is an additional
   chance of a silently discarded server-side edit. A mechanism that generates *fewer* conflicts is
   therefore not merely nicer, it is safer — and the content hash generates strictly fewer, because
   it 409s only on genuinely different content.
4. **Sub-second correctness comes free.** A date revision needs a tiebreaker designed, implemented
   and tested; the hash needs none. Two tills on one order within the same second is not exotic in a
   busy shop.
5. **The expensive part is already paid.** The five strips exist, are tested
   (`Test_Order_Serializer`, `Test_Order_Document_Assembly`, `Test_Orders_Controller_HPOS`), and the
   invariant is pinned by characterization tests. Migration re-opens a settled surface at the exact
   moment 1.10.x is stabilizing.
6. **The perf cost has a cheaper fix than a paradigm change** — see §6.

## 5. The strongest case for MIGRATING to a `date_modified` revision

Equally strongly:

1. **The hash was never chosen.** It is a 2026-05-19 default that predates the existence of CAS by
   one day, and the one time an alternative was on the table (the sync index's
   `{id, date_modified_gmt}` hash) it was deleted for *consistency between two sources*, not on any
   evaluated safety ground. There is no ADR. Keeping it is not defending a decision; it is defending
   an accident that acquired 400 lines of scaffolding.
2. **Byte-sensitivity is a permanent tax with a proven failure record.** Six shipped commits exist
   only to keep the augmented document and the bare re-read hashing equal, and each represents a
   live or near-miss false 409. **Every future read-lane change is a potential CAS break.** Add a
   field to the order document — a new POS decoration, a Pro augmentation, a plugin filter — and you
   must remember to strip it, or every till 409s. That is a landmine field that a date revision does
   not have. The versioned recipe list is the scar tissue of this exact hazard.
3. **The strips are unfalsifiable at review time.** Nothing in the type system or the code structure
   tells a reviewer that `Order_Serializer::document()` and `canonical_revision()` are coupled. The
   only defence is a characterization test suite and institutional memory.
4. **Orders are the *only* collection with a stored revision, and the hash is why.** Because the pull
   lane serves the journal row's revision, the hash must be computed on the write path — dragging the
   full REST read lane into every order save (§6). No other collection pays this. A date revision
   makes the journal's `revision` column redundant for orders (it is already `modified_gmt` +
   `sequence`, both already stored), turning a computed column into a derived one.
5. **The precedent is already in-repo and accepted.** Variations run date-based CAS today, with a
   documented failure analysis and a test pinning it. The mechanism is understood.
6. **The client needs no change.** No `sha256:` assertions, revisions are opaque strings, and the
   date synthesis is still wired as a fallback. The migration is a server-side change with a
   dual-accept window (§3).
7. **The residue is disproportionate.** Four recipes, a grace comparer, an option nobody can set, and
   a retirement gate whose client-side half does not exist in this repo — all guarding a fleet that
   is one day old and uniformly on one recipe.

---

## 6. The performance dimension: root cause or incidental?

**The revision paradigm is the root cause, and the coalesce fix is explicitly a frequency reduction,
not a cure.** This is not inference — the in-flight branch says so itself.

`Sync_Journal::record_order_change()` (`:469-497`) does, on **every** order save:

```php
$serializer = new Order_Serializer();
$payload    = $serializer->serialize_order( $order_id, new WP_REST_Request() );  // full WC_REST_Orders_Controller read lane
$sync_meta  = $serializer->sync_metadata( $payload, $order_id, 'custom-pull', false, 0 );
$revision   = (string) $sync_meta['revision'];
```

Compare `Sync_Journal::object_revision()` (`:509-512`), which every other collection uses:
`gmdate( 'Y-m-d H:i:s', $object->get_date_modified()->getTimestamp() )` — free.

The in-flight branch `perf/coalesce-order-journal-serialization` (commit `623fc8c8`, 2026-08-26,
residual of #1725) states the causality in its own docblock:

> "An order's revision is a CONTENT HASH of its full serialized payload … the entire read lane, to
> produce one string. **That cannot be cheapened: the hash recipe is a wire contract with deployed
> clients (see the versioned recipe list in Order_Serializer), so it can only be run FEWER TIMES, not
> faster.**"

Measured there on dev-free (HPOS, 75,350 orders, medians of 7 reps): one `POST /wcpos/v1/orders`
performed **four** order writes and therefore four full serializations and four journal rows, three
superseded before the response was sent. The three redundant passes cost 45–71 ms, 103–139 rows
examined and 33–48 queries per checkout. The fix buffers order rows for the duration of a REST
handler and writes one row with the settled revision: passes 4 → 1, lookups 10 → 4, ~595 → 477 rows
examined on `pos-open`.

Reading that honestly:

- **Root cause:** orders are the only collection whose revision cannot be read off the object, and
  the only one that must precompute it at write time (because `/orders/pull` serves the *stored*
  revision, `Order_Pull_Planner.php:82-123`). Both facts follow from the content-hash choice.
- **The coalesce fix treats the symptom, and does so well.** It removes 3 of 4 redundant passes; the
  remaining one is irreducible under the current paradigm. It is wire-neutral, correct, and worth
  merging regardless of the verdict — it is not work that a later migration wastes, because
  buffering journal rows per REST handler is desirable independent of what the row contains.
- **What the migration would remove that coalescing cannot:** the last pass. A date+sequence revision
  makes `record_order_change` a pure `INSERT` with values already in hand — no serialization at all,
  bringing orders to parity with every other collection's journal cost. On a 75k-order store that is
  the difference between ~15–24 ms and ~0 ms of serialization per checkout, plus the removal of the
  read-lane's own queries from the write path.
- **A third option the verdict should weigh:** keep the hash but **stop storing it** — have
  `/orders/pull` compute `canonical_revision` from the payload it is already serializing (the
  `fallback_revision` closure at `Orders_Controller.php:129-131` already does exactly this when a row
  carries no revision) and drop the column's use for orders. That moves the cost from N-writes to
  1-per-read-of-a-changed-order and keeps the CAS property intact. It is strictly smaller than a
  paradigm change and should be priced before one.

---

## 7. Facts the verdict (#1737) should not have to re-derive

- No ADR exists for the order revision, in this repo or in `woo-rxdb-replication-lab/docs/adr/`. The
  closest artefacts are lab ticket #422/#423 and
  `woo-rxdb-replication-lab/docs/audits/2026-07-09-revision-unification-cutover.md`, which decide the
  *hasher unification*, never the *hash-vs-date* question.
- Lab #423 explicitly closed a hole where lanes synthesized `date_modified_gmt` as `sync.revision`
  and the push side hashed — those records 409'd on first edit "under EITHER algorithm". Any
  migration must move **both** sides atomically, which is why dual-accept (§3) rather than dual-emit
  is the right shape.
- `Revision::compute` is used by orders too — `canonical_revision` ends in
  `Revision::compute( … )`. "Orders don't use the canonical revision" is false; they use it with a
  pre-strip.
- The change-signal md5 (`Changes_Controller:320-393`) and the integrity digest are separate hashes
  doing separate jobs and are unaffected by anything here.
- Lab measurement for a forced re-pull, if one is ever contemplated: 7.95 ms/order,
  serialization-bound (the sha256 itself is noise) — ~9 minutes of spread-out server work at 68k
  orders. **A migration does not need this**, because a dual-accept window means no re-pull.

## 8. Open questions this research could not close

- **Is there any order-mutating path that fires `woocommerce_update_order` without moving
  `date_modified`?** `persist_updates()` sets it whenever the changeset does not, which suggests no —
  but this was reasoned from source, not proven by test. A migration must pin it.
- **What is the real-world rate of Woo-internal order saves per resident order per day?** This sets
  the false-409 rate under a date revision, which is the load-bearing number for §4.3. It is
  measurable on dev-free by counting journal rows per order over a window; nobody has.
- **Does Pro augment the order document?** Not checked. If it does, it is another strip that must
  exist, and another argument for §5.2.

---

## Appendix — file map

| Concern | File |
|---|---|
| Generic canonical revision + proxy stamps | `includes/Sync/Revision.php` |
| Order recipe list, five strips, three historical recipes | `includes/Sync/Order_Serializer.php:252-433` |
| CAS dispatch, grace comparer, 409/428 | `includes/API/V2/Write_Controller.php:436-484, 512-620, 958-990` |
| Journal write (the per-save serialization) | `includes/Sync/Sync_Journal.php:469-512` |
| Pull serves the stored revision | `includes/Sync/Order_Pull_Planner.php:71-141`, `includes/API/V2/Orders_Controller.php:102-168` |
| Version-skew stance | `includes/API/V2/README.md` |
| Coalesce fix in flight | branch `perf/coalesce-order-journal-serialization`, commit `623fc8c8` |
| Client revision adoption | `monorepo-v2/packages/sync-engine/src/write-path/adopt-stamped-revision.ts`, `…/materialization/record-materialization.ts` |
| Client push + 409 recovery | `monorepo-v2/packages/sync-core/src/recordPushAdapter.ts`, `…/drainMutationQueue.ts`, `…/sync-engine/src/write-path/write-drain-lane.ts` |
| Lab origin | `woo-rxdb-replication-lab` commits `ae3bf35`, `6c32f94`, `e000e14`; tickets #422/#423; `docs/audits/2026-07-09-revision-unification-cutover.md` |
