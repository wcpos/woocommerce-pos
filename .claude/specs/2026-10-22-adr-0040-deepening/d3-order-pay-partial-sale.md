# D3: order-pay refuses full-total gateways on a POS sale that already holds a captured tender

**Stakes:** checkout-money, High. **Lane:** `next`.

## Defect (verified on origin/next 15e6b6f2, WooCommerce 10.4.3)

- `Orders::valid_order_statuses_for_payment()` adds `pos-partial`, so a partly paid POS sale is payable on order-pay (the WCPOS pay page `/wcpos-checkout/order-pay/<id>` and WooCommerce's own customer pay link).
- `Templates\Payment::initialize_order_and_nonces()` gates only on `is_paid()`. `WC_Form_Handler::pay_action()` gates only on `needs_payment()` and then calls the gateway's `process_payment()`.
- A WooCommerce gateway charges `get_total()`, not the balance. `payment_complete()` then marks the sale paid, and `Webview_Passthrough::mint()` returns null because a row in `Ledger::LIVE_STATUSES` already exists: the second charge gets no payment row.

## Ruling (Paul, 2026-10-22): option A

On a POS order (`wcpos_is_pos_order`) that holds a row in `Ledger::COUNTING_STATUSES`, order-pay is refused for every gateway whose capture mode resolves to `webview` (`Descriptor_Builder::resolve_mode()`, i.e. the `wcpos_payment_method_capture_mode` filter). Gateways resolving to `server`, `device` or `manual` are untouched. No new flag, no Pro change, no balance-aware pay flow for webview gateways.

Two layers:
1. On the order-pay endpoint, `woocommerce_available_payment_gateways` drops webview-mode gateways for such an order; when none remain, the gateway list shows "This sale has a payment recorded at the till. Finish it at the till."
2. The pay action (`woocommerce_before_pay_action`) refuses a webview-mode gateway on such an order with that notice, so `process_payment()` never runs.

## Why not a blanket refusal (Pro facts, woocommerce-pos-pro origin/next)

- `Order_Pay_Panel::config()` charges `Ledger::balance()`, not the total, records through Free's `wcpos/v2/orders/<id>/payments` routes, and navigates to `receivedUrl` on capture; Pro's tests model split captured rows on one order (`Test_Order_Pay_Gateway::split_captured()`).
- An extension terminal gateway renders the panel from its own payment fields via `wcpos_pro_order_pay_panel()` (`includes/wcpos-pro-functions.php:98-106`); `Order_Pay_Panel::eligible()` requires a registered server provider (`Order_Pay_Panel.php:30`).
- Extensions declare themselves with `wcpos_pro_register_server_provider()` (`includes/wcpos-pro-functions.php:24-35`, `Server_Providers::register()` at `Server_Providers.php:49`). Pro maps those ids to capture mode `server` (and device providers to `device`) on Free's `wcpos_payment_method_capture_mode` filter (`includes/Init.php:106-119`). That is the existing balance-aware marker this fix keys on.

## Tests

- (a) POS order total 100, 40.00 cash recorded → `pos-partial`; pay with a webview-mode gateway → refused, no `process_payment`, no `payment_complete`, one row, status `pos-partial`.
- (b) Same order, a gateway the filter resolves to `server` → the pay action proceeds as today.
- (c) A POS order with no captured rows → the webview-mode gateway is allowed as today.
