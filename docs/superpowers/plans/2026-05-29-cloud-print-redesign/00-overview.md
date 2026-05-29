# Cloud Print Redesign — Overview & Phase Map

> **For agentic workers:** This is the master plan for a multi-phase project. Each phase is a separate PR, implemented in its own context window. Before starting a phase, read: (1) this overview, (2) the previous phase's `HANDOFF-phase-N.md`, (3) that phase's own plan file. Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to run a phase. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Redesign WCPOS Cloud Print into a clear, non-technical settings screen, add PrintNode as a provider, surface printer connection status / test-print / token management, and make auto-print rules select an active receipt template that is rendered server-side to the right wire format per printer.

**Architecture:** Printers are registered in the `woocommerce_pos_settings_cloud_print` option. Two cloud delivery models coexist: (a) **direct** — Star CloudPRNT / Epson Server Direct Print printers poll this site over HTTP and receive printer-native markup/commands (StarPRNT / ESC-POS / ePOS-Print XML); (b) **PrintNode** — the site renders a PDF and submits it to the PrintNode API, whose local client prints to any OS-connected printer. Auto-print rules (`scope × printer × template`) fire on order create/status-change and enqueue a job rendered to the printer's format.

**Tech Stack:** PHP (WordPress/WooCommerce plugin, `WCPOS\WooCommercePOS` namespace), React + TypeScript + `@wcpos/ui` + TailwindCSS (`wcpos:` prefix) for the settings app, `@tanstack/react-query` + `@wordpress/api-fetch`, PHPUnit via wp-env, Dompdf (PDF), and a PHP port of `@wcpos/receipt-renderer` (thermal).

---

## Settled design decisions (do not relitigate)

These were decided during design; treat as fixed unless a phase uncovers a blocker (record blockers in the handoff).

1. **Feature has not shipped** → no backward-compatibility constraint on the data model. Rename/restructure freely.
2. **UI = printer cards + sentence-style auto-print rules.** Reuse `@wcpos/ui` (`Button`, `Card`, `Chip`, `Modal`, `Select`, `Toggle`, `FormSection`, `FormRow`, `Notice`/`Callout`, `useSnackbar`) — do not hand-roll atoms. Reference mockup: `docs/superpowers/plans/2026-05-29-cloud-print-redesign/cloud-print-redesign.prototype.html` (open in a browser).
3. **Printer card:** inline-editable **name** (border on hover/focus), **status Chip** (Connected / Waiting), **ⓘ** on the immutable Printer ID, **Test print** button, **⋮ menu** with *Setup & token* + *Remove* (Extensions-card pattern).
4. **Printer ID is auto-derived (slugified from name) and immutable.** It is the key for the poll URL, token hash, and rule links. The name is freely editable; the ID never changes (to change it, remove + re-add).
5. **Three providers:** `star-cloudprnt`, `epson-sdp`, `printnode`. (Field renamed from `protocol` → `provider`.)
6. **Intro copy:** the "What is cloud printing?" callout from the mockup (do not re-add 1-2-3 steps).
7. **Auto-print rule = `{ scope, printer_id, template_id }`.** The third control picks an **active receipt template** (from `POS > Templates`), not a raw wire format. The wire format is **derived** from the template + the printer's provider.
8. **Rendering model (the core architecture):**
   - **Manual cloud print** (cashier-initiated, client present): the **client** rasterizes the chosen template (reusing `monorepo-v2/packages/printer/src/raster/rasterize-provider.tsx`) and uploads the payload; the server delivers it (`image/png` → Star, base64 raster in ePOS `<image>` → Epson, PDF/RAW → PrintNode). *Server-side rasterization is NOT attempted — it is browser/canvas-only and impractical in PHP.*
   - **Auto-print → PrintNode:** render the template to **PDF in pure PHP** (Dompdf), submit to PrintNode. Full fidelity, any printer.
   - **Auto-print → Star/Epson direct:** render the chosen **thermal (XML) template** to the printer's native format via a **PHP port of `@wcpos/receipt-renderer`** (markup→commands; StarPRNT/ESC-POS for Star, ePOS-Print XML for Epson). The template dropdown is **filtered to thermal templates** for these printers.
