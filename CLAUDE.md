# WCPOS Free Plugin (`woocommerce-pos`)

WordPress plugin providing the server-side foundation for WCPOS. This file is the repo-local source of truth for Claude and Codex. Do not duplicate these rules into `.ai/`, `.codex/`, or repo-local `.claude/skills`.

## Canonical Agent Context

- Global instructions live in `/Users/kilbot/.claude/CLAUDE.md` and `/Users/kilbot/.claude/rules/*.mdc`.
- Reusable skills live in `/Users/kilbot/.claude/skills`.
- Repo-specific context belongs in this root `CLAUDE.md`.
- Codex entrypoint is `AGENTS.md`, which delegates back to this file.

## Product and Naming

- Use **WCPOS** in user-facing copy, code comments, and documentation.
- Do not write “WooCommerce POS” except for immutable technical identifiers such as:
  - GitHub repo names: `woocommerce-pos`, `woocommerce-pos-pro`
  - WordPress.org slug: `woocommerce-pos`
  - ZIP filenames and existing metadata keys

## Wiki

This repo includes the WCPOS wiki as a submodule at `.wiki/`. Pull latest before relying on it:

```bash
git submodule update --init --remote .wiki
```

Relevant pages:
- `.wiki/product/overview.md` — product and business context
- `.wiki/architecture/plugin-free.md` — free plugin architecture
- `.wiki/product/features.md` — feature inventory
- `.wiki/support/index.md` — support knowledge

## Development Rules

- Follow WordPress and WooCommerce coding conventions configured in `.phpcs.xml.dist`.
- Use `WCPOS\WooCommercePOS\Logger` instead of `error_log()` in production code.
- Preserve backward compatibility when changing public methods; add optional parameters with defaults.
- Minimize admin hook footprint. Register admin handlers only where needed. Remember `admin_post_{action}` runs on `admin-post.php`, not the originating screen.
- Sanitize and validate all request data. Check request origin/context before reading `$_REQUEST`.

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

If wp-env fails because of port conflicts or environment initialization, diagnose and fix Docker/wp-env, try an isolated wp-env config or alternate ports when appropriate, or ask for help. Do not switch to local `composer install`, local `vendor/bin/phpunit`, or symlinked vendor directories.

### PHPUnit Conventions

- Bug fixes require a failing test first.
- Use `WCPOS_REST_Unit_Test_Case` helper methods such as `$this->wp_rest_get_request()` so required WCPOS headers are included.
- Apply settings filters before `parent::setUp()` because REST routes capture schema during `rest_api_init`.
- Use Arrange / Act / Assert structure.
- Name tests `test_[feature]_[scenario]_[expected_result]`.
- PHPUnit assertions are `assertEquals( expected, actual )`.

## JavaScript / Package Tests

- Use `pnpm` for workspace commands.
- For changed packages, run the relevant package test/build/lint scripts when available.
- If package lint is blocked by known tooling/config mismatch, document the exact command and error rather than claiming it passed.
