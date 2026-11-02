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
| D3 | Order-pay on a `pos-partial` POS sale marks it paid with no row for the new tender (and order-pay charges the full total) | ruled 2026-10-22 (Paul): order-pay refuses a POS sale that holds a captured tender, fail closed; refined to every capture mode but `server`/`device` so Pro's balance-aware terminal panel keeps working; widened to every mode but server/device | **PR #2408, merged 2026-10-22 as 3bcb8605** |
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
| c2-1 note | Merged c2-1 runs `release_hold_if_idle` only when a change ran (spec step 9 says every write); re-check in c2-5 (submit/hold flow). On WooCommerce 10.4.3 a POS cash sale lands on `completed`, not `processing`; tests expect that | from the #2410 implementer report | c2-5 |
| D14 | A failed capture replayed across a one-second boundary answers a later `updated_at_gmt` than the first response: the replay restamps a row that should not change (flaky `Test_Ledger::test_capture_verifies_provider_money` `currency` set on PHP 8.5) | seen on CI #2408 (`currency`) and again on #2410 PHP 8.5 cell 2026-10-22 13:13 (`authorized mismatch`): first answer T, stored/replay T+1s; diagnosis: `apply_result()` returns the pre-save `$applied` while `save()` normalizes again | **PR #2415, merged 2026-10-22 as 981ac04a** (`replace_and_save()` returns the stored row; every answering writer uses it; ticking-clock test, 6 writers; 12/12 lines; Codex no findings) |
| #2377 | Gateway submission takes payment without holding stock when prevent-overselling is on | open issue | own ~20-line PR after c2-5 |
| #2378 | A parked or never-written fiscal sale record has no scheduled retry; `recover()` runs only on a money-command answer | open issue; same gap c4 found | own PR after c4-2 |

## PR sequence

1. `fix(payments)`: defects-ledger — D1 (cancel path), D4, D5, D6, D9 — **PR #2396, merged 2026-10-22 as 66d60279** (`_wcpos_tip_payment_id` declared server-owned and stripped from pushed fee lines)
2. `fix`: defects-lanes — D8, D10 (D11 was already done by #2376) — **PR #2397, merged 2026-10-22 as f7fd4d9b**
3. c7 — **PR #2398, merged 2026-10-22 as 9638516a**
4. c1 (+ D2) — **PR #2400, merged 2026-10-22 as 06caef3e**
5. c4-1 (completion-history read model; independent of c2) — **PR #2401, merged 2026-10-22 as f78bc97c**
5b. c8 (one pushable-document step + D13) — **PR #2404, merged 2026-10-22 as 22542bf6**
6. c2 slices 1–3 (design chosen from three alternatives; spec beside this plan; ADR amendment approved by Paul 2026-10-22) — c2-1 **PR #2410, merged 2026-10-22 as 712efb1d** (five Codex rounds, 11 threads all fixed; final 596 lines; GitHub's commits/:sha/pulls endpoint 500'd intermittently that afternoon and the review gate needed reruns) (branch `feat/adr-0040-c2-1-ledger-write`; 584 production lines after Codex round 1 (four accepted findings: S2 decided from storage + staged order props verified, `$before` kept across the S1 retry, heal verifies its completion, zero-total close heals with the `_wcpos_payments`-exists guard for upgraded 1.11 orders; `is_writing()` moved to the c2-2 carry under the same split ruling); round 2: awaiting-customer stamp clear moved from S1 to S3 (`finish()`); round 3: no-change fast path only when S2 will not run, and I3 refuses the WordPress order-save hook family too — the shared hook list (115 lines, `includes/Services/Save_Hooks.php`, read by `Save_Reentry_Guard`) would have taken the PR to 707, so it landed FIRST as its own PR — **#2412, merged 2026-10-22 as efea3c3d** (split, budget not raised; Codex no findings) — after which c2-1 sits at 592 (round 3 pushed as b86a25be); round 4: S1 reads back the three payment indexes; heal repairs a WooCommerce paid status that lost `date_paid` (sets it under lock, `wc_maybe_reduce_stock_levels`, S3) but never a per-gateway status without `date_paid` (Paid_Date_Suppression leaves that on an UNPAID write) — 607 lines, so `Ledger_Draft::drop()` and `MODE_HOLD` also moved to the c2-2 carry (no caller in c2-1; same split ruling) → 594; full suite green; old branch `feat/adr-0040-ledger-write` with a wip commit left on the remote for Paul to delete). **Split ruled by Paul 2026-10-22:** c2-1 ran to ~737 lines against 600, so `defer()`, its flush points and the H2 meta helper move to c2-2 (`c2-2-carry.patch`). Consequence: the Pro companion (`Order_Status_Void` → `Ledger::defer()`) needs `defer()` in Free first, so it merges AFTER c2-2 and before c2-6, not before c2-2 as the spec table says.
6a. c2-2 (`defer()` and its three flush points, the H2 meta-only helper, the webview mint / `mints_attempt` deferred, stamp-clear in before-save, the c2-1 carry) — branch `feat/adr-0040-defer-in-hook-writers`, PR #2420, 351 production lines; the Pro companion (`Order_Status_Void` → `Ledger::defer()`) merges AFTER it and before c2-6, then run Pro's `Test_Order_Status_Void` by hand against Free `next`.
6b. c2-3 (`record`, `close`, the record replay and the sweeper onto `write()`; a covering write's command entries carry `phase: rows` from S1 until S3 advances them, and only a replay owing that phase, a close or the sweep (`MODE_SWEEP`) completes a sale its rows already covered; a close replay owing it heals through `write( MODE_CLOSE )`; `remember_close` deleted) — branch `refactor/adr-0040-record-close-sweeper-on-write`, PR #2427, 217 production lines.
7. c3 (needs c2-3 and c7), then c2 slices 4–6
8. c4-2 (after c2-5), c5, c8, c6; then the #2377 and #2378 PRs

Refs #2389 (closed by c2), #2377, #2378 (overlaps D7/D4). Specs for each PR live beside this plan.
