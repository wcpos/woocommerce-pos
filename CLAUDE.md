# WCPOS Free Plugin (`woocommerce-pos`)

WordPress plugin providing the server-side foundation for WCPOS. This repository keeps project-specific agent context tracked in git so fresh clones are self-contained.

## Canonical Agent Context

- `CLAUDE.md` is the top-level project overview for Claude and Codex.
- `AGENTS.md` is the Codex entrypoint and points back to tracked repo context.
- `.ai/rules/*.mdc` contains project-specific rules that must ship with the repo.
- `.claude/skills/*/SKILL.md` contains project-specific skills that must ship with the repo.
- Agent-written documents (plans, specs, research notes, mockups, handoff briefs) live under `.claude/<kind>/<date>-<slug>/` and may be committed. Never write them to `docs/` (gitignored) or into the project tree, so they are not mistaken for shipped files. Anything another agent must act on still gets a GitHub issue, because a sandboxed agent cannot read this checkout.
- Global maintainer files under `/Users/kilbot/.claude` are optional personal preferences only; do not rely on them for project rules, and do not move project-specific context there.
- Do not create duplicate `.codex` rule/skill sets when the same project guidance already exists in this repo.

## Product and Naming

- Use **WCPOS** in user-facing copy, code comments, and documentation.
- Do not write “WooCommerce POS” except for immutable technical identifiers such as:
  - GitHub repo names: `woocommerce-pos`, `woocommerce-pos-pro`
  - WordPress.org slug: `woocommerce-pos`
  - ZIP filenames and existing metadata keys

## Wiki

