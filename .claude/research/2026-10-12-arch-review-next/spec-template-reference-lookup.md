# Spec — one template lookup; one writer of gallery provenance

## Goal
Eight Free call sites hand-write "a numeric reference is a stored template, any other string is a virtual template of this type (sometimes falling back to a gallery key)". Replace them with two small `Templates` methods. Separately, make `Gallery_Update_Status` the only code that writes gallery provenance meta at install, and have install read one gallery file instead of all 40. The two halves are one PR because both rewrite `Templates::install_gallery_template()`. Also included: the unreachable starter-template code shrinks to its only outcome, and stale "type" docblocks are fixed.

## Stakes
Medium tier: template selection for previews, storefront receipts and print jobs. A failure prints or previews the wrong template, or rejects a valid one. The provenance writes protect merchant-edited templates from silent overwrite, so they must keep exactly the same keys, values and ordering. Accepted risks: a filter-added gallery entry whose key is numeric (never resolvable by key, same as today), and a deleted `templates/gallery/` directory (see Part B).

## Out of scope
Do not add any of these: new type or status checks inside the lookup (callers keep theirs), changes to `get_template()` / `get_virtual_template()` / `get_gallery_template_by_key()`, Pro edits, new filters, options, caching, validation layers, fallbacks, feature flags, env vars, plan files. Do not delete any public method.

## Part A — `Templates::find_template()` and `find_template_or_gallery()`

### `includes/Templates.php` (1592 lines): add after `get_virtual_template()` (which ends near line 611)
```php
/**
 * Find a template by the reference a caller names it with.
 *
 * A numeric reference names a stored template, whatever its type; any other string names a
 * virtual (filesystem) template of `$type`. Type and status policy stay with the caller.
 *
 * @param int|string $reference Template post ID or virtual template ID.
 * @param string     $type      Template type a virtual reference is resolved in.
 *
 * @return null|array Template data, or null when nothing matches.
 */
public static function find_template( $reference, string $type = 'receipt' ): ?array {
	if ( is_numeric( $reference ) ) {
		return self::get_template( (int) $reference );
	}

	return self::get_virtual_template( (string) $reference, $type );
}

/**
 * Find a template by reference, falling back to a bundled gallery entry by its key.
 *
 * For the preview and render paths, which may name a gallery template that was never
 * installed. A numeric reference never falls back.
 *
 * @param int|string $reference Template post ID, virtual template ID or gallery key.
 * @param string     $type      Template type a virtual reference is resolved in.
 *
 * @return null|array Template data, or null when nothing matches.
 */
public static function find_template_or_gallery( $reference, string $type = 'receipt' ): ?array {
	$template = self::find_template( $reference, $type );
	if ( null !== $template || is_numeric( $reference ) ) {
		return $template;
	}

	return self::get_gallery_template_by_key( (string) $reference );
}
```
Keep `is_numeric` + `(int)` exactly as the call sites do today. Do not "tighten" it, because every current site behaves this way.

### Re-point (line numbers at `837e985f`; locate by content if an earlier spec moved them)
1. `Templates::get_active_template()` lines 812-818 (the `is_numeric` branch and the virtual return): `return self::find_template( $active_id, $type );`
2. `Templates::set_active_template_id()` lines 830-841: `if ( null === self::find_template( $template_id, $type ) ) { return false; }` then the existing `update_option(...)`.
3. `includes/API/V1/Templates_Controller.php::get_item()` lines 500-506: `$template = TemplatesManager::find_template( $id, $type );`
4. `Templates_Controller::preview_item()` lines 980-988: `$template = TemplatesManager::find_template_or_gallery( $id, $type );`
5. `includes/Templates/Receipt.php::get_custom_template()` preview branch lines 579-591: `return TemplatesManager::find_template_or_gallery( $preview_id, $type );`
6. `Receipt.php` `?template=` branch lines 600-608:
```php
$template = is_numeric( $template_id ) && 'publish' !== get_post_status( (int) $template_id )
	? null
	: TemplatesManager::find_template_or_gallery( $template_id, $type );
```
Keep the type check at `:610-613` unchanged.
7. `includes/Services/Print_Job_Service.php::load_template()` lines 236-250: add an optional parameter. The signature becomes `load_template( string $template_id, string $type = 'receipt' ): ?array` and the body `return \WCPOS\WooCommercePOS\Templates::find_template( $template_id, $type );`. Update the docblock (`@param string $type Template type a virtual slug is resolved in.`). Its other five callers (`Print_Jobs_Controller.php:761,1055`, `Print_Job_Service.php:283,341`, `Cloud_Print_Trigger_Service.php:217`, `Cloud_Print_Media_Types.php:239`, `Settings/Cloud_Print_Section.php:403`) stay unchanged.
8. `includes/API/V1/Receipts_Controller.php:298`: `$template = Print_Job_Service::load_template( $template_id, $is_closure ? 'closure' : 'receipt' );`. The type-mismatch check at `:299` stays.

