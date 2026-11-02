# Research brief — should WCPOS pursue this issue?

You are a read-only researcher. You cannot reach the network, Docker or `gh`; everything you need is on disk. Do not run any test suite. `php -l`, `grep`, `git log`/`git blame` and reading files are fine.

## Trees

- Free plugin, `origin/next` tip (92b5d970): `/Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/research-issues-next` (your working directory). The 2026-10-12 architecture review record is at `.claude/research/2026-10-12-arch-review-next/` inside it: `verdict-*.md` (the ruling per area), `findings-*.md` (the raw exploration), `BRIEF.md`.
- Free plugin, `origin/main` tip (9b9f7613, the released line): `/Users/kilbot/Projects/woocommerce-pos`. Read it only to say whether the released line has the same code.
- Pro plugin, `origin/next` tip (82cb93e): `/Users/kilbot/Projects/woocommerce-pos-pro-worktrees/research-issues-next`. Pro vendors Free and extends it.
- WCPOS client app (React Native / web / Electron till, the thing that calls these REST routes), `origin/main` tip of 2026-10-14: `/Users/kilbot/Projects/monorepo-v2-worktrees/research-issues` (`apps/`, `packages/`; the REST client lives under `packages/`). Read it when the question is "does any shipped client exercise this path" or "what does the client assume".
- WCPOS wiki (product and architecture pages, Markdown): `/Users/kilbot/Projects/wiki` (`INDEX.md` at the root).

Lanes: `main` is the released 1.x line (patch fixes only). `next` is the 2.0 feature lane. A defect that exists on `main` and matters to released users targets `main`; everything else targets `next`.

## What to produce

The review that filed this issue was written against an older tip and was deliberately incomplete: several claims are labelled LIKELY or NEEDS REPRO, and every "ruling needed" is a bare question copied from a researcher. Your job is to turn it into a decision Paul (the maintainer) can approve or redirect. Be skeptical: verify each claim against the current tip with `file:line` citations, and say plainly when the review was wrong or when the code has since changed (check `git log` for the files since 2026-10-12).

Write your report to the `-o` path (it is the only output anyone reads). Use exactly these sections:

1. **Claim check** — each claim in the issue: HOLDS / CHANGED / WRONG, with `file:line` on `next` (and on `main` when relevant) and the one-sentence trace. If a defect needs a repro, describe the minimal PHPUnit test (class, fixture, request, assertion) that would fail today; do not run it.
2. **Impact** — who is hit (merchant, cashier, attacker, developer), how often, how bad, and whether any shipped client or admin screen actually reaches the path. Severity on a Low / Medium / High scale with the reason. Cite the client or admin code when you say "nothing reaches it".
3. **Cost and risk** — files, rough line count, tests needed, Pro coupling (does Pro call or extend anything touched?), back-compat exposure (public methods/filters/REST shapes), and the lane each piece belongs to.
4. **Options** — for every "ruling needed" in the issue: two to four options, each with concrete pros and cons drawn from the code, data or product docs at hand. Then state which option the evidence favours and why. If the evidence settles it so clearly that it is not really a product call, say so.
5. **Recommendation** — one of PURSUE NOW / PURSUE LATER (name the trigger: a release, another change, a merchant report) / CLOSE (why it is not worth doing), with the evidence that carries it. Then a proposed scope line: "PR 1 on <lane>: … (~N non-test lines, tests: …)". Keep it to what the evidence supports.
6. **Open questions** — only things you could not settle from disk, each with your best guess and what would settle it.

Keep the report under ~250 lines. Prefer file:line citations over prose. Never invent a file, line, hook name, PR number or version; if you did not read it, say so.


---

# Issue wcpos/woocommerce-pos#2248 — till: a session's store comes from the client (D3); Pro's per-store variance threshold is client-only (D4)

From the 2026-10-12 architecture review (#2182, verdict-till D3 and D4). Store Reach (#2192, #2204, Pro #642) now scopes every till read, but two write-side holes remain.

**D3 — a till session's store comes from the client.** `Sessions_Controller` create takes `store_id` from the request body; it flows into the closure, refund provenance and report authorization. Ruling needed: should the session take its store from its register, and should Free's single store then be NULL or 0 (the client compares `store_id === store.id`)? Then ~15 lines plus a test.

**D4 — per-store variance threshold not enforced server-side (Pro).** `Register_Session_Store` reads the General setting plus `woocommerce_pos_session_variance_threshold`, which nobody hooks; Pro stores a per-store threshold and only the client enforces it. Failing test written in the verdict: Pro `Test_Registers::test_close_refused_above_the_session_stores_threshold` (store threshold 5, General blank, variance 10 → 403 `wcpos_override_refused`). Fix: Pro hooks the filter with the session store's threshold (~12 lines), after D3. Ruling needed: does a blank store threshold mean "none" (client glossary) or "inherit General"?



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-till.md` sections D3, D4 and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-till.md` for the raw trace.
- Free: `includes/API/V2/Sessions_Controller.php` (create: where `store_id` comes from), `includes/Services/Register_Session_Store.php` (variance threshold, the `woocommerce_pos_session_variance_threshold` filter), `includes/Services/Register_Store.php`, `includes/Services/Store_Reach.php`, the closure and refund-provenance writers that copy the session's `store_id`.
- Pro: `includes/Registers.php`, `includes/Stores.php` and wherever the per-store variance threshold is stored and read; Pro's `tests/includes/Test_Registers.php` (does the failing test from the verdict exist yet?).
- Client: how the till creates a session (what it sends as `store_id`, where it gets it), the `store_id === store.id` comparison the issue mentions, and how the client enforces the variance threshold (search `variance`, `threshold`, `store_id`). Say what the client would do if Free returned `null` vs `0` for the store.
- Free's single-store model: what `store_id` a register row carries on a Free site (NULL? 0? 1?), and what `Store_Reach` admits for it (memory from past work: `store_id` is BIGINT NULL, so `<= 0` admits unassigned rows).

## Specific questions
1. Is D3 exploitable by a cashier with a valid token (write a session for a store they are not authorized for, then close it / refund against it), or does Store Reach on the write side already block it after Pro #642? Trace the exact check.
2. For D4 the Free half was NEEDS REPRO: does Free's General threshold actually gate the close server-side today (which route, which error code)?
3. The two rulings: session store from register vs client; blank store threshold = "none" vs "inherit General". Settle them from the client glossary, the settings UI copy, the wiki and the code where possible.


Write the report now.
