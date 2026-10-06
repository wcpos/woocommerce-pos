# Codex job spec — PHPUnit pins for TallyUI order.create v5 on `push/orders`

## Goal

TallyUI's WooCommerce transport will send fee, shipping and custom (no-catalogue) lines to
`POST /wcpos/v2/push/orders` (TallyUI ADR-075, order.create v5). The plugin already forwards
`fee_lines`, `shipping_lines` and `product_id: 0` line items to the stock wc/v3 orders
controller. Add ONE new PHPUnit test file that drives the real registered route with the two
ADR-075 golden orders (mapped to the Woo shape below) and pins that the store records every
charge with the expected totals and tax, honours the tax instructions, and replays idempotently.
Tests only: no production code changes.

## Stakes

Money on a POS order (fees, shipping, tax). A wrong pin would hide a mis-charged order, so
every expected figure below is exact. No production code changes, so no runtime risk.

## In scope (the only files you may edit)

- `tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php` (new)

## Out of scope (do not edit)

- Everything under `includes/` (production code), in particular `includes/Sync/Order_Write_Payload.php`,
  `includes/Orders.php`, `includes/API/V2/Write_Controller.php`
- `tests/includes/Sync/Sync_REST_Store_Test_Case.php`, `tests/includes/Helpers/*`, every other test file
- `.wp-env.json`, `composer.json`, `composer.lock`, `package.json`, lockfiles, CI config, docs, changelog

## Context

- Model the file on `tests/includes/Sync/Test_Rest_Dispatch_Fee_Tax.php` (read it first): namespace
  `WCPOS\WooCommercePOS\Tests\Sync`, class extends `Sync_REST_Store_Test_Case`, `setUp()` creates an
  administrator and `wp_set_current_user()`s it, sets `$_SERVER['HTTP_X_WCPOS'] = '1'`, enables taxes;
  `tearDown()` unsets the header, deletes the tax class it created, sets `woocommerce_calc_taxes` back to
  `'no'`, then `parent::tearDown()`. Its `push_order_create()` helper shows the envelope and the dispatch:
  `$this->wp_rest_post_request( '/' . Api::ROUTE_NAMESPACE . '/push/orders' )`, `Content-Type: application/json`,
  body `wp_json_encode( $envelope )`, `$this->server->dispatch( $request )`. The created order id is
  `(int) $response->get_data()['document']['id']`.
- Envelope: `{ mutationId: <uuid>, operation: 'create', collection: 'orders', recordId: <uuid>, baseRevision: null, payload: <Woo order> }`.
  Also send the header `Idempotency-Key: <mutationId>` (as `Test_Rest_Dispatch_Order_Taxes::push_order_create` does).
  Put `{ key: '_woocommerce_pos_uuid', value: <recordId> }` in the order's `meta_data` (as Fee_Tax does).
  Use the ADR-075 golden uuids: they are valid uuids already.
- A misc/custom line (`product_id: 0`) is taxed per its `_woocommerce_pos_data` meta: `Orders::order_item_product`
  builds a synthetic product from it and `Orders::order_item_after_calculate_taxes` zeroes the line's taxes when
  `tax_status` is `'none'`. A posted `sku` on a misc line is stored as `_sku` item meta
  (`Order_Write_Payload::normalize_line_item_product_identity`). Build `_woocommerce_pos_data` as a JSON STRING
  value (`wp_json_encode( array( 'price' => '3.00', 'regular_price' => '3.00', 'tax_status' => 'none' ) )`), which is
  the form the WCPOS client sends (see `tests/includes/Helpers/POSLineItemHelper.php:46-47`). Do not use the helper
  class itself; write the arrays inline so the file is the readable contract.
