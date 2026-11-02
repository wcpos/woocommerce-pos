# How established POS and payment systems model money state on a sale

**Date:** 2026-10-19 · **Question:** how do established point-of-sale and payment systems model money state on a sale, and where does the WCPOS greenfield sketch agree or disagree with them? · **Method:** primary sources only — vendor API references, developer docs, vendor-owned help centres, source code pinned to a commit, first-party engineering writing. Each claim carries its URL. Square forum answers from Square staff are labelled *forum*; processor documents used where a card-network primary source is not public are labelled *processor doc*. Anything not confirmed from a primary source is marked **unverified**.

Source pins: Odoo `odoo/odoo` 18.0 at `9e38c31e` (17.0 at `d4fa9446` where the sync differs); WooCommerce `woocommerce/woocommerce` trunk at `19e3f1a1`.

Abbreviations used in URLs below:
- `O18/` = `https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/`
- `WC/` = `https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/`

(Both are written out in full in every link.)

---

## Executive summary

### Five patterns that at least three systems share

1. **Payments are separate records that reference the sale, and the order carries read-only rollups of them.** Square (Payment with `order_id`; `order.tenders`, `net_amount_due_money` read-only), Stripe (PaymentIntent outside any order, linked by metadata), Adyen (payment per PSP reference), Clover (payment object references the order), Shopify (`OrderTransaction` records under the order after creation), Odoo (`pos.payment` model). Counter-examples: Lightspeed X-Series embeds payments in the sale and replaces the sale wholesale; WooCommerce keeps one tender on the order.
2. **Money records are never rewritten; a correction is a new record linked to the one it corrects.** Square refunds create a return order linked by `source_order_id`; Stripe Refund objects reference the charge; Adyen refunds and reversals are new messages against the PSP reference; Shopify VOID/REFUND/CAPTURE transactions carry `parentTransaction`; Modern Treasury, TigerBeetle and Fowler all treat posted entries as immutable and correct by appending. Counter-example: Odoo lets a `pos.payment` amount change until the session posts it, with an audit note.
3. **A completed sale is not reopened in place; the sanctioned amendment is a new linked document.** Square: COMPLETED is terminal and paid line items are locked; returns are a separate order. Odoo: `write()` forbids paid → draft; a refund is a new order with negative lines. Lightspeed: a closed sale may only go to VOIDED; void and re-ring. Adyen/Stripe: a completed payment can only be refunded. Counter-examples: Toast lets a closed check be reopened until the order is "restricted"; Shopify edits a paid order in a separate edit session and leaves a balance owed; WooCommerce enforces nothing at the REST layer.
4. **The server decides "paid", from the sum of payments against the total.** Square PayOrder completes only when payments sum to `total_money`; Odoo stores the client's `paid` as `draft`, recomputes `amount_paid` from rows "because we don't trust the client" and then sets `paid`; Shopify `fullyPaid` is received ≥ total; Toast check CLOSED means "no remaining amount due"; Adyen `/orders` closes when `remainingAmount` is 0; Clover sets the state automatically from payments. Counter-examples: Lightspeed has the client set `state: closed`; WooCommerce sets status through `payment_complete()` with no amount check.
5. **Retries are made safe by a client-minted operation id that the server stores with its outcome.** Same id plus same body replays the stored result; same id plus a different body is an error; a duplicate while the first request is still running is a conflict. Stripe (24 h retention, parameter compare, 409 in flight), Square (`IDEMPOTENCY_KEY_REUSED`), Shopify (`IDEMPOTENCY_KEY_PARAMETER_MISMATCH`, `IDEMPOTENCY_CONCURRENT_REQUEST`, 24 h), Adyen Checkout (7–14 days, error 704 in flight), Clover (UUID required, same-key retry returns the charge), Brandur's design (409 on mismatch), TigerBeetle (`exists_with_different_*`, ids unique forever), Odoo (uuid unique constraint).

Also shared by three or more, and relevant to WCPOS: **offline card payments are stored on the device, forwarded later as the payment itself, and the merchant carries the decline risk** (Square, Stripe, Adyen, Clover, Lightspeed, Toast). **A lost response is resolved by looking the payment up by the client's own reference**, not by guessing (Adyen TransactionStatus, Clover `retrievePayment`, Stripe metadata + webhooks, Square `ListPayments` by `referenceId`).

### Where the WCPOS sketch diverges, with a verdict on each

| Sketch statement | Industry position | Verdict |
|---|---|---|
| Money never travels in the order document | Agrees: Square, Stripe, Clover, Adyen, Shopify (after create). Disagrees: Lightspeed (embedded, whole-sale replace), Odoo (payments ride in the order sync as per-row commands), Toast (embedded array, but appended by its own POST) | **Keep.** Majority practice, and the two embedded systems either forbid editing a closed sale (Lightspeed) or ignore re-syncs of a non-draft order (Odoo). |
| Four explicit commands, each **a single atomic save** | Agrees for complete, reopen, record. **Disagrees for the gateway command**: a card charge is a foreign side effect that no local transaction can roll back. Brandur, Square delayed capture, TigerBeetle and Stripe all model it as a durable pending record, then the external call, then a post or void, with a sweeper for stranded pending records | **Change.** The gateway command is two atomic saves around the external call, plus a sweeper. Only the purely local commands are one save. |
| Idempotent by client command id | Agrees with every system. The industry adds three things the sketch leaves out: a fingerprint of the request body (mismatch is an error), an in-flight conflict response, and a stored outcome that is replayed | **Keep, and add** body fingerprint, in-flight 409, stored outcome. Retention must outlive the longest offline queue; the industry's 24 h is too short for a till (TigerBeetle and Odoo keep ids forever). |
| A reopen command exists | **Most systems have no reopen**: Square, Odoo, Lightspeed, Clover, Adyen, Stripe. The three that allow editing a paid sale (Toast, Shopify, WooCommerce) have **no epoch**: prior payments keep counting and the balance is total minus everything received | **Strongest divergence.** Either drop reopen (amend by refund + new sale or a linked return, as the majority does) or keep it the Toast/Shopify way: no epoch, prior payments count, the balance is recomputed. |
| The ledger holds only the current epoch; prior rows are **archived** on reopen | **No system does this.** No surveyed POS stops a captured payment counting because the sale was reopened. Ledger literature: posted entries are immutable and are corrected by a linked reversal (Modern Treasury, TigerBeetle, Fowler). Deleting or replacing is allowed only before a real-world action has followed (Fowler), and only for pending entries (Modern Treasury). | **Reject.** A captured payment is a real-world event. It keeps counting until a linked refund or void says otherwise. Note that the *current* WCPOS design (keep every row, filter by epoch) is structurally Fowler's "rejected event" pattern; its readers are the problem, not the storage. |
| One completion truth (the stamp) | Agrees that one explicit, server-set fact is normal (Square COMPLETED, Odoo `paid`, Stripe `succeeded`). It is always **derived by the server from the payment records at the moment it is set**, and the client's claim is ignored (Odoo downgrades it to `draft`) | **Keep**, as "the server sets the stamp only when the payments it holds sum to the total". Never accept it from the till or from four other kinds of evidence. |
| (Not in sketch) concurrency control | Square `version` → `VERSION_MISMATCH`; Square payment `version_token`; Modern Treasury `lock_version`; Stripe serialises per object and returns `lock_timeout` | **Add** an expected order version to every command; on a stale version, refuse, and let the client re-read and retry. |

---

## 1. Square (Orders, Payments, Terminal, Refunds)

