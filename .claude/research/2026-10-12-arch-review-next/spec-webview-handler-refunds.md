# Spec — webview legs refund through their gateway (verdict D2 + candidate 4)

**Repo / lane:** `woocommerce-pos` (Free), branch from **`origin/next`**. The files are next-only, so the fix is not yet released.

## Goal
Every leg that `Webview_Passthrough::mint()` writes carries `capture_mode: 'webview'`. That includes hosted-pay-page payments, `payment_complete()` and offline tenders. `Ledger::refund()` dispatches on that stored mode to `Webview_Handler`, which inherits the abstract 501 `refund()`. So every "original method" refund that Pro allocates to such a leg fails, and WooCommerce rolls the refund back. Make `Webview_Handler` the gateway handler with a different mode name. Its descriptor already promises `refunds.via` = provider/manual, and `Gateway_Handler::refund()` is exactly that behaviour.

## Stakes
High tier (payments, refunds). A failure here means refunds still fail, or, the worse case, a refund that calls a provider twice. Double calls are prevented because Pro's ledger plans run with `api_refund` false (Pro `RefundPlan::ledger`). Do not change that. Accepted risk, not handled: a leg whose gateway plugin has since been uninstalled answers `wcpos_payment_method_not_found`, as gateway legs already do.

## Out of scope
Do not touch `Ledger`, `Webview_Passthrough`, `Descriptor_Builder`, `Capture_Mode_Registry`, `Gateway_Handler` or Pro. Add no new capture mode, no fallback to manual on provider error, no retries, no feature flag, no env var and no option. Do not rename or move either class.

## Exact change
File: `includes/Payments/Contract/Webview_Handler.php` (59 lines today). Replace the class body so that it becomes:

```php
/**
 * Describes legacy order-pay webview capture.
 *
 * A webview leg is money a WooCommerce gateway took on the order-pay page (or
 * payment_complete()), so it is refunded and (not) voided exactly as a gateway leg
 * is; only the advertised mode differs.
 */
class Webview_Handler extends Gateway_Handler {
	/**
	 * Describe webview capture.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array {
		$description                    = parent::describe( $gateway );
		$description['capture']['mode'] = 'webview';

		return $description;
	}
}
```
- Delete `Webview_Handler::void()`. The inherited `Gateway_Handler::void()` returns the same `wcpos_invalid_transition` 409 with the same message.
- Keep the file header and namespace. `Gateway_Handler` is in the same namespace, so no `use` is needed.

Before you edit, check that today's `Webview_Handler::describe()` and `Gateway_Handler::describe()` return identical arrays except `capture.mode` (compare `Webview_Handler.php:19-41` with `Gateway_Handler.php:19-41`). If anything other than the mode differs, keep that difference by overriding only that key, and list it in your report.

## Rulings to preserve
- The webview descriptor is unchanged: `capture` = `{mode: webview, provider: null, hardware: null, webview_available: true}`; capabilities `amount.partial false`, `change false`, `refunds.via provider|manual` following `supports('refunds')`, `refunds.partial` the same boolean, `tips none`, `offline none`, `void false`. New test 1 pins this.
- `Gateway_Handler` behaviour is unchanged and pinned by `tests/includes/Payments/Contract/Test_Gateway_Handler.php` (all tests).
- The descriptor wire is pinned by `tests/includes/API/Test_Payment_Gateways_Controller.php` and `tests/includes/Payments/Test_Gateway_Contract.php::test_provider_refunds_follow_the_capture_mode_descriptor`.

## Tests
Add `tests/includes/Payments/Contract/Test_Webview_Handler.php`, namespace `WCPOS\WooCommercePOS\Tests\Payments\Contract`, `class Test_Webview_Handler extends WCPOS_REST_Unit_Test_Case` (`use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;`). Use Arrange / Act / Assert, `assertSame` with `( expected, actual )`. Define the file's own test gateway classes at the bottom, with unique names (`Webview_Refund_Test_Gateway` with id `wcpos_webview_refund_test`, supports `products` and `refunds`, whose `process_refund()` records `func_get_args()` in a static `$args` and returns a static `$result` defaulting to `true`). Do not reuse the classes in `Test_Gateway_Handler.php`; they only load with that file.

1. `test_webview_description_differs_from_the_gateway_description_only_by_mode`
   - Arrange: a test gateway; `$webview = Capture_Mode_Registry::instance()->get( 'webview' )`; `$gateway_mode = Capture_Mode_Registry::instance()->get( 'gateway' )`.
   - Act / Assert, for `supports` = `array()` and `array( 'refunds' )`: `$expected = $gateway_mode->describe( $g ); $expected['capture']['mode'] = 'webview'; assertSame( $expected, $webview->describe( $g ) )`; and `assertSame( 'wcpos_invalid_transition', $webview->void( array(), '' )->get_error_code() )`.
2. `test_webview_leg_refund_runs_through_the_gateway` (**fails before the change**, with a 501 `wcpos_capture_mode_unsupported`)
   - Arrange: add `Webview_Refund_Test_Gateway` through a `woocommerce_payment_gateways` filter and call `\WC_Payment_Gateways::instance()->init()`; remove both in `finally`, as `Test_Gateway_Handler::test_gateway_refund_follows_the_gateway` does. Create an order with `OrderHelper::create_order()`, `update_meta_data( '_pos', '1' )`, `set_created_via( 'woocommerce-pos' )`, `set_payment_method( 'wcpos_webview_refund_test' )`, `save()`. Seed one leg with `Ledger::instance()->save( $order, array( array( 'id' => $id = wp_generate_uuid4(), 'source' => 'webview', 'method_id' => 'wcpos_webview_refund_test', 'kind' => 'other', 'capture_mode' => 'webview', 'amount' => $order->get_total(), 'currency' => $order->get_currency(), 'status' => 'captured' ) ) )`. Create the refund with `$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '4.00', 'reason' => 'Damaged', 'refund_payment' => false ) )`.
   - Act: `$row = Ledger::instance()->refund( wc_get_order( $order->get_id() ), $id, $refund->get_id(), '4.00' );`
   - Assert: `assertIsArray( $row )`; `assertSame( 'succeeded', $row['refunds'][0]['status'] )`; `assertSame( $refund->get_id(), $row['refunds'][0]['id'] )`; `assertSame( array( $order->get_id(), 4.0, 'Damaged' ), Webview_Refund_Test_Gateway::$args )`.
   - If the OrderHelper total is below 4.00, set the order total to `'10.00'` and save it before seeding.

Delete no tests.

## Budget
Non-test: at most **45 changed lines** (the file shrinks by about 30). Total diff, including the new test file and lint docblocks: at most **260 lines**.
If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
- `php -l includes/Payments/Contract/Webview_Handler.php tests/includes/Payments/Contract/Test_Webview_Handler.php`
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Payments/Contract/Webview_Handler.php tests/includes/Payments/Contract/Test_Webview_Handler.php`
- The orchestrator runs afterwards in wp-env (one file per invocation): `tests/includes/Payments/Contract/Test_Webview_Handler.php`, `tests/includes/Payments/Contract/Test_Gateway_Handler.php`, `tests/includes/Payments/Contract/Test_Ledger.php`, `tests/includes/API/Test_Payment_Gateways_Controller.php`, `tests/includes/Payments/Test_Gateway_Contract.php`, `tests/includes/Test_Orders.php` (the `does_not_mint_webview_ledger_row` test). Optionally, Pro's `tests/includes/Payments/Test_Refund_Processor.php` against this Free tree.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (and adds the new test file).
