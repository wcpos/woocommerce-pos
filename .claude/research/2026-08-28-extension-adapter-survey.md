<!-- roadmap: https://github.com/wcpos/roadmap/issues/101 — wayfinder research ticket. Produced by a Codex read-only survey of the local extension repos on 2026-08-28; facts only. -->

# WCPOS payment-gateway adapter compatibility matrix

**Scope.** Observed from the named local production sources on 2026-08-28; tests, vendor code, plans, and generated assets were excluded from negative searches. Effective defaults below are inferred by combining the WCPOS wrapper with each gateway class. No files were edited.

## 1. Contract surface

### PHP adapter

`Gateway_Adapter_Interface` declares eight methods:

1. `get_pos_provider(?WP_REST_Request): string` — `includes/Payments/Gateway_Adapter_Interface.php:24`
2. `get_pos_type(?WP_REST_Request): string` — `:31`
3. `get_pos_provider_data(?WP_REST_Request): array` — `:38`
4. `supports_pos_checkout(?WP_REST_Request): bool` — `:45`
5. `supports_pos_automatic_refunds(?WP_REST_Request): bool` — `:52`
6. `supports_pos_provider_refunds(?WP_REST_Request): bool` — `:59`
7. `get_pos_bootstrap_response(array $context, ?WP_REST_Request): array` — `:67`
8. `process_pos_checkout_action(array $state, string $action, array $payment_data, WC_Order $order, ?WP_REST_Request)` → array/`WP_Error` — `:80`

A gateway can implement the interface directly; otherwise `Filter_Gateway_Adapter` wraps its `WC_Payment_Gateway` instance (`includes/Payments/Filter_Gateway_Adapter.php:44-47`). `Abstract_POS_Gateway` implements the interface and registers compatibility shims (`includes/Payments/Abstract_POS_Gateway.php:19-29`).

### Public WordPress hooks

All are filters; the contract defines no `do_action()` action.

| Hook | Arguments | Source |
|---|---|---|
| `wcpos_payment_gateway_provider` | provider, gateway, request | `Filter_Gateway_Adapter.php:54-58` |
| `wcpos_payment_gateway_pos_type` | pos_type, gateway, request | `:65-69` |
| `wcpos_payment_gateway_provider_data` | data, gateway, request | `:76-80` |
| `wcpos_payment_gateway_supports_checkout` | bool, gateway, request | `:87-92` |
| `wcpos_payment_gateway_supports_automatic_refunds` | bool, gateway, request | `:99-105` |
| `wcpos_payment_gateway_supports_provider_refunds` | bool, gateway, request | `:112-118` |
| `wcpos_payment_gateway_bootstrap` | response, gateway_id, context, request | `:126-140` |
| `wcpos_process_checkout_action_{$gateway_id}` | state, action, payment_data, order, request | `:153-166` |

Non-direct defaults are: provider = gateway ID; `pos_type=manual`; empty `provider_data`; checkout support iff the dynamic checkout hook exists; both refund flags = `gateway->supports('refunds')`, except `pos_cash`/`pos_card`; default bootstrap = `{gateway_id,status:"ready",expires_at:null,provider_data:{}}` (`Filter_Gateway_Adapter.php:54-139,197-202`).

`Abstract_POS_Gateway` defaults instead to checkout support `true`, both refund flags `false`, and the same manual/ready values (`Abstract_POS_Gateway.php:36-97`).

### Catalog, types, capabilities

`GET /wcpos/v1/payment-gateways` returns objects with:

`id`, `title`, `description`, `enabled`, `provider`, `pos_type`, `capabilities`, `provider_data` (`includes/API/V1/Payment_Gateways.php:65-80,123-133`).

Capabilities are:

- `supports_checkout`
- `supports_automatic_refunds`
- `supports_provider_refunds`
- `requires_hardware`, true only when `pos_type === "terminal"`  
  (`includes/Payments/Gateway_Contract.php:84-93`).

Observed contract literals are `manual` and `terminal`; the REST schema accepts any string rather than declaring an enum (`Payment_Gateways.php:183-188`). Catalog access requires `publish_shop_orders` (`:90-92`).

### Bootstrap route

`POST /wcpos/v1/payment-gateways/{gateway_id}/bootstrap`:

- Request: optional `context` array.
- Response: adapter/filter result; default keys are `gateway_id`, `status:"ready"`, `expires_at:null`, `provider_data:{}`.
- Errors: missing gateway → `wcpos_payment_gateway_not_found`, HTTP 404.
- Permission: `publish_shop_orders`.  
  (`includes/API/V1/Gateway_Bootstrap_Controller.php:53-71,79-106,114-126`)

