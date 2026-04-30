# Receipt Template Studio + Renderer Consolidation

**Date:** 2026-04-29
**Status:** Draft — awaiting review

## Problem

Receipt templates render through three independent codebases that drift from each other:

1. **`woocommerce-pos` PHP** — `Logicless_Renderer` (Mustache.php) renders HTML for WP Admin previews. Thermal templates fall back to `templates/gallery/thermal-receipt.php`, a Legacy PHP file that fakes thermal styling. This file is never installable; it exists only as the cheat behind `Templates_Controller.php::render_thermal_html_preview()`.
2. **`woocommerce-pos` JS** — `packages/thermal-utils/` renders XML AST to styled HTML for WP Admin thermal previews. The header explicitly notes it was *"originally derived from @wcpos/printer/src/renderer/"*.
3. **`monorepo-v2` JS** — `packages/printer/` renders XML AST to *both* HTML preview *and* ESC/POS bytes. This is the only renderer used for real production printing.

The `2026-04-01-thermal-utils-extraction-design.md` spec already consolidated two intra-repo forks within `woocommerce-pos`. The remaining drift is between `thermal-utils` (woocommerce-pos) and `printer` (monorepo-v2) — same source lineage, now diverged. Plus the unrelated PHP rendering paths.

Symptoms the user has observed:

- WP Admin preview ≠ RN app preview
- RN app preview ≠ printed receipt (suspected — needs measurement)
- Iterating on a template requires editing → installing → opening RN app → printing → comparing on paper

## Engine matrix (current and target)

The plugin defines three template engines (`includes/Templates.php:39`). `offline_capable` is engine-derived, not per-template:

| Engine | `offline_capable` | Output | Renderer (today) | Renderer (after Phase 2) |
|---|---|---|---|---|
| `legacy-php` | false | HTML | `Legacy_Php_Renderer` (PHP, server) | unchanged |
| `logicless` | true | HTML | `Logicless_Renderer` (PHP, server) **and** RN-app JS Mustache | `@wcpos/receipt-renderer` (JS, client) only |
| `thermal` | true | ESC/POS bytes | `@wcpos/printer` (JS) for production; `thermal-utils` + PHP cheat for WP Admin previews | `@wcpos/receipt-renderer` (JS, client) only |

**End state:** two renderers in the world. `Legacy_Php_Renderer` serves `legacy-php` only. `@wcpos/receipt-renderer` serves both `logicless` and `thermal`, on every surface (RN app preview, RN app print, WP Admin preview, public print URL).

## Goals

1. Provide a fast iteration loop for tuning the current bundled/gallery templates until they look right (hot reload, fixture data, multiple paper widths, output simulation).
2. Make drift **visible** through a side-by-side rendering harness so divergences can be diagnosed instead of guessed at.
3. Make drift **structurally impossible** going forward by collapsing all non-legacy template rendering onto a single canonical JS package.
4. After the bundled/gallery templates are approved, generate stable preview image assets for the WP Admin gallery UI and use the same harness for drift/snapshot checks.

## Non-goals

- Replacing or migrating Legacy PHP templates. They stay rendered server-side; the studio does not target them.
- Rewriting the printer transport layer (Bluetooth/USB/network adapters). The studio simulates output, not transport.
- Building a template *authoring* WYSIWYG. This is a developer tool, not an end-user designer.
- Server-side rendering of `logicless` or `thermal` templates anywhere. After Phase 2, these render in the browser/RN runtime only.
- Generating preview images for user-created custom templates in the database. Preview image generation targets bundled/gallery templates only.

## Approach

Three phases, sequenced so prerequisites land first, the diagnostic tool ships before consolidation, and the consolidation work is informed by evidence.

### Phase 0: Prerequisites

Two pieces of work that must land before Phase 1 is useful.

**0a. Normalize the preview endpoint response shape.** Today `GET /wcpos/v1/templates/{id}/preview` returns inconsistent payloads:

- Thermal preview returns `receipt_data`
- Logicless with real order returns `receipt_data`
- Logicless sample preview does **not** return `receipt_data`
- Legacy PHP preview does **not** return `receipt_data`

Normalize to a single shape for non-legacy engines:

```ts
{
  engine: 'logicless' | 'thermal',
  template_content: string,
  receipt_data: ReceiptData,  // Receipt_Data_Schema v1.2.0
  order_id: number,
  template_id: number | string
}
```

For Phase 1 diagnostics only, the endpoint may also return a temporary `preview_html` field for `logicless` templates so the studio can compare the current PHP `Logicless_Renderer` output against the canonical JS renderer. This field is removed in Phase 2 when PHP rendering for non-legacy engines is deleted. If keeping the main response pure is preferred, expose the same data behind an explicit diagnostic flag instead:

```txt
GET /wcpos/v1/templates/{id}/preview?include_legacy_html=1
```

Legacy-PHP previews remain server-rendered HTML — different response shape, documented separately.

**0b. Generate TypeScript types from `Receipt_Data_Schema`.** The schema is currently PHP-defined in `includes/Services/Receipt_Data_Schema.php`, so `woocommerce-pos` remains the source of truth. Add a build step in this repo that exports a JSON Schema artifact and TS type definition into a new `packages/receipt-schema/` workspace package consumed by `woocommerce-pos` JS packages. When `@wcpos/receipt-renderer` is extracted in Phase 2, publish the same schema artifact as `@wcpos/receipt-schema` from the release pipeline (or copy it into monorepo-v2 as a generated artifact) rather than hand-maintaining a second schema. PHP fixtures are tested against the JSON Schema to enforce parity.

### Phase 1: Template Studio (template tuning first, measurement second)

A new app in `monorepo-v2/apps/template-studio/`. Vite + React, runs in a browser locally. Consumes `@wcpos/printer` as a workspace package. Fetches templates and resolved receipt data from a configurable WP plugin URL via the now-normalized REST endpoints.

**Primary Phase 1 purpose:** make the current bundled/gallery templates easy to tweak until they look good. Drift snapshots and gallery preview images are downstream outputs of the same harness, but they should not be treated as final until the templates themselves are approved.

**Core tuning workflow:**

1. Select a bundled/gallery template.
2. Edit the template source in the repo or linked fixture location.
3. Studio hot-reloads the rendered preview against selected receipt fixtures and paper widths.
4. For thermal templates, inspect both browser preview and ESC/POS hex/ASCII output.
5. Once the template is visually approved, freeze it by regenerating gallery thumbnail assets and updating curated snapshots.

