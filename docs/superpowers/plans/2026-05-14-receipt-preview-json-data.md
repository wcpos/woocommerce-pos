# Receipt Preview JSON Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add JSON override fixtures for receipt template gallery previews so each template type can control dummy data used for previews and thumbnail generation.

**Architecture:** Keep `Preview_Receipt_Builder` as the canonical data normalizer, then add a focused fixture loader that reads `templates/gallery/preview-data/*.json`, deep-merges profile overrides onto `base-receipt.json`, resolves fixture assets, and returns canonical receipt data. Gallery template metadata can opt into a profile using `preview_data`; preview responses use the selected fixture when no real order is supplied.

**Tech Stack:** PHP/WordPress/WooCommerce, JSON fixtures, PHPUnit through Docker/wp-env.

---

## Task 1: Add JSON fixtures and loader

**Files:**
- Create: `templates/gallery/preview-data/base-receipt.json`
- Create: `templates/gallery/preview-data/invoice.json`
- Create: `templates/gallery/preview-data/standard-receipt-rtl.json`
- Create: `templates/gallery/preview-data/thermal-kitchen-ticket.json`
- Create: `assets/img/template-gallery/preview-assets/coffee-monster-logo.svg`
- Create: `includes/Services/Receipt_Preview_Fixture_Loader.php`
- Test: `tests/includes/Services/Test_Receipt_Preview_Fixture_Loader.php`

- [ ] Write PHPUnit tests for base loading, invoice overrides, RTL overrides, kitchen ticket overrides, and invalid profile fallback.
- [ ] Run targeted test through `pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Receipt_Preview_Fixture_Loader.php` and confirm it fails because the class/files do not exist.
- [ ] Add JSON fixtures, logo SVG, and the fixture loader.
- [ ] Run the same targeted test and confirm it passes.

## Task 2: Wire gallery metadata and preview endpoint

**Files:**
- Modify: `templates/gallery/*.json`
- Modify: `includes/API/Templates_Controller.php`
- Test: `tests/includes/API/Test_Templates_Controller.php`
- Test: `tests/includes/Test_Templates.php`

- [ ] Add failing tests that verify gallery templates expose `preview_data`, invoice preview returns pending/unpaid data, and RTL preview returns RTL data.
- [ ] Run targeted wp-env tests and confirm they fail.
- [ ] Update gallery JSON metadata with profile keys and use `Receipt_Preview_Fixture_Loader` for sample gallery previews.
- [ ] Run targeted tests and confirm they pass.

## Task 3: Frontend asset/test alignment

**Files:**
- Modify: `packages/template-gallery/src/__tests__/gallery-template-assets.test.ts`

- [ ] Add failing frontend test expectations for committed fixture files matching metadata `preview_data` values.
- [ ] Run `pnpm --filter @wcpos/template-gallery test -- gallery-template-assets.test.ts` and confirm it fails if metadata/fixtures are inconsistent.
- [ ] Update tests/fixtures until green.
- [ ] Run package lint and targeted tests.
