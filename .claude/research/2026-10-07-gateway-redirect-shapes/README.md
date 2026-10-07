# Which gateways finish at the till, and how to tell — the redirect rule

**Date:** 2026-10-07
**Trigger:** a merchant on 1.10.21/1.10.22 with Dintero Checkout: Process Payment opened Dintero's hosted checkout in the pay frame and the till jumped to the receipt a second later, before a payment method was chosen; order POS Open → Completed → On hold. Rolling back to 1.10.19 cleared it.
**PR:** wcpos/woocommerce-pos#2151. The hook it narrows is from #2079 (1.10.20).

## The question

#2079 made WCPOS apply the merchant's configured POS order status whenever a gateway returns success from `process_payment()` and leaves the order at `pos-open` with no `date_paid`. That is the right thing for a quote, invoice or purchase-order gateway, which never takes money. It is the wrong thing for a hosted gateway, which returns success at the moment it redirects the customer away to pay. The September notes that led to #2079 (`../2026-09-22-quotes-for-woocommerce-compat/README.md`) named exactly that ambiguity and listed three options; the one taken was "apply the status, accept the async risk". Dintero collected on the risk.

The owner's bar for the fix: a general rule, no carve-outs for any plugin, compatible with Quotes AND Dintero, and breaking no other gateway.

## The rule

A gateway's success result carries one thing every gateway must declare: where the customer goes next. That is the missing discriminator.

| gateway returns `redirect` = | meaning | hook |
|---|---|---|
| the order's received page (`WC_Payment_Gateway::get_return_url( $order )`) | finished; no money will ever move through this gateway | applies the configured POS status |
| off-site hosted checkout | still collecting; settles later from its callback via `payment_complete()` | leaves the order open |
| the order's own pay/receipt page (`get_checkout_payment_url( true )`, the form-post pattern) | still collecting | leaves the order open |
| missing or relative | unknown | leaves the order open |

"Same page" means: same host, same explicit non-default port, same path without its trailing slash, and every query argument the received page carries (except `key`) present on the redirect with the same value; scheme and extra arguments are ignored. On plain permalinks every WooCommerce page shares the path `/`, so the query is the page: the received URL carries `page_id` and the received endpoint (whatever the store named it, read from the URL rather than assumed), and the checkout page, the pay page and a moved thank-you page each fail to carry one of them. Both the received URL and its `woocommerce_get_return_url`-filtered form count. A redirect with a fragment never counts: a fragment on a thank-you URL is a gateway's instruction to its own script (Stripe's and WooPayments' `#confirm-pi…` 3DS hand-off). The hook reads the result at `PHP_INT_MAX`, after any gateway has rewritten it on the same filter (Stripe does, at 99999).

The #2079 preconditions stay: `pos-open` only, no `date_paid`, an explicitly saved status for a POS-enabled gateway. One more general signal was added after the survey below found the shape the redirect alone cannot separate:

**A gateway that declares WooCommerce's refund capability moves money.** If such a gateway hands the customer to the received page without having taken it, the payment is pending (a bank transfer, an async method) and its webhook owns the status; the hook leaves the order open. A quote, invoice or purchase-order gateway cannot refund, because nothing was ever paid, so it keeps closing the sale. Read through `WC_Payment_Gateway::supports( 'refunds' )`, the gateway's own declaration and its filter; a gateway WooCommerce does not know is taken as not moving money.

