---
status: accepted — compute-at-pull landed (free#1745 landed the retirement; free#1746 landed compute-at-pull; the schema-scoped recipe rides the 1.11.0 gate)
---

# Order revision: content hash, computed at pull time, never stored

The order revision — the `baseRevision` a client echoes for optimistic concurrency — stays a
sha256 content hash, the same paradigm as every other CAS'd collection, but is to be computed
at pull time from the exact payload being served and never persisted. Under this decision the
journal row's `revision` column is written empty for orders and `/orders/pull`'s computing
fallback (already shipping for pre-backfill rows) becomes the only revision source; that
change landed in free#1746 (legacy rows keep serving their stored hashes through the
planner's stored-wins branch until they age out). Write-side CAS is unaffected either way: it has
always recomputed from a fresh re-read and never consulted the stored value.

**Why not stored-at-save (the 1.10.0 design):** the write-time hash serializes the full
order on every `woocommerce_update_order` — four times per checkout — to feed a column with
a single reader that already has a computing fallback. Worse, changes that skip the save
hook (certain HPOS meta-only saves, trash transitions) leave a stale stored hash mismatching
the served payload, a false-409 class that pull-time computation makes structurally
impossible. The origin of the stored design was an unchosen lab default, not a decision
(free#1733).

**Why not a date-based revision (closed permanently, not deferred):** CPT order storage is
supported, and on the CPT store most order edits never move `post_modified` — a
`date_modified` revision would be silent for the majority of CPT edits (verified against
WooCommerce 11.1.0-beta.2 source; free#1746 pins it with a test). HPOS adds
second-granularity collisions (one checkout's four saves share a second) and a
caller-supplied-date hole (`wc_create_refund`).

**Fingerprint scope (lands with free#1746):** the hashed form becomes the bare, pre-filter
payload restricted to the top-level fields of WooCommerce's own REST order schema, plus the
existing volatile-field strips. Third-party additions (`register_rest_field`, prepare-filter
injections, the pull-only `woocommerce_pos_sync_serialized_order` filter) ride outside the
hash by construction, so third-party payload noise cannot cause spurious 409s — at the
accepted cost that conflicts on non-schema fields go undetected (concurrent order editing
judged rare; free#1737). The scope is filterable; both hash sites run in the same runtime
and apply the same filters, so site-local customization stays coherent, at the cost of a
one-time self-healing 409 per order when a filter changes.

**Consequences:** free#1745 (landed with this ADR) deletes the pre-1.10.0 versioned recipe
list and legacy-grace comparer — they protected no shipped client, and their generic
recipe-migration machinery goes with them. Compute-at-pull ships in 1.10.x with the current
whole-payload recipe (byte-identical on the wire). One side effect moved with it: the
write-time serialization also minted order and line-item identity uuids on every save, so
identity meta for a newly saved order is now first persisted at pull time (the concurrent
first-stamp race was already handled; see `Pos_Uuid`). The schema-scope recipe change is a wire
transition and ships at the 1.11.0 protocol-gate boundary decided in free#1750 — the gate
makes a dual-accept window unnecessary (superseding this ADR's earlier one-release
dual-accept plan). Cross-collection ratification of schema-scoped fingerprints is pending
the uniformity doctrine (free#1739).

Decided 2026-08-26 with Paul in free#1737, part of the v2 Sync Engine Trust Audit
(free#1731).