## Part B — install reads one file; `Gallery_Update_Status` writes provenance

### `includes/Templates/Gallery_Update_Status.php` (575 lines): add after `record_source_hash()` (ends line 129)
```php
/**
 * Stamp a freshly installed gallery copy with its provenance.
 *
 * Where it came from, which bundled version, and what its content was as installed: the
 * facts `status_for()` compares later. Call it after the content is finally saved.
 *
 * @param int    $template_id The template post ID.
 * @param string $gallery_key The gallery key it was installed from.
 * @param int    $version     The registry version it was installed from.
 * @param string $locale      The locale its phrases were translated into.
 *
 * @return void
 */
public static function record_install( int $template_id, string $gallery_key, int $version, string $locale ): void {
	update_post_meta( $template_id, self::META_GALLERY_KEY, $gallery_key );
	update_post_meta( $template_id, self::META_GALLERY_VERSION, $version );
	self::record_source_hash( $template_id, $locale );
}
```

### `Templates::install_gallery_template()` (lines 1386-1462)
- Replace lines 1387-1395 (the `get_gallery_templates()` loop) with `$template = self::get_gallery_template_by_key( $gallery_key );` and change the guard to `if ( null === $template ) {` (same `invalid_gallery_key` WP_Error). The result set is the same: both skip entries without a content file.
- Delete lines 1437-1438 (`_template_gallery_key`, `_template_gallery_version`).
- Replace line 1460 (`Gallery_Update_Status::record_source_hash( $post_id, determine_locale() );`) with `Gallery_Update_Status::record_install( $post_id, $gallery_key, (int) ( $template['version'] ?? 1 ), determine_locale() );`. Keep the comment above it, adjusted to "Record provenance … must follow the raw save above". Nothing between the old write and this point reads the gallery key: `save_raw_post_content` reads only `_template_engine` (`Templates.php:392`).
- Update the `@see`-style sentence in `Gallery_Update_Status::replace_with_bundled()`'s docblock only if it mentions the meta writes. Otherwise leave it.

## Part C — starter templates shrink to their only outcome (BC-safe)
`get_starter_templates()` always returns `array()` (`Templates.php:1147-1158`), so `install_starter_template()` (`:1331-1385`) can only ever return `invalid_starter`.
- `get_starter_templates()`: body becomes `return array();`. Docblock: `@deprecated No starter templates ship; the template gallery replaced them. Always returns an empty array.`
- `install_starter_template( string $starter_key )`: body becomes `unset( $starter_key ); return new \WP_Error( 'invalid_starter', __( 'Starter template not found.', 'woocommerce-pos' ) );`. Keep the signature and `@return`. Add `@deprecated See get_starter_templates().`
- Do not call `_deprecated_function()`. It needs a version number and none is decided.

## Part D — docblocks (comments only)
- `includes/Templates.php` lines 555, 728, 757, 769, 801, 825, 865, 883, 907, 925, 940 and 1466: `(receipt, report, display)` → `(receipt, report, display, closure)`.
- `includes/Templates/Gallery_Registry.php:5`: "bundled receipt/report templates" → "bundled receipt, display, report and closure templates".
- `includes/Templates/Gallery_Update_Status.php:20`: "reading 37 bundled files" → "reading every bundled file".
- `includes/Services/Template_Pdf_Service.php:42-43`: the comment says only refund documents; the code also refuses closure and X-report. Make it read "only frozen documents (refund, closure, X-report) must not fall back to a native integration that would render the sale."