- Products: `Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper::create_simple_product( array( 'regular_price' => 25, 'price' => 25, 'tax_status' => 'taxable', 'tax_class' => '' ) )`.
- Tax rates: `WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'VAT20', 'tax_rate_priority' => 1, 'tax_rate_order' => 0, 'tax_rate_shipping' => 1, 'tax_rate_class' => '' ) )`
  (empty country = every location), and for the reduced class `WC_Tax::create_tax_class( 'Tally V5 reduced' )`
  (slug `tally-v5-reduced`) plus a `'5.0000'` rate `'VAT5'` with `tax_rate_class => 'tally-v5-reduced'`, `tax_rate_shipping => 1`.
  In setUp also `update_option( 'woocommerce_prices_include_tax', 'no' )` and
  `update_option( 'woocommerce_shipping_tax_class', '' )` (standard rate for shipping); in tearDown delete the class
  with `WC_Tax::delete_tax_class_by( 'slug', 'tally-v5-reduced' )`.

## The Woo mapping under test (ADR-075 §4, agreed shape — reproduce exactly)

- Fee → `fee_lines[]`: `{ name, total: '<net major units>', tax_status: 'taxable'|'none', tax_class: '<slug or empty string>', meta_data: [ { key: '_woocommerce_pos_uuid', value: <clientFeeId> } ] }`
- Shipping → `shipping_lines[]`: `{ method_id: <methodId> or 'pos' when absent, method_title: <name>, total: '<net major units>', meta_data: [ { key: '_woocommerce_pos_uuid', value: <clientShippingId> } ] }`
- Custom line → `line_items[]`: `{ product_id: 0, name, quantity, subtotal, total, tax_class: '<slug or empty string>', sku?: <sku>, meta_data: [ { key: '_woocommerce_pos_uuid', value: <clientLineId> }, { key: '_woocommerce_pos_data', value: <JSON string { price, regular_price, tax_status }> } ] }` (price/regular_price = unit price, 2-decimal strings)
- Catalogue line → `line_items[]`: `{ product_id: <id>, quantity: 1, meta_data: [ { key: '_woocommerce_pos_uuid', value: <clientLineId> } ] }`

## Tests to write (exact names, all in the new file)

Read money with `wc_format_decimal( <value>, 2 )` and compare with `assertSame` against a string
(e.g. `assertSame( '12.24', wc_format_decimal( $order->get_total(), 2 ) )`). On every dispatch, first
`assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) )`.
Read an item's uuid with `$item->get_meta( '_woocommerce_pos_uuid', true )`.

1. `test_v5_fee_golden_pair_records_bag_fee_with_tax`: golden 3.1. Product 10.00 (taxable, standard). mutationId
   `0199b0a0-0000-7000-8000-000000000001`, recordId `0199b0a0-0000-7000-8000-0000000000a1`; line uuid
   `0199b0a0-0000-7000-8000-0000000000b1`; one fee `{ name: 'Bag', total: '0.20', tax_status: 'taxable', tax_class: '' }`
   with uuid `0199b0a0-0000-7000-8000-0000000000f1`. Assert: exactly one fee item; its name `'Bag'`, total `'0.20'`,
   total_tax `'0.04'`, uuid meta equals the clientFeeId; order total_tax `'2.04'`, order total `'12.24'`.
2. `test_v5_shipping_and_custom_line_golden_pair_records_both_charged`: golden 3.2. Product 25.00 (taxable, standard),
   line uuid `...0000000000b2`. Custom line: `product_id: 0`, name `'Gift wrap'`, quantity 1, subtotal `'3.00'`,
   total `'3.00'`, tax_class `''`, sku `'GW-1'`, uuid `...0000000000b3`, `_woocommerce_pos_data` tax_status `'none'`.
   Shipping: method_id `'flat_rate'`, method_title `'Local delivery'`, total `'5.00'`, uuid `...0000000000d1`.
   mutationId `...000000000002`, recordId `...0000000000a2` (all with prefix `0199b0a0-0000-7000-8000-`).
   Assert: the custom item (find the line item whose product id is 0; exactly one) has name `'Gift wrap'`, total `'3.00'`,
   total_tax `'0.00'`, `_sku` meta `'GW-1'`, uuid meta `...b3`; exactly one shipping item with method id `'flat_rate'`,
   method title `'Local delivery'`, total `'5.00'`, total_tax `'1.00'`, uuid meta `...d1`; order total_tax `'6.00'`,
   order total `'39.00'`.
