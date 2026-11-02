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

# Issue wcpos/woocommerce-pos-pro#652 — fix(upgrade): the 2.0.0 migration calls __() before translations load (WP 6.7+ notice)

From the 2026-10-12 architecture review (wcpos/woocommerce-pos#2182, verdict-lifecycle D6, LIKELY). `Activator::run()` on `plugins_loaded@20` → `version_check()` → `db_upgrade()` → `updates/update-2.0.0.php` → `Registers::backfill_store_registers()` → `ensure_store_register()` → `__( 'Register', 'woocommerce-pos-pro' )` (`includes/Registers.php:74` on `next`). On WordPress ≥ 6.7 that is a "translation loading triggered too early" notice on every upgraded Pro site.

Options recorded in the verdict: defer that one backfill to `init`, or move Pro's upgrade ladder to Free's timing (defer to `woocommerce_init`). The minimal fix is the first (~6 lines). Test: run the 2.0.0 update with `did_action( 'init' )` false and assert no `_doing_it_wrong` for `_load_textdomain_just_in_time`.



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-lifecycle.md` D6 and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-lifecycle.md`.
- Pro: `includes/Activator.php` (`run()` on `plugins_loaded`, `version_check()`, `db_upgrade()`), `updates/update-2.0.0.php`, `includes/Registers.php` (`backfill_store_registers()`, `ensure_store_register()`, the `__( 'Register', 'woocommerce-pos-pro' )` call), and how/when Pro loads its textdomain (`load_plugin_textdomain` or none — WP 4.6+ auto-loads from wp.org/languages dirs on first `__()`).
- Free for comparison: when Free runs its own upgrade ladder (`Activator.php`, the `woocommerce_init` deferral the verdict mentions) and whether any Free upgrade file calls `__()` early too (grep `updates/` for `__(`, `_x(`, `esc_html__`).
- WordPress 6.7 semantics of `_load_textdomain_just_in_time`: translations requested before `init` trigger `_doing_it_wrong` (notice; on a debug site it is printed; on a production site it is logged if `WP_DEBUG_LOG`). Does anything in Pro load before `plugins_loaded@20` that would make `init` already done? (No — `plugins_loaded` precedes `init` — but confirm Pro's hook priority on the tip.)
- Pro `main` (released, `/Users/kilbot/Projects/woocommerce-pos-pro`): does the released Pro have the same early `__()` in any upgrade file? If yes the notice already hits released users on WP 6.7+ and the lane is `main`.

## Specific questions
1. Confirm the trace on the tip with file:line, and say whether the string is user-visible (a register name saved to the DB in the site's locale — so deferring to `init` also changes WHICH locale the name is saved in; is that an improvement or a regression for a multilingual site?).
2. The two options (defer that one backfill to `init`, or move Pro's whole upgrade ladder to Free's timing): cost, risk, and what else each would change (other upgrade steps that assume `plugins_loaded` timing, the `wcpos_pro_version` write ordering).
3. Test shape: run `update-2.0.0.php` in PHPUnit with `did_action('init')` false — is that even possible given the WP test bootstrap has already fired `init`? Propose a test that works.


Write the report now.
