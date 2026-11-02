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

# Issue wcpos/woocommerce-pos#2250 — sync: record the Order_Write_Payload rulings for restart, v1 update and provenance

From the 2026-10-12 architecture review (#2182, verdict-sync candidate 3, High). The restart is already one pure function (`Order_Writer::cart_restart_gmt`, called twice on purpose) and the three `set_date_created` sites are each pinned, so there is no code to merge. What is missing is the rulings in `Order_Write_Payload.php:34-37`, which lists only coupon, omitted-item and tax-ID lane splits:

1. Is it deliberate that v1 update ignores the client `date_created_gmt` and strips provenance and cash keys without filling them (`V1/Orders_Controller.php:499-507`)? Record it, or give v1 the v2 rule.
2. Should v1 stamp `_pos_payment_asserted`? Today it never does (ADR 0035 path 3 is v2-only).
3. Should a push that both restarts a cart and checks out take the restart date? The gate reads the persisted status, not `Order_Write_Intent::requested_status()`.
4. The `cart_restart_gmt` docblock's claim about `needs_payment()` may be wrong (Free's `order_needs_payment` returns true for a 0-total `pos-open` order).

Unpinned behaviours to test once ruled: a41ea525 (a restart alone writes no `_wcpos_sale_received_gmt`); v1 update ignoring `date_created_gmt`; v1 update stripping provenance.



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-sync.md` section 3 ("Client-asserted order state is decided in three places"), the Appendix "draft rulings for Order_Write_Payload", and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-sync.md`.
- Free: `includes/Sync/Order_Write_Payload.php` (the rulings block ~34-37), `includes/Sync/Order_Writer.php` (`cart_restart_gmt`, the three `set_date_created` sites), `includes/Sync/Order_Write_Intent.php` (`requested_status()`), `includes/API/V1/Orders_Controller.php` (~499-507, v1 update stripping provenance/cash keys and ignoring `date_created_gmt`), `includes/API/V2/...` order write path, `docs/adr/0035-hook-parity-contract.md` (path 3), `includes/Orders.php` (`order_needs_payment`), commit `a41ea525`.
- Tests that already pin behaviour: `tests/includes/Sync/`, `tests/includes/API/V1/Test_Orders_Controller*.php`, `tests/includes/API/V2/`.
- Client: does any shipped client still write orders through `wcpos/v1`? Which keys does the v2 client send on a cart restart and on checkout (`date_created_gmt`, `_pos_payment_asserted`, provenance keys)? Search the client for `date_created_gmt`, `payment_asserted`, `restart`.

## Specific questions
1. For each of the four rulings in the issue: what does the code do today (cite), what would the client observe if it changed, and which answer does the evidence favour? If a ruling is only "record the current behaviour as deliberate", say so — that is a CONTEXT.md/docblock edit plus a pinning test, not a product call.
2. Item 4 (the `cart_restart_gmt` docblock claim about `needs_payment()`): is the docblock wrong? Show the code path for a 0-total `pos-open` order.
3. Which of the three unpinned behaviours are worth a test, and would any of them be a behaviour change on `main` (v1 is the released client's path)?


Write the report now.
