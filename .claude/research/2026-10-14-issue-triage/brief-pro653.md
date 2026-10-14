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

# Issue wcpos/woocommerce-pos-pro#653 — refactor(payments): collapse the idempotency and provider-registry stacks; the legacy refund path is Paul's call

From the 2026-10-12 architecture review (wcpos/woocommerce-pos#2182, verdict-payments candidate 5). Only the lock-release bug (#637) landed. Remaining, in the verdict's order:

(a) `WordPress_Idempotency_Store` should call Free's `Idempotency_Repository` directly (`reserve`→`claim()`, array→`true`; safe because `Refund_Processor::137` re-reads `find()`), leaving Pro's `Idempotency_Repository` (226 lines) unused.
(b) `Server_Providers.php` ≡ `Device_Providers.php` after the renames: extract an abstract `Provider_Registry` with an interface constant and keep both class names (≈ −55 lines).
(c) The legacy refund path (`Refund_Orchestrator`, `Refund_Processor`'s 5th constructor argument, `plan_from_legacy_resolution`, `RefundPlan::from_legacy_resolution`) is reachable only by injection and ships unreachable in production builds. All are public in released Pro, so deleting them in 2.0 is Paul's call.

(a) and (b) are safe and can land together; (c) waits on the ruling.



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-payments.md` section 5 ("Collapse Pro's idempotency and registry stacks") and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-payments.md`; the Pro spec `.claude/research/2026-10-12-arch-review-next/spec-pro-idempotency-release.md` and Pro PR #637 (`git -C <pro tree> log --grep 637` or search the changelog) to see what already landed.
- Pro: `includes/Payments/Server/WordPress_Idempotency_Store.php` (or wherever it lives), Pro's `Idempotency_Repository`, Free's `Idempotency_Repository` (`claim()`, `find()`), `Refund_Processor.php` (~137 and the 5th constructor argument), `Server_Providers.php`, `Device_Providers.php`, `Refund_Orchestrator.php`, `plan_from_legacy_resolution`, `RefundPlan::from_legacy_resolution`.
- Pro `main` (released 1.x, `/Users/kilbot/Projects/woocommerce-pos-pro`): do `Refund_Orchestrator`, `RefundPlan::from_legacy_resolution`, Pro's `Idempotency_Repository`, `Server_Providers`/`Device_Providers` exist there at all? The issue says "public in released Pro" — verify. If they are `next`-only (never released), deleting them is free and (c) needs no ruling.
- Pro's changelog / readme for 2.0 to see whether a breaking-change policy is already stated for 2.0.

## Specific questions
1. (a): is the `reserve`→`claim()` swap behaviour-preserving? Walk the state machine: what Free's `claim()` returns on a duplicate key, on a stale reservation, on an exception, versus Pro's `reserve()`; and what `Refund_Processor::137`'s re-read of `find()` covers. Cite.
2. (b): diff the two registries on the tip (`diff` them) — identical after the renames, or is there a behavioural difference?
3. (c): count callers of the legacy path on the tip (grep both trees and the extensions under `/Users/kilbot/Projects/*terminal-for-woocommerce*`, `/Users/kilbot/Projects/wcpos-*` — a third-party terminal extension might inject it). If no caller exists anywhere on disk, say so; that is what settles the ruling.


Write the report now.
