# Verdict — templates / receipts / gallery / display

Read on Free `837e985f`, Pro `05f06c4`. All line numbers are Free unless prefixed "Pro".

**Pro grep (applies to every section):** Pro `includes/`, `tests/`, `packages/`, `templates/` for `_wcpos_active_`, `Templates::`, `resolve_templates`, `get_active_template`, `get_gallery_template`, `install_gallery_template`, `format_money_fields`, `Receipt_Data_Schema`, `Gallery_Update_Status`, `Gallery_Registry`, `Fixture_Loader`, `Preview_Receipt_Builder`, `Single_Template`, `Templates_Controller`, `Print_Job_Service`, `Receipt_Data_Builder`, the starter/migrate names, `extends …(Templates|Receipt|Schema|Renderer|Gallery)`, and every template filter. Pro touches only: `Templates::get_active_template/get_gallery_templates/get_template/get_virtual_template` (Pro `Templates/Display.php:65,79,94-95`), `Templates::resolve_templates` + `get_active_template` (Pro `Services/Customer_Display.php:286,292`), the store-override meta (Pro `API/V1/Stores.php:433,435,442,444` **write**, `:555,:573` read; Pro `Customer_Display.php:275` read), and in tests only `Receipt_Data_Schema::get_json_schema` (Pro `Test_Display_Template.php:48`), `migrate_active_template_to_order` (Pro `Test_Templates_Resolution.php:110`). No Pro subclass, no Pro hook on a template filter.

**Explorer correction:** Pro does not only *read* `_wcpos_active_display_templates` — Pro is its **writer** (Pro `API/V1/Stores.php:433-444`); Free only reads it, composing the key at `Templates.php:1006`. A Free accessor therefore has to be a get **and** set pair before Pro can stop touching the key.

---

## 1. Template type is a concept with no owner — VERDICT: DO-RESHAPED
Strength (explorer): Strong · Risk tier: Medium
- 32 PHP template-type literal sites confirmed by grep: `Templates.php` 11 (427, 432, 513, 566-567, 598, 602, 640, 662, 666, 674), `Single_Template.php` 8 (243, 246, 402, 408, 596, 601, 613, 617), `Templates_Controller.php` 5 (389, 1000, 1044, 1052, 1135), `Receipts_Controller.php:299`, `Receipt.php:567`, `Receipt_Data_Schema.php` 4 (1393, 1396, 1689, 2136), `Receipt_Preview_Fixture_Loader.php` 2 (34, 39). The explorer's `Receipt.php:147/227` and `Schema:1680` are document-type / section checks, not template type.
- Only two per-type rules have produced defects. **Preview document** (which sample a template previews with) has three owners: `Templates_Controller.php:1044-1056`, `Single_Template.php:576-583` + `:601`, `Receipt_Preview_Fixture_Loader.php:33-51`. That split causes D3 and D6. **Live for display** is split between `Templates_Controller.php:380-392` and Pro `Customer_Display.php:272-293`, which causes D1.
- The suggested first slice is **not** the honest scope for tonight. A rows table read only by `Templates.php` and `Single_Template` leaves 13 PHP sites, about 31 TS sites (`preview-modal.tsx:20,141`, `active-templates-table.tsx:79,281`, `router.tsx:18-19`, editor `app.tsx`) and 7 Pro sites on literals. The table would become one more place to keep in step, not an owner, and it would touch neither rule that caused a defect.
- The virtual engine rule `Templates.php:566-567` is equivalent to "the resolved file ends in `.html` ⇒ logicless". I checked every (source, type) pair that `:640-662` can return. Use this when the module is built.
- Tonight's slice gives the Preview Document one owner: `Receipt_Preview_Fixture_Loader::for_template()`. The REST preview and the editor bootstrap both call it. It fixes D3 and D6, and D5 rides along because it is the same method's output.