## Rulings to preserve (and the tests that pin them)
- `?template=` accepts only **published** stored templates, and only results whose type equals the requested type. It accepts virtual and gallery keys and falls back to the active template on an invalid id: `tests/includes/Templates/Test_Receipt.php` 373, 402, 431, 451, 471, 490.
- A numeric reference never falls back to the gallery: `Test_Receipt.php:402` (a draft id must not resolve).
- Gallery keys are previewable over REST: `Test_Templates_Controller::test_preview_returns_data_for_gallery_template` (`:1711`), `test_preview_invoice_gallery_template_uses_unpaid_fixture_data` (`:1576`).
- REST closure prints refuse a non-closure template: `Test_Closure_Receipts.php:585` (`wcpos_template_type_mismatch`).
- Active-template semantics are unchanged: `Test_Templates.php` 900-940, 1060-1106, 1446; `Test_Lazy_Service_Construction.php:171-194`.
- Install provenance: key, version, hash after the raw save, and locale. Pinned by `Gallery_Update_Status_Test.php` 113, 130, 461, and the sync and backfill tests after them. Removed gallery keys are refused: `Test_Install_Gallery_I18n::test_install_gallery_template_rejects_removed_template` (`:59`).
- `get_starter_templates()` returns no legacy starters: `Test_Templates.php:1114`.

## Tests to add
Conventions: Arrange/Act/Assert, `assertSame( expected, actual )`, `test_[feature]_[scenario]_[expected]`.
- `tests/includes/Test_Templates.php`
  - `test_find_template_numeric_reference_returns_stored_template_of_any_type`: create a published `display` DB template (engine meta `logicless`, as the file's existing helpers do). `find_template( (string) $id, 'receipt' )['type']` is `'display'`.
  - `test_find_template_string_reference_resolves_virtual_template_in_type`: `find_template( Templates::TEMPLATE_PLUGIN_CORE, 'closure' )['file_path']` ends with `gallery/closure-default.html`.
  - `test_find_template_or_gallery_falls_back_to_gallery_key`: `find_template( 'standard-receipt' )` is null; `find_template_or_gallery( 'standard-receipt' )['source']` is `'gallery'`.
  - `test_find_template_or_gallery_numeric_reference_never_falls_back`: `find_template_or_gallery( '999999999' )` is null.
- `tests/includes/Services/Print_Job_Service_Test.php`
  - `test_load_template_resolves_virtual_slug_in_requested_type`: `load_template( 'plugin-core', 'closure' )['type']` is `'closure'`; `load_template( 'plugin-core' )['type']` is `'receipt'`.
- `tests/includes/Templates/Gallery_Update_Status_Test.php`
  - `test_record_install_writes_key_version_hash_and_locale`: create a `wcpos_template` post and call `record_install( $id, 'standard-receipt', 3, 'fr_FR' )`. Assert the four metas via the class constants, and that `is_unedited( $id )` is true.

Delete no tests.

## Budget
Non-test: net line change must be **at most −20** (it shrinks; about −60 is expected), and the total non-test diff at most 280 lines (lint included). Tests: at most +130 lines. If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification you can run (no Docker)
- `php -l` on every changed file.
- From the worktree root: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Templates.php includes/API/V1/Templates_Controller.php includes/Templates/Receipt.php includes/Services/Print_Job_Service.php includes/API/V1/Receipts_Controller.php includes/Templates/Gallery_Update_Status.php includes/Templates/Gallery_Registry.php includes/Services/Template_Pdf_Service.php` plus the changed test files.
- `git grep -n "get_virtual_template\|get_gallery_template_by_key" -- includes/` afterwards. The remaining callers should be the definitions, `Templates.php:745` (detection), `Templates_Controller.php:759` (batch projection), `get_gallery_template_by_key`'s own uses in `Single_Template.php:614-619`, `Gallery_Update_Status.php:370,529`, and the two new methods.
- The orchestrator will run in wp-env, **one file per invocation**: `tests/includes/Test_Templates.php`, `tests/includes/API/Test_Templates_Controller.php`, `tests/includes/Templates/Test_Receipt.php`, `tests/includes/API/V2/Test_Closure_Receipts.php`, `tests/includes/Templates/Gallery_Update_Status_Test.php`, `tests/includes/Templates/Test_Install_Gallery_I18n.php`, `tests/includes/Services/Print_Job_Service_Test.php`, `tests/includes/Services/Print_Job_Service_Render_Test.php`. Afterwards it runs the Pro suite's `tests/includes/Test_Templates_Resolution.php` and `tests/includes/Templates/Test_Display.php` against this Free tree (no Pro edits expected).

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

## Pro follow-up (not this PR; a separate Pro change after this lands)
Pro `includes/Templates/Display.php:76-98`: replace the `get_gallery_templates( 'display' )` loop with `Templates::get_gallery_template_by_key( $requested )` plus a `'display' === $entry['type']` check, and the numeric/virtual ternary at `:93-95` with `Templates::find_template( $requested, 'display' )`. This stops reading all 21 display gallery files on every preview.
