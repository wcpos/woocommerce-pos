# WCPOS 1.8.15 — Changelog Draft

> **The biggest WCPOS release ever.** Almost three months of work, ~330 substantive PRs. Highlights below.

## Unreleased

- **Breaking (developers):** the `WCPOS\WooCommercePOS\API\Settings` REST controller is now an alias of `API\V1\Settings`, which serves every settings section through `get_section_settings()` / `update_section_settings()`. The per-section public methods (`get_general_settings()`, `update_access_settings()`, `get_general_endpoint_args()`, `remove_license_transient()`, …) are removed without compatibility wrappers; the REST routes and `woocommerce_pos_*` filters are unchanged, and the `Services\Settings` read API (`get_general_settings()`, `get_checkout_settings()`, …) is retained as supported public surface. Code that called or overrode the per-section controller methods must move to the section API; the licence-transient clear now lives on `Init::remove_license_transient()`.
- **Breaking (developers):** `Services\Settings::save_settings()` no longer persists settings ids that have no registered Settings Section. Previously any id fell through to a generic `woocommerce_pos_settings_{id}` option write carrying the `woocommerce_pos_pre_save_{id}_settings` filter and `woocommerce_pos_saved_{id}_settings` action; it now returns a `woocommerce_pos_settings_error` (HTTP 400) and writes nothing. Extensions that stored their own settings group this way must register it via the `woocommerce_pos_register_settings_sections` action — both hooks still fire for registered sections, under the same names. Reads are unaffected: `get_settings()` never resolved unregistered third-party ids.
- Added: anonymous analytics identifier (`wcpos_anon_id`) for the admin welcome screen — random UUID, no store data, deleted on uninstall, manageable via `wp wcpos anon-id rotate|delete`. Landing data contract `schema_version` bumped to 2.
- Added: `woocommerce_pos_consent_copy` filter so the tracking-consent prompt copy can be overridden.
- Added: `woocommerce_pos_get_anon_id()` accessor (used by the Pro licence-activation request).

### 🖨️ Barcode printing fixes

- **Fixed:** thermal receipts now print the barcode symbology the template asks for. The ESC/POS and StarPRNT lanes ignored `<barcode type="…">` entirely and printed everything as Code 128 — so a template specifying EAN-13, UPC-A, ITF or Codabar produced a Code 128 barcode instead.
- **Fixed:** ESC/POS Code 128 barcodes were sent without the code-set selector Epson's `GS k` command requires. Genuine Epson hardware prints nothing at all in that case and reports no error; many clones auto-select, which is why this went unnoticed. Code 128 barcodes now carry the selector.
- **Fixed:** the ePOS-Print XML lane emitted `upca` / `upce`, which Epson's `<barcode>` element rejects. It now emits `upc_a` / `upc_e`.
- **Fixed:** the ePOS-Print XML lane now passes through symbologies Epson supports but WCPOS does not model itself — `jan13`, `jan8`, `code128_auto`, `gs1_128` and the `gs1_databar_*` family. A template asking for one of those previously reached the printer untouched; it must keep doing so rather than being folded to Code 128.
- **Fixed:** a `<barcode type="qr">` rendered noticeably smaller in the on-screen template preview than it printed. Preview and print now agree.
- **Changed:** if a barcode value does not satisfy the rules of the symbology it declares (for example a non-numeric EAN-13, or an odd-length ITF), thermal lanes now print the value as plain text instead of silently substituting a scannable Code 128. Merchants relying on the old fallback will see text where they previously got a barcode — the template's symbology or its value needs correcting.

---

## 🧾 Receipt Templates — Complete Rebuild

This release introduces an entirely new receipt template system. It started in early March as a "receipt data + multi-engine rendering" foundation and grew into a full template platform.