Scope (slice): `Receipt_Preview_Fixture_Loader.php` 179 lines (+~28); `Templates_Controller.php` 1044-1056 (13 → ~8) and 1189-1232 (44 → ~20); `Single_Template.php` 566-583 (18 → ~18) and 601 (1 → 3). Net non-test ≈ −15. Non-test diff ≈ 110, plus 50-90 for lint. Ceiling 200.
Pro impact: none for the slice. A later Template_Type PR adds `Templates::get_store_template_ids()` / `set_store_template_ids()`, followed by a Pro PR that re-points Pro `Customer_Display.php:275` and Pro `API/V1/Stores.php:433-444,555,573` (**Free accessor first, then Pro follow-up**).
Tests that pin behaviour today: `tests/includes/API/Test_Templates_Controller.php` (preview tests 1308-1905, especially 1576, 1644, 1671, 1693, 1711), `tests/includes/Admin/Templates/Test_Single_Template_Sample_Data.php` (31, 53, 77), `tests/includes/Services/Test_Receipt_Preview_Fixture_Loader.php` (11 tests), `Test_Preview_Receipt_Builder.php`. Tests to add are listed in the spec. None are deleted.
ADR / CONTEXT.md: no ADR 0039 conflict. These are data sources and engine rules, not template features. New terms:
- **Template Type**: The kind of document a template renders: `receipt`, `display`, `report` or `closure`. It decides the Preview Document, the engines a new template may use, whether one template is pinned Live, and the field tree the editor offers. It is not `fiscal.document_type`: one closure template prints both closures and X-reports. _Avoid_: template kind, document type.
- **Preview Document**: The sample Receipt Data a template is shown with when no order is named. Its Template Type picks the source (the report fixture, the closure fixture, or the sample receipt), its gallery profile picks the overrides, and the requested store supplies the identity. Decided in one place and used by the REST preview and the editor alike. _Avoid_: sample data, mock data, fixture (for the whole).

Sequencing: the slice first. The Template_Type module follows in its own PR after Paul answers Q1: engine locks written twice (`Single_Template.php:243-251` and `:402-409`), core default path (`Templates.php:640-662`), Live pin, order-previewable (`Templates_Controller.php:1000`). TS mirrors come after that, fed from a PHP-emitted table.
Tonight? YES for the Preview Document slice (`spec-template-preview-source.md`). NO for the Template_Type module, which needs the Pro accessor plan and Paul's ruling on the Live pin that is written on GET.

## 2. One template-reference resolver instead of ~9 copies — VERDICT: DO-RESHAPED (a dedup, not a module)
Strength: Strong · Tier: Medium
- 8 Free copies confirmed: `Templates.php:813-818`, `:831-841`; `Templates_Controller.php:500-506`, `:981-988`; `Receipt.php:575-591`, `:596-614`; `Print_Job_Service.php:245-249`; `Receipts_Controller.php:298`. The 9th is Pro `Display.php:76-98`.
- The copies vary on three axes. Two belong in lookup: gallery fallback (`:981-988`, `Receipt.php:575-614`) and numeric-never-falls-back. The third, the type/publish policy (`Receipt.php:602,611`, `Receipts_Controller.php:299`, Pro `Display.php:96`), is caller policy and stays at the call sites.
- `Print_Job_Service::load_template` hard-codes `'receipt'` (`:248`). That is the only reason `Receipts_Controller.php:298` hand-codes a closure branch. An optional `$type` parameter removes the workaround with identical results.
- Two named methods instead of a bool flag: `Templates::find_template( $reference, $type )` and `find_template_or_gallery( $reference, $type )`. Each body is about 5 lines.
- `install_gallery_template` loops over every gallery entry, reading all 40 files (`Templates.php:1387-1395`), where `get_gallery_template_by_key()` (`:1314-1322`) reads one file and returns the same set (both skip entries with no content file).

