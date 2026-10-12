# Spec — extract the receipt money formatter from Receipt_Data_Schema (pure move)

## Goal
`Receipt_Data_Schema` (2411 lines) describes the receipt contract: field tree, JSON schema and constants. It also carries the one step every server render runs: adding `<key>_display` currency strings (`format_money_fields`, lines 192-308). Move that step, unchanged, into its own module `WCPOS\WooCommercePOS\Services\Receipt_Money_Formatter`. Re-point the four runtime callers and the gallery export script to it, and keep the old method as a delegate.

## Stakes
Medium tier: receipt rendering. If the move is wrong, every server-rendered receipt shows wrong or missing currency strings, so the body must move **byte-for-byte**, apart from `self::` → class-qualified references. Accepted risk: none beyond a faithful move. No behaviour changes are intended.

## Out of scope
Do not move `MONEY_FIELDS`, `ZERO_FALSY_MONEY_FIELDS` or `TOTAL_MONEY_KEYS`. They are contract: ADR 0039 names the zero-falsy money fields as the branching contract, and `Test_Receipt_Data_Schema.php:97-161` pins `MONEY_FIELDS` to the field tree beside it. Also do not touch: `get_field_tree`, `get_json_schema`, `packages/receipt-schema/scripts/export-receipt-schema.php`, JS `formatReceiptData`, caching changes, new filters, `_deprecated_function`, feature flags, env vars, plan files.

## Exact changes

### 1. New file `includes/Services/Receipt_Money_Formatter.php`
- Standard file docblock (`@package WCPOS\WooCommercePOS\Services`), namespace `WCPOS\WooCommercePOS\Services`, `final class Receipt_Money_Formatter`.
- Class docblock, in substance: "Adds a `<key>_display` currency string beside every money field of a Receipt Data payload. The presentation step every server render path runs (logicless, thermal, the REST preview, the editor sample). Which keys are money, and which stay zero-falsy, is contract and lives in Receipt_Data_Schema; this module only formats. Mirrors the app's `formatReceiptData`."
- `public static function format( array $data, string $currency = 'USD', array $presentation_hints = array() ): array`: the body of `Receipt_Data_Schema::format_money_fields()` (lines 205-260) moved verbatim, including the long "DO NOT change this back" comment. Change only these:
  - `array_flip( self::MONEY_FIELDS )` → `array_flip( Receipt_Data_Schema::MONEY_FIELDS )`
  - `array_flip( self::ZERO_FALSY_MONEY_FIELDS )` → `array_flip( Receipt_Data_Schema::ZERO_FALSY_MONEY_FIELDS )`
  - the recursive call `self::format_money_fields( $value, … )` → `self::format( $value, … )`
  - `self::get_price_format_args(` stays `self::` (it moves too).
- `private static function get_price_format_args(…)` (lines 262-287) and `private static function get_price_format_for_position(…)` (lines 289-308) moved verbatim, docblocks included.

### 2. `includes/Services/Receipt_Data_Schema.php`
Replace lines 192-308 (the `format_money_fields` docblock through the end of `get_price_format_for_position`) with a delegate:
```php
/**
 * Format money fields in receipt data.
 *
 * Kept for callers outside this plugin; the formatter is Receipt_Money_Formatter::format().
 *
 * @param array               $data               Receipt data array (or nested sub-array).
 * @param string              $currency           WooCommerce currency code.
 * @param array<string,mixed> $presentation_hints Receipt presentation hints.
 *
 * @return array Data with `_display` companions added beside money fields.
 */
public static function format_money_fields( array $data, string $currency = 'USD', array $presentation_hints = array() ): array {
	return Receipt_Money_Formatter::format( $data, $currency, $presentation_hints );
}
```
Leave `use WCPOS\WooCommercePOS\Logger;` in place (still used at `:2097`). The export script (`packages/receipt-schema/scripts/export-receipt-schema.php`) loads this file with a plain `require_once` and calls only `get_json_schema()`. The delegate's reference to the new class is resolved only when called, so the script needs no change. Do not change it.

### 3. Re-point callers (`Receipt_Data_Schema::format_money_fields(` → `Receipt_Money_Formatter::format(`)
- `includes/Templates/Renderers/Logicless_Renderer.php:45`. Swap the `use …\Receipt_Data_Schema;` import for `use …\Receipt_Money_Formatter;` if the schema import becomes unused.
- `includes/Templates/Thermal/Thermal_Renderer.php:143`. Same import rule.
- `includes/API/V1/Templates_Controller.php:1059`. Same import rule (check for other `Receipt_Data_Schema` uses in the file first).
- `includes/Admin/Templates/Single_Template.php:579`, inside `get_sample_receipt_data()`. It uses fully-qualified names; keep that style. If `spec-template-preview-source.md` already rewrote this method, re-point the call inside the rewritten body.
- `scripts/export-template-gallery-preview-payloads.php:32`, with its `use` line updated.
Autoloading is PSR-4 (`composer.json` `"WCPOS\\WooCommercePOS\\": "includes/"`), so the new file needs no registration.

