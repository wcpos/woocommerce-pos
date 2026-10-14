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

# Issue wcpos/woocommerce-pos#2252 — auth: open-redirect host allowlist (D2) and trusted-proxy rate limiting (D3)

From the 2026-10-12 architecture review (#2182, verdict-auth D2 and D3). Both verified, both Low to Low-Medium, both waiting on an operational decision.

**D2 — open redirect with tokens in the URL.** `Templates/Auth.php` `validate_redirect_uri()` accepts any `http(s)` host, then appends the tokens and `wp_redirect`s. The `auth_session` binding keys on the attacker's own URI, so it does not defend. A host allowlist needs the list of legitimate web-app origins (e2e uses `https://localhost/cb`). Failing test when ruled: `Test_Auth_Template::test_validate_redirect_uri_offsite_https_returns_empty`, plus app-scheme and same-host rows.

**D3 — rate limit bypassable, lockout abusable.** The per-IP limit reads client-supplied `CF-Connecting-IP` / `X-Forwarded-For`; the lockout is keyed only on the typed identifier and checked before the password, so anyone can lock any cashier out for 15 minutes; username and email forms get separate buckets. The fix needs a trusted-proxy decision (which header, from which proxies).



---

## Where to look first
- `.claude/research/2026-10-12-arch-review-next/verdict-auth.md` D2 ("Open redirect with tokens in the URL"), D3 ("Rate limit bypassable / lockout abusable") and "Needs Paul"; `.claude/research/2026-10-12-arch-review-next/findings-auth.md`.
- Free: `includes/Templates/Auth.php` (`validate_redirect_uri`, the IP resolution ~540-564, the rate limit and lockout code — find the transient keys and the 15-minute window), `includes/Services/Session_Context.php` (~123-154, the other IP resolver), the `auth_session` binding the verdict says keys on the attacker's URI.
- How WooCommerce core resolves a client IP (`WC_Geolocation::get_ip_address()` and the `woocommerce_geolocation_ip_lookup`/`X-Real-IP` handling in the vendored WooCommerce if present under `vendor/` or wp-env; otherwise cite from memory and say so) and how WordPress core treats `REMOTE_ADDR` — the point is whether a trusted-proxy allowlist is standard practice for a self-hosted plugin or whether plugins simply trust `REMOTE_ADDR`.
- Client and Electron: what `redirect_uri` values the shipped apps actually send to the auth template (search `/Users/kilbot/Projects/monorepo-v2-worktrees/research-issues` and `/Users/kilbot/Projects/electron` for `redirect_uri`, `wcpos-auth`, the auth path constant). This decides whether a host allowlist can be derived (same-site + app schemes) rather than configured.
- `git log --since=2026-10-12 -- includes/Templates/Auth.php` for anything that has moved (#2202 made query tokens REST-only; check whether that already narrowed D2).

## Specific questions
1. D2: on the tip, trace exactly what an attacker needs (a link the cashier clicks while logged in?) and what they get (an access token? a refresh token?). Is the token in the URL fragment or the query? Does #2202 or the `auth_session` binding reduce it? Then: can the allowlist be derived from the request (same origin as the site, the known app schemes) with no setting at all? Paul's standing rules: zero-config defaults, no new options unless he rules on it.
2. D3: who can lock a cashier out and from where (any unauthenticated request?). Propose the smallest fix that does not need a trusted-proxy decision (e.g. key the lockout on identifier+IP, check it after the password, merge the username/email buckets, and trust `REMOTE_ADDR` only — mirroring what WooCommerce does). Say what that leaves unfixed.
3. Does the released line (`main`) have the same code? These are security items; if `main` is affected the fix lane is `main`.


Write the report now.
