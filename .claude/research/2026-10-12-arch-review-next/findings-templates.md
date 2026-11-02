# Explorer findings — templates / receipts / gallery / display

Read on Free `837e985f` and Pro `05f06c4`. Verbatim explorer output. Template-type literal site counts and Pro's read of `_wcpos_active_display_templates` (Pro `Customer_Display.php:275`, Pro `Stores.php:442-444, 573`) re-verified by the orchestrator.

## A) Deepening candidates

### 1. Template type is a concept with no owner. Strong.
- Files: `includes/Templates.php` (1592), `includes/API/V1/Templates_Controller.php` (1692), `includes/Admin/Templates/Single_Template.php` (649), `includes/Services/Receipt_Data_Schema.php` (2411), `Receipt_Preview_Fixture_Loader.php`, `packages/template-gallery/src`, `packages/template-editor/src/app.tsx`; Pro `Customer_Display.php:272-302`, Pro `Templates/Display.php:61-98`.
- `SUPPORTED_TYPES` (`Templates.php:38`) is only a list. Literal type comparisons: `Templates.php` ~10-11 (427, 432, 513, 566-567, 598, 602, 640, 662, 666, 674); `Single_Template` 8 (243, 246, 402, 408, 596, 601, 613, 617); `Receipt_Data_Schema` 5-6 (1393, 1396, 1680, 1689, 2136); `Templates_Controller` 4-5 (389, 1000, 1044, 1052, 1135 — special-cases display, closure, report); `Fixture_Loader` 2; `Receipt.php:567`; `Receipts_Controller:299`. ≈33 PHP sites + ≈20 gallery TS (7 files) + ≈11 editor `app.tsx` + 7 Pro.
- Rules that differ by type, each in a different file: default path (report/closure use `gallery/<type>-default.html`, `Templates.php:662`); engine lock (logicless-only for display; legacy PHP refused for new report/closure) written twice (`Single_Template:246`, `:408`); "active" means different things (display: one pinned Live, set on a GET `Templates_Controller:389`; receipt: first enabled `Templates.php:785`); starter content (`Single_Template:596-620`); field tree; preview data from four sources (receipt: `Preview_Receipt_Builder`; closure: fixture + real `build_closure_document` `Fixture_Loader:39-51`; report: raw JSON fixture `:34-37`; display: Pro `packages/display/src/sample-snapshot.json` 284 lines).
- Tests: `Test_Templates.php` (76, one skip at :778), `API/Test_Templates_Controller.php` (93, 9 skips), `Test_Single_Template_Save.php` (21).
- Free↔Pro: Pro reads Free's private meta key directly and re-implements "fall back to Live" because `resolve_templates` falls back to first enabled (`Templates.php:999-1037`).

### 2. Resolving a template reference: ~9 hand-written copies. Strong.
- `Templates_Controller:981-988` (incl. gallery); `:496-510` (no gallery); `Receipt.php:574-587` (gallery, no type check); `Receipt.php:592-602` (publish + type); `Templates.php:805-821`, `:829-848`; `Print_Job_Service::load_template:245-249` (hard-codes `'receipt'`, no gallery); `Receipts_Controller:298` (closure-only workaround); Pro `Display.php:76-98` (loops `get_gallery_templates('display')` reading all 21 display files when `get_gallery_template_by_key` exists).

### 3. Gallery provenance has two writers. Worth exploring.
- `Templates::install_gallery_template` (`Templates.php:1386-1462`) vs `Gallery_Update_Status::replace_with_bundled` (`:527-575`; file 575). Docblock says "Mirrors…". Install writes literal keys (`'_template_gallery_key'` `Templates.php:1436`) while `Gallery_Update_Status` declares `META_*` constants (45-60). Registry memoised twice (`Templates::$gallery_preview_data_cache` 485; `Gallery_Update_Status::registry_versions` 212). Four Codex review rounds (fb2d2c86, 6b144f91, 5f497369, c15a37a2), all about install-time facts, two were bugs in the previous round's fix.
- Tests: `Gallery_Update_Status_Test.php` (21), `Test_Install_Gallery_I18n.php`, `scripts/tests/test-gallery-template-versions.php`.

### 4. `Receipt_Data_Schema` is four modules in one file. Worth exploring; Strong for (a).
- (a) money formatter `format_money_fields` (205-320), runs on every render (`Logicless_Renderer:45`, `Thermal_Renderer:143`, `Templates_Controller:1059`, `Single_Template:579`); (b) editor field catalogue `get_field_tree` (321-1808, one 1,487-line method; closure/display/report branches 1393-1808); (c) JSON Schema export (2135-2410; report branch ~45 lines of hand patches; closure/display fall through to receipt schema); (d) runtime validator for reports only via `Report_Document_Validator:21` (draft-04 down-convert).
- `packages/receipt-schema/scripts/export-receipt-schema.php` stubs `__()`/`_x()` just to load the file.
- Tests: `Test_Receipt_Data_Schema.php` (30); `Test_Receipt_Builders_Contract_Sync.php` checks receipt tree only; closure tree spot-checked `Test_Closure_Receipts.php:160`; display tree has no contract test. Display `ledger` fields declared in Free PHP (1689-1808) but data comes from app + Pro TS (`session.ts:198`).

