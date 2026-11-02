# Spec — one owner for a template's Preview Document

## Goal
"Which sample document does this template preview with?" is decided in three places today: `Templates_Controller::preview_item`, `Single_Template::get_editor_inline_script` / `get_sample_receipt_data`, and `Receipt_Preview_Fixture_Loader::build`. Move the decision into one method, `Receipt_Preview_Fixture_Loader::for_template()`, and have the REST preview and the template editor both call it. Three defects go away with it:
- **D3**: a closure preview ignores the requested store.
- **D6**: the editor ignores an installed template's gallery preview profile.
- **D5**: `preview_html` drops barcodes because it re-implements `Logicless_Renderer`.

## Stakes
Medium tier: receipt templates and previews in wp-admin. A failure means a merchant sees the wrong sample in the editor or gallery. No money or data is at risk. Accepted risks: preview data for a store the caller is not allowed to see (store access is already validated at `Templates_Controller.php:1030-1040`), and filter-added gallery entries with odd `preview_data` values.

## Out of scope
Do not add any of these: a Template_Type class or rows table, report previews running `woocommerce_pos_report_data` (D2, deferred), TypeScript changes, new REST params or endpoints, new options, caching, validation layers, fallbacks, feature flags, env vars, plan files.

## Exact changes

### 1. `includes/Services/Receipt_Preview_Fixture_Loader.php` (179 lines)
a. In `build()`, closure branch (lines 39-51), after `$row['register_id'] = 'preview';` add:
```php
// Preview the requested store's identity; without one the fixture's own store stands.
if ( \is_object( $pos_store ) && \method_exists( $pos_store, 'get_id' ) && (int) $pos_store->get_id() > 0 ) {
	$row['store_id'] = (int) $pos_store->get_id();
}
```
Also update the `@param` text for `$pos_store` so it says "used for the closure and receipt profiles".

b. Add a public method after `build()`:
```php
/**
 * The sample document a template previews with, money not yet formatted.
 *
 * The template's type picks the source: a report previews a report fixture, a closure the
 * closure fixture, every other type the sample receipt, overlaid with the template's gallery
 * preview profile when it has one. The REST preview and the template editor both ask here.
 *
 * @param array       $template   Template record; reads `type` and, when present, `preview_data`.
 * @param object|null $pos_store  Store the sample is for, or null for the default store.
 * @param mixed       $report_key Report fixture key for report templates; unknown keys preview 'sales'.
 *
 * @return array Receipt data.
 */
public function for_template( array $template, $pos_store = null, $report_key = 'sales' ): array {
	$type = $template['type'] ?? 'receipt';
	if ( 'report' === $type ) {
		return $this->build( 'report', $report_key );
	}
	if ( 'closure' === $type ) {
		return $this->build( 'closure', $pos_store );
	}
	$profile = $template['preview_data'] ?? null;

	return \is_string( $profile )
		? $this->build( $profile, $pos_store )
		: ( new Preview_Receipt_Builder() )->build( $pos_store );
}
```
(`Preview_Receipt_Builder` is in the same namespace.)

### 2. `includes/API/V1/Templates_Controller.php` (1692 lines), `preview_item()`
a. Replace lines 1044-1056 (the `if ( 'report' === … ) … elseif ( $order ) … else { … }` block that assigns `$receipt_data`) with:
```php
if ( $order ) {
	$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live', $request_pos_store );
} else {
	$receipt_data = ( new Receipt_Preview_Fixture_Loader() )->for_template(
		$template,
		null === $request_pos_store ? wcpos_get_store() : $request_pos_store,
		$request->get_param( 'report' ) ?? 'sales'
	);
}
```
This is equivalent: for report and closure templates `$order` is always null (`:1000` drops `order_id` for them).

b. Replace the body of `render_logicless_preview()` (lines 1189-1232, docblock included) with a delegate to the real renderer:
```php
/**
 * Render a logicless template for the `preview_html` diagnostic.
 *
 * Delegates to Logicless_Renderer so the diagnostic is a real render: barcodes rasterised,
 * print-colour styles kept. Money formatting is idempotent, so pre-formatted data is safe.
 *
 * @param array $template       Template metadata including content.
 * @param array $formatted_data Receipt data with money fields pre-formatted.
 *
 * @return string Rendered HTML.
 */
private function render_logicless_preview( array $template, array $formatted_data ): string {
	ob_start();
	try {
		( new Logicless_Renderer() )->render( $template, null, $formatted_data );

		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
}
```
Add `use WCPOS\WooCommercePOS\Templates\Renderers\Logicless_Renderer;`. Remove `use …\Preview_Receipt_Builder;` if nothing else in the file uses it. The callers' `try/catch ( \Mustache\Exception\SyntaxException )` blocks (around `:1081` and `:1124`) stay unchanged: the exception still propagates, and `finally` cleans the buffer.

