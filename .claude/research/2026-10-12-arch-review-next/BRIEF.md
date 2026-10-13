# Overnight brief — architecture review of `next`, 2026-10-12

You are a feasibility researcher for one area of the WCPOS `next`-lane architecture review. Paul is asleep; the orchestrator (a Claude session) will read your verdict file in the morning run and decide what to implement tonight. Be decisive and concrete: the orchestrator cannot ask you follow-ups cheaply.

## Trees (origin/next, already checked out — read only)
- Free: `/Users/kilbot/Projects/woocommerce-pos/.claude/worktrees/arch-review-next`  (Free `837e985f`)
- Pro:  `/Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next`  (Pro `05f06c4`)

The explorer findings for your area are in `findings-<area>.md` next to this file. Read them first; they are the starting point, not the conclusion. `CONTEXT.md` (Free root) is the domain glossary; `docs/adr/0033`–`0039` are binding decisions; `.ai/rules/*.mdc` are repo rules (read `stakes-tiers.mdc` and `hpos-order-meta.mdc`). Vocabulary: module, interface, implementation, depth, seam, adapter, leverage, locality — never component/service/API/boundary.

## Hard rules
- READ-ONLY except for writing your output files into this directory (`.claude/research/2026-10-12-arch-review-next/`). No edits elsewhere, no tests, no wp-env, no builds, no `composer`, no `pnpm`, no git write commands. `git log`, `git blame`, `git show`, `git grep` are fine. Use absolute paths in every shell command (a guard rejects relative paths).
- Pro vendors Free at build time; Pro subclasses Free classes and hooks Free filters. Any change to a Free public method, filter signature, or class name is a Pro break unless you grep Pro and prove otherwise. Say which Pro files you grepped.
- Budget arithmetic comes from `wc -l` on the actual regions in the tree, never from the findings' numbers (those were explorer estimates). Lint passes under WordPress coding standards cost +50..+220 lines of docblocks and multi-line arrays — add that to every budget.
- Every claim carries a `path:line`. Every verdict names the test files that exist today and the ones the change would add or delete.
- Do NOT re-propose what already shipped (see findings files; Sept 2026 refactors #2012–#2053 and the Aug 2026 wave #1453–#1471).

## Your output: `verdict-<area>.md`
For EVERY candidate and every defect in your findings file, one section:

```
## <n>. <Title>  — VERDICT: DO | DO-RESHAPED | DEFER | DROP
Strength (explorer): … · Risk tier (stakes-tiers.mdc): Low/Medium/High
Why (3–6 bullets, each with path:line evidence; name what the explorer got wrong if anything)
Scope from the tree: files + regions + `wc -l` numbers; est. net lines (non-test) and total diff incl. lint budget
Pro impact: which Pro files/filters/subclasses touch this (grepped: <paths>) and what changes for Pro
Tests: existing files that pin behaviour today; tests to add (names, what they assert through the NEW interface); tests to delete (replaced, not layered)
ADR / CONTEXT.md: conflicts, and glossary terms this would add or sharpen (draft the definition in the CONTEXT.md style: Term, one paragraph, _Avoid_ list)
Sequencing: what must land first; what it unblocks; whether it fits one PR
Tonight? YES (safe to implement unsupervised, Low/Medium tier, clear spec) | NO (needs Paul: High tier judgement call, product decision, BC break, or spec not ready) — one line why
```
For defects, use `## D<n>. <Title> — VERIFIED | LIKELY | NOT A DEFECT | NEEDS REPRO`, with the exact trace (`path:line` → `path:line`), the failing test you would write (file, method name, Arrange/Act/Assert in prose), severity, and whether the fix is a one-line/one-file change (say the lines) or belongs to a candidate.

## Your output: `spec-<slug>.md`, one per candidate you mark DO/DO-RESHAPED and Tonight? YES
A Codex-ready implementation spec, self-contained (Codex sees only this file plus the tree):
- Goal (2 lines) · Stakes line (tier + what a failure costs + which failure classes to treat as accepted risk) · Out-of-scope list (name what NOT to add: locking, retries, new endpoints, validation layers, fallbacks, feature flags, env vars, new tables/options, plan files)
- Exact changes: per file, what moves where, new names (match repo naming: `Snake_Case` classes under `WCPOS\WooCommercePOS\…`, `snake_case` methods), signatures, which public methods stay as `@deprecated` delegates for Pro
- Rulings to preserve verbatim (behaviour that must not change), with the test that pins each
- Tests: files to add (full names), assertions in prose, files/tests to delete; conventions: `WCPOS_REST_Unit_Test_Case`, `$this->wp_rest_get_request()`, Arrange/Act/Assert, `test_[feature]_[scenario]_[expected]`, `assertSame` over `assertEquals`, `( expected, actual )`
- Budget: non-test ceiling and total-diff ceiling (from your `wc -l`, plus lint budget) and the stop rule text: "If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget."
- Verification Codex can run from the sandbox (no Docker): `php -l` on changed files; `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>` (host linter, readable from the sandbox); which PHPUnit files the orchestrator will run afterwards in wp-env
- The two fixed lines: "Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end." and "Do not push; do not open or edit a PR; do not reply on any PR." Plus: "Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report."
- Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.

## Also
- If a candidate's right answer is "a 16-line dedup, not a module", say so and give the 16 lines as the spec.
- If two candidates in your file are really one PR, merge them and say so.
- Finish with a `## Recommended tonight order` list (max 4 items) and a `## Needs Paul` list with the one-line question each.
- Keep `verdict-<area>.md` under ~2500 words; specs as long as they need to be but no longer.
