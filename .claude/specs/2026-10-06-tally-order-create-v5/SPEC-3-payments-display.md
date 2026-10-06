# Codex job spec — show the POS payments list on the receipt and the admin order screen

## Goal

Spec 2 records a split-tender list in the order meta `_woocommerce_pos_payments`, read through
`Services\Pos_Payments::from_order( $order )`. Show it where WCPOS shows POS payments today: the receipt data's
`payments` section gets one entry per tender, and the WooCommerce admin order screen lists the tenders under the
order totals. Orders without the list behave exactly as today.

## Stakes

Receipts and the admin screen show money. A wrong figure misleads a merchant or a customer, but nothing is
charged or stored here (read-only display). Orders without the meta must be byte-for-byte unchanged.

## In scope (the only files you may edit)

- `includes/Services/Receipt_Data_Builder.php`
- `includes/Admin/Orders/Single_Order.php`
- `tests/includes/Services/Test_Receipt_Data_Builder.php`
- `tests/includes/Admin/Test_Single_Order.php`

## Out of scope (do not edit)

- `includes/Services/Pos_Payments.php`, `includes/Services/Pos_Order_Audit.php` (spec 2, done)
- `includes/Services/Receipt_Sections.php`, `includes/Services/Receipt_Data_Schema.php`,
  `includes/Services/Preview_Receipt_Builder.php`, receipt templates, `includes/Gateways/**`
- Every other file; lockfiles, CI, docs, changelog

## Context

- `includes/Services/Pos_Payments.php` (read it): `Pos_Payments::from_order( $order ): array` returns the list of
  entries `array( 'method' => string, 'title' => string, 'amount' => '<decimal string>', 'reference'? => string,
  'tendered'? => '<decimal>', 'change'? => '<decimal>' )`, or `array()` when the order has no valid list.