### 3. `includes/Admin/Templates/Single_Template.php` (649 lines)
a. `get_sample_receipt_data()` (lines 566-583) gains an optional parameter (BC-safe):
```php
public static function get_sample_receipt_data( ?array $template = null ): array {
	$raw = ( new \WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader() )->for_template( $template ?? array( 'type' => 'receipt' ) );

	return \WCPOS\WooCommercePOS\Services\Receipt_Data_Schema::format_money_fields(
		$raw,
		$raw['order']['currency'] ?? 'USD'
	);
}
```
Update the docblock: "the template's own Preview Document; with no template, the plain sample receipt". If `spec-receipt-money-formatter.md` has already landed, the formatter call is `Receipt_Money_Formatter::format(...)`. Keep whichever is present.

b. Line 601 becomes:
```php
$sample_data = \in_array( $type, array( 'closure', 'report' ), true )
	? ( new \WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader() )->for_template( $template ?? array( 'type' => $type ) )
	: self::get_sample_receipt_data( $template );
```

## Rulings to preserve
- Closure and report editor samples are **not** money-formatted. The closure builder formats its own `_display` values from decimal strings without a float round-trip (`Receipt_Data_Builder.php:126-132`). Pinned by `Test_Single_Template_Sample_Data::test_closure_editor_bootstrap_uses_closure_assets` / `test_report_editor_bootstrap_uses_report_assets`.
- With no template, the editor sample is `Preview_Receipt_Builder::build()` formatted (`test_get_sample_receipt_data_includes_formatted_money_companions`).
- Gallery and installed templates preview with their profile (`Test_Templates_Controller` 1576, 1671, 1693). Display templates with a null profile preview the plain sample (`:1644`).
- Closure previews ignore `order_id` (`test_preview_closure_ignores_order_and_returns_sample_data`, `:1308`). The closure fixture's own store stands when no store is given (`Test_Receipt_Preview_Fixture_Loader::test_closure_fixture_contains_full_document`).
- A Mustache syntax error in sample mode still returns the red error `preview_html`. With an order it still returns `receipt_data` without `preview_html`.
- New ruling (intended): a closure template always previews the closure fixture, whatever its `preview_data`. Bundled closure entries already say `'closure'` (`Gallery_Registry.php:619,630`).

## Tests
Write these first and see the D3, D5 and D6 tests fail. Conventions: Arrange/Act/Assert, `assertSame( expected, actual )`, `test_[feature]_[scenario]_[expected]`, `$this->wp_rest_get_request()` for REST.
- `tests/includes/Services/Test_Receipt_Preview_Fixture_Loader.php`
  - `test_for_template_closure_uses_the_given_store` (D3). Arrange: an anonymous-class store whose `get_id()` returns 7. Add a `woocommerce_pos_get_store` filter (3 args) that returns that object when `$the_store === 7`, and remove it in `finally`. Act: `for_template( array( 'type' => 'closure' ), $store )`. Assert: `assertSame( 7, $data['store']['id'] )` (`Receipt_Store_Resolver::build_store_section` reads `get_id`).
  - `test_for_template_uses_the_template_preview_profile`: `for_template( array( 'type' => 'receipt', 'preview_data' => 'invoice' ) )` → `assertSame( 'INV-2048', $data['order']['number'] )`.
  - `test_for_template_report_uses_report_key_and_ignores_profile`: `for_template( array( 'type' => 'report', 'preview_data' => 'invoice' ), null, 'cash-movements' )` → `assertSame( 'cash-movements', $data['report']['key'] )`.
- `tests/includes/Admin/Templates/Test_Single_Template_Sample_Data.php`
  - `test_editor_bootstrap_uses_installed_template_preview_profile` (D6). Arrange: `$post_id = Templates::install_gallery_template( 'invoice' );` and delete it in `finally`. Act: reflect `get_editor_inline_script` exactly as the existing tests in this file do. Assert: `assertSame( 'INV-2048', $config['sampleData']['order']['number'] )`; `assertIsString( $config['sampleData']['totals']['total_incl_display'] )`.
- `tests/includes/API/Test_Templates_Controller.php`
  - `test_preview_html_keeps_barcode_images` (D5). Arrange: a logicless DB template (use the file's `create_template()` helper) whose content is `<div><barcode type="code128" height="40">{{order.number}}</barcode></div>` (the markup from `Test_Logicless_Renderer::test_barcode_marker_rasterized_to_png_image`). Act: GET `/wcpos/v2/templates/<id>/preview` with no order. Assert: `assertStringContainsString( '<img', $data['preview_html'] )` and `assertStringContainsString( 'data:image/png', $data['preview_html'] )`.

Delete no tests.

## Budget
Non-test: at most +30 net lines, total non-test diff at most 200 lines (lint included). Tests: at most +180 lines. If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification you can run (no Docker)
- `php -l` on every changed file.
- From the worktree root: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Services/Receipt_Preview_Fixture_Loader.php includes/API/V1/Templates_Controller.php includes/Admin/Templates/Single_Template.php` plus the changed test files.
- The orchestrator will run in wp-env, **one file per invocation**: `tests/includes/Services/Test_Receipt_Preview_Fixture_Loader.php`, `tests/includes/Admin/Templates/Test_Single_Template_Sample_Data.php`, `tests/includes/API/Test_Templates_Controller.php`, `tests/includes/Templates/Renderers/Test_Logicless_Renderer.php`, `tests/includes/API/V2/Test_Closure_Receipts.php`, `tests/includes/Services/Test_Preview_Receipt_Builder.php`.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
