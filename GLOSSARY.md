# WCPOS

The WordPress plugin that gives a WooCommerce store a point of sale: one catalogue, one set of orders, two front doors (the web shop and the till). These are the terms the money model uses; ADR 0040 records the decisions behind them.

## Language

**Sale**:
A WooCommerce order as the till sees it, whatever door created it. There is one order; the till never has a sale the shop cannot see.
_Avoid_: ticket, check, basket (a cart becomes a sale when its order exists)

**Tender**:
One payment taken against a sale: cash, a card capture, a gateway payment, a gift card. Recorded as a payment row that references the order; several tenders may pay one sale.
_Avoid_: payment method (that is the kind of tender, not the tender), transaction (WooCommerce's single `_transaction_id`)

**Payment row**:
The server-held record of one tender, with its status (`pending`, `authorized`, `captured`, `failed`, `voided`) and its refunds. Once captured it is never edited, moved or archived; it changes only through a linked refund or void.
_Avoid_: ledger entry (fine in prose, but the ledger is the set of rows, not one), payment object

**Balance**:
The sale's total minus every captured tender plus every refund. Derived by the server on every money write; never sent by the till.
_Avoid_: amount due, outstanding (WooCommerce's `needs_payment()` answers a different question)

**Paid**:
The state a sale reaches when its balance is zero or below on a non-zero total. The server sets it through WooCommerce's `payment_complete()`; "completed" in WooCommerce means fulfilled, not paid.
_Avoid_: settled (reserved for a gateway attempt that landed the merchant's stored status without a tender), closed

**Partial**:
A sale with at least one captured tender and a positive balance (`pos-partial`). Layaways and deposits live here.

**Reopen**:
Moving a paid sale back to an open status so its lines can be edited. Every tender keeps counting; the balance after the edit is collected or refunded. Refused only while a tender is in motion.
_Avoid_: new epoch, void and re-ring (a different, rejected model), amend (the edit that follows the reopen)

**Money command**:
A request that creates or changes a payment row: record a tender, start or capture a gateway payment, void, refund, cancel. Carries a till-minted row id and the order revision it was built against; the order push lane is not a money command and cannot carry money.
_Avoid_: payment route (the transport), mutation (the push lane's word)

**Attempt**:
A gateway submission the server persisted before dispatching to the gateway, so a retry can never charge twice. Resolves to a payment row, `settled`, `cancelled` or `superseded`.

**Offline tender**:
A tender the till took while disconnected, recorded as such and forwarded later as the same payment row. The merchant carries the decline risk on an offline card.
_Avoid_: queued payment, deferred mint
