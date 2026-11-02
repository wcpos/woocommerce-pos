---
status: implemented (query-filter fix in 1.10.x; revision + envelope landed on next for 1.11.0, free#1869)
---

# Variations: a first-class record on the house paradigm

A variation is a record like any other — it just happens to have a parent. This ADR retires
the last three places the engine treated it otherwise, decided in free#1736.

**Revision:** variations move from the client-synthesized `date_modified_gmt` — the engine's
last date-based revision — to the house paradigm: a content hash over WooCommerce's variation
REST schema fields (filterable), computed at read time, never stored. Grounds: variations are
the collection most likely to be edited concurrently (stock sync, wp-admin, another till) and
had the weakest conflict detection (1-second date granularity; blindness to meta-only edits).
The switch landed on `next` for 1.11.0 behind the protocol-2 gate; those clients
already consume the stamped hash. `Variation_Writer::document()` now supplies bare
bytes before response augmentation; the flat and barcode lanes use the same recipe.

**Envelope:** the `documents[].{id,parent_id,payload,_rxdb_digest}` wrapper was a lab-era
accident. It is removed on `next` for 1.11.0: variation rows and `/resolve/barcode`'s
`match` are bare wc/v3 records, with transport stamps and UUID metadata.

**Replica policy: complete, seeded by idle trickle.** Defer-hydration made offline
completeness impossible (a barcode scan of a never-opened variation fails offline). An idle
POS trickles all variations in via the flat `/variations` route — WooCommerce's own
cross-parent collection query, visibility-filtered and uuid/digest stamped — parents-first;
the pointer stream keeps them current thereafter. The registry's `proxy: null` remains true
(no wcpos proxy lane) with the flat route recorded as the list/seed lane; the folded digest
id-space and absent bulk reader remain, recorded as principled.

Decided 2026-08-26 with Paul in free#1736, part of the v2 Sync Engine Trust Audit
(free#1731).