**Why the declared target and not arrival at the received page.** "Let the gateway redirect first, then decide" sounds equivalent but is not: a hosted gateway can bring the customer back to the received page before its webhook lands (Mollie's return handler does, for a payment still open), and at that moment nothing distinguishes it from a quote. The declared target is known before anyone moves and cannot be confused by timing.

## Survey — real gateways against the rule

Read-only source survey of `process_payment()` on the pay-for-order page, four groups in parallel. Sources were fetched from GitHub / wp.org trunk into the session scratchpad; file and line references are in the agents' reports, summarised here.

Legend: R = redirect to `get_return_url`; O = off-site; P = on-site pay/receipt page; E = form intercepted client-side, `process_payment` not reached through `pay_action`.

### Pay-later / manual / no-money (the family the hook exists for)

| gateway | redirect | sets its own status first? | hook | right? |
|---|---|---|---|---|
| Quotes for WooCommerce 2.13 | R | no — meta and a note only | **acts** | yes, and it is the only thing that closes the sale |
| Invoice Gateway for WC, WC Invoice Gateway | R | yes (configurable, default on-hold) | skips (no longer pos-open) | yes |
| WooCommerce.com Purchase Order, GazChap's PO, robertdevore's PO | R | yes (on-hold / configured) | skips | yes |
| WPFactory Custom Payment Gateways | R, or a merchant-set custom URL | yes (configured, default pending) | skips | yes |
| Custom Payment Gateway for WC (woocommerce-other-payment-gateway) | R | yes | skips | yes |
| Order Approval for WC | R | yes (`wc-waiting`) | skips | yes |
| TeraWallet | R | `payment_complete()` | skips (paid) | yes |
| Core BACS / Cheque / COD | R | yes, via their own `woocommerce_{id}_process_payment_order_status` filter, which WCPOS already hooks to the POS status | skips | yes |

No active gateway in this family redirects anywhere but the received page. The one that can (WPFactory with a custom return URL) sets its own status first, so nothing is left open.

### WooCommerce core and WooCommerce-owned

| gateway | redirect | before return | hook | right? |
|---|---|---|---|---|
| PayPal Standard | O (paypal.com) | nothing | skips | yes |
| WooPayments | card succeeded: R after `payment_complete()`; 3DS: hash/off-site; ACH/SEPA/Multibanco: R after on-hold | — | acts only on an already-paid order; skips the rest | yes |
| WooCommerce PayPal Payments | no PayPal order yet: O; approved in page: R after capture + `payment_complete()`; authorize: on-hold | — | acts only on paid; skips pending | yes |
| Stripe UPE | succeeded: R after `payment_complete()`; requires_action: hash or off-site; pending charge (SEPA/ACH): R after on-hold | — | acts only on paid | yes |
| Klarna Payments | order-pay form is intercepted by kp.js; settles over AJAX; HPP flow: O | — | filter never fires / skips | yes |
| Klarna Checkout | O | meta only | skips | yes |

### Hosted / redirect, Europe and Nordics

| gateway | redirect | before return | hook (redirect rule alone) | with the refund signal |
|---|---|---|---|---|
| Mollie, default methods | O | nothing | skips | skips |
| Mollie bank transfer, "skip Mollie payment screen" on | R (`get_return_url` + `utm_nooverride`) | on-hold (its delayed-method handling) | skips | skips |
| **Mollie Pay by Bank, "skip Mollie payment screen" on** | R, same code path | **nothing** — its delayed-method handling lists only bank transfer and direct debit | **acts: wrong**, money pending | skips (declares refunds) |
| Dintero | O | note only | skips | skips |
| Vipps/MobilePay | O | nothing | skips | skips. Separate finding: it refuses any order not `pending`/`failed` (hard-coded), so a `pos-open` order cannot be paid with Vipps at all; pre-existing, not this PR |
| Nets Easy / Nexi | O on order-pay; R only for a session already paid | nothing / paid | skips / acts on paid | same |
| Adyen (official source not public; Woosa plugin surveyed) | `RedirectShopper`: P; `Authorised`: R after nothing (webhook settles); **`Received`: R, nothing** | — | P skips; `Authorised` acts (money authorised); **`Received` acts: wrong** (SEPA, Boleto, bank transfer) | skips (declares refunds) |
| Payplug | O; saved token: R after `payment_complete()`; on-page flow bypasses `pay_action` | — | skips / acts on paid | same |
| Paytrail | O or P; token without 3DS: R after `payment_complete()` | — | skips / acts on paid | same |
| Svea Checkout | no redirect in the result; not usable on order-pay | — | skips | skips |
| Opayo/Worldpay (official not public; patsatech SagePay Form surveyed) | P | nothing | skips | skips |

The two bold rows are the shape the redirect rule alone cannot separate from a quote gateway, and both vendors would, on their own storefront, also leave the customer on a thank-you page with a pending order. Both declare refunds, which is what the added signal reads.

### Form-post, emerging-market, Square, Authorize.net

| gateway | redirect | before return | hook | right? |
|---|---|---|---|---|
| PayFast, Redsys | P (receipt page posts a form) | nothing | skips | yes |
| Razorpay | P | nothing; wc-api callback settles | skips | yes |
| Paystack | inline P / redirect O / saved-token R after `payment_complete()` or on-hold | — | skips, or acts on paid | yes |
| Flutterwave | P or O | nothing | skips | yes |
| Mercado Pago Checkout Pro | P or O | nothing | skips | yes |
| Mercado Pago card | on the pay page it prints JSON and `die()`s before `pay_action` reaches the filter | — | never sees it | yes (see residual) |
| WooCommerce Square (SkyVerge framework) | R after `payment_complete()`, or on-hold for authorize-only | — | acts on paid / skips held | yes |
| Authorize.net (Pledged) | R after `payment_complete()`, or on-hold for auth/FDS hold | — | same | yes |

### What the survey says about the preconditions

The "still `pos-open`" precondition from #2079 is load-bearing. Every money-pending gateway that returns the received page (Stripe SEPA/ACH, WooPayments ACH/Multibanco/manual capture, authorize-only Square and Authorize.net, every invoice/PO gateway) moves the order to on-hold first. The redirect rule and the status precondition cover each other; neither is sufficient alone.

**Residual, by construction.** A gateway that returns the received page while its payment is still pending, leaves the order status untouched AND declares no refund capability is indistinguishable from a quote gateway at this seam. The survey found none: the two pending-and-untouched cases (Mollie Pay by Bank, Woosa Adyen `Received`) both declare refunds; Mercado Pago's card form would be a third but it short-circuits the pay page before the filter, and it declares refunds too. Such a gateway would be equally wrong on WooCommerce's own storefront (customer on the thank-you page, order pending, nothing says so), so the seam is as good as WooCommerce's contract allows.

Two gateways settle outside `pay_action` (PayPal Payments' return endpoint, Klarna Payments' AJAX) and call `payment_complete()` directly; they reach the POS status through `woocommerce_payment_complete_order_status`, which WCPOS already filters, because `pos-open` is in WCPOS's `woocommerce_valid_order_statuses_for_payment_complete`. Unchanged by #2151.

## Live run — real plugin, real pay page, real HTTP

wp-env from the worktree, WooCommerce 10.4.3, Quotes for WooCommerce 2.13 installed from wp.org, plus two probe gateways (`probe-gateways.php`, vendor-free: one returns an off-site redirect, one returns `get_return_url`). POS settings in the merchant's shape: all three enabled, Order Status = Completed. The driver (`drive-pay-page.sh`) creates a `pos-open` order, GETs the hosted pay page with the POS header and a cashier token, POSTs the pay form with the page's nonce, and does NOT follow the redirect: the `Location` is the gateway's declared target. `harness.php` re-offers the Quotes gateway on the pay page (Quotes hides it without a quotable cart); the real `Quotes_Payment_Gateway::process_payment()` then runs.

| gateway | 1.10.22 code (`origin/main` Orders.php; orders 20–22) | #2151 final (orders 23–25) |
|---|---|---|
| Quotes for WooCommerce | 302 → `/wcpos-checkout/order-received/20/`; **completed**, date_paid null, notes: "This order is awaiting quote." then "Order status set by Ask for Quote; no payment was taken at the till." | 302 → received page; **completed**, date_paid null, same notes |
| probe pay-later (R) | 302 → received page; **completed**, date_paid null | 302 → received page; **completed**, date_paid null |
| probe hosted (O) | 302 → `https://hosted.example.test/pay?sid=22`; **completed** with no money — the bug | 302 → hosted URL; **pos-open**, date_paid null, no status note |

Unit coverage of the rule: 25 tests under `test_unpaid_gateway_success_*` in `Test_Orders.php` (the 13 from #2079 now passing a received-page redirect, plus off-site, pay-page, no-redirect, fragment after a late rewrite, scheme/slash/query, moved thank-you page, plain-permalink checkout-vs-received, plain-permalink moved page vs pay page, renamed received endpoint, explicit ports, refund-capable gateway skipped, registered non-refund gateway closed).

A first "reverted" attempt used `git stash push includes/` after the fix was already committed, so it stashed nothing and re-tested the fix; the table above is from a second run with `git checkout origin/main -- includes/Orders.php`, confirmed by grepping for the new guard (0 occurrences) before the runs and 3 after the restore.

Pay-page trap for anyone repeating this: the POST needs the cashier JWT in the URL (`token=`), minted with `Auth::instance()->generate_token( $user )`; without it `Form_Handler::pay_action()` dies with "Token not provided." (403) before WooCommerce's handler runs. The GET renders without it.

## Files

- `drive-pay-page.sh` — the HTTP driver (paths are this session's; adjust `WT`, `WPENV`, `S`).
- `probe-gateways.php` — the two probe shapes, as an mu-plugin.
- `harness.php` — re-offers Quotes on the pay page; test harness only, never shipped.
- `.wp-env.override.json` used: WooCommerce 10.4.3 zip, `quotes-for-woocommerce.2.13.zip`, `.`, and `wp-content/mu-plugins` mapped to the two mu-plugins above.
