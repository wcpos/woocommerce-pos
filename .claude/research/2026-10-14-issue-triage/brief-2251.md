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

# Issue wcpos/woocommerce-pos#2251 — refactor(auth): POS session lifecycle, the cookie adapter and the blacklist transients

From the 2026-10-12 architecture review (#2182, verdict-auth candidate 1). The August session-lifecycle spec's three rulings still hold. Remaining: the injected cookie adapter (the only way to test `set_web_session_cookie`, since PHPUnit has already printed output when `setcookie()` runs) and two open questions:

- August Q1: mint-then-revoke for web sessions? Today a token-pair failure revokes the old session and leaves an orphan new row (`generate_refresh_token` records at `:483` before `generate_token_pair` fails at `:520`).
- PR-3: drop the blacklist transients now that `Session_Registry::is_live` is authoritative (03a6e47b kept the transient "as the fast path")? Every in-tree `blacklist_token` call pairs with a registry write.

Scope from the verdict: `Services/Auth.php` `get_user_data` 552-597, the revoke cluster 682-872, blacklist/TTL helpers 874-988; the skip at `Test_Auth_Service.php:977-979` becomes a real test through the adapter.



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-auth.md` section 1 ("One module for the POS session lifecycle (Part B, cookie adapter)") and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-auth.md`.
- The August spec the issue refers to: `/Users/kilbot/Projects/woocommerce-pos/.claude/research/2026-08-07-spec-session-context-part-b.md` (untracked, in the main checkout) — read it for the three rulings it says still hold.
- Free: `includes/Services/Auth.php` (`get_user_data` ~552-597, `generate_refresh_token` ~483, `generate_token_pair` ~520, the revoke cluster ~682-872, blacklist/TTL helpers ~874-988, `set_web_session_cookie`), `includes/Services/Session_Registry.php` (`is_live`), commit `03a6e47b` (the transient kept "as the fast path"), `tests/includes/Services/Test_Auth_Service.php` (~977-979, the skip).
- Count every `blacklist_token` caller and whether each pairs with a registry write; count every reader of the blacklist transient and ask what would be lost if it went (a token revoked on a site whose registry row is missing? a token minted before the registry existed?).

## Specific questions
1. August Q1 (mint-then-revoke): what does a user see today when the token-pair mint fails mid-way — are they logged out with an orphan row, and does anything clean the orphan? How often can that mint fail in practice (what throws)?
2. PR-3 (drop the blacklist transients): is `Session_Registry::is_live` authoritative on EVERY validation path, including tokens issued before the registry shipped on an upgraded site? If a path still relies on the transient, name it.
3. The cookie adapter: how many lines, and does it buy a real test or only coverage? Is there a cheaper way to test `set_web_session_cookie` (e.g. `headers_list()` under `@runInSeparateProcess`, or a `wp_headers`-style filter already in the code)?
4. Is the "module" worth doing at all, or is the issue really two small fixes plus one test seam?


Write the report now.
