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

# Issue wcpos/woocommerce-pos#2249 — refactor(templates): a Template_Type module; the store-scoped display list marks the wrong template active (D1)

From the 2026-10-12 architecture review (#2182, verdict-templates candidate 1 and D1). The preview-document slice landed (#2198). The Template_Type module proper has not: 32 PHP literal sites, ~31 TS sites and 7 Pro sites still spell out `receipt|display|report|closure`, with per-type rules in three places (engine locks written twice in `Single_Template.php`, the core default path in `Templates.php:640-662`, the display Live pin, order-previewable).

Latent defect D1 rides on it: `GET templates?type=display&store_id=N` marks `resolve_templates(N,'display')[0]` active while the screen shows the global Live template (Low; no shipped client reads this list). Failing test in the verdict: `Test_Templates_Controller::test_get_items_store_scoped_display_marks_global_live_active`.

Rulings needed before the module: may the Live pin write move out of the admin GET (`Templates_Controller.php:389-392`) to the write paths, and may Free add `Templates::get_store_template_ids()` / `set_store_template_ids()` for Pro to re-point `Customer_Display.php:275` and `API/V1/Stores.php:433-444,555,573` (Free accessor first, then a Pro follow-up)? Vocabulary drafted in the verdict: **Template Type**, **Preview Document**.



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-templates.md` section 1 ("Template type is a concept with no owner"), D1 and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-templates.md`.
- Free: `includes/API/V1/Templates_Controller.php` (the Live pin write inside the admin GET, ~389-392; `get_items` with `type=display&store_id=N`), `includes/Templates.php` (`resolve_templates`, the core default path ~640-662), `includes/Admin/Templates/Single_Template.php` (engine locks), everywhere the literal `receipt|display|report|closure` set is spelt out (count them: `grep -rn "'receipt', 'display'" includes/` and variants).
- Pro: `includes/Customer_Display.php` (~275), `includes/API/V1/Stores.php` (~433-444, 555, 573) — how Pro reads/writes the per-store template ids today and whether a Free accessor would let it stop touching storage directly.
- Client / admin UI: which screen lists templates by type with a store scope (the templates admin app is in this repo under `packages/` or `assets/`; check which caller passes `store_id` on `GET templates`). The issue says "no shipped client reads this list" — confirm or refute with the caller.
- `git log --since=2026-10-12 -- includes/Templates.php includes/API/V1/Templates_Controller.php` and the preview-document slice (#2198) to see what already moved.

## Specific questions
1. Is D1 reproducible on the tip as described? Write out the failing test.
2. Rank the 32 PHP literal sites: how many are real per-type rules (would change behaviour if a type were added) versus plain validation lists? A Template_Type module is only worth it if the rules are scattered; count them.
3. The two rulings (Live pin write moves out of the admin GET; Free accessor for store template ids with a Pro follow-up): is either genuinely a product/policy call, or does the code settle it?


Write the report now.