### 4. Tests
- New `tests/includes/Services/Test_Receipt_Money_Formatter.php` (namespace `WCPOS\WooCommercePOS\Tests\Services`, `extends WP_UnitTestCase`). **Move** the three formatter tests from `tests/includes/Services/Test_Receipt_Data_Schema.php` with their docblocks: `test_format_money_fields_uses_presentation_hints` (`:347-386`), `test_format_money_fields_formats_savings_and_preserves_unknown_nulls` (`:388-415`), `test_format_money_fields_keeps_bare_keys_numeric` (`:417-465`). Rename them `test_format_uses_presentation_hints`, `test_format_formats_savings_and_preserves_unknown_nulls`, `test_format_keeps_bare_keys_numeric`, and call `Receipt_Money_Formatter::format(…)`. Delete them from the schema test file (replaced, not layered). Bring over any `use` lines they need (e.g. `Preview_Receipt_Builder`).
- Add `test_format_is_idempotent_on_its_own_output`. Arrange: the sample from `( new Preview_Receipt_Builder() )->build()`. Act: format once, then format the result again with the same currency. Assert: `assertSame( $once, $twice )`. This pins the property the preview path relies on, where pre-formatted data meets a renderer that formats again.
- Add to `Test_Receipt_Data_Schema.php`: `test_format_money_fields_delegates_to_money_formatter`. The same input through both gives `assertSame( Receipt_Money_Formatter::format( $data, 'USD' ), Receipt_Data_Schema::format_money_fields( $data, 'USD' ) )`.
- Re-point the other test callers to `Receipt_Money_Formatter::format`: `tests/includes/API/V2/Test_Reports_Controller.php:207`, `tests/includes/Services/Test_Report_Document_Validator.php:23,35`, `tests/includes/Templates/Test_Receipt_Template_Tax_Display.php:151,190`, `tests/includes/Services/Test_Receipt_Data_Builder.php:2689`. Leave comments that merely mention the old name.
- The `MONEY_FIELDS` / `TOTAL_MONEY_KEYS` / `ZERO_FALSY` contract tests in `Test_Receipt_Data_Schema.php` stay unchanged.

## Rulings to preserve
- Bare money keys stay numeric and `_display` companions are added. Zero-falsy fields keep bare `0` and an empty `_display`. Pinned by the three moved tests.
- The `report` key is passed through unformatted. Pinned by `Test_Reports_Controller.php:207`, `Test_Report_Document_Validator.php:23,35`.
- Presentation hints come from the payload when not passed. Pinned by `test_format_uses_presentation_hints`.

## Budget
Non-test: at most +45 net lines; total non-test diff at most 400 lines (lint included). Tests: net at most +60 lines. Moved lines count once on each side. If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification you can run (no Docker)
- `php -l` on every changed and new file.
- From the worktree root: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Services/Receipt_Money_Formatter.php includes/Services/Receipt_Data_Schema.php includes/Templates/Renderers/Logicless_Renderer.php includes/Templates/Thermal/Thermal_Renderer.php includes/API/V1/Templates_Controller.php includes/Admin/Templates/Single_Template.php scripts/export-template-gallery-preview-payloads.php` plus the changed test files.
- Byte check: `git show HEAD:includes/Services/Receipt_Data_Schema.php | sed -n 262,308p` must equal the two helper methods in the new file.
- `git grep -n "format_money_fields(" -- includes/ scripts/` afterwards. Only the delegate's definition should remain.
- The orchestrator will run in wp-env, **one file per invocation**: `tests/includes/Services/Test_Receipt_Money_Formatter.php`, `tests/includes/Services/Test_Receipt_Data_Schema.php`, `tests/includes/Templates/Renderers/Test_Logicless_Renderer.php`, `tests/includes/Templates/Test_Receipt_Template_Tax_Display.php`, `tests/includes/Templates/Test_Receipt_Renderers.php`, `tests/includes/API/Test_Templates_Controller.php`, `tests/includes/API/V2/Test_Reports_Controller.php`, `tests/includes/Services/Test_Report_Document_Validator.php`, `tests/includes/Services/Test_Receipt_Data_Builder.php`, `tests/includes/Admin/Templates/Test_Single_Template_Sample_Data.php`.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`, and must also `git add` the two new files (`includes/Services/Receipt_Money_Formatter.php`, `tests/includes/Services/Test_Receipt_Money_Formatter.php`). List them in your final report.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
