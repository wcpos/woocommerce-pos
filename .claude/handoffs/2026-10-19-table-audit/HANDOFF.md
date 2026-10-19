# Handoff: custom-table audit for the WCPOS free plugin

**Date:** 2026-10-19 · **Lane:** `next` (the tables under review live there) · **Owner of the decision:** Paul · **Stakes:** High (schema on every merchant's server)

## Why this exists

Paul's ruling, 2026-10-19, now a standing rule for every agent in this repo:

> **Every new database table has to earn its place.** A table changes the whole architecture of a merchant's server (install DDL, upgrades, backups, HPOS, multisite, host tooling, support). A table is the last resort, after an existing WCPOS table, order or product meta, an option, a WooCommerce construct, or a JSON column on an existing WCPOS row. The decision is Paul's, before the table is briefed, and the PR body carries the written justification.

The plugin went from **three** custom tables on `main` to **eight** on `next` between 2026-07-14 and 2026-09-12. Nobody wrote the justification paragraph for the later five; they arrived with the register, session and fiscal work. A ninth (`wcpos_money_holds`) is proposed by the wiki for one edge case and has NOT been built. This audit decides which of the eight stay, which fold into others, and what the migration costs.

## The eight tables (as of `origin/next`, 2026-10-19)

| Table | Class | Landed | One row per | Readers (non-test) |
|---|---|---|---|---|
| `wcpos_sync_journal` | `includes/Sync/Sync_Journal.php` | 2026-08-13 `13f51d2d` | change event (type, id, sequence, revision, deleted) | `Changes_Controller`, `Orders_Controller`, `Order_Query`, `Visibility_Observer`, `Sync_Journal_Purge` |
| `wcpos_sync_mutations` | `includes/Sync/Mutation_Store.php` | 2026-07-14 `5b9999a6` | applied mutation id → server id, status, fingerprint | `Write_Controller`, `Collection_Writer_Resolver`, `Sync_Journal_Purge` |
| `wcpos_sync_stored_digest` | `includes/Sync/Integrity_Digest.php` (via `Digest_Index`) | 2026-07-14 `5b9999a6` | product / variation (raw-row digest) | `Integrity_Controller`, `Augmentation_Pipeline`, `Pos_Uuid`, `Init` |
| `wcpos_registers` | `includes/Services/Register_Store.php` | 2026-09-11 `2c42eed5` | register (till) | `Registers_Controller`, `Sessions_Controller`, `Receipt_Data_Builder`, `Report_Scope_Resolver`, `Provenance_Health`, closure and session stores |
| `wcpos_register_sessions` | `includes/Services/Register_Session_Store.php` | 2026-09-12 `b0d2743c` | session (open/close, floats, counts, approvals, LONGTEXT blobs) | `Sessions_Controller`, `Closures_Controller`, `Fiscal_Record_Writers`, `Report_Scope_Resolver`, `Provenance_Health`, cash and closure stores |
| `wcpos_cash_movements` | `includes/Services/Cash_Movement_Store.php` | 2026-09-12 `b0d2743c` | paid-in / paid-out within a session, with void stamp | `Sessions_Controller`, `Session_Figures`, `Closure_Store` |
| `wcpos_closures` | `includes/Services/Closure_Store.php` | 2026-09-12 `52a945ee` | closed session document (Z report), `UNIQUE (register_id, number)`, `UNIQUE session_id` | `Closures_Controller`, `Reports_Controller`, `Sessions_Controller`, `Closure_Csv`, `Closure_Print_Counter`, `Report_Scope_Resolver` |
| `wcpos_fiscal_records` | `includes/Services/Fiscal_Record_Store.php` | 2026-09-11 `55d3a66a` | numbered fiscal record (sale, refund, void, correction, late_*) with checksum, `UNIQUE (type, series, number)` | `Records_Controller`, `Receipts_Controller` (v1), `Templates/Receipt`, `Receipt_Data_Builder`, `Fiscal_Record_Writers`, closure and cash stores |

All eight are installed by `includes/Activator.php` through `dbDelta` and dropped by `uninstall-functions.php` (sync tables always; the five register/fiscal tables are listed there too — verify under which wipe mode). Two retired tables (`wcpos_sync_change_log`, `wcpos_sync_order_index`) are dropped on activation.

Schemas: run `bash tests/bin/…` is not needed; each class has a `schema_sql()` method. The orchestrator's first-pass read is in the table below; **verify it, do not trust it.**

## First-pass verdicts (to be confirmed or overturned)

| Table | First-pass verdict | Reasoning to check |
|---|---|---|
| `wcpos_sync_journal` | Keep | Pull sync is a pointer stream over a global monotonic sequence that must record deletes after the object and its meta are gone. No WP construct gives that. |
| `wcpos_sync_mutations` | Keep | Idempotency must survive record deletion and span collections whose meta tables differ. Also the right home for orderless money holds (the refused mutation row IS the hold) — so `wcpos_money_holds` is NOT needed; the wiki page `architecture/client/cart-settlement-money-holds.md` should be amended. |
| `wcpos_sync_stored_digest` | Questionable | A derived cache so the integrity scan can detect hook-bypassing writes with a GROUP BY. Doubles rows per product. Alternatives: postmeta digest (same rows, slower join), or drop the feature. Decide whether SQL-bypass detection is worth a table at all. |
| `wcpos_registers` | Marginal | A handful of rows. A CPT is more rows; a serialized option serves most stores. Table only pays off with many registers or when sessions/closures need a FK-like index. |
| `wcpos_register_sessions` | Keep if the feature stays | One row per session, queried by register and status; a CPT would be one post plus ~15 meta rows. |
| `wcpos_cash_movements` | Fold into sessions | Append-only entries per session with a void stamp. The session row already carries LONGTEXT blobs (`counted`, `approved_counted`, `approved_expected`); movements can be one more JSON column, written under the session lock. Cost: read-modify-write and losing the `voids` index. |
| `wcpos_closures` | Fold into sessions | One-to-one with `session_id` (UNIQUE) and repeats the session's open/close/by/counted/expected fields. A closure is a closed session; number, totals, breakdowns, findings and print bookkeeping can be columns on the session with immutability enforced in code (`Owned_Table` write-once rules already exist). |
| `wcpos_fiscal_records` | Keep where fiscal records are required | Gapless numbered series across orders, refunds and corrections; checksummed; append-only; must survive order deletion. Order meta cannot give a series across orders. Check whether it is created unconditionally or only when fiscal mode is on. |

Target shape if the verdicts hold: **five tables** (journal, mutations, registers, sessions, fiscal records), with the digest optional or gone.

## What the agent must do

1. **Read, do not assume.** For each table read `schema_sql()`, every reader listed above, the wiki pages that specify it (`architecture/client/fiscal-groundwork/*.md`, `architecture/client/register-sessions/records-and-rules.md`, `architecture/plugin-free/receipt-snapshots-and-adapters.md`; pull the wiki clone first), and the ADR if one exists under `docs/adr/`.
2. **Write the justification paragraph for each table** in the form the rule now demands: what it stores, one row per what, why each alternative fails (existing WCPOS table / order or product meta / option / WooCommerce construct / JSON column on an existing WCPOS row), expected row growth per 1,000 sales, install and uninstall behaviour, HPOS and multisite behaviour. If the paragraph cannot be written honestly, the table does not earn its place.
3. **For every "fold" or "drop" verdict, cost the migration:** `dbDelta` column additions on the surviving table, a one-shot data move in a versioned upgrade file (`update-<version>.php` runs AT MOST ONCE EVER — see the memory note on versioned upgrades), the readers to rewrite, the tests to move, the uninstall list, and what a dev-build site that already has the old tables experiences. State whether the move can be done before the next release so no merchant ever sees the eight.
4. **Check the money-holds proposal** against the mutation store: confirm `wcpos_sync_mutations` can hold an orderless hold (status, uuid, mutation id, payload fingerprint) and draft the wiki amendment that replaces the `wcpos_money_holds` table with it.
5. **Produce a recommendation per table** (keep / fold into X / drop) with the one question Paul must answer for each contested one, and a proposed PR sequence (one PR per fold, each off `origin/next`, no stacked PRs).
6. **Do not build anything.** This is read-only analysis. The only writes are the report under this folder (`REPORT.md`) and a comment on the tracking issue.

## Constraints and rules in force

- `next` is the live lane; branch worktrees from `origin/next`; `main` is release-only.
- Read-only analysis agents may fan out; anything that builds or runs a suite is one at a time (`--maxWorkers=2`).
- Order data goes through the order object, never `*_post_meta()` on an order id (HPOS).
- No new table, column or option in any proposal without flagging it as Paul's call.
- Report in Blocked on you / Changed / Found. Every contested verdict is ONE question with a pick, not a list.

## Pointers

- Memory rule: `~/.claude/projects/-Users-kilbot-Projects-woocommerce-pos/memory/every-new-table-must-earn-its-place.md`
- Install path: `includes/Activator.php` (~line 345, `dbDelta` per store); write-once base: `includes/Services/Owned_Table.php`
- Uninstall list: `uninstall-functions.php` lines 21–42
- Tracking issue: https://github.com/wcpos/woocommerce-pos/issues/2321
