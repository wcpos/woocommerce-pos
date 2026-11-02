# Spec — a closure with no enabled closure template is refused, not printed as a sale

## Goal
When no closure template is enabled, the storefront document route (`wcpos-receipt`, `document=closure:<id>` or `xreport:<id>`) must stop with a 404. Today it renders the legacy **sale** receipt (`templates/receipt.php`) with an empty order. With `intent=print` it also commits a closure print count for that page.

## Stakes
The route is Medium tier (receipt rendering). The side effect is High-adjacent: `Closure_Print_Counter` writes the closure's audit print count and log. Treat this as a bug fix that needs a failing test first, and fail closed. A failure costs a closure counted as "printed" although the page showed no closure figures. Accepted risks (do not address): concurrency; the PDF branch (it already 404s, `includes/Templates/Receipt.php:203-210`); the REST receipts route (it requires an explicit `template_id`).

## Out of scope
Do not add any of these: a fallback to the bundled closure default or to `templates/closure.php`, new options or filters, locking, retries, feature flags, env vars, plan files. Leave the **receipt** fallback to `receipt.php` (`Receipt.php:139`) untouched.

## Exact change — `includes/Templates/Receipt.php` (619 lines), method `get_template()`
Current line 125:
```php
$custom_template = $this->get_custom_template( ! empty( $receipt_data['fiscal']['is_closure_document'] ) ? 'closure' : 'receipt' );
```
Replace with:
```php
$document_type   = ! empty( $receipt_data['fiscal']['is_closure_document'] ) ? 'closure' : 'receipt';
$custom_template = $this->get_custom_template( $document_type );
if ( null === $custom_template && 'closure' === $document_type ) {
	// The fallback below is the legacy SALE receipt: for a closure it would print an empty sale
	// and, on a print intent, count the closure as printed. Refuse as the PDF branch does.
	wp_die( esc_html__( 'No closure template is enabled.', 'woocommerce-pos' ), '', array( 'response' => 404 ) );
}
```
This must stay before `self::$rendering = true;` / `ob_start()` and before `$counting` (`:147`), which it already is. `is_closure_document` is true for X-reports too (`Receipt_Data_Builder.php:207`), so X-reports are covered. If a previous spec has already edited nearby lines, find the `get_custom_template(` call inside `get_template()` by its content.

## Rulings to preserve
- A closure with an enabled template still renders and counts exactly once per print. Pinned by `Test_Closure_Receipts::test_closure_frozen_render_and_once_only_copy_count` and `test_storefront_orderless_documents_and_permissions`.
- A PDF preview never starts print bookkeeping (`test_storefront_closure_pdf_preview_does_not_start_print_counting`).
- Receipt (non-closure) renders with no custom template still fall back to `receipt.php` (`tests/includes/Templates/Test_Receipt.php`).

## Tests — `tests/includes/API/V2/Test_Closure_Receipts.php`
Write this test first and see it fail.
`test_storefront_closure_without_enabled_template_is_refused_and_not_counted`:
- Arrange: `$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );`. Create a current user with caps `access_woocommerce_pos`, `manage_woocommerce_pos`, `manage_woocommerce_pos_cash`, `view_woocommerce_pos_reports`, mirroring the setup at the top of `test_storefront_closure_pdf_preview_does_not_start_print_counting`. Run `\WCPOS\WooCommercePOS\Templates::set_virtual_template_disabled( \WCPOS\WooCommercePOS\Templates::TEMPLATE_PLUGIN_CORE, true, 'closure' );` and re-enable it in `finally`. The fixture creates no closure DB templates.
- Act: `$page = $this->render_receipt_page( 0, array( 'document' => 'closure:' . $row['id'], 'intent' => 'print' ) );`
- Assert: `assertSame( 404, $page['status'] )`; `assertSame( 0, ( new Closure_Store() )->get( $row['id'] )['print_count'] )`.

Delete no tests.

## Budget
Non-test: at most +10 net lines, total non-test diff at most 20. Test additions at most 45 lines. If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification you can run (no Docker)
- `php -l includes/Templates/Receipt.php tests/includes/API/V2/Test_Closure_Receipts.php`
- From the worktree root: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Templates/Receipt.php tests/includes/API/V2/Test_Closure_Receipts.php`
- The orchestrator will run in wp-env, one file per invocation: `tests/includes/API/V2/Test_Closure_Receipts.php`, then `tests/includes/Templates/Test_Receipt.php`.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (it will add the new test method; no new files here).

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
