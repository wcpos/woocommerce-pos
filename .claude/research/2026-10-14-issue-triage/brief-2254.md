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

# Issue wcpos/woocommerce-pos#2254 — chore: the duplicated blocks from the 2026-10-12 smell sweep

From the 2026-10-12 architecture review's smell sweep (#2182). Duplicated blocks the verdicts did not take up; each is a bounded dedup with no behaviour change:

- `Escpos_Thermal_Emitter.php:167-476` ↔ `Starprnt_Thermal_Emitter.php:175-481` (~98 shared lines; what #2012 left), plus `Epos_Xml:192-223` and `Star_Markup:96-118` against them.
- `V1/Product_Variations_Controller.php:140-196` ↔ `V1/Products_Controller.php:133-201` (24 lines; the same pair in Pro).
- `V1/Customers_Controller.php:530-600, 739-828` ↔ `Proxy/Customers_Proxy_Behavior.php:112-221` (role ladder, CASE, last_update, orderby).
- Two print counters with different concurrency; paper-width parsing ×2; engine→language map ×2; closure-print capability ×2.
- Three decimal validators that disagree; business-day derivation ×2 (`Activator.php:308` ↔ `Report_Scope_Resolver.php:105`).
- IP resolution `Session_Context.php:123-154` ↔ `Templates/Auth.php:540-564`, diverging (`''` vs `'unknown'`).
- Manager password approval ×2 plus a JWT variant (`Sessions_Controller`, `Closures_Controller`).

Each needs a characterization test first, then the dedup; pick them one at a time.



---

## Where to look first
- The smell sweep in `.claude/research/2026-10-12-arch-review-next/BRIEF.md` / the findings files (search for "smell" and the file pairs named in the issue); commit history of #2012 (`git log --grep 2012`) to see what dedup the thermal emitters already received.
- For every pair in the issue: open both files on the tip and confirm the duplicated range still exists (line numbers moved; re-cite), measure the shared span, and say whether the two copies have DIVERGED (different behaviour — a latent bug) or are identical.
- Pro: the same `Products`/`Product_Variations` pair the issue says exists in Pro.

## Specific questions
1. For each item: identical or diverged? If diverged, is one side wrong (that is a defect to fix, not a dedup)? The IP-resolution pair (`''` vs `'unknown'`) and "three decimal validators that disagree" are the likely ones; show the disagreement with inputs that produce different results.
2. Rank the items by (risk of the duplication causing a bug) × (ease of dedup), and say which, if any, are worth a PR on their own. A dedup with no diverged behaviour and no upcoming change in the area is usually CLOSE; say so where it applies.
3. The thermal emitters: how much of the ~98 lines is genuinely shared logic versus table-driven data, and would a base class or a trait fit the existing `Emitter` shape without changing any byte of output? Note the receipt-template rule: no new template logic; this is PHP only.


Write the report now.
