# Plan: ADR 0040 follow-through — deepen the money model and fix the defects found in review

**Date:** 2026-10-22 · **Lane:** `next` · **Owner:** the orchestrating session; one implementer at a time (one wp-env at a time) · **Stakes:** checkout-money, High (a wrong outcome prompts a second sale or loses a tender).

## What

An architecture review of the ADR 0040 money model on `next` (2026-10-22, after PRs #2336, #2349, #2352, #2355, #2362, #2365, #2366, #2368, #2370) found the shape the ADR itself warned about: rules applied by hand at many sites. Command idempotency is remembered at ~18 sites, payment rows reach storage by three routes, the Sale-state question is answered in four places with five drifting status lists, and completion provenance travels through static context and hook priorities. This plan tracks the deepening programme and the defects found on the way. Vocabulary: `GLOSSARY.md` (domain) and the codebase-design terms module, interface, seam, adapter, leverage, locality.

## Deepening candidates (one or more PRs each, ≤ 600 added production lines, never stacked)

| # | Candidate | Strength | Depends on |
|---|---|---|---|
| c1 | Collapse the Sale-state question ("may this sale be reopened / edited / cancelled / deleted / closed; what money is in motion") into one `Ledger::sale_state()`; `Order_Writer` keeps only a translator; delete joins the gate under the order lock (D2) | Strong | — |
| c7 | One order-revision read and one comparison for both lanes; no wire change | Strong | — |
| c2 | One locked write for payment rows (a unit of work): one write seam, fresh order under the lock, read-back against the intended rows; `stamp_closure` and the before-save keeper become callers. **This is the spec #2389 asks for.** | Strong | — |
| c3 | One command lifecycle for ADR rule 5 (replay-or-run, remember with the effect, forget on definite failure, verify landed) | Strong | c2 |
| c4 | Completion as an explicit call at the paid transition with provenance passed in; one completion-history read model | Strong | c2 |
| c5 | An Attempt module owning attempts, the awaiting stamp, provenance and resolution (D1 sweeper index) | Worth exploring | c2 |
| c8 | One pushable-document step for ADR rule 2 | Worth exploring | c2 (keeper) |
| c6 | One order-held document store (HPOS knowledge in one place) | Worth exploring | c2 |

## Defects found (each fixed test-first)

| # | Defect | Status | PR |
|---|---|---|---|
| D1 | A `sent` attempt stranded by a throw after dispatch is never resolved; `cancel` needs a stamp it never got; close/reopen/cancel refused forever | confirmed; cancel path fixed in #2396 (refuses when the order shows paid facts the rows do not cover) | resolver in c5; variant: invoice sent then order cancelled by staff leaves `sent` with no way to clear → c5 |
| D2 | Push-lane delete has no money gate and no order lock | confirmed | c1 |
| D3 | Order-pay on a `pos-partial` POS sale marks it paid with no row for the new tender (and order-pay charges the full total) | confirmed; product decision with Paul | — |
| D4 | `Ledger::stored()` throws `RuntimeException`; the route catches only `Ledger_Persistence_Exception` → 500 instead of a retryable 409 | confirmed | defects-ledger |
| D5 | On-reader tip fee is saved before its row; a failed row save then a retry adds a second fee; the "carry unsaved items" block in `save()` is unreachable | confirmed | defects-ledger |
| D6 | Same-id `submit` replay answers `paid`/`payment: null` where the first answer was `recorded` with the row | ruled 2026-10-22: a replay whose first answer was `recorded` replays `recorded` with its row (ADR rule 5); `paid` stays for attempts first answered `sent` | defects-ledger |
| D7 | On the capture path, a ledger write that fails after `payment_complete()` leaves the money taken, the order completed, the row `pending`, a sale record naming no payment, and the retry refused as stale | confirmed red by probe in PR #2396 (test kept in scratchpad `d7-probe-test.php`) | c2-3 (write-then-verify ordering is the fix); probe becomes a c2 test |
| D8 | Live and reprinted receipts for completion n≥2 print completion 1's register/counter/time | confirmed | defects-lanes |
| D9 | Sweeper captures an authorization on an order its captured tenders already cover | confirmed | defects-ledger |
| D10 | Address-only pushes skip the order lock though wc/v3 recalculates totals on them | confirmed | defects-lanes |
| D11 | Dead `Write_Controller::wcpos_check_permissions` (deprecated, no callers in Free, Pro or the extensions) | confirmed | defects-lanes |
| D12 | Pro's prompt-answer route returns rows through `Ledger::to_wire()` with their `commands` map, leaking command ids and fingerprints to the till | confirmed by reading | c3 (+ Pro companion) |
| D13 | `Ledger::stored()` and `Payment_Index::order_id_for_payment` matched the meta key case-insensitively (collation), so a pushed `_WCPOS_PAYMENTS` row with a forged captured row was read back as the ledger; `Core_Order_Audit_Guard` had the same exact-compare hole | confirmed red on both storages in #2404; fixed in readers (byte-exact compare) and strip (case-insensitive match) | c8 (#2404) |
| #2377 | Gateway submission takes payment without holding stock when prevent-overselling is on | open issue | own ~20-line PR after c2-5 |
| #2378 | A parked or never-written fiscal sale record has no scheduled retry; `recover()` runs only on a money-command answer | open issue; same gap c4 found | own PR after c4-2 |

## PR sequence

1. `fix(payments)`: defects-ledger — D1 (cancel path), D4, D5, D6, D9 — **PR #2396**
2. `fix`: defects-lanes — D8, D10 (D11 was already done by #2376) — **PR #2397, merged 2026-10-22 as f7fd4d9b**
3. c7 — **PR #2398, merged 2026-10-22 as 9638516a**
4. c1 (+ D2) — **PR #2400, merged 2026-10-22 as 06caef3e**
5. c4-1 (completion-history read model; independent of c2) — **PR #2401, merged 2026-10-22 as f78bc97c**
5b. c8 (one pushable-document step + D13) — **PR #2404, merged 2026-10-22 as 22542bf6**
6. c2 slices 1–3 (design chosen from three alternatives; spec beside this plan; needs Paul's yes on the ADR amendment)
7. c3 (needs c2-3 and c7), then c2 slices 4–6
8. c4-2 (after c2-5), c5, c8, c6; then the #2377 and #2378 PRs

Refs #2389 (closed by c2), #2377, #2378 (overlaps D7/D4). Specs for each PR live beside this plan.