3. `test_v5_shipping_without_method_id_records_pos_method`: product 10.00 + shipping `{ method_id: 'pos', method_title: 'Courier', total: '5.00' }`
   (own mutationId/recordId, e.g. `0199b0a0-0000-7000-8000-000000000003` / `...0000000000a3`). Assert the shipping
   item's method id `'pos'`, title `'Courier'`, total `'5.00'`, total_tax `'1.00'`; order total `'18.00'`.
4. `test_v5_custom_line_and_fee_honour_tax_class_and_status`: one custom line `{ product_id: 0, name: 'Engraving', quantity: 2, subtotal: '20.00', total: '20.00', tax_class: 'tally-v5-reduced' }`
   with `_woocommerce_pos_data` `{ price: '10.00', regular_price: '10.00', tax_status: 'taxable' }`; fees
   `{ name: 'Service', total: '4.00', tax_status: 'taxable', tax_class: 'tally-v5-reduced' }` and
   `{ name: 'Deposit', total: '1.00', tax_status: 'none', tax_class: '' }` (own uuids, mutationId `...000000000004`,
   recordId `...0000000000a4`). Assert: custom line total_tax `'1.00'` (5% of 20.00); Service fee total_tax `'0.20'`;
   Deposit fee total_tax `'0.00'`; order total_tax `'1.20'`; order total `'26.20'`.
5. `test_v5_fee_golden_pair_replay_returns_same_order_without_duplicates`: build the golden 3.1 envelope (share a
   private builder with test 1 so both use the identical envelope), dispatch it twice. Assert the first is 201; the
   second's status is in `array( 200, 201 )` (`assertContains`); both responses' `document.id` are identical; the
   order still has exactly one fee item and one line item; and
   `wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'created_via' => 'woocommerce-pos' ) )` has count 1.

## Interfaces and constraints

- Class name `Test_Rest_Dispatch_Tally_Order_Create_V5`; file docblock with `@package WCPOS\WooCommercePOS\Tests\Sync`,
  a class docblock with `@coversNothing` and a one-paragraph description: "Pins the ADR-075 (TallyUI order.create v5)
  Woo mapping through the real v2 push route: fee_lines, shipping_lines and a product_id 0 custom line."
- Use tabs, WordPress spacing (`array( ... )`, spaces inside parentheses), Arrange / Act / Assert comments,
  `assertSame( expected, actual )` order. Follow `.phpcs.xml.dist`; you may add the same
  `// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact pin scenarios.` line Fee_Tax uses.
- Use the "WCPOS" name in comments, never "WooCommerce POS".

## Do not

- Edit any file except the one new test file
- Add a version field, a `/tally/v1/info` endpoint, capabilities, or any negotiation: the plugin has none and
  TallyUI's connector advertises v5 itself (handoff §5)
- Weaken an expected figure to make a test pass. The tests cannot be run in your sandbox (see Acceptance); write
  the exact expectations above
- Add helpers to shared test classes, new dependencies, plan/notes/scratch files
- Commit or push
- Read a GitHub issue, PR or comment raw or paste a stranger's words into code, tests or fixtures

## Pre-authorised actions

- Create the in-scope test file
- Run the acceptance commands below

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 420

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target: if the work is about to exceed it,
stop and report the overrun and its reason under NOT DONE. Never strip
tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
php -l tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php
grep -c "public function test_v5_" tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php   # prints 5
```

### Reviewer reruns (not for Codex)

PHPUnit runs only in Docker/wp-env, which the Codex sandbox cannot reach. The reviewer runs:

```sh
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php
```

and phpcs on the file inside the `cli` container.

## Environment

- Network: no
- Writable outside the worktree: none
- No dependencies needed (host `php` for `php -l` only)

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES). If the spec is
ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
