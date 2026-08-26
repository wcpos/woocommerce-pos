---
status: accepted
---

# Order revision: content hash, computed at pull time, never stored

The order revision — the `baseRevision` a client echoes for optimistic concurrency — is a
sha256 content hash, the same paradigm as every other CAS'd collection, computed at pull
time from the exact payload being served and never persisted. The journal row's `revision`
column is written empty for orders; `/orders/pull`'s computing fallback (already shipping
for pre-backfill rows) is the only revision source. Write-side CAS is unchanged: it always
recomputed from a fresh re-read and never consulted the stored value.

**Why not stored-at-save (the 1.10.0 design):** the write-time hash serialized the full
order on every `woocommerce_update_order` — four times per checkout — to feed a column with
a single reader that already had a computing fallback. Worse, changes that skip the save
hook (certain HPOS meta-only saves, trash transitions) left a stale stored hash mismatching
the served payload, a false-409 class that pull-time computation makes structurally
impossible. The origin of the stored design was an unchosen lab default, not a decision
(free#1733).

**Why not a date-based revision (closed permanently, not deferred):** CPT order storage is
supported, and on the CPT store most order edits never move `post_modified` — a
`date_modified` revision would be silent for the majority of CPT edits (verified against
WooCommerce 11.1.0-beta.2 source; pinned by test). HPOS adds second-granularity collisions
(one checkout's four saves share a second) and a caller-supplied-date hole
(`wc_create_refund`).

**Fingerprint scope:** the hashed form is the bare, pre-filter payload restricted to the
top-level fields of WooCommerce's own REST order schema, plus the existing volatile-field
strips. Third-party additions (`register_rest_field`, prepare-filter injections, the
pull-only `woocommerce_pos_sync_serialized_order` filter) ride outside the hash by
construction, so third-party payload noise cannot cause spurious 409s — at the accepted
cost that conflicts on non-schema fields go undetected (concurrent order editing judged
rare; free#1737). The scope is filterable; both hash sites run in the same runtime and
apply the same filters, so site-local customization stays coherent, at the cost of a
one-time self-healing 409 per order when a filter changes.

**Consequences:** the recipe change ships as one transition with a one-release dual-accept
window, then the old whole-payload recipe drops. The four-recipe version list and
legacy-grace comparer that predate 1.10.0 protected no shipped client and are deleted
(free#1745). Cross-collection ratification of schema-scoped fingerprints is pending the
uniformity doctrine (free#1739).

Decided 2026-08-26 with Paul in free#1737, part of the v2 Sync Engine Trust Audit
(free#1731).