Scope: `Templates.php` (+~30 −~30), `Templates_Controller.php` (−8), `Receipt.php` (−12), `Print_Job_Service.php` (±2), `Receipts_Controller.php` (±1). Merged with #3 and the dead-code stubs below, net non-test ≈ −60. Diff ≈ 200, plus lint. Ceiling 280.
Pro impact: none required. Only optional parameters are added, and Pro never calls `Print_Job_Service`. Pro follow-up: `Display.php:79-98` → `get_gallery_template_by_key()` + `find_template(…,'display')`, which stops reading all 21 display files per preview.
Tests that pin behaviour today: `Test_Receipt.php` 373-510 (`?template=` publish, type, virtual and gallery cases), `Test_Templates_Controller.php` 1711 and get-item tests, `Test_Closure_Receipts.php:585` (type mismatch), `Test_Templates.php` active-template tests 900-1106 and 1446, `Print_Job_Service_Test.php`. Tests to add are in the spec. None are deleted.
ADR/CONTEXT: none. Optional term **Template Reference**: a numeric id names a stored template of any type, and any other string names a virtual template of the requested type (in preview and render paths, a gallery key). _Avoid_: template slug.
Sequencing: **merge with #3 into one PR.** Both rewrite `install_gallery_template`. Keep it separate from #1.
Tonight? YES (`spec-template-reference-lookup.md`). Mechanical, and every behaviour it touches is pinned.

## 3. Gallery provenance has two writers — VERDICT: DO-RESHAPED (small; folded into #2)
Strength: Worth exploring · Tier: Medium. A wrong write here silently overwrites a merchant template, but the change only moves writes.
- Provenance meta is written in four places: `Templates.php:1437-1438` (key and version as literal strings, although `Gallery_Update_Status.php:45-60` declares constants), `Templates.php:1460` (hash and locale through `record_source_hash`), `Gallery_Update_Status.php:390-395` (backfill) and `:569-571` (replace). Only the install path sits outside the class.
- The four Codex rounds the explorer cites were about the timing of hash and locale. That logic already lives in `record_source_hash` (`:113-129`). The split that remains is key and version.
- Do **not** share the content-write step. Install inserts and then raw-overwrites (`Templates.php:1446-1455`). Replace raw-writes and then must move `post_modified` (`Gallery_Update_Status.php:544-567`, `:555`). A shared helper would need a mode flag.
- The two memo caches (`Templates.php:55,485`; `Gallery_Update_Status.php:212`) memoise different projections. Leave them.

Change: add `Gallery_Update_Status::record_install( int, string, int, string )`, called once by install after the raw save. That makes the class the sole provenance writer. Tests: `Gallery_Update_Status_Test.php` 113, 130, 461 pin install provenance. Add one `record_install` test.
Tonight? YES, inside `spec-template-reference-lookup.md`.

## 4. Receipt_Data_Schema is four modules in one file — VERDICT: DO for (a); DEFER (b)(c)(d)
Strength: Worth exploring; Strong for (a) · Tier: Medium
- (a) `format_money_fields` and its two private helpers (`Receipt_Data_Schema.php:192-308`, 117 lines) have 4 runtime callers (`Logicless_Renderer.php:45`, `Thermal_Renderer.php:143`, `Templates_Controller.php:1059`, `Single_Template.php:579`), one script (`scripts/export-template-gallery-preview-payloads.php:32`) and test callers in 7 files. It is a pure move.
- **Explorer got this wrong:** the constants stay. `ZERO_FALSY_MONEY_FIELDS` is the template-branching contract that ADR 0039 names, and `Test_Receipt_Data_Schema.php:97-161` pins `MONEY_FIELDS` to the field tree beside it. Moving the constants would also make `packages/receipt-schema/scripts/export-receipt-schema.php:37-38` (plain `require_once`, no autoloader) depend on a second file.
- Leverage is modest. Render paths depend on a ~150-line formatter instead of a 2411-line schema, and the Schema's interface loses its one render-time function. No bug class goes away. It is low risk, so it gets the last slot.
- (b) `get_field_tree` (`:321-1801`) is one 1,481-line method. Splitting it per type is mechanical but means about 1,500 lines of diff: DEFER. The display `ledger` fields (`:1689-1808`) are declared in Free for data that only the app and Pro produce (`session.ts:198`). That is an ownership question for later.
- (c) JSON-schema export and (d) the report-only validator (`Report_Document_Validator.php:21`): DEFER.

