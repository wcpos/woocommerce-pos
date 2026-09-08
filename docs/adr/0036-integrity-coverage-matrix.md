---
status: accepted — implementation phased in 1.10.x (free#1756)
---

# Integrity coverage: every collection, four capabilities, no accidental absences

Integrity is four capabilities — detection (digests), drill-down, self-heal, and the
formula fingerprint — and every collection gets a deliberate answer for each, recorded in
the registry (a `repair` group; fingerprint as a modeled capability). An absent capability
is an explicit registry null with a reason, never an accident: unmodeled capability is
where the 1.10.0 accretion bred (free#1732).

**Fingerprints are universal.** Every collection's serialization has a recipe, and recipes
change (free#1710); the contract-version lever therefore exists for all collections,
degenerating to the version constant alone where no settings inputs exist yet. The
alternative — exempting "simple" collections — is how changing `CUSTOMER_DIGESTED_META_KEYS`
came to silently invalidate every stored customer digest with nothing to trigger a rebuild.

**Detection extends to the previously uncovered rows** — terms, coupons, tax_rates. Terms
had no `modified_after` (WP terms carry no date) and no digest, so a direct-SQL category
import was invisible forever. tax_rates is the highest-stakes drift despite the smallest
table: a stale rate silently miscalculates every sale, and POS-vs-wp-admin divergence is a
bug by definition.

**Self-heal is automatic but never silent.** Heals run unattended and are logged with
counters on the health endpoint — a store that heals 400 products a night has a chronic
drift source to find, not a symptom to keep quietly patching.

**Order integrity is window-scoped**, tracking the `windowed` replica policy: guarantees
cover what the client holds; full-history reconciliation of an unbounded order table is the
crash scenario the replica policy exists to prevent.

Decided 2026-08-26 with Paul in free#1742, part of the v2 Sync Engine Trust Audit
(free#1731).
