---
status: accepted
---

# The record-uniformity doctrine

A collection is a record like any other. The sync engine has one paradigm, and every
collection rides it:

- **Signal**: the journal pointer stream (`/changes/sequence-log`) — every collection's
  changes, orders included once the pull lane retires (free#1748).
- **Hydration and seeding**: the collection's `wc/v3` read surface — real WooCommerce
  controllers, so third-party prepare/query/field hooks run (the hook-parity contract,
  ADR 0035). Seeding strategy is the client's replica policy, not a server lane.
- **Revision**: a schema-scoped content hash, computed at read time, never stored,
  filterable (ADR 0033, ADR 0034). CAS compares strictly; a mismatch is a 409.
- **Writes**: forwarded through the collection's `wc/v3` controller.
- **Capabilities are registry data, never code forks**: identity, write, proxy, journal,
  digest, repair, fingerprint (universal — ADR 0036), envelope, and replica policy
  (`complete` | `complete-by-trickle` | `windowed`) are groups on the collection's
  registry row. An absent capability is an explicit null with a recorded reason.

**Divergence criteria.** A collection may diverge from the paradigm only when WooCommerce's
own storage forces it (HPOS keeps orders outside `wp_posts`, so identity and digest
plumbing differ by necessity) or the domain forces it (orders are the only composite
record — line items carry their own uuids) — and in every case the divergence is expressed
as a registry capability or an ADR, never as an undocumented parallel code path.
"Plausible" is not a justification: every 1.10.0 divergence had a plausible story and none
had a recorded decision (free#1733 — the order revision design was an unchosen lab
default). If a reason is real, it survives being written down.

**Storage-abstraction policy.** Single-record correctness paths use WooCommerce's CRUD and
data stores — plugins should not know about tables. Raw SQL is permitted only in
scan/aggregate lanes (digests, bulk uuid reads) where per-object hydration is prohibitive,
and each raw surface is a recorded migration cost for the day WooCommerce moves a
collection's storage the way it moved orders.

**Divergence ledger (2026-08-26).** Orders: windowed replica (registry policy); pull lane
retiring at the protocol boundary (free#1748, free#1750). Variations: wrapper envelope and
date-based revision both retire at the boundary (free#1736); replica policy
complete-by-trickle. tax_rates: keyed by Woo id, not uuid — the one identity exception
(lab ADR 0009). The raw-SQL selection bypasses on `/orders/pull` and `/resolve/barcode`
are temporary, seam-covered, and die with their lanes (ADR 0035).

Decided 2026-08-26 with Paul in free#1739, closing the v2 Sync Engine Trust Audit
(free#1731).