Scope (a): Schema 2411 → ~2308; new `Receipt_Money_Formatter.php` ~145. Net non-test ≈ +35. Diff ≈ 300, plus lint. Ceiling 400.
Pro impact: none. Pro calls only `get_json_schema`, in tests.
Tests: move the three formatter tests (`Test_Receipt_Data_Schema.php:347-465`) into a new `Test_Receipt_Money_Formatter.php`, add a delegate-equivalence test, and re-point the test callers.
Tonight? YES (`spec-receipt-money-formatter.md`).

## 5. Per-document identity restore inside Receipt_Data_Builder — VERDICT: DEFER
Strength: Worth exploring · Tier: High-adjacent (fiscal identity and closure figures are trust)
- Four restores with four shapes: live, only when a snapshot exists (`Receipt_Data_Builder.php:858-873`: 10 fiscal keys plus register/software); refund, a 6-key merge (`:1022-1026`); closure, identity plus the whole `closure` section plus re-derived flags (`:187-207`); report, key/title/scope plus per-section fields (`:318-335`).
- A shared helper would have to express merge, replace-section and conditional-on-snapshot: a mode-flagged, shallow module that saves about 20 lines.
- What the restores protect is spelled out at `:192-204`: an extension rewriting `variance_display` could print a short drawer as balanced. Stakes-tiers says correctness beats brevity here.
- Cosmetic leftovers: the `software` block built ×5 (`:176, :263, :288, :797`, `Preview_Receipt_Builder.php:862`); `is_report_document` set by hand (`:246, :269`) while closures derive it (`Receipt_Payload_Assembler.php:78-79`, which also accepts both `xreport` and `x_report`).

Tests: `Test_Receipt_Data_Builder.php`, `Test_Closure_Receipts.php`, `Test_Reports_Registry.php`, `Test_Receipt_Payload_Assembler.php`.
Tonight? NO. High-adjacent, and the payoff is low until a fifth document type arrives.

---

## D1. Store-scoped display list marks the wrong template "active" — VERIFIED (latent)
Trace: `GET templates?type=display&store_id=N` → `Templates_Controller.php:380` `resolve_templates(N,'display')` → `Templates.php:1004-1012`: with no override, the global enabled list is returned in stored order → `Templates_Controller.php:381-382` sets active = `enabled[0]`. The pin at `:389` is skipped when `$store_id` is set. The display itself: Pro `Customer_Display.php:275` finds no override → key `live` → `:292` `get_active_template('display')`, which reads option `wcpos_active_template_display`. With order [A, B] and Live = B, the list marks A while the display shows B.
Failing test: `tests/includes/API/Test_Templates_Controller.php::test_get_items_store_scoped_display_marks_global_live_active`. Arrange: two published display templates A and B, `save_template_order([A,B],'display')`, `set_active_template_id(B,'display')`, a store post with no override meta. Act: GET `/wcpos/v2/templates?type=display&store_id=<id>`. Assert: B `is_active` true, A false.
Severity: Low. No shipped client reads this list: Pro store-edit fetches global lists (Pro `packages/store-edit/src/hooks/use-global-templates.ts:10`), and the studio fetches without a store (monorepo `apps/template-studio/src/studio-api.ts:20`). The fix is about 8 lines in the controller plus the Free accessor. It belongs to #1's Template_Type PR. Not tonight.