### Checkout route and state

`POST /wcpos/v1/orders/{id}/checkout`:

- Required header: `X-WCPOS-Idempotency-Key`.
- Body: `gateway_id`; optional `action` (default `start`); optional `payment_data` array.
- Initial adapter state: `{checkout_id,order_id,gateway_id,status:"processing",provider_data:{},terminal:false}`.
- Normalized response uses those same six keys.
- Gateway must be POS-enabled and advertise checkout support.  
  (`includes/API/V1/Checkout_Controller.php:74-90,120-187,193-204`; `Gateway_Contract.php:141-152`)

`GET` on the same path returns persisted state; absent state defaults to status `pending` (`Checkout_Controller.php:210-235`). System-generated status values are `pending` and `processing`; recognized terminal statuses are `completed`, `failed`, `cancelled`, `awaiting_customer` (`Gateway_Contract.php:154-161`). Adapter-returned status strings are otherwise not enum-validated (`Checkout_Controller.php:302-312`).

State is stored in `_wcpos_checkout_state`; completion also writes `_pos_checkout_gateway_id` and `_pos_checkout_idempotency_key` (`Checkout_State_Repository.php:19,27-35`; `Checkout_Controller.php:196-200`). Idempotent results live 24 hours; in-flight claims become stale after five minutes (`Idempotency_Repository.php:19-28,51-60,76-145`).

V2 subclasses V1 unchanged except namespace: `includes/API/V2/{Payment_Gateways,Gateway_Bootstrap_Controller,Checkout_Controller}.php:14-20`.

### Refund execution

The adapter contract exposes only the two refund capability methods/filters; it has no refund-execution method or action. WCPOS Pro’s refund service chooses provider mode from `WC_Payment_Gateway::supports('refunds')`, otherwise manual mode, without consulting either WCPOS refund filter (`woocommerce-pos-pro/includes/Payments/Refund_Processor.php:178-208,285-300`). Provider execution delegates to WooCommerce’s refund controller, which invokes the gateway’s standard `process_refund()` method (`:311-358`).

## 2. Extension × contract matrix

Legend: capabilities = checkout / automatic-refund / provider-refund / hardware. **D** = default ready bootstrap; **∅** = empty provider data.

| Extension | Adapter mechanism | Effective provider / `pos_type` | Provider data / bootstrap | Capabilities | Contract checkout | Actual status transport | Refund execution | Client / WCPOS-version gate |
|---|---|---|---|---|---|---|---|---|
| Stripe Terminal | None; plain WC gateway | `stripe_terminal_for_woocommerce` / `manual` | ∅ / D | F/T/T/F | None | AJAX polling + Stripe webhook/API reads | Provider via WC `process_refund()` | jQuery, Blocks, React terminal UI / none |
| SumUp Terminal | None | `sumup_terminal_for_woocommerce` / `manual` | ∅ / D | F/F/F/F | None | AJAX polling plus webhook metadata | No provider implementation; manual only | jQuery / none |
| Square Terminal | None | `sqtwc` / `manual` | ∅ / D | F/F/F/F | None | AJAX polling, Square webhook, optional Square POS-app callback | No provider implementation; manual only | Vanilla JS + Square POS app mode / none |
| Mollie Terminal | None | `mollie_terminal_for_woocommerce` / `manual` | ∅ / D | F/T/T/F | None | AJAX polling plus Mollie webhook | Provider via WC `process_refund()` | Vanilla JS / none |
| PayArc Terminal | None | `payarc_terminal_for_woocommerce` / `manual` | ∅ / D | F/F/F/F | None | AJAX polling plus PayArc callback | No provider implementation; manual only | jQuery / none |
| PayPal Reader | None | `paypal_reader_for_woocommerce` / `manual` | ∅ / D | F/F/F/F | None | Live browser WebSocket; AJAX polling only in mock mode | No provider implementation; manual only | Vanilla JS/WebSocket / none |
| Vipps MobilePay | None | `wcpos_vipps` / `manual` | ∅ / D | F/T/T/F | None | AJAX polling of Vipps API | Provider via WC `process_refund()` | React / none |
| WCPOS BTCPay | No plugin code in checkout | N/A | N/A | N/A | N/A | N/A | N/A | Infrastructure repo only |
| WCPOS Pro | No adapter/hook registration | N/A | N/A | Reads WC `refunds`, not WCPOS flags | N/A | N/A | Orchestrates provider/manual WC refunds | No gateway client |

## 3. Per-extension notes

