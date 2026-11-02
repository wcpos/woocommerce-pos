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

# Issue wcpos/woocommerce-pos-pro#654 — lifecycle: Pro-only uninstall of Free's tables, and whether StoreInterface stays public

From the 2026-10-12 architecture review (wcpos/woocommerce-pos#2182, verdict-lifecycle D4 and candidate 4). Two rulings, no code until they are made.

1. **Pro-only install, uninstall.** On a site where Free was never installed, Pro's bootstrap of the vendored Free creates the 8 tables and the cron hooks; Pro's `uninstall.php` declares Free's data "never touched" and `woocommerce_pos_pro_uninstall_free_installed()` gates only logs and translations. Should Pro's uninstall drop Free's tables, cron hooks and options under the same default/full-wipe split Free uses?
2. **`Interfaces/StoreInterface.php`.** No consumer in Free or Pro; third parties may `instanceof`. Delete it, or keep it as a public contract?



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-lifecycle.md` section 4 ("Two Store abstracts and a consumer-less StoreInterface"), D4 and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-lifecycle.md`; `.claude/research/2026-10-12-arch-review-next/spec-owned-tables.md` and `.claude/research/2026-10-12-arch-review-next/spec-dead-code.md` for what already landed around uninstall.
- Pro: `uninstall.php`, `woocommerce_pos_pro_uninstall_free_installed()`, how Pro boots the vendored Free on a site without the Free plugin (`vendor/wcpos/woocommerce-pos` or similar), and whether Pro's uninstall could call Free's uninstall routine.
- Free: `uninstall.php` (the default/full-wipe split — which option governs it, what it drops), the owned-tables registry (what "the 8 tables" are on the tip), the cron hooks list.
- `Interfaces/StoreInterface.php` in Free: grep for consumers across Free, Pro, every `/Users/kilbot/Projects/wcpos-*` and `/Users/kilbot/Projects/*-for-woocommerce` extension, and the client. Also `git log --follow` on it: when was it added and did anything ever implement it?
- WordPress.org plugin guidelines on uninstall (what a plugin is expected to remove; the "full wipe" opt-in pattern) — cite from the wiki or memory and say so.

## Specific questions
1. D4: on a Pro-only site, exactly which tables/crons/options does Pro's uninstall leave behind today (list them from the code), and would running Free's uninstall routine from Pro's `uninstall.php` under Free's own default/full-wipe option be safe when Free IS also installed (double-uninstall ordering: Pro uninstalled first while Free stays active must NOT drop Free's tables)? Propose the guard.
2. StoreInterface: zero consumers anywhere on disk? If so, the ruling is "delete in 2.0 with a changelog line" unless the wiki or readme advertises it as an extension point — check.
3. Which of the two is worth doing before 2.0 ships, and which lane?


Write the report now.