## D2. Report previews bypass `woocommerce_pos_report_data` — VERIFIED (gap, not a regression)
Trace: `Templates_Controller.php:1044-1045` → `Receipt_Preview_Fixture_Loader.php:34-37` returns raw `report-<key>.json`. It never reaches `Receipt_Data_Builder::build_report_document` (filter at `:308`, identity restore at `:318-335`) or `Report_Document_Validator`. Receipt previews deliberately run their filter (`Preview_Receipt_Builder.php:508-527`, c67b777c).
Failing test: `Test_Templates_Controller::test_report_preview_runs_report_data_filter`. A filter adds `extension_note`; preview the core report; assert the key appears in `receipt_data`.
Severity: Low (editor and gallery only). Not a one-liner: the filter's third argument is a resolver-shaped scope that the fixture does not have. DEFER. Once the slice lands, `for_template()`'s report branch is the one place to add it (Needs Paul Q2).

## D3. Closure previews ignore the requested store — VERIFIED
Trace: `Templates_Controller.php:1030-1040` validates `store_id` → `:1049` `$pos_store` → `:1053-1054` `Fixture_Loader::build('closure', $pos_store)` → `Receipt_Preview_Fixture_Loader.php:39-50` never reads `$pos_store`. The row's `store_id` is the fixture's `1` (`templates/gallery/preview-data/closure.json:11`) → `Receipt_Data_Builder.php:89-90` `wcpos_get_store(1)`. In multi-store Pro, previewing a closure for store N shows the default store's name, address, locale and timezone.
Failing test: `Test_Receipt_Preview_Fixture_Loader::test_for_template_closure_uses_the_given_store`.
Severity: Low. A 3-line fix in the loader. In `spec-template-preview-source.md`.

## D4. Closure with every closure template disabled renders a sale receipt and counts a print — VERIFIED
Trace: `Receipt.php:125` `get_custom_template('closure')` → `:617` `get_active_template('closure')` → `Templates.php:806-809` returns null when nothing is enabled. Nothing prevents this: `Templates_Controller.php:645-653` lets the admin disable `plugin-core`. Back at `Receipt.php:139` → `include receipt.php` with an empty `WC_Order` and the closure payload. With `intent=print`, `:147` counts → `Closure_Print_Counter.php:32` `record_print` → `:41-46` COMMIT (output is non-empty) and `log_printed`. The closure's audit count goes up for a page that printed an empty sale. The PDF path already refuses with a 404 (`Receipt.php:203-210`).
Failing test: `tests/includes/API/V2/Test_Closure_Receipts.php::test_storefront_closure_without_enabled_template_is_refused_and_not_counted`, using the existing `render_receipt_page()` harness (`:695-742`).
Severity: Medium, because it corrupts an audit count. One file, about 6 lines at `Receipt.php:125`. Tonight YES (`spec-closure-without-template.md`).

## D5. `preview_html` loses barcodes — VERIFIED
Trace: `Templates_Controller.php:1200-1232` is a copy of `Logicless_Renderer.php:36-95` without `Barcode_Image::replace_markup` (`:72-73`) and without the print-color-adjust allowance (`:80-94`). `format_money_fields` is idempotent on its own output (`Receipt_Data_Schema.php:219-256`: bare keys stay numeric and `_display` is recomputed identically), so delegating is safe.
Failing test: `Test_Templates_Controller::test_preview_html_keeps_barcode_images`.
Severity: Low (a diagnostics-only field, per `:1078-1081`, `:1121-1124`). A −25-line delegate. In `spec-template-preview-source.md`.

## D6. Editor sample ignores the template's preview profile — VERIFIED
Trace: `Single_Template.php:601` → `get_sample_receipt_data()` (`:576-583`) → `Preview_Receipt_Builder::build()`, which ignores `$template['preview_data']` (filled from the gallery key at `Templates.php:486-492`). The REST preview honours it (`Templates_Controller.php:1050-1056`). For an installed invoice, the gallery shows unpaid INV-2048 while the editor shows a paid generic sale.
Failing test: `Test_Single_Template_Sample_Data::test_editor_bootstrap_uses_installed_template_preview_profile`.
Severity: Low. In `spec-template-preview-source.md`.

---