1. **Lifecycle.**
   - States: `OPEN` ("can be updated"), `COMPLETED` ("fully paid. This is a terminal state."), `CANCELED` (terminal), `DRAFT` ("can be updated, but cannot be paid") — [OrderState](https://developer.squareup.com/reference/square/enums/OrderState).
   - UpdateOrder: "Orders with a `COMPLETED` or `CANCELED` state cannot be updated" — [UpdateOrder](https://developer.squareup.com/reference/square/orders-api/update-order).
   - Line items lock once paid, even while OPEN: error "LineItems cannot be modified for finalized tenders"; staff say "order line items can only be updated prior to the order being paid" — [forum 9477](https://developer.squareup.com/forums/t/update-order-api-error/9477).
   - Staff point to a partial refund instead, and add that "the system does not support issuing a refund against an open order" — [forum 6144](https://developer.squareup.com/forums/t/updating-an-orders-line-items/6144).
   - Amend path: "Rather than mutate sale Orders, we model returns and refunds with a separate return Order… The return Order links back to the original sale Order" — [Everything is an Order](https://developer.squareup.com/blog/everything-is-an-order/).
   - An order referenced by an invoice cannot have amount-affecting fields changed — [Update Orders](https://developer.squareup.com/docs/orders-api/manage-orders/update-orders).
2. **Tenders.** Payments are separate objects.
   - CreatePayment "must include an order ID" and must match the order total. Split tender: create payments with `autocomplete=false`, then call PayOrder with all `payment_ids` — [Pay for orders](https://developer.squareup.com/docs/orders-api/pay-for-orders); [PayOrder](https://developer.squareup.com/reference/square/orders-api/pay-order).
   - `order.tenders` is read-only output — [Order](https://developer.squareup.com/reference/square/objects/Order).
   - Payments are mutable state machines (`APPROVED`, `PENDING`, `COMPLETED`, `CANCELED`, `FAILED`), each with a `version_token` "used for optimistic concurrency" — [Payment](https://developer.squareup.com/reference/square/objects/Payment).
3. **Completion.** An explicit, server-set transition gated on a derived check: PayOrder moves the order to COMPLETED when "the sum of all these payments is equal to the order total" and the order has no fulfillments — [Pay for orders](https://developer.squareup.com/docs/orders-api/pay-for-orders). That a single `autocomplete=true` payment carrying `order_id` auto-completes the order is **unverified**.
4. **Idempotency.**
   - Keys are per request and client-chosen. Same key and same request: "It doesn't take the payment again… returns the response as the first successful `CreatePayment` response." A changed request returns an error, and "this behavior might vary depending on the API" — [Idempotency](https://developer.squareup.com/docs/build-basics/common-api-patterns/idempotency).
   - Error codes: `IDEMPOTENCY_KEY_REUSED`; `TEMPORARY_ERROR` is safe to retry with the same key — [ErrorCode](https://developer.squareup.com/reference/square/enums/ErrorCode).
   - Key length: 45 characters on CreatePayment ([CreatePayment](https://developer.squareup.com/reference/square/payments-api/create-payment)), 192 on UpdateOrder and PayOrder.
   - Square advises deriving the key from a stable transaction id, not a fresh UUID per attempt — [blog](https://developer.squareup.com/blog/understanding-the-essentials-idempotency/).
   - Server-side TTL is **unverified**. The Mobile Payments SDK keeps keys for 24 h locally — [MPSDK](https://developer.squareup.com/docs/mobile-payments-sdk/android/take-payments).
5. **Concurrency.** Order `version` "is incremented each time an update is committed". A stale version fails with `VERSION_MISMATCH`; the fix is to re-read, reapply and resubmit — [Optimistic concurrency](https://developer.squareup.com/docs/build-basics/common-api-patterns/optimistic-concurrency). Payment `version_token` gates capture and refund — [Delayed capture](https://developer.squareup.com/docs/payments-api/take-payments/card-payments/delayed-capture); [Refund payments](https://developer.squareup.com/docs/payments-api/refund-payments).
6. **Offline.**
   - Mobile Payments SDK: "The seller is responsible for any expired, declined, or disputed payments accepted while offline". Up to 1000 payments in 24 h. The payment `id` is null until uploaded, with a `localID` in the meantime. Statuses run `QUEUED` → `UPLOADED` → `PROCESSED`, or `FAILED_TO_*`; `FAILED_TO_PROCESS` "cannot be recovered" — [MPSDK offline](https://developer.squareup.com/docs/mobile-payments-sdk/ios/offline-payments).
   - Hardware must upload within 72 h; per-transaction caps are seller-set — [help 7777](https://squareup.com/help/us/en/article/7777-process-card-payments-with-offline-mode).
   - The Payment object carries `is_offline_payment` — [Payment](https://developer.squareup.com/reference/square/objects/Payment).
   - That the Terminal API does not support offline is **unverified**.
   - Catch-up is the device uploading the stored payment itself.
7. **Ledger vs order.** Money lives in Payment and PaymentRefund. The order holds rollups (`tenders`, `refunds`, `net_amount_due_money`, `return_amounts`, `net_amounts`) that Square maintains — [Order](https://developer.squareup.com/reference/square/objects/Order). Refunds "create a return order" with `returns[].source_order_id` — [Refunds and Exchanges](https://developer.squareup.com/docs/orders-api/order-returns-exchanges).
8. **Partial failure.**
   - Retry with the same key.
   - Delayed capture (`autocomplete=false`) leaves the payment APPROVED. `delay_action` defaults to CANCEL, and COMPLETE is allowed only without an `order_id`. Defaults are 36 h card-present and 7 days card-not-present — [Delayed capture](https://developer.squareup.com/docs/payments-api/take-payments/card-payments/delayed-capture). So **an authorisation nobody records voids itself by default**.
   - If the SDK lost the key, fall back to `ListPayments` by `referenceId` — [MPSDK](https://developer.squareup.com/docs/mobile-payments-sdk/android/take-payments).

## 2. Stripe (PaymentIntents, Terminal, Refunds, idempotency)

1. **Lifecycle.**
   - PaymentIntent statuses: `requires_payment_method`, `requires_confirmation`, `requires_action`, `processing`, `requires_capture`, `succeeded`, `canceled`.
   - Cancel is possible only before `processing`/`succeeded` and "can't be undone" — [Lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle).
   - "A PaymentIntent can't be canceled after it has succeeded"; refund instead — [Refunds](https://docs.stripe.com/refunds).
   - Before confirmation the amount can be updated, and an interrupted checkout should reuse the same intent — [Payment Intents](https://docs.stripe.com/payments/payment-intents).
2. **Tenders.** The PaymentIntent is separate from the merchant's order, linked via `metadata[order_id]` "to easily reconcile". One intent may have several Charges (attempts) — [Payment Intents](https://docs.stripe.com/payments/payment-intents). The intent is a mutable state machine; a Refund is its own object referencing `charge`/`payment_intent` — [Refund object](https://docs.stripe.com/api/refunds/object).
3. **Completion.** `succeeded` is set by Stripe: "you can confidently fulfill the order" — [Lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle). Manual capture runs `requires_capture` → `succeeded` — [Place a hold](https://docs.stripe.com/payments/place-a-hold-on-a-payment-method). Order completion is left to the merchant.
4. **Idempotency.**
   - `Idempotency-Key` on POSTs, up to 255 characters. Stripe "saves the status code and body of the first request… after endpoint execution begins… including `500` errors". Keys may be pruned after 24 h.
   - Stripe "compares incoming parameters to those of the original request and errors if they're not the same". Validation failures and requests that conflict with one still executing are not saved — [Idempotent requests](https://docs.stripe.com/api/idempotent_requests).
   - Concurrent duplicate: 409 `idempotency_key_in_use` — [Error codes](https://docs.stripe.com/error-codes). Replays carry `Idempotent-Replayed: true` — [Advanced errors](https://docs.stripe.com/error-low-level).
   - API v2 keeps keys 30 days and re-executes a failed request to completion without extra side effects — [API v2 overview](https://docs.stripe.com/api-v2-overview#idempotency).
5. **Concurrency.** No version field. Objects are locked internally; a request that cannot get the lock fails with 429 `lock_timeout` and is not processed. Stripe advises: "make sure that mutations on the same object are queued up and run sequentially" — [Rate limits](https://docs.stripe.com/rate-limits).
6. **Offline (Terminal).**
   - "You, as the user, assume all decline and tamper-related risks… there's no way to recover the funds."
   - Offline intents have a null `id`; put a custom identifier in `metadata` and match via webhooks. The id "may still be null if creating the PaymentIntent in the backend failed".
   - The offline maximum is 10,000 USD per payment. A smart reader must have been online within 24 h.
   - Offline intents cannot be cancelled or refunded until forwarded — [Collect offline](https://docs.stripe.com/terminal/features/operate-offline/collect-card-payments?terminal-card-present-integration=terminal&reader-type=internet&terminal-sdk-platform=android).
   - Catch-up is forwarding the stored payment — [Offline overview](https://docs.stripe.com/terminal/features/operate-offline/overview?reader-type=internet).
   - A deadline for forwarding is **unverified**.
7. **Ledger vs order.** Money lives entirely in Stripe objects; there is no order document. Consistency comes from metadata and webhooks — [Payment Intents](https://docs.stripe.com/payments/payment-intents).
8. **Partial failure.**
   - A 5xx is indeterminate. Retry with the same key and params within 24 h; after that, reconcile through the object, the Dashboard and webhooks before using a new key. Store a local identifier in metadata and persist the key across restarts — [Advanced errors](https://docs.stripe.com/error-low-level).
   - Stripe's blog: if the failure was rolled back by an ACID database, "it'll be safe to retry it wholesale. Otherwise, state is recovered and the call is continued" — [Designing robust and predictable APIs with idempotency](https://stripe.com/blog/idempotency).

## 3. Adyen (Terminal API / nexo SaleToPOI, Checkout)

1. **Lifecycle.**
   - Adyen holds payments, not the sale. That the POS owns the sale is an inference from the message set; no page states it.
   - "After the payment is completed, you cannot use an abort request to cancel the payment"; if a cancel failed and the payment was approved, "you will need to refund" — [Cancel a transaction](https://docs.adyen.com/point-of-sale/basic-tapi-integration/cancel-a-transaction).
   - Refunds are referenced (a ReversalRequest against the PSP reference) or unreferenced (a new PaymentRequest) — [Refund payment](https://docs.adyen.com/point-of-sale/basic-tapi-integration/refund-payment/).
   - Online `/orders` (partial payments) closes when `remainingAmount` reaches 0, sending the ORDER_CLOSED webhook. An unfinished order expires (24 h default) and its payments are cancelled or refunded — [Partial payments](https://docs.adyen.com/online-payments/partial-payments/).
2. **Tenders.**
   - Each payment is its own entity: save the `POITransactionID`; the sale reference is `SaleTransactionID.TransactionID` — [Make a payment](https://docs.adyen.com/point-of-sale/basic-tapi-integration/make-a-payment/).
   - In `/orders`, each payment references the order by `orderData`, which is re-issued after every payment — [Partial payments](https://docs.adyen.com/online-payments/partial-payments/).
   - Refunds are new modifications and "can't be refunded more than once or beyond 100%" — [Refund payment](https://docs.adyen.com/point-of-sale/basic-tapi-integration/refund-payment/).
3. **Completion.** At the till, the PaymentResponse `Result` is the fact, and a status query returns that original response — [Verify transaction status](https://docs.adyen.com/point-of-sale/basic-tapi-integration/verify-transaction-status/). `/orders`: derived (`remainingAmount` = 0) and set by Adyen.
4. **Idempotency.**
   - Terminal: `ServiceID` "Must be unique within the last 48 hours for the terminal" — [Make a payment](https://docs.adyen.com/point-of-sale/basic-tapi-integration/make-a-payment/).
   - Lost response: send TransactionStatusRequest with the original SaleID/ServiceID. `Success` returns the original response in `RepeatedResponseMessageBody`; `InProgress` means poll; `NotFound` means "You can retry the original transaction" — [Verify transaction status](https://docs.adyen.com/point-of-sale/basic-tapi-integration/verify-transaction-status/).
   - Checkout: `idempotency-key` header up to 64 chars, "valid for a period of 7 to 14 days". A duplicate in flight returns 422/409 with error 704; retry only when `transient-error: true`; a processed duplicate returns "the response to the first attempt" — [API idempotency](https://docs.adyen.com/development-resources/api-idempotency/).
   - Reused ServiceID, or reused key with a different body: **unverified**.
5. **Concurrency.** No versions or ETags. The terminal runs one transaction at a time and returns `Busy` with the in-flight `serviceId` — [Error scenarios](https://docs.adyen.com/point-of-sale/error-scenarios).
6. **Offline.**
   - The terminal "stores the card data and details of the transaction" and approves "without any verification" within floor limits, then "sends the stored payments" on reconnect. The offline response has no PSP reference; the real result arrives by webhook.
   - "You are fully liable for the risk of failed captures, chargebacks, and disputes" — [Offline payment](https://docs.adyen.com/point-of-sale/offline-payment/).
7. **Ledger vs order.** Money is in payment records keyed by PSP reference. Unreferenced refunds "require manual reconciliation" — [Refund payment](https://docs.adyen.com/point-of-sale/basic-tapi-integration/refund-payment/).
8. **Partial failure.**
   - "We recommend that your integration automatically checks the status of a transaction any time it fails to receive a transaction response" (after 120 s local, 150 s cloud) — [Verify transaction status](https://docs.adyen.com/point-of-sale/basic-tapi-integration/verify-transaction-status/).
   - When capture state is unknown, a Checkout reversal "cancels the payment" if uncaptured and "refunds the payment" if captured — [Reversal](https://docs.adyen.com/online-payments/reversal).
   - An Adyen statement that the merchant must reverse on unknown status: **not found / unverified**.

## 4. Shopify (Admin GraphQL, Order Editing, POS)

1. **Lifecycle.**
   - Order states are flags and timestamps: `closed` (all items fulfilled or cancelled) and `cancelledAt` — [Order](https://shopify.dev/docs/api/admin-graphql/latest/objects/Order).
   - Money state is separate: `displayFinancialStatus` (PENDING … PAID, PARTIALLY_REFUNDED, REFUNDED, VOIDED, EXPIRED) — [OrderDisplayFinancialStatus](https://shopify.dev/docs/api/admin-graphql/latest/enums/OrderDisplayFinancialStatus).
   - **Paid orders can be edited**, through a separate session: `orderEditBegin` → `CalculatedOrder` → `orderEditCommit`. "If an edit changes the total order value, then a balance might need to be collected from or refunded to the customer"; collection is by invoice — [Edit orders](https://shopify.dev/docs/apps/build/orders-fulfillment/order-management-apps/edit-orders).
   - Edit limits: unfulfilled lines only, and not archived, imported or Installments orders — [Considerations](https://help.shopify.com/en/manual/fulfillment/managing-orders/editing-orders/considerations).
   - POS: on an order being paid in multiple payments, if you "edit the order, then you can't receive additional payments on that order" — [Multiple partial payments](https://help.shopify.com/en/manual/sell-in-person/shopify-pos/payment-management/multiple-partial-payments).
2. **Tenders.**
   - `OrderTransaction` records under the order: `kind` (AUTHORIZATION, CAPTURE, SALE, VOID, REFUND, CHANGE…), `status` (PENDING, SUCCESS, FAILURE, ERROR, AWAITING_RESPONSE, UNKNOWN) and `parentTransaction` — [OrderTransactionKind](https://shopify.dev/docs/api/admin-graphql/latest/enums/OrderTransactionKind); [OrderTransactionStatus](https://shopify.dev/docs/api/admin-graphql/latest/enums/OrderTransactionStatus); [OrderTransaction](https://shopify.dev/docs/api/admin-graphql/latest/objects/OrderTransaction).
   - Corrections are new transactions pointing at a parent. "Append-only" is not stated in so many words (**unverified**), but no documented mutation edits a transaction.
3. **Completion.**
   - Derived: `fullyPaid` "returns `true` when the total amount received equals or exceeds the order total"; `totalOutstandingSet` is "the total amount not yet transacted" — [Order](https://shopify.dev/docs/api/admin-graphql/latest/objects/Order).
   - The explicit command `orderMarkAsPaid` "Marks an order as paid by recording a payment transaction for the outstanding amount": the command writes a transaction, not a flag — [orderMarkAsPaid](https://shopify.dev/docs/api/admin-graphql/latest/mutations/orderMarkAsPaid).
4. **Idempotency.**
   - `@idempotent(key:)` on 17 mutations. The key is per operation and retries must reuse it. Keys are kept 24 h.
   - `IDEMPOTENCY_KEY_PARAMETER_MISMATCH` on a changed body (parameters are fingerprinted); `IDEMPOTENCY_CONCURRENT_REQUEST` while in flight. A replay is "the cached GraphQL response without reprocessing" (rebuilt from current records) — [Implementing idempotency](https://shopify.dev/docs/api/usage/implementing-idempotency).
   - The key is mandatory on `refundCreate` from 2026-04 — [refundCreate](https://shopify.dev/docs/api/admin-graphql/latest/mutations/refundCreate).
5. **Concurrency.** Not documented for edit sessions — [orderEditBegin](https://shopify.dev/docs/api/admin-graphql/latest/mutations/orderEditBegin) (**unverified**).
6. **Offline.**
   - Cash and custom methods work offline; "You can't process exchanges, returns, or voids when you're offline"; orders sync on reconnect — [Offline features](https://help.shopify.com/en/manual/sell-in-person/shopify-pos/selling-offline/offline-features).
   - Offline card payments (opt-in) "aren't routed to the payment processor" until reconnect. Badges run Payment pending → Paid or Card declined, and reconnecting "ideally within 24 hours" is recommended — [Offline payments](https://help.shopify.com/en/manual/sell-in-person/shopify-pos/selling-offline/offline-payments).
   - Who bears the loss: **unverified**.
7. **Ledger vs order.** Order money fields (`totalReceivedSet`, `netPaymentSet`, `totalOutstandingSet`) are computed from transactions — [Order](https://shopify.dev/docs/api/admin-graphql/latest/objects/Order). Money can ride in the order **only at creation** (`orderCreate` accepts `transactions[]`) — [orderCreate](https://shopify.dev/docs/api/admin-graphql/latest/mutations/orderCreate); after that, only through transaction mutations.
8. **Partial failure.** In-doubt outcomes are statuses on the record itself (AWAITING_RESPONSE, UNKNOWN) — [OrderTransactionStatus](https://shopify.dev/docs/api/admin-graphql/latest/enums/OrderTransactionStatus). A reconciliation procedure is **unverified**.

## 5. Lightspeed Retail X-Series (formerly Vend)

1. **Lifecycle.**
   - `state` is parked, pending, closed or voided, plus attributes such as onaccount and layby — [States and attributes](https://x-series-api.lightspeedhq.com/docs/sales_states_and_attributes).
   - "The status of a closed sale should ONLY every be transitioned into `VOIDED`", and VOIDED is terminal — [Sale statuses](https://x-series-api.lightspeedhq.com/docs/sales_statuses).
   - "it is not possible to edit or modify the products on a closed sale… void the sale and recreate it" — [help](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25533970088603-Can-I-edit-the-products-on-a-closed-sale).
   - Void "does not automatically refund any payments" and "cannot be undone" — [Voiding sales](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534112747291-Voiding-sales).
   - Returns are new sales with negative lines and `return.original_sale_id` — [Returns](https://x-series-api.lightspeedhq.com/docs/sales_returns).
   - Managers may edit payment type, date and amount on a completed sale — [Sales history](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534095402907-Understanding-and-managing-the-Sales-history-page).
2. **Tenders.** **Embedded** in the sale (`register_sale_payments` or `payments`). An update must submit "all the attributes of an existing sale", and omitted lines "will be deleted and replaced" — [Sync sale](https://x-series-api.lightspeedhq.com/docs/sync_sale_into_vend); [Migration guide](https://x-series-api.lightspeedhq.com/docs/sales_migration_guide). Payments are mutable rows in the document.
3. **Completion.** **Explicit and client-set**: the client sets `state` to closed — [Returns](https://x-series-api.lightspeedhq.com/docs/sales_returns). On-account sales move to `ONACCOUNT_CLOSED` once paid off — [Sale statuses](https://x-series-api.lightspeedhq.com/docs/sales_statuses). Whether the server checks payments ≥ total is **unverified**.
4. **Idempotency.** No key is documented. Clients store and resend the server sale `id`; `source_id` carries the external id — [Sync sale](https://x-series-api.lightspeedhq.com/docs/sync_sale_into_vend).
5. **Concurrency.** `version` exists for incremental sync, not locking — [Sync entity](https://x-series-api.lightspeedhq.com/docs/sync_entity_to_external_system). Last-write-wins is **unverified**.
6. **Offline.**
   - Offline cash sales, parked sales, layby and on-account are allowed; sales are "saved in your browser" and auto-sync, with a "Retry errored sales" button. Integrated card payments do not work offline — [Offline mode](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534272395163-Selling-in-offline-mode).
   - Errored sales come from long offline periods or from changed taxes and settings — [Errored sales](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25533948150043-What-causes-errored-sales).
   - Offline terminal payments are stored on the terminal and "are not linked to a sales record"; the merchant accepts "responsibility for any expired or declined transactions" — [Payments offline](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25533690950427-Lightspeed-Payments-offline-mode-with-Retail-POS-X-Series).
7. **Ledger vs order.** Money is on the sale document; integrated payments also appear separately under Finance (same page).
8. **Partial failure.** Recovery is manual: park the sale and "Apply payment/refund" from Sales history. A bad payload can return "`200 OK` with an empty sale" — [Sync sale](https://x-series-api.lightspeedhq.com/docs/sync_sale_into_vend).

## 6. Clover

1. **Lifecycle.**
   - Order `state` is "Automatically set by Clover… when a line item is added to the order or a payment is taken" (open, or OPEN and locked); `paymentState` is paid, open or partially paid — [Create order](https://docs.clover.com/dev/reference/ordercreateorder); [Orders FAQs](https://docs.clover.com/dev/docs/orders-faqs).
   - Line items lock once a payment exists: Clover Community answer, **unverified** (page unreachable).
   - Amend path: void (before settlement) or refund — [FAQs](https://docs.clover.com/dev/docs/faqs). Ecommerce returns require status Paid or Fulfilled — [Refunding payments](https://docs.clover.com/dev/docs/ecommerce-refunding-payments).
2. **Tenders.** Separate objects: "Each payment object includes a reference to the associated order" — [Payments and refunds FAQs](https://docs.clover.com/dev/docs/payments-and-refunds-faqs). External tenders are posted to `/orders/{id}/payments` "for bookkeeping purposes" — [Create payment for order](https://docs.clover.com/dev/reference/ordercreatepaymentfororder). Refund-as-object and void-as-mark: **unverified**.
3. **Completion.** Set by Clover: "If the payment is processed through Clover, the order status updates automatically" — [Orders FAQs](https://docs.clover.com/dev/docs/orders-faqs). The exact sum rule is **unverified**.
4. **Idempotency.**
   - The Ecommerce charge needs an `Idempotency-Key` ("version 4 UUID… Use the same key to retry the request and prevent any duplicate charges") — [Accepting payments](https://docs.clover.com/dev/docs/ecommerce-accepting-payments).
   - Save the key "before the transaction request is made" — [Tracking with idempotency keys](https://docs.clover.com/dev/docs/tracking-transactions-with-idempotency-keys).
   - A same-key retry returns the completed charge — [Handling communication issues](https://docs.clover.com/dev/docs/handling-communication-issues).
   - TTL and body mismatch: **unverified**.
5. **Concurrency.** Only `modifiedTime` exists; no version is documented (**unverified**).
6. **Offline.**
   - The device records offline payments without validating the card, for up to 7 days by default, within merchant limits. Overrides set "the level of risk the merchant must accept if an offline payment is eventually declined" — [Handling offline payments](https://docs.clover.com/dev/docs/handling-offline-payments).
   - "REST API may not accurately reflect the state of payments that were taken offline" — [Reconciliation and recovery](https://docs.clover.com/dev/docs/payment-reconciliation-and-recovery).
7. **Ledger vs order.** Payment, refund and void are separate objects; the order's `paymentState` is derived from them — [Orders FAQs](https://docs.clover.com/dev/docs/orders-faqs).
8. **Partial failure.** Create a unique `externalId` per transaction and store it "in a persistent fashion so that your POS can recover from POS shutdowns". On timeout, `retrievePayment()` returns FOUND, IN_PROGRESS or NOT_FOUND, or query `/payments` by `externalPaymentId` — [Reconciliation and recovery](https://docs.clover.com/dev/docs/payment-reconciliation-and-recovery).

## 7. Odoo Point of Sale (source, 18.0)

Odoo is the closest structural analogue to WCPOS: a browser till, offline-first, that syncs whole order documents with their payments to a server.

1. **Lifecycle.**
   - `pos.order.state` is `draft`, `cancel`, `paid`, `done` (posted) or `invoiced` — [pos_order.py#L353-L355](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L353-L355).
   - **A paid order cannot go back**: `write()` raises "This order has already been paid. You cannot set it back to draft or edit it." — [#L571-L573](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L571-L573).
   - Only draft orders can be cancelled — [#L1100-L1102](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L1100-L1102).
   - `paid` becomes `done` when the session posts — [pos_session.py#L467-L469](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_session.py#L467-L469).
   - Amend path is a **refund, which is a new order**: `_refund()` copies the order with a new uuid, negated quantities, `amount_paid: 0` and `refunded_orderline_id` — [#L1257-L1270](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L1257-L1270), [#L1553-L1562](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L1553-L1562).
   - A session cannot close while any of its orders is draft — [pos_session.py#L390-L395](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_session.py#L390-L395).
2. **Tenders.**
   - `pos.payment` is a separate model with `pos_order_id`, `transaction_id`, `payment_status` and a unique `uuid` — [pos_payment.py#L19-L45](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_payment.py#L19-L45).
   - **Mutable until posted**:
     - The amount is frozen once the order is `done`/`invoiced` — [pos_payment.py#L59-L63](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_payment.py#L59-L63).
     - On a paid order a change may not reduce paid below the total — [pos_order.py#L578-L582](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L578-L582).
     - No change is allowed after the receipt has printed — [#L585-L586](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L585-L586).
     - Every change is logged to chatter — [#L588-L593](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L588-L593).
   - Cash change is a negative payment with `is_change=True` — [#L232-L245](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L232-L245).
3. **Completion.** Explicit and server-set after a derived check:
   - A client-sent `state: 'paid'` is stored as `draft` ("paid state will be set later") — [#L76-L79](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L76-L79).
   - `amount_paid` is recomputed from rows "because we don't trust the client" — [#L229](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L229).
   - `action_pos_order_paid()` writes `paid` only if total − paid is zero (with cash-rounding tolerance), else raises — [#L794-L822](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L794-L822).
   - A failed check is logged, not rejected, so the order is saved as draft — [#L190-L204](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L190-L204).
4. **Idempotency.**
   - Dedupe is by the client uuid, under a unique constraint — [#L385](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L385), [#L1042-L1043](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L1042-L1043).
   - `sync_from_ui`: a new uuid is created; an existing draft is updated; **an existing non-draft order ignores the payload and returns its id** — [#L1155-L1203](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L1155-L1203).
   - Payments in a re-sync are applied as per-row x2many commands, dropping commands for ids that no longer exist — [#L106-L117](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L106-L117).
   - 17.0 instead unlinked every payment and re-created them from the payload — [17.0 #L193-L216](https://github.com/odoo/odoo/blob/d4fa9446f59e557c04efe5cd26d6e5f765f2240b/addons/point_of_sale/models/pos_order.py#L193-L216).
5. **Concurrency.**
   - No version check on sync (only incidental `write_date` uses — [#L1427](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L1427)).
   - Odoo relies on PostgreSQL REPEATABLE READ with up to 5 retries on serialization failure — [sql_db.py#L303](https://github.com/odoo/odoo/blob/18.0/odoo/sql_db.py#L303); [service/model.py#L24-L26](https://github.com/odoo/odoo/blob/18.0/odoo/service/model.py#L24-L26) (branch links, not pinned).
   - The client serialises pushes with a mutex — [pos_store.js#L1275-L1371](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/static/src/app/store/pos_store.js#L1275-L1371).
6. **Offline.**
   - Orders and payments live in IndexedDB — [indexed_db.js](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/static/src/app/models/utils/indexed_db.js), with a pending create/write/delete queue — [pos_store.js#L129-L136](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/static/src/app/store/pos_store.js#L129-L136).
   - The server catches up by **receiving the whole order document per order**, not an event log. The client marks the order `paid` and proceeds to the receipt even when the connection is lost; a server rejection resets it to draft — [payment_screen.js#L303-L333](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/static/src/app/screens/payment_screen/payment_screen.js#L303-L333).
   - An order arriving for a closed session is re-homed to an open session of the same config — [#L36-L55](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_order.py#L36-L55).
7. **Ledger vs order.** Payments become accounting entries only at session close. `_validate_session` builds them in a savepoint, rolls back if they are unbalanced, posts, then flips `paid` → `done` — [pos_session.py#L426-L469](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/point_of_sale/models/pos_session.py#L426-L469).
8. **Partial failure.**
   - A terminal approval lives on the client payment line until sync. Adyen's webhook result is held in one `adyen_latest_response` field per payment method, cleared on the next request — [pos_payment_method.py#L28](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/pos_adyen/models/pos_payment_method.py#L28), [#L87-L88](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/pos_adyen/models/pos_payment_method.py#L87-L88).
   - The client matches the result by `ServiceID` — [payment_adyen.js#L222-L297](https://github.com/odoo/odoo/blob/9e38c31ee1ae1a34e0bb009f9e93b980bded8a09/addons/pos_adyen/static/src/app/payment_adyen.js#L222-L297).
   - A server-side record of an approval that never synced: **not found / unverified**.

## 8. Toast

1. **Lifecycle.**
   - Check `paymentStatus` is OPEN, PAID (card applied, tip not adjusted) or CLOSED ("no remaining amount due") — [Check](https://doc.toasttab.com/openapi/orders/tag/Data-definitions/schema/Check/).
   - **A closed check can be reopened**: reopening sets the check to paid; adding items sets both check and order to open — [Reopening](https://doc.toasttab.com/doc/platformguide/platformOrdersReopening.html).
   - Amend path: "reopen the check, void the item, then collect the corrected payment" — [Voiding](https://support.toasttab.com/en/article/Voiding-Items-Payments-and-Checks).
   - Terminal state is "restricted" (configurable, from end of day to 6 months), after which "the only update allowed is to process a refund" — [Restricted orders](https://doc.toasttab.com/doc/platformguide/adminViewingRestrictedOrders.html).
2. **Tenders.**
   - Payments are an array in each check (Order → `checks[]` → `payments[]`) — [Order](https://doc.toasttab.com/openapi/orders/tag/Data-definitions/schema/Order/). Each is still an addressable entity with its own guid, endpoint, `orderGuid`/`checkGuid`, `voidInfo` and `refundStatus` — [Payment information](https://doc.toasttab.com/doc/devguide/apiPaymentntInformation.html).
   - Payments are appended by POST to `/orders/{o}/checks/{c}/payments`, with statuses running through AUTHORIZED, CAPTURED, VOIDED and ERROR_NETWORK, among others — [ordersChecksPaymentsPost](https://doc.toasttab.com/openapi/orders/operation/ordersChecksPaymentsPost/).
3. **Completion.** Derived: CLOSED means no remaining amount due, and the order is closed when all checks are. `closedDate` is "the most recent date", so completion can recur after a reopen — [Check](https://doc.toasttab.com/openapi/orders/tag/Data-definitions/schema/Check/); [Order](https://doc.toasttab.com/openapi/orders/tag/Data-definitions/schema/Order/).
4. **Idempotency.** No idempotency header was found (**unverified**). `externalId` uniqueness on orders is enforced from 2026-02-02 — [announcement](https://api-updates.toasttab.com/announcements/ann_z11cnPVqO0LjR).
5. **Concurrency.** No version or ETag is documented (**unverified**).
6. **Offline.** Offline cards are processed on reconnect, and "any decline that occurs at that point is final" — [Credit card FAQ](https://support.toasttab.com/en/article/Credit-Card-Payments-FAQ). In the UK, Ireland and Canada the merchant is liable — [Offline payments](https://support.toasttab.com/en/article/Identify-Offline-Payments-Canada-Ireland-the-U-K).
7. **Ledger vs order.** A hybrid: embedded but addressable payments. Refunds are recorded on the payment, and the check's `totalAmount` is "not affected by refunds" — [Check](https://doc.toasttab.com/openapi/orders/tag/Data-definitions/schema/Check/).
8. **Partial failure.** An `ERROR_NETWORK` payment status exists; a recovery procedure is **unverified**.

## 9. WooCommerce core (source, trunk)

1. **Lifecycle.**
   - Statuses: pending, failed, on-hold, processing, completed, cancelled, refunded, checkout-draft, among others — [OrderStatus.php](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/src/Enums/OrderStatus.php#L19-L96). None is enforced as terminal.
   - `is_editable()` is true only for pending, on-hold and auto-draft — [class-wc-order.php#L1707-L1716](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/class-wc-order.php#L1707-L1716). Only the admin AJAX handlers enforce it — [class-wc-ajax.php#L1709](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/class-wc-ajax.php#L1709).
   - **The REST API never checks it**: the field is output only — [v2 controller#L441-L442](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/rest-api/Controllers/Version2/class-wc-rest-orders-v2-controller.php#L441-L442). A wc/v3 PUT can change lines on a completed order and recalculate totals — [v3 controller#L283-L287](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-orders-controller.php#L283-L287).
2. **Tenders.** One embedded tender (`payment_method`, `transaction_id`, `date_paid`). Refunds are `shop_order_refund` children.
3. **Completion.**
   - `is_paid()` is a status check against `wc_get_is_paid_statuses()` (processing and completed), with no amount comparison — [class-wc-order.php#L1724-L1726](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/class-wc-order.php#L1724-L1726); [wc-order-functions.php#L134-L143](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/wc-order-functions.php#L134-L143).
   - The status is set explicitly by `payment_complete($txn)`. It acts only from on-hold, pending, failed or cancelled; it sets `transaction_id` and `date_paid`, moves to processing or completed, and fires `woocommerce_payment_complete` — [class-wc-order.php#L138-L208](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/class-wc-order.php#L138-L208); [OrderStatus.php#L103-L108](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/src/Enums/OrderStatus.php#L103-L108).
   - REST `set_paid` calls it after the save, only on create or when `needs_payment()` — [v3 controller#L298-L305](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-orders-controller.php#L298-L305).
4. **Idempotency.**
   - None in core REST: no idempotency or precondition header handling under `includes/rest-api` (grep over the tree).
   - `payment_complete()` is naturally repeat-safe, because a second call finds the status outside its valid list.
   - The Store API reuses the session's draft order with the same cart hash, "otherwise the POST flow would orphan it by creating a fresh order on every retry" — [DraftOrderTrait.php#L53-L69](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/src/StoreApi/Utilities/DraftOrderTrait.php#L53-L69).
5. **Concurrency.** None. The REST update sets props, calculates totals, sets status and saves; last write wins — [v3 controller#L270-L305](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/rest-api/Controllers/Version3/class-wc-rest-orders-controller.php#L270-L305). Any HPOS data-store row check is **unverified**.
6. **Offline.** Not applicable.
7. **Ledger vs order.** Money is on the order; refunds are child objects. The parent goes to `refunded` once refunds are complete — [wc-order-functions.php#L737](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/wc-order-functions.php#L737).
8. **Partial failure.**
   - Checkout: if `payment_complete` throws after the gateway charged, core logs, adds the note "Payment complete event failed." and returns false. Neither the status nor the charge is rolled back — [class-wc-order.php#L190-L206](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/class-wc-order.php#L190-L206).
   - Refund: **record first, then gateway**. `$refund->save()` runs before `process_refund`; on a gateway `WP_Error` the refund is deleted — [wc-order-functions.php#L676-L683](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/wc-order-functions.php#L676-L683).
   - A later exception force-deletes the refund even after the gateway refunded — [#L754-L758](https://github.com/woocommerce/woocommerce/blob/19e3f1a19cf7f16c6d8027e554bd91934f764593/plugins/woocommerce/includes/wc-order-functions.php#L754-L758). That this leaves money moved with no record is an inference from the code order.

## 10. Payment-network semantics: void, reversal, refund

Visa/Mastercard rulebooks and the ISO 8583 and nexo specification texts are not freely readable; the nexo implementation spec is gated at [nexo-standards.org](https://nexo-standards.org/standards/nexo-implementation-specification) (**unverified**).

- **Authorization** places a hold; "Authorizations are meant to be settled." An **authorization reversal** "notif[ies] the issuer that all, or part, of a transaction has been cancelled and that the hold… should be removed". If the sale won't complete it "must be reversed within 24 hours"; an authorization matched to neither clearing nor a reversal incurs a misuse fee — [Visa merchant best practices (PDF)](https://usa.visa.com/content/dam/VCOM/regional/na/us/support-legal/documents/authorization-and-reversal-processing-best-practices-for-merchants.pdf).
- **Timeout reversal** (*processor doc*, Worldpay ISO 8583 v2.46):
  - With no response within the time limit, the terminal "sends a 0420 reversal request" and resends it "until the processor responds".
  - Reason codes include Time Out, Clerk Canceled, Customer Canceled and Hardware Failure — [Worldpay ISO 8583 guide (PDF)](https://developerengine.fisglobal.com/assets/pdf/Worldpay_ISO_8583_Reference_Guide_V2.46.pdf).
  - The generic 0400 vs 0420 meaning is **unverified** and varies by processor.
- **nexo reversal** (*processor doc*, Swedbank Pay): "an immediate financial correction on a successful transaction that was just made", allowed only on the last transaction. `ReversalReason` is `Malfunction` when software sends it automatically — [Swedbank Pay nexo reversal](https://developer.swedbankpay.com/pax-terminal/Nexo-Retailer/Quick-guide/reversal/). TransactionStatus without a reference returns the last response — [TransactionStatus](https://developer.swedbankpay.com/pax-terminal/Nexo-Retailer/Quick-guide/transactionstatus/).
- **Void vs refund** (*processor docs*):
  - Adyen: cancel only "before you capture it" — [Adyen cancel](https://docs.adyen.com/online-payments/cancel/).
  - Toast: "Void only works on the same business day" — [Toast voiding](https://support.toasttab.com/en/article/Voiding-Items-Payments-and-Checks).
  - Stripe: an uncaptured payment must be cancelled, not refunded, and a refund soon after the charge may process as a reversal — [Stripe refunds](https://docs.stripe.com/refunds).
  - Pattern: void and reversal exist **only before capture or settlement**; after that the only path is a refund, which is a new transaction and can itself fail.

## 11. Ledger-design literature (first-party)

- **Stripe blog** (Brandur): the client mints one key per operation. A lost response gets "a cached result"; a failure rolled back by ACID is "safe to retry it wholesale"; retries use exponential backoff with jitter — [stripe.com/blog/idempotency](https://stripe.com/blog/idempotency).
- **Brandur, Postgres implementation** (author's own site): [brandur.org/idempotency-keys](https://brandur.org/idempotency-keys).
  - Atomic phases sit between *foreign state mutations*: "once we make our first foreign state mutation, we're committed one way or another".
  - The local record (ride, null charge id) is committed **before** the charge, with a recovery point. The charge uses a derived idempotency key.
  - The stored request params produce a 409 on mismatch, and a locked key returns 409. Final outcomes, declines included, are stored.
  - A *completer* finishes abandoned requests; a reaper deletes keys after about 72 h.
- **Modern Treasury.**
  - "Once a ledger transaction is `posted` or `archived` its status and entries are immutable"; to undo, "write a second reversing Ledger Transaction". In MT, `archived` means *cancelled*, not superseded — [Transaction status](https://docs.moderntreasury.com/docs/transaction-status-and-balances).
  - Transactions carry `reverses_ledger_transaction_id` / `reversed_by_ledger_transaction_id` — [Ledger transaction object](https://docs.moderntreasury.com/platform/reference/ledger-transaction-object).
  - "Only pending Entries can be replaced; posted and archived Entries are permanent"; replaced entries get `discarded_at`, and current entries are those without it — [How to scale a ledger II](https://www.moderntreasury.com/journal/how-to-scale-a-ledger-part-ii).
  - "store immutable transactions and always compute balances from those transactions" — [Accounting for developers I](https://www.moderntreasury.com/journal/accounting-for-developers-part-i).
  - `lock_version` and balance conditions fail the write (422) "to prevent a double-spend" — [Lock on balance or version](https://docs.moderntreasury.com/ledgers/docs/lock-on-account-balance-or-version).
  - Idempotency results are kept 24 h, successes only, with no documented body-mismatch check — [Idempotent requests](https://docs.moderntreasury.com/reference/idempotent-requests).
  - Parts II–III of "Accounting for developers": **unverified** (not fetched).
- **Martin Fowler** (2006 drafts).
  - Balance "is derived as the sum of all the Accounting Entrys" — [AccountingNarrative](https://martinfowler.com/eaaDev/AccountingNarrative.html).
  - "Entries are immutable" — [AccountingTransaction](https://martinfowler.com/eaaDev/AccountingTransaction.html).
  - Reversal adjustment marks the original "adjusted" — [ReversalAdjustment](https://martinfowler.com/eaaDev/ReversalAdjustment.html).
  - Replacement "distroying the history" is acceptable only until a real-world action follows: "when you've issued a payment from the account, this won't work" — [ReplacementAdjustment](https://martinfowler.com/eaaDev/ReplacementAdjustment.html).
  - "we never delete an event"; rejected events "stay in the event log to maintain history, but are otherwise ignored" — [RetroactiveEvent](https://martinfowler.com/eaaDev/RetroactiveEvent.html).
- **TigerBeetle.**
  - Pending → post/void two-phase transfers; resolving one "does not involve modifying the pending transfer… you create a new transfer" — [Two-phase transfers](https://docs.tigerbeetle.com/coding/two-phase-transfers/).
  - "Transfers cannot be modified after creation" — [Transfer](https://docs.tigerbeetle.com/reference/transfer/).
  - Result codes: `exists` (handle as created), `exists_with_different_*` (mismatch), `id_already_failed` — [create_transfers](https://docs.tigerbeetle.com/reference/requests/create_transfers/).
  - The client "should generate the `id`" and "persist the `id`… before submitting" — [Reliable transaction submission](https://docs.tigerbeetle.com/coding/reliable-transaction-submission/).
  - Linked events "all succeed or fail together" — [Linked events](https://docs.tigerbeetle.com/coding/linked-events/).

---

## Cross-system comparison

| | Reopen a paid sale? | Amend path | Tenders | Payment records | "Paid" decided by | Idempotency | Concurrency | Offline card risk |
|---|---|---|---|---|---|---|---|---|
| **Square** | No (COMPLETED terminal; paid lines locked) | Return order linked by `source_order_id`; refund | Separate Payment with `order_id`; order rollups read-only | Status machine + `version_token` | Server: PayOrder when Σ = total | Per request; replay; `IDEMPOTENCY_KEY_REUSED`; TTL unverified | `version` → `VERSION_MISMATCH` | Seller |
| **Stripe** | n/a (no order); succeeded intent cannot be cancelled | Refund object | PaymentIntent outside order (metadata) | Status machine; Refund separate | Stripe sets `succeeded` | Per request; 24 h; replay incl. 500; param mismatch error; 409 in flight | None; per-object lock, serialise | Merchant |
| **Adyen** | n/a; completed payment only refunded | Referenced/unreferenced refund | Separate payment per PSP ref | Append + status | Terminal `Result`; `/orders` closes at remaining 0 | ServiceID unique 48 h; TransactionStatus replays; Checkout key 7–14 d | Terminal one-at-a-time (`Busy`) | Merchant |
| **Shopify** | Yes, via separate edit session (unfulfilled lines) | Edit → balance owed, collected by invoice / refunded | `OrderTransaction` with parent (money in order only at create) | Append with `parentTransaction` (append-only unverified) | Derived: received ≥ total | Per op; 24 h; param fingerprint; concurrent error | Unverified | Unverified |
| **Lightspeed X** | No (closed → VOIDED only) | Void and re-ring; return = new sale | **Embedded**; whole-sale replace | Mutable rows | **Client** sets `closed` | None documented | `version` for sync only | Merchant |
| **Clover** | No (lines lock with payment; unverified) | Void before settlement or refund | Separate, references order | Unverified | Clover, automatically | UUID key on charges; same-key retry = status | Unverified | Merchant |
| **Odoo** | No (`write()` forbids paid → draft) | Refund = new order, negative lines | Separate `pos.payment`, synced with order as row commands | Mutable until posted; audit note | Server, after recomputing Σ; client claim downgraded | uuid unique; non-draft re-sync ignored | DB isolation + retry; client mutex | n/a (terminal integrations) |
| **Toast** | **Yes**, until "restricted" | Reopen → void item → collect corrected payment | Embedded but addressable, appended by POST | Status machine | Derived: no remaining due; can recur | None found; `externalId` unique | Unverified | Merchant (UK/IE/CA) |
| **WooCommerce** | Yes (REST ignores `is_editable`) | Edit; refund child | One tender on order | n/a | `payment_complete()` sets status; `is_paid` from status | None | None (last write wins) | n/a |

---

## Implications for WCPOS: testable design statements

Each statement names the systems that back it. "Test" says what a PHPUnit or integration case would assert.

1. **A payment row, once captured, is never edited, moved or archived. It changes only by a linked refund or void row.** Backed by Square, Stripe, Adyen, Shopify, Modern Treasury, TigerBeetle and Fowler (Odoo is the exception before posting). *Test:* after a capture, no code path (reopen included) changes the row's `amount`, `status` or `epoch`, or removes it from the counting set without writing a refund or void that references it.
2. **The balance of a sale is total minus every captured-and-not-refunded payment ever taken on it. There is no epoch filter.** Backed by Shopify (`totalOutstandingSet`), Toast (reopen keeps prior payments; collect "the corrected payment") and Square (`net_amount_due_money`). *Test:* reopen a sale paid 50/50, edit lines to a 120 total, and the balance is 20, not 120.
3. **Either remove reopen, or reopen means "the sale is open again with all its payments still counting".** Majority (Square, Odoo, Lightspeed, Clover): no reopen; amend through a refund plus a new sale or a linked return document. Minority (Toast, Shopify): reopen or edit with a recomputed balance. No system resets money on reopen. *Test (if reopen is kept):* after a reopen the sale's paid amount is unchanged, and completing it again needs only the difference.
4. **The server alone sets the completion stamp, at the moment its own payment rows sum to the total. A client-sent completion claim is ignored.** Backed by Odoo (stores the client's `paid` as `draft`), Square (PayOrder), Shopify (`fullyPaid`), Toast and Clover. *Test:* a push carrying the stamp with an outstanding balance leaves the order uncompleted; recording the last payment row sets the stamp in the same save.
5. **The till cannot write money into the order document. Payment rows and status are reachable only through payment commands; an order update carrying money meta is rejected or stripped.** Backed by Square, Stripe, Clover, Adyen and Shopify (after create). *Test:* a wc/v3-proxied order update that includes `_wcpos_payments` does not change the stored ledger.
6. **Once a sale is completed, the order update path ignores or refuses changes to lines and totals, as Odoo ignores re-syncs of non-draft orders and Square refuses UpdateOrder on COMPLETED.** WooCommerce REST does not enforce this itself, so WCPOS must. *Test:* a PUT changing line items on a completed POS order returns a refusal, and the lines are unchanged.
7. **Every money command carries a client-minted id. The server stores (id, body fingerprint, outcome) and enforces three behaviours.** Backed by Stripe, Square, Shopify, Adyen, Clover, Brandur, TigerBeetle and Odoo.
   - Same id and same body: replay the stored outcome.
   - Same id and a different body: 409/422 mismatch.
   - Same id while the first is still running: 409 in flight.

   *Test:* each of the three behaviours.
8. **Command ids are retained at least as long as the longest offline queue, or forever on the row they created.** The industry default is 24 h (Stripe, Shopify, Modern Treasury); TigerBeetle and Odoo (unique constraint) keep ids forever, which is the fit for offline tills. *Test:* replaying a 7-day-old record command does not create a second row.
9. **A gateway or terminal payment is two saves around the external call, not one.** First save: a `pending` row with the command id (and the gateway idempotency key derived from it). Then the external call. Second save: post (captured or authorized) or void. A sweeper resolves stranded `pending` rows by querying the provider by the client's reference. Backed by Brandur (record before foreign mutation, completer), Square (`delay_action` CANCEL default), TigerBeetle (pending → post/void), Adyen (TransactionStatus after timeout), Clover (`retrievePayment` by `externalId`) and Stripe (metadata + webhooks). *Test:* kill the request after the gateway call succeeds; the sweeper finds the pending row and posts it from the provider's record without charging again.
10. **A lost terminal response is resolved by status lookup on the original reference before any retry or reversal.** Backed by Adyen, Clover, Stripe and Square. *Test:* a retry after a timeout queries status first; a new charge is attempted only on NotFound.
11. **Every command carries the order version it was built against; a stale version is refused, and the till re-reads and retries.** Backed by Square (`version`/`VERSION_MISMATCH`) and Modern Treasury (`lock_version`); Stripe serialises per object instead. *Test:* two commands built on the same version, and the second is refused with a stale-version error.
12. **Offline card rows are recorded as offline, are forwarded as the payment itself, and the merchant carries the decline risk. The server never infers money from replayed cart intent.** Backed by Square, Stripe, Adyen, Clover, Lightspeed and Toast. *Test:* an offline row that later fails at the provider becomes `failed`, and the sale's balance reopens. That flow belongs to reconciliation, not to a reopen.
13. **A refund record is written before the gateway refund call, and is never silently deleted after the gateway reports success.** WooCommerce core deletes on gateway failure, but also force-deletes on a later exception even after success. *Test:* an exception thrown after `process_refund` succeeds leaves the refund record in place, marked for attention.

### Where the current WCPOS design already matches the industry
- Client-minted UUID per payment row as the idempotency key (ledger §3.1) matches Odoo's uuid, TigerBeetle's ids and Clover's `externalId`.
- Refund state as linked entries, not a payment status (ledger §3.1 `refunds[]`), matches Stripe, Square, Adyen and Shopify.
- Keeping every row and excluding some by a marker is Fowler's rejected-event and Modern Treasury's `discarded_at` pattern. Modern Treasury allows it only for **pending** entries, however, and the epoch filter applies it to **captured** ones. The review findings (missed epoch filters) are the cost Fowler names for reversal schemes: many entries that every query has to see past ([ReversalAdjustment](https://martinfowler.com/eaaDev/ReversalAdjustment.html); paraphrase, not a quote).

## Unverified / not found
- Square: server-side idempotency TTL; whether one `autocomplete=true` payment with `order_id` auto-completes the order; the Terminal API's offline support.
- Stripe: deadline to forward offline payments.
- Adyen: behaviour on a reused ServiceID, or a reused key with a different body; an explicit "reverse on unknown status" rule.
- Shopify: concurrency on edit sessions; offline loss liability; an explicit append-only statement for transactions.
- Lightspeed: whether the server checks payments ≥ total on close; last-write-wins on update.
- Clover: line-item lock after payment (community page unreachable); refund-object vs void-mark wording; idempotency TTL and mismatch behaviour; concurrency.
- Toast: any idempotency header; order versioning.
- Odoo: a server record of a terminal approval that never synced.
- WooCommerce: HPOS row-level concurrency.
- Generic ISO 8583 0400 vs 0420 semantics; the nexo spec text; Modern Treasury "Accounting for developers" parts II–III.