### 5. Each document type's wrapper fields and identity live inside `Receipt_Data_Builder`. Worth exploring.
- 1669 lines (1477 at the parked branch's base 6c6e6701; `Preview_Receipt_Builder` 1283 → 1260). Builds receipt (444-889), refund (890-1041), closure/X-report (26-218), report (219-341). "Run the filter, then restore identity" ×4 with hand-written key lists: live 851-873 (10 fiscal keys + register/software), refund 1021-1024 (6), closure 187-191 (5), report 318-335. `software` block built 5× (176, 263, 288, 755, `Preview_Receipt_Builder:862`). `Receipt_Payload_Assembler::fiscal` derives `is_closure_document`/`is_x_report` (78-79) but report sets `is_report_document` by hand (`:245`).
- Parked "two source adapters" candidate: friction shrank after #2040; still marginal at +245; the new types moved the friction across document types inside one builder.
- Tests: `Test_Receipt_Data_Builder.php` (79), `Test_Closure_Receipts.php`, `Test_Reports_Registry.php`, `Test_Receipt_Payload_Assembler.php`. ADR 0039: no conflict.

## B) Code smells
- Dead: `Templates.php:1147-1158` `get_starter_templates` always `[]` → `install_starter_template` (1331-1385) unreachable; `Templates.php:1110-1139` `migrate_active_template_to_order` no callers (would delete the display Live pin); `templates/closure.php` unreachable in prod (only `Test_Closure_Receipts.php:42`); `Thermal_Html_Renderer.php:46` ignores `$receipt_data`, unreachable via factory (`Receipt.php:291`, `Template_Pdf_Service.php:52` handle thermal first).
- Duplicated: `Templates_Controller::render_logicless_preview` (1200-1232) copies `Logicless_Renderer::render` (36-65) minus `Barcode_Image::replace_markup` and print-color-adjust; paper-width parsing ×2 (`Thermal_Html_Renderer:98-107`, `Template_Pdf_Service::thermal_paper_width_pt`); engine→language map ×2 (`Single_Template:25`, `Templates.php:1428-1434`), engine→output type `Single_Template:418`; closure-print capability ×2 (`Receipts_Controller:395`, `Receipt.php:521`); two print counters with different concurrency (`GET_LOCK` vs transaction; `render(int)`+`mark()` vs `render(array)`), callers branch at `Receipts_Controller:171,326-344`, `Receipt.php:147,227`.
- Wasted reads: `install_gallery_template` (`Templates.php:1387`) reads every gallery file; Pro `Display.php:80` same.
- Reads that write: `Templates.php:790` deletes an option during a read; `Templates_Controller:389-392` writes the Live pin on GET.
- Docblocks: 12 say "receipt, report, display" omitting closure (`Templates.php:555, 728, 757, 769, 801, 825, 865, 883, 907, 925, 940, 1466`); `Gallery_Registry.php:5` "receipt/report"; `Gallery_Update_Status.php:20` "37 bundled files" (40); `Template_Pdf_Service.php:43` "only refund documents"; `Templates.php:1488` reports "for analytics"; `Frontend.php:73` "@TODO remove in 1.5.0".
- Oversized: `get_field_tree` 1,487; `build_data` 380; `Preview_Receipt_Builder::sample` 353.
- `'xreport'`/`'x_report'` both accepted (`Receipt_Payload_Assembler:78-79`). Mixed lanes: gallery `use-preview.ts:19` calls `wcpos/v1`, editor uses `wcpos/v2` (`Single_Template:603`). TS mirrors: type union ×4 (`preview-modal.tsx:20`, `active-templates-table.tsx:79, :281`, `router.tsx:18-19`); `isDocument` (`preview-modal.tsx:141`) mirrors `Templates_Controller:1000`. `Fixture_Loader::build` second param is store object OR report key (28-36). `Templates.php:598` titles every virtual report "Report" / closure "Closure". Closure PDFs download as `receipt-0.pdf`. No `error_log`.

## C) Possible live defects (none verified live)
1. Store-scoped display list marks "active" = `resolve_templates(store)[0]` (`Templates_Controller:380-383`) while the display shows the global Live (Pro `Customer_Display.php:291-292`).
2. Report previews bypass `woocommerce_pos_report_data` and `Report_Document_Validator` (`Fixture_Loader:34-37`) although receipt previews were deliberately routed through the filter (c67b777c).
3. Closure previews accept `store_id` (`Templates_Controller:1053`) but the fixture loader discards it (`Fixture_Loader:39-51`).
4. Closure with every closure template disabled: `Receipt.php:131-135` falls back to legacy `receipt.php` with an empty `WC_Order`.
5. `preview_html` loses barcodes (`Templates_Controller:1200-1232`), low.
6. Editor sample ignores the template's own preview profile (`Single_Template:601` vs `Templates_Controller:1050`).
