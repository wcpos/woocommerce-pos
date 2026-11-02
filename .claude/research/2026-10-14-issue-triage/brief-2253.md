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

# Issue wcpos/woocommerce-pos#2253 — payments: Ledger::record() merges raw client keys (tip, events, provider_refs); reproduce, then allowlist

From the 2026-10-12 architecture review (#2182, verdict-payments "other finding", NEEDS REPRO). `Ledger::record()` merges the raw client input into the row (`array_merge( $input, … )`). `refunds` and `refunded_amount` are overwritten and, since #2218, `closure_id` is dropped; `tip`, `events`, `provider_refs`, `receipt`, `void_requested_at`, the `*_at_gmt` stamps and arbitrary keys still survive.

To do: reproduce whether a client-set `tip` or `events` reaches receipts, closures or the sweeper (write the failing test first), then choose between an allowlist of client-writable keys in `validate_input()` and documenting the keys the till is trusted to write.



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-payments.md` "Other finding" and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-payments.md`.
- Free: `includes/Payments/Contract/Ledger.php` (`record()`, the `array_merge( $input, … )`, `validate_input()`, `to_wire()`, `EVENTS_MAX`), `includes/Payments/Contract/` (the payment-row shape: `tip`, `events`, `provider_refs`, `receipt`, `void_requested_at`, `*_at_gmt`), every reader of those keys: receipts (`includes/Templates/`, `Receipt_Data_*`), closures (`Services/Session_Figures.php`, `Closure_Store.php`), the sweeper (`Payments/Sweep*` or Pro `Server/Refund_Sweep.php`), Pro `Server/Event_Log.php`, fiscal record writers.
- Which REST route(s) reach `Ledger::record()` with client input, and with what permission (a cashier's token?).
- Client: what keys the till actually sends in a payment/ledger payload (search the client for `provider_refs`, `tip`, `void_requested_at`, the ledger meta key name).

## Specific questions
1. Reproduce on paper: a cashier-level request that sets `tip` (or `events`, or `provider_refs`) on a payment row — write out the failing test (route, body, then the reader that shows the forged value: a receipt total? a closure figure? a sweep decision?). If no reader is affected, say so with citations; that would make this a hygiene item, not a defect.
2. Which keys does the till legitimately write (the client sends them) versus which are server-only stamps that must never come from the client? Produce the allowlist/denylist from the client code and the server readers.
3. Does `main` have the same `record()`? (The released line matters if a cashier can forge a figure that reaches a fiscal record or a closure.)


Write the report now.