The WCPOS wiki lives in [wcpos/wiki](https://github.com/wcpos/wiki) and changes daily. It is intentionally NOT vendored into this repo (a pinned submodule went stale and misled agents) — always fetch pages fresh, on demand.

Checkouts that predate the submodule removal may still have a leftover `.wiki/` directory (Git cannot delete an initialized submodule's working tree on checkout). Delete it — `rm -rf .wiki` — and never read from it.

- **Local agents** (on Paul's machine): read from the sibling clone at `/Users/kilbot/Projects/wiki`, but pull first — the clone can be stale:

  ```bash
  git -C /Users/kilbot/Projects/wiki pull --ff-only
  ```

- **Cloud/CI agents** (no sibling clone): fetch specific pages as readable Markdown with the GitHub CLI:

  ```bash
  wiki_page=product/overview.md
  gh api -H 'Accept: application/vnd.github.raw+json' \
    "repos/wcpos/wiki/contents/${wiki_page}"
  ```

Start with `INDEX.md` at the wiki root — one line per page — then fetch only the pages you need. Paths below are relative to the wiki repo root.

Relevant pages:
- `product/overview.md` — product and business context
- `architecture/plugin-free.md` — free plugin architecture
- `product/features.md` — feature inventory
- `support/index.md` — support knowledge

## Development Rules

- Follow WordPress and WooCommerce coding conventions configured in `.phpcs.xml.dist`.
- Use `WCPOS\WooCommercePOS\Logger` instead of `error_log()` in production code.
- Preserve backward compatibility when changing public methods; add optional parameters with defaults.
- Minimize admin hook footprint. Register admin handlers only where needed. Remember `admin_post_{action}` runs on `admin-post.php`, not the originating screen.
- Sanitize and validate all request data. Check request origin/context before reading `$_REQUEST`.
- **Order data goes through the order object, never `*_post_meta()` or `get_post*()` on an order id — see `.ai/rules/hpos-order-meta.mdc`.** HPOS keeps orders outside `wp_posts`, so post-meta calls on an order silently read nothing. The test bootstrap installs `Order_Postmeta_Guard`, which fails any test where `includes/` calls a `*_post_meta()` function on an order id (it does not see `get_post()` / `get_post_status()` — those stay on you); fix the line it names rather than the test.
- **Scale defensive engineering to the area's risk tier — see `.ai/rules/stakes-tiers.mdc`.** Read-only or trivially reversible admin settings are Low. Settings endpoints that feed the WCPOS client are Medium unless they control a High operation. Settings screens and endpoints inherit the tier of the operation they control, so writes affecting capabilities, payments, stock, auth, or other non-trivially reversible data are High. Do not escalate past an area's declared tier without asking.
- **Receipt templates stay logic-less — see ADR 0039** (`docs/adr/0039-no-comparison-logic-in-receipt-templates.md`). Do not propose an equality or comparison helper for Mustache templates, a Handlebars migration, or a vendor-specific boolean in the receipt data contract. Templates branch on data; plugins add their own keys through `woocommerce_pos_receipt_data`.

## REST API Notes

- WCPOS REST routes (`/wcpos/v1/`) require the `X-WCPOS: 1` header.
- Admin/settings React frontends use WordPress cookie authentication via `@wordpress/api-fetch`.
- POS/mobile API requests use JWT access/refresh tokens.
- Prefer `Authorization: Bearer <token>` headers. Query-parameter tokens are only for controlled local debugging because they can leak through logs and history.
- Apache/FastCGI can strip `Authorization`; code must check auth headers with `! empty()` rather than `isset()`.

## PHP / WordPress Tests

PHP and WordPress tests in this repository must run through Docker/wp-env. Do not use local Composer/PHPUnit as a fallback.

Preferred commands:

```bash
# Start Docker/wp-env test environment
pnpm exec wp-env start

# Run targeted PHPUnit
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist <test-file> --filter <test-name>

# Run project PHP unit script when full-suite validation is appropriate
pnpm run test:unit:php
```

The administrator check's network branch (`@group multisite`) runs only under a network install: `pnpm run test:unit:php:multisite` (config `.phpunit.multisite.xml.dist`).
The default suite skips those cases.

If wp-env fails because of port conflicts or environment initialization, diagnose and fix Docker/wp-env, try an isolated wp-env config or alternate ports when appropriate, or ask for help. Do not switch to local `composer install`, local `vendor/bin/phpunit`, or symlinked vendor directories.

### PHPUnit Conventions

- Bug fixes require a failing test first.
- Use `WCPOS_REST_Unit_Test_Case` helper methods such as `$this->wp_rest_get_request()` so required WCPOS headers are included.
- Apply settings filters before `parent::setUp()` because REST routes capture schema during `rest_api_init`.
- Use Arrange / Act / Assert structure.
- Name tests `test_[feature]_[scenario]_[expected_result]`.
- Assertion arguments go `( expected, actual )` — this is about ARGUMENT ORDER, not about which assertion to call. Prefer the strictest assertion that fits: `assertSame` over `assertEquals`, `assertTrue`/`assertFalse` over equality on booleans, `assertContains`/`assertArrayHasKey` over hand-rolled checks. (`tests/includes/Sync/` uses `assertSame` roughly four times as often as `assertEquals`.)

## JavaScript / Package Tests

- Use `pnpm` for workspace commands.
- For changed packages, run the relevant package test/build/lint scripts when available.
- If package lint is blocked by known tooling/config mismatch, document the exact command and error rather than claiming it passed.

## Branch lanes

Two trunks, one standard release cycle. Which phase we are in is a fact you read off the remote, not off this file — this note was once left saying "`next` is dead" for a week after `next` had been revived, and it sent agents to the wrong lane.

- **`main`** — the released line. Patch releases (`1.x.y`) are cut from its tip, so anything merged here ships in the next patch. Only fixes for the released version target `main`.
- **`next`** — the feature lane for the next minor/major. New features, breaking changes, and bug fixes that only matter because of that work branch from `origin/next` and target `next`. `main` moves daily and is merged into `next` regularly, never the other way around until release.

**The cycle:** `next` is live while the next version is being built. Just before release it is moved to `main` for final testing against dev-free and dev-pro — a freeze of a week or more during which `next` genuinely is dead and everything goes to `main`. Once the version ships, `next` is re-cut from `main` and becomes the working lane again.

**How to tell which phase you are in** (do this before choosing a lane, every session):

```bash
git fetch -q origin
git rev-list --left-right --count origin/main...origin/next   # <only-on-main>  <only-on-next>
```

Read the counts:

- **Right-hand count above 0** — `next` has commits `main` lacks, so `next` is live: feature and breaking work targets `next`.
- **Left-hand count above 0, right-hand 0** — `main` has moved on and `next` has not, so we are in the freeze: target `main`.
- **`0 0`** — the tips are equal. A freshly re-cut `next` looks exactly like the first hours of a freeze, and the count cannot tell them apart. Ask Paul which phase it is; do not default to `main`, or the first `next`-only commit never gets made.

If the remote contradicts this note, the remote wins.

Never commit directly to either trunk — branch off the right one in a worktree and target the PR's base at the same lane.

**Patch compatibility rule (Paul, 2026-10-19).** Every patch on a lane is compatible with every other patch on that lane, in both directions: a `2.0.N` plugin works with any `2.0.M` app or web bundle and vice versa. The web lane forces it (every plugin on `@<major.minor>` loads the newest bundle on that ref) and desktop and mobile merchants update when they choose, so a plugin released today serves apps a month old. The app has no upper bound on the plugin version it accepts; the plugin staying backward compatible within the lane is the only thing that keeps that pairing working. So a `main` PR that touches a REST route, a response field, a header in the CORS floor, an option the app reads, the sync protocol, or a vendored worker answers one question: *would this fail against the lane floor, the `<major.minor>.0` app or bundle, or any patch between it and the tip?* The floor, not "one patch older": a merchant on a 2.0.0 desktop app can still be served by the 2.0.5 plugin. Adding something behind a fallback is a patch. Removing or renaming anything a client reads (a route, a field, a header, an option) is never a one-step change at any version: a minor bump isolates the web bundle (the lane ref) and newer apps (their plugin floor), but nothing isolates an older desktop or mobile app from a newer plugin, so keep serving the old shape for at least one full minor (deprecate in `X.Y`, remove in `X.Y+2` at the earliest) until a plugin-side app floor exists, which it does not today. Anything else that fails the question bumps the minor across every line; it never ships as a patch. The RxDB worker check (`opfs-worker-drift.yml`) is the mechanical case of this rule; the rest is review judgement. The rule and the train-time box live in roadmap `docs/releases/RELEASE-CHECKLIST.md`. The lane-floor E2E leg is filed as #2323 and not built yet; until it is, the question above is answered by review, not CI.