- **Stripe:** gateway ID and `refunds` support at `stripe:includes/Gateway.php:20,46-50`. Its actual lifecycle creates/dispatches a PaymentIntent, polls every 2 seconds, and resolves paid, `requires_payment_method` decline, failed/cancelled, or a five-minute client timeout (`packages/payment-frontend/src/payment.js:95-129,362-430`). Refunds call Stripe through `process_refund()` (`Gateway.php:425-546`). It checks `woocommerce_pos_request()` for POS request context but has no POS-version comparison (`Gateway.php:171-176,772-790`).
- **SumUp:** plain gateway ID at `sumup:includes/Gateway.php:18,43-69`; no `refunds` support or `process_refund`. Custom actions are create, cancel, status, and webhook (`includes/AjaxHandler.php:25-38`). States include `CREATING → PENDING`; checkout finals `PAID/FAILED/CANCELLED/TIMEOUT/EXPIRED`, transaction finals `SUCCESSFUL/FAILED/CANCELLED` (`:155-199,305-422,548-617`).
- **Square:** plain gateway `sqtwc`, no refund capability (`square:includes/Gateway.php:21,37-60`). Client states are `idle/creating/polling/cancelling/final`; provider finals are `COMPLETED/CANCELED/CANCELLED` (`assets/js/payment.js:19-38,396-471,715-756`). It polls, supports cancel/detach/reload resume, receives webhooks, and optionally returns from the Square POS app (`includes/Plugin.php:64-108`; `Gateway.php:216-265`).
- **Mollie:** gateway declares `products,refunds` (`mollie:includes/Gateway.php:9-22`). Custom AJAX actions are start/poll/cancel/list terminals, with a separate webhook (`includes/AjaxHandler.php:10-34`; `WebhookHandler.php:7-35`). Client outcome buckets are paid (`paid/already_paid/conflict`), failed (`failed/canceled/expired/verification_failed`), idle, or pending (`assets/js/payment.js:254-268,333-415`). Provider refunds use `process_refund()` and Mollie refund reconciliation (`Gateway.php:369`; `RefundReconciler.php:9-52`).
- **PayArc:** plain gateway ID and products-only support (`payarc:includes/Gateway.php:5-16`). Start/poll/cancel are custom AJAX actions (`includes/AjaxHandler.php:39-79,187-248`). Normalized states include `created/pending/sent/processing/cancel_requested/success`, with unpaid finals `decline/timeout/cancelled/failure/aborted/dup transaction` (`PaymentAttempt.php:88-124`). Status is both polled and pushed through the PayArc callback (`PaymentReconciler.php:74-110`; `WebhookHandler.php:40-45,104-136`).
- **PayPal Reader:** plain gateway, no refund support (`paypal:includes/Gateway.php:14-30`). Custom start creates either a mock attempt or live WebSocket credentials; live status streams in-browser and is posted back through `confirm_payment`, while mock mode polls (`includes/AjaxHandler.php:82-149,151-251`). Stored states include `pending`, `completed`, `canceled`, provider failure strings, and `amount_mismatch` (`:173-205,236-249`).
- **Vipps:** plain gateway with `refunds` support (`vipps:includes/Gateway.php:5-26`). Client states are `idle/creating/polling/authorized/failed/cancelled/expired`; provider states map `AUTHORIZED`, `ABORTED/TERMINATED`, `EXPIRED`, while `CREATED` keeps polling (`assets/src/types.ts:1-10`; `assets/src/hooks/useVippsPayment.ts:71-120`). Authorization submits the Woo form (`assets/src/App.tsx:25-42`). Provider refunds use `process_refund()` (`Gateway.php:254-285`).
- **BTCPay:** the checkout contains only BTCPay Server deployment infrastructure, not a WordPress gateway/adapter (`wcpos-btcpay:README.md:1-17,25-34`).
- **Pro:** `/wcpos/v1/orders/{id}/refunds` wraps WooCommerce refunds and requires its own idempotency header (`woocommerce-pos-pro/includes/API/V1/Order_Refunds_Controller.php:24-38,127-158`). It records destination/mode/gateway/idempotency audit metadata (`Refund_Audit_Meta.php:24-42`).

## 4. Outside the adapter contract