## Vetted dead-code list
| Item | Visibility | Free refs (beyond definition) | Pro / `packages/` / `templates/` refs | Delete BC-safe tonight? |
|---|---|---|---|---|
| `Templates::get_starter_templates()` `Templates.php:1147-1158` | public static | `:1332`; `Test_Templates.php:1114` | none | **No** (public API). Reducing it to `return array();` with `@deprecated` is **yes**, and is in spec-template-reference-lookup. |
| `Templates::install_starter_template()` `:1331-1385` | public static | none | none | **No** delete. Gutting it to return its only reachable result (the `invalid_starter` WP_Error) is **yes** and saves about 45 lines. In spec. |
| `Templates::migrate_active_template_to_order()` `:1110-1139` | public static | none in `includes/` | **Pro test** `Test_Templates_Resolution.php:110` | **No.** The Pro suite calls it. Run for `display`, it would also delete the Live pin. Remove in a paired Free and Pro PR. |
| `Templates::set_active_template(int)` `:853-861` | public static, "legacy" | `Test_Templates.php:1446` only | none | **No** (public). Deprecate. |
| `Templates::get_default_template()` `:761-764` | public static | `Test_Templates.php:1067` only | none | **No** (public). |
| `Templates::is_active_template()` `:973-985` | public static | `Test_Templates.php` only | none | **No** (public). |
| `templates/closure.php` (141 lines) | template file | `Test_Closure_Receipts.php:42` uses it as the render oracle | Pro `templates/` has none; the theme override path `Templates.php:645-647` does not need Free's copy | **No.** Rewrite `html()` onto `gallery/closure-default.html` first (Needs Paul Q3). |
| `Thermal_Html_Renderer` (110 lines) | public class | `Receipt_Renderer_Factory.php:34`; `Test_Receipt_Renderers.php:433-502` | none | **Keep.** It is unreachable in production (`Receipt.php:290-297` and `Template_Pdf_Service.php:52` handle thermal first), but it is the factory's guard against running raw thermal XML through the PHP renderer (`Receipt_Renderer_Factory.php:31-33`). |
| Hook `woocommerce_pos_template_redirect` `Templates/Frontend.php:70-77` ("@TODO remove in 1.5.0") | public hook | — | none | **No.** Removing a public hook is a release call. |

Not dead: `Templates::is_pro_license_active` (`Analytics_Profile.php:98`), `Single_Template::get_sample_receipt_data` (`:601` and a test).

## Recommended tonight order
1. `spec-closure-without-template.md`: D4, a real audit-count bug, about 6 lines and fail-closed.
2. `spec-template-preview-source.md`: the #1 slice; fixes D3, D5 and D6.
3. `spec-template-reference-lookup.md`: #2, the #3 slice, the starter stubs and docblock fixes.
4. `spec-receipt-money-formatter.md`: #4(a), a pure move.

Run these in order, one at a time. Specs 2 and 4 both touch `Single_Template.php:576-583` and `Templates_Controller.php:1059`; specs 1 and 3 both touch `Receipt.php` (different hunks). Each spec says how to adapt to the previous one.

## Needs Paul
1. **Template_Type module next:** may the next PR encode the engine locks, the core default path and the display Live pin as Template_Type rows? It would move the Live pin write out of the admin GET (`Templates_Controller.php:389-392`) to the write paths, and add `Templates::get_store_template_ids()` / `set_store_template_ids()` with a Pro follow-up re-pointing Pro `Customer_Display.php:275` and `API/V1/Stores.php:433-444,555,573`. Recommended: yes.
2. **Report previews and extensions (D2):** should report previews run `woocommerce_pos_report_data` against a synthetic fixture scope, as receipt previews run theirs? Recommended: yes, once the slice lands.
3. **Retired surfaces:** delete `templates/closure.php` (rewriting the test oracle onto the gallery default), and set a release for removing `migrate_active_template_to_order`, `set_active_template`, `get_default_template`, `is_active_template` and the `woocommerce_pos_template_redirect` hook? Recommended: delete closure.php now; deprecate the rest for one minor version.