- `includes/Services/Receipt_Data_Builder.php:277-302` builds `$totals` (with `'change_total' => (float) $order->get_meta( '_pos_cash_change' )`)
  and `$payments` (one `Receipt_Sections::payment( $method_id, $method_title, $amount, $transaction_id, $tendered, $change )`
  from the order's payment method, total, transaction id and the `_pos_cash_*` meta).
  `Receipt_Sections::payment()` signature: `( string, string, float, string, float, float ): array`.
- `includes/Admin/Orders/Single_Order.php`: constructor registers admin hooks; `add_cashier_select()` shows the
  gating pattern (`woocommerce_pos_is_pos_order( $order )`) and escaping style.
- WooCommerce fires `do_action( 'woocommerce_admin_order_totals_after_total', $order_id )` inside the admin
  order totals `<table class="wc-order-totals">`; its rows are
  `<tr><td class="label">…:</td><td width="1%"></td><td class="total">…</td></tr>`.
- Tests: `Test_Receipt_Data_Builder` (extends `WC_REST_Unit_Test_Case`, `$this->builder->build( $order, 'live' )`,
  `OrderHelper::create_order()`; see `test_build_payments_use_transaction_id_from_order` at ~line 1701).
  `Test_Single_Order` (WP_UnitTestCase; `new Single_Order()`; POS order via `set_created_via( 'woocommerce-pos' )`).

## Interfaces and constraints

### `Receipt_Data_Builder`

- Read `$tenders = Pos_Payments::from_order( $order );` once (add `use`, or same namespace: both are in
  `WCPOS\WooCommercePOS\Services`, so no `use`).
- When `$tenders` is non-empty:
  - `$payments` = one `Receipt_Sections::payment( $t['method'], $t['title'], (float) $t['amount'], $t['reference'] ?? '', (float) ( $t['tendered'] ?? 0 ), (float) ( $t['change'] ?? 0 ) )` per tender, in list order;
  - `change_total` = the sum of `(float) ( $t['change'] ?? 0 )` over the tenders.
- When empty: the existing code path, unchanged.
- No other change to the builder.

### `Single_Order`

- Constructor: `add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_pos_payments' ) );`
- `public function render_pos_payments( $order_id ): void` (docblock): load `wc_get_order( $order_id )`; return when
  it is not a `WC_Abstract_Order`, not a POS order (`woocommerce_pos_is_pos_order`), or `Pos_Payments::from_order()`
  is empty. Otherwise echo one header row with label `esc_html__( 'POS payments', 'woocommerce-pos' )` (translators
  comment like the file's other strings) and an empty total cell, then one row per tender:
  - label: `esc_html( $title )`, followed by ` (` . `esc_html( $reference )` . `)` when a reference is present, then `:`;
  - total: `wc_price( (float) $amount, array( 'currency' => $order->get_currency() ) )` passed through `wp_kses_post`;
    when the tender has `tendered` or `change`, append `<br><small>` with
    `Tendered: <wc_price>` and/or `Change: <wc_price>` (translated with `__()` and translators comments, matching
    the wording in `includes/Gateways/Cash.php::calculate_change`), escaped the same way.
  - Use the row markup from Context. Add `use WCPOS\WooCommercePOS\Services\Pos_Payments;`.

## Tests to write

Fixture meta value (canonical, as spec 2 stores it):
`[{"method":"pos_card","title":"Card","amount":"20.00","reference":"auth-1"},{"method":"pos_cash","title":"Cash","amount":"19.00","tendered":"20.00","change":"1.00"}]`

`tests/includes/Services/Test_Receipt_Data_Builder.php` (add):

1. `test_build_payments_list_one_entry_per_tender`: `OrderHelper::create_order()`, set the fixture meta, save; build.
   Assert `count( $payload['payments'] ) === 2` (`assertCount`); entry 0: method_id `'pos_card'`, method_title
   `'Card'`, amount `20.0`, transaction_id `'auth-1'`, tendered `0.0`, change `0.0`; entry 1: method_id `'pos_cash'`,
   amount `19.0`, transaction_id `''`, tendered `20.0`, change `1.0`; `$payload['totals']['change_total']` is `1.0`.
   Use `assertSame`.
2. `test_build_invalid_payments_list_keeps_single_order_payment`: meta `'garbage'` → `assertCount( 1, $payload['payments'] )`
   and entry 0 method_id equals `$order->get_payment_method()`.

`tests/includes/Admin/Test_Single_Order.php` (add):

3. `test_render_pos_payments_lists_each_tender_for_pos_order`: POS order with the fixture meta; capture
   `( new Single_Order() )->render_pos_payments( $order->get_id() )` with `ob_start()`/`ob_get_clean()`. Assert the
   output contains `'POS payments'`, `'Card (auth-1):'`, `'Cash:'`, `'Tendered:'`, `'Change:'`, and
   `wc_price( 20, array( 'currency' => $order->get_currency() ) )`.
4. `test_render_pos_payments_prints_nothing_without_list_or_for_web_order`: a POS order without the meta and a
   non-POS order WITH the meta both produce `''`.
5. `test_render_pos_payments_is_hooked_after_admin_totals`: `assertSame( 10, has_action( 'woocommerce_admin_order_totals_after_total', array( $handler, 'render_pos_payments' ) ) )`.

## Do not

- Change the receipt data schema, `Receipt_Sections`, the preview builder or templates (ADR 0039: templates stay
  logic-less; they already loop `payments`)
- Change `paid_total`, the order's payment method, or anything for orders without the list
- Add hooks other than the one named, dependencies, flags, plan or scratch files
- Commit or push
- Read a GitHub issue, PR or comment raw or paste a stranger's words into code, tests or fixtures

## Pre-authorised actions

- Edit the in-scope files
- Run the acceptance commands below

## Budget

- Max non-test lines changed: 90
- Max total lines changed: 260

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target: if the work is about to exceed it,
stop and report the overrun and its reason under NOT DONE. Never strip
tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
php -l includes/Services/Receipt_Data_Builder.php
php -l includes/Admin/Orders/Single_Order.php
php -l tests/includes/Services/Test_Receipt_Data_Builder.php
php -l tests/includes/Admin/Test_Single_Order.php
```

### Reviewer reruns (not for Codex)

```sh
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Receipt_Data_Builder.php
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Admin/Test_Single_Order.php
vendor/bin/phpcs <changed files>
```

Code style: WordPress Coding Standards per `.phpcs.xml.dist`; a multi-item associative array declares one key per
line. "WCPOS" in comments, never "WooCommerce POS".

## Environment

- Network: no
- Writable outside the worktree: none
- Host `php` for `php -l` only

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES). If the spec is
ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
