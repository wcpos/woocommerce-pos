# Issue triage — the ten items left open by the 2026-10-12 architecture review

Date: 2026-10-14. Orchestrator: Claude (Fable 5.1). Researchers: ten read-only GPT-6 Astra runs (`codex exec -s read-only`, effort high), one per issue, each against Free `next` 92b5d970, Free `main` 9b9f7613, Pro `next` 82cb93e, the client at c5def3c6 (`main`, and `next` where the till lives) and the wiki. Briefs are `brief-*.md`; reports are `report-*.md`. Verdicts below are the orchestrator's, after reading every report; where a report and the issue disagree, the report's `file:line` trace was taken as the fact.

## Verdicts

| Issue | Verdict | Lane | Size | Why |
|---|---|---|---|---|
| Free #2248 till session store (D3), Pro per-store threshold (D4) | **PURSUE NOW** | `next` (Free, Pro, client) | ~25 + ~25 + 3 lines | A cashier can open a session on an authorized register but name any store; the forged store flows into closures, refund provenance and report authorization. All three rulings settled by the code and the client glossary: store comes from the register, unassigned stays NULL (client recovery normalizes NULL/0), blank store threshold means none. Review's Free half of D4 was wrong: General already gates the close (test since 2026-09-12). |
| Free #2252 open redirect (D2), rate limit (D3) | **PURSUE NOW** | `main` | ~60 + ~90 lines | Both files byte-identical on both lanes. D2 is worse than "LOW": a phishing link to the real store's auth page hands the attacker a renewable access+refresh pair for any HTTPS host. D3: five bad POSTs from anywhere lock any cashier's identifier for 15 min. **Two contested picks for Paul** (allowed origins; lockout keying). |
| Free #2253 `Ledger::record()` raw keys | **PURSUE NOW, narrowed** | `next` | ~40 lines | An unknown `method` key reclassifies a card leg as cash in `Session_Figures`; client `events` and `void_requested_at` survive a cashier request. Receipt/closure totals are not inflated (they read `amount`). Also found: a client `tip` makes an identical replay 409. Not on `main`. |
| Free #2250 Order_Write_Payload rulings | **PURSUE NOW, docs + pins only** | `next` | ~30 non-test lines | All four rulings settled by the code; the `cart_restart_gmt` docblock's `needs_payment()` claim is wrong (Free returns true for 0-total `pos-open`). Three characterization tests. No behaviour change. |
| Pro #652 2.0.0 migration `__()` too early | **PURSUE NOW, narrowed** | Pro `next` | ~10 lines | Holds, but only with a published store lacking a register and a loadable catalogue, so not "every upgraded site". Defer that one backfill to `init@-1` (not default priority: Pro must still seed before Free's own default register at `init@0`). |
| Pro #654 Pro-only uninstall; StoreInterface | **PURSUE NOW** | `main` (uninstall), `next` (interface) | ~100–140 + ~389 (deletion) | Pro's uninstall leaves Free's three sync tables, `db_version`, the secret keys and several cron hooks on a Pro-only site, on the released line too; an explicit full wipe is ignored. Fix: reuse Free's uninstall under a "no standalone Free remains" guard. StoreInterface: zero consumers anywhere on disk (every extension checkout searched); delete in 2.0 with a changelog line. |
| Free #2251 session lifecycle | **PURSUE LATER; one residual fix now** | `main` | ~80 lines | Core premise superseded by 2830d8cf (2026-10-13: reuse, no revoke-on-reissue). Blacklist transients stay: `is_live()` is not authoritative for unlinked access tokens and `blacklist_token()` is a tested public contract. Residual defect: a failed token mint leaves a phantom session visible for up to 30 days. No module, no cookie adapter. |
| Free #2249 Template_Type module; D1 | **PURSUE LATER; D1 fix now** | `next` | ~50 lines | "32 literal sites" is one constant plus 29 real per-type rules; #2198 already removed the demonstrated preview defects; the module waits for a new type or an engine-policy change. D1 is real (scoped display list marks first-enabled, not Live) but no first-party screen reads it; fix independently with a thin store-template-id getter, keep the GET pin. |
| Pro #653 idempotency, registries, legacy refund path | **(a) and (b) CLOSE; (c) needs a ruling** | Pro `next` | ~230 lines (deletion) | (a) is not a safe swap: `claim()` and `reserve()` differ on stale boundary, malformed options and cached replay, and `Order_Refunds_Controller` injects Pro's repository. (b) has no operational benefit. (c): no caller anywhere on disk; released, documented PHP extension point, so retiring it in 2.0 is **Paul's call**. |
| Free #2254 smell-sweep duplicates | **CLOSE as a programme** | `next` | ~15 lines (one fix) | No duplicate is behaviour-neutral: the two IP resolvers feed different policies, the three decimal validators have different scales/signs, the two print counters use different storage. One real but practically unreachable defect: a register default float above 2^53 loses its fraction through `wc_format_decimal`. |

## Contested rulings (asked one at a time, options → pros/cons → pick)

1. #2252 D2: which redirect destinations may receive tokens. Pick: configured site origin + exact `https://wcpos.expo.app` + the existing app schemes, plus a narrow code-level exception for preview/local builds.
2. #2252 D3: lockout keying. Pick: canonical account + `REMOTE_ADDR` buckets, forwarded headers ignored for enforcement, username/email merged.
3. Pro #653 (c): retire the legacy refund resolver injection in 2.0. Pick: yes, with a breaking-change note.

## Routine decisions (made by the orchestrator, stated here)

- #2248 store from register, NULL for unassigned, blank threshold = none.
- #2250 all four rulings: record current behaviour (v1 update ignores `date_created_gmt` and strips provenance; v1 never stamps `_pos_payment_asserted`; a combined restart+checkout takes the restart date; the docblock is corrected).
- #2253 allowlist excludes `events`, `void_requested_at`, `method` and unknown keys; preserves documented offline fields, `provider_refs`, `receipt`, `tip` pending the 2.0 client payload check.
- #2249 keep the GET Live pin; getter only, setter when Pro adopts it.
- Pro #652 `init@-1`, synchronous when `init` already fired; accept latch-before-backfill (manual repair exists).
- Pro #654 reuse Free's cleanup under the guard; delete StoreInterface in 2.0.
- #2251 only the orphan-session cleanup; keep blacklist transients.
- #2254 close the dedup list; fix the float precision only.

## Order of work once approved

1. #2252 (`main`, security, two PRs + client probe tweak) — after the two rulings.
2. #2248 (`next`: client first, then Free, then Pro).
3. Pro #654 uninstall (`main`, Free then Pro).
4. #2253, Pro #652, #2249 D1, #2250, #2251 residual, #2254 precision — small PRs, any order.
5. Pro #653 (c) and StoreInterface deletion — 2.0 breaking-change PRs, after the ruling.