9. **HTML/logic-less templates are not offered for Star/Epson direct auto-print** (they can't print natively there without rasterization). They are available for PrintNode (as PDF) and for manual printing (client raster).
10. **Test print** reuses the diagnostic concept from `monorepo-v2/.../printer-service.ts` (`buildDiagnosticTemplate`): a column-ruler capability check, built server-side per provider.

### Wire-format derivation table (template engine × provider → output)

| Template engine | Star CloudPRNT | Epson SDP | PrintNode |
|---|---|---|---|
| thermal (XML) | StarPRNT (or ESC-POS) | ePOS-Print XML | ESC-POS (RAW) or PDF |
| logic-less / legacy (HTML/A4) | *not offered* | *not offered* | PDF |

---

## Repos & top-level file map

- **`woocommerce-pos`** (this repo) — PHP backend + `packages/settings` React app. Primary repo for all phases.
  - `includes/API/Settings.php` — cloud-print settings REST (schema, sanitizers).
  - `includes/API/Print_Jobs_Controller.php` — printer poll endpoints + job management.
  - `includes/Services/Cloud_Print_Registry.php` — printer lookup + token verify/generate/hash.
  - `includes/Services/Print_Job_Service.php` — job CPT lifecycle, `render_payload()`.
  - `includes/Services/Cloud_Print_Trigger_Service.php` — auto-print assignment matching.
  - `includes/Templates/Adapters/*_Output_Adapter.php` — wire-format emitters (currently hardcoded layout).
  - `includes/Templates.php`, `includes/API/Templates_Controller.php` — template store + active-template query (`Templates::get_enabled_templates()`).
  - `packages/settings/src/screens/cloud-print/` — the React screen (to rebuild).
  - `packages/settings/src/hooks/use-cloud-print-settings.tsx` — settings hook.
- **`monorepo-v2`** (`/Users/kilbot/Projects/monorepo-v2`) — client app. `packages/receipt-renderer` (thermal JS reference to port), `packages/printer/src/raster/rasterize-provider.tsx` (client rasterizer), `packages/printer/src/printer-service.ts` (`buildDiagnosticTemplate`). Phase 5 only (manual-print verification).
- **`docs`** (`/Users/kilbot/Projects/docs`) — Docusaurus customer docs. New Cloud Printing page in Phase 5.
- **`.wiki`** (submodule) — internal support KB; update via `/wiki-ingest` (Opus only) per phase.

---

## Phase map (one PR each)

> Each phase must end **green**: targeted PHPUnit + (for FE) package build/lint pass, and the PR documents the exact commands run. Then write `HANDOFF-phase-N.md` (template below) before starting the next.

### Phase 1 — Backend: printer data model + lifecycle
Schema rework (provider incl. `printnode`, auto-derived immutable id), last-seen recording + status derivation, regenerate-token, test-print endpoint (Star/Epson diagnostics). **No UI, no template rendering.** Plan: `phase-1-backend-printer-lifecycle.md`. PR reviewable via PHPUnit. Depends on: nothing.

### Phase 2 — Frontend: redesigned settings screen
Rebuild `packages/settings/src/screens/cloud-print/` on `@wcpos/ui`: printer cards (inline name, status Chip, ⋮ menu, Test-print), Modal add-printer wizard (all 3 providers, provider-specific setup), sentence-style auto-print rules with active-template picker, `useSnackbar`. Wired to Phase 1 endpoints + the templates active-list endpoint. Depends on: Phase 1. PR reviewable in the app. (Rules are saved; rendering lands in P3/P4.)

### Phase 3 — Star/Epson direct: thermal template rendering
Port `@wcpos/receipt-renderer` thermal-XML→commands to PHP; make `Escpos`/`Starprnt`/`Epos_Xml` adapters **template-driven**. Extend the trigger service + `render_payload()` to render `template_id` per provider. Add format derivation + thermal-only template filtering. Parity tests vs JS fixtures. Depends on: Phase 1 (+ Phase 2 schema). PR.

### Phase 4 — PrintNode provider + PDF rendering
PrintNode API client (list printers, submit job, query state). PDF rendering of templates via Dompdf. Trigger service submits PDF/RAW to PrintNode for `printnode` printers. PrintNode test-print. Depends on: Phase 1, Phase 3 (renderer interface). PR.

### Phase 5 — Manual-print verification + documentation
Verify/complete the client manual-cloud-print path (`monorepo-v2`); new **Cloud Printing** page in `docs`; internal `.wiki` update via `/wiki-ingest`. Depends on: Phases 1–4. PR(s) (may span repos — note in handoff).

---

## Handoff protocol

At the **end** of each phase, before opening the next context window, create `HANDOFF-phase-N.md` in this directory. The next window reads `00-overview.md` + the latest handoff + the next phase plan. The handoff captures reality (which may differ from the plan).

### `HANDOFF-phase-N.md` template

```markdown
# Handoff — Phase N: <title>

**PR:** <url or branch> · **Status:** merged | open · **Tests:** <commands run + result>

## What shipped
- <bullet list of what was actually built>

## Final shapes (as implemented — source of truth for next phase)
- REST: <endpoints, methods, request/response JSON shapes>
- Data: <option keys, array shapes, meta keys, constants>
- PHP: <new/changed class + method signatures>
- TS: <new/changed types, hook signatures, component props>

## Deviations from the plan
- <anything done differently and why>

## Gotchas / for the next phase
- <traps, TODOs, assumptions the next phase depends on>

## Open questions for Paul
- <if any>
```

---

## Cross-cutting conventions (all phases)

- **TDD:** failing test first. Bug fixes require a failing test first. Name tests `test_[feature]_[scenario]_[expected_result]`. Arrange/Act/Assert. `assertEquals( expected, actual )`.
- **PHP tests run via wp-env only** (never local composer/phpunit):
  ```bash
  pnpm exec wp-env start
  pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
    vendor/bin/phpunit -c .phpunit.xml.dist <test-file> --filter <test-name>
  ```
  Use `WCPOS_REST_Unit_Test_Case` helpers (`wp_rest_get_request()`, `wp_rest_post_request()`) so the `X-WCPOS` header is included. Apply settings filters before `parent::setUp()`.
- **PHP lint:** `composer run lint` (PHPCS). Fix ALL errors in touched files, including pre-existing. Docblocks + declared properties required.
- **JS:** `pnpm` workspace; run the changed package's test/build/lint. Tailwind classes are `wcpos:`-prefixed. Strings use `t('key', 'fallback')` from `packages/settings/src/translations`.
- **Logging:** `WCPOS\WooCommercePOS\Logger`, never `error_log()`.
- **REST:** `/wcpos/v1/` routes require `X-WCPOS: 1`. Admin app uses cookie auth (`@wordpress/api-fetch`). Check auth headers with `! empty()` not `isset()`.
- **Branch/PR:** each phase on its own branch via the `/worktree` + `/pr` skills. Never hand-roll `gh pr create`.
- **Secrets:** never return `poll_token_hash` or PrintNode API keys in GET responses.