- **Stripe:** own REST namespace `stripe-terminal/v1`: `/connection-token`, `/list-locations`, `/register-reader`, `/create-payment-intent`, `/capture-payment-intent`, `/attach-payment-method-to-customer`, `/webhook` (`includes/Abstracts/APIController.php:19-28`; `includes/API.php:48-121`). Order meta: `_transaction_id`, `_stripe_currency`, `_stripe_charge_captured`, `_stripe_intent_id`, `_stripe_card_type`, `_stripe_terminal_{payment_intent_id,charge_id,payment_status,payment_amount,payment_currency,payment_method,payment_error,livemode,moto}` (`includes/API.php:332-337,490-502,562-575,626-627`).
- **SumUp:** WordPress AJAX lifecycle/webhook; no custom REST route. Meta: `_sumup_{attempt_started,checkout_status,checkout_updated,last_webhook,reader_id,transaction_checked_at,transaction_status,transaction_updated}` (`includes/AjaxHandler.php:155-181,305-338,538-617`).
- **Square:** REST `/sqtwc/v1/webhook` and `/sqtwc/v1/pos-callback` (`includes/Plugin.php:90-108`), plus AJAX lifecycle. Meta: `_sqtwc_{payment_log,current_attempt_id,checkout_idempotency_key,attempt_request,checkout_id,checkout_status,checkout_updated_at,device_id,attempt_started,square_checked_at,attempt_history,abandoned_checkout_ids,processed_event_ids,payment_ids,collected_amount,tip_amount,duplicate_payment_ids,pos_client_transaction_id,pos_transaction_id}` (`includes/Services/OrderMeta.php:24-107,141-144`; `CheckoutReconciler.php:152-201`; `PosCallbackHandler.php:90,153-154`). Direct DB operations: MySQL advisory locks, owner-checked option deletion, and reconciliation-option discovery (`OrderLock.php:103-122,163-171`; `PaymentSweeper.php:162-168`).
- **Mollie:** AJAX start/poll/cancel/list/pair and webhook; no custom REST route. Payment meta: `_mtfwc_{current_attempt_id,current_payment_id,current_terminal_id,current_payment_status,current_payment_created_at,payment_attempts,abandoned_payment_ids}` (`PaymentAttempt.php:4-11`). Refund-order meta: `_mtfwc_{refund_attempt_id,mollie_refund_id,refund_status,refund_amount}` (`RefundReconciler.php:9-12,26,48-51`).
- **PayArc:** AJAX lifecycle/connection/callback; no custom REST route. Meta: `_patwc_{current_trace_id,current_transaction_id,current_status,current_charge_id,current_terminal_id,current_attempt,attempt_history,processed_callbacks,last_poll_at,charge_id,card_brand,card_entry_mode,card_last4,processor_response_code,processor_response_text,verification_failure}` (`PaymentAttempt.php:7-15`; `PaymentReconciler.php:10-16,355-365`).
- **PayPal Reader:** AJAX reader/start/confirm/cancel/status/pair endpoints; no custom REST route. Meta: `_prwc_{attempt_id,reader_id,internal_trace_id,payment_state,card_payment_uuid,tracking_id}` (`includes/AjaxHandler.php:106-108,131-133,203-205`).
- **Vipps:** AJAX create/status/cancel; no custom REST route (`includes/AjaxHandler.php:7-13`). Meta: `_wcpos_vipps_reference`, `_wcpos_vipps_status` (`:338-340,382-385,414-416`). Direct DB operation: owner-checked payment-lock deletion from the options table (`:149-172`).
- **Pro refund orders:** `_pos_user`, `_pos_store`, `_pos_refund_destination`, `_pos_refund_mode`, `_pos_refund_gateway_id`, `_pos_refund_idempotency_key` (`woocommerce-pos-pro/includes/Payments/Refund_Audit_Meta.php:24-42`).

## 5. Usage summary

**No surveyed extension actively uses:**

- Any of the eight adapter-interface methods.
- Any `wcpos_payment_gateway_*` filter.
- The dynamic `wcpos_process_checkout_action_{$gateway_id}` filter.
- Custom `provider_data`, bootstrap data, or core checkout state.
- `pos_type="terminal"` or explicit WCPOS capability overrides.
- WCPOS refund capability filters.
- A WCPOS plugin-version gate.

**Every one of the seven implemented gateway plugins uses:**

- A plain WooCommerce gateway registered through `woocommerce_payment_gateways`.
- A separate browser-side payment component and WooCommerce order-pay flow.
- The core wrapper’s effective defaults: provider = gateway ID, `pos_type=manual`, empty provider data, ready bootstrap, no contract checkout, and `requires_hardware=false`.

The production-only negative search returned **zero matching files in every extension and WCPOS Pro** for `Gateway_Adapter_Interface`, `Abstract_POS_Gateway`, `wcpos_payment_gateway_*`, or `wcpos_process_checkout_action_*`.