**Core layout** — three columns:

- **Left:** template picker (gallery + DB + local fixtures), data fixture picker, paper-width selector (58mm / 80mm / A4), engine filter
- **Center:** rendered preview via `@wcpos/printer`'s HTML renderer — the canonical output
- **Right:** comparison panel with tabs scoped to the engine of the selected template:
  - For `logicless`: compare canonical JS render vs PHP `Logicless_Renderer` output (fetched from the preview REST endpoint's temporary `preview_html` diagnostic field or `include_legacy_html=1` flag). This is the gap that Phase 2 must close before deleting the PHP renderer.
  - For `thermal`: no inter-renderer comparison (only one production renderer exists). Instead, show the preview HTML alongside the ESC/POS hex dump (paginated, with a decoded ASCII sidebar). The within-renderer contract is "preview faithfully represents what bytes will produce on paper."
  - For `legacy-php`: out of scope, not loadable in the studio.

**Diff affordance:** for logicless comparisons, a "diff" toggle highlights DOM/AST differences. **Pixel diffing is explicitly de-prioritized** — browser fonts, antialiasing, and paper-width CSS create too much noise. DOM/AST diffs first; optional pixel overlay only as a manual visual aid.

**Excluded comparison:** the PHP thermal preview cheat (`render_thermal_html_preview()`) is **not** included in the studio, even temporarily. It does not render the actual thermal XML template — it renders an HTML approximation. Comparing it would produce "expected divergence" everywhere and add noise. It is removed from the codebase in Phase 2.

**Fixture data:** ships in `apps/template-studio/fixtures/` covering edge cases — empty cart, single item, large quantities, long product names, RTL languages, multi-currency, refunds, tax-inclusive vs tax-exclusive, fiscal data. Each fixture is a JSON file conforming to the `Receipt_Data_Schema` JSON Schema (validated at load time).

**Snapshot harness:** snapshots are a stabilization step after template tuning, not the first deliverable. A `pnpm test:snapshots` command renders every (template × fixture × renderer) combination once the bundled/gallery templates are close enough to treat as expected output. **Storage strategy:**

- A small **curated set** of canonical (template × fixture) snapshots committed to the repo as goldens. CI fails on unintended changes to these.
- The **full matrix** can run locally and in CI on renderer-touching PRs, but it does not need a heavyweight permanent reporting system. Keep the output simple: zipped HTML + hex dumps plus a lightweight markdown summary when useful. Do not commit the full matrix.

**Gallery preview image generation:** after the bundled/gallery templates are visually approved, the studio owns a small asset-generation command for gallery thumbnails. This reuses the same canonical renderer, fixture data, browser environment, and screenshot harness instead of creating a separate thumbnail renderer. Do not generate/freeze final thumbnails before the template tuning pass is complete.

- Command: `pnpm generate:gallery-previews` from `monorepo-v2/apps/template-studio/` (or equivalent workspace script), run after templates are approved.
- Inputs: bundled/gallery templates plus one canonical receipt fixture, e.g. `fixtures/gallery-default-receipt.json`.
- Output: optimized static images committed in `woocommerce-pos/packages/template-gallery/src/assets/previews/` (or another gallery-owned asset directory chosen during implementation).
- Formats: prefer `.webp` for small UI assets, with `.png` only if tooling/browser compatibility requires it.
- Scope: bundled/gallery templates only. User-created DB templates continue to use live preview rendering, not pre-generated thumbnails.
- CI: optionally run the generator in check mode for PRs that change gallery templates, renderer code, or the canonical thumbnail fixture. This should fail only when committed preview images are stale, not produce a separate drift-reporting workflow.

**WP plugin connection:** see "Auth and dev environment" below. Falls back to bundled fixture templates if no plugin is reachable.

**Out of scope for Phase 1:** simulating physical thermal printer output (rendering ESC/POS bytes back to a pixel-accurate image of what would appear on paper). Hex dump comparison is the contract; visual fidelity verification against real prints is left for a future phase.

### Phase 2: Renderer Consolidation

Driven by evidence collected in Phase 1. Steps:

1. **Audit the diff output** from Phase 1 across all gallery templates × fixtures. Categorise each `logicless` divergence as:
   - Bug in `@wcpos/printer`'s renderer (fix in monorepo-v2)
   - Bug in PHP `Logicless_Renderer` (irrelevant — being deleted)
   - Mustache feature gap between PHP and JS implementations (must reconcile)
   - Intentional difference that needs to be preserved (document, then resolve)

2. **Extract `@wcpos/receipt-renderer`** from `@wcpos/printer/src/renderer/` and publish to npm. **Do not publish `@wcpos/printer` directly** — its root exports include transport, native peer dependencies, and RN/Electron/web adapters that should not leak into a WP Admin bundle.

   Required exports of `@wcpos/receipt-renderer`:
   - `renderLogiclessTemplate(template: string, data: ReceiptData, options?): string`
   - `sanitizeHtml(html: string, options?): string`
   - `parseXml(content: string): Ast`
   - `renderHtml(ast: Ast, options?): string`
   - `renderEscpos(ast: Ast, options?): Uint8Array`
   - `renderThermalPreview(template, data, options?): string`
   - `encodeThermalTemplate(template, data, options?): Uint8Array`
   - shared types from `@wcpos/receipt-schema`

   Publishing requirements:
   - Built ESM with TypeScript declarations (not raw `src/index.ts`)
   - `exports` field with browser-safe entry points
   - No native peer dependencies; pure JS only
   - Publishing flow set up via changesets (or equivalent) in monorepo-v2

3. **Delete `woocommerce-pos/packages/thermal-utils/`**. Replace its consumers (`packages/template-editor/` and `packages/template-gallery/`) with imports from the published package.

4. **Replace PHP-rendered previews with JS-rendered previews.** WP Admin loads `@wcpos/receipt-renderer` as a JS bundle and renders templates client-side, using the same code as the RN app. The preview REST endpoint stops returning HTML for non-legacy engines and returns only the resolved `receipt_data` payload (already the standard shape after Phase 0a).

5. **Convert the public print URL to a JS bundle for non-legacy engines.** Today `/wcpos-checkout/wcpos-receipt/{order_id}/?key={order_key}` (`Template_Router.php:189`) routes through `Receipt.php` which calls `Logicless_Renderer` for `logicless` templates. After Phase 2, that path still lets PHP validate the `order_id` + `order_key` and build the resolved `receipt_data`, but PHP no longer renders the template. Instead, it serves a thin HTML shell, localizes/embeds the escaped JSON payload and template metadata into the page, loads the bundled `@wcpos/receipt-renderer` asset, renders client-side, sanitizes, and calls `window.print()` after render completes. This avoids adding a new public REST data endpoint and avoids trying to call the admin-only preview endpoint from a public print page. Legacy-PHP templates continue to render server-side via `Receipt.php` → `Legacy_Php_Renderer`.

6. **Delete `Logicless_Renderer` and the PHP thermal preview cheat.** Specifically:
   - `includes/Templates/Renderers/Logicless_Renderer.php` — deleted
   - `templates/gallery/thermal-receipt.php` — deleted
   - `Templates_Controller.php::render_thermal_html_preview()` — deleted
   - `Templates_Controller.php::render_logicless_preview()` — deleted
   - `Receipt_Renderer_Factory` — narrowed to only return `Legacy_Php_Renderer`
   - The `mustache/mustache` Composer dependency stays (legacy-php templates may use it directly)

7. **Update the studio** to consume the published `@wcpos/receipt-renderer` the same way both consumers do, so the studio stays representative of production.

After Phase 2: `logicless` and `thermal` templates have **one renderer** in the world — `@wcpos/receipt-renderer` — used by RN preview, RN print, WP Admin preview, public print URL, and the studio. Drift is structurally impossible. `legacy-php` keeps its own server-side renderer, untouched.

## Architecture

### Package boundaries after Phase 2

```
@wcpos/receipt-renderer (npm, published from monorepo-v2)
├── parse-xml          (XML → AST)
├── render-html        (AST → HTML for browser preview)
├── render-escpos      (AST → ESC/POS bytes)
├── render-zpl         (future)
├── render-cpcl        (future)
└── encode-receipt     (canonical receipt fixture → AST via default template)

@wcpos/receipt-schema (generated from woocommerce-pos, published with renderer release)
└── JSON Schema + TypeScript types for Receipt_Data_Schema v1.2.0

Consumers:
├── monorepo-v2/packages/printer       (transport adapters; uses renderer for encoding)
├── monorepo-v2/apps/wcpos-app         (RN app; uses renderer for preview)
├── monorepo-v2/apps/template-studio   (dev tool; uses renderer + diffing + gallery thumbnail generation)
├── woocommerce-pos/packages/template-editor   (WP Admin; uses renderer for preview)
├── woocommerce-pos/packages/template-gallery  (WP Admin; uses renderer for preview + committed bundled-template preview images)
└── woocommerce-pos print asset bundle (vanilla JS loaded by /wcpos-checkout/wcpos-receipt/...)
```

### Data flow after Phase 2

```
WordPress (woocommerce-pos plugin)
  │
  ├── GET /wcpos/v1/templates              → list templates (HTML / XML content + engine type)
  └── GET /wcpos/v1/templates/{id}/preview → resolved receipt_data payload (Receipt_Data_Schema v1.2.0)
                                              uniform shape for all non-legacy engines

Consumers (RN app / WP Admin / studio / print URL bundle)
  │
  ▼
renderLogiclessTemplate(template.content, receipt_data)  ← JS Mustache + sanitizer, single implementation
  │
  ├── engine=logicless (HTML)   → display sanitized HTML (sandboxed iframe where applicable)
  ├── engine=thermal (XML)
  │     │
  │     ▼
  │   parse-xml → AST
  │     │
  │     ├── render-html(AST)     → preview
  │     └── render-escpos(AST)   → bytes for printer (RN app + studio hex dump)
  │
  └── engine=legacy-php          → server-side render only (no JS path; URL still PHP-rendered)
```

## Equivalence model

The previous spec said "identical output." That's underspecified — HTML pixels and ESC/POS bytes cannot be identical in a useful sense. Equivalence is defined per engine, in two parallel groups.

### HTML group (`logicless` engine)

All three surfaces should produce visually-equivalent output:

- WP Admin preview (after Phase 2: JS-rendered)
- RN app preview (JS-rendered, in WebView)
- Public print URL (after Phase 2: JS-rendered, in browser)
- Browser print of any of the above

These don't need to match byte-for-byte, but must match at the **resolved Mustache HTML string** layer. After that point, the only difference between surfaces is the host environment's CSS rendering, which should be near-identical (same renderer family — Blink/WebKit). Print-media CSS (`@media print`) is the most likely source of drift; equivalence layers worth snapshotting:

- Mustache-resolved HTML string (must match exactly)
- Screen-render DOM snapshot (must match structurally)
- Print-render DOM snapshot (must match structurally)

### Thermal group (`thermal` engine)

All three surfaces should produce equivalent output:

- WP Admin preview (after Phase 2: JS-rendered HTML preview)
- RN app preview (JS-rendered HTML preview)
- Physical thermal print (ESC/POS bytes from same renderer)

Equivalence layers:

- Mustache-resolved XML string (must match exactly across all surfaces)
- Parsed XML AST (must match exactly)
- `render-html` output (must match exactly between WP Admin preview and RN preview)
- `render-escpos` byte stream (single source — only sent to physical printer)
- **Cross-pipeline contract:** the AST renders to HTML that *visually represents* what the ESC/POS bytes will produce on paper. Bold = bold, double-width = double-width, alignment matches, line breaks at the same column. Not byte-identical, but semantically faithful. Verified manually against printed reference samples.

### Cross-group

HTML and thermal templates do **not** need to match each other. Different output media, different layout primitives, different design intent. The studio surfaces them as separate workflows.

## Security

Moving non-legacy previews from PHP to JS removes the safety net of `wp_kses_post()`, which currently sanitizes Mustache output before it lands in the WP Admin DOM. **This is a Phase 2 blocker** if not addressed.

Required:

- WP Admin preview renders client-side output inside a **sandboxed iframe** with the most restrictive `sandbox` attributes practical. Start with an empty sandbox attribute (no scripts, no popups, no top-navigation, no same-origin) and add `allow-same-origin` only if required for print/CSS behavior. The iframe receives already-sanitized HTML via `srcdoc` or `postMessage`.
- Rendered HTML is sanitized in the parent/bundle before it is assigned to the iframe or page DOM. Use DOMPurify (or equivalent) configured to match the `wp_kses_post()` allowlist as closely as practical. Sanitization happens before render, regardless of template trust level.
- Tests for malicious template content: a fixture set of templates containing `<script>` tags, `javascript:` URLs, `onerror` handlers, SVG with embedded JS, and HTML5 event attributes. Snapshot tests assert these are stripped in the rendered output.
- The public print URL bundle inherits the same sanitization rules — even though it's a top-level page (no iframe), the same DOMPurify pass runs before `document.body.innerHTML = ...`.

## Auth and dev environment

The studio runs in `monorepo-v2/apps/template-studio/` and needs to fetch templates from a WP plugin endpoint (`/wcpos/v1/templates`) which requires the `manage_woocommerce_pos` capability.

WordPress admin React frontends use **cookie auth via `@wordpress/api-fetch`**, not JWT (project CLAUDE.md confirms this). The RN app uses JWT, but it's not verified that JWT works for these admin-only template routes.

**Concrete plan:**

- Studio dev mode runs with a Vite proxy (`vite.config.ts` server.proxy) pointing to the local wp-env URL. Cookies flow through the proxy; the studio acts like an admin page from WP's perspective.
- All requests include the required `X-WCPOS: 1` header (the project CLAUDE.md is explicit that WCPOS REST routes require this header, even though some existing frontend uses the `wcpos=1` query param fallback).
- For "studio against a remote dev site" mode, use an application password generated for the user, sent as Basic auth — keeps the studio simple and matches WP norms.
- No JWT path is added unless verified to work for these routes — out of scope for this spec.

## Risks and tradeoffs

- **npm publishing infrastructure** — `monorepo-v2` does not currently publish packages. Phase 2 requires setting up a publish flow (changesets recommended). Estimate: small but non-zero.
- **Version coordination** — once published, `woocommerce-pos` consumers depend on a versioned `@wcpos/receipt-renderer`. Bumps need to flow through both repos. Mitigated by the studio acting as a regression suite and by most renderer changes being additive.
- **Preview image churn** — gallery thumbnails will change when renderer CSS, templates, or the canonical thumbnail fixture changes. Keep the image set intentionally small (one thumbnail per bundled/gallery template) and use an explicit generator/check command so updates are deliberate.
- **Phase 1 ships value alone** — even if Phase 2 is delayed or descoped, the studio is independently useful because it provides the fast template tuning loop needed to make the current templates look good. Risk insulation is intentional.
- **Mustache feature parity** — PHP Mustache.php and JS Mustache may diverge on niche features (lambdas, partial inheritance, custom delimiters). Phase 1 audit must confirm parity before Phase 2 deletes the PHP renderer.
- **Sanitization may strip valid template features** — DOMPurify configured for `wp_kses_post()` parity may reject HTML constructs that templates currently rely on. Phase 2 audit must check every gallery template against the sanitizer.
- **No PHP fallback for non-legacy previews after Phase 2** — if the JS bundle fails to load in WP Admin or on the print URL, no preview/print renders. Mitigation: bundle ships with the plugin as a versioned local asset (no CDN), with clear error UI if loading fails. `Logicless_Renderer` is deleted in this update rather than kept as a compatibility fallback.
- **Drift may be larger than expected** — Phase 1 might reveal divergences hard to reconcile. Phase 2 may then need re-scoping.

## Success criteria

**Phase 0:**

- `GET /wcpos/v1/templates/{id}/preview` returns the normalized shape for all non-legacy engines (logicless and thermal both return `{ engine, template_content, receipt_data, order_id, template_id }`)
- `packages/receipt-schema/` published as a workspace package; PHP fixtures validate against the JSON Schema in CI; TypeScript types are exported

**Phase 1:**

- Studio runs locally with `pnpm dev` from `monorepo-v2/apps/template-studio/`
- Studio supports a hot-reload tuning loop for bundled/gallery template source changes
- All gallery templates render in the studio against the canonical JS renderer
- Logicless templates show side-by-side comparison with PHP `Logicless_Renderer` output
- Thermal templates show side-by-side preview HTML and ESC/POS hex dump
- After the maintainer approves the tuned bundled/gallery templates, those templates have generated preview image assets from the canonical renderer and canonical thumbnail fixture
- At least 5 fixture payloads cover the edge cases listed under "Fixture data"
- After template tuning is approved, `pnpm test:snapshots` runs in CI; canonical goldens are committed; full-matrix output is available as simple local/CI artifacts when useful
- After preview images are generated, gallery preview image generation has a check mode that detects stale committed thumbnails when gallery templates, renderer code, or the thumbnail fixture changes
- A lightweight markdown summary of notable `logicless` divergences is checked into the studio repo; no elaborate drift-reporting system is required

**Phase 2:**

- `@wcpos/receipt-renderer` published to npm with browser-safe exports and built ESM
- `woocommerce-pos/packages/thermal-utils/` deleted
- `Logicless_Renderer`, the PHP thermal preview cheat, and `templates/gallery/thermal-receipt.php` deleted
- WP Admin previews for non-legacy templates render via the published JS package
- The public print URL serves a JS bundle for non-legacy engines; PHP for `legacy-php` engine only
- Snapshot tests in the studio prove that WP Admin preview, RN preview, and (for thermal) ESC/POS bytes all derive from the same AST for every (template × fixture) combination
- All security tests pass: malicious template fixtures are sanitized in every rendering surface
- Legacy PHP rendering path remains untouched and continues to work

## Implementation handoff

This spec is ready for another agent to implement, but it should **not** be attempted as one giant PR. Treat it as a staged cross-repo programme with review gates after each phase.

### Required agent context

Before implementation, the agent must read:

1. `/Users/kilbot/.claude/CLAUDE.md`
2. `/Users/kilbot/.claude/rules/*.mdc`
3. `woocommerce-pos/CLAUDE.md`
4. This spec

Critical repo rules:

- Use a git worktree for code changes unless the maintainer explicitly chooses a docs-only/main-tree edit.
- Pull `origin/main` before starting.
- PHP/WordPress tests must run through Docker/wp-env; do not use local Composer/PHPUnit as a fallback.
- Use `pnpm` for JS workspace commands.
- Do not use GitHub MCP tools; use `gh` CLI if GitHub inspection is needed.


### Agent execution loop

This handoff is intended for iterative agent execution, not a one-shot implementation. The next agent should:

1. Read the required context and this spec.
2. Start with the earliest incomplete PR in the recommended decomposition.
3. Implement as much of that PR as makes sense in one focused session.
4. Validate with the appropriate tests/lint/build commands.
5. Open a PR when the slice is coherent and reviewable.
6. Update this handoff section with:
   - what was completed,
   - what commands were run,
   - what remains,
   - any changed assumptions or follow-up decisions.
7. Stop at a natural checkpoint rather than trying to finish all phases at once.

The next session should repeat the same loop: read the updated handoff, continue from the next incomplete checkpoint, PR, update the handoff, and stop.

Do not wait for a separate prompt for each PR. Keep going through the decomposition in order for as long as the work remains coherent and safe.

### Recommended decomposition

Implement in separate PRs. Each PR should leave the product working and testable.

#### PR 1 — Preview endpoint normalization + schema artifact

**Repo:** `woocommerce-pos`

**Goal:** make template preview data consistently available to JS consumers and create the schema package that later renderer work depends on.

**Likely files:**

- `includes/API/Templates_Controller.php`
- `includes/Services/Receipt_Data_Schema.php`
- `packages/receipt-schema/package.json`
- `packages/receipt-schema/src/` or generated artifacts
- `pnpm-workspace.yaml`
- Tests under `tests/includes/API/` and/or `tests/includes/Services/`

**Deliverables:**

- Non-legacy preview responses return `{ engine, template_content, receipt_data, order_id, template_id }` where `template_id` supports `number | string`.
- Logicless preview can temporarily include PHP-rendered HTML for Phase 1 diagnostics, either as `preview_html` or behind `include_legacy_html=1`.
- Legacy-PHP response shape remains documented and unchanged.
- `packages/receipt-schema` exports JSON Schema + TS types derived from PHP `Receipt_Data_Schema`.
- PHP fixtures validate against the JSON Schema in CI.

**Verification:**

- Targeted wp-env PHPUnit for template preview endpoint response shapes.
- Schema generation/check command.
- Relevant JS package type/lint checks.

**Status update — 2026-04-29 / PR #834 merged (`template-studio-pr1`, merge `5e203944`):**

- Completed:
  - Normalized non-legacy preview payloads so `logicless` and `thermal` responses include `{ engine, template_content, receipt_data, order_id, template_id }`.
  - Added explicit `include_legacy_html` support for temporary Phase 1 `logicless` PHP-rendered diagnostics.
  - Kept legacy-PHP preview URL/HTML behavior unchanged and covered by existing preview tests.
  - Added `Receipt_Data_Schema::get_json_schema()` with PHP as the source of truth.
  - Added `packages/receipt-schema/` with generated JSON Schema, generated TypeScript types, and `build`/`check`/`lint` scripts.
- Verification run:
  - `pnpm --filter @wcpos/receipt-schema build`
  - `pnpm --filter @wcpos/receipt-schema check`
  - `pnpm --filter @wcpos/receipt-schema lint`
  - `pnpm exec wp-env run --config /tmp/wp-env-template-studio-pr1.json --env-cwd='wp-content/plugins/template-studio-pr1' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/API/Test_Templates_Controller.php`
  - `pnpm exec wp-env run --config /tmp/wp-env-template-studio-pr1.json --env-cwd='wp-content/plugins/template-studio-pr1' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Receipt_Data_Schema.php`
  - `pnpm exec wp-env run --config /tmp/wp-env-template-studio-pr1.json --env-cwd='wp-content/plugins/template-studio-pr1' cli -- composer run lint -- includes/API/Templates_Controller.php includes/Services/Receipt_Data_Schema.php tests/includes/API/Test_Templates_Controller.php tests/includes/Services/Test_Receipt_Data_Schema.php packages/receipt-schema/scripts/export-receipt-schema.php`
- What remains:
  - PR 1 is merged; the Phase 0 acceptance gate is satisfied.
  - Next agent should start PR 2 in `monorepo-v2` by extracting the browser-safe `@wcpos/receipt-renderer` package.
- Changed assumptions/follow-up decisions:
  - `logicless` sample previews keep `preview_html` by default for backward compatibility with current gallery clients. Studio can still use `template_content` + `receipt_data` as the stable rendering contract and treat `preview_html` as temporary Phase 1 comparison output.
  - The schema package intentionally exports a broad TypeScript shape plus the JSON Schema artifact; renderer/studio code should use the JSON Schema for structural validation rather than relying on exhaustive hand-authored TS interfaces.

#### PR 2 — Extract browser-safe renderer package in monorepo-v2

**Repo:** `monorepo-v2`

**Goal:** extract the canonical non-legacy renderer without leaking printer transports into browser/admin bundles.

**Likely files:**

- `packages/receipt-renderer/package.json`
- `packages/receipt-renderer/src/`
- `packages/printer/src/renderer/` imports/exports
- monorepo package/build configuration
- renderer tests

**Deliverables:**

- `@wcpos/receipt-renderer` exports:
  - `renderLogiclessTemplate(template, data, options?)`
  - `sanitizeHtml(html, options?)`
  - `parseXml(content)`
  - `renderHtml(ast, options?)`
  - `renderEscpos(ast, options?)`
  - `renderThermalPreview(template, data, options?)`
  - `encodeThermalTemplate(template, data, options?)`
- Built ESM + TypeScript declarations.
- Browser-safe `exports` field.
- No native peer dependencies.
- `@wcpos/printer` consumes the extracted renderer instead of owning a divergent copy.

**Verification:**

- Renderer unit tests.
- Printer package tests that prove existing encode/preview behavior still works.
- Bundle/import check from a browser-like environment.

**Status update — 2026-04-30 / PR #335 merged and deployed (`feature/receipt-renderer-pr2`, merge `5caf3856028d741e65c111a466cf3c154fcf9066`):**

- Completed:
  - Added `@wcpos/receipt-renderer` in `monorepo-v2` with the required public exports for logicless rendering, HTML sanitization, XML parsing, HTML preview rendering, ESC/POS rendering, and thermal template preview/encoding.
  - Added a browser-safe ESM `exports` field, build script, TypeScript declaration output, and no `peerDependencies` on the renderer package.
  - Moved the canonical thermal renderer implementation into the renderer package and changed `@wcpos/printer` renderer entrypoints to consume/re-export it instead of maintaining a divergent copy.
  - Added renderer unit coverage, including sanitization and a regression case for DOMParser-only/non-`window.document` runtimes so `renderThermalPreview()` continues returning usable markup.
  - Added a browser-like Vite bundle/import check for the built renderer package.
- Verification run:
  - `pnpm run lint --filter @wcpos/receipt-renderer` (turbo ran the broader package lint scope and completed with existing warnings in unrelated packages only)
  - `pnpm --filter @wcpos/receipt-renderer lint`
  - `pnpm --filter @wcpos/receipt-renderer typecheck`
  - `pnpm --filter @wcpos/receipt-renderer test`
  - `pnpm --filter @wcpos/receipt-renderer check:browser-import`
  - `pnpm --filter @wcpos/printer exec vitest run`
  - `pnpm --filter @wcpos/printer exec tsc -p tsconfig.json --noEmit`
  - `pnpm --filter @wcpos/printer exec eslint src/renderer/index.ts src/renderer/parse-xml.ts src/renderer/render-escpos.ts src/renderer/render-html.ts src/renderer/types.ts vitest.config.ts`
  - `node -e "const p=require('./packages/receipt-renderer/package.json'); if (p.peerDependencies && Object.keys(p.peerDependencies).length) throw new Error('receipt-renderer has peer dependencies'); console.log('receipt-renderer peerDependencies:', p.peerDependencies || {})"`
  - `/codex-review` twice; first finding was fixed by staging the new package, second finding was fixed by preserving thermal preview markup in DOMParser-only runtimes and adding regression coverage.
- What remains:
  - PR 2 is merged and deployed; the Phase 2 prerequisite for a browser/admin-safe renderer package is satisfied.
  - Next agent should start PR 3 in `monorepo-v2`: Template Studio tuning loop, snapshot harness, and gallery preview image workflow.
  - `woocommerce-pos` handoff PR #835 is also merged (`1b6e95e478b915627d8a5fc799de6a56c6bc5805`).
- Changed assumptions/follow-up decisions:
  - Build output is generated by `pnpm --filter @wcpos/receipt-renderer build` and ignored in git; the release flow must run that build before `changesets publish` (or equivalent) so published artifacts always include `dist/`.
  - The expected `packages/receipt-renderer/package.json#files` contract for publish is `["dist/", "package.json"]` (exclude `src/` from the package tarball).
  - `renderThermalPreview()` sanitizes when a browser DOM is available, but preserves usable markup in DOMParser-only runtimes to avoid breaking existing React Native/WebView preview consumers through `@wcpos/printer`; PR 4 must still add the full WP Admin/public-surface malicious-template fixture coverage for `<script>` tags, `javascript:` URLs, inline handlers (`onerror`/`onload`), SVG with embedded JS, and HTML5 event attributes, with assertions aligned to the `wp_kses_post()` parity requirement in the Security section above.
  - `pnpm install --frozen-lockfile --filter @wcpos/receipt-renderer --filter @wcpos/printer` is currently blocked by pre-existing `apps/electron` submodule manifest/lockfile drift (`better-sqlite3` and `rxdb-premium` mismatch), not by the renderer changes.

#### PR 3 — Template Studio tuning loop + snapshot harness + gallery preview images

**Repo:** `monorepo-v2`, with generated image outputs copied/committed in `woocommerce-pos` if implementation chooses that workflow.

**Goal:** build the local tuning/diagnostic app first, use it to make bundled/gallery templates look good, then use the same harness as the single generator for bundled gallery thumbnails and curated snapshots.

**Likely files:**

- `monorepo-v2/apps/template-studio/package.json`
- `monorepo-v2/apps/template-studio/src/`
- `monorepo-v2/apps/template-studio/fixtures/`
- `monorepo-v2/apps/template-studio/scripts/generate-gallery-previews.ts`
- `woocommerce-pos/packages/template-gallery/src/assets/previews/`
- `woocommerce-pos/packages/template-gallery/src/components/template-card.tsx`
- `woocommerce-pos/packages/template-gallery/src/types.ts`

**Deliverables:**

- Studio runs with `pnpm dev`.
- Studio hot-reloads bundled/gallery template source changes so the maintainer can tweak templates quickly.
- Studio can fetch templates/receipt data from local wp-env through a Vite proxy with cookie auth and `X-WCPOS: 1`.
- Logicless templates can compare JS output against temporary PHP diagnostic output.
- Thermal templates show HTML preview + ESC/POS hex dump.
- After template tuning is approved, `pnpm test:snapshots` creates curated goldens and can generate simple local/CI artifacts.
- After template tuning is approved, `pnpm generate:gallery-previews` renders one thumbnail per bundled/gallery template using the canonical renderer and canonical thumbnail fixture.
- Gallery UI uses committed preview image assets for bundled/gallery templates only.

**Verification:**

- Studio unit/component tests where practical.
- Snapshot command.
- Gallery preview image generator check mode.
- Template gallery package lint/test/build.

**Status update — 2026-04-30 / PR #339 merged (`feature/template-studio-pr3`, head `a065b9109c277b0031fe983a77fcd813c46c50cd`, merge `f94d658508f5fcd196efce9da7a3208c20df33c7`):**

- Completed:
  - Added `apps/template-studio/` in `monorepo-v2` with a Vite/React local tuning UI for bundled/gallery `logicless` and `thermal` templates.
  - Studio renders through `@wcpos/receipt-renderer`, loads gallery templates from the sibling `woocommerce-pos/templates/gallery` checkout, and full-reloads when those template files or Studio fixtures change.
  - Added wp-env preview fetching through the Vite `/wp-json` proxy, preserving browser cookie auth and sending `X-WCPOS: 1`; logicless responses can display temporary `preview_html` diagnostics.
  - Thermal templates show canonical HTML preview plus ESC/POS hex and printable ASCII debug output.
  - Added six receipt fixtures covering default gallery data, empty cart, long product names/large quantities, RTL/multi-currency, refund/tax-inclusive, and fiscal-data scenarios, aligned to the fields current gallery templates consume.
  - Added curated snapshot goldens/checking and a lightweight summary without creating a drift-reporting subsystem.
  - Added `generate:gallery-previews` / `check:gallery-previews` for bundled/gallery templates only, with committed generated PNG previews under `apps/template-studio/gallery-previews`; the output path can be pointed at `woocommerce-pos/packages/template-gallery/src/assets/previews` via `WCPOS_GALLERY_PREVIEW_DIR` when the gallery UI is wired to assets.
- Verification run:
  - `pnpm --filter @wcpos/template-studio lint`
  - `pnpm --filter @wcpos/template-studio test`
  - `pnpm --filter @wcpos/template-studio test:snapshots`
  - `pnpm --filter @wcpos/template-studio check:gallery-previews`
  - `pnpm --filter @wcpos/template-studio build`
  - `/codex-review` three times; findings were fixed for engine-filter selection, hot reload signaling, snapshot check mutation, wp-env filter reset, and fixture/schema alignment.
  - Conflict follow-up checks on 2026-04-30: `git fetch origin main feature/template-studio-pr3`; `git switch -C feature/template-studio-pr3 origin/feature/template-studio-pr3`; `git merge-base origin/main HEAD`; `gh pr view 339 --repo wcpos/monorepo --json mergeable,mergeStateStatus,headRefOid,baseRefName,headRefName`; `gh pr checks 339 --repo wcpos/monorepo`. Evidence: merge base matched `origin/main` (`6b072e6c0e292168b149677c55ab5cdafa0cbd6f`), PR head was `70c632d39aee10bf27a41f8bce9acaeebc1bd9f1`, GitHub reported `mergeable: MERGEABLE`, and remaining blockers were pending CI/review statuses.
  - Review follow-up on 2026-04-30: addressed new CodeRabbit App.tsx XSS-sink feedback by sanitizing both canonical preview HTML and temporary PHP diagnostic HTML before DOM insertion, added a regression test covering `<script>` and inline event-handler removal, added metadata JSON parse filename context, refreshed two stale long-product-name curated snapshots, and pushed commit `a065b9109c277b0031fe983a77fcd813c46c50cd` to PR #339.
  - Round 3 validation on 2026-04-30 before pushing `a065b9109`: `pnpm --filter @wcpos/template-studio lint`; `pnpm --filter @wcpos/template-studio test` (1 file / 7 tests passed); `pnpm --filter @wcpos/template-studio test:snapshots` (curated snapshots current); `pnpm --filter @wcpos/template-studio check:gallery-previews` (gallery preview images current); `pnpm --filter @wcpos/template-studio build` (Vite build completed).
  - Round 3 PR evidence on 2026-04-30: posted PR #339 comment `4348336896`; replied to and resolved review thread `PRRT_kwDOGW7Gic5-mRRP`; `gh pr view 339 --repo wcpos/monorepo --json state,mergeable,mergeStateStatus,statusCheckRollup,reviewDecision,headRefOid,url` reported head `a065b9109c277b0031fe983a77fcd813c46c50cd`, `mergeable: MERGEABLE`, `mergeStateStatus: BLOCKED`, and new CI pending (`Analyze`, `Lint`; unit tests not yet queued in that check snapshot).
- What remains:
  - PR #339 is merged. `gh pr view 339 --repo wcpos/monorepo --json state,mergedAt,mergeCommit,headRefOid,url,statusCheckRollup,reviewDecision` reported `state: MERGED`, `mergedAt: 2026-04-30T00:00:06Z`, merge commit `f94d658508f5fcd196efce9da7a3208c20df33c7`, and head `a065b9109c277b0031fe983a77fcd813c46c50cd`.
  - Post-merge deploy E2E had an unrelated-looking failure in shard 5/6 (`pos-refunds.spec.ts` could not find `Process Refund`; two cart/checkout tests were flaky and passed on retry). Unit tests, lint, merge gate, CodeQL, dependency review, and the deploy job itself completed successfully. No Template Studio-specific follow-up was identified from the failed log excerpt.
  - Have the maintainer use Studio to tune/approve bundled gallery templates visually.
  - Copy or regenerate approved preview assets into the plugin gallery-owned asset directory and wire `packages/template-gallery` to use committed preview assets for bundled/gallery templates only.
  - Start PR 4 only after the maintainer is satisfied the Studio loop is coherent and the gallery-preview handoff/asset decision is settled.
- Changed assumptions/follow-up decisions:
  - Preview image artifacts are PNG because the Playwright screenshot path used by the harness supports PNG reliably; WebP can be revisited if/when an image optimization step is added.
  - The PR keeps generated previews local to `monorepo-v2` by default to make the harness self-contained; cross-repo gallery UI consumption is left as the next plugin-side step.
  - The full matrix remains local/ephemeral; committed snapshots stay curated to avoid overbuilding drift reporting.

- Plugin-side follow-up status — 2026-04-30 / PR #837 opened (`feature/template-gallery-preview-assets`, head `c34dba8434b296e3fd444987c0e2dd7931b9214f`):
  - Completed:
    - Copied the 15 Template Studio generated PNG gallery previews from monorepo-v2 merge `f94d658508f5fcd196efce9da7a3208c20df33c7` into `assets/img/template-gallery/previews/` in `woocommerce-pos`.
    - Added `previewBaseUrl` to the Template Gallery inline bootstrap data and wired gallery cards to render lazy-loaded committed preview images for bundled/gallery templates only.
    - Preserved the thumbnail preview button accessible name with `aria-label` and used `object-contain` so portrait receipt previews are not cropped inside the existing card thumbnail frame.
    - Added Template Gallery tests that verify every bundled gallery template has a committed preview image and that gallery cards render the image URL/accessibility affordance.
  - Verification run:
    - `pnpm install --no-frozen-lockfile` in the worktree to create local `node_modules` after `pnpm install --frozen-lockfile` was blocked by pre-existing override/lockfile config mismatch.
    - Red test: `pnpm --filter @wcpos/template-gallery test` failed because `../preview-assets` did not exist and `TemplateCard` still rendered the text placeholder.
    - Green/final checks: `pnpm --filter @wcpos/template-gallery test` (2 files / 7 tests passed); `pnpm --filter @wcpos/template-gallery build` (Vite build completed); `pnpm exec wp-env run --config /tmp/wp-env-template-gallery-preview-assets.json --env-cwd='wp-content/plugins/template-gallery-preview-assets' tests-cli -- php -l includes/Admin/Menu.php` (no syntax errors); `codex review --base main` (final pass found no discrete correctness issues).
    - Blocked checks: `pnpm --filter @wcpos/template-gallery lint` fails before linting code because ESLint 10 no longer honors the package's legacy `.eslintrc` setup (`ESLint couldn't find an eslint.config.(js|mjs|cjs) file`). Attempted wp-env PHPCS via `composer run lint -- includes/Admin/Menu.php`, but the container had no vendor; `composer install` inside wp-env failed extracting `ramsey/uuid`, so PHPCS did not run.
  - PR evidence:
    - `gh pr view 837 --repo wcpos/woocommerce-pos --json url,state,headRefOid,mergeable,mergeStateStatus,statusCheckRollup,isDraft` reported PR #837 open, non-draft, head `c34dba8434b296e3fd444987c0e2dd7931b9214f`, `mergeable: MERGEABLE`, `mergeStateStatus: BLOCKED`, with CI/CodeRabbit pending immediately after opening.
  - What remains:
    - Wait for PR #837 CI/review to settle and address any actionable feedback.
    - Maintainer still needs to visually approve/tune the actual gallery template output in Studio; if the approved outputs change, regenerate/replace these committed PNGs from the Studio generator before merging/finalizing the gallery asset checkpoint.
    - PR 4 remains gated until the Studio loop/thumbnail workflow is accepted.

**Next-agent operating note — 2026-04-30:**

PR #339 is merged and plugin-side gallery preview asset integration has been opened as PR #837. The next safe checkpoint is to get PR #837 through CI/review, then have the maintainer visually approve/tune the Studio-rendered templates and regenerate/replace the PNGs if needed. Do not start PR 4 solely because PR #339 merged; PR 4 remains gated on accepting the Studio loop/thumbnail workflow.

#### PR 4 — Replace WP Admin non-legacy previews with JS renderer

**Repo:** `woocommerce-pos`

**Goal:** remove runtime dependence on PHP rendering for `logicless`/`thermal` admin previews.

**Likely files:**

- `packages/template-editor/src/`
- `packages/template-gallery/src/`
- `packages/thermal-utils/` deletion
- package manifests and lockfiles
- `includes/API/Templates_Controller.php`

**Deliverables:**

- `packages/thermal-utils/` deleted.
- Template editor/gallery import the published/browser-safe renderer.
- Non-legacy preview UI renders from `{ template_content, receipt_data }` client-side.
- HTML is sanitized before DOM insertion.
- WP Admin preview uses a sandboxed iframe where applicable.
- Temporary PHP `preview_html` diagnostic path is removed for non-legacy engines.

**Verification:**

- Template editor/gallery tests.
- Security fixture tests for script tags, event handlers, `javascript:` URLs, and SVG/script cases.
- Package lint/build.
- Targeted wp-env PHPUnit for final preview endpoint shape.

#### PR 5 — Convert public print URL and delete PHP non-legacy renderers

**Repo:** `woocommerce-pos`

**Goal:** make public receipt printing use the same JS renderer for non-legacy templates, then remove the obsolete PHP renderer paths.

**Likely files:**

- `includes/Templates/Receipt.php`
- `includes/Template_Router.php` if routing changes are needed
- `includes/Services/Receipt_Renderer_Factory.php`
- `includes/Templates/Renderers/Logicless_Renderer.php` deletion
- `templates/gallery/thermal-receipt.php` deletion
- print bundle assets/source chosen during implementation
- PHPUnit tests under `tests/includes/Templates/`

**Deliverables:**

- Public print URL validates `order_id` + `order_key` in PHP.
- PHP builds `receipt_data` and embeds/localizes escaped JSON + template metadata into a thin shell.
- JS print bundle renders with `@wcpos/receipt-renderer`, sanitizes output, and calls `window.print()` after render completes.
- Legacy-PHP templates continue to render server-side.
- `Logicless_Renderer`, `render_logicless_preview()`, `render_thermal_html_preview()`, and `templates/gallery/thermal-receipt.php` are deleted.
- `Receipt_Renderer_Factory` only returns `Legacy_Php_Renderer`.

**Verification:**

- wp-env PHPUnit for receipt route behavior and factory behavior.
- Browser/manual smoke test of public print URL for one logicless, one thermal, and one legacy-php template.
- JS bundle lint/build.

### Acceptance gates

Do not proceed from one phase to the next until these gates pass:

1. **After PR 1:** ✅ complete in merged PR #834 — non-legacy preview response shape is stable and schema generation works.
2. **After PR 2:** ✅ complete in merged PR #335 — renderer package can be imported in browser/admin contexts without native transport dependencies.
3. **After PR 3:** the maintainer can tweak bundled/gallery templates in Studio, approve the final visual output, then generate gallery thumbnails from the canonical renderer and see them in the gallery UI.
4. **After PR 4:** WP Admin previews no longer use PHP for non-legacy engines and security tests pass.
5. **After PR 5:** public print URL works for non-legacy engines and the PHP logicless/thermal preview code is removed.

### Decisions already made

- Studio's first Phase 1 value is the template tuning loop. Gallery preview images and snapshots are generated only after bundled/gallery templates are visually approved.
- Gallery preview images are generated only for bundled/gallery templates, not user-created DB templates.
- Drift reporting stays lightweight: curated snapshots plus optional artifacts/summaries. No dashboard or reporting subsystem.
- `Logicless_Renderer` can be deleted in this update once JS rendering replaces all non-legacy uses.
- Public print URL should not fetch admin-only preview REST data. PHP validates the order key and embeds/localizes the resolved payload into the shell.
- `@wcpos/printer` should not be published directly for WP Admin use. Extract `@wcpos/receipt-renderer`.

### Known pitfalls

- The preview endpoint currently has inconsistent response shapes; tests should lock down both temporary Phase 1 and final Phase 2 behavior.
- `template_id` can be numeric or string because virtual/gallery IDs use string keys.
- DOMPurify cannot run inside a no-script sandboxed iframe; sanitize before assigning HTML to `srcdoc` or page DOM.
- `sandbox="allow-same-origin"` weakens isolation. Start stricter and only add it if required.
- Thermal HTML preview and ESC/POS bytes are not byte-equivalent. Compare AST/semantic behavior, not pixels.
- Do not recreate a drift-reporting product while building thumbnail generation; thumbnails are the primary committed visual artifact.


## Resolved decisions

1. **Studio location:** `monorepo-v2/apps/template-studio/`.
2. **Mustache implementation:** standardize on mustache.js semantics for non-legacy engines after Phase 1 audit confirms bundled/gallery templates do not rely on PHP-only behavior.
3. **Print URL bundle format:** ship as a versioned local plugin asset loaded by the print shell. Do not use a CDN; inline only if the final bundle is tiny enough to justify it.
4. **Drift output scope:** keep drift output lightweight — curated snapshots plus optional local/CI artifacts for renderer-touching PRs. The gallery preview image generator is the primary committed visual output; avoid building a full drift-reporting subsystem unless the manual audit proves painful.