- **New Template Gallery** with starter templates for every common use case — thermal (58mm, 80mm, narrow), A4 receipts, invoices, quotes, packing slips, gift receipts, kitchen tickets, and right-to-left languages (Arabic, Hebrew, Persian, Urdu).
- **New Template Editor** built on CodeMirror 6 with a field picker, live preview, paper-size + engine locking, and a Sample/Order preview pill toggle.
- **Multi-engine rendering** — HTML, Logicless (Mustache), PHP, and Thermal engines. Each template can declare which engine it uses; the active template is resolved per-store and per-order.
- **Receipt Data v1 contract** — a stable, branchable data shape that custom templates can rely on: refunds, transaction IDs, line tax labels, per-row meta, fiscal/refund context, customer notes, store address composed via `WC_Countries`, structured `store.tax_ids[]`, customer tax-id labels, localized `status_label`, and a render-time `order.printed` timestamp.
- **Store-aware presentation** — receipts now respect store currency, locale, timezone, tax display setting, price presentation, opening hours, and site logo, instead of falling back to global WooCommerce options.
- **Refund-aware totals** — refunded orders keep totals positive and add Refunded/Net rows so receipts stay readable.
- **Discounts as first-class data** — each coupon emits its own discount row, with `discounts[].code` as the canonical reference and `discounts[].label` taking the coupon description when available.
- **Tax-summary guard** so templates correctly distinguish tax-inclusive vs tax-exclusive stores, and summaries use real post-discount taxable bases instead of reverse-calculating from rounded tax.
- **Black-and-white safe printing** — light tints actually print, dark reverse-out fills removed, thermal receipts made 42-column-safe with star-column support.
- **PHP gallery catalogue registry** (#1035) replaces JSON tracking for shipped templates, keeping the gallery cleaner and easier to extend.
- **Template-management admin redesign** — unified Templates table with sidebar filters, drag-and-drop ordering, inactive-but-visible status, and a kebab menu replacing the old card "Settings" link.

## 🪪 Customer & Store Tax IDs (new feature)

- **Customer tax IDs** end-to-end — reader, writer, REST exposure, persistence (#850, #863).
- **Store Tax IDs settings UI** with dedicated REST endpoints and country defaults (#851, #859, #872, #883).
- **Consolidation** — Tax IDs folded into General → Customers and into the Store Details block (#860, #924, #942).
- **Structured `store.tax_ids[]`** exposed on receipts with a derived `tax_id` scalar for legacy templates (#853, #874, #875).

## 📈 Tracking Consent & Analytics (new feature)

- **GDPR-friendly consent prompt** with a two-tier landing page (#787, #788, #790, #792, #801, #804).
- **PostHog analytics foundation** with consent gating; session recording is explicitly disabled (#795, #799).
- **Reusable `@wcpos/consent` package** so the same consent flow can be embedded elsewhere (#822, #824, #829).
- **Landing Profile service** for personalized A/B testing (#781, #784, #797).
- **Upgrade-funnel instrumentation** (#798).

## ⚙️ Settings Overhaul

- **General tab redesign** with a new Callout primitive (#864).
- **Store Details block** extracted with a Pro override slot (#868, #877, #1015).
- **General settings grouped** into Products and Customers sections (#733, #740).
- **Snackbar improvements** — pinned to viewport, aligned to content area (#867, #878).

## 🧱 Shared UI Package (`@wcpos/ui`)

Many components extracted into a shared workspace package and reused across settings, templates, and consent:

- Button (with ghost-destructive variant, form primitives) (#782, #783, #802)
- Table (#879), Combobox (#880), searchable CountrySelect (#871)
- FormSection / FormRow with `headerRight` and divider props (#796, #800)
- Toggle hardened for hosts without Tailwind preflight (#841, #842, #843)
- Skeleton loading states (#703, #704)
- Filter sidebar with horizontal rule (#998)
- Shared `@wcpos/thermal-utils` workspace package (#730)

## 🧩 Extensions Catalog

- **Redesigned cards** with body/footer layout, update badges, has-update flag, responsive grid, smaller buttons (#580, #778, #779, #780, #808, #809).
- **Auto-update field** exposed in the catalog (#566).
- **Extension settings and logs** now surface in POS admin (#821).

## 📋 Sessions & Logs

- **Sessions page** redesigned as master/detail with a shared Avatar component (#826).
- **Logs page** redesigned with grouped-by-day view, copy boxes, and a new Chip primitive (#825).

## 💳 Payments

- **Per-gateway order status settings** — Cash and Card gateways now have their own configurable post-checkout statuses (#564).
- **Refund support** added to POS Cash and Card gateways (#632).
- **POS payment gateway contract** — gateway catalog, bootstrap, and checkout coverage (#828, #830).

## 🐞 Major Bug Fixes

### POS order reliability
- Fixed `WC_Data_Exception` crash on misc product duplicate SKU (#573).
- Prevent line-item tax overrides from mutating products (#563).
- Cashier capabilities synced on plugin update, stripped on deactivation (#623, #768).
- POS capability checks added to checkout routes; cashier roles included in auth payloads (#624, #806).
- Cashier can now update orders with HPOS enabled (#662).
- New misc product meta fields handled: category, virtual, downloadable (#690).
- Variable-product empty sale prices filtered (#692).
- Optional stock restore when POS orders are deleted, with admin toggle (#701, #732).
- Line-item image IDs cast to integer in order responses (#756).
- Client-provided order creation date honored (#915).
- Historical store lookup works for trashed stores (#940).
- Receipt permission error code corrected (#1014).

### Coupon calculations (multi-round WooCommerce interaction fixes)
- Strip coupon line IDs to prevent readonly errors on order update (#613).
- Deactivate subtotal filter before `calculate_totals()` (#674).
- Preserve subtotals during coupon recalculation (WC compound-tax bug) (#675, #677, #684).
- Use POS price directly as subtotal, remove fragile filter mechanism (#686).
- Clean up temporary cache entries after coupon validation (#693).
- Coupon include/exclude sync filters fixed (#840).
- Coupon REST API: shipped (#518), moved to Pro (#588), then restored as a standard controller (#766).

### Auth & compatibility
- Prevent third-party JWT plugins from blocking WCPOS Bearer token auth (#744).
- Fix `require()` in IIFE bundle from Vite 8 CJS interop (#659, #664).
- Analytics tsconfig updated for TypeScript 6 (#708).

### Memory & performance
- Log-spam memory exhaustion from i18n thundering herd resolved (#702).
- OPFS worker added for RxDB 17 storage migration (#739, #897).

### Localization
- Translation write-location fallback and failure caching (#567).
- jsDelivr CDN trailing-slash redirect-loop fixed (#931).
- `TRANSLATION_VERSION` used for JS translation URLs (#707).
- `loadPath` interpolation conflict fixed (#729).
- Receipt dates and timestamps respect store timezone and locale (#666, #919, #937, #1029).
- Receipt gallery, template editor, Combobox, CountrySelect, and Skeleton strings all localized (#669, #681, #903, #925, #927).

## 🔧 Behind the Scenes

- **CI/build** — root build script, simpler workflows, dynamic test matrix with RC/beta auto-detection, merge gate for bot PRs, hook-docs gh-pages deploy, release-draft idempotency, GitHub App tokens for POT and update-matrix automation, Dependabot auto-merge.
- **Dependencies** — Vite 8, TypeScript 6, RxDB 17, codecov-action 6, many action upgrades; pnpm supply-chain settings tightened.
- **Wiki** — moved to a `wcpos/wiki` submodule; deprecated `.kb/` folder removed (#759, #760).
- **Security** — `TemplatesManager::save_raw_post_content` hardened with structural guards (#984).
- **Docs** — `_woocommerce_pos_data` explainer, web bundle architecture KB article (#672, #753).

---

**Stats:** 329 substantive PRs since v1.8.14, plus 60 routine translation/dependency-bot PRs folded into "Behind the Scenes".
